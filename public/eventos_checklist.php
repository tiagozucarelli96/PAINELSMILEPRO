<?php
/**
 * eventos_checklist.php
 * Checklist operacional para realização de evento.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

$can_eventos = !empty($_SESSION['perm_eventos']);
$can_realizar_evento = !empty($_SESSION['perm_eventos_realizar']);
$is_superadmin = !empty($_SESSION['perm_superadmin']);
$somente_realizar = (!$is_superadmin && !$can_eventos && $can_realizar_evento);

if (!$is_superadmin && !$can_eventos && !$can_realizar_evento) {
    header('Location: index.php?page=dashboard');
    exit;
}

function eventos_checklist_itens_padrao(): array
{
    return [
        ['id' => 'estrutura', 'label' => 'Estrutura principal pronta no local', 'done' => false],
        ['id' => 'som_luz', 'label' => 'Som e iluminação testados', 'done' => false],
        ['id' => 'recepcao', 'label' => 'Equipe de recepção posicionada', 'done' => false],
        ['id' => 'fornecedores', 'label' => 'Fornecedores confirmados e alinhados', 'done' => false],
        ['id' => 'cerimonial', 'label' => 'Cerimonial/protocolos revisados', 'done' => false],
        ['id' => 'convidados', 'label' => 'Check-in pronto para operação', 'done' => false],
    ];
}

function eventos_checklist_normalizar_items($items): array
{
    if (!is_array($items)) {
        return [];
    }

    $normalized = [];
    $seen = [];
    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }

        $label = trim((string)($item['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        if (strlen($label) > 180) {
            $label = substr($label, 0, 180);
        }

        $raw_id = trim((string)($item['id'] ?? ''));
        if ($raw_id === '') {
            $raw_id = 'item_' . ($idx + 1);
        }
        $safe_id = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $raw_id);
        if ($safe_id === null || $safe_id === '') {
            $safe_id = 'item_' . ($idx + 1);
        }

        if (isset($seen[$safe_id])) {
            $safe_id .= '_' . ($idx + 1);
        }
        $seen[$safe_id] = true;

        $normalized[] = [
            'id' => $safe_id,
            'label' => $label,
            'done' => !empty($item['done']),
        ];

        if (count($normalized) >= 120) {
            break;
        }
    }

    return $normalized;
}

$user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$meeting_id = (int)($_GET['id'] ?? $_POST['meeting_id'] ?? 0);
$origin = trim((string)($_GET['origin'] ?? $_POST['origin'] ?? 'realizar'));
if ($origin !== 'organizacao' && $origin !== 'realizar') {
    $origin = 'realizar';
}
if ($somente_realizar) {
    $origin = 'realizar';
}
$readonly_mode = $somente_realizar
    || ($origin === 'realizar')
    || ((string)($_GET['readonly'] ?? $_POST['readonly'] ?? '0') === '1');

$return_page = $origin === 'organizacao' ? 'eventos_organizacao' : 'eventos_realizar';
$return_href = 'index.php?page=' . $return_page;
if ($meeting_id > 0 && $origin === 'organizacao') {
    $return_href .= '&id=' . $meeting_id;
}
if ($meeting_id > 0 && $origin === 'realizar') {
    $return_href .= '&id=' . $meeting_id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'salvar_checklist') {
    header('Content-Type: application/json; charset=utf-8');

    if ($readonly_mode) {
        echo json_encode(['ok' => false, 'error' => 'Checklist em modo somente leitura na realização do evento.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($meeting_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Evento inválido.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $reuniao_post = eventos_reuniao_get($pdo, $meeting_id);
    if (!$reuniao_post) {
        echo json_encode(['ok' => false, 'error' => 'Evento não encontrado.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $items_raw_json = (string)($_POST['items_json'] ?? '[]');
    $decoded = json_decode($items_raw_json, true);
    $items_normalized = eventos_checklist_normalizar_items($decoded);

    if (empty($items_normalized)) {
        echo json_encode(['ok' => false, 'error' => 'Adicione pelo menos um item no checklist.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $payload = [
        'items' => $items_normalized,
        'updated_at' => date(DATE_ATOM),
        'updated_by' => $user_id,
    ];
    $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload_json === false) {
        echo json_encode(['ok' => false, 'error' => 'Erro ao preparar checklist.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $save = eventos_reuniao_salvar_secao(
        $pdo,
        $meeting_id,
        'checklist_evento',
        $payload_json,
        $user_id,
        'Atualização checklist de realização'
    );

    if (empty($save['ok'])) {
        echo json_encode(['ok' => false, 'error' => (string)($save['error'] ?? 'Erro ao salvar checklist.')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $total = count($items_normalized);
    $concluidos = 0;
    foreach ($items_normalized as $item) {
        if (!empty($item['done'])) {
            $concluidos++;
        }
    }

    echo json_encode([
        'ok' => true,
        'items' => $items_normalized,
        'resumo' => [
            'total' => $total,
            'concluidos' => $concluidos,
            'percentual' => $total > 0 ? (int)round(($concluidos / $total) * 100) : 0,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($meeting_id <= 0) {
    header('Location: index.php?page=eventos_realizar');
    exit;
}

$reuniao = eventos_reuniao_get($pdo, $meeting_id);
if (!$reuniao) {
    header('Location: index.php?page=eventos_realizar');
    exit;
}

$snapshot = json_decode((string)($reuniao['me_event_snapshot'] ?? '{}'), true);
if (!is_array($snapshot)) {
    $snapshot = [];
}

$nome_evento = trim((string)($snapshot['nome'] ?? 'Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : '-';
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? ''));
$horario_evento = $hora_inicio !== '' ? $hora_inicio : '-';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_evento .= ' - ' . $hora_fim;
}
$local_evento = trim((string)($snapshot['local'] ?? 'Local não informado'));
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? 'Cliente não informado'));

$secao = eventos_reuniao_get_secao($pdo, $meeting_id, 'checklist_evento');
$items = [];
if ($secao) {
    $payload = json_decode((string)($secao['content_html'] ?? ''), true);
    if (is_array($payload)) {
        $items = eventos_checklist_normalizar_items($payload['items'] ?? []);
    }
}
if (empty($items)) {
    $items = eventos_checklist_itens_padrao();
}

$total = count($items);
$concluidos = 0;
foreach ($items as $item) {
    if (!empty($item['done'])) {
        $concluidos++;
    }
}
$percentual = $total > 0 ? (int)round(($concluidos / $total) * 100) : 0;

$items_json = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($items_json === false) {
    $items_json = '[]';
}

includeSidebar('Checklist do evento');
?>

<style>
    .checklist-page {
        padding: 2rem;
        max-width: 1100px;
        margin: 0 auto;
        background: #f8fafc;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.85rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .title {
        margin: 0;
        color: #1e3a8a;
        font-size: 1.55rem;
    }

    .subtitle {
        margin-top: 0.4rem;
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
        font-size: 0.85rem;
        font-weight: 700;
        padding: 0.58rem 0.9rem;
        gap: 0.35rem;
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

    .btn-danger {
        background: #fff1f2;
        color: #b91c1c;
        border-color: #fecdd3;
    }

    .event-box {
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #fff;
        padding: 1rem;
        margin-bottom: 0.9rem;
    }

    .event-box h2 {
        margin: 0 0 0.65rem 0;
        color: #1f2937;
    }

    .event-meta {
        display: grid;
        gap: 0.55rem;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        color: #334155;
        font-size: 0.9rem;
    }

    .progress-box {
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #fff;
        padding: 0.9rem;
        margin-bottom: 0.9rem;
    }

    .progress-header {
        display: flex;
        justify-content: space-between;
        gap: 0.8rem;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 0.45rem;
    }

    .progress-title {
        margin: 0;
        font-size: 0.94rem;
        color: #334155;
        font-weight: 700;
    }

    .progress-value {
        font-size: 0.86rem;
        color: #0f172a;
        font-weight: 700;
    }

    .progress-bar {
        height: 10px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        width: 0;
        background: linear-gradient(90deg, #1e3a8a, #2563eb);
        transition: width 0.2s ease;
    }

    .toolbar {
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
        margin-bottom: 0.9rem;
    }

    .checklist-card {
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #fff;
        padding: 0.9rem;
    }

    .checklist-items {
        display: grid;
        gap: 0.6rem;
    }

    .item-row {
        display: grid;
        grid-template-columns: 36px 1fr auto;
        gap: 0.55rem;
        align-items: center;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.58rem;
        background: #f8fafc;
    }

    .item-row input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
    }

    .item-label {
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.5rem 0.6rem;
        font-size: 0.9rem;
        color: #1f2937;
        background: #fff;
    }

    .item-row.done .item-label {
        color: #64748b;
        text-decoration: line-through;
        background: #f1f5f9;
    }

    .status-note {
        margin-top: 0.75rem;
        font-size: 0.84rem;
        color: #0f766e;
        display: none;
    }

    .status-note.error {
        color: #b91c1c;
    }

    .modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 60;
        padding: 1rem;
    }

    .modal-backdrop.open {
        display: flex;
    }

    .modal-card {
        width: min(760px, 100%);
        max-height: 90vh;
        overflow: hidden;
        background: #fff;
        border-radius: 12px;
        border: 1px solid #dbe3ef;
        box-shadow: 0 18px 38px rgba(15, 23, 42, 0.22);
        padding: 1rem;
    }

    .modal-header {
        display: flex;
        justify-content: space-between;
        gap: 0.8rem;
        align-items: flex-start;
        margin-bottom: 0.7rem;
    }

    .modal-title {
        margin: 0;
        color: #1f2937;
        font-size: 1.05rem;
    }

    .modal-description {
        margin-top: 0.3rem;
        font-size: 0.84rem;
        color: #64748b;
    }

    .modal-close {
        border: 1px solid #dbe3ef;
        background: #fff;
        color: #334155;
        border-radius: 8px;
        width: 34px;
        height: 34px;
        font-size: 1.25rem;
        line-height: 1;
        cursor: pointer;
    }

    body.modal-open {
        overflow: hidden;
    }

    @media (max-width: 768px) {
        .checklist-page {
            padding: 1rem;
        }

        .item-row {
            grid-template-columns: 32px 1fr;
        }

        .item-row .btn-danger {
            grid-column: 2 / 3;
            justify-self: start;
        }
    }
</style>

<div class="checklist-page">
    <div class="page-header">
        <div>
            <h1 class="title">✅ Checklist de evento</h1>
            <div class="subtitle">
                <?= $readonly_mode
                    ? 'Modo realização: somente visualização e download.'
                    : 'Checklist operacional para execução no dia do evento.' ?>
            </div>
        </div>
        <a href="<?= htmlspecialchars($return_href) ?>" class="btn btn-secondary">← Voltar</a>
    </div>

    <section class="event-box">
        <h2><?= htmlspecialchars($nome_evento) ?></h2>
        <div class="event-meta">
            <div><strong>📅 Data:</strong> <?= htmlspecialchars($data_evento_fmt) ?></div>
            <div><strong>⏰ Horário:</strong> <?= htmlspecialchars($horario_evento) ?></div>
            <div><strong>📍 Local:</strong> <?= htmlspecialchars($local_evento) ?></div>
            <div><strong>👤 Cliente:</strong> <?= htmlspecialchars($cliente_nome) ?></div>
        </div>
    </section>

    <section class="progress-box">
        <div class="progress-header">
            <h3 class="progress-title">Progresso do checklist</h3>
            <div class="progress-value" id="progressValue"><?= (int)$concluidos ?>/<?= (int)$total ?> (<?= (int)$percentual ?>%)</div>
        </div>
        <div class="progress-bar">
            <div class="progress-fill" id="progressFill" style="width: <?= (int)$percentual ?>%;"></div>
        </div>
    </section>

    <div class="toolbar">
        <?php if ($readonly_mode): ?>
        <button type="button" class="btn btn-secondary" id="btnVisualizarChecklist">Visualizar em modal</button>
        <button type="button" class="btn btn-primary" id="btnBaixarChecklist">Download checklist</button>
        <?php else: ?>
        <button type="button" class="btn btn-secondary" id="btnAdicionar">+ Adicionar item</button>
        <button type="button" class="btn btn-secondary" id="btnMarcarTodos">Marcar todos</button>
        <button type="button" class="btn btn-secondary" id="btnDesmarcarTodos">Desmarcar todos</button>
        <button type="button" class="btn btn-primary" id="btnSalvarChecklist">Salvar checklist</button>
        <?php endif; ?>
    </div>

    <section class="checklist-card">
        <div class="checklist-items" id="checklistItems"></div>
        <div class="status-note" id="statusNote"></div>
    </section>
</div>

<?php if ($readonly_mode): ?>
<div class="modal-backdrop" id="modalChecklistVisualizacao" hidden>
    <div class="modal-card" style="max-width: 760px;">
        <div class="modal-header">
            <div>
                <h3 class="modal-title">Visualização do checklist</h3>
                <p class="modal-description">Modo somente leitura para a realização do evento.</p>
            </div>
            <button type="button" class="modal-close" id="btnFecharModalChecklist" aria-label="Fechar">×</button>
        </div>
        <div id="modalChecklistConteudo" style="max-height: 62vh; overflow:auto;"></div>
    </div>
</div>
<?php endif; ?>

<script>
const meetingId = <?= (int)$meeting_id ?>;
const origin = <?= json_encode($origin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const checklistReadonly = <?= $readonly_mode ? 'true' : 'false' ?>;
const checklistState = <?= $items_json ?>;

function escapeHtml(value) {
    return (value ?? '').toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function showStatus(message, isError = false) {
    const el = document.getElementById('statusNote');
    if (!el) return;
    el.textContent = message;
    el.classList.toggle('error', !!isError);
    el.style.display = message ? 'block' : 'none';
}

function updateProgress() {
    const total = checklistState.length;
    const concluidos = checklistState.filter((item) => !!item.done).length;
    const percentual = total > 0 ? Math.round((concluidos / total) * 100) : 0;

    const value = document.getElementById('progressValue');
    if (value) {
        value.textContent = `${concluidos}/${total} (${percentual}%)`;
    }

    const fill = document.getElementById('progressFill');
    if (fill) {
        fill.style.width = `${percentual}%`;
    }
}

function renderChecklist() {
    const wrap = document.getElementById('checklistItems');
    if (!wrap) return;

    if (checklistState.length === 0) {
        wrap.innerHTML = '<div style="color:#64748b;font-style:italic;">Checklist vazio. Adicione um item.</div>';
        updateProgress();
        return;
    }

    if (checklistReadonly) {
        wrap.innerHTML = checklistState.map((item) => {
            const rowClass = item.done ? 'item-row done' : 'item-row';
            return `
                <div class="${rowClass}">
                    <input type="checkbox" ${item.done ? 'checked' : ''} disabled>
                    <div class="item-label" style="display:flex; align-items:center;">${escapeHtml(item.label || '')}</div>
                    <div style="color:#64748b; font-size:0.8rem; font-weight:700;">${item.done ? 'Concluído' : 'Pendente'}</div>
                </div>
            `;
        }).join('');
    } else {
        wrap.innerHTML = checklistState.map((item, idx) => {
            const rowClass = item.done ? 'item-row done' : 'item-row';
            return `
                <div class="${rowClass}">
                    <input type="checkbox" data-action="toggle" data-index="${idx}" ${item.done ? 'checked' : ''}>
                    <input type="text" class="item-label" data-action="edit" data-index="${idx}" value="${escapeHtml(item.label || '')}" maxlength="180">
                    <button type="button" class="btn btn-danger" data-action="remove" data-index="${idx}">Remover</button>
                </div>
            `;
        }).join('');
    }

    updateProgress();
}

function addItem() {
    const next = checklistState.length + 1;
    checklistState.push({
        id: `item_${Date.now()}_${next}`,
        label: `Novo item ${next}`,
        done: false,
    });
    renderChecklist();
}

function markAll(value) {
    if (checklistReadonly) return;
    checklistState.forEach((item) => {
        item.done = !!value;
    });
    renderChecklist();
}

function collectItemsForSave() {
    return checklistState
        .map((item, idx) => {
            const label = String(item.label || '').trim();
            if (!label) return null;
            return {
                id: String(item.id || `item_${idx + 1}`),
                label,
                done: !!item.done,
            };
        })
        .filter((item) => item !== null);
}

async function salvarChecklist() {
    if (checklistReadonly) {
        showStatus('Checklist em modo somente leitura.');
        return;
    }
    const btn = document.getElementById('btnSalvarChecklist');
    const payload = collectItemsForSave();
    if (payload.length === 0) {
        showStatus('Adicione ao menos um item válido para salvar.', true);
        return;
    }

    if (btn) btn.disabled = true;
    showStatus('Salvando checklist...');

    try {
        const formData = new FormData();
        formData.append('action', 'salvar_checklist');
        formData.append('meeting_id', String(meetingId));
        formData.append('origin', origin);
        formData.append('items_json', JSON.stringify(payload));

        const response = await fetch(window.location.href, { method: 'POST', body: formData });
        const raw = await response.text();
        const data = JSON.parse(raw || '{}');

        if (!data || !data.ok) {
            throw new Error((data && data.error) ? data.error : 'Falha ao salvar checklist.');
        }

        checklistState.length = 0;
        (data.items || []).forEach((item) => checklistState.push(item));
        renderChecklist();
        showStatus('Checklist salvo com sucesso.');
    } catch (err) {
        showStatus('Erro: ' + (err?.message || 'não foi possível salvar.'), true);
    } finally {
        if (btn) btn.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    renderChecklist();

    const wrap = document.getElementById('checklistItems');
    if (wrap) {
        wrap.addEventListener('change', (event) => {
            if (checklistReadonly) return;
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;

            const action = target.getAttribute('data-action');
            const index = Number(target.getAttribute('data-index'));
            if (!Number.isInteger(index) || index < 0 || index >= checklistState.length) return;

            if (action === 'toggle' && target instanceof HTMLInputElement) {
                checklistState[index].done = !!target.checked;
                renderChecklist();
            }
        });

        wrap.addEventListener('input', (event) => {
            if (checklistReadonly) return;
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) return;
            if (target.getAttribute('data-action') !== 'edit') return;

            const index = Number(target.getAttribute('data-index'));
            if (!Number.isInteger(index) || index < 0 || index >= checklistState.length) return;
            checklistState[index].label = target.value || '';
        });

        wrap.addEventListener('click', (event) => {
            if (checklistReadonly) return;
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;
            if (target.getAttribute('data-action') !== 'remove') return;

            const index = Number(target.getAttribute('data-index'));
            if (!Number.isInteger(index) || index < 0 || index >= checklistState.length) return;

            checklistState.splice(index, 1);
            renderChecklist();
        });
    }

    const btnAdicionar = document.getElementById('btnAdicionar');
    if (btnAdicionar) {
        btnAdicionar.addEventListener('click', addItem);
    }

    const btnMarcarTodos = document.getElementById('btnMarcarTodos');
    if (btnMarcarTodos) {
        btnMarcarTodos.addEventListener('click', () => markAll(true));
    }

    const btnDesmarcarTodos = document.getElementById('btnDesmarcarTodos');
    if (btnDesmarcarTodos) {
        btnDesmarcarTodos.addEventListener('click', () => markAll(false));
    }

    const btnSalvarChecklist = document.getElementById('btnSalvarChecklist');
    if (btnSalvarChecklist) {
        btnSalvarChecklist.addEventListener('click', salvarChecklist);
    }

    if (checklistReadonly) {
        const modal = document.getElementById('modalChecklistVisualizacao');
        const modalBody = document.getElementById('modalChecklistConteudo');
        const btnVisualizar = document.getElementById('btnVisualizarChecklist');
        const btnFechar = document.getElementById('btnFecharModalChecklist');
        const btnBaixar = document.getElementById('btnBaixarChecklist');

        function renderModalChecklist() {
            if (!modalBody) return;
            if (!Array.isArray(checklistState) || checklistState.length === 0) {
                modalBody.innerHTML = '<div style="padding:0.8rem; color:#64748b;">Checklist sem itens cadastrados.</div>';
                return;
            }
            modalBody.innerHTML = checklistState.map((item, idx) => {
                const done = !!item.done;
                return `
                    <div style="display:flex; gap:0.6rem; align-items:flex-start; padding:0.62rem 0.2rem; border-bottom:1px solid #e2e8f0;">
                        <div style="font-weight:700; color:${done ? '#0f766e' : '#64748b'};">${done ? '✓' : '•'}</div>
                        <div style="flex:1;">
                            <div style="font-size:0.92rem; color:#1f2937; ${done ? 'text-decoration:line-through; color:#64748b;' : ''}">${escapeHtml(item.label || `Item ${idx + 1}`)}</div>
                            <div style="font-size:0.78rem; color:#64748b; margin-top:0.2rem;">${done ? 'Concluído' : 'Pendente'}</div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function openChecklistModal() {
            if (!modal) return;
            renderModalChecklist();
            modal.hidden = false;
            modal.classList.add('open');
            document.body.classList.add('modal-open');
        }

        function closeChecklistModal() {
            if (!modal) return;
            modal.classList.remove('open');
            modal.hidden = true;
            document.body.classList.remove('modal-open');
        }

        function baixarChecklistTxt() {
            const linhas = [];
            linhas.push('Checklist de Evento');
            linhas.push('');
            checklistState.forEach((item, idx) => {
                const flag = item.done ? '[x]' : '[ ]';
                linhas.push(`${idx + 1}. ${flag} ${(item.label || '').toString().trim()}`);
            });
            const conteudo = linhas.join('\n');
            const blob = new Blob([conteudo], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `checklist-evento-${meetingId}.txt`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        }

        if (btnVisualizar) {
            btnVisualizar.addEventListener('click', openChecklistModal);
        }
        if (btnFechar) {
            btnFechar.addEventListener('click', closeChecklistModal);
        }
        if (modal) {
            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeChecklistModal();
                }
            });
        }
        if (btnBaixar) {
            btnBaixar.addEventListener('click', baixarChecklistTxt);
        }
    }
});
</script>

<?php endSidebar(); ?>
