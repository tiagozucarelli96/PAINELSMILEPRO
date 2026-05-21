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
require_once __DIR__ . '/eventos_cliente_portal_ui.php';
require_once __DIR__ . '/logistica_cardapio_helper.php';

logistica_cardapio_ensure_schema($pdo);

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
$links_formulario_portal = [];
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
$visivel_formulario = false;
$editavel_formulario = false;
$visivel_convidados = false;
$editavel_convidados = false;
$visivel_arquivos = false;
$editavel_arquivos = false;
$visivel_cardapio = false;
$editavel_cardapio = false;
$portal_secoes = [
    'decoracao' => ['visivel' => false, 'editavel' => false],
    'observacoes_gerais' => ['visivel' => false, 'editavel' => false],
    'dj_protocolo' => ['visivel' => false, 'editavel' => false],
    'formulario' => ['visivel' => false, 'editavel' => false],
];
$cardapio_summary = [
    'has_pacote' => false,
    'pacote_nome' => '',
    'secoes_total' => 0,
    'itens_total' => 0,
    'selecionados_total' => 0,
    'submitted_at' => '',
];

function eventos_cliente_portal_dj_tem_campos_formulario($schema): bool
{
    if (!is_array($schema)) {
        return false;
    }
    foreach ($schema as $field) {
        if (!is_array($field)) {
            continue;
        }
        $field_id = trim((string)($field['id'] ?? ''));
        if (strpos($field_id, 'legacy_portal_text_') === 0) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        if (in_array($type, ['text', 'textarea', 'yesno', 'select', 'file'], true)) {
            return true;
        }
    }
    return false;
}

function eventos_cliente_portal_formulario_tem_campos($schema): bool
{
    return eventos_cliente_portal_dj_tem_campos_formulario($schema);
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
            $portal_secoes = eventos_cliente_portal_obter_config_secoes($portal);
            $editavel_reuniao = !empty($portal_secoes['decoracao']['editavel'])
                || !empty($portal_secoes['observacoes_gerais']['editavel'])
                || !empty($portal_secoes['dj_protocolo']['editavel'])
                || !empty($portal_secoes['formulario']['editavel']);
            $visivel_dj = !empty($portal_secoes['dj_protocolo']['visivel']);
            $editavel_dj = !empty($portal_secoes['dj_protocolo']['editavel']);
            $visivel_formulario = !empty($portal_secoes['formulario']['visivel']);
            $editavel_formulario = !empty($portal_secoes['formulario']['editavel']);
            $visivel_convidados = !empty($portal['visivel_convidados']);
            $editavel_convidados = !empty($portal['editavel_convidados']);
            $visivel_arquivos = !empty($portal['visivel_arquivos']);
            $editavel_arquivos = !empty($portal['editavel_arquivos']);
            $visivel_cardapio = !empty($portal['visivel_cardapio']);
            $editavel_cardapio = !empty($portal['editavel_cardapio']);

            if ($visivel_reuniao || $editavel_reuniao) {
                try {
                    $sync_reuniao = eventos_cliente_portal_sincronizar_link_reuniao(
                        $pdo,
                        (int)$reuniao['id'],
                        $visivel_reuniao,
                        $editavel_reuniao,
                        0
                    );
                    if (empty($sync_reuniao['ok'])) {
                        error_log('eventos_cliente_portal sync reuniao: ' . (string)($sync_reuniao['error'] ?? 'erro desconhecido'));
                    }
                } catch (Throwable $e) {
                    error_log('eventos_cliente_portal sync reuniao exception: ' . $e->getMessage());
                }
            }

            if ($visivel_dj || $editavel_dj) {
                try {
                    $sync_dj = eventos_cliente_portal_sincronizar_link_dj(
                        $pdo,
                        (int)$reuniao['id'],
                        $visivel_dj,
                        $editavel_dj,
                        0
                    );
                    if (empty($sync_dj['ok'])) {
                        error_log('eventos_cliente_portal sync dj: ' . (string)($sync_dj['error'] ?? 'erro desconhecido'));
                    }
                } catch (Throwable $e) {
                    error_log('eventos_cliente_portal sync dj exception: ' . $e->getMessage());
                }
            }

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

            $links_formulario = eventos_reuniao_listar_links_cliente($pdo, (int)$reuniao['id'], 'cliente_formulario');
            foreach ($links_formulario as $form_link_item) {
                if (empty($form_link_item['is_active']) || empty($form_link_item['portal_visible'])) {
                    continue;
                }
                if (!eventos_cliente_portal_formulario_tem_campos($form_link_item['form_schema'] ?? null)) {
                    continue;
                }
                $links_formulario_portal[] = $form_link_item;
            }

            if ($visivel_convidados) {
                $convidados_resumo = eventos_convidados_resumo($pdo, (int)$reuniao['id']);
            }

            if ($visivel_arquivos) {
                $tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($reuniao['tipo_evento_real'] ?? ($snapshot['tipo_evento_real'] ?? '')));
                eventos_arquivos_seed_campos_por_tipo($pdo, (int)$reuniao['id'], $tipo_evento_real, 0);
                $arquivos_resumo = eventos_arquivos_resumo($pdo, (int)$reuniao['id']);
            }

            if ($visivel_cardapio) {
                $cardapio_context = logistica_cardapio_evento_contexto($pdo, (int)$reuniao['id']);
                if (!empty($cardapio_context['ok'])) {
                    $cardapio_summary = $cardapio_context['summary'] ?? $cardapio_summary;
                }
            }
        }
    }
}

