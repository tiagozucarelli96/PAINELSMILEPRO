<?php
/**
 * portal_dj.php
 * Painel do Portal DJ (acesso externo)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

// Verificar login
if (empty($_SESSION['portal_dj_logado']) || $_SESSION['portal_dj_logado'] !== true) {
    $redirect = (string)($_SERVER['REQUEST_URI'] ?? '');
    $redirect_param = '';
    $starts_ok = (substr($redirect, 0, 9) === '/index.php' || substr($redirect, 0, 8) === 'index.php');
    if ($redirect !== '' && $starts_ok && strpos($redirect, 'page=portal_dj') !== false) {
        $redirect_param = '&redirect=' . urlencode($redirect);
    }
    header('Location: index.php?page=portal_dj_login' . $redirect_param);
    exit;
}

$fornecedor_id = $_SESSION['portal_dj_fornecedor_id'];
$nome = $_SESSION['portal_dj_nome'];

// Logout
if (isset($_GET['logout'])) {
    // Invalidar sessão no banco
    if (!empty($_SESSION['portal_dj_token'])) {
        $stmt = $pdo->prepare("UPDATE eventos_fornecedores_sessoes SET ativo = FALSE WHERE token = :token");
        $stmt->execute([':token' => $_SESSION['portal_dj_token']]);
    }
    
    unset($_SESSION['portal_dj_logado']);
    unset($_SESSION['portal_dj_fornecedor_id']);
    unset($_SESSION['portal_dj_nome']);
    unset($_SESSION['portal_dj_token']);
    
    header('Location: index.php?page=portal_dj_login');
    exit;
}

// Buscar próximos 30 eventos (somente tipo real casamento/15 anos)
$eventos = [];
$eventos_por_data = [];
$erro_eventos = '';
$aviso_evento_cancelado = '';
$start_date = date('Y-m-d');
try {
    $start = new DateTime('today');
    $start_date = $start->format('Y-m-d');
    $tipo_real_expr = "COALESCE(NULLIF(LOWER(TRIM(r.tipo_evento_real)), ''), LOWER(TRIM(COALESCE(r.me_event_snapshot->>'tipo_evento_real', ''))))";

    $stmt = $pdo->prepare("
        SELECT r.id,
               r.me_event_id,
               r.status,
               r.me_event_snapshot,
               (r.me_event_snapshot->>'data')::date as data_evento,
               (r.me_event_snapshot->>'nome') as nome_evento,
               (r.me_event_snapshot->>'local') as local_evento,
               (r.me_event_snapshot->>'hora_inicio') as hora_evento,
               (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome
        FROM eventos_reunioes r
        WHERE (r.me_event_snapshot->>'data')::date >= :start
          AND {$tipo_real_expr} IN ('casamento', '15anos')
        ORDER BY (r.me_event_snapshot->>'data')::date ASC, (r.me_event_snapshot->>'hora_inicio') ASC, r.id ASC
        LIMIT 30
    ");
    $stmt->execute([':start' => $start_date]);
    $eventos_raw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($eventos_raw as $ev) {
        $snapshot = json_decode((string)($ev['me_event_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $me_event_id = (int)($ev['me_event_id'] ?? ($snapshot['id'] ?? 0));
        $cancelado = (!empty($snapshot) && eventos_me_evento_cancelado($snapshot))
            || ($me_event_id > 0 && eventos_me_evento_cancelado_por_webhook($pdo, $me_event_id));

        if ($cancelado) {
            continue;
        }

        unset($ev['me_event_snapshot']);
        $eventos[] = $ev;
    }

    foreach ($eventos as $ev) {
        $data_evento = trim((string)($ev['data_evento'] ?? ''));
        if ($data_evento === '') {
            continue;
        }
        $data_norm = date('Y-m-d', strtotime($data_evento));
        if (!isset($eventos_por_data[$data_norm])) {
            $eventos_por_data[$data_norm] = [];
        }
        $eventos_por_data[$data_norm][] = $ev;
    }
} catch (Exception $e) {
    error_log("Erro portal DJ (lista calendário): " . $e->getMessage());
    $erro_eventos = 'Erro interno ao buscar eventos.';
}

// Ver detalhes de um evento
$evento_selecionado = null;
$secao_dj = null;
$secao_observacoes = null;
$anexos_dj = [];
$anexos_observacoes = [];
$arquivos_evento = [];

if (!empty($_GET['evento'])) {
    $evento_id = (int)$_GET['evento'];

    try {
        $tipo_real_expr = "COALESCE(NULLIF(LOWER(TRIM(r.tipo_evento_real)), ''), LOWER(TRIM(COALESCE(r.me_event_snapshot->>'tipo_evento_real', ''))))";
        $stmt = $pdo->prepare("
            SELECT r.id,
                   r.me_event_id,
                   r.status,
                   r.me_event_snapshot,
                   (r.me_event_snapshot->>'data')::date as data_evento,
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   (r.me_event_snapshot->>'local') as local_evento,
                   (r.me_event_snapshot->>'hora_inicio') as hora_evento,
                   (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome
            FROM eventos_reunioes r
            WHERE r.id = :id
              AND (r.me_event_snapshot->>'data')::date >= :start
              AND {$tipo_real_expr} IN ('casamento', '15anos')
            LIMIT 1
        ");
        $stmt->execute([':id' => $evento_id, ':start' => $start_date]);
        $evento_selecionado = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($evento_selecionado) {
            $snapshot = json_decode((string)($evento_selecionado['me_event_snapshot'] ?? '{}'), true);
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $me_event_id = (int)($evento_selecionado['me_event_id'] ?? ($snapshot['id'] ?? 0));
            $cancelado = (!empty($snapshot) && eventos_me_evento_cancelado($snapshot))
                || ($me_event_id > 0 && eventos_me_evento_cancelado_por_webhook($pdo, $me_event_id));

            if ($cancelado) {
                $aviso_evento_cancelado = 'Evento cancelado na ME. Ele foi ocultado do portal.';
                $evento_selecionado = null;
            } else {
                $secao_dj = eventos_reuniao_get_secao($pdo, $evento_id, 'dj_protocolo');
                $secao_observacoes = eventos_reuniao_get_secao($pdo, $evento_id, 'observacoes_gerais');
                $anexos_dj = eventos_reuniao_get_anexos($pdo, $evento_id, 'dj_protocolo');
                $anexos_observacoes = eventos_reuniao_get_anexos($pdo, $evento_id, 'observacoes_gerais');
                $arquivos_evento = eventos_arquivos_listar($pdo, $evento_id, false);
            }
        }
    } catch (Exception $e) {
        error_log("Erro portal DJ (detalhe): " . $e->getMessage());
        $evento_selecionado = null;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal DJ - Minha Agenda</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #7c3aed 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 {
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .header-user {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .header-user span {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
        }
        .btn-light {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .btn-light:hover {
            background: rgba(255,255,255,0.3);
        }
        .btn-primary {
            background: #1e3a8a;
            color: white;
        }
        .btn-primary:hover {
            background: #1e40af;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        .btn-small {
            padding: 0.4rem 0.7rem;
            font-size: 0.78rem;
            font-weight: 600;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        .section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 1rem;
        }
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        .event-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #1e3a8a;
            transition: all 0.2s;
        }
        .event-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .event-card.past {
            opacity: 0.6;
            border-left-color: #94a3b8;
        }
        .event-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        .event-meta {
            font-size: 0.875rem;
            color: #64748b;
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .event-date {
            display: inline-block;
            background: #eff6ff;
            color: #1e40af;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-top: 0.75rem;
        }
        .event-actions {
            margin-top: 1rem;
        }
        .detail-panel {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
        }
        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .detail-header h2 {
            font-size: 1.25rem;
        }
        .content-box {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1.5rem;
            min-height: 200px;
        }
        .content-box h3 {
            font-size: 0.95rem;
            color: #374151;
            margin-bottom: 1rem;
        }
        .anexos-list {
            margin-top: 1.5rem;
        }
        .anexo-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.75rem;
            background: #f1f5f9;
            border-radius: 6px;
            margin-bottom: 0.5rem;
            border: 1px solid #e2e8f0;
            flex-wrap: wrap;
        }
        .anexo-main {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            min-width: 0;
            flex: 1;
        }
        .anexo-icon {
            font-size: 1.1rem;
            line-height: 1;
            margin-top: 0.1rem;
        }
        .anexo-info {
            min-width: 0;
        }
        .anexo-name {
            font-size: 0.875rem;
            font-weight: 700;
            color: #0f172a;
            word-break: break-word;
        }
        .anexo-meta {
            margin-top: 0.2rem;
            font-size: 0.75rem;
            color: #64748b;
        }
        .anexo-note {
            margin-top: 0.2rem;
            font-size: 0.75rem;
            color: #475569;
        }
        .anexo-actions {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }

        /* Calendário (similar a eventos_calendario) */
        .month-nav {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .month-nav h2 {
            font-size: 1.125rem;
            color: #1e293b;
            margin: 0;
            min-width: 200px;
            text-align: center;
            flex: 1;
        }
        .nav-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1px solid #e2e8f0;
            background: white;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            transition: all 0.2s;
            color: #1e293b;
            text-decoration: none;
        }
        .nav-btn:hover {
            background: #f1f5f9;
            border-color: #1e3a8a;
        }
        .calendar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        .calendar-header {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #1e3a8a;
            color: white;
        }
        .calendar-header div {
            padding: 0.75rem;
            text-align: center;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .calendar-body {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
        }
        .calendar-day {
            min-height: 110px;
            border: 1px solid #e5e7eb;
            padding: 0.5rem;
            position: relative;
        }
        .calendar-day.other-month {
            background: #f8fafc;
            color: #94a3b8;
        }
        .calendar-day.today {
            background: #eff6ff;
        }
        .day-number {
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .calendar-day.today .day-number {
            background: #1e3a8a;
            color: white;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .day-events {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }
        .event-tag {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            cursor: pointer;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: all 0.2s;
            text-decoration: none;
            display: block;
        }
        .event-tag:hover { transform: translateX(2px); }
        .event-tag.rascunho {
            background: #fef3c7;
            color: #92400e;
            border-left: 3px solid #f59e0b;
        }
        .event-tag.concluida {
            background: #d1fae5;
            color: #065f46;
            border-left: 3px solid #10b981;
        }
        .event-tag.muted {
            background: #e2e8f0;
            color: #475569;
            cursor: default;
            transform: none !important;
        }
        .event-tag.more-btn {
            border: 0;
            width: 100%;
            text-align: left;
            font-family: inherit;
        }
        .event-tag.more-btn.muted {
            cursor: pointer;
        }
        .event-tag.more-btn:hover {
            transform: none;
        }
        .event-tag.more-btn.muted:hover {
            background: #cbd5e1;
        }

        /* Modal (lista de eventos do dia) */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: none;
            align-items: flex-end;
            justify-content: center;
            padding: 1rem;
            z-index: 9999;
        }
        .modal-overlay.open {
            display: flex;
        }
        .modal {
            background: #ffffff;
            width: min(560px, 100%);
            border-radius: 14px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            overflow: hidden;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }
        .modal-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }
        .modal-title {
            font-weight: 800;
            color: #0f172a;
            line-height: 1.1;
        }
        .modal-subtitle {
            margin-top: 0.25rem;
            font-size: 0.875rem;
            color: #64748b;
        }
        .modal-close {
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #0f172a;
            border-radius: 10px;
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .modal-close:hover {
            background: #f1f5f9;
        }
        .modal-body {
            padding: 1rem 1.25rem;
            overflow: auto;
        }
        .modal-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        .modal-item {
            border: 1px solid #e5e7eb;
            background: #f8fafc;
            border-radius: 12px;
            padding: 0.75rem 0.85rem;
        }
        .modal-link {
            color: #1e3a8a;
            text-decoration: none;
            font-weight: 800;
        }
        .modal-link:hover {
            text-decoration: underline;
        }
        .modal-meta {
            margin-top: 0.25rem;
            font-size: 0.85rem;
            color: #64748b;
        }
        .anexo-preview-body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 240px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
        }
        .anexo-preview-body img,
        .anexo-preview-body video {
            max-width: 100%;
            max-height: 65vh;
            display: block;
        }
        .anexo-preview-body audio {
            width: min(560px, 100%);
        }
        .anexo-preview-body iframe {
            width: 100%;
            height: min(70vh, 640px);
            border: 0;
            background: #fff;
        }
        .anexo-preview-empty {
            text-align: center;
            color: #64748b;
            font-size: 0.9rem;
            padding: 2rem 1rem;
        }
        .anexo-preview-footer {
            margin-top: 0.9rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .calendar-day { min-height: 80px; padding: 0.25rem; }
            .day-number { font-size: 0.75rem; }
            .event-tag { font-size: 0.65rem; padding: 0.125rem 0.25rem; }
            .calendar-header div { padding: 0.5rem; font-size: 0.75rem; }
            .anexo-actions {
                width: 100%;
            }
            .anexo-actions .btn {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎧 Portal DJ</h1>
        <div class="header-user">
            <span>Olá, <?= htmlspecialchars($nome) ?></span>
            <a href="?page=portal_dj&logout=1" class="btn btn-light">Sair</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($aviso_evento_cancelado !== ''): ?>
        <div style="margin-bottom:1rem; padding:0.85rem 1rem; border:1px solid #facc15; background:#fef9c3; color:#854d0e; border-radius:8px;">
            <?= htmlspecialchars($aviso_evento_cancelado) ?>
        </div>
        <?php endif; ?>
        <?php if ($evento_selecionado): ?>
        <!-- Detalhes do Evento -->
        <a href="?page=portal_dj" class="btn btn-primary" style="margin-bottom: 1rem;">← Voltar à lista</a>
        
        <div class="detail-panel">
            <div class="detail-header">
                <div>
                    <h2><?= htmlspecialchars($evento_selecionado['nome_evento']) ?></h2>
                    <p style="color: #64748b; margin-top: 0.25rem;">
                        📅 <?= date('d/m/Y', strtotime($evento_selecionado['data_evento'])) ?> às <?= htmlspecialchars($evento_selecionado['hora_evento'] ?: '-') ?>
                        • 📍 <?= htmlspecialchars($evento_selecionado['local_evento'] ?: '-') ?>
                        • 👤 <?= htmlspecialchars($evento_selecionado['cliente_nome'] ?: '-') ?>
                    </p>
                </div>
            </div>
            
            <div class="content-box">
                <h3>🎵 Músicas e Protocolos</h3>
                <?php if ($secao_dj && $secao_dj['content_html']): ?>
                <div><?= $secao_dj['content_html'] ?></div>
                <?php else: ?>
                <p style="color: #64748b; font-style: italic;">Nenhuma informação cadastrada ainda.</p>
                <?php endif; ?>
            </div>

            <div class="content-box" style="margin-top: 1rem;">
                <h3>📝 Observações Gerais</h3>
                <?php if ($secao_observacoes && $secao_observacoes['content_html']): ?>
                <div><?= $secao_observacoes['content_html'] ?></div>
                <?php else: ?>
                <p style="color: #64748b; font-style: italic;">Nenhuma observação geral cadastrada ainda.</p>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($anexos_dj)): ?>
            <div class="anexos-list">
                <h3 style="font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem;">📎 Anexos (DJ / Protocolos)</h3>
                <?php foreach ($anexos_dj as $a): ?>
                <?php
                    $anexo_url = trim((string)($a['public_url'] ?? ''));
                    $anexo_nome = trim((string)($a['original_name'] ?? 'arquivo'));
                    $anexo_mime = strtolower(trim((string)($a['mime_type'] ?? 'application/octet-stream')));
                    $anexo_kind = strtolower(trim((string)($a['file_kind'] ?? 'outros')));
                    $anexo_size = (int)($a['size_bytes'] ?? 0);
                    $anexo_note = trim((string)($a['note'] ?? ''));
                    $anexo_icon = '📎';
                    if ($anexo_kind === 'imagem') {
                        $anexo_icon = '🖼️';
                    } elseif ($anexo_kind === 'video') {
                        $anexo_icon = '🎬';
                    } elseif ($anexo_kind === 'audio') {
                        $anexo_icon = '🎵';
                    } elseif ($anexo_kind === 'pdf') {
                        $anexo_icon = '📄';
                    }
                ?>
                <div class="anexo-item">
                    <div class="anexo-main">
                        <span class="anexo-icon"><?= $anexo_icon ?></span>
                        <div class="anexo-info">
                            <div class="anexo-name"><?= htmlspecialchars($anexo_nome !== '' ? $anexo_nome : 'arquivo') ?></div>
                            <div class="anexo-meta">
                                <?= htmlspecialchars($anexo_mime !== '' ? $anexo_mime : 'application/octet-stream') ?>
                                • <?= $anexo_size > 0 ? htmlspecialchars(number_format($anexo_size / 1024, 1, ',', '.')) . ' KB' : '-' ?>
                            </div>
                            <?php if ($anexo_note !== ''): ?>
                            <div class="anexo-note"><strong>Obs:</strong> <?= htmlspecialchars($anexo_note) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="anexo-actions">
                        <?php if ($anexo_url !== ''): ?>
                        <button type="button"
                                class="btn btn-secondary btn-small"
                                data-open-anexo-modal="1"
                                data-url="<?= htmlspecialchars($anexo_url, ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars($anexo_nome, ENT_QUOTES, 'UTF-8') ?>"
                                data-mime="<?= htmlspecialchars($anexo_mime, ENT_QUOTES, 'UTF-8') ?>"
                                data-kind="<?= htmlspecialchars($anexo_kind, ENT_QUOTES, 'UTF-8') ?>">
                            Visualizar
                        </button>
                        <a href="<?= htmlspecialchars($anexo_url) ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           download
                           class="btn btn-primary btn-small">Download</a>
                        <?php else: ?>
                        <span class="anexo-meta">Arquivo sem URL pública.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($anexos_observacoes)): ?>
            <div class="anexos-list">
                <h3 style="font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem;">📎 Anexos (Observações Gerais)</h3>
                <?php foreach ($anexos_observacoes as $a): ?>
                <?php
                    $anexo_url = trim((string)($a['public_url'] ?? ''));
                    $anexo_nome = trim((string)($a['original_name'] ?? 'arquivo'));
                    $anexo_mime = strtolower(trim((string)($a['mime_type'] ?? 'application/octet-stream')));
                    $anexo_kind = strtolower(trim((string)($a['file_kind'] ?? 'outros')));
                    $anexo_size = (int)($a['size_bytes'] ?? 0);
                    $anexo_note = trim((string)($a['note'] ?? ''));
                    $anexo_icon = '📎';
                    if ($anexo_kind === 'imagem') {
                        $anexo_icon = '🖼️';
                    } elseif ($anexo_kind === 'video') {
                        $anexo_icon = '🎬';
                    } elseif ($anexo_kind === 'audio') {
                        $anexo_icon = '🎵';
                    } elseif ($anexo_kind === 'pdf') {
                        $anexo_icon = '📄';
                    }
                ?>
                <div class="anexo-item">
                    <div class="anexo-main">
                        <span class="anexo-icon"><?= $anexo_icon ?></span>
                        <div class="anexo-info">
                            <div class="anexo-name"><?= htmlspecialchars($anexo_nome !== '' ? $anexo_nome : 'arquivo') ?></div>
                            <div class="anexo-meta">
                                <?= htmlspecialchars($anexo_mime !== '' ? $anexo_mime : 'application/octet-stream') ?>
                                • <?= $anexo_size > 0 ? htmlspecialchars(number_format($anexo_size / 1024, 1, ',', '.')) . ' KB' : '-' ?>
                            </div>
                            <?php if ($anexo_note !== ''): ?>
                            <div class="anexo-note"><strong>Obs:</strong> <?= htmlspecialchars($anexo_note) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="anexo-actions">
                        <?php if ($anexo_url !== ''): ?>
                        <button type="button"
                                class="btn btn-secondary btn-small"
                                data-open-anexo-modal="1"
                                data-url="<?= htmlspecialchars($anexo_url, ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars($anexo_nome, ENT_QUOTES, 'UTF-8') ?>"
                                data-mime="<?= htmlspecialchars($anexo_mime, ENT_QUOTES, 'UTF-8') ?>"
                                data-kind="<?= htmlspecialchars($anexo_kind, ENT_QUOTES, 'UTF-8') ?>">
                            Visualizar
                        </button>
                        <a href="<?= htmlspecialchars($anexo_url) ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           download
                           class="btn btn-primary btn-small">Download</a>
                        <?php else: ?>
                        <span class="anexo-meta">Arquivo sem URL pública.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="anexos-list">
                <h3 style="font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem;">📁 Arquivos anexados do evento</h3>
                <?php if (empty($arquivos_evento)): ?>
                <p style="color: #64748b; font-style: italic;">Nenhum arquivo anexado neste evento.</p>
                <?php else: ?>
                <?php foreach ($arquivos_evento as $arquivo_evento): ?>
                <?php
                    $anexo_url = trim((string)($arquivo_evento['public_url'] ?? ''));
                    $anexo_nome = trim((string)($arquivo_evento['original_name'] ?? 'arquivo'));
                    $anexo_mime = strtolower(trim((string)($arquivo_evento['mime_type'] ?? 'application/octet-stream')));
                    $anexo_kind = strtolower(trim((string)($arquivo_evento['file_kind'] ?? 'outros')));
                    $anexo_size = (int)($arquivo_evento['size_bytes'] ?? 0);
                    $anexo_note = trim((string)($arquivo_evento['descricao'] ?? ''));
                    $anexo_campo = trim((string)($arquivo_evento['campo_titulo'] ?? ''));
                    $anexo_visivel_cliente = !empty($arquivo_evento['visivel_cliente']);
                    $anexo_upload_raw = trim((string)($arquivo_evento['uploaded_at'] ?? ''));
                    $anexo_upload_fmt = $anexo_upload_raw !== '' ? date('d/m/Y H:i', strtotime($anexo_upload_raw)) : '-';
                    $anexo_autor = trim((string)($arquivo_evento['uploaded_by_type'] ?? 'interno'));
                    $anexo_icon = '📎';
                    if ($anexo_kind === 'imagem') {
                        $anexo_icon = '🖼️';
                    } elseif ($anexo_kind === 'video') {
                        $anexo_icon = '🎬';
                    } elseif ($anexo_kind === 'audio') {
                        $anexo_icon = '🎵';
                    } elseif ($anexo_kind === 'pdf') {
                        $anexo_icon = '📄';
                    }
                ?>
                <div class="anexo-item">
                    <div class="anexo-main">
                        <span class="anexo-icon"><?= $anexo_icon ?></span>
                        <div class="anexo-info">
                            <div class="anexo-name"><?= htmlspecialchars($anexo_nome !== '' ? $anexo_nome : 'arquivo') ?></div>
                            <div class="anexo-meta">
                                <?= htmlspecialchars($anexo_mime !== '' ? $anexo_mime : 'application/octet-stream') ?>
                                • <?= $anexo_size > 0 ? htmlspecialchars(number_format($anexo_size / 1024, 1, ',', '.')) . ' KB' : '-' ?>
                                • Enviado em <?= htmlspecialchars($anexo_upload_fmt) ?>
                                • Origem: <?= htmlspecialchars($anexo_autor) ?>
                            </div>
                            <div class="anexo-note">
                                <?= $anexo_campo !== '' ? '<strong>Campo:</strong> ' . htmlspecialchars($anexo_campo) . ' • ' : '' ?>
                                <?= $anexo_visivel_cliente ? 'Visível no portal do cliente' : 'Uso interno da equipe' ?>
                            </div>
                            <?php if ($anexo_note !== ''): ?>
                            <div class="anexo-note"><strong>Descrição:</strong> <?= htmlspecialchars($anexo_note) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="anexo-actions">
                        <?php if ($anexo_url !== ''): ?>
                        <button type="button"
                                class="btn btn-secondary btn-small"
                                data-open-anexo-modal="1"
                                data-url="<?= htmlspecialchars($anexo_url, ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars($anexo_nome, ENT_QUOTES, 'UTF-8') ?>"
                                data-mime="<?= htmlspecialchars($anexo_mime, ENT_QUOTES, 'UTF-8') ?>"
                                data-kind="<?= htmlspecialchars($anexo_kind, ENT_QUOTES, 'UTF-8') ?>">
                            Visualizar
                        </button>
                        <a href="<?= htmlspecialchars($anexo_url) ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           download
                           class="btn btn-primary btn-small">Download</a>
                        <?php else: ?>
                        <span class="anexo-meta">Arquivo sem URL pública.</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        <!-- Calendário -->
        <h2 class="section-title">📅 Próximos 30 eventos</h2>
        <p style="color: #64748b; margin-top: -0.25rem; margin-bottom: 1rem;">Somente eventos com tipo real casamento ou 15 anos.</p>
        <div class="month-nav">
            <h2>Agenda por ordem de data/hora</h2>
            <a href="?page=portal_dj" class="btn btn-primary" style="margin-left: auto;">⟳ Atualizar</a>
        </div>

        <?php if ($erro_eventos): ?>
        <div class="empty-state">
            <p style="font-size: 2rem;">⚠️</p>
            <p><strong>Não foi possível carregar os eventos.</strong></p>
            <p><?= htmlspecialchars($erro_eventos) ?></p>
        </div>
        <?php elseif (empty($eventos)): ?>
        <div class="empty-state">
            <p style="font-size: 2rem;">🎧</p>
            <p><strong>Nenhum evento disponível.</strong></p>
            <p>Este calendário mostra os próximos 30 eventos com tipo real casamento ou 15 anos.</p>
        </div>
        <?php else: ?>
        <?php
            $today = new DateTime('today');
            $end = clone $today;
            if (!empty($eventos)) {
                $ultimo_evento = end($eventos);
                $ultima_data = trim((string)($ultimo_evento['data_evento'] ?? ''));
                if ($ultima_data !== '') {
                    $ts_ultima_data = strtotime($ultima_data);
                    if ($ts_ultima_data) {
                        $end = new DateTime(date('Y-m-d', $ts_ultima_data));
                    }
                }
                reset($eventos);
            }
            if ($end < $today) {
                $end = clone $today;
            }

            $start_grid = new DateTime('today');
            $weekday = (int)$start_grid->format('w');
            if ($weekday > 0) {
                $start_grid->modify('-' . $weekday . ' days');
            }

            $end_grid = clone $end;
            $weekday_end = (int)$end_grid->format('w');
            if ($weekday_end < 6) {
                $end_grid->modify('+' . (6 - $weekday_end) . ' days');
            }

            $cursor = clone $start_grid;
        ?>

        <div class="calendar">
            <div class="calendar-header">
                <div>Dom</div>
                <div>Seg</div>
                <div>Ter</div>
                <div>Qua</div>
                <div>Qui</div>
                <div>Sex</div>
                <div>Sáb</div>
            </div>
            <div class="calendar-body">
                <?php while ($cursor <= $end_grid):
                    $date_key = $cursor->format('Y-m-d');
                    $is_today = $date_key === $today->format('Y-m-d');
                    $is_outside = ($cursor < $today) || ($cursor > $end);
                    $day_events = $eventos_por_data[$date_key] ?? [];
                ?>
                <div class="calendar-day <?= $is_outside ? 'other-month' : '' ?> <?= $is_today ? 'today' : '' ?>">
                    <div class="day-number"><?= (int)$cursor->format('j') ?></div>
                    <div class="day-events">
                        <?php foreach (array_slice($day_events, 0, 3) as $ev): 
                            $title_parts = [];
                            $title_parts[] = trim((string)($ev['nome_evento'] ?? ''));
                            if (!empty($ev['hora_evento'])) $title_parts[] = 'Hora: ' . $ev['hora_evento'];
                            if (!empty($ev['local_evento'])) $title_parts[] = 'Local: ' . $ev['local_evento'];
                            $title = trim(implode(' | ', array_filter($title_parts)));
                            $ev_name = (string)($ev['nome_evento'] ?? '');
                            $short = function_exists('mb_substr') ? mb_substr($ev_name, 0, 15) : substr($ev_name, 0, 15);
                            $len = function_exists('mb_strlen') ? mb_strlen($ev_name) : strlen($ev_name);
                        ?>
                        <a href="?page=portal_dj&evento=<?= (int)$ev['id'] ?>"
                           class="event-tag concluida"
                           title="<?= htmlspecialchars($title ?: $ev_name) ?>">
                            <?= htmlspecialchars($short) ?><?= ($len > 15 ? '...' : '') ?>
                        </a>
                        <?php endforeach; ?>
                        <?php if (count($day_events) > 3): ?>
                            <?php
                                $events_payload = [];
                                foreach ($day_events as $ev_full) {
                                    $events_payload[] = [
                                        'name' => (string)($ev_full['nome_evento'] ?? ''),
                                        'url' => '?page=portal_dj&evento=' . (int)($ev_full['id'] ?? 0),
                                        'time' => (string)($ev_full['hora_evento'] ?? ''),
                                        'local' => (string)($ev_full['local_evento'] ?? ''),
                                        'cliente' => (string)($ev_full['cliente_nome'] ?? ''),
                                    ];
                                }
                                $events_json = htmlspecialchars(
                                    json_encode($events_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            ?>
                        <button
                                type="button"
                                class="event-tag muted more-btn"
                                data-open-day-modal="1"
                                data-date-display="<?= htmlspecialchars($cursor->format('d/m/Y')) ?>"
                                data-events="<?= $events_json ?>"
                            >+<?= (int)(count($day_events) - 3) ?> mais</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $cursor->modify('+1 day'); endwhile; ?>
            </div>
        </div>

        <h3 class="section-title" style="margin-top: 0;">📋 Próximos 30 eventos (<?= (int)count($eventos) ?>)</h3>
        <div class="events-grid">
            <?php foreach ($eventos as $ev):
                $is_past = strtotime($ev['data_evento']) < strtotime('today');
            ?>
            <div class="event-card <?= $is_past ? 'past' : '' ?>">
                <div class="event-name"><?= htmlspecialchars($ev['nome_evento']) ?></div>
                <div class="event-meta">
                    <span>📍 <?= htmlspecialchars($ev['local_evento'] ?: 'Local não definido') ?></span>
                    <span>👤 <?= htmlspecialchars($ev['cliente_nome'] ?: 'Cliente') ?></span>
                </div>
                <div class="event-date">
                    📅 <?= date('d/m/Y', strtotime($ev['data_evento'])) ?> às <?= htmlspecialchars($ev['hora_evento'] ?: '-') ?>
                </div>
                <div class="event-actions">
                    <a href="?page=portal_dj&evento=<?= (int)$ev['id'] ?>" class="btn btn-primary">Ver Detalhes</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
	        <?php endif; ?>
	        <?php endif; ?>
	    </div>
        <div id="dayModalOverlay" class="modal-overlay" aria-hidden="true">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dayModalTitle">
                <div class="modal-header">
                    <div>
                        <div class="modal-title" id="dayModalTitle">Eventos</div>
                        <div class="modal-subtitle" id="dayModalSubtitle"></div>
                    </div>
                    <button type="button" class="modal-close" data-day-modal-close="1">Fechar</button>
                </div>
                <div class="modal-body">
                    <div class="modal-list" id="dayModalList"></div>
                </div>
            </div>
        </div>
        <div id="anexoModalOverlay" class="modal-overlay" aria-hidden="true">
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="anexoModalTitle">
                <div class="modal-header">
                    <div>
                        <div class="modal-title" id="anexoModalTitle">Pré-visualização</div>
                        <div class="modal-subtitle" id="anexoModalSubtitle"></div>
                    </div>
                    <button type="button" class="modal-close" data-close-anexo-modal="1">Fechar</button>
                </div>
                <div class="modal-body">
                    <div class="anexo-preview-body" id="anexoModalBody"></div>
                    <div class="anexo-preview-footer">
                        <a href="#"
                           id="anexoModalDownload"
                           target="_blank"
                           rel="noopener noreferrer"
                           download
                           class="btn btn-primary btn-small">Download</a>
                        <button type="button" class="btn btn-secondary btn-small" data-close-anexo-modal="1">Fechar</button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            (function() {
                const overlay = document.getElementById('dayModalOverlay');
                const list = document.getElementById('dayModalList');
                const title = document.getElementById('dayModalTitle');
                const subtitle = document.getElementById('dayModalSubtitle');

                if (!overlay || !list || !title || !subtitle) return;

                function closeModal() {
                    overlay.classList.remove('open');
                    overlay.setAttribute('aria-hidden', 'true');
                    document.body.style.overflow = '';
                }

                function openModal(btn) {
                    const dateDisplay = btn.getAttribute('data-date-display') || '';
                    let events = [];
                    try {
                        events = JSON.parse(btn.getAttribute('data-events') || '[]') || [];
                    } catch (e) {
                        events = [];
                    }

                    title.textContent = 'Eventos do dia';
                    subtitle.textContent = (dateDisplay ? dateDisplay : '') + (events.length ? ' • ' + events.length : '');
                    list.innerHTML = '';

                    if (!events.length) {
                        const empty = document.createElement('div');
                        empty.style.color = '#64748b';
                        empty.textContent = 'Nenhum evento.';
                        list.appendChild(empty);
                    } else {
                        events.forEach((ev) => {
                            const item = document.createElement('div');
                            item.className = 'modal-item';

                            const a = document.createElement('a');
                            a.className = 'modal-link';
                            a.href = ev.url || '#';
                            a.textContent = ev.name || 'Evento';

                            const meta = document.createElement('div');
                            meta.className = 'modal-meta';
                            const parts = [];
                            if (ev.time) parts.push('Hora: ' + ev.time);
                            if (ev.local) parts.push('Local: ' + ev.local);
                            if (ev.cliente) parts.push('Cliente: ' + ev.cliente);
                            meta.textContent = parts.join(' • ');

                            item.appendChild(a);
                            if (parts.length) item.appendChild(meta);
                            list.appendChild(item);
                        });
                    }

                    overlay.classList.add('open');
                    overlay.setAttribute('aria-hidden', 'false');
                    document.body.style.overflow = 'hidden';
                }

                document.addEventListener('click', (e) => {
                    const openBtn = e.target.closest('[data-open-day-modal="1"]');
                    if (openBtn) {
                        openModal(openBtn);
                        return;
                    }
                    if (e.target.closest('[data-day-modal-close="1"]')) {
                        closeModal();
                        return;
                    }
                    if (e.target === overlay) {
                        closeModal();
                    }
                });

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && overlay.classList.contains('open')) {
                        closeModal();
                    }
                });
            })();

            (function() {
                const overlay = document.getElementById('anexoModalOverlay');
                const body = document.getElementById('anexoModalBody');
                const title = document.getElementById('anexoModalTitle');
                const subtitle = document.getElementById('anexoModalSubtitle');
                const downloadLink = document.getElementById('anexoModalDownload');

                if (!overlay || !body || !title || !subtitle || !downloadLink) return;

                function closeAttachmentModal() {
                    overlay.classList.remove('open');
                    overlay.setAttribute('aria-hidden', 'true');
                    document.body.style.overflow = '';
                    body.innerHTML = '';
                    subtitle.textContent = '';
                    downloadLink.setAttribute('href', '#');
                    downloadLink.removeAttribute('download');
                }

                function getFileExtension(fileName) {
                    const name = String(fileName || '').trim();
                    const idx = name.lastIndexOf('.');
                    if (idx < 0) return '';
                    return name.slice(idx + 1).toLowerCase();
                }

                function openAttachmentModal(btn) {
                    const url = String(btn.getAttribute('data-url') || '').trim();
                    const name = String(btn.getAttribute('data-name') || 'arquivo').trim() || 'arquivo';
                    const mime = String(btn.getAttribute('data-mime') || '').toLowerCase();
                    const kind = String(btn.getAttribute('data-kind') || '').toLowerCase();
                    const ext = getFileExtension(name);

                    title.textContent = name;
                    subtitle.textContent = mime || kind || 'Arquivo';
                    body.innerHTML = '';

                    if (url === '') {
                        const msg = document.createElement('div');
                        msg.className = 'anexo-preview-empty';
                        msg.textContent = 'Arquivo sem URL pública para visualização.';
                        body.appendChild(msg);
                        downloadLink.setAttribute('href', '#');
                        downloadLink.removeAttribute('download');
                        downloadLink.style.display = 'none';
                    } else {
                        downloadLink.style.display = 'inline-flex';
                        downloadLink.setAttribute('href', url);
                        downloadLink.setAttribute('download', name);

                        const isImage = kind === 'imagem'
                            || mime.startsWith('image/')
                            || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif'].includes(ext);
                        const isVideo = kind === 'video'
                            || mime.startsWith('video/')
                            || ['mp4', 'mov', 'webm', 'ogg', 'avi'].includes(ext);
                        const isAudio = kind === 'audio'
                            || mime.startsWith('audio/')
                            || ['mp3', 'wav', 'ogg', 'aac', 'm4a'].includes(ext);
                        const isPdf = kind === 'pdf' || mime === 'application/pdf' || ext === 'pdf';

                        if (isImage) {
                            const img = document.createElement('img');
                            img.src = url;
                            img.alt = name;
                            body.appendChild(img);
                        } else if (isVideo) {
                            const video = document.createElement('video');
                            video.src = url;
                            video.controls = true;
                            video.preload = 'metadata';
                            body.appendChild(video);
                        } else if (isAudio) {
                            const audio = document.createElement('audio');
                            audio.src = url;
                            audio.controls = true;
                            audio.preload = 'metadata';
                            body.appendChild(audio);
                        } else if (isPdf) {
                            const frame = document.createElement('iframe');
                            frame.src = url;
                            frame.title = name;
                            body.appendChild(frame);
                        } else {
                            const msg = document.createElement('div');
                            msg.className = 'anexo-preview-empty';
                            msg.innerHTML = 'Pré-visualização não disponível para este formato.<br>Use o botão Download.';
                            body.appendChild(msg);
                        }
                    }

                    overlay.classList.add('open');
                    overlay.setAttribute('aria-hidden', 'false');
                    document.body.style.overflow = 'hidden';
                }

                document.addEventListener('click', (e) => {
                    const openBtn = e.target.closest('[data-open-anexo-modal="1"]');
                    if (openBtn) {
                        openAttachmentModal(openBtn);
                        return;
                    }
                    if (e.target.closest('[data-close-anexo-modal="1"]')) {
                        closeAttachmentModal();
                        return;
                    }
                    if (e.target === overlay) {
                        closeAttachmentModal();
                    }
                });

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && overlay.classList.contains('open')) {
                        closeAttachmentModal();
                    }
                });
            })();
        </script>
</body>
</html>
