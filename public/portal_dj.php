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
    header('Location: index.php?page=portal_dj_login');
    exit;
}

$fornecedor_id = $_SESSION['portal_dj_fornecedor_id'];
$nome = $_SESSION['portal_dj_nome'];

// Mês/Ano atual (para calendário)
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

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

// Buscar eventos do mês (somente eventos com link de DJ gerado)
$eventos = [];
$eventos_por_dia = [];
try {
    $first_day = mktime(0, 0, 0, $month, 1, $year);
    $start_date = date('Y-m-01', $first_day);
    $end_date = date('Y-m-t', $first_day);

    $stmt = $pdo->prepare("
        SELECT r.id,
               r.status,
               (r.me_event_snapshot->>'data') as data_evento,
               (r.me_event_snapshot->>'nome') as nome_evento,
               (r.me_event_snapshot->>'local') as local_evento,
               (r.me_event_snapshot->>'hora_inicio') as hora_evento,
               (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome,
               BOOL_OR(lp.submitted_at IS NOT NULL) AS dj_submitted,
               COUNT(*) FILTER (WHERE lp.is_active = TRUE) AS links_ativos
        FROM eventos_reunioes r
        JOIN eventos_links_publicos lp ON lp.meeting_id = r.id
        WHERE lp.link_type = 'cliente_dj'
          AND lp.is_active = TRUE
          AND (r.me_event_snapshot->>'data')::date BETWEEN :start AND :end
          AND COALESCE(r.me_event_snapshot->>'me_status', 'ativo') <> 'cancelado'
        GROUP BY r.id, r.status, r.me_event_snapshot
        ORDER BY (r.me_event_snapshot->>'data')::date ASC
    ");
    $stmt->execute([':start' => $start_date, ':end' => $end_date]);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($eventos as $ev) {
        $day = (int)date('j', strtotime($ev['data_evento']));
        if (!isset($eventos_por_dia[$day])) {
            $eventos_por_dia[$day] = [];
        }
        $eventos_por_dia[$day][] = $ev;
    }
} catch (Exception $e) {
    error_log("Erro portal DJ (lista calendário): " . $e->getMessage());
}

// Ver detalhes de um evento
$evento_selecionado = null;
$secao_dj = null;
$anexos = [];

if (!empty($_GET['evento'])) {
    $evento_id = (int)$_GET['evento'];

    try {
        $stmt = $pdo->prepare("
            SELECT r.id,
                   r.status,
                   r.me_event_snapshot,
                   (r.me_event_snapshot->>'data') as data_evento,
                   (r.me_event_snapshot->>'nome') as nome_evento,
                   (r.me_event_snapshot->>'local') as local_evento,
                   (r.me_event_snapshot->>'hora_inicio') as hora_evento,
                   (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome
            FROM eventos_reunioes r
            WHERE r.id = :id
              AND EXISTS (
                SELECT 1 FROM eventos_links_publicos lp
                WHERE lp.meeting_id = r.id
                  AND lp.link_type = 'cliente_dj'
                  AND lp.is_active = TRUE
              )
            LIMIT 1
        ");
        $stmt->execute([':id' => $evento_id]);
        $evento_selecionado = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($evento_selecionado) {
            $secao_dj = eventos_reuniao_get_secao($pdo, $evento_id, 'dj_protocolo');
            $anexos = eventos_reuniao_get_anexos($pdo, $evento_id, 'dj_protocolo');
        }
    } catch (Exception $e) {
        error_log("Erro portal DJ (detalhe): " . $e->getMessage());
        $evento_selecionado = null;
    }
}

$month_names = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];
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
            gap: 0.75rem;
            padding: 0.75rem;
            background: #f1f5f9;
            border-radius: 6px;
            margin-bottom: 0.5rem;
        }
        .anexo-item a {
            color: #1e3a8a;
            text-decoration: none;
            font-size: 0.875rem;
        }
        .anexo-item a:hover {
            text-decoration: underline;
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

        @media (max-width: 768px) {
            .container { padding: 1rem; }
            .header { flex-direction: column; gap: 1rem; text-align: center; }
            .calendar-day { min-height: 80px; padding: 0.25rem; }
            .day-number { font-size: 0.75rem; }
            .event-tag { font-size: 0.65rem; padding: 0.125rem 0.25rem; }
            .calendar-header div { padding: 0.5rem; font-size: 0.75rem; }
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
            
            <?php if (!empty($anexos)): ?>
            <div class="anexos-list">
                <h3 style="font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem;">📎 Anexos</h3>
                <?php foreach ($anexos as $a): ?>
                <div class="anexo-item">
                    <span>📄</span>
                    <a href="<?= htmlspecialchars($a['public_url'] ?: '#') ?>" target="_blank">
                        <?= htmlspecialchars($a['original_name']) ?>
                    </a>
                    <span style="color: #94a3b8; font-size: 0.75rem;">
                        (<?= round($a['size_bytes'] / 1024) ?> KB)
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <?php else: ?>
        <!-- Calendário -->
        <h2 class="section-title">📅 Calendário (somente eventos com formulário DJ gerado)</h2>

        <div class="month-nav">
            <a class="nav-btn" href="?page=portal_dj&month=<?= $month - 1 ?>&year=<?= $year ?>" aria-label="Mês anterior">←</a>
            <h2><?= htmlspecialchars($month_names[$month] ?? '') ?> <?= (int)$year ?></h2>
            <a class="nav-btn" href="?page=portal_dj&month=<?= $month + 1 ?>&year=<?= $year ?>" aria-label="Próximo mês">→</a>
            <a href="?page=portal_dj" class="btn btn-primary" style="margin-left: auto;">Hoje</a>
        </div>

        <?php
            $first_day = mktime(0, 0, 0, $month, 1, $year);
            $days_in_month = (int)date('t', $first_day);
            $start_weekday = (int)date('w', $first_day); // 0=Dom, 6=Sab
            $today = date('Y-m-d');
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
                <?php
                    $cell = 0;
                    for ($i = 0; $i < $start_weekday; $i++):
                        $prev_day = date('j', strtotime("-" . ($start_weekday - $i) . " days", $first_day));
                ?>
                <div class="calendar-day other-month">
                    <div class="day-number"><?= (int)$prev_day ?></div>
                </div>
                <?php $cell++; endfor; ?>

                <?php for ($day = 1; $day <= $days_in_month; $day++):
                    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $is_today = $date === $today;
                    $day_events = $eventos_por_dia[$day] ?? [];
                ?>
                <div class="calendar-day <?= $is_today ? 'today' : '' ?>">
                    <div class="day-number"><?= (int)$day ?></div>
                    <div class="day-events">
                        <?php foreach (array_slice($day_events, 0, 3) as $ev): 
                            $title_parts = [];
                            $title_parts[] = trim((string)($ev['nome_evento'] ?? ''));
                            if (!empty($ev['hora_evento'])) $title_parts[] = 'Hora: ' . $ev['hora_evento'];
                            if (!empty($ev['local_evento'])) $title_parts[] = 'Local: ' . $ev['local_evento'];
                            $title = trim(implode(' | ', array_filter($title_parts)));
                            $ev_name = (string)($ev['nome_evento'] ?? '');
                            $short = function_exists('mb_substr') ? mb_substr($ev_name, 0, 15) : substr($ev_name, 0, 15);
                            $tag_class = !empty($ev['dj_submitted']) ? 'concluida' : 'rascunho';
                        ?>
                        <a href="?page=portal_dj&evento=<?= (int)$ev['id'] ?>"
                           class="event-tag <?= $tag_class ?>"
                           title="<?= htmlspecialchars($title ?: $ev_name) ?>">
                            <?= htmlspecialchars($short) ?><?= (strlen($ev_name) > 15 ? '...' : '') ?>
                        </a>
                        <?php endforeach; ?>
                        <?php if (count($day_events) > 3): ?>
                        <span class="event-tag muted">+<?= (int)(count($day_events) - 3) ?> mais</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php $cell++; endfor; ?>

                <?php while ($cell % 7 !== 0):
                    $next_day = $cell - $start_weekday - $days_in_month + 1;
                ?>
                <div class="calendar-day other-month">
                    <div class="day-number"><?= (int)$next_day ?></div>
                </div>
                <?php $cell++; endwhile; ?>
            </div>
        </div>

        <?php if (empty($eventos)): ?>
        <div class="empty-state">
            <p style="font-size: 2rem;">🎧</p>
            <p><strong>Nenhum evento com formulário de DJ gerado neste mês.</strong></p>
            <p>Os eventos aparecem aqui quando a equipe gera o link do formulário DJ na reunião final.</p>
        </div>
        <?php else: ?>
        <h3 class="section-title" style="margin-top: 0;">📋 Eventos deste mês (<?= (int)count($eventos) ?>)</h3>
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
</body>
</html>
