<?php
// logistica_separacao_lista.php — Separação/Distribuição por lista
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

$errors = [];
$messages = [];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $errors[] = 'Lista inválida.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0) {
    $action = $_POST['action'] ?? '';

    if ($action === 'marcar_garden') {
        $lista_check = $pdo->prepare("SELECT * FROM logistica_listas WHERE id = :id");
        $lista_check->execute([':id' => $id]);
        $lista_check = $lista_check->fetch(PDO::FETCH_ASSOC);

        $pdo->prepare("
            UPDATE logistica_listas
            SET separado_garden = TRUE, separado_em = NOW(), separado_por = :uid,
                status = CASE
                    WHEN status = 'gerada' THEN 'separacao_em_andamento'
                    ELSE status
                END
            WHERE id = :id
        ")->execute([
            ':id' => $id,
            ':uid' => (int)($_SESSION['id'] ?? 0)
        ]);

        if ($lista_check && ($lista_check['unidade_interna_id'] ?? null)) {
            $destino = $pdo->prepare("SELECT codigo FROM logistica_unidades WHERE id = :id");
            $destino->execute([':id' => (int)$lista_check['unidade_interna_id']]);
            $codigo = $destino->fetchColumn();
            if ($codigo === 'GardenCentral') {
                $pdo->prepare("UPDATE logistica_listas SET status = 'pronta' WHERE id = :id")
                    ->execute([':id' => $id]);
            }
        }
        $messages[] = 'Garden marcado como separado.';
    }

    if ($action === 'gerar_transferencias') {
        try {
            $pdo->beginTransaction();
            $lista = $pdo->prepare("SELECT * FROM logistica_listas WHERE id = :id FOR UPDATE");
            $lista->execute([':id' => $id]);
            $lista = $lista->fetch(PDO::FETCH_ASSOC);
            if (!$lista) {
                throw new RuntimeException('Lista não encontrada.');
            }

            $stmt = $pdo->prepare("
                SELECT * FROM logistica_lista_eventos WHERE lista_id = :id
            ");
            $stmt->execute([':id' => $id]);
            $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$eventos) {
                throw new RuntimeException('Lista sem eventos.');
            }

            $destino_unidade = (int)($lista['unidade_interna_id'] ?? 0);
            if ($destino_unidade <= 0) {
                throw new RuntimeException('Unidade da lista não definida.');
            }

            $stmt = $pdo->prepare("SELECT id, codigo FROM logistica_unidades WHERE id = :id");
            $stmt->execute([':id' => $destino_unidade]);
            $destino_info = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$destino_info) {
                throw new RuntimeException('Unidade destino não encontrada.');
            }

            $space_visivel = $lista['space_visivel'] ?? null;
            if ($destino_info['codigo'] === 'GardenCentral' && $space_visivel !== 'Cristal') {
                $messages[] = 'Lista Garden/Cristal: não é necessária transferência.';
            } else {
                $itens = $pdo->prepare("
                    SELECT li.insumo_id, li.quantidade_total_bruto, li.unidade_medida_id
                    FROM logistica_lista_itens li
                    WHERE li.lista_id = :id
                ");
                $itens->execute([':id' => $id]);
                $itens = $itens->fetchAll(PDO::FETCH_ASSOC);

                if (!$itens) {
                    throw new RuntimeException('Lista sem itens.');
                }

                $origin = $pdo->query("SELECT id FROM logistica_unidades WHERE codigo = 'GardenCentral'")->fetchColumn();
                if (!$origin) {
                    throw new RuntimeException('Unidade GardenCentral não encontrada.');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO logistica_transferencias
                        (unidade_origem_id, unidade_destino_id, status, criado_por, criado_em, observacao, space_destino, lista_id)
                    VALUES
                        (:origem, :destino, 'rascunho', :criado_por, NOW(), :observacao, :space_destino, :lista_id)
                    RETURNING id
                ");
                $stmt->execute([
                    ':origem' => (int)$origin,
                    ':destino' => $destino_unidade,
                    ':criado_por' => (int)($_SESSION['id'] ?? 0),
                    ':observacao' => 'Gerada a partir da lista #' . $id,
                    ':space_destino' => $space_visivel,
                    ':lista_id' => $id
                ]);
                $transfer_id = (int)$stmt->fetchColumn();

                $stmt_item = $pdo->prepare("
                    INSERT INTO logistica_transferencias_itens
                        (transferencia_id, insumo_id, quantidade, unidade_medida_id, check_carregado, check_recebido)
                    VALUES
                        (:tid, :insumo_id, :quantidade, :unidade_medida_id, FALSE, FALSE)
                ");
                foreach ($itens as $it) {
                    $stmt_item->execute([
                        ':tid' => $transfer_id,
                        ':insumo_id' => (int)$it['insumo_id'],
                        ':quantidade' => (float)$it['quantidade_total_bruto'],
                        ':unidade_medida_id' => $it['unidade_medida_id'] ?: null
                    ]);
                }

                $pdo->prepare("UPDATE logistica_listas SET status = 'separacao_em_andamento' WHERE id = :id")
                    ->execute([':id' => $id]);
                $messages[] = 'Transferência gerada (rascunho).';
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Erro ao gerar transferência: ' . $e->getMessage();
        }
    }
}

$lista = null;
$lista_eventos = [];
$lista_itens = [];
$transfer_stats = ['total' => 0, 'recebidas' => 0];

if ($id > 0) {
    $stmt = $pdo->prepare("
        SELECT l.*, u.nome AS unidade_nome
        FROM logistica_listas l
        LEFT JOIN logistica_unidades u ON u.id = l.unidade_interna_id
        WHERE l.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $lista = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($lista) {
        $stmt = $pdo->prepare("SELECT * FROM logistica_lista_eventos WHERE lista_id = :id ORDER BY data_evento ASC, hora_inicio ASC NULLS LAST");
        $stmt->execute([':id' => $id]);
        $lista_eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT li.*, i.nome_oficial, u.nome AS unidade_nome
            FROM logistica_lista_itens li
            LEFT JOIN logistica_insumos i ON i.id = li.insumo_id
            LEFT JOIN logistica_unidades_medida u ON u.id = li.unidade_medida_id
            WHERE li.lista_id = :id
            ORDER BY i.nome_oficial
        ");
        $stmt->execute([':id' => $id]);
        $lista_itens = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'recebida' THEN 1 ELSE 0 END) AS recebidas
            FROM logistica_transferencias
            WHERE lista_id = :id
        ");
        $stmt->execute([':id' => $id]);
        $transfer_stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: $transfer_stats;
    }
}

includeSidebar('Separação - Logística');
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
    <h1>Separação da Lista</h1>

    <?php foreach ($messages as $message): ?>
        <div class="alert alert-success"><?= h($message) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if ($lista): ?>
        <div class="section-card">
            <h2>Lista #<?= (int)$lista['id'] ?> · Status: <?= h($lista['status'] ?? 'gerada') ?></h2>
            <p>
                Unidade: <?= h($lista['unidade_nome'] ?? '') ?>
                <?= $lista['space_visivel'] ? '· ' . h($lista['space_visivel']) : '' ?>
                · Gerada em <?= h(date('d/m/Y H:i', strtotime($lista['criado_em']))) ?>
            </p>
            <?php
                $separado_por_nome = '-';
                if (!empty($lista['separado_por'])) {
                    $stmt = $pdo->prepare("SELECT nome FROM usuarios WHERE id = :id");
                    $stmt->execute([':id' => (int)$lista['separado_por']]);
                    $separado_por_nome = $stmt->fetchColumn() ?: '-';
                }
            ?>
            <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:.75rem;">
                <div>Garden separado: <?= $lista['separado_garden'] ? '✅' : '❌' ?></div>
                <div>Transferências geradas: <?= (int)($transfer_stats['total'] ?? 0) ?></div>
                <div>Transferências recebidas: <?= (int)($transfer_stats['recebidas'] ?? 0) ?> de <?= (int)($transfer_stats['total'] ?? 0) ?></div>
                <div>Separado por: <?= h($separado_por_nome) ?> · <?= $lista['separado_em'] ? h(date('d/m/Y H:i', strtotime($lista['separado_em']))) : '-' ?></div>
            </div>

            <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:1rem;">
                <form method="post">
                    <input type="hidden" name="action" value="gerar_transferencias">
                    <button class="btn-primary" type="submit">Gerar transferências a partir da lista</button>
                </form>
                <form method="post">
                    <input type="hidden" name="action" value="marcar_garden">
                    <button class="btn-secondary" type="submit">Marcar Garden como separado</button>
                </form>
                <button class="btn-secondary" type="button" id="copy-checklist">Copiar checklist</button>
            </div>
        </div>

        <div class="section-card">
            <h3>Eventos vinculados</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Hora</th>
                        <th>Nome</th>
                        <th>Local</th>
                        <th>Unidade</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_eventos as $ev): ?>
                        <tr>
                            <td><?= h(date('d/m/Y', strtotime($ev['data_evento']))) ?></td>
                            <td><?= h($ev['hora_inicio'] ?? '') ?></td>
                            <td><?= h($ev['nome_evento'] ?? 'Evento') ?></td>
                            <td><?= h($ev['localevento'] ?? '') ?></td>
                            <td><?= h($ev['space_visivel'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section-card">
            <h3>Itens consolidados</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Unidade</th>
                        <th>Quantidade total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lista_itens as $it): ?>
                        <tr>
                            <td><?= h($it['nome_oficial'] ?? '') ?></td>
                            <td><?= h($it['unidade_nome'] ?? '') ?></td>
                            <td><?= number_format((float)$it['quantidade_total_bruto'], 4, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($lista_itens): ?>
<script>
document.getElementById('copy-checklist').addEventListener('click', () => {
    const items = <?= json_encode(array_map(function ($it) {
        return [
            'nome' => $it['nome_oficial'] ?? '',
            'quantidade' => $it['quantidade_total_bruto'] ?? 0,
            'unidade' => $it['unidade_nome'] ?? ''
        ];
    }, $lista_itens), JSON_UNESCAPED_UNICODE) ?>;
    const lines = items.map(i => `${i.nome} - ${Number(i.quantidade).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 4 })} ${i.unidade}`);
    const text = `Checklist da lista #<?= (int)$lista['id'] ?>\n` + lines.join('\n');
    navigator.clipboard.writeText(text).then(() => {
        alert('Checklist copiado.');
    });
});
</script>
<?php endif; ?>

<?php endSidebar(); ?>
