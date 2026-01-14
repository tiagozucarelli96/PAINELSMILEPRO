<?php
// logistica_transferencia_receber.php — Recebimento de transferência
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
        $checks = $_POST['recebido'] ?? [];
        $pdo->prepare("UPDATE logistica_transferencias_itens SET check_recebido = FALSE WHERE transferencia_id = :tid")
            ->execute([':tid' => $id]);
        $stmt = $pdo->prepare("
            UPDATE logistica_transferencias_itens
            SET check_recebido = :check
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

    if ($action === 'confirmar') {
        try {
            $pdo->beginTransaction();

            $transfer = $pdo->prepare("SELECT * FROM logistica_transferencias WHERE id = :id FOR UPDATE");
            $transfer->execute([':id' => $id]);
            $transfer = $transfer->fetch(PDO::FETCH_ASSOC);
            if (!$transfer) {
                throw new RuntimeException('Transferência não encontrada.');
            }
            if ($transfer['status'] !== 'em_transito') {
                throw new RuntimeException('Transferência não está em trânsito.');
            }

            $stmt = $pdo->prepare("
                SELECT ti.*, i.nome_oficial
                FROM logistica_transferencias_itens ti
                LEFT JOIN logistica_insumos i ON i.id = ti.insumo_id
                WHERE ti.transferencia_id = :id
            ");
            $stmt->execute([':id' => $id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) {
                throw new RuntimeException('Transferência sem itens.');
            }

            foreach ($items as $it) {
                if (empty($it['check_recebido'])) {
                    throw new RuntimeException('Existem itens não recebidos.');
                }
            }

            $origem_id = (int)$transfer['unidade_origem_id'];
            $destino_id = (int)$transfer['unidade_destino_id'];

            foreach ($items as $it) {
                $insumo_id = (int)$it['insumo_id'];
                $quantidade = (float)$it['quantidade'];
                if ($quantidade <= 0) { continue; }

                $saldo_stmt = $pdo->prepare("SELECT quantidade_atual FROM logistica_estoque_saldos WHERE unidade_id = :uid AND insumo_id = :iid FOR UPDATE");
                $saldo_stmt->execute([':uid' => $origem_id, ':iid' => $insumo_id]);
                $saldo_atual = (float)($saldo_stmt->fetchColumn() ?? 0);
                if ($saldo_atual < $quantidade) {
                    throw new RuntimeException('Saldo insuficiente no Garden para ' . ($it['nome_oficial'] ?? 'item') . '.');
                }

                $pdo->prepare("
                    INSERT INTO logistica_estoque_saldos (unidade_id, insumo_id, quantidade_atual, unidade_medida_id, updated_at)
                    VALUES (:uid, :iid, :quantidade, :unidade_medida_id, NOW())
                    ON CONFLICT (unidade_id, insumo_id)
                    DO UPDATE SET quantidade_atual = logistica_estoque_saldos.quantidade_atual + EXCLUDED.quantidade_atual,
                                 unidade_medida_id = COALESCE(EXCLUDED.unidade_medida_id, logistica_estoque_saldos.unidade_medida_id),
                                 updated_at = NOW()
                ")->execute([
                    ':uid' => $destino_id,
                    ':iid' => $insumo_id,
                    ':quantidade' => $quantidade,
                    ':unidade_medida_id' => $it['unidade_medida_id'] ?: null
                ]);

                $pdo->prepare("
                    UPDATE logistica_estoque_saldos
                    SET quantidade_atual = quantidade_atual - :quantidade, updated_at = NOW()
                    WHERE unidade_id = :uid AND insumo_id = :iid
                ")->execute([
                    ':quantidade' => $quantidade,
                    ':uid' => $origem_id,
                    ':iid' => $insumo_id
                ]);

                $mov = $pdo->prepare("
                    INSERT INTO logistica_estoque_movimentos
                        (unidade_id_origem, unidade_id_destino, insumo_id, tipo, quantidade, referencia_tipo, referencia_id, criado_por, criado_em, observacao)
                    VALUES
                        (:origem, NULL, :insumo_id, 'transferencia_envio', :quantidade, 'transferencia', :ref_id, :criado_por, NOW(), NULL),
                        (NULL, :destino, :insumo_id, 'transferencia_recebimento', :quantidade, 'transferencia', :ref_id, :criado_por, NOW(), NULL)
                ");
                $mov->execute([
                    ':origem' => $origem_id,
                    ':destino' => $destino_id,
                    ':insumo_id' => $insumo_id,
                    ':quantidade' => $quantidade,
                    ':ref_id' => $id,
                    ':criado_por' => (int)($_SESSION['id'] ?? 0)
                ]);
            }

            $pdo->prepare("UPDATE logistica_transferencias SET status = 'recebida', recebido_em = NOW() WHERE id = :id")
                ->execute([':id' => $id]);

            $pdo->commit();
            $messages[] = 'Transferência recebida.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Erro ao receber: ' . $e->getMessage();
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

includeSidebar('Receber Transferência - Logística');
?>

<style>
.transfer-view { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }
.card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.5rem; box-shadow:0 2px 8px rgba(0,0,0,0.08); }
.table { width:100%; border-collapse:collapse; }
.table th,.table td { padding:.6rem; border-bottom:1px solid #e5e7eb; }
.btn { padding:.55rem .9rem; border-radius:8px; border:none; cursor:pointer; }
.btn-primary { background:#2563eb; color:#fff; }
.btn-secondary { background:#e2e8f0; color:#0f172a; }
</style>

<div class="transfer-view">
    <h1>Recebimento</h1>
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
                        <th>Recebido</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= htmlspecialchars($it['nome_oficial'] ?? '') ?></td>
                        <td><?= number_format((float)$it['quantidade'], 3, ',', '.') ?></td>
                        <td><?= htmlspecialchars($it['unidade_nome'] ?? '-') ?></td>
                        <td>
                            <input type="checkbox" name="recebido[<?= (int)$it['insumo_id'] ?>]" value="1" <?= !empty($it['check_recebido']) ? 'checked' : '' ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:1rem;display:flex;gap:.75rem;">
                <button class="btn btn-secondary" type="submit">Salvar checklist</button>
            </div>
        </form>

        <?php if ($transfer['status'] === 'em_transito'): ?>
        <form method="post" style="margin-top:1rem;">
            <input type="hidden" name="action" value="confirmar">
            <button class="btn btn-primary" type="submit">Confirmar recebimento</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php endSidebar(); ?>
