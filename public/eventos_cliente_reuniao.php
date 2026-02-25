<?php
/**
 * eventos_cliente_reuniao.php
 * Página pública dedicada à Reunião Final do cliente.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

$token = trim((string)($_GET['token'] ?? ''));
$error = '';

$portal = null;
$reuniao = null;
$snapshot = [];
$link_observacoes = null;
$visivel_reuniao = false;
$editavel_reuniao = false;

function eventos_cliente_reuniao_selecionar_link_observacoes(array $links_observacoes): ?array
{
    $has_rules = false;
    foreach ($links_observacoes as $link_item) {
        if (!empty($link_item['portal_configured'])) {
            $has_rules = true;
            break;
        }
    }

    foreach ($links_observacoes as $link_item) {
        if (empty($link_item['is_active'])) {
            continue;
        }
        if ($has_rules && empty($link_item['portal_visible'])) {
            continue;
        }
        return $link_item;
    }

    return null;
}

if ($token === '') {
    $error = 'Link inválido.';
} else {
    $portal = eventos_cliente_portal_get_by_token($pdo, $token);
    if (!$portal || empty($portal['is_active'])) {
        $error = 'Portal não encontrado ou desativado.';
    } else {
        $reuniao = eventos_reuniao_get($pdo, (int)($portal['meeting_id'] ?? 0));
        if (!$reuniao) {
            $error = 'Reunião não encontrada.';
        } else {
            $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
            if (!is_array($snapshot)) {
                $snapshot = [];
            }

            $visivel_reuniao = !empty($portal['visivel_reuniao']);
            $editavel_reuniao = !empty($portal['editavel_reuniao']);
            if (!$visivel_reuniao) {
                $error = 'A área de reunião final ainda não está habilitada para este evento.';
            } else {
                $links_observacoes = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_observacoes');
                $link_observacoes = eventos_cliente_reuniao_selecionar_link_observacoes($links_observacoes);

                // Auto-correção: se reunião está visível mas ainda não existe link, tenta criar/sincronizar.
                if (!$link_observacoes) {
                    $sync_result = eventos_cliente_portal_sincronizar_link_reuniao(
                        $pdo,
                        (int)$reuniao['id'],
                        $visivel_reuniao,
                        $editavel_reuniao,
                        0
                    );
                    if (!empty($sync_result['ok'])) {
                        $links_observacoes = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_observacoes');
                        $link_observacoes = eventos_cliente_reuniao_selecionar_link_observacoes($links_observacoes);
                    } else {
                        error_log('eventos_cliente_reuniao sync reuniao: ' . (string)($sync_result['error'] ?? 'erro desconhecido'));
                    }
                }
            }
        }
    }
}

$evento_nome = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
$horario_evento = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_evento .= ' - ' . $hora_fim;
}
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reunião Final - Portal do Cliente</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            padding: 2rem 1rem;
            text-align: center;
        }

        .header img { max-width: 170px; margin-bottom: 0.8rem; }
        .header h1 { font-size: 1.55rem; margin-bottom: 0.3rem; }

        .container {
            max-width: 980px;
            margin: 0 auto;
            padding: 1.2rem;
        }

        .alert {
            border-radius: 10px;
            padding: 0.9rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            font-size: 0.9rem;
        }

        .alert-error {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }

        .event-box,
        .card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .event-box h2 {
            color: #1e3a8a;
            margin-bottom: 0.6rem;
        }

        .event-meta {
            display: grid;
            gap: 0.55rem;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            font-size: 0.92rem;
            color: #334155;
        }

        .actions-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-top: 0.75rem;
        }

        .card h3 {
            color: #0f172a;
            font-size: 1.06rem;
            margin-bottom: 0.45rem;
        }

        .card-subtitle {
            color: #64748b;
            font-size: 0.88rem;
            margin-bottom: 0.8rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            border: 1px solid transparent;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.86rem;
            font-weight: 700;
            padding: 0.56rem 0.9rem;
            gap: 0.45rem;
        }

        .btn-primary { background: #1e3a8a; color: #fff; }
        .btn-primary:hover { background: #254ac9; }
        .btn-secondary { background: #f1f5f9; border-color: #dbe3ef; color: #334155; }

        .empty-text {
            color: #64748b;
            font-style: italic;
            font-size: 0.9rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.2rem 0.55rem;
            font-size: 0.72rem;
            font-weight: 700;
            border: 1px solid transparent;
            margin-left: 0.4rem;
        }

        .status-editavel {
            background: #dbeafe;
            border-color: #93c5fd;
            color: #1e40af;
        }

        .status-visualizacao {
            background: #e2e8f0;
            border-color: #cbd5e1;
            color: #334155;
        }

        @media (max-width: 780px) {
            .container {
                padding: 1rem 0.8rem 1.2rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
        <h1>📝 Reunião Final</h1>
        <p>Área exclusiva da reunião final do evento</p>
    </div>

    <div class="container">
        <?php if ($error !== ''): ?>
        <div class="alert alert-error"><strong>Erro:</strong> <?= htmlspecialchars($error) ?></div>
        <?php else: ?>
            <div class="event-box">
                <h2><?= htmlspecialchars($evento_nome) ?></h2>
                <div class="event-meta">
                    <div><strong>📅 Data:</strong> <?= htmlspecialchars($data_evento_fmt) ?></div>
                    <div><strong>⏰ Horário:</strong> <?= htmlspecialchars($horario_evento) ?></div>
                    <div><strong>📍 Local:</strong> <?= htmlspecialchars($local_evento) ?></div>
                    <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($cliente_nome) ?></div>
                </div>
                <div class="actions-row">
                    <a class="btn btn-secondary" href="index.php?page=eventos_cliente_portal&token=<?= urlencode($token) ?>">← Voltar ao portal</a>
                </div>
            </div>

            <section class="card">
                <h3>
                    Formulário da reunião final
                    <span class="status-badge <?= $editavel_reuniao ? 'status-editavel' : 'status-visualizacao' ?>">
                        <?= $editavel_reuniao ? 'Editável' : 'Somente visualização' ?>
                    </span>
                </h3>
                <div class="card-subtitle">Preencha ou consulte as informações finais alinhadas com a equipe.</div>

                <?php if (!empty($link_observacoes['token'])): ?>
                <div class="actions-row">
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$link_observacoes['token']) ?>">
                        <?= $editavel_reuniao ? 'Abrir formulário da reunião final' : 'Visualizar formulário da reunião final' ?>
                    </a>
                </div>
                <?php else: ?>
                <div class="empty-text">Nenhum formulário disponível no momento para esta área.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>
