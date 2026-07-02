<?php
/**
 * Minuta pública dos documentos de formatura.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_notificacoes_central_helper.php';

function minuta_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function minuta_data_br(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '';
    }
    $time = strtotime($date);
    return $time ? date('d/m/Y H:i', $time) : $date;
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$documento = null;
$message = '';
$error = '';

if ($token !== '') {
    $stmt = $pdo->prepare("
        SELECT
            d.*,
            e.me_event_id,
            e.nome_evento,
            er.id AS meeting_id,
            f.nome_formando,
            c.nome_completo AS responsavel_nome
        FROM eventos_formatura_documentos d
        JOIN logistica_eventos_espelho e ON e.id = d.evento_id
        JOIN eventos_formatura_formandos f ON f.id = d.formando_id
        JOIN comercial_cadastro_clientes c ON c.id = f.cliente_cadastro_id
        LEFT JOIN LATERAL (
            SELECT id
            FROM eventos_reunioes
            WHERE me_event_id = e.me_event_id
            ORDER BY updated_at DESC NULLS LAST, id DESC
            LIMIT 1
        ) er ON TRUE
        WHERE d.minuta_token = :token
          AND d.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $documento = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$documento) {
    $error = 'Minuta não encontrada ou indisponível.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'approve') {
    if (!empty($documento['minuta_aprovada_em'])) {
        $message = 'Esta minuta já foi aprovada em ' . minuta_data_br((string)$documento['minuta_aprovada_em']) . '.';
    } else {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80);
        $stmt = $pdo->prepare("
            UPDATE eventos_formatura_documentos
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
            eventosNotificacoesCentralCriar(
                $pdo,
                $meetingId,
                'formatura_minuta_aprovada',
                'Minuta aprovada',
                'index.php?page=eventos_formatura&evento_id=' . (int)$documento['evento_id'],
                'formatura_minuta_aprovada:' . (int)$documento['id'],
                'O responsável ' . (string)$documento['responsavel_nome'] . ' aprovou a minuta "' . (string)$documento['titulo'] . '".'
            );
        }

        $message = 'Minuta aprovada com sucesso.';
        $stmtReload = $pdo->prepare("SELECT * FROM eventos_formatura_documentos WHERE id = :id LIMIT 1");
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
    <title><?= $documento ? minuta_e((string)$documento['titulo']) : 'Minuta' ?></title>
    <style>
        :root { color-scheme: light; }
        body {
            margin: 0;
            font-family: Inter, Arial, sans-serif;
            background: #f3f6fb;
            color: #1f2937;
        }
        .page {
            max-width: 980px;
            margin: 0 auto;
            padding: 28px 18px 44px;
        }
        .header,
        .document,
        .actions {
            background: #fff;
            border: 1px solid #dbe3ef;
            border-radius: 10px;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }
        .header {
            padding: 20px 22px;
            margin-bottom: 16px;
        }
        h1 {
            margin: 0;
            color: #1e3a8a;
            font-size: 1.55rem;
        }
        .meta {
            margin-top: 8px;
            color: #64748b;
            font-weight: 700;
        }
        .document {
            padding: 28px;
            min-height: 420px;
            line-height: 1.55;
        }
        .actions {
            margin-top: 16px;
            padding: 18px 22px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn {
            border: 0;
            border-radius: 8px;
            padding: 12px 18px;
            font-weight: 900;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-primary { background: #16c784; color: #fff; }
        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 7px 11px;
            background: #dcfce7;
            color: #166534;
            font-weight: 900;
        }
        .alert {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-weight: 800;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <main class="page">
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= minuta_e($error) ?></div>
        <?php elseif ($documento): ?>
            <?php if ($message !== ''): ?>
                <div class="alert alert-success"><?= minuta_e($message) ?></div>
            <?php endif; ?>
            <section class="header">
                <h1><?= minuta_e((string)$documento['titulo']) ?></h1>
                <div class="meta">
                    <?= minuta_e((string)$documento['nome_evento']) ?> ·
                    Formando: <?= minuta_e((string)$documento['nome_formando']) ?> ·
                    Responsável: <?= minuta_e((string)$documento['responsavel_nome']) ?>
                </div>
            </section>
            <article class="document">
                <?= (string)$documento['conteudo_html'] ?>
            </article>
            <section class="actions">
                <?php if (!empty($documento['minuta_aprovada_em'])): ?>
                    <span class="badge">Aprovada em <?= minuta_e(minuta_data_br((string)$documento['minuta_aprovada_em'])) ?></span>
                <?php else: ?>
                    <form method="post" onsubmit="return confirm('Confirmar aprovação desta minuta?');">
                        <input type="hidden" name="token" value="<?= minuta_e($token) ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-primary">Aprovar minuta</button>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
