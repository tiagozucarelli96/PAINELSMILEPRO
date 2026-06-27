<?php
/**
 * cadastros_categorias_financeiras.php
 * CRUD de categorias financeiras usadas nos lancamentos.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_cadastros']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function cf_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cf_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS financeiro_categorias (
            id BIGSERIAL PRIMARY KEY,
            nome VARCHAR(180) NOT NULL,
            grupo VARCHAR(120) NOT NULL DEFAULT 'Geral',
            tipo VARCHAR(20) NOT NULL DEFAULT 'despesa',
            ordem INTEGER NOT NULL DEFAULT 0,
            ativo BOOLEAN NOT NULL DEFAULT TRUE,
            descricao TEXT NULL,
            created_by INTEGER NULL,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (tipo, grupo, nome)
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_financeiro_categorias_ativo ON financeiro_categorias(tipo, ativo, grupo, ordem, nome)");
}

function cf_seed_defaults(PDO $pdo): void
{
    $defaults = [
        ['Geral', '13o Salario'], ['Geral', 'Agua e esgoto'], ['Geral', 'Aluguel'], ['Geral', 'Anuidade de cartao'],
        ['Geral', 'Aquisicao de equipamentos'], ['Geral', 'Assistencia Medica'], ['Geral', 'Assistencia odontologica'],
        ['Geral', 'Barmen'], ['Geral', 'Bebidas'], ['Geral', 'Bolo'], ['Geral', 'Cartorio'], ['Geral', 'Combustivel'],
        ['Geral', 'Comissao de vendedores'], ['Geral', 'Custos Eventos'], ['Geral', 'Decoracao'], ['Geral', 'Devolucao Cliente'],
        ['Geral', 'DJ'], ['Geral', 'Doces'], ['Geral', 'Emprestimos'], ['Geral', 'Energia eletrica'], ['Geral', 'Escritorio'],
        ['Geral', 'Fornecedores'], ['Geral', 'Frutas congeladas'], ['Geral', 'Gelo'], ['Geral', 'Horas Extras'], ['Geral', 'Hort Frut'],
        ['Geral', 'Insumos'], ['Geral', 'Internet'], ['Geral', 'Investimentos'],
        ['Impostos', 'Alvara'], ['Impostos', 'INSS'], ['Impostos', 'Iof'], ['Impostos', 'IPTU'], ['Impostos', 'IPVA'],
        ['Impostos', 'IR'], ['Impostos', 'IRPJ'], ['Impostos', 'Juros'], ['Impostos', 'Simples Nacional'], ['Impostos', 'Taxa de lixo'],
        ['Pessoas e Equipe', 'Beneficios (V.A., VT, VR)'], ['Pessoas e Equipe', 'Bonificacao Func.'],
        ['Pessoas e Equipe', 'Freelancer'], ['Pessoas e Equipe', 'Pro-Labore'], ['Pessoas e Equipe', 'RH'],
        ['Pessoas e Equipe', 'Salario'], ['Pessoas e Equipe', 'Vale Transporte'],
        ['Despesas Fixas', 'Agua'], ['Despesas Fixas', 'Aluguel'], ['Despesas Fixas', 'Aluguel de maquinas'],
        ['Despesas Fixas', 'Assistencia Juridica'], ['Despesas Fixas', 'Contabilidade'], ['Despesas Fixas', 'Convenio medico'],
        ['Despesas Fixas', 'Distribuicao de lucros'], ['Despesas Fixas', 'Financiamento Carro'], ['Despesas Fixas', 'Fundo de emergencia'],
        ['Despesas Fixas', 'Luz'], ['Despesas Fixas', 'Manutencao'], ['Despesas Fixas', 'Medicina do Trabalho'], ['Despesas Fixas', 'Telefone'],
        ['Despesas Fixas', 'Limpeza'], ['Despesas Fixas', 'Manutencao de equipamentos'], ['Despesas Fixas', 'Material de escritorio'],
        ['Despesas Fixas', 'Mini Lanchinhos'], ['Despesas Fixas', 'Obras - Antiga e nova'], ['Despesas Fixas', 'Pao / Padaria'],
        ['Despesas Fixas', 'Passagem aereas'], ['Despesas Fixas', 'Publicidade'], ['Despesas Fixas', 'Rescisoes trabalhistas'],
        ['Despesas Fixas', 'Salgados'], ['Despesas Fixas', 'Seguro Patrimonial'], ['Despesas Fixas', 'Sistemas em geral'],
        ['Despesas Fixas', 'Taxas bancarias'], ['Despesas Fixas', 'Taxas Cobranca'], ['Despesas Fixas', 'Taxas Maquininhas'],
        ['Despesas Fixas', 'Telefone celular'], ['Despesas Fixas', 'Telefone fixo'], ['Despesas Fixas', 'Toalhas'],
        ['Despesas Fixas', 'Translado'], ['Despesas Fixas', 'Transportes (Uber e etc)'], ['Despesas Fixas', 'Treinamentos'],
        ['Despesas Fixas', 'Uber'], ['Despesas Fixas', 'Vale Alimentacao'], ['Despesas Fixas', 'Vale Transporte'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO financeiro_categorias (grupo, nome, tipo, ordem, ativo, updated_at)
        VALUES (:grupo, :nome, 'despesa', :ordem, TRUE, NOW())
        ON CONFLICT (tipo, grupo, nome) DO UPDATE SET ativo = TRUE, ordem = EXCLUDED.ordem, updated_at = NOW()
    ");
    $ordens = [];
    foreach ($defaults as $item) {
        $grupo = $item[0];
        $ordens[$grupo] = ($ordens[$grupo] ?? 0) + 10;
        $stmt->execute([':grupo' => $grupo, ':nome' => $item[1], ':ordem' => $ordens[$grupo]]);
    }
}

cf_ensure_schema($pdo);
cf_seed_defaults($pdo);

$success = '';
$errors = [];
$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE financeiro_categorias SET ativo = NOT ativo, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $_SESSION['cf_success'] = 'Status da categoria atualizado.';
            header('Location: index.php?page=cadastros_categorias_financeiras');
            exit;
        } catch (Throwable $e) {
            error_log('categorias financeiras toggle: ' . $e->getMessage());
            $errors[] = 'Nao foi possivel alterar o status.';
        }
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $grupo = trim((string)($_POST['grupo'] ?? 'Geral')) ?: 'Geral';
        $tipo = trim((string)($_POST['tipo'] ?? 'despesa'));
        $ordem = (int)($_POST['ordem'] ?? 0);
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $ativo = !empty($_POST['ativo']);

        if ($nome === '') {
            $errors[] = 'Informe o nome da categoria.';
        }
        if (!in_array($tipo, ['receita', 'despesa', 'ambos'], true)) {
            $errors[] = 'Tipo invalido.';
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE financeiro_categorias
                        SET nome = :nome, grupo = :grupo, tipo = :tipo, ordem = :ordem, ativo = :ativo,
                            descricao = :descricao, updated_at = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $id,
                        ':nome' => $nome,
                        ':grupo' => $grupo,
                        ':tipo' => $tipo,
                        ':ordem' => $ordem,
                        ':ativo' => $ativo,
                        ':descricao' => $descricao !== '' ? $descricao : null,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO financeiro_categorias (nome, grupo, tipo, ordem, ativo, descricao, created_by, created_at, updated_at)
                        VALUES (:nome, :grupo, :tipo, :ordem, :ativo, :descricao, :created_by, NOW(), NOW())
                        ON CONFLICT (tipo, grupo, nome) DO UPDATE SET
                            ordem = EXCLUDED.ordem,
                            ativo = EXCLUDED.ativo,
                            descricao = EXCLUDED.descricao,
                            updated_at = NOW()
                    ");
                    $stmt->execute([
                        ':nome' => $nome,
                        ':grupo' => $grupo,
                        ':tipo' => $tipo,
                        ':ordem' => $ordem,
                        ':ativo' => $ativo,
                        ':descricao' => $descricao !== '' ? $descricao : null,
                        ':created_by' => $userId > 0 ? $userId : null,
                    ]);
                }
                $_SESSION['cf_success'] = 'Categoria salva com sucesso.';
                header('Location: index.php?page=cadastros_categorias_financeiras');
                exit;
            } catch (Throwable $e) {
                error_log('categorias financeiras save: ' . $e->getMessage());
                $errors[] = 'Nao foi possivel salvar a categoria.';
            }
        }
    }
}

if (!empty($_SESSION['cf_success'])) {
    $success = (string)$_SESSION['cf_success'];
    unset($_SESSION['cf_success']);
}

$q = trim((string)($_GET['q'] ?? ''));
$grupoFiltro = trim((string)($_GET['grupo'] ?? ''));
$statusFiltro = trim((string)($_GET['status'] ?? ''));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(nome ILIKE :q OR grupo ILIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($grupoFiltro !== '') {
    $where[] = 'grupo = :grupo';
    $params[':grupo'] = $grupoFiltro;
}
if ($statusFiltro === 'ativo') {
    $where[] = 'ativo IS TRUE';
} elseif ($statusFiltro === 'inativo') {
    $where[] = 'ativo IS FALSE';
}

$sql = 'SELECT * FROM financeiro_categorias';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY ativo DESC, grupo ASC, ordem ASC, nome ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$grupos = $pdo->query("SELECT DISTINCT grupo FROM financeiro_categorias ORDER BY grupo")->fetchAll(PDO::FETCH_COLUMN) ?: [];

includeSidebar('Categorias Financeiras');
?>

<style>
.cf-page{padding:1.5rem;max-width:1380px;margin:0 auto;color:#334155}
.cf-header{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1rem}
.cf-title{margin:0;color:#1e3a8a;font-size:1.8rem;font-weight:900}.cf-subtitle{margin:.35rem 0 0;color:#64748b}
.cf-actions{display:flex;gap:.65rem;flex-wrap:wrap}.cf-btn{border-radius:8px;text-decoration:none;font-weight:900;padding:.7rem 1rem;display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe3ef;cursor:pointer;background:#fff;color:#334155;font:inherit}.cf-btn.primary{background:#1e3a8a;color:#fff;border-color:#1e3a8a}
.cf-alert{margin-bottom:1rem;border-radius:8px;padding:.85rem 1rem;font-weight:800}.cf-alert.success{background:#ecfdf5;color:#166534;border:1px solid #a7f3d0}.cf-alert.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
.cf-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 14px 34px rgba(15,23,42,.07);overflow:hidden}.cf-card-header{padding:1rem;border-bottom:1px solid #e2e8f0;background:#f8fbff}
.cf-filters{display:grid;grid-template-columns:1.4fr 1fr 150px auto;gap:.7rem;align-items:end}.cf-field{display:grid;gap:.35rem}.cf-field label{font-weight:800;font-size:.82rem;color:#475569}.cf-field input,.cf-field select,.cf-field textarea{width:100%;border:1px solid #d1d9e6;border-radius:8px;padding:.68rem .78rem;color:#1e293b;background:#fff;font:inherit}.cf-field textarea{min-height:78px}
.cf-table-wrap{overflow:auto}.cf-table{width:100%;border-collapse:collapse;min-width:920px}.cf-table th,.cf-table td{padding:.82rem;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:middle}.cf-table th{background:#f8fafc;color:#475569;font-size:.78rem;text-transform:uppercase}.cf-name{font-weight:900;color:#1e293b}.cf-muted{color:#64748b;font-size:.86rem}.cf-pill{display:inline-flex;border-radius:999px;padding:.24rem .6rem;font-size:.76rem;font-weight:900}.cf-pill.on{background:#dcfce7;color:#166534}.cf-pill.off{background:#fee2e2;color:#991b1b}.cf-pill.type{background:#e0f2fe;color:#075985}.cf-row-actions{display:flex;gap:.45rem;flex-wrap:wrap}.cf-action{border:1px solid #dbe3ef;background:#fff;border-radius:8px;padding:.42rem .62rem;cursor:pointer;font-weight:800;color:#334155}
.cf-modal-backdrop{position:fixed;inset:0;z-index:1000;display:none;align-items:center;justify-content:center;padding:1rem;background:rgba(15,23,42,.55)}.cf-modal-backdrop.open{display:flex}.cf-modal{width:min(620px,100%);max-height:calc(100vh - 2rem);overflow:auto;background:#fff;border-radius:12px;box-shadow:0 24px 70px rgba(15,23,42,.28)}.cf-modal-header{padding:1rem 1.1rem;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;gap:1rem;align-items:center}.cf-modal-title{margin:0;color:#1e293b;font-weight:900;font-size:1.15rem}.cf-modal-close{width:36px;height:36px;border:none;border-radius:999px;background:#f1f5f9;color:#334155;cursor:pointer;font-size:1.25rem}.cf-form{padding:1rem;display:grid;gap:.85rem}.cf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.85rem}.cf-full{grid-column:1/-1}.cf-check{display:flex;align-items:center;gap:.5rem;font-weight:800}.cf-check input{width:auto}.cf-modal-actions{display:flex;justify-content:flex-end;gap:.65rem;border-top:1px solid #e2e8f0;padding:1rem}
@media(max-width:760px){.cf-filters,.cf-grid{grid-template-columns:1fr}.cf-page{padding:1rem}}
</style>

<main class="cf-page">
    <div class="cf-header">
        <div>
            <h1 class="cf-title">Categorias Financeiras</h1>
            <p class="cf-subtitle">Cadastre, edite e desative categorias para os lancamentos financeiros.</p>
        </div>
        <div class="cf-actions">
            <a class="cf-btn" href="index.php?page=cadastros">← Cadastros</a>
            <button class="cf-btn primary" type="button" data-open-cf-modal>+ Nova categoria</button>
        </div>
    </div>

    <?php if ($success !== ''): ?><div class="cf-alert success"><?= cf_h($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="cf-alert error"><?= cf_h((string)$error) ?></div><?php endforeach; ?>

    <section class="cf-card">
        <div class="cf-card-header">
            <form class="cf-filters" method="get">
                <input type="hidden" name="page" value="cadastros_categorias_financeiras">
                <div class="cf-field"><label>Buscar</label><input name="q" value="<?= cf_h($q) ?>" placeholder="Nome ou grupo"></div>
                <div class="cf-field"><label>Grupo</label><select name="grupo"><option value="">Todos</option><?php foreach ($grupos as $grupo): ?><option value="<?= cf_h((string)$grupo) ?>" <?= $grupoFiltro === (string)$grupo ? 'selected' : '' ?>><?= cf_h((string)$grupo) ?></option><?php endforeach; ?></select></div>
                <div class="cf-field"><label>Status</label><select name="status"><option value="">Todos</option><option value="ativo" <?= $statusFiltro === 'ativo' ? 'selected' : '' ?>>Ativas</option><option value="inativo" <?= $statusFiltro === 'inativo' ? 'selected' : '' ?>>Inativas</option></select></div>
                <button class="cf-btn" type="submit">Filtrar</button>
            </form>
        </div>
        <div class="cf-table-wrap">
            <table class="cf-table">
                <thead><tr><th>Categoria</th><th>Grupo</th><th>Tipo</th><th>Ordem</th><th>Status</th><th>Acoes</th></tr></thead>
                <tbody>
                    <?php foreach ($categorias as $categoria): ?>
                        <tr>
                            <td><span class="cf-name"><?= cf_h((string)$categoria['nome']) ?></span><?php if (!empty($categoria['descricao'])): ?><div class="cf-muted"><?= cf_h((string)$categoria['descricao']) ?></div><?php endif; ?></td>
                            <td><?= cf_h((string)$categoria['grupo']) ?></td>
                            <td><span class="cf-pill type"><?= cf_h((string)$categoria['tipo']) ?></span></td>
                            <td><?= (int)$categoria['ordem'] ?></td>
                            <td><span class="cf-pill <?= !empty($categoria['ativo']) ? 'on' : 'off' ?>"><?= !empty($categoria['ativo']) ? 'Ativa' : 'Inativa' ?></span></td>
                            <td>
                                <div class="cf-row-actions">
                                    <button class="cf-action" type="button" data-edit-cf
                                        data-id="<?= (int)$categoria['id'] ?>"
                                        data-nome="<?= cf_h((string)$categoria['nome']) ?>"
                                        data-grupo="<?= cf_h((string)$categoria['grupo']) ?>"
                                        data-tipo="<?= cf_h((string)$categoria['tipo']) ?>"
                                        data-ordem="<?= (int)$categoria['ordem'] ?>"
                                        data-ativo="<?= !empty($categoria['ativo']) ? '1' : '0' ?>"
                                        data-descricao="<?= cf_h((string)($categoria['descricao'] ?? '')) ?>"
                                    >Editar</button>
                                    <form method="post">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$categoria['id'] ?>">
                                        <button class="cf-action" type="submit"><?= !empty($categoria['ativo']) ? 'Desativar' : 'Ativar' ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$categorias): ?><tr><td colspan="6" class="cf-muted">Nenhuma categoria cadastrada.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<div class="cf-modal-backdrop" id="cf-modal" role="dialog" aria-modal="true" aria-labelledby="cf-modal-title">
    <div class="cf-modal">
        <div class="cf-modal-header">
            <h2 class="cf-modal-title" id="cf-modal-title">Nova categoria</h2>
            <button class="cf-modal-close" type="button" data-close-cf-modal aria-label="Fechar">×</button>
        </div>
        <form method="post" class="cf-form">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="cf-id" value="0">
            <div class="cf-grid">
                <div class="cf-field cf-full"><label>Nome</label><input name="nome" id="cf-nome" maxlength="180" required></div>
                <div class="cf-field"><label>Grupo</label><input name="grupo" id="cf-grupo" list="cf-grupos" value="Geral" maxlength="120"><datalist id="cf-grupos"><?php foreach ($grupos as $grupo): ?><option value="<?= cf_h((string)$grupo) ?>"><?php endforeach; ?></datalist></div>
                <div class="cf-field"><label>Tipo</label><select name="tipo" id="cf-tipo"><option value="despesa">Despesa</option><option value="receita">Receita</option><option value="ambos">Ambos</option></select></div>
                <div class="cf-field"><label>Ordem</label><input type="number" name="ordem" id="cf-ordem" value="0"></div>
                <label class="cf-check"><input type="checkbox" name="ativo" id="cf-ativo" value="1" checked> Categoria ativa</label>
                <div class="cf-field cf-full"><label>Descricao</label><textarea name="descricao" id="cf-descricao"></textarea></div>
            </div>
            <div class="cf-modal-actions">
                <button class="cf-btn" type="button" data-close-cf-modal>Cancelar</button>
                <button class="cf-btn primary" type="submit">Salvar categoria</button>
            </div>
        </form>
    </div>
</div>

<script>
const cfModal = document.getElementById('cf-modal');
const cfTitle = document.getElementById('cf-modal-title');
function openCfModal(data = null) {
    document.getElementById('cf-id').value = data?.id || '0';
    document.getElementById('cf-nome').value = data?.nome || '';
    document.getElementById('cf-grupo').value = data?.grupo || 'Geral';
    document.getElementById('cf-tipo').value = data?.tipo || 'despesa';
    document.getElementById('cf-ordem').value = data?.ordem || '0';
    document.getElementById('cf-ativo').checked = data ? data.ativo === '1' : true;
    document.getElementById('cf-descricao').value = data?.descricao || '';
    cfTitle.textContent = data ? 'Editar categoria' : 'Nova categoria';
    cfModal.classList.add('open');
}
document.querySelector('[data-open-cf-modal]')?.addEventListener('click', () => openCfModal());
document.querySelectorAll('[data-edit-cf]').forEach((button) => button.addEventListener('click', () => openCfModal(button.dataset)));
document.querySelectorAll('[data-close-cf-modal]').forEach((button) => button.addEventListener('click', () => cfModal.classList.remove('open')));
cfModal?.addEventListener('click', (event) => { if (event.target === cfModal) cfModal.classList.remove('open'); });
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') cfModal?.classList.remove('open'); });
</script>

<?php endSidebar(); ?>
