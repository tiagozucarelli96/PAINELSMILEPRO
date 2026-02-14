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
            eventos_form_template_seed_protocolo_15anos($pdo, $user_id);
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

        case 'garantir_template_protocolo_15anos':
            $force_update = ((string)($_POST['force_update'] ?? '0')) === '1';
            $seed = eventos_form_template_seed_protocolo_15anos($pdo, $user_id, $force_update);
            if (empty($seed['ok'])) {
                echo json_encode(['ok' => false, 'error' => (string)($seed['error'] ?? 'Falha ao garantir template padrão')]);
                exit;
            }
            echo json_encode([
                'ok' => true,
                'seed' => $seed,
                'templates' => eventos_form_templates_listar($pdo),
            ]);
            exit;

        default:
            echo json_encode(['ok' => false, 'error' => 'Ação inválida']);
            exit;
    }
}

eventos_form_template_seed_protocolo_15anos($pdo, $user_id);
$templates = eventos_form_templates_listar($pdo);
includeSidebar('Formulários eventos');
?>

<style>
    .forms-page {
        max-width: 1520px;
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
        align-items: center;
    }

    .btn {
        padding: 0.62rem 1rem;
        border-radius: 10px;
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

    .btn-ghost {
        background: #fff;
        color: #334155;
        border-color: #d6deea;
    }

    .btn-ghost:hover {
        background: #f8fafc;
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
        grid-template-columns: minmax(320px, 0.9fr) minmax(520px, 1.9fr);
        gap: 1.15rem;
        align-items: start;
    }

    .panel {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 1rem;
    }

    .panel-library {
        position: sticky;
        top: 1rem;
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

    .library-filters {
        margin-top: 0.4rem;
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.62rem;
    }

    .library-summary {
        margin-top: 0.7rem;
        padding: 0.55rem 0.75rem;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        color: #475569;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .library-actions {
        margin-top: 0.8rem;
        border-top: 1px solid #e2e8f0;
        padding-top: 0.8rem;
    }

    .library-actions-title {
        margin: 0 0 0.45rem 0;
        color: #334155;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: uppercase;
    }

    .library-actions .toolbar {
        margin-top: 0.55rem;
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
        background: #fff;
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
        max-height: 430px;
        overflow-y: auto;
        background: #fff;
    }

    .template-card {
        padding: 0.75rem;
        border-bottom: 1px solid #e2e8f0;
        cursor: pointer;
        background: #fff;
        transition: background-color 0.15s ease;
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

    .template-card-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.45rem;
    }

    .template-card-title {
        margin: 0;
        font-size: 0.9rem;
        color: #0f172a;
        font-weight: 700;
    }

    .template-card-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.16rem 0.48rem;
        border-radius: 999px;
        border: 1px solid #cbd5e1;
        background: #f8fafc;
        color: #475569;
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
    }

    .template-card-meta {
        margin: 0.22rem 0 0 0;
        font-size: 0.77rem;
        color: #64748b;
    }

    .panel-editor {
        overflow: hidden;
    }

    .editor-tabs {
        margin-top: 0.85rem;
        display: flex;
        gap: 0.42rem;
        flex-wrap: wrap;
        padding-bottom: 0.85rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .editor-tab-btn {
        border: 1px solid #d7dfeb;
        background: #f8fafc;
        color: #475569;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.38rem 0.7rem;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .editor-tab-btn:hover {
        background: #eef2f7;
        color: #334155;
    }

    .editor-tab-btn.active {
        background: #1d4ed8;
        border-color: #1d4ed8;
        color: #fff;
    }

    .editor-tab-panel {
        display: none;
        margin-top: 0.9rem;
    }

    .editor-tab-panel.active {
        display: block;
    }

    .editor-panel-title {
        margin: 0;
        color: #0f172a;
        font-size: 1rem;
        font-weight: 700;
    }

    .editor-panel-subtitle {
        margin: 0.26rem 0 0.75rem 0;
        color: #64748b;
        font-size: 0.82rem;
    }

    .editor-panel-card {
        padding: 0.75rem;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #fff;
    }

    .builder-fields-list {
        margin-top: 0.2rem;
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
        margin-top: 0.85rem;
        font-size: 0.8rem;
        color: #475569;
        min-height: 1.2rem;
        padding: 0.56rem 0.7rem;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
    }

    .status-line:empty {
        display: none;
    }

    .status-line.error {
        color: #b91c1c;
        border-color: #fecaca;
        background: #fff1f2;
    }

    .status-line.success {
        color: #166534;
        border-color: #bbf7d0;
        background: #f0fdf4;
    }

    .import-box {
        margin-bottom: 0;
        padding: 0.8rem;
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

    .compact-toolbar .btn {
        padding: 0.52rem 0.72rem;
        font-size: 0.79rem;
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

    .counter-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        padding: 0.12rem 0.45rem;
        border-radius: 999px;
        border: 1px solid #bfdbfe;
        background: #eff6ff;
        color: #1d4ed8;
        font-size: 0.74rem;
        font-weight: 700;
    }

    .hidden-accessibility {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }

    @media (max-width: 1080px) {
        .forms-grid {
            grid-template-columns: 1fr;
        }

        .panel-library {
            position: static;
        }
    }

    @media (max-width: 640px) {
        .field-grid {
            grid-template-columns: 1fr;
        }

        .forms-actions .btn {
            width: 100%;
        }

        .editor-tabs {
            gap: 0.3rem;
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
            <button type="button" class="btn btn-secondary" onclick="ensureProtocolo15Anos()">📌 Garantir protocolo 15 anos</button>
            <button type="button" class="btn btn-secondary" onclick="refreshTemplates()">↻ Atualizar lista</button>
        </div>
    </div>

    <div class="forms-grid">
        <section class="panel panel-library">
            <h2>Biblioteca de formulários</h2>
            <p>Busque, filtre e escolha um formulário salvo para reutilizar.</p>

            <div class="library-filters">
                <div class="field-group">
                    <label for="templatesSearch">Buscar por nome</label>
                    <input type="text" id="templatesSearch" placeholder="Ex.: protocolo 15 anos" oninput="onTemplatesSearchInput(this.value)">
                </div>
                <div class="field-group">
                    <label for="templatesCategoryFilter">Categoria</label>
                    <select id="templatesCategoryFilter" onchange="onTemplatesCategoryFilterChange(this.value)">
                        <option value="all">Todas</option>
                        <option value="15anos">15 anos</option>
                        <option value="casamento">Casamento</option>
                        <option value="infantil">Infantil</option>
                        <option value="geral">Geral</option>
                    </select>
                </div>
            </div>

            <div class="library-summary" id="librarySummary">0 formulário(s)</div>
            <div class="templates-list" id="templatesList"></div>

            <div class="library-actions">
                <p class="library-actions-title">Ações no selecionado</p>

                <label class="hidden-accessibility" for="savedTemplateSelect">Formulário selecionado</label>
                <select id="savedTemplateSelect" onchange="onTemplateSelectChange()">
                    <option value="">Selecione...</option>
                </select>

                <div class="toolbar compact-toolbar">
                    <button type="button" class="btn btn-ghost" onclick="loadSelectedTemplate()">📥 Carregar</button>
                    <button type="button" class="btn btn-ghost" onclick="overwriteSelectedTemplate()">♻️ Sobrescrever</button>
                    <button type="button" class="btn btn-danger" onclick="archiveSelectedTemplate()">🗑️ Arquivar</button>
                </div>
                <div class="status-line" id="selectedTemplateMeta">Nenhum formulário selecionado.</div>
            </div>
        </section>

        <section class="panel panel-editor">
            <h2>Editor do formulário</h2>
            <p>Trabalhe por etapas para reduzir poluição visual e focar no que importa.</p>

            <div class="editor-tabs" role="tablist" aria-label="Etapas do editor">
                <button type="button" class="editor-tab-btn active" data-editor-tab="dados" onclick="switchEditorTab('dados')">1. Dados</button>
                <button type="button" class="editor-tab-btn" data-editor-tab="manual" onclick="switchEditorTab('manual')">2. Montagem manual</button>
                <button type="button" class="editor-tab-btn" data-editor-tab="importar" onclick="switchEditorTab('importar')">3. Importar texto</button>
                <button type="button" class="editor-tab-btn" data-editor-tab="campos" onclick="switchEditorTab('campos')">4. Campos <span class="counter-badge" id="builderFieldsCount">0</span></button>
            </div>

            <div class="editor-tab-panel active" id="editorTab-dados" data-editor-panel="dados">
                <h3 class="editor-panel-title">Dados base do formulário</h3>
                <p class="editor-panel-subtitle">Defina nome e categoria antes de salvar.</p>
                <div class="editor-panel-card">
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
                    </div>
                    <div class="toolbar">
                        <button type="button" class="btn btn-primary" onclick="saveNewTemplate()">💾 Salvar novo</button>
                        <button type="button" class="btn btn-secondary" onclick="newTemplate()">+ Novo formulário</button>
                        <button type="button" class="btn btn-secondary" onclick="clearBuilder(true)">Limpar campos</button>
                    </div>
                </div>
            </div>

            <div class="editor-tab-panel" id="editorTab-manual" data-editor-panel="manual">
                <h3 class="editor-panel-title">Montagem manual</h3>
                <p class="editor-panel-subtitle">Adicione campos avulsos sem importar conteúdo externo.</p>
                <div class="editor-panel-card">
                    <div class="field-grid">
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
                        <button type="button" class="btn btn-ghost" onclick="switchEditorTab('campos')">Ver campos adicionados</button>
                    </div>
                </div>
            </div>

            <div class="editor-tab-panel" id="editorTab-importar" data-editor-panel="importar">
                <h3 class="editor-panel-title">Importar texto/HTML</h3>
                <p class="editor-panel-subtitle">Cole o conteúdo pronto e gere a estrutura automaticamente.</p>
                <div class="import-box">
                    <div class="field-group full">
                        <label for="importSource">Fonte para importação</label>
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
            </div>

            <div class="editor-tab-panel" id="editorTab-campos" data-editor-panel="campos">
                <h3 class="editor-panel-title">Campos adicionados</h3>
                <p class="editor-panel-subtitle">Revise a ordem e ajustes antes de salvar.</p>
                <div class="editor-panel-card">
                    <p class="helper-text">Dica: use setas para ordenar e "Obrig." para alternar obrigatoriedade nos campos preenchíveis.</p>
                    <div class="builder-fields-list" id="builderFieldsList"></div>
                </div>
            </div>

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
let templatesSearchTerm = '';
let templatesCategoryFilter = 'all';
let activeEditorTab = 'dados';

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

function getCategoryLabel(category) {
    const map = {
        '15anos': '15 anos',
        'casamento': 'Casamento',
        'infantil': 'Infantil',
        'geral': 'Geral'
    };
    const key = String(category || '').toLowerCase();
    return map[key] || String(category || 'Geral');
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
        const label = `${String(template.nome || 'Modelo sem nome')} - ${getCategoryLabel(String(template.categoria || 'geral'))}`;
        const selected = String(id) === current ? ' selected' : '';
        options.push(`<option value="${id}"${selected}>${escapeHtml(label)}</option>`);
    });
    select.innerHTML = options.join('');
}

function getFilteredTemplatesList() {
    const term = String(templatesSearchTerm || '').trim().toLowerCase();
    const category = String(templatesCategoryFilter || 'all').trim().toLowerCase();
    return savedFormTemplates.filter((template) => {
        const nome = String(template.nome || '').toLowerCase();
        const categoria = String(template.categoria || 'geral').toLowerCase();
        const matchTerm = term === '' || nome.includes(term);
        const matchCategory = category === 'all' || categoria === category;
        return matchTerm && matchCategory;
    });
}

function updateLibrarySummary(filteredTotal = null) {
    const summary = document.getElementById('librarySummary');
    if (!summary) return;
    const visible = filteredTotal === null ? getFilteredTemplatesList().length : Number(filteredTotal || 0);
    const total = Array.isArray(savedFormTemplates) ? savedFormTemplates.length : 0;
    if (visible === total) {
        summary.textContent = `${total} formulário(s) disponível(is)`;
        return;
    }
    summary.textContent = `${visible} de ${total} formulário(s)`;
}

function renderTemplatesList() {
    const list = document.getElementById('templatesList');
    if (!list) return;
    if (!savedFormTemplates.length) {
        updateLibrarySummary(0);
        list.innerHTML = '<div class="template-card"><p class="template-card-meta">Nenhum formulário salvo ainda.</p></div>';
        return;
    }

    const filteredTemplates = getFilteredTemplatesList();
    updateLibrarySummary(filteredTemplates.length);

    if (!filteredTemplates.length) {
        list.innerHTML = '<div class="template-card"><p class="template-card-meta">Nenhum formulário encontrado para este filtro.</p></div>';
        return;
    }

    list.innerHTML = filteredTemplates.map((template) => {
        const id = Number(template.id || 0);
        const activeClass = Number(selectedTemplateId) === id ? 'active' : '';
        const nome = escapeHtml(String(template.nome || 'Modelo sem nome'));
        const categoria = escapeHtml(getCategoryLabel(String(template.categoria || 'geral')));
        const stamp = escapeHtml(formatDate(String(template.updated_at || '')));
        return `
            <div class="template-card ${activeClass}" onclick="selectTemplateFromCard(${id})">
                <div class="template-card-row">
                    <p class="template-card-title">${nome}</p>
                    <span class="template-card-badge">${categoria}</span>
                </div>
                <p class="template-card-meta">Atualizado em ${stamp}</p>
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
    meta.textContent = `${String(template.nome || 'Modelo sem nome')} • ${getCategoryLabel(String(template.categoria || 'geral'))} • Atualizado em ${formatDate(template.updated_at || '')}`;
}

function renderBuilderFields() {
    const list = document.getElementById('builderFieldsList');
    const counter = document.getElementById('builderFieldsCount');
    if (counter) {
        counter.textContent = String(Array.isArray(formBuilderFields) ? formBuilderFields.length : 0);
    }
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
    const select = document.getElementById('savedTemplateSelect');
    if (select) {
        select.value = selectedTemplateId ? String(selectedTemplateId) : '';
    }
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

function onTemplatesSearchInput(value) {
    templatesSearchTerm = String(value || '');
    renderTemplatesList();
}

function onTemplatesCategoryFilterChange(value) {
    templatesCategoryFilter = String(value || 'all');
    renderTemplatesList();
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
    switchEditorTab('campos');
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
    switchEditorTab('campos');
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
    switchEditorTab('dados');
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
        switchEditorTab('campos');

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

function switchEditorTab(tab) {
    const nextTab = String(tab || 'dados');
    activeEditorTab = nextTab;

    document.querySelectorAll('.editor-tab-btn').forEach((btn) => {
        const key = String(btn.getAttribute('data-editor-tab') || '');
        btn.classList.toggle('active', key === nextTab);
    });

    document.querySelectorAll('[data-editor-panel]').forEach((panel) => {
        const key = String(panel.getAttribute('data-editor-panel') || '');
        panel.classList.toggle('active', key === nextTab);
    });
}

async function ensureProtocolo15Anos(forceUpdate = false) {
    try {
        const formData = new FormData();
        formData.append('action', 'garantir_template_protocolo_15anos');
        if (forceUpdate) {
            formData.append('force_update', '1');
        }
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (!data.ok) {
            setStatus(data.error || 'Não foi possível garantir o protocolo 15 anos.', 'error');
            return;
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

        const protocolo = savedFormTemplates.find((item) => (
            String(item.nome || '').toLowerCase() === 'protocolo 15 anos' && String(item.categoria || '') === '15anos'
        )) || null;
        selectedTemplateId = protocolo ? Number(protocolo.id || 0) : selectedTemplateId;

        renderTemplatesSelect();
        renderTemplatesList();
        updateSelectedTemplateMeta();
        setStatus('Template "protocolo 15 anos" garantido com sucesso.', 'success');
    } catch (err) {
        setStatus('Erro ao garantir template padrão: ' + (err.message || err), 'error');
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
    switchEditorTab(activeEditorTab);
    refreshTemplates();

    const nameInput = document.getElementById('templateName');
    const categoryInput = document.getElementById('templateCategory');
    const searchInput = document.getElementById('templatesSearch');
    const categoryFilterInput = document.getElementById('templatesCategoryFilter');
    if (nameInput) {
        nameInput.addEventListener('input', () => setBuilderDirty(true));
    }
    if (categoryInput) {
        categoryInput.addEventListener('change', () => setBuilderDirty(true));
    }
    if (searchInput) {
        searchInput.addEventListener('input', (event) => onTemplatesSearchInput(event.target.value));
    }
    if (categoryFilterInput) {
        categoryFilterInput.addEventListener('change', (event) => onTemplatesCategoryFilterChange(event.target.value));
    }
});

window.addEventListener('pageshow', function (event) {
    if (event && event.persisted) {
        refreshTemplates();
    }
});
</script>

<?php endSidebar(); ?>
