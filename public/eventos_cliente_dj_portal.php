<?php
/**
 * eventos_cliente_dj_portal.php
 * Página pública dedicada aos formulários de DJ/protocolos.
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
$links_dj_portal = [];
$links_observacoes_portal = [];
$visivel_dj = false;
$editavel_dj = false;
$visivel_reuniao = false;
$editavel_reuniao = false;

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

            $visivel_dj = !empty($portal['visivel_dj']);
            $editavel_dj = !empty($portal['editavel_dj']);
            $visivel_reuniao = !empty($portal['visivel_reuniao']);
            $editavel_reuniao = !empty($portal['editavel_reuniao']);

            if (!$visivel_dj) {
                $error = 'A área de DJ/protocolos ainda não está habilitada para este evento.';
            } else {
                $links_dj = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_dj');
                $dj_has_slot_rules = false;
                foreach ($links_dj as $dj_link_item) {
                    if (!empty($dj_link_item['portal_configured'])) {
                        $dj_has_slot_rules = true;
                        break;
                    }
                }

                if ($dj_has_slot_rules) {
                    foreach ($links_dj as $dj_link_item) {
                        if (empty($dj_link_item['is_active']) || empty($dj_link_item['portal_visible'])) {
                            continue;
                        }
                        $links_dj_portal[] = $dj_link_item;
                    }
                } else {
                    foreach ($links_dj as $dj_link_item) {
                        if (!empty($dj_link_item['is_active'])) {
                            $links_dj_portal[] = $dj_link_item;
                        }
                    }
                }

                if ($visivel_reuniao) {
                    $links_observacoes = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_observacoes');
                    $observacoes_has_slot_rules = false;
                    foreach ($links_observacoes as $obs_link_item) {
                        if (!empty($obs_link_item['portal_configured'])) {
                            $observacoes_has_slot_rules = true;
                            break;
                        }
                    }

                    if ($observacoes_has_slot_rules) {
                        foreach ($links_observacoes as $obs_link_item) {
                            if (empty($obs_link_item['is_active']) || empty($obs_link_item['portal_visible'])) {
                                continue;
                            }
                            $links_observacoes_portal[] = $obs_link_item;
                        }
                    } else {
                        foreach ($links_observacoes as $obs_link_item) {
                            if (!empty($obs_link_item['is_active'])) {
                                $links_observacoes_portal[] = $obs_link_item;
                            }
                        }
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
    <title>DJ e Protocolos - Portal do Cliente</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%);
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
        .card,
        .dj-item {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .event-box h2 {
            color: #0f172a;
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

        .btn-primary { background: #0284c7; color: #fff; }
        .btn-primary:hover { background: #0369a1; }
        .btn-secondary { background: #f1f5f9; border-color: #dbe3ef; color: #334155; }

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

        .dj-grid {
            display: grid;
            gap: 0.7rem;
        }

        .dj-item {
            margin-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .dj-item-title {
            font-weight: 700;
            color: #0f172a;
        }

        .dj-item-subtitle {
            font-size: 0.82rem;
            color: #64748b;
        }

        .empty-text {
            color: #64748b;
            font-style: italic;
            font-size: 0.9rem;
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
        <h1>🎧 DJ e Protocolos</h1>
        <p>Área de formulários de DJ e observações gerais</p>
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
                    Formulários disponíveis
                    <span class="status-badge <?= $editavel_dj ? 'status-editavel' : 'status-visualizacao' ?>">
                        <?= $editavel_dj ? 'Editável' : 'Somente visualização' ?>
                    </span>
                </h3>
                <div class="card-subtitle">Selecione abaixo os formulários que deseja preencher ou consultar.</div>

                <?php if (empty($links_dj_portal) && empty($links_observacoes_portal)): ?>
                <div class="empty-text">Nenhum formulário disponível para este portal no momento.</div>
                <?php endif; ?>

                <?php if (!empty($links_dj_portal)): ?>
                <div class="card-subtitle" style="margin-bottom: 0.55rem;"><strong>DJ / Protocolos</strong></div>
                <div class="dj-grid">
                    <?php foreach ($links_dj_portal as $dj_link_portal): ?>
                    <?php
                        $dj_slot = max(1, (int)($dj_link_portal['slot_index'] ?? 1));
                        $dj_title = trim((string)($dj_link_portal['form_title'] ?? ''));
                        if ($dj_title === '') {
                            $dj_title = 'Formulário DJ';
                        }
                    ?>
                    <div class="dj-item">
                        <div>
                            <div class="dj-item-title"><?= htmlspecialchars($dj_title) ?></div>
                            <div class="dj-item-subtitle">Quadro <?= $dj_slot ?></div>
                        </div>
                        <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$dj_link_portal['token']) ?>">
                            <?= $editavel_dj ? 'Abrir formulário' : 'Visualizar formulário' ?>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($links_observacoes_portal)): ?>
                <div class="card-subtitle" style="margin-top: 0.95rem; margin-bottom: 0.55rem;">
                    <strong>Observações Gerais</strong>
                    <span class="status-badge <?= $editavel_reuniao ? 'status-editavel' : 'status-visualizacao' ?>" style="margin-left: 0.45rem;">
                        <?= $editavel_reuniao ? 'Editável' : 'Somente visualização' ?>
                    </span>
                </div>
                <div class="dj-grid">
                    <?php foreach ($links_observacoes_portal as $obs_link_portal): ?>
                    <?php
                        $obs_slot = max(1, (int)($obs_link_portal['slot_index'] ?? 1));
                        $obs_title = trim((string)($obs_link_portal['form_title'] ?? ''));
                        if ($obs_title === '') {
                            $obs_title = 'Formulário de Observações Gerais';
                        }
                    ?>
                    <div class="dj-item">
                        <div>
                            <div class="dj-item-title"><?= htmlspecialchars($obs_title) ?></div>
                            <div class="dj-item-subtitle">Quadro <?= $obs_slot ?></div>
                        </div>
                        <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj&token=<?= urlencode((string)$obs_link_portal['token']) ?>">
                            <?= $editavel_reuniao ? 'Abrir formulário' : 'Visualizar formulário' ?>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</body>
</html>