$ocultar_cards_embutidos_reuniao = $visivel_reuniao;
$mostrar_card_dj = $visivel_dj && !$ocultar_cards_embutidos_reuniao;
$mostrar_card_formularios = $visivel_formulario && !empty($links_formulario_portal) && !$ocultar_cards_embutidos_reuniao;

$cards_visiveis_total =
    ($visivel_reuniao ? 1 : 0) +
    ($mostrar_card_dj ? 1 : 0) +
    ($mostrar_card_formularios ? 1 : 0) +
    ($visivel_convidados ? 1 : 0) +
    ($visivel_arquivos ? 1 : 0) +
    ($visivel_cardapio ? 1 : 0);

$evento_nome = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$horario_evento = eventos_cliente_ui_horario_evento($snapshot, '-');
$evento_datetime_iso = eventos_cliente_ui_event_datetime_iso($snapshot);
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));

$links_dj_formularios = [];
$has_dj_texto_direto = false;
$link_dj_principal = null;
foreach ($links_dj_portal as $dj_link_item) {
    if ($link_dj_principal === null) {
        $link_dj_principal = $dj_link_item;
    }
    if (eventos_cliente_portal_dj_tem_campos_formulario($dj_link_item['form_schema'] ?? null)) {
        $links_dj_formularios[] = $dj_link_item;
    } else {
        $has_dj_texto_direto = true;
        $link_dj_principal = $dj_link_item;
    }
}

