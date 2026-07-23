<?php
/**
 * Tarefas do cliente no checklist de planejamento.
 * A rota permanece indisponível enquanto a configuração global estiver desligada.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/eventos_checklist_planejamento_helper.php';

function ecc_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$config = eventos_checklist_planejamento_config($pdo);
$error = '';
$portal = null;
$reuniao = null;
$evento = null;
$eventoId = 0;

if (empty($config['portal_cliente_ativo'])) {
    $error = 'Esta área ainda não está disponível.';
} elseif ($token === '') {
    $error = 'Link inválido.';
} else {
    $portal = eventos_cliente_portal_get_by_token($pdo, $token);
    if (!$portal || empty($portal['is_active'])) {
        $error = 'Portal não encontrado ou desativado.';
    } else {
        $reuniao = eventos_reuniao_get($pdo, (int)$portal['meeting_id']);
        if (!$reuniao) {
            $error = 'Evento não encontrado.';
        } else {
            $stmt = $pdo->prepare("
                SELECT e.id
                FROM logistica_eventos_espelho e
                WHERE e.me_event_id = :me_event_id
                ORDER BY e.updated_at DESC NULLS LAST, e.id DESC
                LIMIT 1
            ");
            $stmt->execute([':me_event_id' => (int)$reuniao['me_event_id']]);
            $eventoId = (int)$stmt->fetchColumn();
            $evento = $eventoId > 0 ? eventos_checklist_planejamento_evento($pdo, $eventoId) : null;
            if (!$evento) {
                $error = 'Checklist ainda não disponível para este evento.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '' && (string)($_POST['action'] ?? '') === 'complete_task') {
    $taskId = (int)($_POST['tarefa_id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_checklist_tarefas
        WHERE id = :id
          AND evento_id = :evento_id
          AND responsabilidade = 'cliente'
          AND visivel_cliente = TRUE
          AND status IN ('pendente', 'em_andamento')
        LIMIT 1
    ");
    $stmt->execute([':id' => $taskId, ':evento_id' => $eventoId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($task) {
        $next = !empty($task['exige_validacao']) ? 'aguardando_validacao' : 'concluida';
        $pdo->prepare("
            UPDATE eventos_checklist_tarefas
            SET status = :status,
                concluida_em = CASE WHEN :status = 'concluida' THEN NOW() ELSE NULL END,
                concluida_pelo_cliente = TRUE,
                updated_at = NOW()
            WHERE id = :id
        ")->execute([':status' => $next, ':id' => $taskId]);
        eventos_checklist_planejamento_historico(
            $pdo,
            $taskId,
            $eventoId,
            'cliente_marcar_concluida',
            $next === 'concluida' ? 'Cliente concluiu a tarefa.' : 'Cliente informou a conclusão; aguardando validação.',
            ['status' => $task['status']],
            ['status' => $next],
            0,
            'cliente'
        );
    }
    header('Location: index.php?page=eventos_cliente_checklist&token=' . urlencode($token) . '&ok=1');
    exit;
}

$tarefas = [];
if ($error === '') {
    $stmt = $pdo->prepare("
        SELECT *
        FROM eventos_checklist_tarefas
        WHERE evento_id = :evento_id
          AND responsabilidade = 'cliente'
          AND visivel_cliente = TRUE
          AND status <> 'desativada'
        ORDER BY vencimento ASC NULLS LAST, ordem, id
    ");
    $stmt->execute([':evento_id' => $eventoId]);
    $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tarefas do evento</title>
    <style>
    *{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,sans-serif;background:#f4f7fb;color:#0f172a}.page{max-width:860px;margin:0 auto;padding:1.2rem}.hero{background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border-radius:18px;padding:1.5rem;margin-bottom:1rem}.hero h1{margin:0;font-size:1.65rem}.hero p{margin:.45rem 0 0;opacity:.88}
    .task{background:#fff;border:1px solid #dbe3ef;border-radius:14px;padding:1rem;margin-bottom:.75rem}.task h2{font-size:1.05rem;margin:0}.meta{color:#64748b;font-size:.85rem;margin:.35rem 0}.desc{color:#475569;white-space:pre-line}.btn{border:0;border-radius:10px;background:#22c55e;color:#fff;font-weight:800;padding:.7rem 1rem;cursor:pointer}.status{display:inline-block;border-radius:999px;padding:.25rem .55rem;background:#e0e7ff;color:#3730a3;font-size:.78rem;font-weight:750}.alert{padding:1rem;border-radius:12px;background:#fff;border:1px solid #fecaca;color:#991b1b}.ok{padding:.8rem;border-radius:10px;background:#ecfdf5;color:#166534;margin-bottom:1rem}.back{display:inline-block;color:#1d4ed8;text-decoration:none;font-weight:750;margin-top:1rem}
    </style>
</head>
<body>
<main class="page">
    <?php if ($error !== ''): ?>
        <div class="alert"><?= ecc_h($error) ?></div>
    <?php else: ?>
        <section class="hero"><h1>✅ Suas tarefas</h1><p><?= ecc_h($evento['nome_evento']) ?> • <?= ecc_h(date('d/m/Y', strtotime((string)$evento['data_evento']))) ?></p></section>
        <?php if (!empty($_GET['ok'])): ?><div class="ok">Tarefa atualizada com sucesso.</div><?php endif; ?>
        <?php if (!$tarefas): ?><div class="task">Você não possui tarefas disponíveis neste momento.</div><?php endif; ?>
        <?php foreach ($tarefas as $tarefa): ?>
            <article class="task">
                <h2><?= ecc_h($tarefa['titulo']) ?></h2>
                <div class="meta"><?= $tarefa['vencimento'] ? 'Prazo: ' . ecc_h(date('d/m/Y', strtotime((string)$tarefa['vencimento']))) : 'Sem prazo definido' ?></div>
                <?php if ($tarefa['descricao'] !== ''): ?><div class="desc"><?= ecc_h($tarefa['descricao']) ?></div><?php endif; ?>
                <div style="margin-top:.75rem">
                    <?php if ($tarefa['status'] === 'concluida'): ?><span class="status">Concluída</span>
                    <?php elseif ($tarefa['status'] === 'aguardando_validacao'): ?><span class="status">Aguardando validação</span>
                    <?php else: ?>
                        <form method="post"><input type="hidden" name="token" value="<?= ecc_h($token) ?>"><input type="hidden" name="action" value="complete_task"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>"><button class="btn" type="submit">Marcar como concluída</button></form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <a class="back" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>">← Voltar ao portal</a>
    <?php endif; ?>
</main>
</body>
</html>
