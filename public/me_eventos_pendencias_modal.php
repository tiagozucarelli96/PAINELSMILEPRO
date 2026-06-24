<?php
$me_pendencias_enabled = !empty($_SESSION['logado']) && (!empty($_SESSION['perm_comercial']) || !empty($_SESSION['perm_superadmin']));
if (empty($me_pendencias_enabled)) {
    return;
}
?>
<style>
    .me-pending-modal {
        position: fixed;
        inset: 0;
        z-index: 3100;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(15, 23, 42, 0.62);
    }
    .me-pending-modal.open {
        display: flex;
    }
    .me-pending-dialog {
        width: min(720px, 100%);
        max-height: min(820px, calc(100vh - 40px));
        overflow: auto;
        background: #fff;
        border: 1px solid #dbeafe;
        border-radius: 8px;
        box-shadow: 0 24px 70px rgba(15, 23, 42, 0.32);
    }
    .me-pending-head {
        padding: 18px 20px;
        background: #1e3a8a;
        color: #fff;
        display: flex;
        justify-content: space-between;
        gap: 16px;
    }
    .me-pending-head h2 {
        margin: 0;
        font-size: 1.05rem;
        line-height: 1.25;
    }
    .me-pending-head p {
        margin: 5px 0 0;
        color: #dbeafe;
        font-size: 0.9rem;
    }
    .me-pending-close {
        border: 0;
        background: rgba(255, 255, 255, 0.18);
        color: #fff;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 20px;
        line-height: 1;
    }
    .me-pending-body {
        padding: 18px 20px 20px;
    }
    .me-pending-summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
        margin-bottom: 16px;
        padding: 12px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #f8fafc;
    }
    .me-pending-summary div {
        min-width: 0;
    }
    .me-pending-summary span {
        display: block;
        color: #64748b;
        font-size: 0.76rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .me-pending-summary strong {
        display: block;
        margin-top: 3px;
        color: #0f172a;
        font-size: 0.92rem;
        word-break: break-word;
    }
    .me-pending-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }
    .me-pending-field {
        display: grid;
        gap: 5px;
        margin-bottom: 12px;
    }
    .me-pending-field.full {
        grid-column: 1 / -1;
    }
    .me-pending-field label {
        color: #334155;
        font-size: 0.84rem;
        font-weight: 800;
    }
    .me-pending-field input,
    .me-pending-field select,
    .me-pending-field textarea {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 10px 11px;
        color: #0f172a;
        font-size: 0.92rem;
        box-sizing: border-box;
    }
    .me-pending-field textarea {
        min-height: 76px;
        resize: vertical;
    }
    .me-pending-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding-top: 8px;
    }
    .me-pending-link {
        color: #1e40af;
        font-weight: 800;
        text-decoration: none;
        font-size: 0.9rem;
    }
    .me-pending-buttons {
        display: flex;
        gap: 8px;
    }
    .me-pending-btn {
        border: 0;
        border-radius: 8px;
        padding: 10px 14px;
        font-weight: 800;
        cursor: pointer;
        background: #e2e8f0;
        color: #0f172a;
    }
    .me-pending-btn.primary {
        background: #1e3a8a;
        color: #fff;
    }
    .me-pending-btn[disabled] {
        opacity: 0.55;
        cursor: not-allowed;
    }
    .me-pending-feedback {
        min-height: 20px;
        color: #991b1b;
        font-size: 0.88rem;
        font-weight: 700;
    }
    @media (max-width: 720px) {
        .me-pending-summary,
        .me-pending-grid {
            grid-template-columns: 1fr;
        }
        .me-pending-actions {
            align-items: stretch;
            flex-direction: column;
        }
        .me-pending-buttons,
        .me-pending-btn {
            width: 100%;
        }
    }
