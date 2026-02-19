<?php
/**
 * eventos_lista_convidados.php
 * Painel interno: filtros e check-in da lista de convidados.
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
    if ($meeting_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Reuniao invalida.']);
        exit;
    }

    try {
        switch ($action) {
            case 'toggle_checkin':
                $guest_id = (int)($_POST['guest_id'] ?? 0);
                $checked = ((string)($_POST['checked'] ?? '0') === '1');
                $result = eventos_convidados_toggle_checkin($pdo, $meeting_id, $guest_id, $checked, $user_id);
                if (empty($result['ok'])) {
                    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    exit;
                }
                echo json_encode([
                    'ok' => true,
                    'convidado' => $result['convidado'] ?? null,
                    'resumo' => eventos_convidados_resumo($pdo, $meeting_id),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;

            case 'listar_convidados':
                echo json_encode([
                    'ok' => true,
                    'convidados' => eventos_convidados_listar($pdo, $meeting_id),
                    'resumo' => eventos_convidados_resumo($pdo, $meeting_id),
                    'config' => eventos_convidados_get_config($pdo, $meeting_id),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
        }

        echo json_encode(['ok' => false, 'error' => 'Acao invalida.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        error_log('eventos_lista_convidados POST: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => 'Erro interno ao processar a solicitacao.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($meeting_id <= 0) {
    header('Location: index.php?page=eventos_organizacao');
    exit;
}

$reuniao = eventos_reuniao_get($pdo, $meeting_id);
if (!$reuniao) {
    includeSidebar('Lista de convidados');
    ?>
    <div style="padding:1.4rem;">
        <div style="background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:1rem;border-radius:10px;">
            Reuniao nao encontrada.
        </div>
    </div>
    <?php
    endSidebar();
    return;
}

$snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
if (!is_array($snapshot)) {
    $snapshot = [];
}

$config_convidados = eventos_convidados_get_config($pdo, $meeting_id);
$convidados = eventos_convidados_listar($pdo, $meeting_id);
$resumo = eventos_convidados_resumo($pdo, $meeting_id);
$convidados_json = json_encode($convidados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($convidados_json === false) {
    $convidados_json = '[]';
}

$portal_data = eventos_cliente_portal_get_or_create($pdo, $meeting_id, $user_id);
$portal_url = '';
if (!empty($portal_data['ok']) && !empty($portal_data['portal']['url'])) {
    $portal_url = (string)$portal_data['portal']['url'];
}

$evento_nome = trim((string)($snapshot['nome'] ?? 'Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
$horario_evento = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_evento .= ' - ' . $hora_fim;
}
$local_evento = trim((string)($snapshot['local'] ?? 'Local nao informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente'));
$usa_mesa = !empty($config_convidados['usa_mesa']);

includeSidebar('Lista de convidados');
?>

<style>
    .convidados-page {
        padding: 2rem;
        max-width: 1340px;
        margin: 0 auto;
        background: #f8fafc;
    }

    .top-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.8rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .title {
        margin: 0;
        font-size: 1.5rem;
        color: #1e3a8a;
    }

    .subtitle {
        margin-top: 0.35rem;
        color: #64748b;
        font-size: 0.9rem;
    }

    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid transparent;
        border-radius: 8px;
        cursor: pointer;
        text-decoration: none;
        font-size: 0.84rem;
        font-weight: 700;
        padding: 0.58rem 0.9rem;
        gap: 0.4rem;
    }

    .btn-primary {
        background: #1e3a8a;
        color: #fff;
    }

    .btn-secondary {
        background: #f1f5f9;
        color: #334155;
        border-color: #dbe3ef;
    }

    .event-box {
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #fff;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .event-box h2 {
        margin: 0 0 0.6rem 0;
        color: #1f2937;
    }

    .event-meta {
        display: grid;
        gap: 0.55rem;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        color: #334155;
        font-size: 0.9rem;
    }

    .stats-row {
        margin-top: 0.9rem;
        display: grid;
        gap: 0.7rem;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    }

    .stat {
        border: 1px solid #dbe3ef;
        border-radius: 9px;
        background: #f8fafc;
        padding: 0.7rem;
    }

    .stat-label {
        font-size: 0.78rem;
        color: #64748b;
    }

    .stat-value {
        margin-top: 0.25rem;
        font-size: 1.2rem;
        font-weight: 700;
        color: #0f172a;
    }

    .info-note {
        margin-bottom: 1rem;
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        color: #1e3a8a;
        border-radius: 10px;
        padding: 0.85rem 1rem;
        font-size: 0.86rem;
    }

    .filters {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 0.9rem;
        margin-bottom: 1rem;
        display: grid;
        gap: 0.7rem;
        grid-template-columns: minmax(220px, 2fr) minmax(170px, 1fr) minmax(170px, 1fr);
    }

    .filters label {
        display: block;
        font-size: 0.79rem;
        margin-bottom: 0.28rem;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .filters input,
    .filters select {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.56rem 0.65rem;
        font-size: 0.86rem;
        color: #1f2937;
    }

    .status-note {
        margin-bottom: 1rem;
        font-size: 0.85rem;
        color: #0f766e;
        display: none;
    }

    .status-note.error {
        color: #b91c1c;
    }

    .groups-wrap {
        display: grid;
        gap: 0.85rem;
    }

    .mesa-group {
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #fff;
        overflow: hidden;
    }

    .mesa-header {
        padding: 0.72rem 0.85rem;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-size: 0.9rem;
        font-weight: 700;
        color: #334155;
    }

    .guest-row {
        display: grid;
        gap: 0.65rem;
        align-items: center;
        padding: 0.62rem 0.85rem;
        border-top: 1px solid #eef2f7;
    }

    .guest-row.with-mesa {
        grid-template-columns: 42px minmax(200px, 2fr) minmax(130px, 1.1fr) minmax(95px, 0.8fr) minmax(120px, 1fr);
    }

    .guest-row.without-mesa {
        grid-template-columns: 42px minmax(220px, 2fr) minmax(140px, 1.1fr) minmax(120px, 1fr);
    }

    .guest-name {
        font-weight: 700;
        color: #1f2937;
        font-size: 0.9rem;
    }

    .guest-meta {
        color: #64748b;
        font-size: 0.84rem;
    }

    .check-cell {
        display: flex;
        justify-content: center;
    }

    .check-cell input {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }

    .check-time {
        color: #0f766e;
        font-size: 0.81rem;
        font-weight: 600;
    }

    .empty-state {
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        background: #fff;
        color: #64748b;
        padding: 1rem;
        text-align: center;
        font-style: italic;
    }

    @media (max-width: 900px) {
        .convidados-page {
            padding: 1rem;
        }

        .filters {
            grid-template-columns: 1fr;
        }

        .guest-row.with-mesa,
        .guest-row.without-mesa {
            grid-template-columns: 42px 1fr;
        }
    }
</style>

<div class="convidados-page">
    <div class="top-header">
        <div>
            <h1 class="title">📋 Lista de convidados</h1>
            <div class="subtitle">Filtro por mesa, busca por nome e check-in em tempo real na recepcao.</div>
        </div>
        <div style="display:flex;gap:0.55rem;flex-wrap:wrap;">
            <a href="index.php?page=eventos_organizacao&id=<?= (int)$meeting_id ?>" class="btn btn-secondary">← Voltar organizacao</a>
            <?php if ($portal_url !== ''): ?>
            <a href="<?= htmlspecialchars($portal_url) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">Abrir portal cliente</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="event-box">
        <h2><?= htmlspecialchars($evento_nome) ?></h2>
        <div class="event-meta">
            <div><strong>📅 Data:</strong> <?= htmlspecialchars($data_evento_fmt) ?></div>
            <div><strong>⏰ Horario:</strong> <?= htmlspecialchars($horario_evento) ?></div>
            <div><strong>📍 Local:</strong> <?= htmlspecialchars($local_evento) ?></div>
            <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($cliente_nome) ?></div>
            <div><strong>Tipo da lista:</strong> <?= $usa_mesa ? '15 anos / Casamento' : 'Festa infantil' ?></div>
        </div>

        <div class="stats-row">
            <div class="stat">
                <div class="stat-label">Total de convidados</div>
                <div id="sumTotal" class="stat-value"><?= (int)$resumo['total'] ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Check-ins</div>
                <div id="sumCheckin" class="stat-value"><?= (int)$resumo['checkin'] ?></div>
            </div>
            <div class="stat">
                <div class="stat-label">Pendentes</div>
                <div id="sumPendentes" class="stat-value"><?= (int)$resumo['pendentes'] ?></div>
            </div>
        </div>
    </div>

    <div class="info-note">
        <strong>Informacao importante para o time:</strong> para o mapeamento de mesa funcionar o cliente deve trazer a plaquinha com numero de mesa.
        Nao levamos os convidados ate a mesa; o numero e informado no momento da entrada.
    </div>

    <div class="filters">
        <div>
            <label for="filtroBusca">Buscar por nome</label>
            <input type="text" id="filtroBusca" placeholder="Digite o nome do convidado...">
        </div>
        <div>
            <label for="filtroStatus">Status</label>
            <select id="filtroStatus">
                <option value="todos">Todos</option>
                <option value="presentes">Somente check-in</option>
                <option value="pendentes">Somente pendentes</option>
            </select>
        </div>
        <div>
            <label for="filtroMesa">Mesa</label>
            <select id="filtroMesa">
                <option value="todas">Todas</option>
            </select>
        </div>
    </div>

    <div id="statusNote" class="status-note"></div>
    <div id="groupsWrap" class="groups-wrap"></div>
</div>

<script>
const meetingId = <?= (int)$meeting_id ?>;
const usaMesa = <?= $usa_mesa ? 'true' : 'false' ?>;
const convidadosState = <?= $convidados_json ?>;

function escapeHtml(value) {
    return (value ?? '').toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function normalizeText(value) {
    return (value || '')
        .toString()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
}

function parseJsonSafe(text) {
    if (!text) return null;
    try {
        return JSON.parse(text);
    } catch (_) {
        return null;
    }
}

function mesaKey(value) {
    const mesa = (value || '').toString().trim();
    return mesa !== '' ? mesa : '__SEM_MESA__';
}

function compareMesa(a, b) {
    if (a === b) return 0;
    if (a === '__SEM_MESA__') return 1;
    if (b === '__SEM_MESA__') return -1;

    const aNum = /^\d+$/.test(a);
    const bNum = /^\d+$/.test(b);
    if (aNum && bNum) {
        return Number(a) - Number(b);
    }
    if (aNum) return -1;
    if (bNum) return 1;
    return a.localeCompare(b, 'pt-BR', { sensitivity: 'base' });
}

function formatCheckinDate(raw) {
    if (!raw) return '';
    const iso = raw.includes('T') ? raw : raw.replace(' ', 'T');
    const dt = new Date(iso);
    if (Number.isNaN(dt.getTime())) return '';
    return dt.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function updateResumo(resumo) {
    if (!resumo) return;
    const total = document.getElementById('sumTotal');
    const checkin = document.getElementById('sumCheckin');
    const pendentes = document.getElementById('sumPendentes');
    if (total) total.textContent = String(Number(resumo.total || 0));
    if (checkin) checkin.textContent = String(Number(resumo.checkin || 0));
    if (pendentes) pendentes.textContent = String(Number(resumo.pendentes || 0));
}

function showStatus(message, isError = false) {
    const el = document.getElementById('statusNote');
    if (!el) return;
    el.textContent = message;
    el.classList.toggle('error', !!isError);
    el.style.display = message ? 'block' : 'none';
}

function preencherFiltroMesas() {
    const select = document.getElementById('filtroMesa');
    if (!select) return;
    const current = select.value || 'todas';
    const mesas = new Set();
    convidadosState.forEach((g) => {
        const mesa = (g.numero_mesa || '').toString().trim();
        if (mesa !== '') mesas.add(mesa);
    });

    const ordenadas = Array.from(mesas).sort(compareMesa);
    const opts = ['<option value="todas">Todas</option>'];
    ordenadas.forEach((m) => opts.push(`<option value="${escapeHtml(m)}">Mesa ${escapeHtml(m)}</option>`));
    opts.push('<option value="__SEM_MESA__">Sem mesa</option>');
    select.innerHTML = opts.join('');

    if (Array.from(select.options).some((o) => o.value === current)) {
        select.value = current;
    }
}

function renderGrupos() {
    const wrap = document.getElementById('groupsWrap');
    const busca = normalizeText(document.getElementById('filtroBusca')?.value || '');
    const status = (document.getElementById('filtroStatus')?.value || 'todos').toString();
    const filtroMesa = (document.getElementById('filtroMesa')?.value || 'todas').toString();
    if (!wrap) return;

    let filtrados = convidadosState.filter((g) => {
        if (busca && !normalizeText(g.nome || '').includes(busca)) {
            return false;
        }
        if (status === 'presentes' && !g.is_checked_in) return false;
        if (status === 'pendentes' && g.is_checked_in) return false;
        if (filtroMesa !== 'todas') {
            const key = mesaKey(g.numero_mesa || '');
            if (key !== filtroMesa) return false;
        }
        return true;
    });

    filtrados.sort((a, b) => {
        const mesaCmp = compareMesa(mesaKey(a.numero_mesa), mesaKey(b.numero_mesa));
        if (mesaCmp !== 0) return mesaCmp;
        return (a.nome || '').localeCompare((b.nome || ''), 'pt-BR', { sensitivity: 'base' });
    });

    if (filtrados.length === 0) {
        wrap.innerHTML = '<div class="empty-state">Nenhum convidado encontrado para os filtros atuais.</div>';
        return;
    }

    const grupos = new Map();
    filtrados.forEach((g) => {
        const key = mesaKey(g.numero_mesa || '');
        if (!grupos.has(key)) {
            grupos.set(key, []);
        }
        grupos.get(key).push(g);
    });

    const keys = Array.from(grupos.keys()).sort(compareMesa);
    wrap.innerHTML = keys.map((k) => {
        const titulo = k === '__SEM_MESA__' ? 'Sem mesa definida' : `Mesa ${escapeHtml(k)}`;
        const rows = grupos.get(k).map((g) => {
            const faixa = (g.faixa_etaria || '').toString().trim();
            const mesa = (g.numero_mesa || '').toString().trim();
            const checkinDate = formatCheckinDate(g.checkin_at);
            return `
                <div class="guest-row ${usaMesa ? 'with-mesa' : 'without-mesa'}">
                    <div class="check-cell">
                        <input type="checkbox" data-guest-id="${Number(g.id || 0)}" ${g.is_checked_in ? 'checked' : ''}>
                    </div>
                    <div>
                        <div class="guest-name">${escapeHtml(g.nome || 'Convidado')}</div>
                        <div class="guest-meta">${g.created_by_type === 'cliente' ? 'Origem: Cliente' : 'Origem: Interno'}</div>
                    </div>
                    <div class="guest-meta">${faixa !== '' ? escapeHtml(faixa) : '-'}</div>
                    ${usaMesa ? `<div class="guest-meta">${mesa !== '' ? escapeHtml(mesa) : '-'}</div>` : ''}
                    <div class="check-time">${checkinDate !== '' ? `Check-in: ${escapeHtml(checkinDate)}` : 'Sem check-in'}</div>
                </div>
            `;
        }).join('');

        return `
            <section class="mesa-group">
                <div class="mesa-header">${titulo} (${grupos.get(k).length})</div>
                ${rows}
            </section>
        `;
    }).join('');
}

async function toggleCheckin(guestId, checked, checkboxEl) {
    const formData = new FormData();
    formData.append('action', 'toggle_checkin');
    formData.append('meeting_id', String(meetingId));
    formData.append('guest_id', String(guestId));
    formData.append('checked', checked ? '1' : '0');

    if (checkboxEl) checkboxEl.disabled = true;
    showStatus('Salvando check-in...');

    try {
        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const raw = await response.text();
        const data = parseJsonSafe(raw);
        if (!data || !data.ok) {
            throw new Error((data && data.error) ? data.error : 'Falha ao salvar check-in');
        }

        const convidado = data.convidado || null;
        if (convidado && Number(convidado.id) > 0) {
            const idx = convidadosState.findIndex((g) => Number(g.id) === Number(convidado.id));
            if (idx >= 0) {
                convidadosState[idx] = convidado;
            }
        }
        updateResumo(data.resumo || null);
        renderGrupos();
        showStatus('Check-in atualizado.');
    } catch (err) {
        if (checkboxEl) checkboxEl.checked = !checked;
        showStatus('Erro: ' + (err?.message || 'nao foi possivel salvar.'), true);
    } finally {
        if (checkboxEl) checkboxEl.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    preencherFiltroMesas();
    renderGrupos();

    const busca = document.getElementById('filtroBusca');
    const status = document.getElementById('filtroStatus');
    const mesa = document.getElementById('filtroMesa');

    if (busca) {
        busca.addEventListener('input', renderGrupos);
    }
    if (status) {
        status.addEventListener('change', renderGrupos);
    }
    if (mesa) {
        mesa.addEventListener('change', renderGrupos);
    }

    const groupsWrap = document.getElementById('groupsWrap');
    if (groupsWrap) {
        groupsWrap.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) return;
            if (target.type !== 'checkbox') return;
            const guestId = Number(target.getAttribute('data-guest-id') || 0);
            if (guestId <= 0) return;
            toggleCheckin(guestId, target.checked, target);
        });
    }
});
</script>

<?php endSidebar(); ?>
