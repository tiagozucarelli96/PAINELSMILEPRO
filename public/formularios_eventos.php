<?php
/**
 * formularios_eventos.php
 * Gestão central de formulários reutilizáveis para Eventos.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

if (empty($_SESSION['perm_configuracoes']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    switch ($action) {
        case 'listar_templates_form':
            echo json_encode([
                'ok' => true,
                'templates' => eventos_form_templates_listar($pdo),
            ]);
            exit;

        case 'salvar_template_form':
            $template_id = (int)($_POST['template_id'] ?? 0);
            $template_name = trim((string)($_POST['template_name'] ?? ''));
            $template_category = trim((string)($_POST['template_category'] ?? 'geral'));
            $schema_json = (string)($_POST['schema_json'] ?? '[]');
            $schema = json_decode($schema_json, true);
            if (!is_array($schema)) {
                echo json_encode(['ok' => false, 'error' => 'Schema inválido']);
                exit;
            }
            $result = eventos_form_template_salvar(
                $pdo,
                $template_name,
                $template_category,
                $schema,
                $user_id,
                $template_id > 0 ? $template_id : null
            );
            echo json_encode($result);
            exit;

        case 'arquivar_template_form':
            $template_id = (int)($_POST['template_id'] ?? 0);
            echo json_encode(eventos_form_template_arquivar($pdo, $template_id));
            exit;

        case 'gerar_schema_template_form':
            $source_text = (string)($_POST['source_text'] ?? '');
            $include_notes = ((string)($_POST['include_notes'] ?? '1')) !== '0';
            $schema = eventos_form_template_gerar_schema_por_fonte($source_text, $include_notes);
            if (empty($schema)) {
                echo json_encode(['ok' => false, 'error' => 'Nao foi possivel gerar campos com o texto informado.']);
                exit;
            }
            if (!eventos_form_template_tem_campo_util($schema)) {
                echo json_encode(['ok' => false, 'error' => 'A importacao nao encontrou perguntas preenchiveis.']);
                exit;
            }
            $fillable_types = ['text', 'textarea', 'yesno', 'select', 'file'];
            $fillable_count = 0;
            foreach ($schema as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $type = strtolower(trim((string)($field['type'] ?? '')));
                if (in_array($type, $fillable_types, true)) {
                    $fillable_count++;
                }
            }
            echo json_encode([
                'ok' => true,
                'schema' => $schema,
                'summary' => [
                    'total' => count($schema),
                    'fillable' => $fillable_count,
                ],
            ]);
            exit;

        default:
            echo json_encode(['ok' => false, 'error' => 'Ação inválida']);
            exit;
    }
}

$templates = eventos_form_templates_listar($pdo);
includeSidebar('Formulários eventos');
?>

<style>
    .forms-page {
        max-width: 1400px;
        margin: 0 auto;
        padding: 1.5rem;
        background: #f8fafc;
    }

    .forms-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1rem;
    }

    .forms-title {
        margin: 0;
        font-size: 1.5rem;
        color: #0f172a;
    }

    .forms-subtitle {
        margin: 0.3rem 0 0 0;
        color: #64748b;
        font-size: 0.9rem;
    }

    .forms-actions {
        display: flex;
        gap: 0.6rem;
        flex-wrap: wrap;
    }

    .btn {
        padding: 0.62rem 1rem;
        border-radius: 8px;
        border: 1px solid transparent;
        font-size: 0.88rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
    }

    .btn-primary {
        background: #1d4ed8;
        color: #fff;
    }

    .btn-primary:hover {
        background: #1e40af;
    }

    .btn-secondary {
        background: #f1f5f9;
        color: #334155;
        border-color: #cbd5e1;
    }

    .btn-secondary:hover {
        background: #e2e8f0;
    }

    .btn-danger {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fecaca;
    }

    .btn-danger:hover {
        background: #fecaca;
    }

    .forms-grid {
        display: grid;
        grid-template-columns: minmax(320px, 1fr) minmax(420px, 2fr);
        gap: 1rem;
        align-items: start;
    }

    .panel {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 1rem;
    }

    .panel h2 {
        margin: 0 0 0.7rem 0;
        font-size: 1.1rem;
        color: #0f172a;
    }

    .panel p {
        margin: 0 0 0.8rem 0;
        color: #64748b;
        font-size: 0.85rem;
    }

    .field-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }

    .field-group {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }

    .field-group.full {
        grid-column: 1 / -1;
    }

    .field-group label {
        font-size: 0.8rem;
        font-weight: 600;
        color: #334155;
    }

    .field-group input,
    .field-group select,
    .field-group textarea {
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.55rem 0.65rem;
        font-size: 0.88rem;
        width: 100%;
    }

    .helper-text {
        margin: 0.55rem 0 0 0;
        font-size: 0.76rem;
        color: #64748b;
    }

    .toolbar {
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
        margin-top: 0.75rem;
    }

    .templates-list {
        margin-top: 0.8rem;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        max-height: 360px;
        overflow-y: auto;
    }

    .template-card {
        padding: 0.75rem;
        border-bottom: 1px solid #e2e8f0;
        cursor: pointer;
        background: #fff;
    }

    .template-card:last-child {
        border-bottom: none;
    }

    .template-card:hover {
        background: #f8fafc;
    }

    .template-card.active {
        border-left: 3px solid #2563eb;
        background: #eff6ff;
    }

    .template-card-title {
        margin: 0;
        font-size: 0.9rem;
        color: #0f172a;
        font-weight: 700;
    }

    .template-card-meta {
        margin: 0.22rem 0 0 0;
        font-size: 0.77rem;
        color: #64748b;
    }

    .builder-fields-list {
        margin-top: 0.9rem;
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
    }

    .builder-field-card {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        padding: 0.7rem;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.75rem;
    }

    .builder-field-title {
        margin: 0;
        font-size: 0.88rem;
        color: #0f172a;
        font-weight: 700;
    }

    .builder-field-meta {
        margin: 0.22rem 0 0 0;
        color: #64748b;
        font-size: 0.78rem;
    }

    .builder-field-actions {
        display: flex;
        gap: 0.35rem;
        flex-wrap: wrap;
    }

    .builder-field-actions .btn {
        padding: 0.32rem 0.5rem;
        font-size: 0.74rem;
    }

    .status-line {
        margin-top: 0.7rem;
        font-size: 0.8rem;
        color: #334155;
        min-height: 1.2rem;
    }

    .status-line.error {
        color: #b91c1c;
    }

    .status-line.success {
        color: #166534;
    }

    .import-box {
        margin-bottom: 0.85rem;
        padding: 0.75rem;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        background: #f8fafc;
    }

    .import-box textarea {
        min-height: 150px;
        resize: vertical;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
    }

    .import-actions {
        margin-top: 0.55rem;
        display: flex;
        align-items: center;
        gap: 0.55rem;
        flex-wrap: wrap;
    }

    .import-check {
        display: inline-flex;
        align-items: center;
        gap: 0.38rem;
        font-size: 0.78rem;
        color: #334155;
    }

    .import-check input[type="checkbox"] {
        margin: 0;
        width: auto;
    }

    @media (max-width: 1080px) {
        .forms-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .field-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="forms-page">
    <div class="forms-header">
        <div>
            <h1 class="forms-title">🧩 Formularios eventos</h1>
            <p class="forms-subtitle">Cadastre e mantenha os formulários reutilizáveis para reuniões de eventos.</p>
        </div>
        <div class="forms-actions">
            <button type="button" class="btn btn-secondary" onclick="newTemplate()">+ Novo formulário</button>
            <button type="button" class="btn btn-secondary" onclick="refreshTemplates()">↻ Atualizar lista</button>
        </div>
    </div>

    <div class="forms-grid">
        <section class="panel">
            <h2>Formulários salvos</h2>
            <p>Selecione um formulário para carregar, sobrescrever ou arquivar.</p>

            <div class="field-group">
                <label for="savedTemplateSelect">Selecionar formulário</label>
                <select id="savedTemplateSelect" onchange="onTemplateSelectChange()">
                    <option value="">Selecione...</option>
                </select>
            </div>

            <div class="toolbar">
                <button type="button" class="btn btn-secondary" onclick="loadSelectedTemplate()">📥 Carregar selecionado</button>
                <button type="button" class="btn btn-secondary" onclick="overwriteSelectedTemplate()">♻️ Sobrescrever selecionado</button>
                <button type="button" class="btn btn-danger" onclick="archiveSelectedTemplate()">🗑️ Arquivar selecionado</button>
            </div>

            <div class="status-line" id="selectedTemplateMeta">Nenhum formulário selecionado.</div>
            <div class="templates-list" id="templatesList"></div>
        </section>

        <section class="panel">
            <h2>Montagem do formulário</h2>
            <p>Monte o formulário por campos ou importe texto/HTML e salve como novo template.</p>

            <div class="import-box">
                <div class="field-group full">
                    <label for="importSource">Importar de texto/HTML (geração automática de campos)</label>
                    <textarea id="importSource" rows="7" placeholder="Cole aqui o texto ou codigo HTML do formulario pronto..."></textarea>
                </div>
                <div class="import-actions">
                    <label class="import-check" for="importIncludeNotes">
                        <input type="checkbox" id="importIncludeNotes" checked>
                        Manter instruções como texto informativo
                    </label>
                    <button type="button" class="btn btn-secondary" onclick="importFromSource(false)">⚙️ Gerar campos</button>
                    <button type="button" class="btn btn-secondary" onclick="importFromSource(true)">⚡ Gerar e salvar novo</button>
                </div>
            </div>

            <div class="field-grid">
                <div class="field-group">
                    <label for="templateName">Nome do formulário</label>
                    <input type="text" id="templateName" placeholder="Ex.: 15 anos completo padrão Smile">
                </div>
                <div class="field-group">
                    <label for="templateCategory">Categoria</label>
                    <select id="templateCategory">
                        <option value="15anos">15 anos</option>
                        <option value="casamento">Casamento</option>
                        <option value="infantil">Infantil</option>
                        <option value="geral">Geral</option>
                    </select>
                </div>
                <div class="field-group">
                    <label for="fieldType">Tipo de campo</label>
                    <select id="fieldType" onchange="onChangeFieldType()">
                        <option value="text">Texto curto</option>
                        <option value="textarea">Texto longo</option>
                        <option value="yesno">Opção Sim/Não</option>
                        <option value="select">Múltipla escolha</option>
                        <option value="file">Upload de arquivo</option>
                        <option value="note">Texto informativo</option>
                        <option value="section">Título de seção</option>
                    </select>
                </div>
                <div class="field-group">
                    <label for="fieldRequired">Obrigatório</label>
                    <select id="fieldRequired">
                        <option value="1">Sim</option>
                        <option value="0">Não</option>
                    </select>
                </div>
                <div class="field-group full">
                    <label for="fieldQuestion">Pergunta / título</label>
                    <input type="text" id="fieldQuestion" placeholder="Digite a pergunta...">
                </div>
                <div class="field-group full" id="fieldOptionsWrap" style="display:none;">
                    <label for="fieldOptions">Opções (uma por linha)</label>
                    <textarea id="fieldOptions" rows="3" placeholder="Opção 1&#10;Opção 2&#10;Opção 3"></textarea>
                </div>
            </div>

            <div class="toolbar">
                <button type="button" class="btn btn-secondary" onclick="addField()">+ Adicionar campo</button>
                <button type="button" class="btn btn-secondary" onclick="addDivider()">− Separador</button>
                <button type="button" class="btn btn-primary" onclick="saveNewTemplate()">💾 Salvar novo</button>
                <button type="button" class="btn btn-secondary" onclick="clearBuilder(true)">Limpar campos</button>
            </div>

            <p class="helper-text">Dica: "Sobrescrever selecionado" atualiza o template escolhido com os campos atuais.</p>
            <div class="builder-fields-list" id="builderFieldsList"></div>
            <div class="status-line" id="statusMessage"></div>
        </section>
    </div>
</div>

<script>
const allowedTemplateCategories = ['15anos', 'casamento', 'infantil', 'geral'];
let savedFormTemplates = <?= json_encode(array_map(static function(array $template): array {
    return [
        'id' => (int)($template['id'] ?? 0),
        'nome' => (string)($template['nome'] ?? ''),
        'categoria' => (string)($template['categoria'] ?? 'geral'),
        'updated_at' => (string)($template['updated_at'] ?? ''),
        'created_by_user_id' => (int)($template['created_by_user_id'] ?? 0),
        'schema' => is_array($template['schema'] ?? null) ? $template['schema'] : [],
    ];
}, $templates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

let selectedTemplateId = null;
let formBuilderFields = [];
let builderDirty = false;

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) {
        return '-';
    }
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

function normalizeFormSchema(schema) {
    if (!Array.isArray(schema)) return [];
    const allowedTypes = ['text', 'textarea', 'yesno', 'select', 'file', 'section', 'divider', 'note'];
    return schema.map((field) => {
        let type = String(field.type || 'text').trim().toLowerCase();
        if (!allowedTypes.includes(type)) type = 'text';
        const options = Array.isArray(field.options)
            ? field.options.map((v) => String(v).trim()).filter(Boolean)
            : [];
        const neverRequired = ['section', 'divider', 'note'].includes(type);
        return {
            id: String(field.id || ('f_' + Math.random().toString(36).slice(2, 10))),
            type: type,
            label: String(field.label || '').trim(),
            required: neverRequired ? false : !!field.required,
            options: options
        };
    }).filter((field) => field.type === 'divider' || field.label !== '');
}

function getFieldTypeLabel(type) {
    const map = {
        text: 'Texto curto',
        textarea: 'Texto longo',
        yesno: 'Sim/Não',
        select: 'Múltipla escolha',
        file: 'Upload',
        note: 'Texto informativo',
        section: 'Título de seção',
        divider: 'Separador'
    };
    return map[type] || type;
}

function hasUsefulSchemaFields(schema) {
    if (!Array.isArray(schema)) return false;
    return schema.some((field) => {
        const type = String(field && field.type ? field.type : '').toLowerCase();
        const label = String(field && field.label ? field.label : '').trim();
        return ['text', 'textarea', 'yesno', 'select', 'file'].includes(type) && label !== '';
    });
}

function setStatus(message, type = '') {
    const status = document.getElementById('statusMessage');
    if (!status) return;
    status.className = 'status-line' + (type ? ' ' + type : '');
    status.textContent = message || '';
}

function setBuilderDirty(isDirty) {
    builderDirty = !!isDirty;
}

function onChangeFieldType() {
    const fieldType = document.getElementById('fieldType');
    const wrap = document.getElementById('fieldOptionsWrap');
    if (!fieldType || !wrap) return;
    wrap.style.display = fieldType.value === 'select' ? 'block' : 'none';
}

function renderTemplatesSelect() {
    const select = document.getElementById('savedTemplateSelect');
    if (!select) return;
    const current = selectedTemplateId ? String(selectedTemplateId) : '';
    const options = ['<option value="">Selecione...</option>'];
    savedFormTemplates.forEach((template) => {
        const id = Number(template.id || 0);
        if (!id) return;
        const label = `${String(template.nome || 'Modelo sem nome')} - ${String(template.categoria || 'geral')}`;
        const selected = String(id) === current ? ' selected' : '';
        options.push(`<option value="${id}"${selected}>${escapeHtml(label)}</option>`);
    });
    select.innerHTML = options.join('');
}

function renderTemplatesList() {
    const list = document.getElementById('templatesList');
    if (!list) return;
    if (!savedFormTemplates.length) {
        list.innerHTML = '<div class="template-card"><p class="template-card-meta">Nenhum formulário salvo ainda.</p></div>';
        return;
    }
    list.innerHTML = savedFormTemplates.map((template) => {
        const id = Number(template.id || 0);
        const activeClass = Number(selectedTemplateId) === id ? 'active' : '';
        const nome = escapeHtml(String(template.nome || 'Modelo sem nome'));
        const categoria = escapeHtml(String(template.categoria || 'geral'));
        const stamp = escapeHtml(formatDate(String(template.updated_at || '')));
        return `
            <div class="template-card ${activeClass}" onclick="selectTemplateFromCard(${id})">
                <p class="template-card-title">${nome}</p>
                <p class="template-card-meta">${categoria} • Atualizado em ${stamp}</p>
            </div>
        `;
    }).join('');
}

function updateSelectedTemplateMeta() {
    const meta = document.getElementById('selectedTemplateMeta');
    if (!meta) return;
    if (!selectedTemplateId) {
        meta.textContent = 'Nenhum formulário selecionado.';
        return;
    }
    const template = savedFormTemplates.find((item) => Number(item.id) === Number(selectedTemplateId));
    if (!template) {
        meta.textContent = 'Formulário selecionado não encontrado.';
        return;
    }
    meta.textContent = `${String(template.nome || 'Modelo sem nome')} • ${String(template.categoria || 'geral')} • Atualizado em ${formatDate(template.updated_at || '')}`;
}

function renderBuilderFields() {
    const list = document.getElementById('builderFieldsList');
    if (!list) return;
    if (!formBuilderFields.length) {
        list.innerHTML = '<div class="builder-field-card"><p class="builder-field-meta">Nenhum campo adicionado ainda.</p></div>';
        return;
    }
    list.innerHTML = formBuilderFields.map((field, index) => `
        <div class="builder-field-card">
            <div>
                <p class="builder-field-title">${index + 1}. ${escapeHtml(field.label || '(sem título)')}</p>
                <p class="builder-field-meta">
                    ${escapeHtml(getFieldTypeLabel(field.type))}
                    ${field.required ? ' • Obrigatório' : ' • Opcional'}
                    ${field.options && field.options.length ? ' • ' + field.options.length + ' opção(ões)' : ''}
                </p>
            </div>
            <div class="builder-field-actions">
                <button type="button" class="btn btn-secondary" onclick="moveField(${index}, -1)">↑</button>
                <button type="button" class="btn btn-secondary" onclick="moveField(${index}, 1)">↓</button>
                <button type="button" class="btn btn-secondary" onclick="toggleRequired(${index})">Obrig.</button>
                <button type="button" class="btn btn-danger" onclick="removeField(${index})">Excluir</button>
            </div>
        </div>
    `).join('');
}

function selectTemplateFromCard(templateId) {
    selectedTemplateId = Number(templateId) || null;
    renderTemplatesSelect();
    renderTemplatesList();
    updateSelectedTemplateMeta();
}

function onTemplateSelectChange() {
    const select = document.getElementById('savedTemplateSelect');
    selectedTemplateId = select && select.value ? Number(select.value) : null;
    renderTemplatesList();
    updateSelectedTemplateMeta();
}

function addField() {
    const fieldType = document.getElementById('fieldType');
    const fieldRequired = document.getElementById('fieldRequired');
    const fieldQuestion = document.getElementById('fieldQuestion');
    const fieldOptions = document.getElementById('fieldOptions');
    const type = fieldType ? (fieldType.value || 'text') : 'text';
    const question = ((fieldQuestion ? fieldQuestion.value : '') || '').trim();
    const optionsRaw = ((fieldOptions ? fieldOptions.value : '') || '').trim();
    const required = (fieldRequired ? fieldRequired.value : '1') === '1';

    if (type !== 'divider' && !question) {
        setStatus('Digite a pergunta/título para adicionar o campo.', 'error');
        return;
    }

    const field = {
        id: 'f_' + Math.random().toString(36).slice(2, 10),
        type: type,
        label: question,
        required: type === 'section' || type === 'divider' || type === 'note' ? false : required,
        options: []
    };

    if (type === 'select') {
        field.options = optionsRaw.split('\n').map((v) => v.trim()).filter(Boolean);
        if (!field.options.length) {
            setStatus('Para múltipla escolha, informe ao menos uma opção.', 'error');
            return;
        }
    }

    formBuilderFields.push(field);
    renderBuilderFields();
    setBuilderDirty(true);
    setStatus('');
    if (fieldQuestion) fieldQuestion.value = '';
    if (fieldOptions) fieldOptions.value = '';
}

function addDivider() {
    formBuilderFields.push({
        id: 'f_' + Math.random().toString(36).slice(2, 10),
        type: 'divider',
        label: '---',
        required: false,
        options: []
    });
    renderBuilderFields();
    setBuilderDirty(true);
    setStatus('');
}

function removeField(index) {
    if (!Array.isArray(formBuilderFields) || index < 0 || index >= formBuilderFields.length) return;
    formBuilderFields.splice(index, 1);
    renderBuilderFields();
    setBuilderDirty(true);
}

function moveField(index, direction) {
    if (!Array.isArray(formBuilderFields) || index < 0 || index >= formBuilderFields.length) return;
    const target = index + direction;
    if (target < 0 || target >= formBuilderFields.length) return;
    const item = formBuilderFields[index];
    formBuilderFields[index] = formBuilderFields[target];
    formBuilderFields[target] = item;
    renderBuilderFields();
    setBuilderDirty(true);
}

function toggleRequired(index) {
    if (!Array.isArray(formBuilderFields) || index < 0 || index >= formBuilderFields.length) return;
    const field = formBuilderFields[index];
    if (!field || field.type === 'section' || field.type === 'divider' || field.type === 'note') return;
    field.required = !field.required;
    renderBuilderFields();
    setBuilderDirty(true);
}

function loadTemplateIntoBuilder(template) {
    if (!template) return;
    formBuilderFields = normalizeFormSchema(Array.isArray(template.schema) ? template.schema : []);
    const name = document.getElementById('templateName');
    const category = document.getElementById('templateCategory');
    if (name) name.value = String(template.nome || '');
    if (category) category.value = allowedTemplateCategories.includes(String(template.categoria || 'geral'))
        ? String(template.categoria || 'geral')
        : 'geral';
    renderBuilderFields();
    setBuilderDirty(false);
    setStatus(`Formulário "${String(template.nome || 'modelo')}" carregado.`, 'success');
}

function clearBuilder(confirmIfDirty) {
    if (confirmIfDirty && builderDirty && !confirm('Limpar campos atuais? As alterações não salvas serão perdidas.')) {
        return;
    }
    formBuilderFields = [];
    const name = document.getElementById('templateName');
    const category = document.getElementById('templateCategory');
    if (name) name.value = '';
    if (category) category.value = 'geral';
    renderBuilderFields();
    setBuilderDirty(false);
    setStatus('Construtor limpo.', 'success');
}

function newTemplate() {
    selectedTemplateId = null;
    renderTemplatesSelect();
    renderTemplatesList();
    updateSelectedTemplateMeta();
    clearBuilder(true);
}

function validateTemplateInputs() {
    const nameEl = document.getElementById('templateName');
    const categoryEl = document.getElementById('templateCategory');
    const templateName = (nameEl ? nameEl.value : '').trim();
    const templateCategory = (categoryEl ? categoryEl.value : 'geral') || 'geral';

    if (templateName.length < 3) {
        setStatus('Informe um nome com no mínimo 3 caracteres.', 'error');
        return null;
    }
    if (!allowedTemplateCategories.includes(templateCategory)) {
        setStatus('Categoria inválida.', 'error');
        return null;
    }

    const normalized = normalizeFormSchema(formBuilderFields);
    if (!normalized.length || !hasUsefulSchemaFields(normalized)) {
        setStatus('Adicione ao menos um campo preenchível antes de salvar.', 'error');
        return null;
    }

    return {
        templateName,
        templateCategory,
        schema: normalized
    };
}

async function refreshTemplates() {
    try {
        const formData = new FormData();
        formData.append('action', 'listar_templates_form');
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (!data.ok) {
            throw new Error(data.error || 'Erro ao listar formulários');
        }
        const templates = Array.isArray(data.templates) ? data.templates : [];
        savedFormTemplates = templates.map((template) => ({
            id: Number(template.id || 0),
            nome: String(template.nome || ''),
            categoria: String(template.categoria || 'geral'),
            updated_at: String(template.updated_at || ''),
            created_by_user_id: Number(template.created_by_user_id || 0),
            schema: normalizeFormSchema(Array.isArray(template.schema) ? template.schema : [])
        }));
        if (selectedTemplateId) {
            const exists = savedFormTemplates.some((item) => Number(item.id) === Number(selectedTemplateId));
            if (!exists) {
                selectedTemplateId = null;
            }
        }
        renderTemplatesSelect();
        renderTemplatesList();
        updateSelectedTemplateMeta();
    } catch (err) {
        setStatus(err.message || 'Erro ao carregar formulários.', 'error');
    }
}

function countFillableFields(schema) {
    if (!Array.isArray(schema)) return 0;
    const fillable = ['text', 'textarea', 'yesno', 'select', 'file'];
    return schema.reduce((total, field) => {
        const type = String(field && field.type ? field.type : '').toLowerCase();
        return total + (fillable.includes(type) ? 1 : 0);
    }, 0);
}

async function importFromSource(autoSave = false) {
    const sourceEl = document.getElementById('importSource');
    const includeNotesEl = document.getElementById('importIncludeNotes');
    const sourceText = sourceEl ? String(sourceEl.value || '').trim() : '';
    if (!sourceText) {
        setStatus('Cole o texto/HTML para gerar os campos automaticamente.', 'error');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'gerar_schema_template_form');
        formData.append('source_text', sourceText);
        formData.append('include_notes', includeNotesEl && includeNotesEl.checked ? '1' : '0');
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (!data.ok) {
            setStatus(data.error || 'Erro ao importar texto para campos.', 'error');
            return;
        }

        const importedSchema = normalizeFormSchema(Array.isArray(data.schema) ? data.schema : []);
        if (!importedSchema.length || !hasUsefulSchemaFields(importedSchema)) {
            setStatus('Nao foi possivel gerar perguntas preenchiveis a partir do texto informado.', 'error');
            return;
        }

        formBuilderFields = importedSchema;
        renderBuilderFields();
        setBuilderDirty(true);

        const total = importedSchema.length;
        const fillable = countFillableFields(importedSchema);
        setStatus(`Importacao concluida: ${total} item(ns), ${fillable} campo(s) preenchivel(is).`, 'success');

        if (autoSave) {
            await saveNewTemplate();
        }
    } catch (err) {
        setStatus('Erro ao importar texto: ' + (err.message || err), 'error');
    }
}

async function saveTemplateRequest(payload) {
    const formData = new FormData();
    formData.append('action', 'salvar_template_form');
    if (payload.templateId) {
        formData.append('template_id', String(payload.templateId));
    }
    formData.append('template_name', payload.templateName);
    formData.append('template_category', payload.templateCategory);
    formData.append('schema_json', JSON.stringify(payload.schema));

    const resp = await fetch(window.location.href, {
        method: 'POST',
        body: formData
    });
    return resp.json();
}

async function saveNewTemplate() {
    const payload = validateTemplateInputs();
    if (!payload) return;

    try {
        const data = await saveTemplateRequest(payload);
        if (!data.ok || !data.template) {
            setStatus(data.error || 'Erro ao salvar formulário.', 'error');
            return;
        }
        selectedTemplateId = Number(data.template.id || 0) || null;
        await refreshTemplates();
        setBuilderDirty(false);
        setStatus('Formulário salvo com sucesso.', 'success');
    } catch (err) {
        setStatus('Erro ao salvar formulário: ' + err.message, 'error');
    }
}

async function loadSelectedTemplate() {
    if (!selectedTemplateId) {
        setStatus('Selecione um formulário para carregar.', 'error');
        return;
    }
    if (builderDirty && !confirm('Existem alterações não salvas. Deseja carregar outro formulário mesmo assim?')) {
        return;
    }
    const template = savedFormTemplates.find((item) => Number(item.id) === Number(selectedTemplateId));
    if (!template) {
        setStatus('Formulário selecionado não encontrado.', 'error');
        return;
    }
    loadTemplateIntoBuilder(template);
}

async function overwriteSelectedTemplate() {
    if (!selectedTemplateId) {
        setStatus('Selecione um formulário para sobrescrever.', 'error');
        return;
    }
    const payload = validateTemplateInputs();
    if (!payload) return;
    const template = savedFormTemplates.find((item) => Number(item.id) === Number(selectedTemplateId));
    if (!template) {
        setStatus('Formulário selecionado não encontrado.', 'error');
        return;
    }
    if (!confirm(`Sobrescrever "${String(template.nome || 'formulário')}" com os campos atuais?`)) {
        return;
    }

    try {
        const data = await saveTemplateRequest({
            ...payload,
            templateId: selectedTemplateId
        });
        if (!data.ok || !data.template) {
            setStatus(data.error || 'Erro ao sobrescrever formulário.', 'error');
            return;
        }
        selectedTemplateId = Number(data.template.id || 0) || null;
        await refreshTemplates();
        setBuilderDirty(false);
        setStatus('Formulário sobrescrito com sucesso.', 'success');
    } catch (err) {
        setStatus('Erro ao sobrescrever formulário: ' + err.message, 'error');
    }
}

async function archiveSelectedTemplate() {
    if (!selectedTemplateId) {
        setStatus('Selecione um formulário para arquivar.', 'error');
        return;
    }
    const template = savedFormTemplates.find((item) => Number(item.id) === Number(selectedTemplateId));
    if (!template) {
        setStatus('Formulário selecionado não encontrado.', 'error');
        return;
    }
    if (!confirm(`Arquivar "${String(template.nome || 'formulário')}"?`)) {
        return;
    }
    try {
        const formData = new FormData();
        formData.append('action', 'arquivar_template_form');
        formData.append('template_id', String(selectedTemplateId));
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (!data.ok) {
            setStatus(data.error || 'Erro ao arquivar formulário.', 'error');
            return;
        }
        selectedTemplateId = null;
        await refreshTemplates();
        setStatus('Formulário arquivado.', 'success');
    } catch (err) {
        setStatus('Erro ao arquivar formulário: ' + err.message, 'error');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    onChangeFieldType();
    renderTemplatesSelect();
    renderTemplatesList();
    renderBuilderFields();
    updateSelectedTemplateMeta();
    refreshTemplates();

    const nameInput = document.getElementById('templateName');
    const categoryInput = document.getElementById('templateCategory');
    if (nameInput) {
        nameInput.addEventListener('input', () => setBuilderDirty(true));
    }
    if (categoryInput) {
        categoryInput.addEventListener('change', () => setBuilderDirty(true));
    }
});

window.addEventListener('pageshow', function (event) {
    if (event && event.persisted) {
        refreshTemplates();
    }
});
</script>

<?php endSidebar(); ?>
