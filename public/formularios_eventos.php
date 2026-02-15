<?php
/**
 * formularios_eventos.php
 * Gestao central de formularios reutilizaveis para Eventos.
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
$request_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($request_method === 'POST') {
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
                echo json_encode(['ok' => false, 'error' => 'Schema invalido']);
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
                echo json_encode(['ok' => false, 'error' => (string)($seed['error'] ?? 'Falha ao garantir template padrao')]);
                exit;
            }

            echo json_encode([
                'ok' => true,
                'seed' => $seed,
                'templates' => eventos_form_templates_listar($pdo),
            ]);
            exit;

        default:
            echo json_encode(['ok' => false, 'error' => 'Acao invalida']);
            exit;
    }
}

eventos_form_template_seed_protocolo_15anos($pdo, $user_id);
$templates = eventos_form_templates_listar($pdo);
includeSidebar('Formularios eventos');
?>

<style>
    .forms-shell {
        max-width: 1500px;
        margin: 0 auto;
        padding: 1.2rem 1.2rem 1.5rem;
        background: #f3f5f9;
        min-height: calc(100vh - 90px);
    }

    .view {
        display: none;
    }

    .view.active {
        display: block;
    }

    .btn {
        border: 1px solid transparent;
        border-radius: 10px;
        padding: 0.58rem 0.9rem;
        font-size: 0.86rem;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.38rem;
    }

    .btn-primary {
        background: #3252cc;
        color: #fff;
    }

    .btn-primary:hover {
        background: #2541ad;
    }

    .btn-secondary {
        background: #eef2f8;
        color: #334155;
        border-color: #d1d9e6;
    }

    .btn-secondary:hover {
        background: #e5ebf4;
    }

    .btn-ghost {
        background: #fff;
        color: #334155;
        border-color: #d2dbe8;
    }

    .btn-ghost:hover {
        background: #f8fafd;
    }

    .btn-danger {
        background: #fff1f2;
        color: #b91c1c;
        border-color: #fecdd3;
    }

    .btn-danger:hover {
        background: #ffe4e6;
    }

    .library-topbar {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 0.95rem;
    }

    .library-title {
        margin: 0;
        font-size: 1.5rem;
        color: #0f172a;
    }

    .library-subtitle {
        margin: 0.35rem 0 0 0;
        color: #64748b;
        font-size: 0.9rem;
    }

    .library-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .library-filters {
        display: grid;
        grid-template-columns: minmax(240px, 1fr) minmax(190px, 220px);
        gap: 0.65rem;
        margin-bottom: 0.85rem;
    }

    .field-group {
        display: flex;
        flex-direction: column;
        gap: 0.34rem;
    }

    .field-group label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #334155;
    }

    .field-group input,
    .field-group select,
    .field-group textarea {
        width: 100%;
        border: 1px solid #cfd8e5;
        border-radius: 10px;
        padding: 0.57rem 0.68rem;
        font-size: 0.9rem;
        background: #fff;
    }

    .library-summary {
        margin-bottom: 0.75rem;
        color: #475569;
        font-size: 0.82rem;
        font-weight: 700;
    }

    .template-gallery {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 0.85rem;
    }

    .new-template-card,
    .template-card {
        border: 1px solid #d8e1ef;
        border-radius: 14px;
        background: #fff;
        min-height: 178px;
        padding: 0.9rem;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .new-template-card {
        cursor: pointer;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.65rem;
        border-style: dashed;
        border-width: 2px;
        color: #334155;
        background: #fbfcff;
    }

    .new-template-card:hover,
    .template-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 22px rgba(37, 65, 173, 0.11);
    }

    .new-template-plus {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: #e9edfa;
        color: #3151cb;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
        font-weight: 700;
        line-height: 1;
    }

    .template-card {
        cursor: pointer;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        border-top: 5px solid #3252cc;
    }

    .template-card-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.6rem;
    }

    .template-card-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.3;
    }

    .template-card-category {
        display: inline-flex;
        align-items: center;
        border: 1px solid #cfdbf2;
        border-radius: 999px;
        padding: 0.18rem 0.48rem;
        font-size: 0.73rem;
        font-weight: 700;
        color: #37537a;
        background: #f5f8fd;
    }

    .template-card-meta {
        margin-top: 0.72rem;
        color: #64748b;
        font-size: 0.79rem;
        line-height: 1.38;
    }

    .template-card-footer {
        margin-top: 0.72rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.6rem;
    }

    .template-card-count {
        color: #334155;
        font-size: 0.78rem;
        font-weight: 700;
    }

    .mini-btn {
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #b91c1c;
        border-radius: 8px;
        font-size: 0.74rem;
        font-weight: 700;
        padding: 0.3rem 0.5rem;
        cursor: pointer;
    }

    .mini-btn:hover {
        background: #fff1f2;
        border-color: #fecdd3;
    }

    .library-empty {
        padding: 1rem;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        color: #64748b;
        background: #fff;
        font-size: 0.84rem;
    }

    .editor-shell {
        display: flex;
        flex-direction: column;
        gap: 0.82rem;
    }

    .editor-topbar {
        background: #fff;
        border: 1px solid #d7dfeb;
        border-radius: 14px;
        padding: 0.8rem;
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 0.8rem;
        flex-wrap: wrap;
    }

    .editor-main-info {
        display: grid;
        grid-template-columns: minmax(180px, 1fr) minmax(150px, 180px);
        gap: 0.55rem;
        flex: 1;
        min-width: 270px;
    }

    .editor-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        align-items: center;
        justify-content: flex-end;
    }

    .import-panel {
        background: #fff;
        border: 1px solid #d7dfeb;
        border-radius: 14px;
        padding: 0.85rem;
        display: none;
    }

    .import-panel.open {
        display: block;
    }

    .import-panel h3 {
        margin: 0;
        font-size: 0.98rem;
        color: #0f172a;
    }

    .import-panel p {
        margin: 0.32rem 0 0.7rem 0;
        color: #64748b;
        font-size: 0.82rem;
    }

    .import-panel textarea {
        min-height: 140px;
        resize: vertical;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
    }

    .import-row {
        margin-top: 0.62rem;
        display: flex;
        gap: 0.52rem;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
    }

    .import-check {
        display: inline-flex;
        gap: 0.34rem;
        align-items: center;
        color: #334155;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .import-check input {
        margin: 0;
        width: auto;
    }

    .editor-layout {
        display: grid;
        grid-template-columns: minmax(520px, 1fr) 62px;
        gap: 0.9rem;
        align-items: start;
    }

    .form-canvas {
        max-width: 860px;
        margin: 0 auto;
        width: 100%;
        display: flex;
        flex-direction: column;
        gap: 0.74rem;
    }

    .form-header-card {
        background: #fff;
        border: 1px solid #d7dfeb;
        border-radius: 14px;
        border-top: 9px solid #3252cc;
        padding: 1rem 1rem 0.8rem;
    }

    .form-header-title {
        margin: 0;
        font-size: 1.55rem;
        color: #0f172a;
        font-weight: 800;
        line-height: 1.22;
    }

    .form-header-subtitle {
        margin: 0.45rem 0 0 0;
        color: #64748b;
        font-size: 0.86rem;
    }

    .questions-stack {
        display: flex;
        flex-direction: column;
        gap: 0.72rem;
    }

    .question-card {
        background: #fff;
        border: 1px solid #d7dfeb;
        border-left: 5px solid #4f6de0;
        border-radius: 12px;
        padding: 0.82rem;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
        transition: border-color 0.16s ease, box-shadow 0.16s ease, opacity 0.16s ease;
    }

    .question-head {
        display: grid;
        grid-template-columns: 74px 1fr 190px;
        gap: 0.55rem;
        align-items: center;
    }

    .question-order-cell {
        display: inline-flex;
        align-items: center;
        gap: 0.34rem;
    }

    .question-index {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #eef2ff;
        color: #1d4ed8;
        font-size: 0.82rem;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .drag-handle {
        border: 1px solid #d7dfeb;
        background: #f8fafc;
        color: #475569;
        width: 30px;
        height: 34px;
        border-radius: 8px;
        font-size: 0.84rem;
        font-weight: 700;
        cursor: grab;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .drag-handle:hover {
        background: #eef2ff;
        border-color: #c7d2fe;
        color: #1e40af;
    }

    .question-card.dragging {
        opacity: 0.62;
        border-color: #93c5fd;
    }

    .question-card.drop-target {
        border-color: #60a5fa;
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
    }

    .question-head input,
    .question-head select,
    .question-extra textarea {
        width: 100%;
        border: 1px solid #cfd8e5;
        border-radius: 9px;
        padding: 0.52rem 0.62rem;
        font-size: 0.88rem;
        background: #fff;
    }

    .question-extra {
        margin-top: 0.56rem;
    }

    .question-extra textarea {
        min-height: 78px;
        resize: vertical;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
    }

    .question-note {
        margin-top: 0.56rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 9px;
        padding: 0.5rem 0.58rem;
        color: #64748b;
        font-size: 0.79rem;
    }

    .question-footer {
        margin-top: 0.63rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.6rem;
        flex-wrap: wrap;
    }

    .question-actions {
        display: flex;
        gap: 0.35rem;
        flex-wrap: wrap;
    }

    .question-mini-btn {
        border: 1px solid #d4deec;
        background: #f8fafd;
        color: #334155;
        border-radius: 8px;
        padding: 0.3rem 0.5rem;
        font-size: 0.74rem;
        font-weight: 700;
        cursor: pointer;
    }

    .question-mini-btn:hover {
        background: #eef3fa;
    }

    .required-toggle {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        color: #334155;
        font-size: 0.8rem;
        font-weight: 700;
    }

    .required-toggle.disabled {
        opacity: 0.52;
    }

    .required-toggle input {
        margin: 0;
        width: auto;
    }

    .add-question-row {
        display: flex;
        justify-content: center;
        margin-top: 0.2rem;
    }

    .tools-rail {
        position: sticky;
        top: 1rem;
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        background: #fff;
        border: 1px solid #d7dfeb;
        border-radius: 12px;
        padding: 0.36rem;
    }

    .rail-btn {
        border: 1px solid #d5deea;
        background: #f8fafd;
        color: #334155;
        border-radius: 9px;
        width: 44px;
        height: 38px;
        font-size: 0.72rem;
        font-weight: 800;
        cursor: pointer;
    }

    .rail-btn:hover {
        background: #edf2fa;
    }

    .status-box {
        min-height: 1.05rem;
        padding: 0.56rem 0.7rem;
        border: 1px solid #d7dfeb;
        border-radius: 9px;
        font-size: 0.82rem;
        color: #475569;
        background: #fff;
    }

    .status-box:empty {
        display: none;
    }

    .status-box.error {
        color: #b91c1c;
        border-color: #fecdd3;
        background: #fff1f2;
    }

    .status-box.success {
        color: #166534;
        border-color: #bbf7d0;
        background: #f0fdf4;
    }

    .hidden-ui {
        display: none !important;
    }

    @media (max-width: 1100px) {
        .editor-layout {
            grid-template-columns: 1fr;
        }

        .tools-rail {
            position: static;
            flex-direction: row;
            justify-content: center;
            width: fit-content;
            margin: 0 auto;
        }

        .rail-btn {
            width: 48px;
        }
    }

    @media (max-width: 900px) {
        .library-filters,
        .editor-main-info {
            grid-template-columns: 1fr;
        }

        .question-head {
            grid-template-columns: 74px 1fr;
        }

        .question-head select {
            grid-column: 1 / -1;
        }

        .library-actions,
        .editor-actions {
            width: 100%;
        }

        .library-actions .btn,
        .editor-actions .btn {
            flex: 1;
            min-width: 145px;
        }
    }

    @media (max-width: 640px) {
        .forms-shell {
            padding: 0.85rem;
        }

        .template-gallery {
            grid-template-columns: 1fr;
        }

        .question-footer {
            align-items: flex-start;
            flex-direction: column;
        }

        .question-actions {
            width: 100%;
        }

        .question-mini-btn {
            flex: 1;
        }
    }
</style>

<div class="forms-shell">
    <section id="libraryView" class="view active">
        <div class="library-topbar">
            <div>
                <h1 class="library-title">Formularios eventos</h1>
                <p class="library-subtitle">Escolha um formulario salvo ou crie um novo, no estilo galeria.</p>
            </div>
            <div class="library-actions">
                <button type="button" class="btn btn-primary" onclick="startNewTemplate()">+ Novo formulario</button>
            </div>
        </div>

        <div class="library-filters">
            <div class="field-group">
                <label for="templatesSearch">Buscar formulario</label>
                <input type="text" id="templatesSearch" placeholder="Digite parte do nome...">
            </div>
            <div class="field-group">
                <label for="templatesCategoryFilter">Categoria</label>
                <select id="templatesCategoryFilter">
                    <option value="all">Todas</option>
                    <option value="15anos">15 anos</option>
                    <option value="casamento">Casamento</option>
                    <option value="infantil">Infantil</option>
                    <option value="geral">Geral</option>
                </select>
            </div>
        </div>

        <div class="library-summary" id="librarySummary">0 formulario(s)</div>
        <div class="template-gallery" id="templateGallery"></div>
        <div class="library-empty hidden-ui" id="libraryEmpty">Nenhum formulario encontrado para o filtro aplicado.</div>
        <div class="status-box" id="libraryStatus"></div>
    </section>

    <section id="editorView" class="view">
        <div class="editor-shell">
            <div class="editor-topbar">
                <div class="editor-main-info">
                    <div class="field-group">
                        <label for="editorTemplateName">Nome do formulario</label>
                        <input type="text" id="editorTemplateName" placeholder="Ex.: Protocolo 15 anos">
                    </div>
                    <div class="field-group">
                        <label for="editorTemplateCategory">Categoria</label>
                        <select id="editorTemplateCategory">
                            <option value="15anos">15 anos</option>
                            <option value="casamento">Casamento</option>
                            <option value="infantil">Infantil</option>
                            <option value="geral">Geral</option>
                        </select>
                    </div>
                </div>

                <div class="editor-actions">
                    <button type="button" class="btn btn-ghost" onclick="backToLibrary()">← Formularios</button>
                    <button type="button" class="btn btn-ghost" onclick="toggleImportPanel()">&lt;/&gt; Ler codigo fonte</button>
                    <button type="button" class="btn btn-secondary" onclick="addFieldByType('text')">+ Pergunta</button>
                    <button type="button" class="btn btn-secondary" onclick="openPreviewTab()">Prévia</button>
                    <button type="button" class="btn btn-primary" id="btnSaveTemplate" onclick="handleSaveButtonClick(event)">Salvar novo</button>
                    <button type="button" class="btn btn-danger" id="btnArchiveCurrent" onclick="archiveTemplate()">Arquivar</button>
                </div>
            </div>

            <div class="status-box" id="editorStatusTop"></div>

            <div class="import-panel" id="importPanel">
                <h3>Importar por texto/HTML</h3>
                <p>Cole o codigo fonte ou texto do formulario para gerar os campos automaticamente.</p>
                <div class="field-group">
                    <label for="importSource">Fonte</label>
                    <textarea id="importSource" placeholder="Cole aqui o texto ou codigo HTML..."></textarea>
                </div>
                <div class="import-row">
                    <label class="import-check" for="importIncludeNotes">
                        <input type="checkbox" id="importIncludeNotes" checked>
                        Manter instrucoes como texto informativo
                    </label>
                    <div style="display:flex; gap:0.45rem; flex-wrap:wrap;">
                        <button type="button" class="btn btn-secondary" onclick="importFromSource(false)">⚙️ Gerar campos</button>
                        <button type="button" class="btn btn-primary" onclick="importFromSource(true)">⚡ Gerar e salvar</button>
                    </div>
                </div>
            </div>

            <div class="editor-layout">
                <div class="form-canvas">
                    <div class="form-header-card">
                        <h2 class="form-header-title" id="editorFormTitlePreview">Formulario sem titulo</h2>
                        <p class="form-header-subtitle">Edite as perguntas abaixo. Clique em salvar para persistir.</p>
                    </div>

                    <div class="questions-stack" id="questionList"></div>

                    <div class="add-question-row">
                        <button type="button" class="btn btn-secondary" onclick="addFieldByType('text')">+ Adicionar pergunta</button>
                    </div>
                </div>

                <aside class="tools-rail" aria-label="Atalhos de campos">
                    <button type="button" class="rail-btn" title="Pergunta" onclick="addFieldByType('text')">+Q</button>
                    <button type="button" class="rail-btn" title="Secao" onclick="addFieldByType('section')">Sec</button>
                    <button type="button" class="rail-btn" title="Nota" onclick="addFieldByType('note')">Nota</button>
                    <button type="button" class="rail-btn" title="Separador" onclick="addFieldByType('divider')">---</button>
                </aside>
            </div>

            <div class="status-box" id="editorStatus"></div>
        </div>
    </section>
</div>

<script>
const allowedTemplateCategories = ['15anos', 'casamento', 'infantil', 'geral'];
const fieldTypes = ['text', 'textarea', 'yesno', 'select', 'file', 'section', 'divider', 'note'];

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

let searchTerm = '';
let searchCategory = 'all';
let editingTemplateId = null;
let formBuilderFields = [];
let builderDirty = false;
let importPanelOpen = false;
let saveInFlight = false;
let draggingQuestionIndex = null;

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

function getCategoryLabel(category) {
    const key = String(category || 'geral').toLowerCase();
    const map = {
        '15anos': '15 anos',
        'casamento': 'Casamento',
        'infantil': 'Infantil',
        'geral': 'Geral'
    };
    return map[key] || 'Geral';
}

function getFieldTypeLabel(type) {
    const map = {
        text: 'Texto curto',
        textarea: 'Texto longo',
        yesno: 'Sim/Não',
        select: 'Múltipla escolha',
        file: 'Upload',
        section: 'Título de seção',
        divider: 'Separador',
        note: 'Texto informativo'
    };
    return map[type] || type;
}

function generateFieldId() {
    return 'f_' + Math.random().toString(36).slice(2, 10);
}

function normalizeFormSchema(schema) {
    if (!Array.isArray(schema)) return [];
    return schema.map((field) => {
        let type = String(field.type || 'text').trim().toLowerCase();
        if (!fieldTypes.includes(type)) {
            type = 'text';
        }

        const options = Array.isArray(field.options)
            ? field.options.map((value) => String(value).trim()).filter(Boolean)
            : [];

        const neverRequired = ['section', 'divider', 'note'].includes(type);
        return {
            id: String(field.id || generateFieldId()),
            type: type,
            label: String(field.label || '').trim(),
            required: neverRequired ? false : !!field.required,
            options: type === 'select' ? options : []
        };
    }).filter((field) => field.type === 'divider' || field.label !== '');
}

function hasUsefulSchemaFields(schema) {
    if (!Array.isArray(schema)) return false;
    return schema.some((field) => {
        const type = String(field && field.type ? field.type : '').toLowerCase();
        const label = String(field && field.label ? field.label : '').trim();
        return ['text', 'textarea', 'yesno', 'select', 'file'].includes(type) && label !== '';
    });
}

function updateStatus(el, message, type = '') {
    if (!el) return;
    el.className = 'status-box' + (type ? ' ' + type : '');
    el.textContent = message || '';
}

function setLibraryStatus(message, type = '') {
    updateStatus(document.getElementById('libraryStatus'), message, type);
}

function setEditorStatus(message, type = '') {
    updateStatus(document.getElementById('editorStatusTop'), message, type);
    updateStatus(document.getElementById('editorStatus'), message, type);
    if (message && type === 'error') {
        const anchor = document.getElementById('editorStatusTop');
        if (anchor && typeof anchor.scrollIntoView === 'function') {
            anchor.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

function setBuilderDirty(flag) {
    builderDirty = !!flag;
}

function ensureDiscardChanges() {
    if (!builderDirty) {
        return true;
    }
    return confirm('Existem alteracoes nao salvas. Deseja continuar mesmo assim?');
}

function showLibraryView() {
    const library = document.getElementById('libraryView');
    const editor = document.getElementById('editorView');
    if (library) library.classList.add('active');
    if (editor) editor.classList.remove('active');
}

function showEditorView() {
    const library = document.getElementById('libraryView');
    const editor = document.getElementById('editorView');
    if (library) library.classList.remove('active');
    if (editor) editor.classList.add('active');
}

function updateEditorHeader() {
    const nameInput = document.getElementById('editorTemplateName');
    const previewTitle = document.getElementById('editorFormTitlePreview');
    const saveBtn = document.getElementById('btnSaveTemplate');
    const archiveBtn = document.getElementById('btnArchiveCurrent');

    const currentName = nameInput ? String(nameInput.value || '').trim() : '';
    if (previewTitle) {
        previewTitle.textContent = currentName !== '' ? currentName : 'Formulario sem titulo';
    }

    if (saveBtn) {
        saveBtn.textContent = editingTemplateId ? 'Salvar alteracoes' : 'Salvar novo';
    }

    if (archiveBtn) {
        archiveBtn.style.display = editingTemplateId ? 'inline-flex' : 'none';
    }
}

function getFilteredTemplates() {
    const term = String(searchTerm || '').trim().toLowerCase();
    const category = String(searchCategory || 'all').toLowerCase();

    return savedFormTemplates.filter((template) => {
        const nome = String(template.nome || '').toLowerCase();
        const categoria = String(template.categoria || 'geral').toLowerCase();
        const matchTerm = term === '' || nome.includes(term);
        const matchCategory = category === 'all' || category === categoria;
        return matchTerm && matchCategory;
    });
}

function renderLibrary() {
    const gallery = document.getElementById('templateGallery');
    const summary = document.getElementById('librarySummary');
    const empty = document.getElementById('libraryEmpty');
    if (!gallery || !summary || !empty) return;

    const filtered = getFilteredTemplates();
    const total = savedFormTemplates.length;
    summary.textContent = filtered.length === total
        ? `${total} formulario(s)`
        : `${filtered.length} de ${total} formulario(s)`;

    const cards = [];
    cards.push(`
        <button type="button" class="new-template-card" onclick="startNewTemplate()">
            <span class="new-template-plus">+</span>
            <strong>Novo formulario</strong>
            <span style="font-size:0.78rem; color:#64748b;">Criar do zero</span>
        </button>
    `);

    filtered.forEach((template) => {
        const id = Number(template.id || 0);
        if (!id) return;

        const nome = escapeHtml(String(template.nome || 'Modelo sem nome'));
        const categoria = escapeHtml(getCategoryLabel(String(template.categoria || 'geral')));
        const stamp = escapeHtml(formatDate(String(template.updated_at || '')));
        const schema = normalizeFormSchema(Array.isArray(template.schema) ? template.schema : []);
        const fillableCount = schema.filter((field) => ['text', 'textarea', 'yesno', 'select', 'file'].includes(String(field.type || '').toLowerCase())).length;

        cards.push(`
            <article class="template-card" onclick="openTemplateById(${id})">
                <div>
                    <div class="template-card-head">
                        <p class="template-card-title">${nome}</p>
                        <span class="template-card-category">${categoria}</span>
                    </div>
                    <p class="template-card-meta">Atualizado em ${stamp}</p>
                </div>
                <div class="template-card-footer">
                    <span class="template-card-count">${fillableCount} campo(s)</span>
                    <button type="button" class="mini-btn" onclick="archiveTemplateFromCard(event, ${id})">Arquivar</button>
                </div>
            </article>
        `);
    });

    gallery.innerHTML = cards.join('');

    if (filtered.length === 0) {
        empty.classList.remove('hidden-ui');
    } else {
        empty.classList.add('hidden-ui');
    }
}

function defaultLabelByType(type) {
    switch (type) {
        case 'section':
            return 'Titulo da secao';
        case 'note':
            return 'Texto informativo';
        case 'divider':
            return '---';
        default:
            return 'Pergunta sem titulo';
    }
}

function createField(type = 'text') {
    const normalizedType = fieldTypes.includes(type) ? type : 'text';
    return {
        id: generateFieldId(),
        type: normalizedType,
        label: defaultLabelByType(normalizedType),
        required: ['section', 'divider', 'note'].includes(normalizedType) ? false : false,
        options: normalizedType === 'select' ? ['Opcao 1'] : []
    };
}

function renderQuestionList() {
    const list = document.getElementById('questionList');
    if (!list) return;

    if (!Array.isArray(formBuilderFields) || formBuilderFields.length === 0) {
        list.innerHTML = `
            <div class="question-card" style="border-left-color:#94a3b8;">
                <p class="form-header-subtitle" style="margin:0;">Nenhum campo adicionado ainda. Use + Pergunta para iniciar.</p>
            </div>
        `;
        return;
    }

    list.innerHTML = formBuilderFields.map((field, index) => {
        const type = String(field.type || 'text');
        const label = escapeHtml(String(field.label || ''));
        const required = !!field.required;
        const optionsText = escapeHtml(Array.isArray(field.options) ? field.options.join('\n') : '');
        const canRequire = ['text', 'textarea', 'yesno', 'select', 'file'].includes(type);

        const typeOptions = fieldTypes.map((opt) => {
            const selected = opt === type ? ' selected' : '';
            return `<option value="${opt}"${selected}>${escapeHtml(getFieldTypeLabel(opt))}</option>`;
        }).join('');

        let extraHtml = '';
        if (type === 'select') {
            extraHtml = `
                <div class="question-extra">
                    <label style="font-size:0.76rem; font-weight:700; color:#475569; display:block; margin-bottom:0.3rem;">Opcoes (uma por linha)</label>
                    <textarea oninput="setFieldOptions(${index}, this.value)" placeholder="Opcao 1&#10;Opcao 2">${optionsText}</textarea>
                </div>
            `;
        } else if (type === 'divider') {
            extraHtml = `<div class="question-note">Separador visual entre grupos de perguntas.</div>`;
        } else if (type === 'section') {
            extraHtml = `<div class="question-note">Titulo para dividir blocos do formulario.</div>`;
        } else if (type === 'note') {
            extraHtml = `<div class="question-note">Texto exibido para orientacao do cliente (nao preenchivel).</div>`;
        }

        return `
            <article class="question-card" data-index="${index}" draggable="true">
                <div class="question-head">
                    <div class="question-order-cell">
                        <span class="question-index">${index + 1}</span>
                        <button type="button" class="drag-handle" title="Arrastar para reordenar" aria-label="Arrastar pergunta">⋮⋮</button>
                    </div>
                    <input type="text" value="${label}" placeholder="Pergunta sem titulo" oninput="setFieldLabel(${index}, this.value)">
                    <select onchange="setFieldType(${index}, this.value)">${typeOptions}</select>
                </div>
                ${extraHtml}
                <div class="question-footer">
                    <label class="required-toggle ${canRequire ? '' : 'disabled'}">
                        <input type="checkbox" ${required ? 'checked' : ''} ${canRequire ? '' : 'disabled'} onchange="setFieldRequired(${index}, this.checked)">
                        Obrigatoria
                    </label>
                    <div class="question-actions">
                        <button type="button" class="question-mini-btn" onclick="duplicateField(${index})">Duplicar</button>
                        <button type="button" class="question-mini-btn" onclick="removeField(${index})">Excluir</button>
                    </div>
                </div>
            </article>
        `;
    }).join('');
    bindQuestionDragAndDrop();
}

function shouldIgnoreDragStartTarget(target) {
    if (!target || !(target instanceof HTMLElement)) {
        return false;
    }
    if (target.closest('.drag-handle')) {
        return false;
    }
    return !!target.closest('input, select, textarea, button, label');
}

function clearQuestionDragState() {
    draggingQuestionIndex = null;
    const list = document.getElementById('questionList');
    if (!list) return;
    list.querySelectorAll('.question-card.dragging, .question-card.drop-target').forEach((card) => {
        card.classList.remove('dragging');
        card.classList.remove('drop-target');
    });
}

function onQuestionDragStart(event) {
    const card = event.currentTarget;
    if (!card) return;
    if (shouldIgnoreDragStartTarget(event.target)) {
        event.preventDefault();
        return;
    }

    const fromIndex = Number(card.dataset.index || -1);
    if (!Number.isInteger(fromIndex) || fromIndex < 0 || fromIndex >= formBuilderFields.length) {
        event.preventDefault();
        return;
    }

    draggingQuestionIndex = fromIndex;
    card.classList.add('dragging');
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', String(fromIndex));
    }
}

function onQuestionDragOver(event) {
    if (draggingQuestionIndex === null) {
        return;
    }
    event.preventDefault();
    const card = event.currentTarget;
    if (!card) return;

    const overIndex = Number(card.dataset.index || -1);
    if (!Number.isInteger(overIndex) || overIndex === draggingQuestionIndex) {
        return;
    }
    const list = document.getElementById('questionList');
    if (!list) return;
    list.querySelectorAll('.question-card.drop-target').forEach((el) => {
        if (el !== card) {
            el.classList.remove('drop-target');
        }
    });
    card.classList.add('drop-target');
}

function onQuestionDrop(event) {
    event.preventDefault();
    const card = event.currentTarget;
    if (!card) {
        clearQuestionDragState();
        return;
    }

    const toIndex = Number(card.dataset.index || -1);
    const fromIndex = draggingQuestionIndex;
    clearQuestionDragState();

    if (!Number.isInteger(fromIndex) || !Number.isInteger(toIndex)) return;
    if (fromIndex < 0 || toIndex < 0) return;
    if (fromIndex === toIndex) return;
    if (!Array.isArray(formBuilderFields) || formBuilderFields.length < 2) return;

    const moved = formBuilderFields.splice(fromIndex, 1)[0];
    if (!moved) return;
    const insertIndex = fromIndex < toIndex ? (toIndex - 1) : toIndex;
    formBuilderFields.splice(insertIndex, 0, moved);
    setBuilderDirty(true);
    renderQuestionList();
}

function onQuestionDragEnd() {
    clearQuestionDragState();
}

function bindQuestionDragAndDrop() {
    const list = document.getElementById('questionList');
    if (!list) return;

    list.querySelectorAll('.question-card[data-index]').forEach((card) => {
        card.addEventListener('dragstart', onQuestionDragStart);
        card.addEventListener('dragover', onQuestionDragOver);
        card.addEventListener('drop', onQuestionDrop);
        card.addEventListener('dragend', onQuestionDragEnd);
    });
}

function addFieldByType(type = 'text') {
    formBuilderFields.push(createField(type));
    setBuilderDirty(true);
    renderQuestionList();
    setEditorStatus('Campo adicionado.', 'success');
}

function setFieldLabel(index, value) {
    if (!Array.isArray(formBuilderFields) || !formBuilderFields[index]) return;
    formBuilderFields[index].label = String(value || '');
    setBuilderDirty(true);
    if (index === 0) {
        renderQuestionList();
    }
}

function setFieldType(index, type) {
    if (!Array.isArray(formBuilderFields) || !formBuilderFields[index]) return;
    const field = formBuilderFields[index];
    let nextType = String(type || 'text').toLowerCase();
    if (!fieldTypes.includes(nextType)) {
        nextType = 'text';
    }

    field.type = nextType;
    if (nextType === 'select') {
        field.options = Array.isArray(field.options) && field.options.length ? field.options : ['Opcao 1'];
        if ((field.label || '').trim() === '' || field.label === '---') {
            field.label = 'Pergunta sem titulo';
        }
    } else {
        field.options = [];
    }

    if (nextType === 'divider') {
        field.label = '---';
        field.required = false;
    } else if (nextType === 'section' && (field.label || '').trim() === '') {
        field.label = 'Titulo da secao';
        field.required = false;
    } else if (nextType === 'note' && (field.label || '').trim() === '') {
        field.label = 'Texto informativo';
        field.required = false;
    }

    if (['section', 'divider', 'note'].includes(nextType)) {
        field.required = false;
    }

    setBuilderDirty(true);
    renderQuestionList();
}

function setFieldRequired(index, checked) {
    if (!Array.isArray(formBuilderFields) || !formBuilderFields[index]) return;
    const field = formBuilderFields[index];
    if (['section', 'divider', 'note'].includes(String(field.type || ''))) {
        field.required = false;
    } else {
        field.required = !!checked;
    }
    setBuilderDirty(true);
    renderQuestionList();
}

function setFieldOptions(index, rawText) {
    if (!Array.isArray(formBuilderFields) || !formBuilderFields[index]) return;
    const options = String(rawText || '').split('\n').map((item) => item.trim()).filter(Boolean);
    formBuilderFields[index].options = options;
    setBuilderDirty(true);
}

function moveField(index, direction) {
    if (!Array.isArray(formBuilderFields) || index < 0 || index >= formBuilderFields.length) return;
    const target = index + direction;
    if (target < 0 || target >= formBuilderFields.length) return;
    const tmp = formBuilderFields[index];
    formBuilderFields[index] = formBuilderFields[target];
    formBuilderFields[target] = tmp;
    setBuilderDirty(true);
    renderQuestionList();
}

function duplicateField(index) {
    if (!Array.isArray(formBuilderFields) || !formBuilderFields[index]) return;
    const src = formBuilderFields[index];
    const clone = {
        id: generateFieldId(),
        type: String(src.type || 'text'),
        label: String(src.label || ''),
        required: !!src.required,
        options: Array.isArray(src.options) ? src.options.map((value) => String(value)) : []
    };
    formBuilderFields.splice(index + 1, 0, clone);
    setBuilderDirty(true);
    renderQuestionList();
}

function removeField(index) {
    if (!Array.isArray(formBuilderFields) || index < 0 || index >= formBuilderFields.length) return;
    formBuilderFields.splice(index, 1);
    setBuilderDirty(true);
    renderQuestionList();
}

function buildPreviewFieldHtml(field) {
    const type = String(field && field.type ? field.type : 'text').toLowerCase();
    const label = escapeHtml(String(field && field.label ? field.label : ''));
    const required = !!(field && field.required);
    const reqMark = required ? ' <span style="color:#dc2626">*</span>' : '';

    if (type === 'divider') {
        return '<hr class="preview-divider">';
    }
    if (type === 'section') {
        return `<h3 class="preview-section-title">${label}</h3>`;
    }
    if (type === 'note') {
        return `<p class="preview-note">${label}</p>`;
    }

    if (type === 'textarea') {
        return `<div class="preview-field"><label>${label}${reqMark}</label><textarea rows="4" placeholder="Sua resposta..." ${required ? 'required' : ''}></textarea></div>`;
    }
    if (type === 'yesno') {
        return `<div class="preview-field"><label>${label}${reqMark}</label><select ${required ? 'required' : ''}><option value="">Selecione...</option><option value="sim">Sim</option><option value="nao">Não</option></select></div>`;
    }
    if (type === 'select') {
        const options = Array.isArray(field.options) ? field.options : [];
        const optionHtml = options.map((opt) => `<option value="${escapeHtml(String(opt))}">${escapeHtml(String(opt))}</option>`).join('');
        return `<div class="preview-field"><label>${label}${reqMark}</label><select ${required ? 'required' : ''}><option value="">Selecione...</option>${optionHtml}</select></div>`;
    }
    if (type === 'file') {
        return `<div class="preview-field"><label>${label}${reqMark}</label><input type="file" ${required ? 'required' : ''}></div>`;
    }

    return `<div class="preview-field"><label>${label}${reqMark}</label><input type="text" placeholder="Sua resposta..." ${required ? 'required' : ''}></div>`;
}

function openPreviewTab() {
    const schema = normalizeFormSchema(formBuilderFields);
    if (!schema.length) {
        setEditorStatus('Adicione ao menos um campo para visualizar a prévia.', 'error');
        return;
    }

    const nameInput = document.getElementById('editorTemplateName');
    const title = String(nameInput ? nameInput.value : '').trim() || 'Formulario sem titulo';
    const fieldBlocks = schema.map((field) => buildPreviewFieldHtml(field)).join('');

    const win = window.open('', '_blank');
    if (!win) {
        setEditorStatus('Nao foi possivel abrir a aba de previa. Verifique bloqueio de pop-up.', 'error');
        return;
    }

    const previewHtml = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prévia - ${escapeHtml(title)}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #f6f8fc;
            color: #0f172a;
            line-height: 1.5;
        }
        .preview-header {
            background: #1e3a8a;
            color: #fff;
            padding: 1rem 1.2rem;
        }
        .preview-header strong {
            display: block;
            font-size: 1rem;
        }
        .preview-header span {
            opacity: 0.9;
            font-size: 0.86rem;
        }
        .preview-container {
            max-width: 820px;
            margin: 1.35rem auto;
            padding: 0 0.9rem 1.2rem;
        }
        .preview-card {
            background: #fff;
            border: 1px solid #dbe3ef;
            border-radius: 14px;
            padding: 1rem;
            box-shadow: 0 10px 22px rgba(15, 23, 42, 0.06);
        }
        .preview-title {
            margin: 0 0 0.35rem 0;
            font-size: 1.55rem;
            font-weight: 800;
        }
        .preview-sub {
            margin: 0 0 1rem 0;
            font-size: 0.88rem;
            color: #64748b;
        }
        .preview-section-title {
            margin: 1rem 0 0.45rem 0;
            font-size: 1.04rem;
            color: #1d4ed8;
        }
        .preview-note {
            margin: 0.45rem 0;
            font-size: 0.88rem;
            color: #475569;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.62rem 0.7rem;
        }
        .preview-divider {
            border: 0;
            border-top: 1px solid #e2e8f0;
            margin: 0.85rem 0;
        }
        .preview-field {
            margin-bottom: 0.78rem;
        }
        .preview-field label {
            display: block;
            margin-bottom: 0.35rem;
            font-size: 0.86rem;
            font-weight: 700;
            color: #334155;
        }
        .preview-field input,
        .preview-field textarea,
        .preview-field select {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            padding: 0.58rem 0.68rem;
            font-size: 0.88rem;
            background: #fff;
        }
        .preview-submit {
            margin-top: 0.7rem;
            border: 0;
            border-radius: 10px;
            padding: 0.62rem 0.9rem;
            font-size: 0.88rem;
            font-weight: 700;
            background: #3b82f6;
            color: #fff;
            opacity: 0.75;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="preview-header">
        <strong>Prévia de preenchimento</strong>
        <span>Visualização aproximada de como o cliente verá o formulário ao abrir o link.</span>
    </div>
    <div class="preview-container">
        <div class="preview-card">
            <h1 class="preview-title">${escapeHtml(title)}</h1>
            <p class="preview-sub">Esta aba é apenas para validação visual antes do envio ao cliente.</p>
            ${fieldBlocks}
            <button type="button" class="preview-submit" disabled>Enviar formulário (prévia)</button>
        </div>
    </div>
</body>
</html>`;

    win.document.open();
    win.document.write(previewHtml);
    win.document.close();
}

function populateEditorFromTemplate(template) {
    const nameInput = document.getElementById('editorTemplateName');
    const categoryInput = document.getElementById('editorTemplateCategory');

    if (nameInput) {
        nameInput.value = String(template && template.nome ? template.nome : '');
    }
    if (categoryInput) {
        const category = String(template && template.categoria ? template.categoria : 'geral');
        categoryInput.value = allowedTemplateCategories.includes(category) ? category : 'geral';
    }

    formBuilderFields = normalizeFormSchema(Array.isArray(template && template.schema ? template.schema : []) ? template.schema : []);
    renderQuestionList();
    updateEditorHeader();
}

function openTemplateById(templateId) {
    const id = Number(templateId || 0);
    if (!id) return;

    if (!ensureDiscardChanges()) {
        return;
    }

    const template = savedFormTemplates.find((item) => Number(item.id) === id);
    if (!template) {
        setLibraryStatus('Formulario selecionado nao encontrado.', 'error');
        return;
    }

    editingTemplateId = id;
    importPanelOpen = false;
    toggleImportPanel(false);

    populateEditorFromTemplate(template);
    setBuilderDirty(false);
    showEditorView();
    setEditorStatus(`Editando "${String(template.nome || 'formulario')}".`, 'success');
}

function startNewTemplate() {
    if (!ensureDiscardChanges()) {
        return;
    }

    editingTemplateId = null;
    importPanelOpen = false;
    toggleImportPanel(false);

    const nameInput = document.getElementById('editorTemplateName');
    const categoryInput = document.getElementById('editorTemplateCategory');
    if (nameInput) nameInput.value = '';
    if (categoryInput) categoryInput.value = 'geral';

    formBuilderFields = [createField('text')];
    renderQuestionList();
    updateEditorHeader();
    setBuilderDirty(true);

    showEditorView();
    setEditorStatus('Novo formulario criado. Edite e salve.', 'success');
}

function backToLibrary() {
    if (!ensureDiscardChanges()) {
        return;
    }
    showLibraryView();
    setEditorStatus('');
    setLibraryStatus('');
    renderLibrary();
}

function toggleImportPanel(forceOpen = null) {
    const panel = document.getElementById('importPanel');
    if (!panel) return;

    if (typeof forceOpen === 'boolean') {
        importPanelOpen = forceOpen;
    } else {
        importPanelOpen = !importPanelOpen;
    }

    panel.classList.toggle('open', importPanelOpen);
}

async function importFromSource(autoSave = false) {
    const sourceEl = document.getElementById('importSource');
    const includeNotesEl = document.getElementById('importIncludeNotes');
    const sourceText = sourceEl ? String(sourceEl.value || '').trim() : '';

    if (!sourceText) {
        setEditorStatus('Cole o texto ou codigo HTML para importar.', 'error');
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
            setEditorStatus(data.error || 'Erro ao importar campos.', 'error');
            return;
        }

        const schema = normalizeFormSchema(Array.isArray(data.schema) ? data.schema : []);
        if (!schema.length || !hasUsefulSchemaFields(schema)) {
            setEditorStatus('Nao foi possivel gerar perguntas preenchiveis.', 'error');
            return;
        }

        formBuilderFields = schema;
        renderQuestionList();
        setBuilderDirty(true);

        const fillable = schema.filter((field) => ['text', 'textarea', 'yesno', 'select', 'file'].includes(String(field.type || '').toLowerCase())).length;
        setEditorStatus(`Importacao concluida: ${schema.length} item(ns), ${fillable} campo(s) preenchivel(is).`, 'success');

        if (autoSave) {
            await saveCurrentTemplate();
        }
    } catch (err) {
        setEditorStatus('Erro ao importar: ' + (err.message || err), 'error');
    }
}

function validateCurrentEditor() {
    const nameInput = document.getElementById('editorTemplateName');
    const categoryInput = document.getElementById('editorTemplateCategory');
    const templateName = String(nameInput ? nameInput.value : '').trim();
    const templateCategory = String(categoryInput ? categoryInput.value : 'geral') || 'geral';

    if (templateName.length < 3) {
        setEditorStatus('Informe um nome com pelo menos 3 caracteres.', 'error');
        return null;
    }
    if (!allowedTemplateCategories.includes(templateCategory)) {
        setEditorStatus('Categoria invalida.', 'error');
        return null;
    }

    const normalized = normalizeFormSchema(formBuilderFields);
    if (!normalized.length || !hasUsefulSchemaFields(normalized)) {
        setEditorStatus('Adicione ao menos um campo preenchivel antes de salvar.', 'error');
        return null;
    }

    return {
        templateName,
        templateCategory,
        schema: normalized
    };
}

async function saveCurrentTemplate() {
    if (saveInFlight) {
        return;
    }

    const payload = validateCurrentEditor();
    if (!payload) {
        return;
    }

    try {
        saveInFlight = true;
        const saveBtn = document.getElementById('btnSaveTemplate');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = 'Salvando...';
        }
        setEditorStatus('Salvando formulario...', '');

        const formData = new FormData();
        formData.append('action', 'salvar_template_form');
        formData.append('template_name', payload.templateName);
        formData.append('template_category', payload.templateCategory);
        formData.append('schema_json', JSON.stringify(payload.schema));
        if (editingTemplateId) {
            formData.append('template_id', String(editingTemplateId));
        }

        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const raw = await resp.text();
        let data = null;
        try {
            data = JSON.parse(raw);
        } catch (parseErr) {
            throw new Error('Resposta invalida do servidor ao salvar.');
        }

        if (!data.ok || !data.template) {
            setEditorStatus(data.error || 'Erro ao salvar formulario.', 'error');
            return;
        }

        editingTemplateId = Number(data.template.id || 0) || null;
        setBuilderDirty(false);
        updateEditorHeader();
        await refreshTemplates(false);
        setEditorStatus(editingTemplateId ? 'Formulario salvo com sucesso.' : 'Formulario salvo.', 'success');
    } catch (err) {
        setEditorStatus('Erro ao salvar formulario: ' + (err.message || err), 'error');
    } finally {
        saveInFlight = false;
        const saveBtn = document.getElementById('btnSaveTemplate');
        if (saveBtn) {
            saveBtn.disabled = false;
        }
        updateEditorHeader();
    }
}

function handleSaveButtonClick(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    saveCurrentTemplate();
}

async function archiveTemplate(templateId = null) {
    const targetId = Number(templateId || editingTemplateId || 0);
    if (!targetId) {
        setEditorStatus('Nenhum formulario selecionado para arquivar.', 'error');
        return;
    }

    const template = savedFormTemplates.find((item) => Number(item.id) === targetId);
    const nome = String(template && template.nome ? template.nome : 'formulario');
    if (!confirm(`Arquivar "${nome}"?`)) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'arquivar_template_form');
        formData.append('template_id', String(targetId));

        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (!data.ok) {
            const msg = data.error || 'Erro ao arquivar formulario.';
            if (document.getElementById('editorView').classList.contains('active')) {
                setEditorStatus(msg, 'error');
            } else {
                setLibraryStatus(msg, 'error');
            }
            return;
        }

        if (Number(editingTemplateId || 0) === targetId) {
            editingTemplateId = null;
            formBuilderFields = [];
            setBuilderDirty(false);
            showLibraryView();
            setEditorStatus('');
        }

        await refreshTemplates(false);
        setLibraryStatus('Formulario arquivado com sucesso.', 'success');
    } catch (err) {
        setLibraryStatus('Erro ao arquivar formulario: ' + (err.message || err), 'error');
    }
}

function archiveTemplateFromCard(event, templateId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    archiveTemplate(templateId);
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
            setLibraryStatus(data.error || 'Nao foi possivel garantir o protocolo 15 anos.', 'error');
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

        renderLibrary();
        setLibraryStatus('Template "protocolo 15 anos" garantido.', 'success');
    } catch (err) {
        setLibraryStatus('Erro ao garantir template: ' + (err.message || err), 'error');
    }
}

async function refreshTemplates(showMessage = false) {
    try {
        const formData = new FormData();
        formData.append('action', 'listar_templates_form');

        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (!data.ok) {
            throw new Error(data.error || 'Erro ao listar formularios');
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

        renderLibrary();

        if (editingTemplateId) {
            const updated = savedFormTemplates.find((item) => Number(item.id) === Number(editingTemplateId));
            if (updated) {
                populateEditorFromTemplate({
                    ...updated,
                    schema: formBuilderFields
                });
                updateEditorHeader();
            } else if (!builderDirty) {
                showLibraryView();
            }
        }

        if (showMessage) {
            setLibraryStatus('Lista atualizada.', 'success');
        }
    } catch (err) {
        setLibraryStatus(err.message || 'Erro ao carregar formularios.', 'error');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('templatesSearch');
    const categoryInput = document.getElementById('templatesCategoryFilter');
    const nameInput = document.getElementById('editorTemplateName');
    const categoryEditor = document.getElementById('editorTemplateCategory');

    if (searchInput) {
        searchInput.addEventListener('input', (event) => {
            searchTerm = String(event.target.value || '');
            renderLibrary();
        });
    }

    if (categoryInput) {
        categoryInput.addEventListener('change', (event) => {
            searchCategory = String(event.target.value || 'all');
            renderLibrary();
        });
    }

    if (nameInput) {
        nameInput.addEventListener('input', () => {
            setBuilderDirty(true);
            updateEditorHeader();
        });
    }

    if (categoryEditor) {
        categoryEditor.addEventListener('change', () => {
            setBuilderDirty(true);
            updateEditorHeader();
        });
    }

    renderLibrary();
    renderQuestionList();
    updateEditorHeader();
    refreshTemplates(false);
});

window.addEventListener('pageshow', function (event) {
    if (event && event.persisted) {
        refreshTemplates(false);
    }
});
</script>

<?php endSidebar(); ?>