</style>
<div class="me-pending-modal" id="mePendingModal" aria-hidden="true">
    <div class="me-pending-dialog" role="dialog" aria-modal="true" aria-labelledby="mePendingTitle">
        <div class="me-pending-head">
            <div>
                <h2 id="mePendingTitle">Evento criado direto na ME</h2>
                <p>Complete os dados para o painel tratar este evento como criado pelo fluxo interno.</p>
            </div>
            <button type="button" class="me-pending-close" id="mePendingClose" aria-label="Fechar">&times;</button>
        </div>
        <form class="me-pending-body" id="mePendingForm">
            <input type="hidden" name="pendencia_id" id="mePendingId">
            <div class="me-pending-summary">
                <div><span>Evento</span><strong id="mePendingEvento">-</strong></div>
                <div><span>Data</span><strong id="mePendingData">-</strong></div>
                <div><span>Cliente</span><strong id="mePendingCliente">-</strong></div>
                <div><span>Contato</span><strong id="mePendingContato">-</strong></div>
            </div>
            <div class="me-pending-grid">
                <div class="me-pending-field">
                    <label for="mePendingTipo">Tipo real do evento</label>
                    <select name="tipo_evento_real" id="mePendingTipo" required></select>
                </div>
                <div class="me-pending-field">
                    <label for="mePendingPacote">Pacote do evento</label>
                    <select name="pacote_evento_id" id="mePendingPacote" required></select>
                </div>
                <div class="me-pending-field">
                    <label for="mePendingRazao">Razão social</label>
                    <input type="text" name="pj_razao_social" id="mePendingRazao" maxlength="255" required>
                </div>
                <div class="me-pending-field">
                    <label for="mePendingFantasia">Nome fantasia</label>
                    <input type="text" name="pj_nome_fantasia" id="mePendingFantasia" maxlength="255">
                </div>
                <div class="me-pending-field">
                    <label for="mePendingCnpj">CNPJ</label>
                    <input type="text" name="pj_cnpj" id="mePendingCnpj" inputmode="numeric" maxlength="18" required>
                </div>
                <div class="me-pending-field">
                    <label for="mePendingResponsavel">Responsável pela PJ</label>
                    <input type="text" name="pj_responsavel_nome" id="mePendingResponsavel" maxlength="255" required>
                </div>
                <div class="me-pending-field">
                    <label for="mePendingCpf">CPF do responsável</label>
                    <input type="text" name="pj_responsavel_cpf" id="mePendingCpf" inputmode="numeric" maxlength="14">
                </div>
                <div class="me-pending-field full">
                    <label for="mePendingObs">Observações internas</label>
                    <textarea name="observacoes" id="mePendingObs"></textarea>
                </div>
            </div>
            <div class="me-pending-feedback" id="mePendingFeedback"></div>
            <div class="me-pending-actions">
                <a href="#" class="me-pending-link" id="mePendingOrgLink" target="_blank" rel="noopener">Abrir organização do evento</a>
                <div class="me-pending-buttons">
                    <button type="button" class="me-pending-btn" id="mePendingLater">Depois</button>
                    <button type="submit" class="me-pending-btn primary" id="mePendingSubmit">Confirmar dados</button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    const modal = document.getElementById('mePendingModal');
    const form = document.getElementById('mePendingForm');
    if (!modal || !form) return;

    const state = { current: null, loading: false, dismissedId: 0 };
    const els = {
        id: document.getElementById('mePendingId'),
        evento: document.getElementById('mePendingEvento'),
        data: document.getElementById('mePendingData'),
        cliente: document.getElementById('mePendingCliente'),
        contato: document.getElementById('mePendingContato'),
        tipo: document.getElementById('mePendingTipo'),
        pacote: document.getElementById('mePendingPacote'),
        razao: document.getElementById('mePendingRazao'),
        fantasia: document.getElementById('mePendingFantasia'),
        cnpj: document.getElementById('mePendingCnpj'),
        responsavel: document.getElementById('mePendingResponsavel'),
        cpf: document.getElementById('mePendingCpf'),
        obs: document.getElementById('mePendingObs'),
        feedback: document.getElementById('mePendingFeedback'),
        submit: document.getElementById('mePendingSubmit'),
        link: document.getElementById('mePendingOrgLink')
    };

    function text(value, fallback) {
        const normalized = String(value || '').trim();
        return normalized || fallback || '-';
    }

    function formatDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return '-';
        const parts = raw.split('-');
        if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
        return raw;
    }

    function openModal() {
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
    }

    function closeModal() {
        if (state.current && state.current.id) {
            state.dismissedId = Number(state.current.id);
        }
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    }

    function fillPackages(packages, selectedId) {
        els.pacote.innerHTML = '<option value="">Selecione o pacote</option>';
        packages.forEach((pkg) => {
            const opt = document.createElement('option');
            opt.value = String(pkg.id || '');
            opt.textContent = String(pkg.nome || 'Pacote');
            if (Number(selectedId || 0) === Number(pkg.id || 0)) {
                opt.selected = true;
            }
            els.pacote.appendChild(opt);
        });
    }

    function fillTipos(tipos, selectedValue) {
        els.tipo.innerHTML = '<option value="">Selecione o tipo real</option>';
        Object.entries(tipos || {}).forEach(([value, label]) => {
            const opt = document.createElement('option');
            opt.value = String(value || '');
            opt.textContent = String(label || value || 'Tipo');
            if (String(selectedValue || '') === String(value || '')) {
                opt.selected = true;
            }
            els.tipo.appendChild(opt);
        });
    }

    function fillPending(pendencia, packages, tipos) {
        state.current = pendencia;
        els.id.value = String(pendencia.id || '');
        els.evento.textContent = text(pendencia.evento_nome, 'Evento sem nome');
        els.data.textContent = formatDate(pendencia.data_evento);
        els.cliente.textContent = text(pendencia.cliente_nome, 'Cliente PJ');
        els.contato.textContent = [pendencia.cliente_email, pendencia.cliente_telefone].map((v) => String(v || '').trim()).filter(Boolean).join(' | ') || '-';
        fillTipos(tipos || {}, pendencia.tipo_evento_real || '');
        fillPackages(packages || [], pendencia.pacote_evento_id);
        els.razao.value = text(pendencia.cliente_nome, '');
        els.fantasia.value = '';
        els.cnpj.value = '';
        els.responsavel.value = '';
        els.cpf.value = '';
        els.obs.value = '';
        els.feedback.textContent = '';
        const meetingId = Number(pendencia.meeting_id || 0);
        els.link.style.display = meetingId > 0 ? 'inline-flex' : 'none';
        els.link.href = meetingId > 0 ? `index.php?page=eventos_organizacao&id=${meetingId}` : '#';
        openModal();
    }

    async function loadNext() {
        if (state.loading) return;
        state.loading = true;
        try {
            const resp = await fetch('index.php?page=me_eventos_pendencias_api&action=next', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            if (!resp.ok) return;
            const data = await resp.json();
            const pendencia = data && data.pendencia ? data.pendencia : null;
            if (!pendencia || Number(pendencia.id || 0) <= 0) return;
            if (Number(pendencia.id || 0) === state.dismissedId) return;
            fillPending(
                pendencia,
                Array.isArray(data.pacotes) ? data.pacotes : [],
                data.tipos_evento_real || {}
            );
        } catch (e) {
            console.warn('Falha ao buscar pendência ME', e);
        } finally {
            state.loading = false;
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        els.feedback.textContent = '';
        els.submit.disabled = true;
        try {
            const fd = new FormData(form);
            fd.append('action', 'complete');
            const resp = await fetch('index.php?page=me_eventos_pendencias_api&action=complete', {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            });
            const data = await resp.json().catch(() => ({}));
            if (!resp.ok || !data.ok) {
                els.feedback.textContent = data.error || 'Não foi possível confirmar os dados.';
                return;
            }
            state.dismissedId = 0;
            state.current = null;
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
            window.setTimeout(loadNext, 800);
        } catch (e) {
            els.feedback.textContent = 'Erro ao salvar. Tente novamente.';
        } finally {
            els.submit.disabled = false;
        }
    });

    document.getElementById('mePendingClose')?.addEventListener('click', closeModal);
    document.getElementById('mePendingLater')?.addEventListener('click', closeModal);
    window.setTimeout(loadNext, 1200);
    window.setInterval(loadNext, 120000);
})();
</script>
