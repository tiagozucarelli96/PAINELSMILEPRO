<?php
/**
 * evento_documento_minuta.php
 * Visualização pública da minuta de documentos gerais do evento.
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_documentos_helper.php';
require_once __DIR__ . '/eventos_notificacoes_central_helper.php';

function evento_documento_minuta_data_br(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }
    $time = strtotime($date);
    return $time ? date('d/m/Y H:i', $time) : $date;
}

function evento_documento_minuta_ensure_approval_columns(PDO $pdo): void
{
    static $done = false;
    if ($done || !eventos_documentos_table_exists($pdo, 'eventos_documentos')) {
        return;
    }
    $pdo->exec("ALTER TABLE eventos_documentos ADD COLUMN IF NOT EXISTS minuta_aprovada_em TIMESTAMP NULL");
    $pdo->exec("ALTER TABLE eventos_documentos ADD COLUMN IF NOT EXISTS minuta_aprovada_ip VARCHAR(80) NULL");
    $done = true;
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$documento = null;
$message = '';
$error = '';
if ($token !== '' && eventos_documentos_table_exists($pdo, 'eventos_documentos')) {
    $hasClienteCadastro = eventos_documentos_column_exists($pdo, 'logistica_eventos_espelho', 'cliente_cadastro_id');
    $hasClientes = eventos_documentos_table_exists($pdo, 'comercial_cadastro_clientes');
    $hasReunioes = eventos_documentos_table_exists($pdo, 'eventos_reunioes');
    $hasReuniaoMeEvent = $hasReunioes && eventos_documentos_column_exists($pdo, 'eventos_reunioes', 'me_event_id');
    $hasReuniaoEspelho = $hasReunioes && eventos_documentos_column_exists($pdo, 'eventos_reunioes', 'espelho_evento_id');
    $joinCliente = ($hasClienteCadastro && $hasClientes) ? "LEFT JOIN comercial_cadastro_clientes c ON c.id = e.cliente_cadastro_id" : "";
    $clienteSelect = ($hasClienteCadastro && $hasClientes) ? "COALESCE(NULLIF(TRIM(c.nome_completo), ''), '') AS cliente_nome" : "'' AS cliente_nome";
    $meetingConditions = [];
    if ($hasReuniaoMeEvent) {
        $meetingConditions[] = 'me_event_id = e.me_event_id';
    }
    if ($hasReuniaoEspelho) {
        $meetingConditions[] = 'espelho_evento_id = e.id';
    }
    $joinMeeting = $meetingConditions ? "
        LEFT JOIN LATERAL (
            SELECT id
            FROM eventos_reunioes
            WHERE " . implode(' OR ', $meetingConditions) . "
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ) er ON TRUE
    " : "";
    $meetingSelect = $meetingConditions ? "COALESCE(er.id, 0) AS meeting_id" : "0 AS meeting_id";

    $stmt = $pdo->prepare("
        SELECT
            d.*,
            e.nome_evento,
            e.me_event_id,
            {$meetingSelect},
            {$clienteSelect}
        FROM eventos_documentos d
        JOIN logistica_eventos_espelho e ON e.id = d.evento_id
        {$joinCliente}
        {$joinMeeting}
        WHERE d.minuta_token = :token
          AND d.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$documento) {
    $error = 'Documento não encontrado.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    evento_documento_minuta_ensure_approval_columns($pdo);
    if (!empty($documento['minuta_aprovada_em'])) {
        $message = 'Esta minuta já foi aprovada em ' . evento_documento_minuta_data_br((string)$documento['minuta_aprovada_em']) . '.';
    } else {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80);
        $stmt = $pdo->prepare("
            UPDATE eventos_documentos
            SET status = 'minuta_aprovada',
                minuta_aprovada_em = NOW(),
                minuta_aprovada_ip = :ip,
                updated_at = NOW()
            WHERE id = :id
              AND deleted_at IS NULL
        ");
        $stmt->execute([':ip' => $ip, ':id' => (int)$documento['id']]);

        $meetingId = (int)($documento['meeting_id'] ?? 0);
        if ($meetingId <= 0) {
            $meetingId = (int)$documento['evento_id'];
        }
        if ($meetingId > 0) {
            $clienteNome = trim((string)($documento['cliente_nome'] ?? ''));
            eventosNotificacoesCentralCriar(
                $pdo,
                $meetingId,
                'evento_minuta_aprovada',
                'Minuta aprovada',
                'index.php?page=eventos_documentos&evento_id=' . (int)$documento['evento_id'],
                'evento_minuta_aprovada:' . (int)$documento['id'],
                ($clienteNome !== '' ? 'O responsável ' . $clienteNome : 'O cliente') . ' aprovou a minuta "' . (string)$documento['titulo'] . '".'
            );
        }

        $message = 'Minuta aprovada com sucesso. Nossa equipe comercial foi notificada.';
        $stmtReload = $pdo->prepare("SELECT * FROM eventos_documentos WHERE id = :id LIMIT 1");
        $stmtReload->execute([':id' => (int)$documento['id']]);
        $documento = array_merge($documento, $stmtReload->fetch(PDO::FETCH_ASSOC) ?: []);
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Minuta do Documento</title>
    <style>
        body { margin:0; background:#f4f7fb; color:#1f2937; font-family:Arial,sans-serif; }
        .wrap { max-width:960px; margin:32px auto; padding:0 18px; }
        .header { background:#fff; border:1px solid #dbe5f0; border-radius:10px; padding:18px 20px; margin-bottom:18px; }
        .header h1 { margin:0; font-size:24px; color:#17357a; }
        .header p { margin:6px 0 0; color:#64748b; }
        .actions { background:#fff; border:1px solid #dbe5f0; border-radius:10px; padding:16px 20px; margin-bottom:18px; display:flex; justify-content:flex-end; gap:12px; box-shadow:0 16px 42px rgba(31,50,82,.08); }
        .paper { background:#fff; border:1px solid #dbe5f0; border-radius:10px; padding:28px; box-shadow:0 16px 42px rgba(31,50,82,.08); }
        .empty { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; border-radius:10px; padding:18px; font-weight:700; }
        .alert { padding:14px 16px; border-radius:10px; margin-bottom:16px; font-weight:800; }
        .alert-success { background:#dcfce7; color:#166534; border:1px solid #86efac; }
        .alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .btn { border:0; border-radius:8px; padding:12px 18px; font-weight:900; cursor:pointer; text-decoration:none; }
        .btn-primary { background:#16c784; color:#fff; }
        .badge { display:inline-flex; align-items:center; border-radius:999px; padding:8px 12px; background:#dcfce7; color:#166534; font-weight:900; }
    </style>
</head>
<body>
<main class="wrap">
    <?php if ($error !== ''): ?>
        <div class="alert alert-error"><?= eventos_documentos_e($error) ?></div>
    <?php else: ?>
        <?php if ($message !== ''): ?>
            <div class="alert alert-success"><?= eventos_documentos_e($message) ?></div>
        <?php endif; ?>
        <section class="header">
            <h1><?= eventos_documentos_e((string)$documento['titulo']) ?></h1>
            <p><?= eventos_documentos_e((string)($documento['nome_evento'] ?? 'Evento')) ?></p>
        </section>
        <section class="actions">
            <?php if (!empty($documento['minuta_aprovada_em'])): ?>
                <span class="badge">Aprovada em <?= eventos_documentos_e(evento_documento_minuta_data_br((string)$documento['minuta_aprovada_em'])) ?></span>
            <?php else: ?>
                <form method="post" onsubmit="return confirm('Confirmar aprovação desta minuta para assinatura?');">
                    <input type="hidden" name="token" value="<?= eventos_documentos_e($token) ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="btn btn-primary">Aprovar para assinatura</button>
                </form>
            <?php endif; ?>
        </section>
        <article class="paper">
            <?= (string)$documento['conteudo_html'] ?>
        </article>
    <?php endif; ?>
</main>
</body>
</html>
