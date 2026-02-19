<?php
/**
 * eventos_organizacao.php
 * Hub de Organização de Eventos (interno)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$meeting_id = (int)($_GET['id'] ?? $_POST['meeting_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($action) {
            case 'organizar_evento':
                $me_event_id = (int)($_POST['me_event_id'] ?? 0);
                if ($me_event_id <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'Selecione um evento válido.']);
                    exit;
                }

                $meeting_result = eventos_reuniao_get_or_create($pdo, $me_event_id, $user_id);
                if (empty($meeting_result['ok']) || empty($meeting_result['reuniao']['id'])) {
                    echo json_encode(['ok' => false, 'error' => $meeting_result['error'] ?? 'Não foi possível organizar este evento.']);
                    exit;
                }

                $meeting_id = (int)$meeting_result['reuniao']['id'];
                $portal_result = eventos_cliente_portal_get_or_create($pdo, $meeting_id, $user_id);
                if (empty($portal_result['ok']) || empty($portal_result['portal'])) {
                    echo json_encode(['ok' => false, 'error' => $portal_result['error'] ?? 'Falha ao gerar link do portal do cliente.']);
                    exit;
                }

                echo json_encode([
                    'ok' => true,
                    'reuniao' => ['id' => $meeting_id],
                    'portal' => $portal_result['portal'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;

            case 'salvar_portal_config':
                if ($meeting_id <= 0) {
                    echo json_encode(['ok' => false, 'error' => 'Reunião inválida.']);
                    exit;
                }

                $result = eventos_cliente_portal_atualizar_config(
                    $pdo,
                    $meeting_id,
                    [
                        'visivel_reuniao' => ((string)($_POST['visivel_reuniao'] ?? '0') === '1'),
                        'editavel_reuniao' => ((string)($_POST['editavel_reuniao'] ?? '0') === '1'),
                        'visivel_dj' => ((string)($_POST['visivel_dj'] ?? '0') === '1'),
                        'editavel_dj' => ((string)($_POST['editavel_dj'] ?? '0') === '1'),
                        'visivel_convidados' => ((string)($_POST['visivel_convidados'] ?? '0') === '1'),
                        'editavel_convidados' => ((string)($_POST['editavel_convidados'] ?? '0') === '1'),
                    ],
                    $user_id
                );

                echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
        }
    } catch (Throwable $e) {
        error_log('eventos_organizacao POST: ' . $e->getMessage());
        echo json_encode([
            'ok' => false,
            'error' => 'Erro interno ao processar a solicitação.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$reuniao = null;
$snapshot = [];
$portal = null;
$links_cliente_dj = [];
$links_cliente_observacoes = [];

if ($meeting_id > 0) {
    $reuniao = eventos_reuniao_get($pdo, $meeting_id);
    if ($reuniao) {
        $snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
        if (!is_array($snapshot)) {
            $snapshot = [];
        }

        $portal_result = eventos_cliente_portal_get_or_create($pdo, $meeting_id, $user_id);
        if (!empty($portal_result['ok']) && !empty($portal_result['portal'])) {
            $portal = $portal_result['portal'];
        }

        $links_cliente_dj = eventos_reuniao_listar_links_cliente($pdo, $meeting_id, 'cliente_dj');
        $links_cliente_observacoes = eventos_reuniao_listar_links_cliente($pdo, $meeting_id, 'cliente_observacoes');
    }
}

$nome_evento = trim((string)($snapshot['nome'] ?? 'Evento'));
$data_evento = trim((string)($snapshot['data'] ?? ''));
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
$local_evento = trim((string)($snapshot['local'] ?? 'Local não definido'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente não informado'));
$data_fmt = $data_evento !== '' ? date('d/m/Y', strtotime($data_evento)) : '-';
$horario_fmt = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_fmt .= ' - ' . $hora_fim;
}

$portal_url = (string)($portal['url'] ?? '');
$visivel_reuniao = !empty($portal['visivel_reuniao']);
$editavel_reuniao = !empty($portal['editavel_reuniao']);
$visivel_dj = !empty($portal['visivel_dj']);
$editavel_dj = !empty($portal['editavel_dj']);
$visivel_convidados = !empty($portal['visivel_convidados']);
$editavel_convidados = !empty($portal['editavel_convidados']);
$convidados_resumo = $meeting_id > 0 ? eventos_convidados_resumo($pdo, $meeting_id) : ['total' => 0, 'checkin' => 0, 'pendentes' => 0];

$has_dj_link = !empty($links_cliente_dj);
$has_obs_link = !empty($links_cliente_observacoes);

includeSidebar('Organização eventos');
?>

<style>
    .organizacao-container {
        padding: 2rem;
        max-width: 1280px;
        margin: 0 auto;
        background: #f8fafc;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
    }

    .page-title {
        font-size: 1.6rem;
        font-weight: 700;
        color: #1e3a8a;
        margin: 0;
    }

    .page-subtitle {
        color: #64748b;
        margin-top: 0.35rem;
        font-size: 0.92rem;
    }

    .btn {
        padding: 0.62rem 1rem;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        font-size: 0.86rem;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
    }

    .btn-primary {
        background: #1e3a8a;
        color: #fff;
    }

    .btn-primary:hover {
        background: #254ac9;
    }

    .btn-secondary {
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #dbe3ef;
    }

    .btn-secondary:hover {
        background: #e2e8f0;
    }

    .btn-success {
        background: #059669;
        color: #fff;
    }

    .btn-success:hover {
        background: #047857;
    }

    .event-selector {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 1.2rem;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
    }

    .event-selector h3 {
        margin: 0 0 0.85rem 0;
        color: #1f2937;
    }

    .search-wrapper {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
    }

    .search-input {
        flex: 1;
        border: 1px solid #d1d5db;
        border-radius: 10px;
        padding: 0.72rem 0.9rem;
        font-size: 0.9rem;
    }

    .search-input:focus {
        outline: none;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.12);
    }

    .search-hint {
        color: #64748b;
        font-size: 0.8rem;
        margin-bottom: 0.75rem;
    }

    .events-list {
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        max-height: 360px;
        overflow-y: auto;
        background: #fff;
    }

    .event-item {
        padding: 0.9rem 1rem;
        border-bottom: 1px solid #eef2f7;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        transition: background 0.15s ease;
    }

    .event-item:last-child {
        border-bottom: none;
    }

    .event-item:hover {
        background: #f8fafc;
    }

    .event-item.selected {
        background: #e7efff;
        border-left: 3px solid #1e3a8a;
        padding-left: calc(1rem - 3px);
    }

    .event-info h4 {
        margin: 0;
        font-size: 1.05rem;
        color: #1f2937;
    }

    .event-info p {
        margin: 0.2rem 0;
        color: #64748b;
        font-size: 0.92rem;
    }

    .event-item-label {
        color: #1d4ed8;
        font-size: 0.9rem;
        font-weight: 600;
    }

    .event-date {
        color: #1e3a8a;
        font-weight: 700;
        white-space: nowrap;
    }

    .selected-event-summary {
        display: none;
        margin-top: 0.9rem;
        padding: 0.7rem 0.85rem;
        border: 1px solid #dbe3ef;
        border-radius: 9px;
        background: #f8fafc;
        color: #334155;
        font-size: 0.88rem;
    }

    .selected-event {
        display: none;
        margin-top: 0.85rem;
    }

    .event-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: #fff;
        border-radius: 14px;
        padding: 1.3rem 1.5rem;
        margin-bottom: 1rem;
    }

    .event-header h2 {
        margin: 0 0 0.55rem 0;
        font-size: 1.45rem;
    }

    .event-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.85rem 1.35rem;
        font-size: 0.92rem;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.3rem 0.58rem;
        border-radius: 999px;
        font-weight: 700;
        font-size: 0.78rem;
        background: rgba(255, 255, 255, 0.2);
    }

    .portal-link-card {
        position: sticky;
        top: 0.75rem;
        z-index: 8;
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 0.85rem;
        margin-bottom: 1rem;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
    }

    .portal-link-title {
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 0.5rem;
        font-size: 0.92rem;
    }

    .link-row {
        display: flex;
        gap: 0.6rem;
    }

    .link-input {
        flex: 1;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.62rem 0.78rem;
        font-size: 0.84rem;
        color: #1f2937;
        background: #f8fafc;
    }

    .cards-grid {
        display: grid;
        gap: 0.95rem;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    }

    .module-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
    }

    .module-card h3 {
        margin: 0;
        color: #1f2937;
        font-size: 1.05rem;
    }

    .module-card p {
        margin: 0.45rem 0 0 0;
        color: #64748b;
        font-size: 0.86rem;
        line-height: 1.45;
    }

    .module-options {
        margin-top: 0.85rem;
        display: grid;
        gap: 0.5rem;
    }

    .check-row {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        color: #334155;
        font-size: 0.88rem;
    }

    .card-actions {
        margin-top: 0.85rem;
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
    }

    .helper-note {
        margin-top: 0.5rem;
        color: #64748b;
        font-size: 0.8rem;
    }

    .status-note {
        margin-top: 0.75rem;
        font-size: 0.82rem;
        color: #0f766e;
        display: none;
    }

    .status-note.error {
        color: #b91c1c;
    }

    @media (max-width: 768px) {
        .organizacao-container {
            padding: 1rem;
        }

        .search-wrapper,
        .link-row {
            flex-direction: column;
        }
    }
</style>

<div class="organizacao-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">🧩 Organização de Eventos</h1>
            <div class="page-subtitle">Busque o evento, organize e configure o que o cliente verá no portal.</div>
        </div>
        <?php if ($reuniao): ?>
        <a href="index.php?page=eventos_organizacao" class="btn btn-secondary">+ Organizar outro evento</a>
        <?php else: ?>
        <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
        <?php endif; ?>
    </div>

    <?php if (!$reuniao): ?>
    <div class="event-selector">
        <h3>🔍 Buscar Evento</h3>
        <div class="search-wrapper">
            <input type="text" id="eventSearch" class="search-input" placeholder="Digite nome, cliente, local ou data...">
            <button type="button" class="btn btn-primary" onclick="searchEvents(null, true)">Buscar</button>
        </div>
        <div class="search-hint">Busca inteligente: digitou, filtrou. A lista também usa cache para reduzir atraso.</div>
        <div id="eventsList" class="events-list" style="display:none;"></div>
        <div id="loadingEvents" style="display:none; padding:1.3rem; text-align:center; color:#64748b;">Carregando eventos...</div>
        <div id="selectedEventSummary" class="selected-event-summary"></div>
        <div id="selectedEvent" class="selected-event">
            <button type="button" class="btn btn-success" onclick="organizarEvento()">Organizar este Evento</button>
        </div>
    </div>
    <?php else: ?>
    <div class="event-header">
        <h2><?= htmlspecialchars($nome_evento) ?></h2>
        <div class="event-meta">
            <div>📅 <?= htmlspecialchars($data_fmt) ?> • <?= htmlspecialchars($horario_fmt) ?></div>
            <div>📍 <?= htmlspecialchars($local_evento) ?></div>
            <div>👤 <?= htmlspecialchars($cliente_nome) ?></div>
            <div class="status-badge">
                <?= !empty($reuniao['status']) && $reuniao['status'] === 'concluida' ? 'Concluída' : 'Rascunho' ?>
            </div>
        </div>
    </div>

    <div class="portal-link-card">
        <div class="portal-link-title">🔗 Link do portal do cliente (fixo)</div>
        <div class="link-row">
            <input type="text" id="portalLinkInput" class="link-input" readonly value="<?= htmlspecialchars($portal_url) ?>">
            <button type="button" class="btn btn-secondary" onclick="copiarPortalLink()">📋 Copiar</button>
            <a href="<?= htmlspecialchars($portal_url) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">Abrir</a>
        </div>
    </div>

    <div class="cards-grid">
        <div class="module-card">
            <h3>📝 Reunião Final</h3>
            <p>Mantém as funções atuais com foco em Decoração e Observações Gerais.</p>
            <div class="module-options">
                <label class="check-row">
                    <input type="checkbox" id="cfgVisivelReuniao" <?= $visivel_reuniao ? 'checked' : '' ?>>
                    <span>Visível para o cliente</span>
                </label>
                <label class="check-row">
                    <input type="checkbox" id="cfgEditavelReuniao" <?= $editavel_reuniao ? 'checked' : '' ?>>
                    <span>Editável pelo cliente</span>
                </label>
            </div>
            <div class="card-actions">
                <a href="index.php?page=eventos_reuniao_final&id=<?= (int)$meeting_id ?>&scope=reuniao&origin=organizacao" class="btn btn-primary">Abrir Reunião Final</a>
            </div>
            <div class="helper-note">
                <?= $has_obs_link ? 'Há link público ativo para Observações Gerais.' : 'Sem link público ativo de Observações Gerais.' ?>
            </div>
        </div>

        <div class="module-card">
            <h3>🎧 DJ / Protocolos</h3>
            <p>Mantém os quadros, links de formulário e uploads exatamente como no DJ / Protocolos atual.</p>
            <div class="module-options">
                <label class="check-row">
                    <input type="checkbox" id="cfgVisivelDj" <?= $visivel_dj ? 'checked' : '' ?>>
                    <span>Visível para o cliente</span>
                </label>
                <label class="check-row">
                    <input type="checkbox" id="cfgEditavelDj" <?= $editavel_dj ? 'checked' : '' ?>>
                    <span>Editável pelo cliente</span>
                </label>
            </div>
            <div class="card-actions">
                <a href="index.php?page=eventos_reuniao_final&id=<?= (int)$meeting_id ?>&scope=dj&origin=organizacao" class="btn btn-primary">Abrir DJ / Protocolos</a>
            </div>
            <div class="helper-note">
                <?= $has_dj_link ? 'Há link público ativo para DJ.' : 'Sem link público ativo de DJ.' ?>
            </div>
        </div>

        <div class="module-card">
            <h3>📋 Lista de Convidados</h3>
            <p>Cliente preenche lista por nome/faixa etária e, quando aplicável, número da mesa. Uso interno com check-in por nome.</p>
            <div class="module-options">
                <label class="check-row">
                    <input type="checkbox" id="cfgVisivelConvidados" <?= $visivel_convidados ? 'checked' : '' ?>>
                    <span>Visível para o cliente</span>
                </label>
                <label class="check-row">
                    <input type="checkbox" id="cfgEditavelConvidados" <?= $editavel_convidados ? 'checked' : '' ?>>
                    <span>Editável pelo cliente</span>
                </label>
            </div>
            <div class="card-actions">
                <a href="index.php?page=eventos_lista_convidados&id=<?= (int)$meeting_id ?>" class="btn btn-primary">Abrir Lista / Check-in</a>
                <button type="button" class="btn btn-secondary" onclick="salvarConfigPortal()">Salvar</button>
            </div>
            <div class="helper-note">
                <?= (int)$convidados_resumo['total'] ?> convidados cadastrados • <?= (int)$convidados_resumo['checkin'] ?> check-ins
            </div>
        </div>
    </div>

    <div id="cfgStatus" class="status-note"></div>
    <?php endif; ?>
</div>

<script>
const meetingId = <?= $meeting_id > 0 ? (int)$meeting_id : 'null' ?>;
let selectedEventId = null;
let selectedEventData = null;
let searchDebounceTimer = null;
let searchAbortController = null;
let eventsCacheLoaded = false;
let eventsMasterCache = [];
const eventsQueryCache = new Map();
let portalConfigSaveInFlight = false;
let portalConfigSaveQueued = false;

async function parseJsonResponse(response) {
    const raw = await response.text();
    if (raw === '') {
        throw new Error('Resposta vazia do servidor.');
    }
    try {
        return JSON.parse(raw);
    } catch (err) {
        throw new Error('Resposta inválida do servidor.');
    }
}

function normalizeText(value) {
    return (value || '')
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

function localFilterEvents(query) {
    const q = normalizeText(query);
    if (!q) return eventsMasterCache.slice(0, 50);
    return eventsMasterCache.filter((ev) => {
        const hay = normalizeText([
            ev.nome,
            ev.cliente,
            ev.local,
            ev.data_formatada,
            ev.tipo
        ].join(' '));
        return hay.includes(q);
    }).slice(0, 80);
}

function renderEventsList(events) {
    const list = document.getElementById('eventsList');
    if (!list) return;

    if (!Array.isArray(events) || events.length === 0) {
        list.innerHTML = '<div style="padding:0.9rem; color:#64748b;">Nenhum evento encontrado</div>';
        list.style.display = 'block';
        return;
    }

    const selectedId = Number(selectedEventId || 0);
    list.innerHTML = events.map((ev) => {
        const isSelected = selectedId > 0 && Number(ev.id) === selectedId;
        const label = ev.label || `${ev.nome || 'Evento'} - ${ev.data_formatada || ''}`;
        return `
            <div class="event-item ${isSelected ? 'selected' : ''}" data-id="${ev.id}" onclick="selectEvent(this, ${ev.id})">
                <div class="event-info">
                    <h4>${ev.nome || 'Evento'}</h4>
                    <p>${ev.cliente || 'Cliente'} • ${ev.local || 'Local'} • ${ev.convidados || 0} convidados</p>
                    <div class="event-item-label">${label}</div>
                </div>
                <div class="event-date">${ev.data_formatada || '-'}</div>
            </div>
        `;
    }).join('');
    list.style.display = 'block';
}

function renderSelectedEventSummary(ev) {
    const summary = document.getElementById('selectedEventSummary');
    if (!summary) return;
    if (!ev) {
        summary.innerHTML = '';
        summary.style.display = 'none';
        return;
    }
    summary.innerHTML = `<strong>Selecionado:</strong> ${ev.nome || 'Evento'}<br><span>${ev.data_formatada || '-'} • ${ev.hora || '-'} • ${ev.local || 'Local não informado'} • ${ev.cliente || 'Cliente'}</span>`;
    summary.style.display = 'block';
}

async function fetchRemoteEvents(query = '', forceRefresh = false) {
    const key = `${query}::${forceRefresh ? '1' : '0'}`;
    if (!forceRefresh && eventsQueryCache.has(key)) {
        return eventsQueryCache.get(key);
    }

    if (searchAbortController) {
        searchAbortController.abort();
    }
    searchAbortController = new AbortController();

    const url = `index.php?page=eventos_me_proxy&action=list&search=${encodeURIComponent(query)}&days=120${forceRefresh ? '&refresh=1' : ''}`;
    const resp = await fetch(url, { signal: searchAbortController.signal });
    const data = await parseJsonResponse(resp);
    if (!data.ok) {
        throw new Error(data.error || 'Erro ao buscar eventos');
    }
    const events = Array.isArray(data.events) ? data.events : [];
    eventsQueryCache.set(key, events);

    if (!query) {
        eventsMasterCache = events;
        eventsCacheLoaded = true;
    } else if (eventsMasterCache.length > 0) {
        const ids = new Set(eventsMasterCache.map((e) => Number(e.id)));
        events.forEach((ev) => {
            if (!ids.has(Number(ev.id))) {
                eventsMasterCache.push(ev);
            }
        });
    }
    return events;
}

async function searchEvents(queryOverride = null, forceRemote = false) {
    const input = document.getElementById('eventSearch');
    const list = document.getElementById('eventsList');
    const loading = document.getElementById('loadingEvents');
    if (!input || !list || !loading) return;

    const query = (queryOverride !== null ? queryOverride : input.value || '').trim();
    loading.style.display = 'block';
    list.style.display = 'none';

    try {
        if (!eventsCacheLoaded) {
            const initial = await fetchRemoteEvents('', false);
            renderEventsList(initial);
        }

        const localResults = localFilterEvents(query);
        renderEventsList(localResults);
        loading.style.display = 'none';

        if ((query.length >= 2 && forceRemote) || (query.length >= 3 && localResults.length < 8) || (forceRemote && query.length === 0)) {
            const remote = await fetchRemoteEvents(query, forceRemote);
            renderEventsList(remote);
        }
    } catch (err) {
        if (err && err.name === 'AbortError') return;
        loading.style.display = 'none';
        list.innerHTML = `<div style="padding:0.9rem; color:#b91c1c;">Erro: ${err.message}</div>`;
        list.style.display = 'block';
    }
}

function selectEvent(el, id) {
    selectedEventId = Number(id);
    selectedEventData =
        (eventsMasterCache || []).find((ev) => Number(ev.id) === selectedEventId)
        || Array.from(eventsQueryCache.values()).flat().find((ev) => Number(ev.id) === selectedEventId)
        || null;

    document.querySelectorAll('.event-item').forEach((item) => item.classList.remove('selected'));
    if (el) el.classList.add('selected');
    renderSelectedEventSummary(selectedEventData);

    const selected = document.getElementById('selectedEvent');
    if (selected) selected.style.display = 'block';
}

async function organizarEvento() {
    if (!selectedEventId) {
        alert('Selecione um evento primeiro.');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'organizar_evento');
    formData.append('me_event_id', String(selectedEventId));

    try {
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp);
        if (!data.ok || !data.reuniao || !data.reuniao.id) {
            alert(data.error || 'Não foi possível organizar o evento.');
            return;
        }
        window.location.href = `index.php?page=eventos_organizacao&id=${data.reuniao.id}`;
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

function copiarPortalLink() {
    const input = document.getElementById('portalLinkInput');
    if (!input || !input.value) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        mostrarStatusConfig('Link copiado.');
    }).catch(() => {
        document.execCommand('copy');
        mostrarStatusConfig('Link copiado.');
    });
}

function mostrarStatusConfig(texto, isError = false) {
    const el = document.getElementById('cfgStatus');
    if (!el) return;
    el.textContent = texto;
    el.classList.toggle('error', !!isError);
    el.style.display = 'block';
}

async function salvarConfigPortal() {
    if (!meetingId) return;

    if (portalConfigSaveInFlight) {
        portalConfigSaveQueued = true;
        return;
    }

    const visivelReuniao = document.getElementById('cfgVisivelReuniao');
    const editavelReuniao = document.getElementById('cfgEditavelReuniao');
    const visivelDj = document.getElementById('cfgVisivelDj');
    const editavelDj = document.getElementById('cfgEditavelDj');
    const visivelConvidados = document.getElementById('cfgVisivelConvidados');
    const editavelConvidados = document.getElementById('cfgEditavelConvidados');

    if (editavelReuniao && editavelReuniao.checked && visivelReuniao) {
        visivelReuniao.checked = true;
    }
    if (editavelDj && editavelDj.checked && visivelDj) {
        visivelDj.checked = true;
    }
    if (editavelConvidados && editavelConvidados.checked && visivelConvidados) {
        visivelConvidados.checked = true;
    }

    const formData = new FormData();
    formData.append('action', 'salvar_portal_config');
    formData.append('meeting_id', String(meetingId));
    formData.append('visivel_reuniao', visivelReuniao && visivelReuniao.checked ? '1' : '0');
    formData.append('editavel_reuniao', editavelReuniao && editavelReuniao.checked ? '1' : '0');
    formData.append('visivel_dj', visivelDj && visivelDj.checked ? '1' : '0');
    formData.append('editavel_dj', editavelDj && editavelDj.checked ? '1' : '0');
    formData.append('visivel_convidados', visivelConvidados && visivelConvidados.checked ? '1' : '0');
    formData.append('editavel_convidados', editavelConvidados && editavelConvidados.checked ? '1' : '0');

    portalConfigSaveInFlight = true;
    mostrarStatusConfig('Salvando configurações...');
    try {
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp);
        if (!data.ok) {
            mostrarStatusConfig(data.error || 'Erro ao salvar configurações.', true);
            return;
        }
        mostrarStatusConfig('Configurações salvas automaticamente.');
    } catch (err) {
        mostrarStatusConfig('Erro: ' + err.message, true);
    } finally {
        portalConfigSaveInFlight = false;
        if (portalConfigSaveQueued) {
            portalConfigSaveQueued = false;
            salvarConfigPortal();
        }
    }
}

function bindPortalConfigAutoSave() {
    const ids = [
        'cfgVisivelReuniao',
        'cfgEditavelReuniao',
        'cfgVisivelDj',
        'cfgEditavelDj',
        'cfgVisivelConvidados',
        'cfgEditavelConvidados',
    ];
    ids.forEach((id) => {
        const input = document.getElementById(id);
        if (!input) return;
        input.addEventListener('change', () => {
            salvarConfigPortal();
        });
    });
}

function bindSearchEvents() {
    const searchInput = document.getElementById('eventSearch');
    if (!searchInput) return;

    searchInput.addEventListener('input', function() {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => searchEvents(searchInput.value, false), 260);
    });

    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchEvents(searchInput.value, true);
        }
    });

    searchEvents('', false);
}

document.addEventListener('DOMContentLoaded', () => {
    bindSearchEvents();
    bindPortalConfigAutoSave();
});
</script>

<?php endSidebar(); ?>
