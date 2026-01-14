<?php
// logistica_transferencia_ver.php — Checklist de carregamento
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_superadmin']) && empty($_SESSION['perm_logistico'])) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$errors = [];
$messages = [];
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    $errors[] = 'Transferência inválida.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_checks') {
        $checks = $_POST['carregado'] ?? [];
        $pdo->prepare("UPDATE logistica_transferencias_itens SET check_carregado = FALSE WHERE transferencia_id = :tid")
            ->execute([':tid' => $id]);
        $stmt = $pdo->prepare("
            UPDATE logistica_transferencias_itens
            SET check_carregado = :check
            WHERE transferencia_id = :tid AND insumo_id = :iid
        ");
        foreach ($checks as $insumo_id => $val) {
            $stmt->execute([
                ':check' => ($val === '1'),
                ':tid' => $id,
                ':iid' => (int)$insumo_id
            ]);
        }
        $messages[] = 'Checklist atualizado.';
    }

    if ($action === 'enviar') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN check_carregado THEN 1 ELSE 0 END) AS carregados
            FROM logistica_transferencias_itens
            WHERE transferencia_id = :tid
        ");
        $stmt->execute([':tid' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['total'] === 0) {
            $errors[] = 'Transferência sem itens.';
        } elseif ((int)$row['total'] !== (int)$row['carregados']) {
            $errors[] = 'Existem itens não carregados.';
        } else {
            $pdo->prepare("UPDATE logistica_transferencias SET status = 'em_transito', enviado_em = NOW() WHERE id = :id")
                ->execute([':id' => $id]);
            $messages[] = 'Transferência em trânsito.';
        }
    }
}

$transfer = null;
$items = [];
if ($id > 0) {
    $stmt = $pdo->prepare("
        SELECT t.*, u.nome AS destino_nome
        FROM logistica_transferencias t
        LEFT JOIN logistica_unidades u ON u.id = t.unidade_destino_id
        WHERE t.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $transfer = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($transfer) {
        $stmt = $pdo->prepare("
            SELECT ti.*, i.nome_oficial, u.nome AS unidade_nome
            FROM logistica_transferencias_itens ti
            LEFT JOIN logistica_insumos i ON i.id = ti.insumo_id
            LEFT JOIN logistica_unidades_medida u ON u.id = ti.unidade_medida_id
            WHERE ti.transferencia_id = :id
            ORDER BY i.nome_oficial
        ");
        $stmt->execute([':id' => $id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $errors[] = 'Transferência não encontrada.';
    }
}

includeSidebar('Transferência - Logística');
?>

<style>
.transfer-view {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1.5rem;
}
.card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.table { width:100%; border-collapse:collapse; }
.table th,.table td { padding:.6rem; border-bottom:1px solid #e5e7eb; }
.btn { padding:.55rem .9rem; border-radius:8px; border:none; cursor:pointer; }
.btn-primary { background:#2563eb; color:#fff; }
.btn-secondary { background:#e2e8f0; color:#0f172a; }
</style>

<div class="transfer-view">
    <h1>Transferência</h1>
    <?php foreach ($errors as $e): ?>
        <div class="alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>
    <?php foreach ($messages as $m): ?>
        <div class="alert-success"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>

    <?php if ($transfer): ?>
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div>
                <strong>Destino:</strong> <?= htmlspecialchars($transfer['space_destino'] ?: ($transfer['destino_nome'] ?? '')) ?><br>
                <strong>Status:</strong> <?= htmlspecialchars($transfer['status']) ?>
            </div>
            <a class="btn btn-secondary" href="index.php?page=logistica_transferencias">Voltar</a>
        </div>

        <form method="post" style="margin-top:1rem;">
            <input type="hidden" name="action" value="save_checks">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Quantidade</th>
                        <th>Unidade</th>
                        <th>Carregado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= htmlspecialchars($it['nome_oficial'] ?? '') ?></td>
                        <td><?= number_format((float)$it['quantidade'], 3, ',', '.') ?></td>
                        <td><?= htmlspecialchars($it['unidade_nome'] ?? '-') ?></td>
                        <td>
                            <input type="checkbox" name="carregado[<?= (int)$it['insumo_id'] ?>]" value="1" <?= !empty($it['check_carregado']) ? 'checked' : '' ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:1rem;display:flex;gap:.75rem;">
                <button class="btn btn-secondary" type="submit">Salvar checklist</button>
            </div>
        </form>

        <?php if ($transfer['status'] === 'rascunho'): ?>
        <form method="post" style="margin-top:1rem;">
            <input type="hidden" name="action" value="enviar">
            <button class="btn btn-primary" type="submit">Marcar como em trânsito</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php endSidebar(); ?>