$formularios_total = count($links_formulario_portal);
$formularios_pendentes = 0;
$formularios_editaveis_abertos = 0;
foreach ($links_formulario_portal as $formulario_link_item) {
    if (empty($formulario_link_item['submitted_at'])) {
        $formularios_pendentes++;
    }
    if (!empty($formulario_link_item['portal_editable']) && empty($formulario_link_item['submitted_at'])) {
        $formularios_editaveis_abertos++;
    }
}
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
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.16), transparent 26%),
                radial-gradient(circle at bottom right, rgba(37, 99, 235, 0.14), transparent 28%),
                linear-gradient(180deg, #f8fbff 0%, #eef4ff 100%);
            color: #1e293b;
            line-height: 1.6;
            min-height: 100vh;
        }

        .header {
            background: linear-gradient(135deg, #123c9c 0%, #2563eb 55%, #3b82f6 100%);
            color: #fff;
            padding: 2.35rem 1rem 2.1rem;
            text-align: center;
            box-shadow: 0 20px 44px rgba(37, 99, 235, 0.18);
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
            padding: 1.45rem;
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
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(14px);
            border-radius: 26px;
            border: 1px solid rgba(191, 219, 254, 0.9);
            padding: 1.3rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 24px 60px rgba(30, 64, 175, 0.12);
        }

        .event-box-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
            align-items: stretch;
        }

        .event-box h2 {
            color: #1e3a8a;
            margin-bottom: 0.45rem;
            font-size: 1.55rem;
            line-height: 1.15;
            letter-spacing: -0.02em;
        }

        .event-kicker {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            border-radius: 999px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: #1e40af;
            font-size: 0.74rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 700;
            padding: 0.3rem 0.62rem;
            margin-bottom: 0.65rem;
        }

        .event-summary-copy {
            color: #475569;
            font-size: 0.9rem;
            margin-bottom: 0.95rem;
            line-height: 1.45;
        }

        .event-facts {
            display: grid;
            gap: 0.55rem;
        }

        .event-fact {
            display: grid;
            grid-template-columns: 110px minmax(0, 1fr);
            align-items: baseline;
            gap: 0.65rem;
            border: 1px solid #dbeafe;
            border-radius: 12px;
            background: #ffffff;
            padding: 0.62rem 0.72rem;
        }

        .event-fact-label {
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-weight: 700;
            color: #64748b;
            white-space: nowrap;
        }

        .event-fact-value {
            color: #0f172a;
            font-size: 1.04rem;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .countdown-panel {
            position: relative;
            overflow: hidden;
            border-radius: 24px;
            padding: 1.15rem;
            background: linear-gradient(155deg, #0f2e7a 0%, #1d4ed8 58%, #60a5fa 100%);
            color: #fff;
            box-shadow: 0 24px 48px rgba(29, 78, 216, 0.28);
        }

        .countdown-panel::before,
        .countdown-panel::after {
            content: '';
            position: absolute;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            pointer-events: none;
        }

        .countdown-panel::before {
            width: 180px;
            height: 180px;
            top: -85px;
            right: -45px;
        }

        .countdown-panel::after {
            width: 120px;
            height: 120px;
            bottom: -55px;
            left: -35px;
        }

        .countdown-eyebrow,
        .countdown-title,
        .countdown-grid,
        .countdown-fun {
            position: relative;
            z-index: 1;
        }

        .countdown-eyebrow {
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            opacity: 0.78;
            font-weight: 700;
        }

        .countdown-title {
            margin-top: 0.25rem;
            font-size: 1.35rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .countdown-grid {
            margin-top: 1rem;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.55rem;
        }

        .countdown-unit {
            padding: 0.8rem 0.55rem;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.18);
            text-align: center;
            backdrop-filter: blur(12px);
        }

        .countdown-number {
            display: block;
            font-size: 1.55rem;
            line-height: 1;
            font-weight: 800;
            letter-spacing: -0.04em;
        }

        .countdown-label {
            display: block;
            margin-top: 0.35rem;
            font-size: 0.73rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            opacity: 0.78;
        }

        .countdown-fun {
            margin-top: 0.95rem;
            padding: 0.75rem 0.9rem;
            border-radius: 16px;
            background: rgba(12, 28, 76, 0.3);
            font-size: 0.92rem;
            font-weight: 600;
            line-height: 1.45;
        }

        .countdown-panel.is-complete .countdown-grid {
            grid-template-columns: 1fr;
        }

        .countdown-panel.is-complete .countdown-unit {
            padding: 1rem;
        }

        .countdown-panel.is-complete .countdown-number {
            font-size: 2rem;
        }

        .cards-grid {
            display: grid;
            gap: 1.1rem;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            align-items: stretch;
            grid-auto-rows: 1fr;
        }

        .portal-card {
            min-height: 260px;
            border-radius: 22px;
            padding: 1.55rem;
            color: #fff;
            box-shadow: 0 18px 38px rgba(29, 78, 216, 0.2);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: 100%;
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

        .portal-card-inner {
            display: flex;
            flex-direction: column;
            gap: 0.95rem;
            height: 100%;
        }

        .portal-card-theme-reuniao {
            background: linear-gradient(145deg, #2563eb 0%, #1d4ed8 100%);
        }

        .portal-card-theme-dj {
            background: linear-gradient(145deg, #2f6ff5 0%, #1e40af 100%);
        }

        .portal-card-theme-convidados {
            background: linear-gradient(145deg, #3b82f6 0%, #1d4ed8 100%);
        }

        .portal-card-theme-arquivos {
            background: linear-gradient(145deg, #1d4ed8 0%, #1e3a8a 100%);
        }

        .portal-card-theme-cardapio {
            background: linear-gradient(145deg, #2563eb 0%, #1e40af 100%);
        }

        .portal-card-theme-formulario {
            background: linear-gradient(145deg, #4f8df8 0%, #1d4ed8 100%);
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
            flex: 1 1 auto;
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
            margin-top: auto;
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
            color: #1e3a8a;
            border-color: rgba(255, 255, 255, 0.65);
            box-shadow: 0 10px 18px rgba(15, 23, 42, 0.12);
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

            .event-box-grid {
                grid-template-columns: 1fr;
            }

            .event-fact {
                grid-template-columns: 1fr;
                gap: 0.28rem;
            }

            .countdown-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
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
            <div class="event-box-grid">
                <div>
                    <div class="event-kicker">Resumo do Evento</div>
                    <h2><?= htmlspecialchars($evento_nome) ?></h2>
                    <div class="event-summary-copy">Tudo organizado em um só lugar para o cliente navegar com clareza até o grande dia.</div>
                    <div class="event-facts">
                        <div class="event-fact">
                            <div class="event-fact-label">Data</div>
                            <div class="event-fact-value"><?= htmlspecialchars($data_evento_fmt) ?></div>
                        </div>
                        <div class="event-fact">
                            <div class="event-fact-label">Horário</div>
                            <div class="event-fact-value"><?= htmlspecialchars($horario_evento) ?></div>
                        </div>
                        <div class="event-fact">
                            <div class="event-fact-label">Local</div>
                            <div class="event-fact-value"><?= htmlspecialchars($local_evento) ?></div>
                        </div>
                        <div class="event-fact">
                            <div class="event-fact-label">Cliente</div>
                            <div class="event-fact-value"><?= htmlspecialchars($cliente_nome) ?></div>
                        </div>
                    </div>
                </div>

                <aside class="countdown-panel" data-countdown data-event-datetime="<?= htmlspecialchars($evento_datetime_iso) ?>">
                    <div class="countdown-eyebrow">Contagem Regressiva</div>
                    <div class="countdown-title"><?= $evento_datetime_iso !== '' ? 'Falta pouco para o evento' : 'Data em preparação' ?></div>
                    <div class="countdown-grid">
                        <div class="countdown-unit">
                            <span class="countdown-number" data-unit="days"><?= $evento_datetime_iso !== '' ? '--' : '00' ?></span>
                            <span class="countdown-label">Dias</span>
                        </div>
                        <div class="countdown-unit">
                            <span class="countdown-number" data-unit="hours"><?= $evento_datetime_iso !== '' ? '--' : '00' ?></span>
                            <span class="countdown-label">Horas</span>
                        </div>
                        <div class="countdown-unit">
                            <span class="countdown-number" data-unit="minutes"><?= $evento_datetime_iso !== '' ? '--' : '00' ?></span>
                            <span class="countdown-label">Minutos</span>
                        </div>
                    </div>
                    <div class="countdown-fun" data-countdown-message>
                        <?= $evento_datetime_iso !== '' ? 'Respira fundo: a festa já está vindo com estilo.' : 'Defina a data do evento para liberar a experiência completa da contagem.' ?>
                    </div>
                </aside>
            </div>
        </div>

        <div class="cards-grid">
            <?php if ($visivel_reuniao): ?>
            <section class="portal-card portal-card-theme-reuniao">
                <div class="portal-card-inner">
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

            <?php if ($mostrar_card_dj): ?>
            <section class="portal-card portal-card-theme-dj">
                <div class="portal-card-inner">
                    <div class="card-icon">🎧</div>
                    <div class="card-title">
                        <h3>DJ e Protocolos</h3>
                        <div class="card-subtitle">Acesse a área de DJ e protocolos em uma página dedicada.</div>
                        <div class="card-meta">
                            <?= !empty($link_dj_principal)
                                ? ($editavel_dj ? 'Área liberada para edição' : 'Área disponível para visualização')
                                : 'Área ainda sem conteúdo liberado' ?>
                        </div>
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
                <div class="portal-card-inner">
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
                <div class="portal-card-inner">
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

            <?php if ($mostrar_card_formularios): ?>
            <section class="portal-card portal-card-theme-formulario">
                <div class="portal-card-inner">
                    <div class="card-icon">📋</div>
                    <div class="card-title">
                        <h3>Formulários</h3>
                        <div class="card-subtitle">Visualize os formulários liberados para este evento e preencha os que estiverem disponíveis.</div>
                        <div class="card-meta">
                            <?= $formularios_total ?> formulário(s) • <?= $formularios_pendentes ?> pendente(s)
                        </div>
                    </div>
                </div>
                <div class="card-actions">
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_formulario_portal&token=<?= urlencode($token) ?>">
                        <?= $formularios_editaveis_abertos > 0 ? 'Preencher formulários' : 'Visualizar formulários' ?>
                    </a>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($visivel_cardapio): ?>
            <section class="portal-card portal-card-theme-cardapio">
                <div class="portal-card-inner">
                    <div class="card-icon">🍽️</div>
                    <div class="card-title">
                        <h3>Cardápio</h3>
                        <div class="card-subtitle">Escolha os itens do cardápio conforme o pacote configurado para o evento.</div>
                        <div class="card-meta">
                            <?php if (empty($cardapio_summary['has_pacote'])): ?>
                                Aguardando pacote do evento
                            <?php elseif (!empty($cardapio_summary['submitted_at'])): ?>
                                <?= (int)($cardapio_summary['selecionados_total'] ?? 0) ?> escolhido(s) • concluído
                            <?php else: ?>
                                <?= (int)($cardapio_summary['secoes_total'] ?? 0) ?> seção(ões) • <?= (int)($cardapio_summary['itens_total'] ?? 0) ?> opção(ões)
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="card-actions">
                    <a class="btn btn-primary" href="index.php?page=eventos_cliente_cardapio&token=<?= urlencode($token) ?>">
                        <?= ($editavel_cardapio && empty($cardapio_summary['submitted_at'])) ? 'Escolher cardápio' : 'Visualizar cardápio' ?>
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
    <script>
        (() => {
            const panels = document.querySelectorAll('[data-countdown]');
            if (!panels.length) return;

            const funMessages = [
                { limit: 3600, text: 'Última volta do relógio. Já já esse portal vira evento ao vivo.' },
                { limit: 21600, text: 'Contagem acelerada: os detalhes finais já estão entrando em cena.' },
                { limit: 86400, text: 'É amanhã. Hora de curtir a reta final com aquele friozinho bom.' },
                { limit: 259200, text: 'A energia já mudou por aqui. Está ficando muito perto.' },
                { limit: 1209600, text: 'Semana de evento no radar. Organização bonita é assim.' },
                { limit: Infinity, text: 'Tudo certo: o grande momento já tem destino e está no caminho.' }
            ];

            const pluralize = (value, singular, plural) => value === 1 ? singular : plural;

            panels.forEach((panel) => {
                const iso = panel.getAttribute('data-event-datetime') || '';
                if (!iso) return;

                const targetTime = new Date(iso).getTime();
                if (!Number.isFinite(targetTime)) return;

                const units = {
                    days: panel.querySelector('[data-unit="days"]'),
                    hours: panel.querySelector('[data-unit="hours"]'),
                    minutes: panel.querySelector('[data-unit="minutes"]')
                };
                const message = panel.querySelector('[data-countdown-message]');
                const title = panel.querySelector('.countdown-title');

                const update = () => {
                    const diffMs = targetTime - Date.now();
                    if (diffMs <= 0) {
                        panel.classList.add('is-complete');
                        if (units.days) units.days.textContent = 'Chegou';
                        if (units.hours) units.hours.textContent = '00';
                        if (units.minutes) units.minutes.textContent = '00';
                        if (title) title.textContent = 'O grande dia começou';
                        if (message) message.textContent = 'Portal em modo celebração: agora é curtir cada minuto.';
                        return;
                    }

                    const totalSeconds = Math.floor(diffMs / 1000);
                    const days = Math.floor(totalSeconds / 86400);
                    const hours = Math.floor((totalSeconds % 86400) / 3600);
                    const minutes = Math.floor((totalSeconds % 3600) / 60);

                    if (units.days) units.days.textContent = String(days).padStart(2, '0');
                    if (units.hours) units.hours.textContent = String(hours).padStart(2, '0');
                    if (units.minutes) units.minutes.textContent = String(minutes).padStart(2, '0');

                    if (title) {
                        title.textContent = `Faltam ${days} ${pluralize(days, 'dia', 'dias')} para o evento`;
                    }
                    if (message) {
                        const current = funMessages.find((item) => totalSeconds <= item.limit) || funMessages[funMessages.length - 1];
                        message.textContent = current.text;
                    }
                };

                update();
                window.setInterval(update, 1000);
            });
        })();
    </script>
</body>
</html>
