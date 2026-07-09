<?php
/**
 * financeiro_classificar_pendencias.php
 * Tela temporaria para classificar despesas importadas da ME antes de lancar.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_financeiro']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function fcp_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fcp_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_importacao_pendencias (
            id BIGSERIAL PRIMARY KEY,
            import_hash VARCHAR(80) NOT NULL UNIQUE,
            source_file VARCHAR(180) NOT NULL,
            source_line INTEGER NOT NULL,
            data_movimento DATE NOT NULL,
            descricao TEXT NOT NULL,
            categoria_original VARCHAR(180) NULL,
            categoria_sugerida VARCHAR(180) NULL,
            recebido_pago VARCHAR(180) NULL,
            conta VARCHAR(180) NULL,
            tipo VARCHAR(30) NOT NULL DEFAULT 'Despesa',
            valor NUMERIC(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'pendente',
            observacao TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_importacao_pendencias_status ON financeiro_importacao_pendencias(status, data_movimento)");
}

function fcp_money($value): string
{
    if (function_exists('format_currency')) {
        return format_currency($value);
    }
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function fcp_render_categoria_select(array $categorias, int $id, string $value): string
{
    ob_start();
    ?>
    <select class="fcp-cat" name="categoria[<?= (int)$id ?>]" data-cat-select>
        <option value="">Selecionar categoria</option>
        <?php
        $grupoAtual = null;
        foreach ($categorias as $categoria):
            $nome = (string)$categoria['nome'];
            $grupo = (string)$categoria['grupo'];
            if ($grupoAtual !== $grupo):
                if ($grupoAtual !== null): ?></optgroup><?php endif;
                $grupoAtual = $grupo; ?>
                <optgroup label="<?= fcp_h($grupo) ?>">
            <?php endif; ?>
            <option value="<?= fcp_h($nome) ?>" <?= $value === $nome ? 'selected' : '' ?>><?= fcp_h($nome) ?></option>
        <?php endforeach; ?>
        <?php if ($grupoAtual !== null): ?></optgroup><?php endif; ?>
    </select>
    <?php
    return (string)ob_get_clean();
}

fcp_ensure_schema($pdo);

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_categories') {
        $categoriasPost = is_array($_POST['categoria'] ?? null) ? $_POST['categoria'] : [];
        $stmt = $pdo->prepare("
            UPDATE financeiro_importacao_pendencias
            SET categoria_sugerida = NULLIF(TRIM(:categoria), ''),
                updated_at = NOW()
            WHERE id = :id
              AND status = 'pendente'
        ");
        $updated = 0;
        foreach ($categoriasPost as $id => $categoria) {
            $stmt->execute([
                ':id' => (int)$id,
                ':categoria' => trim((string)$categoria),
            ]);
            $updated += $stmt->rowCount();
        }
        $messages[] = $updated . ' categoria(s) salvas.';
    }

    if ($action === 'import_categorized') {
        $pdo->beginTransaction();
        try {
            $rows = $pdo->query("
                SELECT *
                FROM financeiro_importacao_pendencias
                WHERE status = 'pendente'
                  AND TRIM(COALESCE(categoria_sugerida, '')) <> ''
                ORDER BY data_movimento, id
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $insert = $pdo->prepare("
                INSERT INTO financeiro_despesas
                    (data_movimento, descricao, valor, banco, conta, categoria, centro_custo, status, origem,
                     ofx_fitid, ofx_payload, destinatario, created_by, import_hash, import_origem)
                VALUES
                    (:data_movimento, :descricao, :valor, NULL, :conta, :categoria, NULL, 'conciliado',
                     'extrato_meeventos', NULL, CAST(:payload AS JSONB), :destinatario, :created_by, :import_hash, :import_origem)
                ON CONFLICT DO NOTHING
            ");
            $mark = $pdo->prepare("
                UPDATE financeiro_importacao_pendencias
                SET status = 'importado',
                    updated_at = NOW()
                WHERE id = :id
            ");

            $imported = 0;
            $ignored = 0;
            $userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
            foreach ($rows as $row) {
                $payload = json_encode($row, JSON_UNESCAPED_UNICODE);
                $insert->execute([
                    ':data_movimento' => $row['data_movimento'],
                    ':descricao' => $row['descricao'],
                    ':valor' => (float)$row['valor'],
                    ':conta' => $row['conta'] ?: null,
                    ':categoria' => $row['categoria_sugerida'],
                    ':payload' => $payload,
                    ':destinatario' => $row['recebido_pago'] ?: null,
                    ':created_by' => $userId > 0 ? $userId : null,
                    ':import_hash' => $row['import_hash'],
                    ':import_origem' => $row['source_file'] . ':' . $row['source_line'],
                ]);
                if ($insert->rowCount() > 0) {
                    $imported++;
                    $mark->execute([':id' => (int)$row['id']]);
                } else {
                    $ignored++;
                }
            }
            $pdo->commit();
            $messages[] = $imported . ' despesa(s) importadas. ' . $ignored . ' duplicada(s) ignorada(s).';
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('financeiro_classificar_pendencias import: ' . $e->getMessage());
            $errors[] = 'Nao foi possivel importar as despesas categorizadas.';
        }
    }
}

$categorias = $pdo->query("
    SELECT nome, grupo
    FROM financeiro_categorias
    WHERE ativo IS TRUE
      AND tipo IN ('despesa', 'ambos')
    ORDER BY grupo, ordem, nome
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$busca = trim((string)($_GET['busca'] ?? ''));
$status = (string)($_GET['status'] ?? 'pendente');
$status = in_array($status, ['pendente', 'importado', 'todos'], true) ? $status : 'pendente';

$where = [];
$params = [];
if ($status !== 'todos') {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}
if ($busca !== '') {
    $where[] = "(descricao ILIKE :busca OR recebido_pago ILIKE :busca OR conta ILIKE :busca OR categoria_sugerida ILIKE :busca)";
    $params[':busca'] = '%' . $busca . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT *
    FROM financeiro_importacao_pendencias
    {$whereSql}
    ORDER BY status, data_movimento, id
    LIMIT 900
");
$stmt->execute($params);
$pendencias = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = $pdo->query("
    SELECT
        COUNT(*) FILTER (WHERE status = 'pendente') AS pendentes,
        COUNT(*) FILTER (WHERE status = 'pendente' AND TRIM(COALESCE(categoria_sugerida, '')) <> '') AS prontas,
        COUNT(*) FILTER (WHERE status = 'importado') AS importadas,
        COALESCE(SUM(valor) FILTER (WHERE status = 'pendente'), 0) AS total_pendente
    FROM financeiro_importacao_pendencias
")->fetch(PDO::FETCH_ASSOC) ?: ['pendentes' => 0, 'prontas' => 0, 'importadas' => 0, 'total_pendente' => 0];

includeSidebar('Classificar Pendencias');
?>

<style>
.fcp-page{max-width:1480px;margin:0 auto;padding:1.5rem;color:#334155}.fcp-top{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap}.fcp-title{margin:0;color:#1e3a8a;font-size:1.8rem;font-weight:900}.fcp-sub{margin:.35rem 0 0;color:#64748b}.fcp-actions{display:flex;gap:.6rem;flex-wrap:wrap}.fcp-btn{border:0;border-radius:8px;padding:.7rem 1rem;font-weight:900;text-decoration:none;cursor:pointer;background:#e2e8f0;color:#334155;font:inherit}.fcp-btn.primary{background:#1e3a8a;color:#fff}.fcp-btn.green{background:#20c985;color:#fff}.fcp-alert{padding:.8rem 1rem;border-radius:8px;margin:1rem 0;font-weight:800}.fcp-alert.success{background:#ecfdf5;color:#166534;border:1px solid #a7f3d0}.fcp-alert.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.fcp-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem;margin:1rem 0}.fcp-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:1rem;box-shadow:0 12px 28px rgba(15,23,42,.06)}.fcp-card small{display:block;text-transform:uppercase;font-weight:900;color:#64748b;font-size:.72rem}.fcp-card strong{display:block;margin-top:.25rem;color:#1e293b;font-size:1.35rem}.fcp-panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 12px 28px rgba(15,23,42,.06);overflow:hidden}.fcp-filter{display:grid;grid-template-columns:1fr 180px auto;gap:.7rem;padding:1rem;background:#f8fbff;border-bottom:1px solid #e2e8f0}.fcp-filter input,.fcp-filter select,.fcp-cat,.fcp-bulk-cat{border:1px solid #cbd5e1;border-radius:8px;padding:.62rem .72rem;font:inherit;width:100%;background:#fff}.fcp-table-wrap{overflow:auto}.fcp-table{width:100%;border-collapse:collapse;min-width:1120px}.fcp-table th,.fcp-table td{padding:.72rem;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}.fcp-table th{background:#f8fafc;color:#475569;font-size:.75rem;text-transform:uppercase}.fcp-money{font-weight:900;color:#b42318;white-space:nowrap}.fcp-muted{color:#64748b;font-size:.84rem}.fcp-desc{font-weight:800;color:#1e293b}.fcp-row-importado{opacity:.58}.fcp-footer{display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;padding:1rem;border-top:1px solid #e2e8f0;background:#fff}.fcp-bulk{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}.fcp-bulk-cat{width:280px}@media(max-width:900px){.fcp-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.fcp-filter{grid-template-columns:1fr}.fcp-page{padding:1rem}}
</style>

<main class="fcp-page">
    <div class="fcp-top">
        <div>
            <h1 class="fcp-title">Classificar despesas sem categoria</h1>
            <p class="fcp-sub">Tela temporaria para categorizar as pendencias do extrato MeEventos antes de importar.</p>
        </div>
        <div class="fcp-actions">
            <a class="fcp-btn" href="index.php?page=financeiro">Financeiro</a>
            <a class="fcp-btn" href="index.php?page=cadastros_categorias_financeiras">Categorias</a>
        </div>
    </div>

    <?php foreach ($messages as $message): ?><div class="fcp-alert success"><?= fcp_h($message) ?></div><?php endforeach; ?>
    <?php foreach ($errors as $error): ?><div class="fcp-alert error"><?= fcp_h($error) ?></div><?php endforeach; ?>

    <section class="fcp-cards">
        <div class="fcp-card"><small>Pendentes</small><strong><?= (int)$stats['pendentes'] ?></strong></div>
        <div class="fcp-card"><small>Com categoria</small><strong><?= (int)$stats['prontas'] ?></strong></div>
        <div class="fcp-card"><small>Importadas</small><strong><?= (int)$stats['importadas'] ?></strong></div>
        <div class="fcp-card"><small>Total pendente</small><strong><?= fcp_h(fcp_money($stats['total_pendente'])) ?></strong></div>
    </section>

    <section class="fcp-panel">
        <form class="fcp-filter" method="get">
            <input type="hidden" name="page" value="financeiro_classificar_pendencias">
            <input name="busca" value="<?= fcp_h($busca) ?>" placeholder="Buscar descricao, destinatario, conta ou categoria">
            <select name="status">
                <option value="pendente" <?= $status === 'pendente' ? 'selected' : '' ?>>Pendentes</option>
                <option value="importado" <?= $status === 'importado' ? 'selected' : '' ?>>Importadas</option>
                <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Todos</option>
            </select>
            <button class="fcp-btn" type="submit">Filtrar</button>
        </form>

        <form method="post" id="fcp-form">
            <input type="hidden" name="action" value="save_categories" id="fcp-action">
            <div class="fcp-table-wrap">
                <table class="fcp-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" data-select-all></th>
                            <th>Data</th>
                            <th>Descricao</th>
                            <th>Destinatario</th>
                            <th>Conta</th>
                            <th>Valor</th>
                            <th>Categoria</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendencias as $row): ?>
                            <?php $isImported = (string)$row['status'] === 'importado'; ?>
                            <tr class="<?= $isImported ? 'fcp-row-importado' : '' ?>">
                                <td><input type="checkbox" data-row-check <?= $isImported ? 'disabled' : '' ?>></td>
                                <td><?= fcp_h(brDateOnly((string)$row['data_movimento'])) ?><div class="fcp-muted"><?= fcp_h($row['source_file'] . ':' . $row['source_line']) ?></div></td>
                                <td><div class="fcp-desc"><?= fcp_h($row['descricao']) ?></div><div class="fcp-muted">Original: <?= fcp_h($row['categoria_original'] ?: 'Nao informado') ?></div></td>
                                <td><?= fcp_h($row['recebido_pago'] ?: '-') ?></td>
                                <td><?= fcp_h($row['conta'] ?: '-') ?></td>
                                <td><span class="fcp-money"><?= fcp_h(fcp_money($row['valor'])) ?></span></td>
                                <td><?= fcp_render_categoria_select($categorias, (int)$row['id'], (string)($row['categoria_sugerida'] ?? '')) ?></td>
                                <td><?= fcp_h($row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$pendencias): ?><tr><td colspan="8" class="fcp-muted">Nenhuma pendencia encontrada.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="fcp-footer">
                <div class="fcp-bulk">
                    <select class="fcp-bulk-cat" data-bulk-cat>
                        <option value="">Aplicar categoria nos marcados</option>
                        <?php foreach ($categorias as $categoria): ?><option value="<?= fcp_h((string)$categoria['nome']) ?>"><?= fcp_h((string)$categoria['grupo'] . ' - ' . (string)$categoria['nome']) ?></option><?php endforeach; ?>
                    </select>
                    <button class="fcp-btn" type="button" data-apply-bulk>Aplicar</button>
                </div>
                <div class="fcp-actions">
                    <button class="fcp-btn" type="submit" data-save>Salvar categorias</button>
                    <button class="fcp-btn green" type="submit" data-import>Importar categorizadas</button>
                </div>
            </div>
        </form>
    </section>
</main>

<script>
document.querySelector('[data-select-all]')?.addEventListener('change', (event) => {
    document.querySelectorAll('[data-row-check]').forEach((checkbox) => {
        if (!checkbox.disabled) checkbox.checked = event.target.checked;
    });
});

document.querySelector('[data-apply-bulk]')?.addEventListener('click', () => {
    const value = document.querySelector('[data-bulk-cat]')?.value || '';
    if (!value) return;
    document.querySelectorAll('[data-row-check]:checked').forEach((checkbox) => {
        const row = checkbox.closest('tr');
        const select = row?.querySelector('[data-cat-select]');
        if (select) select.value = value;
    });
});

document.querySelector('[data-import]')?.addEventListener('click', () => {
    document.getElementById('fcp-action').value = 'import_categorized';
});

document.querySelector('[data-save]')?.addEventListener('click', () => {
    document.getElementById('fcp-action').value = 'save_categories';
});
</script>

<?php endSidebar(); ?>
