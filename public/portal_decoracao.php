<?php
/**
 * portal_decoracao.php
 * Painel do Portal Decoração (acesso externo)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

// Verificar login
if (empty($_SESSION['portal_decoracao_logado']) || $_SESSION['portal_decoracao_logado'] !== true) {
    header('Location: index.php?page=portal_decoracao_login');
    exit;
}

$fornecedor_id = $_SESSION['portal_decoracao_fornecedor_id'];
$nome = $_SESSION['portal_decoracao_nome'];

// Logout
if (isset($_GET['logout'])) {
    if (!empty($_SESSION['portal_decoracao_token'])) {
        $stmt = $pdo->prepare("UPDATE eventos_fornecedores_sessoes SET ativo = FALSE WHERE token = :token");
        $stmt->execute([':token' => $_SESSION['portal_decoracao_token']]);
    }
    
    unset($_SESSION['portal_decoracao_logado']);
    unset($_SESSION['portal_decoracao_fornecedor_id']);
    unset($_SESSION['portal_decoracao_nome']);
    unset($_SESSION['portal_decoracao_token']);
    
    header('Location: index.php?page=portal_decoracao_login');
    exit;
}

// Buscar eventos da ME (próximos 30 dias)
$eventos = [];
$eventos_por_data = [];
$erro_eventos = '';
$force_refresh = !empty($_GET['refresh']);

try {
    $result = eventos_me_buscar_futuros($pdo, '', 30, $force_refresh);
    if (empty($result['ok'])) {
        $erro_eventos = $result['error'] ?? 'Erro ao buscar eventos da ME';
    } else {
        foreach (($result['events'] ?? []) as $ev) {
            if (!is_array($ev)) {
                continue;
            }

            $id = eventos_me_pick_int($ev, ['id']);
            if ($id <= 0) {
                continue;
            }

            $nome_evento = eventos_me_pick_text($ev, ['nomeevento', 'nome'], 'Sem nome');
            $data_raw = eventos_me_pick_text($ev, ['dataevento', 'data']);
            if ($data_raw === '') {
                continue;
            }
            $ts = strtotime($data_raw);
            if (!$ts) {
                continue;
            }
            $data_norm = date('Y-m-d', $ts);

            $hora_evento = eventos_me_pick_text($ev, ['horainicio', 'hora_inicio', 'horaevento']);
            $local_evento = eventos_me_pick_text($ev, ['local', 'nomelocal', 'localevento', 'localEvento', 'endereco']);
            $cliente_nome = eventos_me_pick_text($ev, ['nomecliente', 'nomeCliente', 'cliente.nome']);

            $item = [
                'me_event_id' => $id,
                'data_evento' => $data_norm,
                'nome_evento' => $nome_evento,
                'local_evento' => $local_evento,
                'hora_evento' => $hora_evento,
                'cliente_nome' => $cliente_nome,
            ];

            $eventos[] = $item;
            if (!isset($eventos_por_data[$data_norm])) {
                $eventos_por_data[$data_norm] = [];
            }
            $eventos_por_data[$data_norm][] = $item;
        }

        usort($eventos, function($a, $b) {
            $da = (string)($a['data_evento'] ?? '');
            $db = (string)($b['data_evento'] ?? '');
            $cmp = strcmp($da, $db);
            if ($cmp !== 0) return $cmp;
            return strcmp((string)($a['hora_evento'] ?? ''), (string)($b['hora_evento'] ?? ''));
        });
    }
} catch (Throwable $e) {
    error_log("Erro portal Decoracao (ME): " . $e->getMessage());
    $erro_eventos = 'Erro interno ao buscar eventos.';
}

// Ver detalhes
$evento_selecionado = null;
$secao_decoracao = null;
$anexos = [];
$evento_me = null;
$evento_me_id = 0;
$meeting_id = 0;

// Prioridade: detalhe por me_event_id (mesmo se não existir reunião interna)
if (!empty($_GET['me_event_id'])) {
    $evento_me_id = (int)$_GET['me_event_id'];
    if ($evento_me_id > 0) {
        $me = eventos_me_buscar_por_id($pdo, $evento_me_id);
        if (!empty($me['ok']) && !empty($me['event']) && is_array($me['event'])) {
            $evento_me = $me['event'];
        }

        $stmt = $pdo->prepare("SELECT id FROM eventos_reunioes WHERE me_event_id = :me LIMIT 1");
        $stmt->execute([':me' => $evento_me_id]);
        $meeting_id = (int)($stmt->fetchColumn() ?: 0);

        if ($meeting_id > 0) {
            $evento_selecionado = eventos_reuniao_get($pdo, $meeting_id);
            $secao_decoracao = eventos_reuniao_get_secao($pdo, $meeting_id, 'decoracao');
            $anexos = eventos_reuniao_get_anexos($pdo, $meeting_id, 'decoracao');
        }
    }
}

// Compat: detalhe por ID da reunião (links antigos)
if (!$evento_me_id && !empty($_GET['evento'])) {
    $meeting_id = (int)$_GET['evento'];
    if ($meeting_id > 0) {
        $evento_selecionado = eventos_reuniao_get($pdo, $meeting_id);
        if ($evento_selecionado) {
            $secao_decoracao = eventos_reuniao_get_secao($pdo, $meeting_id, 'decoracao');
            $anexos = eventos_reuniao_get_anexos($pdo, $meeting_id, 'decoracao');
        }
    }
}

// Preparar cabeçalho do evento (para detalhe)
$detail_nome = '';
$detail_data = '';
$detail_hora = '';
$detail_local = '';
$detail_cliente = '';

if (is_array($evento_me)) {
    $detail_nome = eventos_me_pick_text($evento_me, ['nomeevento', 'nome'], 'Evento');
    $detail_data = eventos_me_pick_text($evento_me, ['dataevento', 'data'], '');
    $detail_hora = eventos_me_pick_text($evento_me, ['horainicio', 'hora_inicio', 'horaevento'], '');
    $detail_local = eventos_me_pick_text($evento_me, ['local', 'nomelocal', 'localevento', 'localEvento', 'endereco'], '');
    $detail_cliente = eventos_me_pick_text($evento_me, ['nomecliente', 'nomeCliente', 'cliente.nome'], '');
} elseif (is_array($evento_selecionado)) {
    $snap = json_decode((string)($evento_selecionado['me_event_snapshot'] ?? '{}'), true);
    $snap = is_array($snap) ? $snap : [];
    $detail_nome = trim((string)($snap['nome'] ?? 'Evento'));
    $detail_data = trim((string)($snap['data'] ?? ''));
    $detail_hora = trim((string)($snap['hora_inicio'] ?? ''));
    $detail_local = trim((string)($snap['local'] ?? ''));
    $detail_cliente = trim((string)($snap['cliente']['nome'] ?? ''));
    $evento_me_id = (int)($snap['id'] ?? 0);
}

$detail_data_fmt = $detail_data !== '' ? date('d/m/Y', strtotime($detail_data)) : '-';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Decoração - Minha Agenda</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
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
        .btn-primary {
            background: #059669;
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
            border-left: 4px solid #059669;
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
            background: #d1fae5;
            color: #065f46;
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
            color: #059669;
            text-decoration: none;
            font-size: 0.875rem;
        }
        .anexo-item a:hover {
            text-decoration: underline;
        }
        .images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .image-item {
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 1;
        }
        .image-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }

        /* Calendário (próximos 30 dias) */
        .calendar-header-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .calendar-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #374151;
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
            background: #059669;
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
        }
        .calendar-day.other-month {
            background: #f8fafc;
            color: #94a3b8;
        }
        .calendar-day.today {
            background: #ecfdf5;
        }
        .day-number {
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .calendar-day.today .day-number {
            background: #059669;
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
            background: #d1fae5;
            color: #065f46;
            border-left: 3px solid #10b981;
        }
        .event-tag:hover { transform: translateX(2px); }
        .event-tag.muted {
            background: #e2e8f0;
            color: #475569;
            border-left: none;
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
        <h1>🎨 Portal Decoração</h1>
        <div class="header-user">
            <span>Olá, <?= htmlspecialchars($nome) ?></span>
            <a href="?page=portal_decoracao&logout=1" class="btn btn-light">Sair</a>
        </div>
	    </div>
	    
	    <div class="container">
	        <?php if ($evento_selecionado || $evento_me_id): ?>
	        <a href="?page=portal_decoracao" class="btn btn-primary" style="margin-bottom: 1rem;">← Voltar ao calendário</a>
	        
	        <div class="detail-panel">
	            <div class="detail-header">
	                <div>
	                    <h2><?= htmlspecialchars($detail_nome ?: 'Evento') ?></h2>
	                    <p style="color: #64748b; margin-top: 0.25rem;">
	                        📅 <?= htmlspecialchars($detail_data_fmt) ?> às <?= htmlspecialchars($detail_hora !== '' ? $detail_hora : '-') ?>
	                        • 📍 <?= htmlspecialchars($detail_local !== '' ? $detail_local : '-') ?>
	                        • 👤 <?= htmlspecialchars($detail_cliente !== '' ? $detail_cliente : '-') ?>
	                    </p>
	                </div>
	            </div>
	            
	            <div class="content-box">
	                <h3>🎨 Decoração</h3>
	                <?php if ($secao_decoracao && $secao_decoracao['content_html']): ?>
	                <div><?= $secao_decoracao['content_html'] ?></div>
	                <?php elseif ($meeting_id <= 0): ?>
	                <p style="color: #64748b; font-style: italic;">
	                    Este evento ainda não tem uma reunião criada no painel, então não há conteúdo de decoração salvo.
	                </p>
	                <?php else: ?>
	                <p style="color: #64748b; font-style: italic;">Nenhuma informação cadastrada ainda.</p>
	                <?php endif; ?>
	            </div>
            
            <?php 
            $imagens = array_filter($anexos, fn($a) => $a['file_kind'] === 'imagem');
            $outros = array_filter($anexos, fn($a) => $a['file_kind'] !== 'imagem');
            ?>
            
            <?php if (!empty($imagens)): ?>
            <div class="anexos-list">
                <h3 style="font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem;">🖼️ Imagens</h3>
                <div class="images-grid">
                    <?php foreach ($imagens as $img): ?>
                    <a href="<?= htmlspecialchars($img['public_url'] ?: '#') ?>" target="_blank" class="image-item">
                        <img src="<?= htmlspecialchars($img['public_url'] ?: '') ?>" alt="<?= htmlspecialchars($img['original_name']) ?>">
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($outros)): ?>
            <div class="anexos-list">
                <h3 style="font-size: 0.95rem; color: #374151; margin-bottom: 0.75rem;">📎 Outros Anexos</h3>
                <?php foreach ($outros as $a): ?>
                <div class="anexo-item">
                    <span>📄</span>
                    <a href="<?= htmlspecialchars($a['public_url'] ?: '#') ?>" target="_blank">
                        <?= htmlspecialchars($a['original_name']) ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
	        </div>
	        
	        <?php else: ?>
	        <div class="calendar-header-row">
	            <div class="calendar-title">📅 Próximos eventos (30 dias)</div>
	            <div style="display:flex; gap: 0.5rem; flex-wrap: wrap;">
	                <a href="?page=portal_decoracao&refresh=1" class="btn btn-primary">⟳ Atualizar</a>
	            </div>
	        </div>
	        
	        <?php if ($erro_eventos): ?>
	        <div class="empty-state">
	            <p style="font-size: 2rem;">⚠️</p>
	            <p><strong>Não foi possível carregar os eventos.</strong></p>
	            <p><?= htmlspecialchars($erro_eventos) ?></p>
	        </div>
	        <?php elseif (empty($eventos)): ?>
	        <div class="empty-state">
	            <p style="font-size: 2rem;">🎨</p>
	            <p><strong>Nenhum evento nos próximos 30 dias.</strong></p>
	            <p>Este calendário mostra automaticamente os eventos da ME Eventos.</p>
	        </div>
	        <?php else: ?>
	        <?php
	            $today = new DateTime('today');
	            $end = new DateTime('today');
	            $end->modify('+30 days');

	            $start_grid = new DateTime('today');
	            $weekday = (int)$start_grid->format('w'); // 0=Dom
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
	                        <a href="?page=portal_decoracao&me_event_id=<?= (int)$ev['me_event_id'] ?>"
	                           class="event-tag"
	                           title="<?= htmlspecialchars($title ?: $ev_name) ?>">
	                            <?= htmlspecialchars($short) ?><?= ($len > 15 ? '...' : '') ?>
	                        </a>
	                        <?php endforeach; ?>
	                        <?php if (count($day_events) > 3): ?>
	                        <span class="event-tag muted">+<?= (int)(count($day_events) - 3) ?> mais</span>
	                        <?php endif; ?>
	                    </div>
	                </div>
	                <?php $cursor->modify('+1 day'); endwhile; ?>
	            </div>
	        </div>

	        <h2 class="section-title">📋 Lista (30 dias)</h2>
	        <div class="events-grid">
	            <?php foreach ($eventos as $ev): 
	                $is_past = strtotime($ev['data_evento'] ?? '') < strtotime('today');
	            ?>
	            <div class="event-card <?= $is_past ? 'past' : '' ?>">
	                <div class="event-name"><?= htmlspecialchars($ev['nome_evento']) ?></div>
	                <div class="event-meta">
	                    <span>📍 <?= htmlspecialchars(($ev['local_evento'] ?? '') !== '' ? $ev['local_evento'] : 'Local não definido') ?></span>
	                    <span>👤 <?= htmlspecialchars(($ev['cliente_nome'] ?? '') !== '' ? $ev['cliente_nome'] : 'Cliente') ?></span>
	                </div>
	                <div class="event-date">
	                    📅 <?= htmlspecialchars(date('d/m/Y', strtotime($ev['data_evento']))) ?> às <?= htmlspecialchars(($ev['hora_evento'] ?? '') !== '' ? $ev['hora_evento'] : '-') ?>
	                </div>
	                <div class="event-actions">
	                    <a href="?page=portal_decoracao&me_event_id=<?= (int)$ev['me_event_id'] ?>" class="btn btn-primary">Ver Detalhes</a>
	                </div>
	            </div>
	            <?php endforeach; ?>
	        </div>
	        <?php endif; ?>
	        <?php endif; ?>
	    </div>
	</body>
	</html>
