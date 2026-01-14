<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_listas.php — Histórico de listas de compras
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

$can_manage = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);
if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE logistica_listas SET excluida = TRUE, excluida_em = NOW(), excluida_por = :uid WHERE id = :id")
            ->execute([
                ':id' => $id,
                ':uid' => (int)($_SESSION['id'] ?? 0)
            ]);
        $messages[] = 'Lista excluída.';
    }
}

$list_id = (int)($_GET['list_id'] ?? 0);
$lista = null;
$lista_eventos = [];
$lista_itens = [];

if ($list_id > 0) {
    $stmt = $pdo->prepare("
        SELECT l.*, u.nome AS unidade_nome
        FROM logistica_listas l
        LEFT JOIN logistica_unidades u ON u.id = l.unidade_interna_id
        WHERE l.id = :id
    ");
    $stmt->execute([':id' => $list_id]);
    $lista = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($lista) {
        $stmt = $pdo->prepare("SELECT * FROM logistica_lista_eventos WHERE lista_id = :id ORDER BY data_evento ASC, hora_inicio ASC NULLS LAST");
        $stmt->execute([':id' => $list_id]);
        $lista_eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT li.*, i.nome_oficial, u.nome AS unidade_nome, t.nome AS tipologia_nome
            FROM logistica_lista_itens li
            LEFT JOIN logistica_insumos i ON i.id = li.insumo_id
            LEFT JOIN logistica_unidades_medida u ON u.id = li.unidade_medida_id
            LEFT JOIN logistica_tipologias_insumo t ON t.id = li.tipologia_insumo_id
            WHERE li.lista_id = :id
            ORDER BY t.nome, i.nome_oficial
        ");
        $stmt->execute([':id' => $list_id]);
        $lista_itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$listas = $pdo->query("
    SELECT l.id, l.criado_em, l.unidade_interna_id, l.space_visivel, l.status, u.nome AS unidade_nome,
           (SELECT COUNT(*) FROM logistica_lista_eventos le WHERE le.lista_id = l.id) AS eventos_total
    FROM logistica_listas l
    LEFT JOIN logistica_unidades u ON u.id = l.unidade_interna_id
    WHERE l.excluida IS FALSE
    ORDER BY l.criado_em DESC
")->fetchAll(PDO::FETCH_ASSOC);

includeSidebar('Logística - Histórico de Listas');
?>

<style>
.page-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}
.section-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
    text-align: left;
    padding: 0.6rem;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
}
.btn-primary {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}
.btn-secondary {
    background: #e2e8f0;
    color: #1f2937;
    border: none;
    padding: 0.5rem 0.9rem;
    border-radius: 8px;
    cursor: pointer;
}
.alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}
.alert-error { background: #fee2e2; color: #991b1b; }
.alert-success { background: #dcfce7; color: #166534; }
</style>

<div class="page-container">
    <h1>Histórico de Listas</h1>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if ($lista): ?>
        <div class="section-card">
            <h2>Lista #<?= (int)$lista['id'] ?></h2>
            <p>
                Unidade: <?= h($lista['unidade_nome'] ?? '') ?>
                <?= $lista['space_visivel'] ? '· ' . h($lista['space_visivel']) : '' ?>
                · Gerada em <?= h(date('d/m/Y H:i', strtotime($lista['criado_em']))) ?>
            </p>

            <h3>Eventos</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Hora</th>
                        <th>Nome</th>
                        <th>Local</th>
                        <th>Convidados</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_eventos as $ev): ?>
                        <tr>
                            <td><?= h(date('d/m/Y', strtotime($ev['data_evento']))) ?></td>
                            <td><?= h($ev['hora_inicio'] ?? '') ?></td>
                            <td><?= h($ev['nome_evento'] ?? 'Evento') ?></td>
                            <td><?= h($ev['localevento'] ?? '') ?></td>
                            <td><?= (int)($ev['convidados'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h3 style="margin-top:1rem;">Itens</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Tipologia</th>
                        <th>Item</th>
                        <th>Unidade</th>
                        <th>Quantidade total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_itens as $it): ?>
                        <tr>
                            <td><?= h($it['tipologia_nome'] ?? 'Sem tipologia') ?></td>
                            <td><?= h($it['nome_oficial'] ?? '') ?></td>
                            <td><?= h($it['unidade_nome'] ?? '') ?></td>
                            <td><?= number_format((float)$it['quantidade_total_bruto'], 4, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top:1rem;">
                <a class="btn-primary" href="index.php?page=logistica_lista_pdf&lista_id=<?= (int)$lista['id'] ?>" target="_blank">PDF</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="section-card">
        <h2>Listas geradas</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Data</th>
                    <th>Unidade</th>
                    <th>Eventos</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($listas as $l): ?>
                    <tr>
                        <td><?= (int)$l['id'] ?></td>
                        <td><?= h(date('d/m/Y H:i', strtotime($l['criado_em']))) ?></td>
                        <td><?= h($l['unidade_nome'] ?? '') ?></td>
                        <td><?= (int)$l['eventos_total'] ?></td>
                        <td><?= h($l['status'] ?? 'gerada') ?></td>
                        <td>
                            <a class="btn-secondary" href="index.php?page=logistica_listas&list_id=<?= (int)$l['id'] ?>">Ver</a>
                            <a class="btn-secondary" href="index.php?page=logistica_separacao_lista&id=<?= (int)$l['id'] ?>">Separação</a>
                            <a class="btn-secondary" href="index.php?page=logistica_lista_pdf&lista_id=<?= (int)$l['id'] ?>" target="_blank">PDF</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir esta lista?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                                <button class="btn-secondary" type="submit">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($listas)): ?>
                    <tr><td colspan="5">Nenhuma lista encontrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endSidebar(); ?>
