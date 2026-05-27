<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

if (empty($_SESSION['logado'])) {
    header('Location: login.php');
    exit;
}

includeSidebar('Demandas');
?>

<style>
.demandas-page {
    background: #f6f7fb;
    color: #172033;
    min-height: calc(100vh - 64px);
    padding: 1.25rem;
}
.demandas-shell {
    display: grid;
    grid-template-columns: minmax(320px, 420px) minmax(0, 1fr);
    gap: 1rem;
    height: calc(100vh - 108px);
}
.demandas-panel,
.demandas-detail {
    background: #ffffff;
    border: 1px solid #dfe4ee;
    border-radius: 8px;
    min-height: 0;
}
.demandas-panel {
    display: flex;
    flex-direction: column;
}
.demandas-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}
.demandas-header h1 {
    font-size: 1.55rem;
    margin: 0;
    color: #101827;
}
.demandas-header p {
    margin: 0.15rem 0 0;
    color: #6a7280;
    font-size: 0.9rem;
}
.demandas-tabs {
    display: flex;
    gap: 0.35rem;
    padding: 0.75rem;
    border-bottom: 1px solid #e7ebf2;
    overflow-x: auto;
}
.demandas-tab {
    border: 1px solid transparent;
    background: transparent;
    color: #526073;
    border-radius: 6px;
    padding: 0.48rem 0.68rem;
    cursor: pointer;
    white-space: nowrap;
    font-weight: 600;
    font-size: 0.84rem;
}
.demandas-tab.active {
    border-color: #b8c5d8;
    background: #edf3fb;
    color: #153e75;
}
.demandas-admin-filter {
    display: none;
    padding: 0 0.75rem 0.75rem;
    border-bottom: 1px solid #e7ebf2;
}
.demandas-list {
    overflow: auto;
    padding: 0.75rem;
}
.demanda-card {
    border: 1px solid #e0e6ef;
    border-left: 4px solid #6b7280;
    background: #fff;
    border-radius: 8px;
    padding: 0.85rem;
    margin-bottom: 0.65rem;
    cursor: pointer;
}
.demanda-card:hover,
.demanda-card.active {
    border-color: #93afd6;
    box-shadow: 0 6px 18px rgba(36, 64, 96, 0.1);
}
.demanda-card.prioridade-baixa { border-left-color: #64748b; }
.demanda-card.prioridade-normal { border-left-color: #2563eb; }
.demanda-card.prioridade-alta { border-left-color: #d97706; }
.demanda-card.prioridade-urgente { border-left-color: #dc2626; }
.demanda-card-title {
    font-weight: 750;
    color: #111827;
    margin-bottom: 0.35rem;
}
.demanda-card-desc {
    color: #5f6978;
    font-size: 0.86rem;
    line-height: 1.35;
    max-height: 2.7em;
    overflow: hidden;
}
.demanda-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: 0.65rem;
}
.pill {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border-radius: 999px;
    padding: 0.2rem 0.5rem;
    font-size: 0.74rem;
    font-weight: 650;
    background: #eef2f7;
    color: #475569;
}
.pill.status-aberta { background: #e0f2fe; color: #075985; }
.pill.status-em_andamento { background: #dcfce7; color: #166534; }
.pill.status-aguardando { background: #fef3c7; color: #92400e; }
.pill.status-resolvida { background: #ede9fe; color: #5b21b6; }
.pill.status-encerrada { background: #e5e7eb; color: #374151; }
.pill.status-cancelada { background: #fee2e2; color: #991b1b; }
.demandas-detail {
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.detail-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: #6b7280;
    text-align: center;
    padding: 2rem;
}
.detail-head {
    padding: 1rem;
    border-bottom: 1px solid #e7ebf2;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 1rem;
}
.detail-head h2 {
    margin: 0 0 0.4rem;
    font-size: 1.2rem;
    color: #101827;
}
.detail-actions {
    display: flex;
    gap: 0.45rem;
    align-items: flex-start;
}
.event-box {
    margin-top: 0.8rem;
    display: grid;
    grid-template-columns: repeat(4, minmax(120px, 1fr));
    gap: 0.5rem;
    padding: 0.7rem;
    border: 1px solid #dce6f2;
    background: #f8fbff;
    border-radius: 8px;
}
.event-box strong {
    display: block;
    color: #526073;
    font-size: 0.72rem;
    margin-bottom: 0.12rem;
}
.event-box span {
    color: #172033;
    font-size: 0.86rem;
}
.chat-area {
    padding: 1rem;
    overflow: auto;
    flex: 1;
    background: #fbfcfe;
}
.message {
    max-width: 780px;
    background: #ffffff;
    border: 1px solid #e0e6ef;
    border-radius: 8px;
    padding: 0.75rem;
    margin-bottom: 0.7rem;
}
.message strong {
    color: #172033;
}
.message time {
    color: #7b8493;
    font-size: 0.75rem;
    margin-left: 0.4rem;
}
.message p {
    white-space: pre-wrap;
    margin: 0.4rem 0 0;
    color: #384454;
    line-height: 1.45;
}
.attachments {
    padding: 0.75rem 1rem;
    border-top: 1px solid #e7ebf2;
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
}
.attachment-link {
    border: 1px solid #d7dee9;
    background: #fff;
    color: #1f4f82;
    text-decoration: none;
    border-radius: 6px;
    padding: 0.35rem 0.55rem;
    font-size: 0.82rem;
}
.composer {
    border-top: 1px solid #e7ebf2;
    padding: 0.75rem;
    background: #ffffff;
}
.composer textarea {
    width: 100%;
    min-height: 72px;
    resize: vertical;
}
.composer-row {
    margin-top: 0.55rem;
    display: flex;
    gap: 0.5rem;
    justify-content: space-between;
    align-items: center;
}
.btn {
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1f2937;
    border-radius: 6px;
    padding: 0.58rem 0.8rem;
    cursor: pointer;
    font-weight: 700;
    font-size: 0.86rem;
}
.btn:hover {
    background: #f8fafc;
}
.btn-primary {
    border-color: #1d4ed8;
    background: #1d4ed8;
    color: #fff;
}
.btn-primary:hover {
    background: #1e40af;
}
.btn-icon {
    width: 38px;
    height: 36px;
    padding: 0;
}
.field,
.field textarea,
.field input,
.field select {
    width: 100%;
}
.field label {
    display: block;
    font-size: 0.78rem;
    font-weight: 750;
    color: #4b5563;
    margin-bottom: 0.28rem;
}
.field input,
.field select,
.field textarea {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 0.6rem 0.65rem;
    font: inherit;
    color: #172033;
}
.grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}
.modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 3000;
    background: rgba(15, 23, 42, 0.48);
    padding: 2rem;
    overflow: auto;
}
.modal.open {
    display: block;
}
.modal-card {
    background: #ffffff;
    border-radius: 8px;
    max-width: 760px;
    margin: 0 auto;
    border: 1px solid #d9e1ec;
    box-shadow: 0 24px 80px rgba(15, 23, 42, 0.25);
}
.modal-head,
.modal-foot {
    padding: 1rem;
    border-bottom: 1px solid #e7ebf2;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
}
.modal-foot {
    border-top: 1px solid #e7ebf2;
    border-bottom: 0;
    justify-content: flex-end;
}
.modal-body {
    padding: 1rem;
    display: grid;
    gap: 0.85rem;
}
.event-search-results,
.history-list {
    display: grid;
    gap: 0.5rem;
}
.event-result,
.history-item {
    border: 1px solid #e0e6ef;
    border-radius: 6px;
    padding: 0.65rem;
    background: #fff;
}
.event-result {
    cursor: pointer;
}
.event-result:hover {
    border-color: #8aa7d2;
}
.hidden {
    display: none !important;
}
.empty-list,
.loading {
    padding: 2rem 1rem;
    text-align: center;
    color: #6b7280;
}
@media (max-width: 980px) {
    .demandas-shell {
        grid-template-columns: 1fr;
        height: auto;
    }
    .demandas-panel,
    .demandas-detail {
        min-height: 420px;
    }
    .event-box,
    .grid-2 {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="demandas-page">
    <div class="demandas-header">
        <div>
            <h1>Demandas</h1>
            <p>Solicitações internas por conversa, responsável, prazo e evento vinculado.</p>
        </div>
        <button class="btn btn-primary" type="button" onclick="openCreateModal()">Nova demanda</button>
    </div>

    <div class="demandas-shell">
        <section class="demandas-panel">
            <div class="demandas-tabs" id="tabs">
                <button class="demandas-tab active" data-tab="todas">Todas</button>
                <button class="demandas-tab" data-tab="minhas">Abertas para mim</button>
                <button class="demandas-tab" data-tab="criadas">Criadas por mim</button>
                <button class="demandas-tab" data-tab="citacoes">Citação</button>
                <button class="demandas-tab" data-tab="encerradas">Encerradas</button>
                <button class="demandas-tab hidden" data-tab="demais" id="tab-demais">Demais usuários</button>
            </div>
            <div class="demandas-admin-filter" id="admin-filter">
                <div class="field">
                    <label for="filter-user">Usuário</label>
                    <select id="filter-user" onchange="loadDemandas()"></select>
                </div>
            </div>
            <div class="demandas-list" id="demandas-list">
                <div class="loading">Carregando demandas...</div>
            </div>
        </section>

        <section class="demandas-detail" id="detail">
            <div class="detail-empty">Selecione uma demanda para abrir a conversa.</div>
        </section>
    </div>
</div>

<div class="modal" id="create-modal">
    <div class="modal-card">
        <form id="create-form">
            <div class="modal-head">
                <strong>Nova demanda</strong>
                <button class="btn btn-icon" type="button" onclick="closeModal('create-modal')">×</button>
            </div>
            <div class="modal-body">
                <div class="field">
                    <label for="create-titulo">Título da demanda</label>
                    <input id="create-titulo" name="titulo" required maxlength="180">
                </div>
                <div class="field">
                    <label for="create-descricao">Descrição</label>
                    <textarea id="create-descricao" name="descricao" rows="4"></textarea>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label for="create-responsavel-tipo">Responsável ou setor</label>
                        <select id="create-responsavel-tipo" name="responsavel_tipo" onchange="toggleResponsavelFields('create')">
                            <option value="usuario">Usuário</option>
                            <option value="setor">Setor</option>
                        </select>
                    </div>
                    <div class="field" id="create-responsavel-user-wrap">
                        <label for="create-responsavel-id">Usuário responsável</label>
                        <select id="create-responsavel-id" name="responsavel_id"></select>
                    </div>
                    <div class="field hidden" id="create-responsavel-setor-wrap">
                        <label for="create-responsavel-setor">Setor responsável</label>
                        <select id="create-responsavel-setor" name="responsavel_setor"></select>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label for="create-prazo">Prazo</label>
                        <input id="create-prazo" name="prazo" type="date" required>
                    </div>
                    <div class="field">
                        <label for="create-prioridade">Prioridade</label>
                        <select id="create-prioridade" name="prioridade">
                            <option value="baixa">Baixa</option>
                            <option value="normal" selected>Normal</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="has-evento">Esta demanda é referente a algum evento?</label>
                    <select id="has-evento" onchange="toggleEventSearch()">
                        <option value="nao">Não</option>
                        <option value="sim">Sim</option>
                    </select>
                </div>
                <div id="event-search-wrap" class="hidden">
                    <div class="field">
                        <label for="event-search">Localizar evento</label>
                        <input id="event-search" placeholder="Busque por nome, local ou ID" oninput="searchEvents(this.value)">
                    </div>
                    <div id="event-results" class="event-search-results"></div>
                    <input type="hidden" name="evento_tipo" id="evento-tipo">
                    <input type="hidden" name="evento_id" id="evento-id">
                    <input type="hidden" name="evento_data" id="evento-data">
                    <input type="hidden" name="evento_local" id="evento-local">
                    <input type="hidden" name="evento_nome" id="evento-nome">
                    <input type="hidden" name="evento_whatsapp" id="evento-whatsapp">
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" type="button" onclick="closeModal('create-modal')">Cancelar</button>
                <button class="btn btn-primary" type="submit">Criar demanda</button>
            </div>
        </form>
    </div>
</div>

<div class="modal" id="history-modal">
    <div class="modal-card">
        <div class="modal-head">
            <strong>Histórico da demanda</strong>
            <button class="btn btn-icon" type="button" onclick="closeModal('history-modal')">×</button>
        </div>
        <div class="modal-body">
            <div id="history-list" class="history-list"></div>
        </div>
    </div>
</div>

<div class="modal" id="gallery-modal">
    <div class="modal-card">
        <div class="modal-head">
            <strong>Galeria Smile</strong>
            <button class="btn btn-icon" type="button" onclick="closeModal('gallery-modal')">×</button>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="gallery-search">Buscar imagem</label>
                <input id="gallery-search" oninput="searchGallery(this.value)" placeholder="Nome, categoria ou tag">
            </div>
            <div id="gallery-results" class="event-search-results"></div>
        </div>
    </div>
</div>

<script>
const API = 'demandas_internas_api.php';
const state = {
    tab: 'todas',
    selectedId: null,
    demandas: [],
    usuarios: [],
    setores: [],
    isAdmin: false
};

const statusLabels = {
    aberta: 'Aberta',
    em_andamento: 'Em andamento',
    aguardando: 'Aguardando',
    resolvida: 'Resolvida',
    encerrada: 'Encerrada',
    cancelada: 'Cancelada'
};
const prioridadeLabels = {
    baixa: 'Baixa',
    normal: 'Normal',
    alta: 'Alta',
    urgente: 'Urgente'
};

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
}

function formatDate(value) {
    if (!value) return '-';
    const [year, month, day] = String(value).slice(0, 10).split('-');
    return year && month && day ? `${day}/${month}/${year}` : value;
}

function formatDateTime(value) {
    if (!value) return '';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('pt-BR');
}

function openModal(id) {
    document.getElementById(id).classList.add('open');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

function fillSelects() {
    const userOptions = ['<option value="">Selecione...</option>'].concat(
        state.usuarios.map(u => `<option value="${u.id}">${escapeHtml(userLabel(u))}</option>`)
    ).join('');
    const setorOptions = ['<option value="">Selecione...</option>'].concat(
        state.setores.map(s => `<option value="${escapeHtml(s)}">${escapeHtml(s)}</option>`)
    ).join('');

    ['create-responsavel-id'].forEach(id => document.getElementById(id).innerHTML = userOptions);
    ['create-responsavel-setor'].forEach(id => document.getElementById(id).innerHTML = setorOptions);

    document.getElementById('filter-user').innerHTML = userOptions;
    document.getElementById('tab-demais').classList.toggle('hidden', !state.isAdmin);
}

function userLabel(user) {
    return user?.label_usuario || user?.login || user?.nome || '';
}

function toggleResponsavelFields(prefix) {
    const tipo = document.getElementById(`${prefix}-responsavel-tipo`).value;
    document.getElementById(`${prefix}-responsavel-user-wrap`).classList.toggle('hidden', tipo !== 'usuario');
    document.getElementById(`${prefix}-responsavel-setor-wrap`).classList.toggle('hidden', tipo !== 'setor');
}

function toggleEventSearch() {
    const enabled = document.getElementById('has-evento').value === 'sim';
    document.getElementById('event-search-wrap').classList.toggle('hidden', !enabled);
    if (!enabled) {
        ['tipo', 'id', 'data', 'local', 'nome', 'whatsapp'].forEach(key => {
            document.getElementById(`evento-${key}`).value = '';
        });
        document.getElementById('event-results').innerHTML = '';
    } else {
        searchEvents('', true);
    }
}

async function fetchJson(url, options = {}) {
    const response = await fetch(url, options);
    const json = await response.json();
    if (!json.success) throw new Error(json.error || 'Erro na requisição.');
    return json;
}

async function bootstrap() {
    const data = await fetchJson(`${API}?action=bootstrap`);
    state.usuarios = data.usuarios || [];
    state.setores = data.setores || [];
    state.isAdmin = !!data.is_admin;
    fillSelects();
    await loadDemandas();
}

async function loadDemandas() {
    const list = document.getElementById('demandas-list');
    list.innerHTML = '<div class="loading">Carregando demandas...</div>';
    const params = new URLSearchParams({ action: 'list', aba: state.tab });
    if (state.tab === 'demais') {
        const userId = document.getElementById('filter-user').value;
        if (userId) params.set('usuario_id', userId);
    }
    try {
        const data = await fetchJson(`${API}?${params.toString()}`);
        state.demandas = data.data || [];
        renderList();
    } catch (error) {
        list.innerHTML = `<div class="empty-list">${escapeHtml(error.message)}</div>`;
    }
}

function renderList() {
    const list = document.getElementById('demandas-list');
    if (!state.demandas.length) {
        list.innerHTML = '<div class="empty-list">Nenhuma demanda nesta aba.</div>';
        return;
    }
    list.innerHTML = state.demandas.map(d => {
        const responsavel = d.responsavel_tipo === 'setor' ? d.responsavel_setor : (d.responsavel_login || d.responsavel_nome);
        return `
            <article class="demanda-card prioridade-${escapeHtml(d.prioridade)} ${Number(d.id) === Number(state.selectedId) ? 'active' : ''}" onclick="openDetail(${Number(d.id)})">
                <div class="demanda-card-title">${escapeHtml(d.titulo)}</div>
                <div class="demanda-card-desc">${escapeHtml(d.descricao || 'Sem descrição.')}</div>
                <div class="demanda-meta">
                    <span class="pill status-${escapeHtml(d.status)}">${statusLabels[d.status] || d.status}</span>
                    <span class="pill">${prioridadeLabels[d.prioridade] || d.prioridade}</span>
                    <span class="pill">Prazo ${formatDate(d.prazo)}</span>
                    <span class="pill">${escapeHtml(responsavel || 'Sem responsável')}</span>
                    ${Number(d.mensagens_total) ? `<span class="pill">${Number(d.mensagens_total)} msg</span>` : ''}
                    ${Number(d.anexos_total) ? `<span class="pill">${Number(d.anexos_total)} anexo</span>` : ''}
                </div>
            </article>
        `;
    }).join('');
}

async function openDetail(id) {
    state.selectedId = id;
    renderList();
    const detail = document.getElementById('detail');
    detail.innerHTML = '<div class="detail-empty">Carregando conversa...</div>';
    try {
        const data = await fetchJson(`${API}?action=detail&id=${id}`);
        renderDetail(data.demanda, data.mensagens || [], data.anexos || []);
    } catch (error) {
        detail.innerHTML = `<div class="detail-empty">${escapeHtml(error.message)}</div>`;
    }
}

function renderAdminControls(d) {
    if (!state.isAdmin) return '';
    const userOptions = state.usuarios.map(u => `<option value="${u.id}" ${Number(d.responsavel_id) === Number(u.id) ? 'selected' : ''}>${escapeHtml(userLabel(u))}</option>`).join('');
    const setorOptions = state.setores.map(s => `<option value="${escapeHtml(s)}" ${d.responsavel_setor === s ? 'selected' : ''}>${escapeHtml(s)}</option>`).join('');
    return `
        <form class="grid-2" onsubmit="saveAdmin(event, ${Number(d.id)})" style="margin-top:.8rem;">
            <div class="field">
                <label>Status</label>
                <select name="status">${Object.entries(statusLabels).map(([k,v]) => `<option value="${k}" ${d.status === k ? 'selected' : ''}>${v}</option>`).join('')}</select>
            </div>
            <div class="field">
                <label>Prioridade</label>
                <select name="prioridade">${Object.entries(prioridadeLabels).map(([k,v]) => `<option value="${k}" ${d.prioridade === k ? 'selected' : ''}>${v}</option>`).join('')}</select>
            </div>
            <div class="field">
                <label>Prazo</label>
                <input type="date" name="prazo" value="${escapeHtml(String(d.prazo || '').slice(0, 10))}">
            </div>
            <div class="field">
                <label>Tipo de responsável</label>
                <select name="responsavel_tipo" onchange="toggleInlineResponsavel(this)">
                    <option value="usuario" ${d.responsavel_tipo === 'usuario' ? 'selected' : ''}>Usuário</option>
                    <option value="setor" ${d.responsavel_tipo === 'setor' ? 'selected' : ''}>Setor</option>
                </select>
            </div>
            <div class="field inline-user ${d.responsavel_tipo === 'setor' ? 'hidden' : ''}">
                <label>Responsável</label>
                <select name="responsavel_id">${userOptions}</select>
            </div>
            <div class="field inline-setor ${d.responsavel_tipo !== 'setor' ? 'hidden' : ''}">
                <label>Setor</label>
                <select name="responsavel_setor">${setorOptions}</select>
            </div>
            <button class="btn btn-primary" type="submit">Salvar alterações</button>
        </form>
    `;
}

function renderDetail(d, mensagens, anexos) {
    const responsavel = d.responsavel_tipo === 'setor' ? d.responsavel_setor : (d.responsavel_login || d.responsavel_nome);
    document.getElementById('detail').innerHTML = `
        <div class="detail-head">
            <div>
                <h2>${escapeHtml(d.titulo)}</h2>
                <div class="demanda-meta">
                    <span class="pill status-${escapeHtml(d.status)}">${statusLabels[d.status] || d.status}</span>
                    <span class="pill">${prioridadeLabels[d.prioridade] || d.prioridade}</span>
                    <span class="pill">Prazo ${formatDate(d.prazo)}</span>
                    <span class="pill">Responsável: ${escapeHtml(responsavel || '-')}</span>
                    <span class="pill">Criada por ${escapeHtml(d.criador_login || d.criador_nome || '-')}</span>
                </div>
                ${d.descricao ? `<p style="margin:.8rem 0 0;color:#4b5563;white-space:pre-wrap;">${escapeHtml(d.descricao)}</p>` : ''}
                ${d.evento_id ? `
                    <div class="event-box">
                        <div><strong>Data do evento</strong><span>${formatDate(d.evento_data)}</span></div>
                        <div><strong>Local/unidade</strong><span>${escapeHtml(d.evento_local || '-')}</span></div>
                        <div><strong>Cliente ou evento</strong><span>${escapeHtml(d.evento_nome || '-')}</span></div>
                        <div><strong>WhatsApp</strong><span>${escapeHtml(d.evento_whatsapp || '-')}</span></div>
                    </div>
                ` : ''}
                ${renderAdminControls(d)}
            </div>
            <div class="detail-actions">
                <button class="btn btn-icon" title="Histórico" onclick="openHistory(${Number(d.id)})">⏱</button>
            </div>
        </div>
        <div class="chat-area" id="chat-area">
            ${mensagens.length ? mensagens.map(m => `
                <div class="message">
                    <strong>${escapeHtml(m.autor_nome || 'Usuário')}</strong>
                    <time>${formatDateTime(m.criado_em)}</time>
                    <p>${escapeHtml(m.mensagem)}</p>
                </div>
            `).join('') : '<div class="empty-list">Ainda não há mensagens nesta demanda.</div>'}
        </div>
        <div class="attachments">
            ${anexos.length ? anexos.map(a => `
                <a class="attachment-link" href="${escapeHtml(a.url || '#')}" target="_blank" rel="noopener">${escapeHtml(a.nome_original)}</a>
            `).join('') : '<span class="pill">Sem anexos</span>'}
        </div>
        <form class="composer" onsubmit="sendMessage(event, ${Number(d.id)})">
            <textarea name="mensagem" placeholder="Escreva uma mensagem. Use @nome ou @setor para citar." required></textarea>
            <div class="composer-row">
                <label class="btn">
                    Anexar arquivo
                    <input class="hidden" type="file" onchange="uploadAttachment(event, ${Number(d.id)})">
                </label>
                <div>
                    <button class="btn" type="button" onclick="openGallery(${Number(d.id)})">Galeria Smile</button>
                    <button class="btn" type="button" onclick="forwardDemand(${Number(d.id)})">Encaminhar</button>
                    <button class="btn btn-primary" type="submit">Enviar</button>
                </div>
            </div>
        </form>
    `;
    const chat = document.getElementById('chat-area');
    chat.scrollTop = chat.scrollHeight;
}

function toggleInlineResponsavel(select) {
    const form = select.closest('form');
    form.querySelector('.inline-user').classList.toggle('hidden', select.value !== 'usuario');
    form.querySelector('.inline-setor').classList.toggle('hidden', select.value !== 'setor');
}

async function saveAdmin(event, id) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    data.append('action', 'update');
    data.append('demanda_id', id);
    await fetchJson(API, { method: 'POST', body: data });
    await loadDemandas();
    await openDetail(id);
}

async function sendMessage(event, id) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    data.append('action', 'message');
    data.append('demanda_id', id);
    await fetchJson(API, { method: 'POST', body: data });
    form.reset();
    await openDetail(id);
}

async function uploadAttachment(event, id) {
    const file = event.currentTarget.files[0];
    if (!file) return;
    const data = new FormData();
    data.append('action', 'attach');
    data.append('demanda_id', id);
    data.append('arquivo', file);
    try {
        await fetchJson(API, { method: 'POST', body: data });
        await openDetail(id);
    } catch (error) {
        alert(error.message);
    } finally {
        event.currentTarget.value = '';
    }
}

let galleryDemandId = null;
let galleryTimer = null;
function openGallery(id) {
    galleryDemandId = id;
    document.getElementById('gallery-search').value = '';
    document.getElementById('gallery-results').innerHTML = '<div class="empty-list">Busque uma imagem para anexar.</div>';
    openModal('gallery-modal');
}

function searchGallery(query) {
    clearTimeout(galleryTimer);
    if (query.trim().length < 2) {
        document.getElementById('gallery-results').innerHTML = '<div class="empty-list">Digite ao menos 2 caracteres.</div>';
        return;
    }
    galleryTimer = setTimeout(async () => {
        try {
            const data = await fetchJson(`${API}?action=gallery_search&q=${encodeURIComponent(query)}`);
            document.getElementById('gallery-results').innerHTML = (data.data || []).map(item => `
                <div class="event-result" onclick="attachGalleryImage(${Number(item.id)})">
                    <strong>${escapeHtml(item.nome || 'Imagem')}</strong>
                    <div style="color:#6b7280;font-size:.82rem;">${escapeHtml(item.categoria || 'Sem categoria')}</div>
                </div>
            `).join('') || '<div class="empty-list">Nenhuma imagem encontrada.</div>';
        } catch (error) {
            document.getElementById('gallery-results').innerHTML = `<div class="empty-list">${escapeHtml(error.message)}</div>`;
        }
    }, 250);
}

async function attachGalleryImage(galleryId) {
    if (!galleryDemandId) return;
    const data = new FormData();
    data.append('action', 'attach_gallery');
    data.append('demanda_id', galleryDemandId);
    data.append('gallery_id', galleryId);
    await fetchJson(API, { method: 'POST', body: data });
    closeModal('gallery-modal');
    await openDetail(galleryDemandId);
}

async function forwardDemand(id) {
    const tipo = prompt('Encaminhar para "usuario" ou "setor"?', 'usuario');
    if (!tipo || !['usuario', 'setor'].includes(tipo)) return;
    const data = new FormData();
    data.append('action', 'forward');
    data.append('demanda_id', id);
    data.append('responsavel_tipo', tipo);
    if (tipo === 'usuario') {
        const nome = prompt('Digite parte do login do novo responsável:');
        const user = state.usuarios.find(u => String(userLabel(u)).toLowerCase().includes(String(nome || '').toLowerCase()));
        if (!user) return alert('Usuário não encontrado.');
        data.append('responsavel_id', user.id);
    } else {
        const setor = prompt('Digite o setor:');
        if (!setor) return;
        data.append('responsavel_setor', setor);
    }
    await fetchJson(API, { method: 'POST', body: data });
    await loadDemandas();
    await openDetail(id);
}

async function openHistory(id) {
    const data = await fetchJson(`${API}?action=history&id=${id}`);
    document.getElementById('history-list').innerHTML = (data.data || []).length
        ? data.data.map(item => `
            <div class="history-item">
                <strong>${escapeHtml(item.resumo)}</strong>
                <div style="color:#6b7280;font-size:.82rem;margin-top:.25rem;">${escapeHtml(item.usuario_nome || 'Sistema')} • ${formatDateTime(item.criado_em)}</div>
            </div>
        `).join('')
        : '<div class="empty-list">Sem histórico registrado.</div>';
    openModal('history-modal');
}

function openCreateModal() {
    document.getElementById('create-form').reset();
    toggleResponsavelFields('create');
    toggleEventSearch();
    openModal('create-modal');
}

document.getElementById('tabs').addEventListener('click', event => {
    const button = event.target.closest('.demandas-tab');
    if (!button) return;
    state.tab = button.dataset.tab;
    document.querySelectorAll('.demandas-tab').forEach(tab => tab.classList.toggle('active', tab === button));
    document.getElementById('admin-filter').style.display = state.tab === 'demais' ? 'block' : 'none';
    loadDemandas();
});

document.getElementById('create-form').addEventListener('submit', async event => {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    data.append('action', 'create');
    try {
        const result = await fetchJson(API, { method: 'POST', body: data });
        closeModal('create-modal');
        await loadDemandas();
        await openDetail(result.id);
    } catch (error) {
        alert(error.message);
    }
});

let eventSearchTimer = null;
function searchEvents(query, force = false) {
    clearTimeout(eventSearchTimer);
    if (!force && query.trim().length < 2) {
        document.getElementById('event-results').innerHTML = '<div class="empty-list">Digite ao menos 2 caracteres ou selecione um evento recente.</div>';
        return;
    }
    document.getElementById('event-results').innerHTML = '<div class="loading">Buscando eventos...</div>';
    eventSearchTimer = setTimeout(async () => {
        try {
            const data = await fetchJson(`${API}?action=event_search&q=${encodeURIComponent(query)}`);
            document.getElementById('event-results').innerHTML = (data.data || []).map(item => `
                <div class="event-result" onclick='selectEvent(${JSON.stringify(item).replace(/'/g, '&#039;')})'>
                    <strong>${escapeHtml(item.nome || 'Evento')}</strong>
                    <div style="color:#6b7280;font-size:.82rem;">${formatDate(item.data_evento)} • ${escapeHtml(item.local || 'Local não informado')} • ${escapeHtml(item.whatsapp || 'Sem WhatsApp')}</div>
                </div>
            `).join('') || '<div class="empty-list">Nenhum evento encontrado.</div>';
        } catch (error) {
            document.getElementById('event-results').innerHTML = `<div class="empty-list">${escapeHtml(error.message)}</div>`;
        }
    }, 250);
}

function selectEvent(item) {
    document.getElementById('evento-tipo').value = item.tipo || '';
    document.getElementById('evento-id').value = item.id || '';
    document.getElementById('evento-data').value = item.data_evento ? String(item.data_evento).slice(0, 10) : '';
    document.getElementById('evento-local').value = item.local || '';
    document.getElementById('evento-nome').value = item.nome || '';
    document.getElementById('evento-whatsapp').value = item.whatsapp || '';
    document.getElementById('event-results').innerHTML = `
        <div class="event-result">
            <strong>Selecionado: ${escapeHtml(item.nome || 'Evento')}</strong>
            <div style="color:#6b7280;font-size:.82rem;">${formatDate(item.data_evento)} • ${escapeHtml(item.local || 'Local não informado')}</div>
        </div>
    `;
}

bootstrap().catch(error => {
    document.getElementById('demandas-list').innerHTML = `<div class="empty-list">${escapeHtml(error.message)}</div>`;
});
</script>

<?php endSidebar(); ?>
