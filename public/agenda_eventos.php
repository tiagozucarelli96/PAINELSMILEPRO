<?php
/**
 * agenda_eventos.php
 * Calendário de eventos filtrado por unidade/space visível do usuário.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

if (empty($_SESSION['perm_agenda_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function agenda_eventos_has_table(PDO $pdo, string $table): bool
{
    try {
        // Respeita o search_path ativo da aplicação em produção.
        $stmt = $pdo->prepare("SELECT to_regclass(:table)");
        $stmt->execute([':table' => $table]);
        $reg = $stmt->fetchColumn();
        return is_string($reg) && trim($reg) !== '';
    } catch (Throwable $e) {
        return false;
    }
}

function agenda_eventos_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM pg_attribute
            WHERE attrelid = to_regclass(:table)
              AND attname = :column
              AND NOT attisdropped
            LIMIT 1
        ");
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function agenda_eventos_fetch_user_spaces(PDO $pdo, int $usuarioId): array
{
    if (!agenda_eventos_has_table($pdo, 'usuarios_spaces_visiveis')) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT TRIM(space_visivel) AS space_visivel
        FROM usuarios_spaces_visiveis
        WHERE usuario_id = :usuario_id
          AND TRIM(COALESCE(space_visivel, '')) <> ''
        ORDER BY TRIM(space_visivel) ASC
    ");
    $stmt->execute([':usuario_id' => $usuarioId]);

    $spaces = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $space) {
        $space = trim((string)$space);
        if ($space !== '') {
            $spaces[$space] = $space;
        }
    }

    return array_values($spaces);
}

function agenda_eventos_month_label(DateTimeImmutable $date): string
{
    $meses = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    return ($meses[(int)$date->format('n')] ?? $date->format('F')) . ' ' . $date->format('Y');
}

$usuarioId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$isSuperadmin = !empty($_SESSION['perm_superadmin']);
$spacesUsuario = $isSuperadmin ? [] : agenda_eventos_fetch_user_spaces($pdo, $usuarioId);

$mesAtual = new DateTimeImmutable('first day of this month');
$mesSeguinte = $mesAtual->modify('+1 month');
$fimPeriodo = $mesSeguinte->modify('last day of this month');
$hojeIso = (new DateTimeImmutable('today'))->format('Y-m-d');

$months = [$mesAtual, $mesSeguinte];
$weekDays = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
$eventos = [];
$eventosPorData = [];
$errors = [];

$temTabelaEventos = agenda_eventos_has_table($pdo, 'logistica_eventos_espelho');
$temColunaNomeEvento = $temTabelaEventos && agenda_eventos_has_column($pdo, 'logistica_eventos_espelho', 'nome_evento');
$temTabelaReunioes = agenda_eventos_has_table($pdo, 'eventos_reunioes');

if (!$temTabelaEventos) {
    $errors[] = 'A tabela de eventos da logística ainda não existe. Execute a base da Logística antes de usar este módulo.';
} elseif (!$isSuperadmin && empty($spacesUsuario)) {
    $errors[] = 'Este usuário não possui nenhuma unidade/space marcada na aba "Unidade".';
} else {
    try {
        $joinReunioes = $temTabelaReunioes
            ? "
                LEFT JOIN LATERAL (
                    SELECT er.me_event_snapshot
                    FROM eventos_reunioes er
                    WHERE er.me_event_id = e.me_event_id
                    ORDER BY er.updated_at DESC NULLS LAST, er.id DESC
                    LIMIT 1
                ) r ON TRUE
            "
            : '';

        $nomeEventoSql = $temColunaNomeEvento
            ? "COALESCE(NULLIF(TRIM(e.nome_evento), ''), 'Evento sem nome')"
            : "'Evento sem nome'";

        $horaFimSql = $temTabelaReunioes
            ? "COALESCE(
                    NULLIF(TRIM(r.me_event_snapshot->>'hora_fim'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'horafim'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'horatermino'), '')
               )"
            : "NULL";

        $snapshotLocalSql = $temTabelaReunioes
            ? "COALESCE(
                    NULLIF(TRIM(r.me_event_snapshot->>'local'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'nomelocal'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'localevento'), ''),
                    NULLIF(TRIM(r.me_event_snapshot->>'localEvento'), '')
               )"
            : "NULL";

        $sql = "
            SELECT
                e.id,
                e.me_event_id,
                e.data_evento::text AS data_evento,
                COALESCE(TO_CHAR(e.hora_inicio, 'HH24:MI'), '') AS hora_inicio,
                {$horaFimSql} AS hora_fim,
                {$nomeEventoSql} AS nome_evento,
                COALESCE(NULLIF(TRIM(e.localevento), ''), {$snapshotLocalSql}, 'Local não informado') AS local_evento,
                COALESCE(NULLIF(TRIM(e.space_visivel), ''), 'Não mapeado') AS space_visivel,
                COALESCE(NULLIF(TRIM(e.status_mapeamento), ''), 'PENDENTE') AS status_mapeamento,
                COALESCE(e.convidados, 0) AS convidados,
                COALESCE(e.idlocalevento, 0) AS id_local_me
            FROM logistica_eventos_espelho e
            {$joinReunioes}
            WHERE COALESCE(e.arquivado, FALSE) = FALSE
              AND e.data_evento BETWEEN :inicio AND :fim
        ";

        $params = [
            ':inicio' => $mesAtual->format('Y-m-d'),
            ':fim' => $fimPeriodo->format('Y-m-d'),
        ];

        if (!$isSuperadmin) {
            $placeholders = [];
            foreach ($spacesUsuario as $index => $space) {
                $key = ':space_' . $index;
                $placeholders[] = $key;
                $params[$key] = $space;
            }
            $sql .= ' AND TRIM(COALESCE(e.space_visivel, \'\')) IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY e.data_evento ASC, e.hora_inicio ASC NULLS LAST, e.id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $errors[] = 'Não foi possível carregar os eventos.';
        error_log('[AGENDA_EVENTOS] ' . $e->getMessage());
    }
}

foreach ($eventos as $evento) {
    $dataEvento = (string)($evento['data_evento'] ?? '');
    if ($dataEvento === '') {
        continue;
    }
    if (!isset($eventosPorData[$dataEvento])) {
        $eventosPorData[$dataEvento] = [];
    }
    $eventosPorData[$dataEvento][] = $evento;
}

includeSidebar('Agenda de eventos');
?>

<style>
.agenda-eventos-page {
    max-width: 1480px;
    margin: 0 auto;
    padding: 1.5rem;
    background: #f8fafc;
}

.agenda-eventos-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}

.agenda-eventos-title {
    margin: 0;
    font-size: 2rem;
    font-weight: 800;
    color: #1e3a8a;
}

.agenda-eventos-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.95rem;
}

.agenda-eventos-badges {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.agenda-badge {
    background: #ffffff;
    border: 1px solid #dbe3ef;
    border-radius: 999px;
    padding: 0.65rem 0.95rem;
    font-size: 0.85rem;
    color: #334155;
    box-shadow: 0 6px 20px rgba(15, 23, 42, 0.06);
}

.agenda-alert {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-left: 4px solid #1d4ed8;
    color: #334155;
    border-radius: 14px;
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
}

.agenda-alert.error {
    border-left-color: #dc2626;
}

.agenda-calendars {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.25rem;
}

.calendar-card {
    background: #ffffff;
    border: 1px solid #dbe3ef;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.1rem;
    background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
    border-bottom: 1px solid #e2e8f0;
}

.calendar-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
}

.calendar-caption {
    font-size: 0.8rem;
    color: #64748b;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
}

.weekday {
    padding: 0.8rem 0.45rem;
    text-align: center;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.75rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.day-cell {
    min-height: 150px;
    padding: 0.7rem;
    border-right: 1px solid #eef2f7;
    border-bottom: 1px solid #eef2f7;
    background: #ffffff;
}

.day-cell:nth-child(7n) {
    border-right: none;
}

.day-cell.is-empty {
    background: #f8fafc;
}

.day-cell.is-today {
    background: linear-gradient(180deg, #eff6ff 0%, #ffffff 100%);
}

.day-number {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 0.55rem;
    font-size: 0.95rem;
    font-weight: 700;
    color: #1e293b;
}

.today-pill {
    font-size: 0.68rem;
    font-weight: 700;
    color: #1d4ed8;
    background: #dbeafe;
    border-radius: 999px;
    padding: 0.16rem 0.45rem;
}

.day-events {
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
}

.event-chip {
    width: 100%;
    text-align: left;
    border: 1px solid #dbeafe;
    background: #eff6ff;
    border-radius: 12px;
    padding: 0.55rem 0.6rem;
    cursor: pointer;
    transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

.event-chip:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 18px rgba(37, 99, 235, 0.12);
    border-color: #93c5fd;
}

.event-chip-time {
    font-size: 0.72rem;
    font-weight: 700;
    color: #1d4ed8;
    margin-bottom: 0.18rem;
}

.event-chip-name {
    font-size: 0.8rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1.25;
}

.event-chip-meta {
    margin-top: 0.18rem;
    font-size: 0.72rem;
    color: #64748b;
    line-height: 1.25;
}

.event-chip-guests {
    margin-top: 0.3rem;
    font-size: 0.72rem;
    font-weight: 700;
    color: #0f766e;
}

.event-more {
    font-size: 0.73rem;
    color: #475569;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    padding: 0.45rem 0.55rem;
}

.agenda-empty {
    background: #ffffff;
    border: 1px dashed #cbd5e1;
    border-radius: 16px;
    padding: 2rem;
    text-align: center;
    color: #64748b;
    margin-top: 1rem;
}

.agenda-modal {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    z-index: 1600;
}

.agenda-modal.is-open {
    display: flex;
}

.agenda-modal-card {
    width: min(100%, 560px);
    background: #ffffff;
    border-radius: 20px;
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.28);
    overflow: hidden;
}

.agenda-modal-header {
    padding: 1.1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.agenda-modal-title {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 800;
    color: #0f172a;
}

.agenda-modal-subtitle {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.85rem;
}

.agenda-modal-close {
    border: none;
    background: #f1f5f9;
    color: #334155;
    width: 36px;
    height: 36px;
    border-radius: 999px;
    cursor: pointer;
    font-size: 1.25rem;
}

.agenda-modal-body {
    padding: 1.1rem 1.25rem 1.25rem;
}

.agenda-modal-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem;
}

.agenda-modal-field {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 0.85rem;
}

.agenda-modal-label {
    display: block;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    margin-bottom: 0.3rem;
    font-weight: 700;
}

.agenda-modal-value {
    color: #0f172a;
    font-size: 0.94rem;
    font-weight: 600;
    line-height: 1.35;
    word-break: break-word;
}

@media (max-width: 1100px) {
    .agenda-calendars {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 720px) {
    .agenda-eventos-page {
        padding: 1rem;
    }

    .day-cell {
        min-height: 120px;
        padding: 0.55rem;
    }

    .agenda-modal-grid {
        grid-template-columns: 1fr;
    }

    .calendar-title {
        font-size: 1rem;
    }
}
</style>

<div class="agenda-eventos-page">
    <div class="agenda-eventos-header">
        <div>
            <h1 class="agenda-eventos-title">Agenda de eventos</h1>
            <p class="agenda-eventos-subtitle">Calendário do mês atual e do próximo mês, filtrado pelas unidades marcadas no usuário.</p>
        </div>
        <div class="agenda-eventos-badges">
            <div class="agenda-badge">Período: <?= h($mesAtual->format('d/m/Y')) ?> até <?= h($fimPeriodo->format('d/m/Y')) ?></div>
            <div class="agenda-badge">Eventos: <?= count($eventos) ?></div>
            <?php if ($isSuperadmin): ?>
                <div class="agenda-badge">Visualização: todas as unidades</div>
            <?php else: ?>
                <div class="agenda-badge">Unidades: <?= h(implode(', ', $spacesUsuario)) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($errors)): ?>
        <div class="agenda-alert">
            Os eventos são exibidos com base no mapeamento <strong>ME → Unidade/Space</strong> da Logística e respeitam os checkboxes da aba <strong>Unidade</strong> no cadastro do usuário.
        </div>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <div class="agenda-alert error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if (empty($errors)): ?>
        <div class="agenda-calendars">
            <?php foreach ($months as $monthDate): ?>
                <?php
                $monthStart = $monthDate->modify('first day of this month');
                $daysInMonth = (int)$monthStart->format('t');
                $firstWeekday = (int)$monthStart->format('N');
                ?>
                <section class="calendar-card">
                    <div class="calendar-header">
                        <h2 class="calendar-title"><?= h(agenda_eventos_month_label($monthStart)) ?></h2>
                        <span class="calendar-caption">Somente eventos mapeados para este período</span>
                    </div>

                    <div class="calendar-grid">
                        <?php foreach ($weekDays as $weekDay): ?>
                            <div class="weekday"><?= h($weekDay) ?></div>
                        <?php endforeach; ?>

                        <?php for ($blank = 1; $blank < $firstWeekday; $blank++): ?>
                            <div class="day-cell is-empty"></div>
                        <?php endfor; ?>

                        <?php for ($day = 1; $day <= $daysInMonth; $day++): ?>
                            <?php
                            $dateObj = $monthStart->setDate((int)$monthStart->format('Y'), (int)$monthStart->format('m'), $day);
                            $dateKey = $dateObj->format('Y-m-d');
                            $dayEvents = $eventosPorData[$dateKey] ?? [];
                            $isToday = $dateKey === $hojeIso;
                            ?>
                            <div class="day-cell<?= $isToday ? ' is-today' : '' ?>">
                                <div class="day-number">
                                    <span><?= $day ?></span>
                                    <?php if ($isToday): ?>
                                        <span class="today-pill">Hoje</span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($dayEvents)): ?>
                                    <div class="day-events">
                                        <?php foreach (array_slice($dayEvents, 0, 3) as $evento): ?>
                                            <?php
                                            $eventoPayload = htmlspecialchars(json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                                            $horaInicio = trim((string)($evento['hora_inicio'] ?? ''));
                                            $horaFim = trim((string)($evento['hora_fim'] ?? ''));
                                            $horario = $horaInicio !== '' ? $horaInicio : 'Sem horário';
                                            if ($horaInicio !== '' && $horaFim !== '') {
                                                $horario .= ' - ' . $horaFim;
                                            }
                                            ?>
                                            <button type="button" class="event-chip" data-event='<?= $eventoPayload ?>'>
                                                <div class="event-chip-time"><?= h($horario) ?></div>
                                                <div class="event-chip-name"><?= h((string)($evento['nome_evento'] ?? 'Evento')) ?></div>
                                                <div class="event-chip-meta"><?= h((string)($evento['local_evento'] ?? 'Local não informado')) ?></div>
                                                <div class="event-chip-guests">Convidados: <?= (int)($evento['convidados'] ?? 0) ?></div>
                                            </button>
                                        <?php endforeach; ?>

                                        <?php if (count($dayEvents) > 3): ?>
                                            <div class="event-more">+<?= count($dayEvents) - 3 ?> evento(s) neste dia</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <?php if (empty($eventos)): ?>
            <div class="agenda-empty">
                Nenhum evento encontrado para as unidades selecionadas no período atual.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div id="agendaEventosModal" class="agenda-modal" aria-hidden="true">
    <div class="agenda-modal-card">
        <div class="agenda-modal-header">
            <div>
                <h3 class="agenda-modal-title" id="agendaEventosModalTitle">Evento</h3>
                <p class="agenda-modal-subtitle" id="agendaEventosModalSubtitle">Detalhes básicos do evento</p>
            </div>
            <button type="button" class="agenda-modal-close" id="agendaEventosModalClose" aria-label="Fechar">&times;</button>
        </div>
        <div class="agenda-modal-body">
            <div class="agenda-modal-grid">
                <div class="agenda-modal-field">
                    <span class="agenda-modal-label">Local</span>
                    <div class="agenda-modal-value" id="modalLocalEvento">-</div>
                </div>
                <div class="agenda-modal-field">
                    <span class="agenda-modal-label">Space</span>
                    <div class="agenda-modal-value" id="modalSpaceEvento">-</div>
                </div>
                <div class="agenda-modal-field">
                    <span class="agenda-modal-label">Data</span>
                    <div class="agenda-modal-value" id="modalDataEvento">-</div>
                </div>
                <div class="agenda-modal-field">
                    <span class="agenda-modal-label">Horário</span>
                    <div class="agenda-modal-value" id="modalHorarioEvento">-</div>
                </div>
                <div class="agenda-modal-field">
                    <span class="agenda-modal-label">Convidados</span>
                    <div class="agenda-modal-value" id="modalConvidadosEvento">-</div>
                </div>
                <div class="agenda-modal-field">
                    <span class="agenda-modal-label">Status do mapeamento</span>
                    <div class="agenda-modal-value" id="modalStatusEvento">-</div>
                </div>
                <div class="agenda-modal-field">
                    <span class="agenda-modal-label">ID evento ME</span>
                    <div class="agenda-modal-value" id="modalMeEvento">-</div>
                </div>
                <div class="agenda-modal-field">
                    <span class="agenda-modal-label">ID local ME</span>
                    <div class="agenda-modal-value" id="modalLocalMeEvento">-</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const modal = document.getElementById('agendaEventosModal');
    const closeButton = document.getElementById('agendaEventosModalClose');

    if (!modal || !closeButton) {
        return;
    }

    const fields = {
        title: document.getElementById('agendaEventosModalTitle'),
        subtitle: document.getElementById('agendaEventosModalSubtitle'),
        local: document.getElementById('modalLocalEvento'),
        space: document.getElementById('modalSpaceEvento'),
        data: document.getElementById('modalDataEvento'),
        horario: document.getElementById('modalHorarioEvento'),
        convidados: document.getElementById('modalConvidadosEvento'),
        status: document.getElementById('modalStatusEvento'),
        me: document.getElementById('modalMeEvento'),
        localMe: document.getElementById('modalLocalMeEvento')
    };

    const formatDate = (iso) => {
        if (!iso) return '-';
        const parts = String(iso).split('-');
        if (parts.length !== 3) return iso;
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
    };

    const openModal = (evento) => {
        const horaInicio = String(evento.hora_inicio || '').trim();
        const horaFim = String(evento.hora_fim || '').trim();
        let horario = horaInicio || 'Sem horário informado';
        if (horaInicio && horaFim) {
            horario = `${horaInicio} - ${horaFim}`;
        }

        fields.title.textContent = evento.nome_evento || 'Evento';
        fields.subtitle.textContent = `ME #${evento.me_event_id || '-'} • ${evento.space_visivel || 'Sem space'}`;
        fields.local.textContent = evento.local_evento || '-';
        fields.space.textContent = evento.space_visivel || '-';
        fields.data.textContent = formatDate(evento.data_evento || '');
        fields.horario.textContent = horario;
        fields.convidados.textContent = String(evento.convidados ?? '-');
        fields.status.textContent = evento.status_mapeamento || '-';
        fields.me.textContent = String(evento.me_event_id || '-');
        fields.localMe.textContent = String(evento.id_local_me || '-');

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    };

    document.querySelectorAll('.event-chip').forEach((button) => {
        button.addEventListener('click', () => {
            try {
                const payload = JSON.parse(button.dataset.event || '{}');
                openModal(payload);
            } catch (error) {
                console.error('Falha ao abrir modal do evento:', error);
            }
        });
    });

    closeButton.addEventListener('click', closeModal);
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
})();
</script>

<?php endSidebar(); ?>
