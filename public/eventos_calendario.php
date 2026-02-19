<?php
/**
 * eventos_calendario.php
 * Calendário de reuniões com visualização mensal
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/core/google_calendar_auto_sync.php';

// Verificar permissão
if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? 0;

// Auto-sync Google Calendar ao carregar o calendário (com throttling por sessão)
google_calendar_auto_sync($GLOBALS['pdo'], 'eventos_calendario');

// Mês/Ano atual
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));

// Validar
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$first_day = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = (int)date('t', $first_day);
$start_weekday = (int)date('w', $first_day); // 0=Dom, 6=Sab

// Buscar reuniões do mês
$start_date = date('Y-m-01', $first_day);
$end_date = date('Y-m-t', $first_day);

$reunioes = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, 
               (r.me_event_snapshot->>'data') as data_evento,
               (r.me_event_snapshot->>'nome') as nome_evento,
               (r.me_event_snapshot->>'local') as local_evento,
               (r.me_event_snapshot->'cliente'->>'nome') as cliente_nome
        FROM eventos_reunioes r
        WHERE (r.me_event_snapshot->>'data')::date BETWEEN :start AND :end
        ORDER BY (r.me_event_snapshot->>'data')::date ASC
    ");
    $stmt->execute([':start' => $start_date, ':end' => $end_date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar por dia
    foreach ($rows as $r) {
        $day = (int)date('j', strtotime($r['data_evento']));
        if (!isset($reunioes[$day])) {
            $reunioes[$day] = [];
        }
        $reunioes[$day][] = $r;
    }
} catch (Exception $e) {
    error_log("Erro ao buscar reuniões: " . $e->getMessage());
}

$month_names = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

includeSidebar('Calendário de Reuniões');
?>

<style>
    .calendario-container {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
        background: #f8fafc;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .page-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e3a8a;
        margin: 0;
    }
    
    .header-actions {
        display: flex;
        gap: 0.75rem;
    }
    
    .btn {
        padding: 0.625rem 1.25rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }
    
    .btn-primary {
        background: #1e3a8a;
        color: white;
    }
    
    .btn-primary:hover {
        background: #2563eb;
    }
    
    .btn-secondary {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    
    .btn-secondary:hover {
        background: #e2e8f0;
    }
    
    /* Navegação do mês */
    .month-nav {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .month-nav h2 {
        font-size: 1.25rem;
        color: #1e293b;
        margin: 0;
        min-width: 200px;
        text-align: center;
    }
    
    .nav-btn {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        border: 1px solid #e2e8f0;
        background: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        transition: all 0.2s;
    }
    
    .nav-btn:hover {
        background: #f1f5f9;
        border-color: #1e3a8a;
    }
    
    /* Calendário */
    .calendar {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        overflow: hidden;
    }
    
    .calendar-header {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        background: #1e3a8a;
        color: white;
    }
    
    .calendar-header div {
        padding: 1rem;
        text-align: center;
        font-weight: 600;
        font-size: 0.875rem;
    }
    
    .calendar-body {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
    }
    
    .calendar-day {
        min-height: 120px;
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
        display: flex;
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
    }
    
    .event-tag:hover {
        transform: translateX(2px);
    }
    
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
    
    /* Lista de reuniões */
    .reunioes-list {
        margin-top: 2rem;
    }
    
    .reunioes-list h3 {
        font-size: 1.125rem;
        color: #374151;
        margin-bottom: 1rem;
    }
    
    .reuniao-card {
        background: white;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-left: 4px solid #1e3a8a;
    }
    
    .reuniao-info h4 {
        margin: 0;
        font-size: 0.95rem;
        color: #1e293b;
    }
    
    .reuniao-info p {
        margin: 0.25rem 0 0 0;
        font-size: 0.8rem;
        color: #64748b;
    }
    
    .reuniao-actions {
        display: flex;
        gap: 0.5rem;
    }
    
    .btn-sm {
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
    }
    
    /* Responsivo */
    @media (max-width: 768px) {
        .calendario-container {
            padding: 1rem;
        }
        
        .calendar-day {
            min-height: 80px;
            padding: 0.25rem;
        }
        
        .day-number {
            font-size: 0.75rem;
        }
        
        .event-tag {
            font-size: 0.65rem;
            padding: 0.125rem 0.25rem;
        }
        
        .calendar-header div {
            padding: 0.5rem;
            font-size: 0.75rem;
        }
    }
</style>

<div class="calendario-container">
    <div class="page-header">
        <h1 class="page-title">📅 Calendário de Reuniões</h1>
        <div class="header-actions">
            <a href="index.php?page=eventos_organizacao" class="btn btn-primary">+ Nova Organização</a>
            <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
        </div>
    </div>
    
    <!-- Navegação do mês -->
    <div class="month-nav">
        <a href="?page=eventos_calendario&month=<?= $month - 1 ?>&year=<?= $year ?>" class="nav-btn">←</a>
        <h2><?= $month_names[$month] ?> <?= $year ?></h2>
        <a href="?page=eventos_calendario&month=<?= $month + 1 ?>&year=<?= $year ?>" class="nav-btn">→</a>
        <a href="?page=eventos_calendario" class="btn btn-secondary btn-sm">Hoje</a>
    </div>
    
    <!-- Calendário -->
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
            $today = date('Y-m-d');
            $cell = 0;
            
            // Dias vazios antes do primeiro dia
            for ($i = 0; $i < $start_weekday; $i++):
                $prev_day = date('j', strtotime("-" . ($start_weekday - $i) . " days", $first_day));
            ?>
            <div class="calendar-day other-month">
                <div class="day-number"><?= $prev_day ?></div>
            </div>
            <?php 
                $cell++;
            endfor; 
            
            // Dias do mês
            for ($day = 1; $day <= $days_in_month; $day++):
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $is_today = $date === $today;
                $day_events = $reunioes[$day] ?? [];
            ?>
            <div class="calendar-day <?= $is_today ? 'today' : '' ?>">
                <div class="day-number"><?= $day ?></div>
                <div class="day-events">
                    <?php foreach (array_slice($day_events, 0, 3) as $ev): ?>
                    <a href="index.php?page=eventos_organizacao&id=<?= $ev['id'] ?>" 
                       class="event-tag <?= $ev['status'] ?>"
                       title="<?= htmlspecialchars($ev['nome_evento']) ?>">
                        <?= htmlspecialchars(mb_substr($ev['nome_evento'], 0, 15)) ?>...
                    </a>
                    <?php endforeach; ?>
                    <?php if (count($day_events) > 3): ?>
                    <span class="event-tag" style="background: #e2e8f0; color: #475569;">
                        +<?= count($day_events) - 3 ?> mais
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <?php 
                $cell++;
            endfor;
            
            // Dias vazios após o último dia
            while ($cell % 7 !== 0):
                $next_day = $cell - $start_weekday - $days_in_month + 1;
            ?>
            <div class="calendar-day other-month">
                <div class="day-number"><?= $next_day ?></div>
            </div>
            <?php 
                $cell++;
            endwhile; 
            ?>
        </div>
    </div>
    
    <!-- Lista de reuniões do mês -->
    <div class="reunioes-list">
        <h3>📋 Reuniões deste mês (<?= array_sum(array_map('count', $reunioes)) ?>)</h3>
        
        <?php if (empty($reunioes)): ?>
        <p style="color: #64748b;">Nenhuma reunião cadastrada para este mês.</p>
        <?php else: ?>
        <?php foreach ($reunioes as $day => $events): ?>
        <?php foreach ($events as $ev): ?>
        <div class="reuniao-card">
            <div class="reuniao-info">
                <h4><?= htmlspecialchars($ev['nome_evento']) ?></h4>
                <p>
                    📅 <?= date('d/m/Y', strtotime($ev['data_evento'])) ?> 
                    • 📍 <?= htmlspecialchars($ev['local_evento'] ?: 'Local não definido') ?>
                    • 👤 <?= htmlspecialchars($ev['cliente_nome'] ?: 'Cliente') ?>
                    • <span style="color: <?= $ev['status'] === 'concluida' ? '#059669' : '#f59e0b' ?>">
                        <?= $ev['status'] === 'concluida' ? '✓ Concluída' : '⏳ Rascunho' ?>
                    </span>
                </p>
            </div>
            <div class="reuniao-actions">
                <a href="index.php?page=eventos_organizacao&id=<?= $ev['id'] ?>" class="btn btn-primary btn-sm">Editar</a>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php endSidebar(); ?>
