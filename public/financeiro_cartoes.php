<?php
/**
 * financeiro_cartoes.php
 * Cadastro de cartoes e importacao de faturas por texto cru.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_financeiro']) && empty($_SESSION['perm_cadastros']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$messages = [];
$errors = [];
if (!empty($_SESSION['fc_flash'])) {
    $messages[] = (string)$_SESSION['fc_flash'];
    unset($_SESSION['fc_flash']);
}

function fc_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_cartoes (
            id BIGSERIAL PRIMARY KEY,
            nome VARCHAR(160) NOT NULL,
            dia_vencimento INTEGER NOT NULL DEFAULT 1,
            ativo BOOLEAN NOT NULL DEFAULT TRUE,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_cartao_faturas (
            id BIGSERIAL PRIMARY KEY,
            cartao_id BIGINT NOT NULL REFERENCES financeiro_cartoes(id) ON DELETE CASCADE,
            competencia DATE NOT NULL,
            vencimento DATE NOT NULL,
            total NUMERIC(12,2) NOT NULL DEFAULT 0,
            despesa_id BIGINT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (cartao_id, competencia)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_cartao_lancamentos (
            id BIGSERIAL PRIMARY KEY,
            fatura_id BIGINT NOT NULL REFERENCES financeiro_cartao_faturas(id) ON DELETE CASCADE,
            cartao_id BIGINT NOT NULL REFERENCES financeiro_cartoes(id) ON DELETE CASCADE,
            data_compra DATE NOT NULL,
            descricao TEXT NOT NULL,
            descricao_normalizada VARCHAR(220) NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            parcela_numero INTEGER NOT NULL DEFAULT 1,
            parcelas_total INTEGER NOT NULL DEFAULT 1,
            categoria VARCHAR(120) NULL,
            hash_parcela VARCHAR(80) NOT NULL,
            origem_texto TEXT NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (cartao_id, hash_parcela)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_cartao_descricoes (
            id BIGSERIAL PRIMARY KEY,
            descricao_normalizada VARCHAR(220) NOT NULL UNIQUE,
            descricao_exemplo TEXT NOT NULL,
            categoria VARCHAR(120) NULL,
            uso_count INTEGER NOT NULL DEFAULT 0,
            ultimo_uso_em TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_despesas (
            id BIGSERIAL PRIMARY KEY,
            data_movimento DATE NOT NULL,
            descricao TEXT NOT NULL,
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            banco VARCHAR(120) NULL,
            conta VARCHAR(120) NULL,
            categoria VARCHAR(120) NULL,
            centro_custo VARCHAR(120) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            origem VARCHAR(30) NOT NULL DEFAULT 'manual',
            ofx_fitid VARCHAR(180) NULL,
            ofx_payload JSONB NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_cartao_faturas_cartao ON financeiro_cartao_faturas(cartao_id, vencimento)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_cartao_lancamentos_fatura ON financeiro_cartao_lancamentos(fatura_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_cartao_descricoes_norm ON financeiro_cartao_descricoes(descricao_normalizada)");
}

function fc_money($value): float
{
    $raw = trim((string)$value);
    $raw = str_replace(['R$', ' '], '', $raw);
    if (preg_match('/,\d{1,2}$/', $raw)) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    }
    return round((float)$raw, 2);
}

function fc_norm(string $text): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($text));
    $text = $ascii !== false ? $ascii : $text;
    $text = strtoupper($text);
    $text = preg_replace('/[^A-Z0-9 ]+/', ' ', $text);
    return trim((string)preg_replace('/\s+/', ' ', (string)$text));
}

function fc_parse_date(string $date): ?string
{
    $dt = DateTimeImmutable::createFromFormat('d/m/Y', trim($date));
    return $dt ? $dt->format('Y-m-d') : null;
}

function fc_parse_parcela(string $text): array
{
    if (preg_match('/(\d{1,2})\s*\/\s*(\d{1,2})/', $text, $m)) {
        return [max(1, (int)$m[1]), max(1, (int)$m[2])];
    }
    return [1, 1];
}

function fc_parse_raw(string $text): array
{
    $rows = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $lineNo => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) {
            continue;
        }
        $date = fc_parse_date($parts[0]);
        $valor = fc_money($parts[2]);
        if (!$date || $valor <= 0) {
            continue;
        }
        [$parcela, $total] = fc_parse_parcela($parts[3] ?? $parts[1]);
        $descricao = trim($parts[1]);
        $norm = fc_norm($descricao);
        $rows[] = [
            'data_compra' => $date,
            'descricao' => $descricao,
            'descricao_normalizada' => $norm,
            'valor' => $valor,
            'parcela_numero' => min($parcela, $total),
            'parcelas_total' => max($total, $parcela),
            'linha_original' => $line,
            'linha' => $lineNo + 1,
        ];
    }
    return $rows;
}

function fc_due_add_months(string $vencimento, int $months): string
{
    return (new DateTimeImmutable($vencimento))->modify('+' . $months . ' months')->format('Y-m-d');
}

function fc_competencia(string $vencimento): string
{
    return (new DateTimeImmutable($vencimento))->format('Y-m-01');
}

function fc_hash(int $cartaoId, array $item, int $parcela): string
{
    return sha1($cartaoId . '|' . $item['data_compra'] . '|' . $item['descricao_normalizada'] . '|' . number_format((float)$item['valor'], 2, '.', '') . '|' . $parcela . '|' . (int)$item['parcelas_total']);
}

function fc_duplicate(PDO $pdo, int $cartaoId, array $item): bool
{
    $hash = fc_hash($cartaoId, $item, (int)$item['parcela_numero']);
    $stmt = $pdo->prepare("SELECT 1 FROM financeiro_cartao_lancamentos WHERE cartao_id = :cartao_id AND hash_parcela = :hash LIMIT 1");
    $stmt->execute([':cartao_id' => $cartaoId, ':hash' => $hash]);
    return (bool)$stmt->fetchColumn();
}

function fc_suggest_category(PDO $pdo, string $norm): string
{
    $stmt = $pdo->prepare("SELECT categoria FROM financeiro_cartao_descricoes WHERE descricao_normalizada = :norm AND COALESCE(categoria, '') <> '' LIMIT 1");
    $stmt->execute([':norm' => $norm]);
    $cat = (string)($stmt->fetchColumn() ?: '');
    if ($cat !== '') {
        return $cat;
    }
    $stmt = $pdo->query("SELECT descricao_normalizada, categoria FROM financeiro_cartao_descricoes WHERE COALESCE(categoria, '') <> '' ORDER BY ultimo_uso_em DESC NULLS LAST, updated_at DESC LIMIT 500");
    $best = '';
    $scoreBest = 0.0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        similar_text($norm, (string)$row['descricao_normalizada'], $score);
        if ($score >= 82.0 && $score > $scoreBest) {
            $scoreBest = $score;
            $best = (string)$row['categoria'];
        }
    }
    return $best;
}

function fc_learn(PDO $pdo, array $item, string $categoria): void
{
    $stmt = $pdo->prepare("
        INSERT INTO financeiro_cartao_descricoes (descricao_normalizada, descricao_exemplo, categoria, uso_count, ultimo_uso_em, updated_at)
        VALUES (:norm, :exemplo, :categoria, 1, NOW(), NOW())
        ON CONFLICT (descricao_normalizada) DO UPDATE SET
            descricao_exemplo = EXCLUDED.descricao_exemplo,
            categoria = EXCLUDED.categoria,
            uso_count = financeiro_cartao_descricoes.uso_count + 1,
            ultimo_uso_em = NOW(),
            updated_at = NOW()
    ");
    $stmt->execute([
        ':norm' => $item['descricao_normalizada'],
        ':exemplo' => $item['descricao'],
        ':categoria' => $categoria !== '' ? $categoria : null,
    ]);
}

function fc_get_or_create_fatura(PDO $pdo, int $cartaoId, string $vencimento): int
{
    $competencia = fc_competencia($vencimento);
    $stmt = $pdo->prepare("
        INSERT INTO financeiro_cartao_faturas (cartao_id, competencia, vencimento, updated_at)
        VALUES (:cartao_id, :competencia, :vencimento, NOW())
        ON CONFLICT (cartao_id, competencia) DO UPDATE SET vencimento = EXCLUDED.vencimento, updated_at = NOW()
        RETURNING id
    ");
    $stmt->execute([':cartao_id' => $cartaoId, ':competencia' => $competencia, ':vencimento' => $vencimento]);
    return (int)$stmt->fetchColumn();
}

function fc_sync_fatura(PDO $pdo, int $faturaId, int $userId): void
{
    $stmt = $pdo->prepare("
        SELECT f.*, c.nome AS cartao_nome
        FROM financeiro_cartao_faturas f
        INNER JOIN financeiro_cartoes c ON c.id = f.cartao_id
        WHERE f.id = :id
    ");
    $stmt->execute([':id' => $faturaId]);
    $fatura = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fatura) {
        return;
    }
    $sum = $pdo->prepare("SELECT COALESCE(SUM(valor), 0) FROM financeiro_cartao_lancamentos WHERE fatura_id = :id");
    $sum->execute([':id' => $faturaId]);
    $total = (float)$sum->fetchColumn();
    $descricao = 'Fatura Cartao ' . (string)$fatura['cartao_nome'] . ' - ' . date('m/Y', strtotime((string)$fatura['vencimento']));
    $fitid = 'cartao_fatura:' . $faturaId;

    $lookup = $pdo->prepare("SELECT id FROM financeiro_despesas WHERE ofx_fitid = :fitid LIMIT 1");
    $lookup->execute([':fitid' => $fitid]);
    $despesaId = (int)($lookup->fetchColumn() ?: 0);
    if ($despesaId > 0) {
        $upd = $pdo->prepare("UPDATE financeiro_despesas SET data_movimento = :data, descricao = :descricao, valor = :valor, origem = 'cartao_credito', updated_at = NOW() WHERE id = :id");
        $upd->execute([':data' => $fatura['vencimento'], ':descricao' => $descricao, ':valor' => $total, ':id' => $despesaId]);
    } else {
        $ins = $pdo->prepare("
            INSERT INTO financeiro_despesas (data_movimento, descricao, valor, categoria, status, origem, ofx_fitid, created_by)
            VALUES (:data, :descricao, :valor, 'Cartao de credito', 'pendente', 'cartao_credito', :fitid, :created_by)
            RETURNING id
        ");
        $ins->execute([':data' => $fatura['vencimento'], ':descricao' => $descricao, ':valor' => $total, ':fitid' => $fitid, ':created_by' => $userId > 0 ? $userId : null]);
        $despesaId = (int)$ins->fetchColumn();
    }
    $updFat = $pdo->prepare("UPDATE financeiro_cartao_faturas SET total = :total, despesa_id = :despesa_id, updated_at = NOW() WHERE id = :id");
    $updFat->execute([':total' => $total, ':despesa_id' => $despesaId ?: null, ':id' => $faturaId]);
}

fc_ensure_schema($pdo);

$categorias = [];
try {
    $categorias = $pdo->query("SELECT nome, grupo FROM financeiro_categorias WHERE ativo IS TRUE AND tipo IN ('despesa', 'ambos') ORDER BY grupo, ordem, nome")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $categorias = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_card') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $dia = max(1, min(31, (int)($_POST['dia_vencimento'] ?? 1)));
        if ($nome === '') {
            $errors[] = 'Informe o nome do cartao.';
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE financeiro_cartoes SET nome = :nome, dia_vencimento = :dia, updated_at = NOW() WHERE id = :id");
                $stmt->execute([':nome' => $nome, ':dia' => $dia, ':id' => $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO financeiro_cartoes (nome, dia_vencimento, created_by) VALUES (:nome, :dia, :created_by)");
                $stmt->execute([':nome' => $nome, ':dia' => $dia, ':created_by' => $userId > 0 ? $userId : null]);
            }
            $_SESSION['fc_flash'] = 'Cartao salvo com sucesso.';
            header('Location: index.php?page=financeiro_cartoes');
            exit;
        }
    }

    if ($action === 'toggle_card') {
        $stmt = $pdo->prepare("UPDATE financeiro_cartoes SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => (int)($_POST['id'] ?? 0)]);
        $_SESSION['fc_flash'] = 'Status do cartao atualizado.';
        header('Location: index.php?page=financeiro_cartoes');
        exit;
    }

    if ($action === 'preview_import') {
        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $vencimento = trim((string)($_POST['vencimento'] ?? ''));
        $texto = (string)($_POST['texto'] ?? '');
        $items = [];
        foreach (fc_parse_raw($texto) as $item) {
            $item['categoria_sugerida'] = fc_suggest_category($pdo, (string)$item['descricao_normalizada']);
            $item['duplicado'] = $cartaoId > 0 ? fc_duplicate($pdo, $cartaoId, $item) : true;
            $items[] = $item;
        }
        if ($cartaoId <= 0 || $vencimento === '' || !$items) {
            $errors[] = 'Selecione o cartao, vencimento e informe lancamentos validos.';
        } else {
            $_SESSION['fc_preview'] = ['cartao_id' => $cartaoId, 'vencimento' => $vencimento, 'items' => $items];
            header('Location: index.php?page=financeiro_cartoes&preview=1');
            exit;
        }
    }

    if ($action === 'cancel_preview') {
        unset($_SESSION['fc_preview']);
        $_SESSION['fc_flash'] = 'Pre-visualizacao descartada.';
        header('Location: index.php?page=financeiro_cartoes');
        exit;
    }

    if ($action === 'confirm_import') {
        $preview = $_SESSION['fc_preview'] ?? null;
        $items = is_array($preview['items'] ?? null) ? $preview['items'] : [];
        $selected = is_array($_POST['selected'] ?? null) ? array_map('intval', $_POST['selected']) : [];
        $categoriasPost = is_array($_POST['categoria'] ?? null) ? $_POST['categoria'] : [];
        $cartaoId = (int)($preview['cartao_id'] ?? 0);
        $vencimentoBase = (string)($preview['vencimento'] ?? '');
        $touched = [];
        $imported = 0;
        $dupes = 0;
        foreach ($selected as $idx) {
            if (!isset($items[$idx])) {
                continue;
            }
            $item = $items[$idx];
            $categoria = trim((string)($categoriasPost[$idx] ?? $item['categoria_sugerida'] ?? ''));
            for ($parcela = (int)$item['parcela_numero']; $parcela <= (int)$item['parcelas_total']; $parcela++) {
                $due = fc_due_add_months($vencimentoBase, $parcela - (int)$item['parcela_numero']);
                $faturaId = fc_get_or_create_fatura($pdo, $cartaoId, $due);
                $hash = fc_hash($cartaoId, $item, $parcela);
                $stmt = $pdo->prepare("
                    INSERT INTO financeiro_cartao_lancamentos
                        (fatura_id, cartao_id, data_compra, descricao, descricao_normalizada, valor, parcela_numero, parcelas_total, categoria, hash_parcela, origem_texto, created_by)
                    VALUES
                        (:fatura_id, :cartao_id, :data_compra, :descricao, :norm, :valor, :parcela, :total, :categoria, :hash, :origem, :created_by)
                    ON CONFLICT (cartao_id, hash_parcela) DO NOTHING
                ");
                $stmt->execute([
                    ':fatura_id' => $faturaId,
                    ':cartao_id' => $cartaoId,
                    ':data_compra' => $item['data_compra'],
                    ':descricao' => $item['descricao'],
                    ':norm' => $item['descricao_normalizada'],
                    ':valor' => $item['valor'],
                    ':parcela' => $parcela,
                    ':total' => $item['parcelas_total'],
                    ':categoria' => $categoria !== '' ? $categoria : null,
                    ':hash' => $hash,
                    ':origem' => $item['linha_original'],
                    ':created_by' => $userId > 0 ? $userId : null,
                ]);
                if ($stmt->rowCount() > 0) {
                    $imported++;
                    $touched[$faturaId] = true;
                } else {
                    $dupes++;
                }
            }
            fc_learn($pdo, $item, $categoria);
        }
        foreach (array_keys($touched) as $faturaId) {
            fc_sync_fatura($pdo, (int)$faturaId, $userId);
        }
        unset($_SESSION['fc_preview']);
        $_SESSION['fc_flash'] = $imported . ' lancamento(s) importado(s). ' . $dupes . ' duplicado(s) ignorado(s).';
        header('Location: index.php?page=financeiro_cartoes');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['preview'])) {
    unset($_SESSION['fc_preview']);
}

$cartoes = $pdo->query("SELECT * FROM financeiro_cartoes ORDER BY ativo DESC, nome")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$faturasPorCartao = [];
$stmt = $pdo->query("SELECT f.*, c.nome AS cartao_nome FROM financeiro_cartao_faturas f INNER JOIN financeiro_cartoes c ON c.id = f.cartao_id ORDER BY f.vencimento ASC");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $fatura) {
    $faturasPorCartao[(int)$fatura['cartao_id']][] = $fatura;
}
$preview = is_array($_SESSION['fc_preview'] ?? null) ? $_SESSION['fc_preview'] : null;
$previewItems = is_array($preview['items'] ?? null) ? $preview['items'] : [];
$viewFaturaId = (int)($_GET['fatura_id'] ?? 0);
$viewLancamentos = [];
$viewFatura = null;
if ($viewFaturaId > 0) {
    $stmt = $pdo->prepare("SELECT f.*, c.nome AS cartao_nome FROM financeiro_cartao_faturas f INNER JOIN financeiro_cartoes c ON c.id = f.cartao_id WHERE f.id = :id");
    $stmt->execute([':id' => $viewFaturaId]);
    $viewFatura = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt = $pdo->prepare("SELECT * FROM financeiro_cartao_lancamentos WHERE fatura_id = :id ORDER BY data_compra, id");
    $stmt->execute([':id' => $viewFaturaId]);
    $viewLancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fc_render_categoria_combo(array $categorias, string $name, string $value = '', bool $disabled = false): string
{
    ob_start();
    ?>
    <div class="cc-cat-field" data-cc-cat-field>
        <input type="hidden" name="<?= h($name) ?>" value="<?= h($value) ?>" data-cc-cat-hidden <?= $disabled ? 'disabled' : '' ?>>
        <div class="cc-cat-combo <?= $disabled ? 'disabled' : '' ?>" data-cc-cat-combo>
            <button class="cc-cat-trigger" type="button" data-cc-cat-trigger <?= $disabled ? 'disabled' : '' ?>>
                <span data-cc-cat-label><?= h($value !== '' ? $value : 'Selecionar categoria') ?></span>
            </button>
            <div class="cc-cat-panel">
                <div class="cc-cat-search-wrap"><input class="cc-cat-search" type="text" placeholder="Pesquisar categoria" data-cc-cat-search></div>
                <div class="cc-cat-option <?= $value === '' ? 'active' : '' ?>" data-cc-cat-option data-value="" data-group="">Selecionar categoria</div>
                <?php
                $grupoAtual = null;
                foreach ($categorias as $categoria):
                    $nomeCategoria = (string)($categoria['nome'] ?? '');
                    $grupoCategoria = (string)($categoria['grupo'] ?? 'Geral');
                    if ($grupoAtual !== $grupoCategoria):
                        $grupoAtual = $grupoCategoria;
                        ?>
                        <div class="cc-cat-group" data-cc-cat-group="<?= h($grupoCategoria) ?>"><?= h($grupoCategoria) ?></div>
                    <?php endif; ?>
                    <div class="cc-cat-option <?= $value === $nomeCategoria ? 'active' : '' ?>" data-cc-cat-option data-value="<?= h($nomeCategoria) ?>" data-group="<?= h($grupoCategoria) ?>"><?= h($nomeCategoria) ?></div>
                <?php endforeach; ?>
                <div class="cc-cat-empty" data-cc-cat-empty style="display:none">Nenhuma categoria encontrada.</div>
            </div>
        </div>
    </div>
    <?php
    return (string)ob_get_clean();
}

includeSidebar('Cartoes de Credito');
?>

<style>
.cc-page{max-width:1440px;margin:0 auto;padding:1.5rem;color:#334155}.cc-top{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap}.cc-title{margin:0;color:#1e3a8a;font-size:1.85rem;font-weight:900}.cc-sub{margin:.3rem 0 0;color:#64748b}.cc-actions{display:flex;gap:.65rem;flex-wrap:wrap}.cc-btn{border:0;border-radius:8px;padding:.72rem 1rem;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;background:#e2e8f0;color:#334155;font:inherit}.cc-btn.primary{background:#1e3a8a;color:#fff}.cc-btn.green{background:#20c985;color:#fff}.cc-alert{padding:.85rem 1rem;border-radius:8px;margin:1rem 0;font-weight:800}.cc-alert.success{background:#ecfdf5;color:#166534;border:1px solid #a7f3d0}.cc-alert.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.cc-grid{display:grid;grid-template-columns:1fr;gap:1rem;margin-top:1rem}.cc-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 14px 34px rgba(15,23,42,.07);overflow:hidden}.cc-card summary{cursor:pointer;padding:1rem;background:#f8fbff;display:flex;justify-content:space-between;gap:1rem;align-items:center}.cc-card-title{font-weight:900;color:#1e293b}.cc-muted{color:#64748b;font-size:.88rem}.cc-table-wrap{overflow:auto}.cc-table{width:100%;border-collapse:collapse;min-width:900px}.cc-table th,.cc-table td{padding:.8rem;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:middle}.cc-table th{background:#f8fafc;color:#475569;font-size:.76rem;text-transform:uppercase}.cc-pill{display:inline-flex;border-radius:999px;padding:.22rem .55rem;font-size:.76rem;font-weight:900}.cc-pill.on{background:#dcfce7;color:#166534}.cc-pill.off{background:#fee2e2;color:#991b1b}.cc-money{font-weight:900;color:#b42318}.cc-row-actions{display:flex;gap:.45rem;flex-wrap:wrap}.cc-small-btn{border:1px solid #dbe3ef;background:#fff;border-radius:8px;padding:.42rem .62rem;cursor:pointer;font-weight:800;color:#334155;text-decoration:none}.cc-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;display:none;align-items:center;justify-content:center;padding:1rem}.cc-modal-backdrop.open{display:flex}.cc-modal{width:min(860px,100%);max-height:calc(100vh - 2rem);overflow:auto;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(15,23,42,.28)}.cc-modal-head{display:flex;justify-content:space-between;align-items:center;padding:1rem;border-bottom:1px solid #e2e8f0}.cc-modal-title{margin:0;font-size:1.15rem;color:#1e293b;font-weight:900}.cc-close{width:36px;height:36px;border:0;border-radius:999px;background:#f1f5f9;cursor:pointer;font-size:1.25rem}.cc-form{padding:1rem;display:grid;gap:.85rem}.cc-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.85rem}.cc-field{display:grid;gap:.35rem}.cc-field.full{grid-column:1/-1}.cc-field label{font-weight:800;font-size:.84rem}.cc-field input,.cc-field select,.cc-field textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.68rem .78rem;font:inherit}.cc-field textarea{min-height:180px}.cc-preview{margin-top:1rem;border:1px solid #bae6fd;border-radius:10px;overflow:visible;background:#f0f9ff}.cc-preview-head{padding:1rem;border-bottom:1px solid #bae6fd;font-weight:900;color:#075985}.cc-duplicate{background:#fff7ed}.cc-footer{display:flex;justify-content:flex-end;padding:1rem;border-top:1px solid #e2e8f0}.cc-cat-field{min-width:260px}.cc-cat-combo{position:relative}.cc-cat-trigger{width:100%;display:flex;align-items:center;justify-content:space-between;gap:.5rem;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#1e293b;padding:.55rem .68rem;font:inherit;text-align:left;cursor:pointer}.cc-cat-trigger:after{content:'▾';color:#64748b;font-size:.78rem}.cc-cat-panel{position:absolute;z-index:90;top:calc(100% + 4px);left:0;right:0;display:none;background:#fff;border:1px solid #334155;box-shadow:0 12px 28px rgba(15,23,42,.18);padding:.45rem;max-height:280px;overflow:auto}.cc-cat-combo.open .cc-cat-panel{display:block}.cc-cat-search-wrap{position:relative;margin-bottom:.45rem}.cc-cat-search{width:100%;border:1px solid #d1d5db;border-radius:0;padding:.48rem 1.9rem .48rem .55rem;font:inherit}.cc-cat-search-wrap:after{content:'⌕';position:absolute;right:.55rem;top:.34rem;color:#64748b;font-weight:900}.cc-cat-group{font-weight:900;color:#475569;font-size:.8rem;padding:.35rem .4rem}.cc-cat-option{padding:.42rem .7rem;cursor:pointer;color:#334155}.cc-cat-option:hover,.cc-cat-option.active{background:#334155;color:#fff}.cc-cat-empty{padding:.55rem;color:#64748b;font-size:.85rem}.cc-cat-combo.disabled{opacity:.55;pointer-events:none}@media(max-width:760px){.cc-form-grid{grid-template-columns:1fr}.cc-page{padding:1rem}}
</style>

<main class="cc-page">
    <div class="cc-top">
        <div><h1 class="cc-title">Cartoes de Credito</h1><p class="cc-sub">Cadastre cartoes, importe faturas e integre o total ao financeiro.</p></div>
        <div class="cc-actions">
            <a class="cc-btn" href="index.php?page=financeiro">Financeiro</a>
            <button class="cc-btn green" type="button" data-open-modal="import-modal" onclick="ccOpenModal('import-modal')">Importar fatura</button>
            <button class="cc-btn primary" type="button" data-open-modal="card-modal" onclick="ccOpenModal('card-modal')">Novo cartao</button>
        </div>
    </div>
    <?php foreach ($messages as $msg): ?><div class="cc-alert success"><?= h($msg) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $err): ?><div class="cc-alert error"><?= h($err) ?></div><?php endforeach; ?>

    <?php if ($previewItems): ?>
        <section class="cc-preview">
            <div class="cc-preview-head">Pre-visualizacao da fatura - <?= count($previewItems) ?> lancamento(s)</div>
            <form method="post">
                <input type="hidden" name="action" value="confirm_import">
                <div class="cc-table-wrap"><table class="cc-table"><thead><tr><th><input type="checkbox" data-cc-select-all checked></th><th>Data</th><th>Descricao</th><th>Valor</th><th>Parcela</th><th>Categoria</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($previewItems as $idx => $item): ?>
                    <?php $dup = !empty($item['duplicado']); $cat = (string)($item['categoria_sugerida'] ?? ''); ?>
                    <tr class="<?= $dup ? 'cc-duplicate' : '' ?>">
                        <td><input type="checkbox" name="selected[]" value="<?= (int)$idx ?>" <?= $dup ? 'disabled' : 'checked' ?> data-cc-row-check></td>
                        <td><?= h(brDateOnly((string)$item['data_compra'])) ?></td>
                        <td><?= h((string)$item['descricao']) ?></td>
                        <td><span class="cc-money"><?= h(format_currency($item['valor'])) ?></span></td>
                        <td><?= (int)$item['parcela_numero'] ?>/<?= (int)$item['parcelas_total'] ?></td>
                        <td><?= fc_render_categoria_combo($categorias, 'categoria[' . (int)$idx . ']', $cat, $dup) ?></td>
                        <td><?= $dup ? '<span class="cc-pill off">Duplicado</span>' : '<span class="cc-pill on">Pronto</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
                <div class="cc-footer"><button class="cc-btn primary" type="submit">Importar selecionados</button></div>
            </form>
            <form method="post" style="padding:0 1rem 1rem"><input type="hidden" name="action" value="cancel_preview"><button class="cc-small-btn" type="submit">Descartar pre-visualizacao</button></form>
        </section>
    <?php endif; ?>

    <?php if ($viewFatura): ?>
        <section class="cc-card" style="margin-top:1rem">
            <div style="padding:1rem;background:#f8fbff;border-bottom:1px solid #e2e8f0"><strong><?= h((string)$viewFatura['cartao_nome']) ?> - <?= h(date('m/Y', strtotime((string)$viewFatura['vencimento']))) ?></strong> · <span class="cc-money"><?= h(format_currency($viewFatura['total'])) ?></span></div>
            <div class="cc-table-wrap"><table class="cc-table"><thead><tr><th>Data</th><th>Descricao</th><th>Valor</th><th>Parcela</th><th>Categoria</th></tr></thead><tbody><?php foreach($viewLancamentos as $l): ?><tr><td><?= h(brDateOnly((string)$l['data_compra'])) ?></td><td><?= h((string)$l['descricao']) ?></td><td><span class="cc-money"><?= h(format_currency($l['valor'])) ?></span></td><td><?= (int)$l['parcela_numero'] ?>/<?= (int)$l['parcelas_total'] ?></td><td><?= h((string)($l['categoria'] ?: '-')) ?></td></tr><?php endforeach; ?></tbody></table></div>
        </section>
    <?php endif; ?>

    <div class="cc-grid">
        <?php foreach ($cartoes as $cartao): ?>
            <details class="cc-card">
                <summary><span><span class="cc-card-title"><?= h((string)$cartao['nome']) ?></span><span class="cc-muted"> · vencimento dia <?= (int)$cartao['dia_vencimento'] ?></span></span><span class="cc-pill <?= !empty($cartao['ativo']) ? 'on' : 'off' ?>"><?= !empty($cartao['ativo']) ? 'Ativo' : 'Inativo' ?></span></summary>
                <div style="padding:1rem"><div class="cc-row-actions"><button class="cc-small-btn" type="button" data-edit-card data-id="<?= (int)$cartao['id'] ?>" data-nome="<?= h((string)$cartao['nome']) ?>" data-dia="<?= (int)$cartao['dia_vencimento'] ?>">Editar</button><form method="post"><input type="hidden" name="action" value="toggle_card"><input type="hidden" name="id" value="<?= (int)$cartao['id'] ?>"><button class="cc-small-btn" type="submit"><?= !empty($cartao['ativo']) ? 'Desativar' : 'Ativar' ?></button></form></div></div>
                <div class="cc-table-wrap"><table class="cc-table"><thead><tr><th>Fatura</th><th>Vencimento</th><th>Total</th><th>Acoes</th></tr></thead><tbody><?php foreach (($faturasPorCartao[(int)$cartao['id']] ?? []) as $fatura): ?><tr><td><?= h(date('m/Y', strtotime((string)$fatura['competencia']))) ?></td><td><?= h(brDateOnly((string)$fatura['vencimento'])) ?></td><td><span class="cc-money"><?= h(format_currency($fatura['total'])) ?></span></td><td><a class="cc-small-btn" href="index.php?page=financeiro_cartoes&fatura_id=<?= (int)$fatura['id'] ?>">Visualizar</a></td></tr><?php endforeach; ?><?php if (empty($faturasPorCartao[(int)$cartao['id']])): ?><tr><td colspan="4" class="cc-muted">Nenhuma fatura importada.</td></tr><?php endif; ?></tbody></table></div>
            </details>
        <?php endforeach; ?>
        <?php if (!$cartoes): ?><div class="cc-card" style="padding:1rem">Nenhum cartao cadastrado.</div><?php endif; ?>
    </div>
</main>

<div class="cc-modal-backdrop" id="card-modal"><div class="cc-modal"><div class="cc-modal-head"><h2 class="cc-modal-title" id="card-modal-title">Novo cartao</h2><button class="cc-close" type="button" data-close-modal>×</button></div><form class="cc-form" method="post"><input type="hidden" name="action" value="save_card"><input type="hidden" name="id" id="card-id" value="0"><div class="cc-form-grid"><div class="cc-field"><label>Nome</label><input name="nome" id="card-nome" required></div><div class="cc-field"><label>Vencimento</label><input type="number" name="dia_vencimento" id="card-dia" min="1" max="31" value="1" required></div></div><div class="cc-footer"><button class="cc-btn primary" type="submit">Salvar cartao</button></div></form></div></div>

<div class="cc-modal-backdrop" id="import-modal"><div class="cc-modal"><div class="cc-modal-head"><h2 class="cc-modal-title">Importar fatura</h2><button class="cc-close" type="button" data-close-modal>×</button></div><form class="cc-form" method="post"><input type="hidden" name="action" value="preview_import"><div class="cc-form-grid"><div class="cc-field"><label>Cartao</label><select name="cartao_id" required><option value="">Selecionar cartao</option><?php foreach($cartoes as $cartao): if(empty($cartao['ativo'])) continue; ?><option value="<?= (int)$cartao['id'] ?>"><?= h((string)$cartao['nome']) ?></option><?php endforeach; ?></select></div><div class="cc-field"><label>Vencimento da fatura</label><input type="date" name="vencimento" required></div><div class="cc-field full"><label>Texto cru da fatura</label><textarea name="texto" placeholder="09/03/2026 | DEPOSITO FLORIDA JACAR | 380,90 | 2/3" required></textarea></div></div><div class="cc-footer"><button class="cc-btn primary" type="submit">Pre-visualizar</button></div></form></div></div>

<script>
function ccOpenModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('open');
    }
}

function ccCloseModal(modal) {
    if (modal) {
        modal.classList.remove('open');
    }
}

function ccOpenCardModal(data = {}) {
    document.getElementById('card-id').value = data.id || '0';
    document.getElementById('card-nome').value = data.nome || '';
    document.getElementById('card-dia').value = data.dia || '1';
    document.getElementById('card-modal-title').textContent = data.id ? 'Editar cartao' : 'Novo cartao';
    ccOpenModal('card-modal');
}

document.addEventListener('click', (event) => {
    const openButton = event.target.closest('[data-open-modal]');
    if (openButton) {
        ccOpenModal(openButton.dataset.openModal || '');
        return;
    }

    const closeButton = event.target.closest('[data-close-modal]');
    if (closeButton) {
        ccCloseModal(closeButton.closest('.cc-modal-backdrop'));
        return;
    }

    const editButton = event.target.closest('[data-edit-card]');
    if (editButton) {
        ccOpenCardModal({
            id: editButton.dataset.id || '0',
            nome: editButton.dataset.nome || '',
            dia: editButton.dataset.dia || '1',
        });
        return;
    }

    if (event.target.classList && event.target.classList.contains('cc-modal-backdrop')) {
        ccCloseModal(event.target);
    }
});

document.querySelector('[data-cc-select-all]')?.addEventListener('change', (event) => {
    document.querySelectorAll('[data-cc-row-check]').forEach((checkbox) => {
        if (!checkbox.disabled) {
            checkbox.checked = event.target.checked;
        }
    });
});

document.querySelectorAll('[data-cc-cat-combo]').forEach((combo) => {
    const trigger = combo.querySelector('[data-cc-cat-trigger]');
    const search = combo.querySelector('[data-cc-cat-search]');
    const label = combo.querySelector('[data-cc-cat-label]');
    const hidden = combo.closest('[data-cc-cat-field]')?.querySelector('[data-cc-cat-hidden]');
    const options = Array.from(combo.querySelectorAll('[data-cc-cat-option]'));
    const groups = Array.from(combo.querySelectorAll('[data-cc-cat-group]'));
    const empty = combo.querySelector('[data-cc-cat-empty]');

    function filterOptions() {
        const term = (search?.value || '').trim().toLowerCase();
        let visibleCount = 0;
        options.forEach((option) => {
            const text = `${option.dataset.value || ''} ${option.dataset.group || ''}`.toLowerCase();
            const visible = term === '' || text.includes(term);
            option.style.display = visible ? '' : 'none';
            if (visible) {
                visibleCount++;
            }
        });
        groups.forEach((group) => {
            const groupName = group.dataset.ccCatGroup || '';
            const hasVisible = options.some((option) => option.dataset.group === groupName && option.style.display !== 'none');
            group.style.display = hasVisible ? '' : 'none';
        });
        if (empty) {
            empty.style.display = visibleCount === 0 ? '' : 'none';
        }
    }

    trigger?.addEventListener('click', () => {
        document.querySelectorAll('[data-cc-cat-combo].open').forEach((other) => {
            if (other !== combo) {
                other.classList.remove('open');
            }
        });
        combo.classList.toggle('open');
        if (combo.classList.contains('open')) {
            if (search) {
                search.value = '';
            }
            filterOptions();
            setTimeout(() => search?.focus(), 0);
        }
    });

    search?.addEventListener('input', filterOptions);

    options.forEach((option) => {
        option.addEventListener('click', () => {
            const value = option.dataset.value || '';
            if (hidden) {
                hidden.value = value;
            }
            if (label) {
                label.textContent = value || 'Selecionar categoria';
            }
            options.forEach((item) => item.classList.toggle('active', item === option));
            combo.classList.remove('open');
        });
    });
});

document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-cc-cat-combo]')) {
        document.querySelectorAll('[data-cc-cat-combo].open').forEach((combo) => combo.classList.remove('open'));
    }
});
</script>

<?php endSidebar(); ?>
