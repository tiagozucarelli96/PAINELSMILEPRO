<?php
// logistica_resolver_conflitos.php — Resolver conflitos de listas prontas
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/logistica_alertas_helper.php';

if (empty($_SESSION['perm_superadmin'])) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolver') {
    $evento_id = (int)($_POST['evento_id'] ?? 0);
    $lista_escolhida = (int)($_POST['lista_id'] ?? 0);
    if ($evento_id <= 0 || $lista_escolhida <= 0) {
        $errors[] = 'Dados inválidos para resolução.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT l.id
                FROM logistica_lista_eventos le
                JOIN logistica_listas l ON l.id = le.lista_id
                WHERE le.evento_id = :eid AND l.status = 'pronta' AND l.excluida IS FALSE
            ");
            $stmt->execute([':eid' => $evento_id]);
            $listas_prontas = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $rebaixadas = array_values(array_filter($listas_prontas, fn($id) => (int)$id !== $lista_escolhida));

            $pdo->prepare("UPDATE logistica_listas SET status = 'pronta' WHERE id = :id")->execute([':id' => $lista_escolhida]);
            if ($rebaixadas) {
                $placeholders = implode(',', array_fill(0, count($rebaixadas), '?'));
                $stmt = $pdo->prepare("UPDATE logistica_listas SET status = 'gerada' WHERE id IN ($placeholders)");
                $stmt->execute($rebaixadas);
            }

            $msg = 'Conflito resolvido. Evento ' . $evento_id . ', lista escolhida ' . $lista_escolhida;
            if ($rebaixadas) {
                $msg .= ', listas rebaixadas: ' . implode(',', $rebaixadas);
            }

            $stmt = $pdo->prepare("
                INSERT INTO logistica_alertas_log
                    (tipo, unidade_id, referencia_tipo, referencia_id, mensagem, criado_em, criado_por)
                VALUES
                    ('conflito_resolvido', NULL, 'evento', :eid, :mensagem, NOW(), :uid)
            ");
            $stmt->execute([
                ':eid' => $evento_id,
                ':mensagem' => $msg,
                ':uid' => (int)($_SESSION['id'] ?? 0)
            ]);

            $pdo->commit();
            $messages[] = 'Conflito resolvido com sucesso.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = 'Erro ao resolver conflito: ' . $e->getMessage();
        }
    }
}

$conflitos = $pdo->query("
    SELECT le.evento_id, e.nome_evento, e.data_evento, e.hora_inicio, e.space_visivel,
           COUNT(*) AS total_listas
    FROM logistica_lista_eventos le
    JOIN logistica_listas l ON l.id = le.lista_id
    JOIN logistica_eventos_espelho e ON e.id = le.evento_id
    WHERE l.status = 'pronta' AND l.excluida IS FALSE
    GROUP BY le.evento_id, e.nome_evento, e.data_evento, e.hora_inicio, e.space_visivel
    HAVING COUNT(*) > 1
    ORDER BY e.data_evento ASC, e.hora_inicio ASC NULLS LAST
")->fetchAll(PDO::FETCH_ASSOC);

$eventos_sem_pronta = logistica_fetch_eventos_proximos($pdo, 7, false, 0);
$eventos_sem_pronta = array_filter($eventos_sem_pronta, function ($ev) use ($pdo) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM logistica_lista_eventos le
        JOIN logistica_listas l ON l.id = le.lista_id
        WHERE le.evento_id = :eid AND l.status = 'pronta' AND l.excluida IS FALSE
    ");
    $stmt->execute([':eid' => (int)$ev['id']]);
    return (int)$stmt->fetchColumn() === 0;
});

includeSidebar('Resolver Conflitos - Logística');
?>

