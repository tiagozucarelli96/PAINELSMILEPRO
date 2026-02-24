<?php
/**
 * eventos_cliente_portal.php
 * Painel público do cliente (apenas cards de navegação)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

$token = trim((string)($_GET['token'] ?? ''));
$error = '';

$legacy_view = trim((string)($_GET['view'] ?? ''));
if ($token !== '' && $legacy_view === 'arquivos') {
    header('Location: index.php?page=eventos_cliente_arquivos&token=' . urlencode($token));
    exit;
}

$portal = null;
$reuniao = null;
$snapshot = [];
$links_dj_portal = [];
$convidados_resumo = ['total' => 0, 'checkin' => 0, 'pendentes' => 0];
$arquivos_resumo = [
    'campos_total' => 0,
    'campos_obrigatorios' => 0,
    'campos_pendentes' => 0,
    'arquivos_total' => 0,
    'arquivos_visiveis_cliente' => 0,
    'arquivos_cliente' => 0,
];

$visivel_reuniao = false;
$editavel_reuniao = false;
$visivel_dj = false;
$editavel_dj = false;
$visivel_convidados = false;
$editavel_convidados = false;
$visivel_arquivos = false;
$editavel_arquivos = false;

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
            $visivel_dj = !empty($portal['visivel_dj']);
            $editavel_dj = !empty($portal['editavel_dj']);
            $visivel_convidados = !empty($portal['visivel_convidados']);
            $editavel_convidados = !empty($portal['editavel_convidados']);
            $visivel_arquivos = !empty($portal['visivel_arquivos']);
            $editavel_arquivos = !empty($portal['editavel_arquivos']);

            if ($visivel_dj) {
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
            }

            if ($visivel_convidados) {
                $convidados_resumo = eventos_convidados_resumo($pdo, (int)$reuniao['id']);
            }

            if ($visivel_arquivos) {
                $tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($reuniao['tipo_evento_real'] ?? ($snapshot['tipo_evento_real'] ?? '')));
                eventos_arquivos_seed_campos_por_tipo($pdo, (int)$reuniao['id'], $tipo_evento_real, 0);
                $arquivos_resumo = eventos_arquivos_resumo($pdo, (int)$reuniao['id']);
            }
        }
    }
}

$cards_visiveis_total =
    ($visivel_reuniao ? 1 : 0) +
    ($visivel_dj ? 1 : 0) +
    ($visivel_convidados ? 1 : 0) +
    ($visivel_arquivos ? 1 : 0);

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
    <title>Portal do Cliente - Organização do Evento</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }

        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: #fff;
            padding: 2rem 1rem;
            text-align: center;
        }

        .header img {
            max-width: 170px;
            margin-bottom: 0.9rem;
        }

        .header h1 {
            font-size: 1.6rem;
            margin-bottom: 0.35rem;
        }

        .header p {
            opacity: 0.92;
            font-size: 0.95rem;
        }

        .container {
            max-width: 1320px;
            margin: 0 auto;
            padding: 1.4rem;
        }

        .alert {
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
        }

        .alert-error {
            background: #fee2e2;
            border-color: #fca5a5;
            color: #991b1b;
        }

        .event-box {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 1.1rem;
            margin-bottom: 1.2rem;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.08);
        }

        .event-box h2 {
            color: #1e3a8a;
            margin-bottom: 0.6rem;
            font-size: 1.3rem;
        }

        .event-meta {
            display: grid;
            gap: 0.6rem;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            color: #334155;
            font-size: 0.92rem;
        }

        .cards-grid {
            display: grid;
            gap: 1.1rem;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            align-items: start;
        }

        .portal-card {
            min-height: 250px;
            border-radius: 22px;
            padding: 1.55rem;
            color: #fff;
            box-shadow: 0 18px 38px rgba(15, 23, 42, 0.16);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .portal-card::before {
            content: '';
            position: absolute;
            width: 240px;
            height: 240px;
            border-radius: 999px;
            top: -120px;
            right: -90px;
            background: rgba(255, 255, 255, 0.14);
            pointer-events: none;
        }

        .portal-card-theme-reuniao {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }

        .portal-card-theme-dj {
            background: linear-gradient(135deg, #06b6d4 0%, #0284c7 100%);
        }

        .portal-card-theme-convidados {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .portal-card-theme-arquivos {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
        }

        .card-icon {
            font-size: 2.7rem;
            line-height: 1;
            margin-bottom: 1.25rem;
            position: relative;
            z-index: 1;
        }

        .card-title {
            position: relative;
            z-index: 1;
        }

        .card-title h3 {
            font-size: 2.2rem;
            line-height: 1.1;
            margin-bottom: 0.65rem;
            font-weight: 800;
            letter-spacing: -0.01em;
        }

        .card-subtitle {
            font-size: 0.98rem;
            line-height: 1.35;
            opacity: 0.9;
            max-width: 92%;
        }

        .card-meta {
            margin-top: 0.7rem;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            opacity: 0.92;
        }

        .card-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            padding: 0.6rem 0.95rem;
            border-radius: 10px;
            border: 1px solid transparent;
            font-size: 0.82rem;
            font-weight: 700;
            cursor: pointer;
        }

        .portal-card .btn-primary {
            background: #fff;
            color: #1e293b;
            border-color: rgba(255, 255, 255, 0.65);
        }

        .module-panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 1rem;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.07);
        }

        .module-panel h3 {
            color: #0f172a;
            font-size: 1.1rem;
            margin-bottom: 0.2rem;
        }

        .module-subtitle {
            color: #64748b;
            font-size: 0.85rem;
            margin-bottom: 0.2rem;
        }

        @media (max-width: 760px) {
            .container {
                padding: 1rem 0.8rem 1.2rem;
            }

            .cards-grid {
                grid-template-columns: 1fr;
            }

            .portal-card {
                min-height: auto;
                padding: 1.25rem;
            }

            .card-title h3 {
                font-size: 1.85rem;
            }

            .card-subtitle {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
        <h1>🧩 Portal do Cliente</h1>
        <p>Organização do evento</p>
    </div>

    <div class="container">
        <?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <strong>Erro:</strong> <?= htmlspecialchars($error) ?>
        </div>
        <?php else: ?>
        <div class="event-box">
            <h2><?= htmlspecialchars($evento_nome) ?></h2>
            <div class="event-meta">
                <div><strong>📅 Data:</strong> <?= htmlspecialchars($data_evento_fmt) ?></div>
                <div><strong>⏰ Horário:</strong> <?= htmlspecialchars($horario_evento) ?></div>
                <div><strong>📍 Local:</strong> <?= htmlspecialchars($local_evento) ?></div>
                <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($cliente_nome) ?></div>
            </div>
        </div>

        <div class="cards-grid">
            <?php if ($visivel_reuniao): ?>
            <section class="portal-card portal-card-theme-reuniao">
                <div>
                    <div class="card-icon">📝</div>
                    <div class="card-title">
                        <h3>Reunião Final</h3>
                        <div class="card-subtitle">Acesse a área da reunião final em uma página exclusiva.</div>
                        <div class="card-meta"><?= $editavel_reuniao ? 'Editável pelo cliente' : 'Somente visualização' ?></div>
                    </div>
                </div>
                <div class="card-actions">
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_reuniao&token=<?= urlencode($token) ?>">
                        <?= $editavel_reuniao ? 'Abrir reunião final' : 'Visualizar reunião final' ?>
                    </a>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($visivel_dj): ?>
            <section class="portal-card portal-card-theme-dj">
                <div>
                    <div class="card-icon">🎧</div>
                    <div class="card-title">
                        <h3>DJ e Protocolos</h3>
                        <div class="card-subtitle">Acesse os formulários de DJ em uma página dedicada.</div>
                        <div class="card-meta"><?= count($links_dj_portal) ?> quadro(s) liberado(s)</div>
                    </div>
                </div>
                <div class="card-actions">
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_dj_portal&token=<?= urlencode($token) ?>">
                        <?= $editavel_dj ? 'Abrir área DJ' : 'Visualizar área DJ' ?>
                    </a>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($visivel_convidados): ?>
            <section class="portal-card portal-card-theme-convidados">
                <div>
                    <div class="card-icon">👥</div>
                    <div class="card-title">
                        <h3>Convidados</h3>
                        <div class="card-subtitle">Visualize e acompanhe lista de convidados e check-ins.</div>
                        <div class="card-meta">
                            <?= (int)$convidados_resumo['total'] ?> total • <?= (int)$convidados_resumo['checkin'] ?> check-ins
                        </div>
                    </div>
                </div>
                <div class="card-actions">
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_convidados&token=<?= urlencode($token) ?>">
                        <?= $editavel_convidados ? 'Gerenciar convidados' : 'Visualizar convidados' ?>
                    </a>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($visivel_arquivos): ?>
            <section class="portal-card portal-card-theme-arquivos">
                <div>
                    <div class="card-icon">📁</div>
                    <div class="card-title">
                        <h3>Arquivos</h3>
                        <div class="card-subtitle">Acesse os arquivos do evento em uma página separada.</div>
                        <div class="card-meta">
                            <?= (int)$arquivos_resumo['arquivos_cliente'] ?> enviados • <?= (int)$arquivos_resumo['campos_pendentes'] ?> pendente(s)
                        </div>
                    </div>
                </div>
                <div class="card-actions">
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_arquivos&token=<?= urlencode($token) ?>">
                        <?= $editavel_arquivos ? 'Abrir área de arquivos' : 'Visualizar arquivos' ?>
                    </a>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($cards_visiveis_total === 0): ?>
            <section class="module-panel">
                <h3>Conteúdo indisponível</h3>
                <div class="module-subtitle">Ainda não há cards habilitados para visualização neste portal.</div>
            </section>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