<style>
.page-container { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
.section-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.table { width:100%; border-collapse:collapse; }
.table th,.table td { padding:.6rem; border-bottom:1px solid #e5e7eb; }
.btn-secondary { background:#e2e8f0; color:#0f172a; border:none; padding:.5rem .9rem; border-radius:8px; cursor:pointer; }
.btn-primary { background:#2563eb; color:#fff; border:none; padding:.5rem .9rem; border-radius:8px; cursor:pointer; }
.alert-success { background:#dcfce7; color:#166534; padding:.6rem .9rem; border-radius:8px; margin-bottom:1rem; }
.alert-error { background:#fee2e2; color:#991b1b; padding:.6rem .9rem; border-radius:8px; margin-bottom:1rem; }
</style>

<div class="page-container">
    <h1>Resolver Conflitos</h1>

    <?php foreach ($messages as $m): ?>
        <div class="alert-success"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
        <div class="alert-error"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <div class="section-card">
        <h2>Conflitos de listas prontas</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Evento</th>
                    <th>Data/Hora</th>
                    <th>Unidade</th>
                    <th>Qtde listas prontas</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$conflitos): ?>
                    <tr><td colspan="5">Nenhum conflito encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($conflitos as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['nome_evento'] ?? 'Evento') ?></td>
                            <td><?= format_date($c['data_evento'] ?? null, 'd/m/Y') ?> <?= htmlspecialchars($c['hora_inicio'] ?? '') ?></td>
                            <td><?= htmlspecialchars($c['space_visivel'] ?? '') ?></td>
                            <td><?= (int)$c['total_listas'] ?></td>
                            <td><a class="btn-secondary" href="#resolver-<?= (int)$c['evento_id'] ?>">Resolver</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php foreach ($conflitos as $c): ?>
            <?php
                $stmt = $pdo->prepare("
                    SELECT l.id, l.criado_em, l.criado_por, l.status
                    FROM logistica_lista_eventos le
                    JOIN logistica_listas l ON l.id = le.lista_id
                    WHERE le.evento_id = :eid AND l.status = 'pronta' AND l.excluida IS FALSE
                    ORDER BY l.criado_em DESC
                ");
                $stmt->execute([':eid' => (int)$c['evento_id']]);
                $listas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>
            <div class="section-card" id="resolver-<?= (int)$c['evento_id'] ?>">
                <h3><?= htmlspecialchars($c['nome_evento'] ?? 'Evento') ?> — listas prontas</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Lista</th>
                            <th>Criada em</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($listas as $l): ?>
                            <tr>
                                <td>#<?= (int)$l['id'] ?></td>
                                <td><?= format_date($l['criado_em'] ?? null) ?></td>
                                <td><?= htmlspecialchars($l['status']) ?></td>
                                <td>
                                    <a class="btn-secondary" href="index.php?page=logistica_listas&list_id=<?= (int)$l['id'] ?>">Ver lista</a>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="action" value="resolver">
                                        <input type="hidden" name="evento_id" value="<?= (int)$c['evento_id'] ?>">
                                        <input type="hidden" name="lista_id" value="<?= (int)$l['id'] ?>">
                                        <button class="btn-primary" type="submit">Definir como ÚNICA PRONTA</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="section-card">
        <h2>Eventos próximos sem lista pronta (7 dias)</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Evento</th>
                    <th>Data/Hora</th>
                    <th>Unidade</th>
                    <th>Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$eventos_sem_pronta): ?>
                    <tr><td colspan="4">Nenhum evento pendente.</td></tr>
                <?php else: ?>
                    <?php foreach ($eventos_sem_pronta as $e): ?>
                        <tr>
                            <td><?= htmlspecialchars($e['nome_evento'] ?? 'Evento') ?></td>
                            <td><?= format_date($e['data_evento'] ?? null, 'd/m/Y') ?> <?= htmlspecialchars($e['hora_inicio'] ?? '') ?></td>
                            <td><?= htmlspecialchars($e['space_visivel'] ?? '') ?></td>
                            <td><a class="btn-secondary" href="index.php?page=logistica_gerar_lista">Abrir gerador</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endSidebar(); ?>
