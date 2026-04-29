<?php
/**
 * checklists_operacionais.php
 * Biblioteca interna de modelos de checklists operacionais.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/checklists_operacionais_helper.php';

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
        case 'listar_checklists_operacionais':
            echo json_encode([
                'ok' => true,
                'models' => checklists_operacionais_listar($pdo, true),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;

        case 'salvar_checklist_operacional':
            $model_id = (int)($_POST['model_id'] ?? 0);
            $payload_json = (string)($_POST['payload_json'] ?? '{}');
            $payload = json_decode($payload_json, true);
            if (!is_array($payload)) {
                echo json_encode(['ok' => false, 'error' => 'Payload invalido.']);
                exit;
            }

            echo json_encode(
                checklists_operacionais_salvar($pdo, $payload, $user_id, $model_id > 0 ? $model_id : null),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;

        case 'duplicar_checklist_operacional':
            $model_id = (int)($_POST['model_id'] ?? 0);
            echo json_encode(
                checklists_operacionais_duplicar($pdo, $model_id, $user_id),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;

        case 'toggle_checklist_operacional':
            $model_id = (int)($_POST['model_id'] ?? 0);
            $ativo = ((string)($_POST['ativo'] ?? '1')) === '1';
            echo json_encode(
                checklists_operacionais_toggle_ativo($pdo, $model_id, $ativo),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;

        default:
            echo json_encode(['ok' => false, 'error' => 'Acao invalida.']);
            exit;
    }
}

$models = checklists_operacionais_listar($pdo, true);
$cargo_options = checklists_operacionais_listar_cargos($pdo);
$unidade_options = checklists_operacionais_listar_unidades($pdo);
$pacote_options = checklists_operacionais_listar_pacotes($pdo);

includeSidebar('Checklists Operacionais');
?>

<style>
    .ops-shell {
        max-width: 1520px;
        margin: 0 auto;
        padding: 1.15rem 1.15rem 1.5rem;
        background: #f3f6fb;
        min-height: calc(100vh - 90px);
    }

    .view { display: none; }
    .view.active { display: block; }

    .btn {
        border: 1px solid transparent;
        border-radius: 10px;
        padding: 0.58rem 0.92rem;
        font-size: 0.86rem;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.38rem;
        text-decoration: none;
    }

    .btn-primary { background: #3252cc; color: #fff; }
    .btn-primary:hover { background: #2843ab; }
    .btn-secondary { background: #eef2f8; color: #334155; border-color: #d1d9e6; }
    .btn-secondary:hover { background: #e5ebf4; }
    .btn-ghost { background: #fff; color: #334155; border-color: #d2dbe8; }
    .btn-ghost:hover { background: #f8fafd; }
    .btn-danger { background: #fff1f2; color: #b91c1c; border-color: #fecdd3; }
    .btn-danger:hover { background: #ffe4e6; }

    .topbar {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 0.95rem;
    }

    .title {
        margin: 0;
        font-size: 1.52rem;
        color: #0f172a;
    }

    .subtitle {
        margin: 0.35rem 0 0;
        color: #64748b;
        font-size: 0.9rem;
    }

    .filters-grid,
    .editor-main-grid {
        display: grid;
        gap: 0.65rem;
    }

    .filters-grid {
        grid-template-columns: minmax(240px, 1fr) minmax(160px, 200px);
        margin-bottom: 0.85rem;
    }

    .editor-main-grid {
        grid-template-columns: minmax(220px, 1.2fr) minmax(160px, 180px);
        flex: 1;
        min-width: 300px;
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

    .summary {
        margin-bottom: 0.75rem;
        color: #475569;
        font-size: 0.82rem;
        font-weight: 700;
    }

    .gallery {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
        gap: 0.85rem;
    }

    .new-card,
    .model-card {
        border: 1px solid #d8e1ef;
        border-radius: 14px;
        background: #fff;
        min-height: 210px;
        padding: 0.9rem;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .new-card:hover,
    .model-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 22px rgba(37, 65, 173, 0.11);
    }

    .new-card {
        border-style: dashed;
        border-width: 2px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.7rem;
        cursor: pointer;
        background: #fbfcff;
        color: #334155;
    }

    .new-plus {
        width: 46px;
        height: 46px;
        border-radius: 50%;
        background: #e9edfa;
        color: #3151cb;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.7rem;
        font-weight: 700;
        line-height: 1;
    }

    .model-card {
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        border-top: 5px solid #3252cc;
        cursor: pointer;
    }

    .model-card.inactive {
        border-top-color: #94a3b8;
        opacity: 0.88;
    }

    .model-card-head {
        display: flex;
        justify-content: space-between;
        gap: 0.6rem;
        align-items: flex-start;
    }

    .model-card-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: #0f172a;
        line-height: 1.3;
    }

    .badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.18rem 0.48rem;
        font-size: 0.73rem;
        font-weight: 700;
    }

    .badge-blue { background: #dbeafe; color: #1d4ed8; }
    .badge-slate { background: #e2e8f0; color: #334155; }
    .badge-green { background: #dcfce7; color: #166534; }

    .model-meta {
        margin-top: 0.7rem;
        color: #64748b;
        font-size: 0.8rem;
        line-height: 1.4;
        display: grid;
        gap: 0.25rem;
    }

    .model-footer {
        margin-top: 0.8rem;
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
    }

    .model-counts {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        color: #334155;
        font-size: 0.78rem;
        font-weight: 700;
    }

    .card-actions {
        display: flex;
        gap: 0.35rem;
        flex-wrap: wrap;
    }

    .mini-btn {
        border: 1px solid #d4deec;
        background: #f8fafd;
        color: #334155;
        border-radius: 8px;
        padding: 0.28rem 0.46rem;
        font-size: 0.73rem;
        font-weight: 700;
        cursor: pointer;
    }

    .mini-btn:hover { background: #eef3fa; }

    .status-box {
        min-height: 1.05rem;
        padding: 0.56rem 0.7rem;
        border: 1px solid #d7dfeb;
        border-radius: 9px;
        font-size: 0.82rem;
        color: #475569;
        background: #fff;
    }

    .status-box:empty { display: none; }
    .status-box.error { color: #b91c1c; border-color: #fecdd3; background: #fff1f2; }
    .status-box.success { color: #166534; border-color: #bbf7d0; background: #f0fdf4; }

    .empty-box {
        padding: 1rem;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        color: #64748b;
        background: #fff;
        font-size: 0.84rem;
    }

    .hidden-ui { display: none !important; }

    .editor-shell { display: flex; flex-direction: column; gap: 0.82rem; }

    .editor-topbar,
    .panel-card {
        background: #fff;
        border: 1px solid #d7dfeb;
        border-radius: 14px;
        padding: 0.82rem;
    }

    .editor-topbar {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 0.8rem;
        flex-wrap: wrap;
    }

    .editor-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .split-grid {
        display: grid;
        grid-template-columns: minmax(300px, 380px) minmax(0, 1fr) 62px;
        gap: 0.9rem;
        align-items: start;
    }

    .panel-card h3 {
        margin: 0 0 0.65rem 0;
        font-size: 0.98rem;
        color: #0f172a;
    }

    .panel-help {
        margin: -0.22rem 0 0.7rem 0;
        color: #64748b;
        font-size: 0.82rem;
    }

    .checks-grid {
        display: grid;
        gap: 0.4rem;
    }

    .check-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.45rem 0.5rem;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #f8fafc;
        font-size: 0.84rem;
        color: #334155;
    }

    .check-item input { width: auto; margin: 0; }

    .block-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        margin-top: 0.65rem;
    }

    .block-row {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 0.45rem;
        align-items: center;
    }

    .block-actions {
        display: flex;
        gap: 0.28rem;
    }

    .inline-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
    }

    .form-canvas {
        width: 100%;
        max-width: 900px;
        margin: 0 auto;
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
        margin: 0.45rem 0 0;
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

    .question-card.dragging { opacity: 0.62; border-color: #93c5fd; }
    .question-card.drop-target { border-color: #60a5fa; box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2); }

    .question-head {
        display: grid;
        grid-template-columns: 74px minmax(0, 1fr) 180px 180px;
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
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .drag-handle:hover { background: #eef2ff; border-color: #c7d2fe; color: #1e40af; }

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

    .question-extra { margin-top: 0.56rem; display: grid; gap: 0.5rem; }
    .question-extra textarea { min-height: 84px; resize: vertical; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

    .question-note {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 9px;
        padding: 0.5rem 0.58rem;
        color: #64748b;
        font-size: 0.79rem;
    }

    .toggles-row,
    .question-footer,
    .question-actions,
    .tools-rail {
        display: flex;
        gap: 0.35rem;
        flex-wrap: wrap;
    }

    .question-footer {
        margin-top: 0.63rem;
        justify-content: space-between;
        align-items: center;
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

    .toggle-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        color: #334155;
        font-size: 0.8rem;
        font-weight: 700;
        padding: 0.28rem 0.42rem;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }

    .toggle-chip.disabled { opacity: 0.52; }
    .toggle-chip input { margin: 0; width: auto; }

    .add-row { display: flex; justify-content: center; }

    .tools-rail {
        position: sticky;
        top: 1rem;
        flex-direction: column;
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

    .rail-btn:hover { background: #edf2fa; }

    @media (max-width: 1220px) {
        .split-grid { grid-template-columns: 1fr; }
        .tools-rail {
            position: static;
            flex-direction: row;
            justify-content: center;
            width: fit-content;
            margin: 0 auto;
        }
    }

    @media (max-width: 980px) {
        .filters-grid,
        .editor-main-grid { grid-template-columns: 1fr; }
        .question-head { grid-template-columns: 74px 1fr; }
        .question-head select { grid-column: 1 / -1; }
        .editor-actions .btn { flex: 1; min-width: 145px; }
    }

    @media (max-width: 640px) {
        .ops-shell { padding: 0.85rem; }
        .gallery { grid-template-columns: 1fr; }
        .question-footer { align-items: flex-start; flex-direction: column; }
        .question-actions,
        .editor-actions { width: 100%; }
        .question-mini-btn { flex: 1; }
    }
</style>

<div class="ops-shell">
    <section id="libraryView" class="view active">
        <div class="topbar">
            <div>
                <h1 class="title">Checklists Operacionais</h1>
                <p class="subtitle">Modelos internos por cargo, unidade, pacote e momento do evento, com campos dinâmicos em schema JSON.</p>
            </div>
            <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <button type="button" class="btn btn-primary" onclick="startNewModel()">+ Novo checklist</button>
            </div>
        </div>

        <div class="filters-grid">
            <div class="field-group">
                <label for="modelSearch">Buscar checklist</label>
                <input type="text" id="modelSearch" placeholder="Digite parte do nome...">
            </div>
            <div class="field-group">
                <label for="modelStatusFilter">Status</label>
                <select id="modelStatusFilter">
                    <option value="all">Todos</option>
                    <option value="active">Ativos</option>
                    <option value="inactive">Inativos</option>
                </select>
            </div>
        </div>

        <div class="summary" id="librarySummary">0 checklist(s)</div>
        <div class="gallery" id="modelsGallery"></div>
        <div class="empty-box hidden-ui" id="libraryEmpty">Nenhum checklist encontrado para o filtro aplicado.</div>
        <div class="status-box" id="libraryStatus"></div>
    </section>

    <section id="editorView" class="view">
        <div class="editor-shell">
            <div class="editor-topbar">
                <div class="editor-main-grid">
                    <div class="field-group">
                        <label for="editorModelName">Nome do checklist</label>
                        <input type="text" id="editorModelName" placeholder="Ex.: Recepção operacional padrão">
                    </div>
                    <div class="field-group">
                        <label for="editorModelStatus">Status</label>
                        <select id="editorModelStatus">
                            <option value="1">Ativo</option>
                            <option value="0">Inativo</option>
                        </select>
                    </div>
                </div>

                <div class="editor-actions">
                    <button type="button" class="btn btn-ghost" onclick="backToLibrary()">← Biblioteca</button>
                    <button type="button" class="btn btn-secondary" onclick="addFieldByType('text')">+ Pergunta</button>
                    <button type="button" class="btn btn-secondary" onclick="openPreviewTab()">Prévia</button>
                    <button type="button" class="btn btn-primary" id="btnSaveModel" onclick="saveCurrentModel()">Salvar checklist</button>
                </div>
            </div>

            <div class="status-box" id="editorStatusTop"></div>

            <div class="split-grid">
                <aside style="display:flex; flex-direction:column; gap:0.82rem;">
                    <div class="panel-card">
                        <h3>Aplicabilidade</h3>
                        <p class="panel-help">Selecione os cargos, unidades e pacotes em que este checklist deve ser usado.</p>

                        <div class="field-group">
                            <label>Cargos</label>
                            <div id="cargoChecklist" class="checks-grid"></div>
                        </div>

                        <div class="field-group" style="margin-top:0.75rem;">
                            <label>Unidades</label>
                            <div id="unidadeChecklist" class="checks-grid"></div>
                        </div>

                        <div class="field-group" style="margin-top:0.75rem;">
                            <label>Pacotes</label>
                            <div id="pacoteChecklist" class="checks-grid"></div>
                        </div>
                    </div>

                    <div class="panel-card">
                        <h3>Blocos por Momento</h3>
                        <p class="panel-help">Organize as perguntas por etapa do evento. Cada campo pode ser vinculado a um bloco.</p>
                        <div class="inline-buttons">
                            <button type="button" class="mini-btn" onclick="applyDefaultBlocks()">Usar blocos padrão</button>
                            <button type="button" class="mini-btn" onclick="addBlockPrompt()">+ Novo bloco</button>
                        </div>
                        <div class="block-list" id="blockList"></div>
                    </div>
                </aside>

                <div class="form-canvas">
                    <div class="form-header-card">
                        <h2 class="form-header-title" id="editorHeaderTitle">Checklist sem nome</h2>
                        <p class="form-header-subtitle">Monte as perguntas e reordene por arraste. Use os blocos para separar os momentos do evento.</p>
                    </div>

                    <div class="questions-stack" id="questionList"></div>

                    <div class="add-row">
                        <button type="button" class="btn btn-secondary" onclick="addFieldByType('text')">+ Adicionar pergunta</button>
                    </div>
                </div>

                <aside class="tools-rail" aria-label="Atalhos">
                    <button type="button" class="rail-btn" title="Texto curto" onclick="addFieldByType('text')">Txt</button>
                    <button type="button" class="rail-btn" title="Texto longo" onclick="addFieldByType('textarea')">Txt+</button>
                    <button type="button" class="rail-btn" title="Sim/Não" onclick="addFieldByType('yesno')">S/N</button>
                    <button type="button" class="rail-btn" title="Seleção" onclick="addFieldByType('select')">Sel</button>
                    <button type="button" class="rail-btn" title="Número" onclick="addFieldByType('number')">123</button>
                    <button type="button" class="rail-btn" title="Foto" onclick="addFieldByType('photo')">Foto</button>
                    <button type="button" class="rail-btn" title="Texto com foto" onclick="addFieldByType('text_photo')">T+F</button>
                    <button type="button" class="rail-btn" title="Informativo" onclick="addFieldByType('note')">Info</button>
                    <button type="button" class="rail-btn" title="Separador" onclick="addFieldByType('divider')">---</button>
                </aside>
            </div>

            <div class="status-box" id="editorStatus"></div>
        </div>
    </section>
</div>

<script>
const cargoOptions = <?= json_encode(array_map(static function(array $row): array {
    return ['id' => (int)($row['id'] ?? 0), 'nome' => (string)($row['nome'] ?? '')];
}, $cargo_options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const unidadeOptions = <?= json_encode(array_map(static function(array $row): array {
    return ['nome' => (string)($row['nome'] ?? '')];
}, $unidade_options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const pacoteOptions = <?= json_encode(array_map(static function(array $row): array {
    return ['id' => (int)($row['id'] ?? 0), 'nome' => (string)($row['nome'] ?? '')];
}, $pacote_options), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const allowedFieldTypes = ['text', 'textarea', 'yesno', 'select', 'number', 'photo', 'text_photo', 'note', 'divider'];

let savedModels = <?= json_encode(array_map(static function(array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'nome' => (string)($row['nome'] ?? ''),
        'ativo' => !empty($row['ativo']),
        'cargos' => is_array($row['cargos'] ?? null) ? array_values($row['cargos']) : [],
        'unidades' => is_array($row['unidades'] ?? null) ? array_values($row['unidades']) : [],
        'pacotes' => is_array($row['pacotes'] ?? null) ? array_map('intval', $row['pacotes']) : [],
        'blocos' => is_array($row['blocos'] ?? null) ? array_values($row['blocos']) : [],
        'schema' => is_array($row['schema'] ?? null) ? array_values($row['schema']) : [],
        'updated_at' => (string)($row['updated_at'] ?? ''),
    ];
}, $models), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

let searchTerm = '';
let statusFilter = 'all';
let editingModelId = null;
let builderDirty = false;
let saveInFlight = false;
let draggingQuestionIndex = null;
let currentBlocks = [];
let currentFields = [];

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
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
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return '-';
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

function fieldTypeLabel(type) {
    const map = {
        text: 'Texto curto',
        textarea: 'Texto longo',
        yesno: 'Sim/Não',
        select: 'Múltipla escolha',
        number: 'Número',
        photo: 'Foto',
        text_photo: 'Texto com foto',
        note: 'Informativo',
        divider: 'Separador'
    };
    return map[type] || type;
}

function generateId(prefix = 'cof') {
    return prefix + '_' + Math.random().toString(36).slice(2, 10);
}

function defaultBlocks() {
    return ['Antes do evento', 'Início', 'Durante', 'Encerramento', 'Pós-evento'].map((label, index) => ({
        id: generateId('cob'),
        label,
        order: index + 1
    }));
}

function defaultLabelByType(type) {
    const map = {
        text: 'Pergunta curta',
        textarea: 'Pergunta longa',
        yesno: 'Pergunta de sim/não',
        select: 'Escolha uma opção',
        number: 'Informe um número',
        photo: 'Anexe uma foto',
        text_photo: 'Descreva e anexe foto',
        note: 'Texto informativo',
        divider: '---'
    };
    return map[type] || 'Pergunta sem título';
}

function normalizeBlocks(blocks) {
    if (!Array.isArray(blocks)) return [];
    let order = 1;
    return blocks.map((block) => ({
        id: String(block && block.id ? block.id : generateId('cob')),
        label: String(block && block.label ? block.label : '').trim(),
        order: order++
    })).filter((block) => block.label !== '');
}

function normalizeFields(fields, blocks = currentBlocks) {
    const blockIds = new Set((Array.isArray(blocks) ? blocks : []).map((block) => String(block.id || '')));
    return (Array.isArray(fields) ? fields : []).map((field) => {
        let type = String(field && field.type ? field.type : 'text').trim().toLowerCase();
        if (!allowedFieldTypes.includes(type)) type = 'text';

        const options = Array.isArray(field && field.options)
            ? field.options.map((opt) => String(opt).trim()).filter(Boolean)
            : [];
        const infoOnly = !!(field && field.info_only) || ['note', 'divider'].includes(type);
        const requirePhoto = !!(field && field.require_photo) || ['photo', 'text_photo'].includes(type);
        const blockIdRaw = String(field && field.block_id ? field.block_id : '').trim();

        return {
            id: String(field && field.id ? field.id : generateId('cof')),
            type,
            label: String(field && field.label ? field.label : '').trim(),
            required: infoOnly ? false : !!(field && field.required),
            options: type === 'select' ? options : [],
            info_only: infoOnly,
            require_photo: requirePhoto,
            block_id: blockIds.has(blockIdRaw) ? blockIdRaw : '',
            content_html: type === 'note' ? String(field && field.content_html ? field.content_html : '').trim() : ''
        };
    }).filter((field) => field.type === 'divider' || field.label !== '' || (field.type === 'note' && field.content_html !== ''));
}

function hasUsefulFields(fields) {
    return (Array.isArray(fields) ? fields : []).some((field) => {
        const type = String(field && field.type ? field.type : '').toLowerCase();
        const label = String(field && field.label ? field.label : '').trim();
        return ['text', 'textarea', 'yesno', 'select', 'number', 'photo', 'text_photo'].includes(type) && label !== '';
    });
}

function getFieldCount(model) {
    return normalizeFields(model && model.schema ? model.schema : [], model && model.blocos ? model.blocos : []).filter((field) => {
        return ['text', 'textarea', 'yesno', 'select', 'number', 'photo', 'text_photo'].includes(String(field.type || ''));
    }).length;
}

function setBuilderDirty(flag) {
    builderDirty = !!flag;
}

function ensureDiscardChanges() {
    if (!builderDirty) return true;
    return confirm('Existem alterações não salvas. Deseja continuar mesmo assim?');
}

function showLibraryView() {
    document.getElementById('libraryView')?.classList.add('active');
    document.getElementById('editorView')?.classList.remove('active');
}

function showEditorView() {
    document.getElementById('libraryView')?.classList.remove('active');
    document.getElementById('editorView')?.classList.add('active');
}

function updateEditorHeader() {
    const name = String(document.getElementById('editorModelName')?.value || '').trim();
    const titleEl = document.getElementById('editorHeaderTitle');
    const saveBtn = document.getElementById('btnSaveModel');
    if (titleEl) titleEl.textContent = name !== '' ? name : 'Checklist sem nome';
    if (saveBtn) saveBtn.textContent = editingModelId ? 'Salvar alterações' : 'Salvar checklist';
}

function getSelectedValues(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return [];
    return Array.from(container.querySelectorAll('input[type="checkbox"]:checked')).map((input) => String(input.value || ''));
}

function renderCheckOptions(containerId, options, selectedValues, valueKey = 'nome') {
    const container = document.getElementById(containerId);
    if (!container) return;
    const selectedSet = new Set((selectedValues || []).map((value) => String(value)));
    if (!Array.isArray(options) || options.length === 0) {
        container.innerHTML = '<div class="empty-box">Nenhuma opção cadastrada.</div>';
        return;
    }

    container.innerHTML = options.map((option) => {
        const value = String(option && option[valueKey] !== undefined ? option[valueKey] : option.id || '');
        const label = String(option && option.nome ? option.nome : value);
        return `
            <label class="check-item">
                <input type="checkbox" value="${escapeHtml(value)}" ${selectedSet.has(value) ? 'checked' : ''} onchange="setBuilderDirty(true)">
                <span>${escapeHtml(label)}</span>
            </label>
        `;
    }).join('');
}

function renderBlockList() {
    const list = document.getElementById('blockList');
    if (!list) return;

    if (!Array.isArray(currentBlocks) || currentBlocks.length === 0) {
        list.innerHTML = '<div class="empty-box">Nenhum bloco criado ainda. Use os blocos padrão ou adicione manualmente.</div>';
        return;
    }

    list.innerHTML = currentBlocks.map((block, index) => `
        <div class="block-row">
            <input type="text" value="${escapeHtml(block.label || '')}" placeholder="Nome do bloco" oninput="updateBlockLabel(${index}, this.value)">
            <div class="block-actions">
                <button type="button" class="question-mini-btn" onclick="moveBlock(${index}, -1)" ${index === 0 ? 'disabled' : ''}>↑</button>
                <button type="button" class="question-mini-btn" onclick="moveBlock(${index}, 1)" ${index === currentBlocks.length - 1 ? 'disabled' : ''}>↓</button>
                <button type="button" class="question-mini-btn" onclick="removeBlock(${index})">Excluir</button>
            </div>
        </div>
    `).join('');
}

function createField(type = 'text') {
    const normalizedType = allowedFieldTypes.includes(type) ? type : 'text';
    const firstBlockId = currentBlocks.length > 0 ? String(currentBlocks[0].id || '') : '';
    return {
        id: generateId('cof'),
        type: normalizedType,
        label: defaultLabelByType(normalizedType),
        required: false,
        options: normalizedType === 'select' ? ['Opção 1'] : [],
        info_only: ['note', 'divider'].includes(normalizedType),
        require_photo: ['photo', 'text_photo'].includes(normalizedType),
        block_id: ['divider', 'note'].includes(normalizedType) ? '' : firstBlockId,
        content_html: normalizedType === 'note' ? 'Texto informativo' : ''
    };
}

function renderQuestionList() {
    const list = document.getElementById('questionList');
    if (!list) return;

    if (!Array.isArray(currentFields) || currentFields.length === 0) {
        list.innerHTML = `
            <div class="question-card" style="border-left-color:#94a3b8;">
                <p class="form-header-subtitle" style="margin:0;">Nenhum campo adicionado ainda. Use + Pergunta para iniciar.</p>
            </div>
        `;
        return;
    }

    const blockOptions = ['<option value="">Sem bloco específico</option>'].concat(currentBlocks.map((block) => (
        `<option value="${escapeHtml(String(block.id || ''))}">${escapeHtml(String(block.label || ''))}</option>`
    )));

    list.innerHTML = currentFields.map((field, index) => {
        const type = String(field.type || 'text');
        const canRequire = !field.info_only && !['divider', 'note'].includes(type);
        const canRequirePhoto = !['divider', 'note', 'photo', 'text_photo'].includes(type);
        const typeOptions = allowedFieldTypes.map((opt) => {
            const selected = opt === type ? ' selected' : '';
            return `<option value="${opt}"${selected}>${escapeHtml(fieldTypeLabel(opt))}</option>`;
        }).join('');

        const blockSelectHtml = blockOptions.map((opt) => {
            const valueMatch = opt.match(/value="([^"]*)"/);
            const value = valueMatch ? valueMatch[1] : '';
            if (value === escapeHtml(String(field.block_id || ''))) {
                return opt.replace('>', ' selected>');
            }
            return opt;
        }).join('');

        let extraHtml = '';
        if (type === 'select') {
            extraHtml = `
                <div class="question-extra">
                    <label style="font-size:0.76rem; font-weight:700; color:#475569;">Opções (uma por linha)</label>
                    <textarea oninput="setFieldOptions(${index}, this.value)" placeholder="Opção 1&#10;Opção 2">${escapeHtml(Array.isArray(field.options) ? field.options.join('\n') : '')}</textarea>
                </div>
            `;
        } else if (type === 'note') {
            extraHtml = `
                <div class="question-extra">
                    <label style="font-size:0.76rem; font-weight:700; color:#475569;">Conteúdo informativo</label>
                    <textarea oninput="setFieldContent(${index}, this.value)" placeholder="Texto exibido para a equipe">${escapeHtml(String(field.content_html || ''))}</textarea>
                </div>
            `;
        } else if (type === 'divider') {
            extraHtml = `<div class="question-note">Separador visual entre grupos do checklist.</div>`;
        } else if (type === 'photo') {
            extraHtml = `<div class="question-note">Campo focado em evidência visual.</div>`;
        } else if (type === 'text_photo') {
            extraHtml = `<div class="question-note">Resposta composta por texto e foto no mesmo item.</div>`;
        }

        return `
            <article class="question-card" data-index="${index}" draggable="true">
                <div class="question-head">
                    <div class="question-order-cell">
                        <span class="question-index">${index + 1}</span>
                        <button type="button" class="drag-handle" title="Arrastar para reordenar">⋮⋮</button>
                    </div>
                    <input type="text" value="${escapeHtml(String(field.label || ''))}" placeholder="Pergunta sem título" oninput="setFieldLabel(${index}, this.value)">
                    <select onchange="setFieldType(${index}, this.value)">${typeOptions}</select>
                    <select onchange="setFieldBlock(${index}, this.value)" ${['divider', 'note'].includes(type) ? 'disabled' : ''}>${blockSelectHtml}</select>
                </div>
                ${extraHtml}
                <div class="question-footer">
                    <div class="toggles-row">
                        <label class="toggle-chip ${canRequire ? '' : 'disabled'}">
                            <input type="checkbox" ${field.required ? 'checked' : ''} ${canRequire ? '' : 'disabled'} onchange="setFieldRequired(${index}, this.checked)">
                            Obrigatória
                        </label>
                        <label class="toggle-chip ${['divider', 'note'].includes(type) ? 'disabled' : ''}">
                            <input type="checkbox" ${field.info_only ? 'checked' : ''} ${['divider', 'note'].includes(type) ? 'disabled' : ''} onchange="setFieldInfoOnly(${index}, this.checked)">
                            Só informativo
                        </label>
                        <label class="toggle-chip ${canRequirePhoto ? '' : 'disabled'}">
                            <input type="checkbox" ${field.require_photo ? 'checked' : ''} ${canRequirePhoto ? '' : 'disabled'} onchange="setFieldRequirePhoto(${index}, this.checked)">
                            Exige foto
                        </label>
                    </div>
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
    if (!target || !(target instanceof HTMLElement)) return false;
    if (target.closest('.drag-handle')) return false;
    return !!target.closest('input, select, textarea, button, label');
}

function clearQuestionDragState() {
    draggingQuestionIndex = null;
    document.querySelectorAll('.question-card.dragging, .question-card.drop-target').forEach((card) => {
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
    if (!Number.isInteger(fromIndex) || fromIndex < 0 || fromIndex >= currentFields.length) {
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
    if (draggingQuestionIndex === null) return;
    event.preventDefault();
    const card = event.currentTarget;
    if (!card) return;
    const overIndex = Number(card.dataset.index || -1);
    if (!Number.isInteger(overIndex) || overIndex === draggingQuestionIndex) return;
    document.querySelectorAll('.question-card.drop-target').forEach((el) => {
        if (el !== card) el.classList.remove('drop-target');
    });
    card.classList.add('drop-target');
}

function onQuestionDrop(event) {
    event.preventDefault();
    const card = event.currentTarget;
    const toIndex = Number(card?.dataset.index || -1);
    const fromIndex = draggingQuestionIndex;
    clearQuestionDragState();
    if (!Number.isInteger(fromIndex) || !Number.isInteger(toIndex)) return;
    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) return;

    const moved = currentFields.splice(fromIndex, 1)[0];
    if (!moved) return;
    const insertIndex = fromIndex < toIndex ? (toIndex - 1) : toIndex;
    currentFields.splice(insertIndex, 0, moved);
    setBuilderDirty(true);
    renderQuestionList();
}

function bindQuestionDragAndDrop() {
    document.querySelectorAll('.question-card[data-index]').forEach((card) => {
        card.addEventListener('dragstart', onQuestionDragStart);
        card.addEventListener('dragover', onQuestionDragOver);
        card.addEventListener('drop', onQuestionDrop);
        card.addEventListener('dragend', clearQuestionDragState);
    });
}

function renderLibrary() {
    const gallery = document.getElementById('modelsGallery');
    const summary = document.getElementById('librarySummary');
    const empty = document.getElementById('libraryEmpty');
    if (!gallery || !summary || !empty) return;

    const term = String(searchTerm || '').trim().toLowerCase();
    const filtered = savedModels.filter((model) => {
        const name = String(model.nome || '').toLowerCase();
        const statusMatch = statusFilter === 'all'
            || (statusFilter === 'active' && model.ativo)
            || (statusFilter === 'inactive' && !model.ativo);
        return statusMatch && (term === '' || name.includes(term));
    });

    summary.textContent = filtered.length === savedModels.length
        ? `${savedModels.length} checklist(s)`
        : `${filtered.length} de ${savedModels.length} checklist(s)`;

    const cards = [`
        <button type="button" class="new-card" onclick="startNewModel()">
            <span class="new-plus">+</span>
            <strong>Novo checklist</strong>
            <span style="font-size:0.78rem; color:#64748b;">Criar do zero</span>
        </button>
    `];

    filtered.forEach((model) => {
        const id = Number(model.id || 0);
        if (!id) return;
        const badgeClass = model.ativo ? 'badge-green' : 'badge-slate';
        const badgeText = model.ativo ? 'Ativo' : 'Inativo';
        cards.push(`
            <article class="model-card ${model.ativo ? '' : 'inactive'}" onclick="openModelById(${id})">
                <div>
                    <div class="model-card-head">
                        <p class="model-card-title">${escapeHtml(String(model.nome || 'Checklist sem nome'))}</p>
                        <span class="badge ${badgeClass}">${badgeText}</span>
                    </div>
                    <div class="model-meta">
                        <span>Atualizado em ${escapeHtml(formatDate(String(model.updated_at || '')))}</span>
                        <span>${(Array.isArray(model.blocos) ? model.blocos.length : 0)} bloco(s) • ${getFieldCount(model)} campo(s)</span>
                        <span>${(Array.isArray(model.cargos) ? model.cargos.length : 0)} cargo(s) • ${(Array.isArray(model.unidades) ? model.unidades.length : 0)} unidade(s)</span>
                    </div>
                </div>
                <div class="model-footer">
                    <div class="model-counts">
                        <span>${(Array.isArray(model.pacotes) ? model.pacotes.length : 0)} pacote(s)</span>
                    </div>
                    <div class="card-actions">
                        <button type="button" class="mini-btn" onclick="openModelFromCard(event, ${id})">Editar</button>
                        <button type="button" class="mini-btn" onclick="openPreviewFromCard(event, ${id})">Prévia</button>
                        <button type="button" class="mini-btn" onclick="duplicateModel(event, ${id})">Duplicar</button>
                        <button type="button" class="mini-btn" onclick="toggleModelActive(event, ${id}, ${model.ativo ? '0' : '1'})">${model.ativo ? 'Desativar' : 'Ativar'}</button>
                    </div>
                </div>
            </article>
        `);
    });

    gallery.innerHTML = cards.join('');
    if (filtered.length === 0) empty.classList.remove('hidden-ui');
    else empty.classList.add('hidden-ui');
}

function renderApplicabilitySelections(model = null) {
    renderCheckOptions('cargoChecklist', cargoOptions, Array.isArray(model?.cargos) ? model.cargos : [], 'nome');
    renderCheckOptions('unidadeChecklist', unidadeOptions, Array.isArray(model?.unidades) ? model.unidades : [], 'nome');
    renderCheckOptions('pacoteChecklist', pacoteOptions, Array.isArray(model?.pacotes) ? model.pacotes.map(String) : [], 'id');
}

function startNewModel() {
    if (!ensureDiscardChanges()) return;
    editingModelId = null;
    currentBlocks = defaultBlocks();
    currentFields = [createField('text')];
    document.getElementById('editorModelName').value = '';
    document.getElementById('editorModelStatus').value = '1';
    renderApplicabilitySelections(null);
    renderBlockList();
    renderQuestionList();
    updateEditorHeader();
    setBuilderDirty(false);
    setEditorStatus('Novo checklist pronto para edição.', 'success');
    showEditorView();
}

function backToLibrary() {
    if (!ensureDiscardChanges()) return;
    setEditorStatus('', '');
    showLibraryView();
}

function openModelById(modelId) {
    const model = savedModels.find((item) => Number(item.id || 0) === Number(modelId || 0));
    if (!model) {
        setLibraryStatus('Checklist não encontrado.', 'error');
        return;
    }

    if (!ensureDiscardChanges()) return;

    editingModelId = Number(model.id || 0);
    currentBlocks = normalizeBlocks(model.blocos || []);
    currentFields = normalizeFields(model.schema || [], currentBlocks);
    document.getElementById('editorModelName').value = String(model.nome || '');
    document.getElementById('editorModelStatus').value = model.ativo ? '1' : '0';
    renderApplicabilitySelections(model);
    renderBlockList();
    renderQuestionList();
    updateEditorHeader();
    setBuilderDirty(false);
    setEditorStatus('Checklist carregado para edição.', 'success');
    showEditorView();
}

function updateBlockLabel(index, value) {
    if (!currentBlocks[index]) return;
    currentBlocks[index].label = String(value || '');
    setBuilderDirty(true);
    renderBlockList();
    renderQuestionList();
}

function addBlockPrompt() {
    const label = prompt('Nome do bloco/momento do evento:');
    if (!label) return;
    addBlock(label);
}

function addBlock(label) {
    const text = String(label || '').trim();
    if (text === '') return;
    currentBlocks.push({ id: generateId('cob'), label: text, order: currentBlocks.length + 1 });
    setBuilderDirty(true);
    renderBlockList();
    renderQuestionList();
}

function applyDefaultBlocks() {
    if (currentBlocks.length > 0 && !confirm('Substituir os blocos atuais pelos blocos padrão?')) {
        return;
    }
    currentBlocks = defaultBlocks();
    currentFields = currentFields.map((field, index) => ({
        ...field,
        block_id: ['divider', 'note'].includes(String(field.type || '')) ? '' : String(currentBlocks[Math.min(index, currentBlocks.length - 1)]?.id || '')
    }));
    setBuilderDirty(true);
    renderBlockList();
    renderQuestionList();
}

function moveBlock(index, direction) {
    const target = index + direction;
    if (target < 0 || target >= currentBlocks.length) return;
    const tmp = currentBlocks[index];
    currentBlocks[index] = currentBlocks[target];
    currentBlocks[target] = tmp;
    setBuilderDirty(true);
    renderBlockList();
    renderQuestionList();
}

function removeBlock(index) {
    const block = currentBlocks[index];
    if (!block) return;
    if (!confirm(`Remover o bloco "${block.label}"?`)) return;
    currentBlocks.splice(index, 1);
    currentFields = currentFields.map((field) => {
        if (String(field.block_id || '') === String(block.id || '')) {
            return { ...field, block_id: '' };
        }
        return field;
    });
    setBuilderDirty(true);
    renderBlockList();
    renderQuestionList();
}

function addFieldByType(type = 'text') {
    currentFields.push(createField(type));
    setBuilderDirty(true);
    renderQuestionList();
    setEditorStatus('Campo adicionado.', 'success');
}

function setFieldLabel(index, value) {
    if (!currentFields[index]) return;
    currentFields[index].label = String(value || '');
    setBuilderDirty(true);
    updateEditorHeader();
}

function setFieldType(index, value) {
    const field = currentFields[index];
    if (!field) return;
    let nextType = String(value || 'text').toLowerCase();
    if (!allowedFieldTypes.includes(nextType)) nextType = 'text';

    field.type = nextType;
    field.info_only = ['note', 'divider'].includes(nextType) ? true : !!field.info_only;
    field.required = field.info_only ? false : !!field.required;
    field.require_photo = ['photo', 'text_photo'].includes(nextType) ? true : !!field.require_photo;
    field.options = nextType === 'select'
        ? (Array.isArray(field.options) && field.options.length ? field.options : ['Opção 1'])
        : [];
    field.content_html = nextType === 'note' ? (String(field.content_html || '').trim() || 'Texto informativo') : '';
    if (nextType === 'divider') {
        field.label = '---';
        field.block_id = '';
    } else if (nextType === 'note') {
        if ((field.label || '').trim() === '' || field.label === '---') field.label = 'Texto informativo';
        field.block_id = '';
    } else if ((field.label || '').trim() === '' || field.label === '---') {
        field.label = defaultLabelByType(nextType);
    }
    if (['photo', 'text_photo'].includes(nextType)) {
        field.info_only = false;
        field.required = true;
    }
    if (!['divider', 'note'].includes(nextType) && String(field.block_id || '') === '' && currentBlocks.length > 0) {
        field.block_id = String(currentBlocks[0].id || '');
    }
    setBuilderDirty(true);
    renderQuestionList();
}

function setFieldBlock(index, value) {
    if (!currentFields[index]) return;
    currentFields[index].block_id = String(value || '');
    setBuilderDirty(true);
}

function setFieldRequired(index, checked) {
    if (!currentFields[index]) return;
    if (currentFields[index].info_only) {
        currentFields[index].required = false;
    } else {
        currentFields[index].required = !!checked;
    }
    setBuilderDirty(true);
    renderQuestionList();
}

function setFieldInfoOnly(index, checked) {
    if (!currentFields[index]) return;
    const field = currentFields[index];
    if (['note', 'divider'].includes(String(field.type || ''))) {
        field.info_only = true;
        field.required = false;
    } else {
        field.info_only = !!checked;
        if (field.info_only) field.required = false;
    }
    setBuilderDirty(true);
    renderQuestionList();
}

function setFieldRequirePhoto(index, checked) {
    if (!currentFields[index]) return;
    const field = currentFields[index];
    if (['photo', 'text_photo'].includes(String(field.type || ''))) {
        field.require_photo = true;
    } else {
        field.require_photo = !!checked;
    }
    setBuilderDirty(true);
    renderQuestionList();
}

function setFieldOptions(index, rawText) {
    if (!currentFields[index]) return;
    currentFields[index].options = String(rawText || '').split('\n').map((item) => item.trim()).filter(Boolean);
    setBuilderDirty(true);
}

function setFieldContent(index, value) {
    if (!currentFields[index]) return;
    currentFields[index].content_html = String(value || '');
    setBuilderDirty(true);
}

function duplicateField(index) {
    const src = currentFields[index];
    if (!src) return;
    const clone = JSON.parse(JSON.stringify(src));
    clone.id = generateId('cof');
    currentFields.splice(index + 1, 0, clone);
    setBuilderDirty(true);
    renderQuestionList();
}

function removeField(index) {
    if (!currentFields[index]) return;
    currentFields.splice(index, 1);
    setBuilderDirty(true);
    renderQuestionList();
}

function buildPayloadFromEditor() {
    const nome = String(document.getElementById('editorModelName')?.value || '').trim();
    if (nome.length < 3) {
        setEditorStatus('Informe um nome com pelo menos 3 caracteres.', 'error');
        return null;
    }

    const blocos = normalizeBlocks(currentBlocks);
    const schema = normalizeFields(currentFields, blocos);
    if (!schema.length || !hasUsefulFields(schema)) {
        setEditorStatus('Adicione ao menos um campo preenchível antes de salvar.', 'error');
        return null;
    }

    return {
        nome,
        ativo: String(document.getElementById('editorModelStatus')?.value || '1') === '1',
        cargos: getSelectedValues('cargoChecklist'),
        unidades: getSelectedValues('unidadeChecklist'),
        pacotes: getSelectedValues('pacoteChecklist').map((value) => Number(value || 0)).filter((value) => value > 0),
        blocos,
        schema
    };
}

async function refreshModels(silent = false) {
    try {
        const formData = new FormData();
        formData.append('action', 'listar_checklists_operacionais');
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await resp.json();
        if (!data.ok || !Array.isArray(data.models)) {
            throw new Error(data.error || 'Erro ao recarregar checklists.');
        }
        savedModels = data.models;
        renderLibrary();
        if (!silent) {
            setLibraryStatus('Biblioteca atualizada.', 'success');
        }
    } catch (error) {
        setLibraryStatus(error.message || 'Erro ao atualizar biblioteca.', 'error');
    }
}

async function saveCurrentModel() {
    if (saveInFlight) return;
    const payload = buildPayloadFromEditor();
    if (!payload) return;

    try {
        saveInFlight = true;
        const saveBtn = document.getElementById('btnSaveModel');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.textContent = 'Salvando...';
        }
        setEditorStatus('Salvando checklist...', '');

        const formData = new FormData();
        formData.append('action', 'salvar_checklist_operacional');
        formData.append('payload_json', JSON.stringify(payload));
        if (editingModelId) {
            formData.append('model_id', String(editingModelId));
        }

        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await resp.json();
        if (!data.ok || !data.model) {
            throw new Error(data.error || 'Erro ao salvar checklist.');
        }

        editingModelId = Number(data.model.id || 0);
        setBuilderDirty(false);
        await refreshModels(true);
        setEditorStatus('Checklist salvo com sucesso.', 'success');
        updateEditorHeader();
    } catch (error) {
        setEditorStatus(error.message || 'Erro ao salvar checklist.', 'error');
    } finally {
        saveInFlight = false;
        const saveBtn = document.getElementById('btnSaveModel');
        if (saveBtn) {
            saveBtn.disabled = false;
            updateEditorHeader();
        }
    }
}

async function duplicateModel(event, modelId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    try {
        const formData = new FormData();
        formData.append('action', 'duplicar_checklist_operacional');
        formData.append('model_id', String(modelId));
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await resp.json();
        if (!data.ok) {
            throw new Error(data.error || 'Erro ao duplicar checklist.');
        }
        await refreshModels(true);
        setLibraryStatus('Checklist duplicado com sucesso.', 'success');
    } catch (error) {
        setLibraryStatus(error.message || 'Erro ao duplicar checklist.', 'error');
    }
}

async function toggleModelActive(event, modelId, activeFlag) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    try {
        const formData = new FormData();
        formData.append('action', 'toggle_checklist_operacional');
        formData.append('model_id', String(modelId));
        formData.append('ativo', String(activeFlag || '0'));
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await resp.json();
        if (!data.ok) {
            throw new Error(data.error || 'Erro ao atualizar status.');
        }
        await refreshModels(true);
        setLibraryStatus(activeFlag === 1 || activeFlag === '1' ? 'Checklist ativado.' : 'Checklist desativado.', 'success');
    } catch (error) {
        setLibraryStatus(error.message || 'Erro ao atualizar status.', 'error');
    }
}

function buildPreviewHtmlFromModel(model) {
    const payload = model || buildPayloadFromEditor();
    if (!payload) return '';

    const title = escapeHtml(String(payload.nome || 'Checklist Operacional'));
    const blocks = normalizeBlocks(payload.blocos || []);
    const fields = normalizeFields(payload.schema || [], blocks);
    const blockMap = new Map(blocks.map((block) => [String(block.id || ''), block]));
    const grouped = [];
    const ungrouped = [];

    fields.forEach((field) => {
        const blockId = String(field.block_id || '');
        if (blockId && blockMap.has(blockId)) {
            if (!grouped[blockId]) grouped[blockId] = [];
            grouped[blockId].push(field);
        } else {
            ungrouped.push(field);
        }
    });

    function renderField(field) {
        const type = String(field.type || 'text');
        const label = escapeHtml(String(field.label || ''));
        const reqMark = field.required ? ' *' : '';
        if (type === 'divider') return '<hr class="preview-divider">';
        if (type === 'note') {
            const noteText = String(field.content_html || '').trim() !== '' ? escapeHtml(String(field.content_html || '')) : label;
            return `<div class="preview-note">${noteText.replace(/\n/g, '<br>')}</div>`;
        }
        if (field.info_only) {
            return `<div class="preview-note"><strong>${label}</strong></div>`;
        }
        if (type === 'textarea') {
            return `<div class="preview-field"><label>${label}${reqMark}</label><textarea rows="4" placeholder="Sua resposta..."></textarea>${field.require_photo ? '<input type="file" accept="image/*">' : ''}</div>`;
        }
        if (type === 'yesno') {
            return `<div class="preview-field"><label>${label}${reqMark}</label><select><option value="">Selecione...</option><option>Sim</option><option>Não</option></select>${field.require_photo ? '<input type="file" accept="image/*">' : ''}</div>`;
        }
        if (type === 'select') {
            const options = (Array.isArray(field.options) ? field.options : []).map((option) => `<option>${escapeHtml(String(option || ''))}</option>`).join('');
            return `<div class="preview-field"><label>${label}${reqMark}</label><select><option value="">Selecione...</option>${options}</select>${field.require_photo ? '<input type="file" accept="image/*">' : ''}</div>`;
        }
        if (type === 'number') {
            return `<div class="preview-field"><label>${label}${reqMark}</label><input type="number" placeholder="0">${field.require_photo ? '<input type="file" accept="image/*">' : ''}</div>`;
        }
        if (type === 'photo') {
            return `<div class="preview-field"><label>${label}${reqMark}</label><input type="file" accept="image/*"></div>`;
        }
        if (type === 'text_photo') {
            return `<div class="preview-field"><label>${label}${reqMark}</label><textarea rows="3" placeholder="Descreva..."></textarea><input type="file" accept="image/*"></div>`;
        }
        return `<div class="preview-field"><label>${label}${reqMark}</label><input type="text" placeholder="Sua resposta...">${field.require_photo ? '<input type="file" accept="image/*">' : ''}</div>`;
    }

    let sectionsHtml = '';
    blocks.forEach((block) => {
        const blockFields = grouped[String(block.id || '')] || [];
        if (!blockFields.length) return;
        sectionsHtml += `
            <section class="preview-section">
                <h2 class="preview-section-title">${escapeHtml(String(block.label || 'Bloco'))}</h2>
                ${blockFields.map(renderField).join('')}
            </section>
        `;
    });

    if (ungrouped.length) {
        sectionsHtml += `
            <section class="preview-section">
                <h2 class="preview-section-title">Campos sem bloco</h2>
                ${ungrouped.map(renderField).join('')}
            </section>
        `;
    }

    return `<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Prévia - ${title}</title>
    <style>
        body { margin:0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background:#eef3fa; color:#0f172a; }
        .preview-header { background:linear-gradient(135deg, #1d4ed8, #1e40af); color:#fff; padding:18px 22px; }
        .preview-header strong { display:block; font-size:1.2rem; }
        .preview-header span { display:block; margin-top:6px; opacity:0.9; font-size:0.9rem; }
        .preview-wrap { max-width:940px; margin:0 auto; padding:22px 16px 34px; }
        .preview-card { background:#fff; border:1px solid #dbe3ef; border-radius:18px; box-shadow:0 14px 34px rgba(15, 23, 42, 0.08); padding:22px; }
        .preview-title { margin:0; font-size:1.6rem; }
        .preview-sub { margin:8px 0 0; color:#64748b; font-size:0.92rem; }
        .preview-section { margin-top:20px; display:grid; gap:12px; }
        .preview-section-title { margin:0; font-size:1.05rem; color:#1d4ed8; }
        .preview-note { border:1px solid #dbe3ef; background:#f8fafc; border-radius:12px; padding:12px 14px; font-size:0.88rem; color:#475569; line-height:1.5; }
        .preview-divider { border:none; border-top:1px dashed #cbd5e1; margin:2px 0; }
        .preview-field { display:grid; gap:8px; }
        .preview-field label { font-size:0.86rem; font-weight:700; color:#334155; }
        .preview-field input, .preview-field textarea, .preview-field select { width:100%; box-sizing:border-box; border:1px solid #cfd8e5; border-radius:10px; padding:10px 12px; font-size:0.9rem; }
        .preview-submit { margin-top:18px; border:none; background:#3252cc; color:#fff; font-weight:700; border-radius:12px; padding:12px 18px; width:100%; }
    </style>
</head>
<body>
    <div class="preview-header">
        <strong>Prévia do Checklist Operacional</strong>
        <span>Validação visual interna do modelo antes de publicar para a equipe.</span>
    </div>
    <div class="preview-wrap">
        <div class="preview-card">
            <h1 class="preview-title">${title}</h1>
            <p class="preview-sub">Estrutura agrupada por momento do evento.</p>
            ${sectionsHtml}
            <button type="button" class="preview-submit" disabled>Enviar checklist (prévia)</button>
        </div>
    </div>
</body>
</html>`;
}

function openPreviewTab() {
    const html = buildPreviewHtmlFromModel(null);
    if (!html) return;
    const win = window.open('', '_blank');
    if (!win) {
        setEditorStatus('Não foi possível abrir a prévia. Libere pop-ups para este domínio.', 'error');
        return;
    }
    win.document.open();
    win.document.write(html);
    win.document.close();
}

function openPreviewFromCard(event, modelId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const model = savedModels.find((item) => Number(item.id || 0) === Number(modelId || 0));
    if (!model) {
        setLibraryStatus('Checklist não encontrado.', 'error');
        return;
    }
    const html = buildPreviewHtmlFromModel(model);
    const win = window.open('', '_blank');
    if (!win) {
        setLibraryStatus('Não foi possível abrir a prévia. Libere pop-ups para este domínio.', 'error');
        return;
    }
    win.document.open();
    win.document.write(html);
    win.document.close();
}

function openModelFromCard(event, modelId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    openModelById(modelId);
}

document.getElementById('modelSearch')?.addEventListener('input', (event) => {
    searchTerm = String(event.target.value || '');
    renderLibrary();
});

document.getElementById('modelStatusFilter')?.addEventListener('change', (event) => {
    statusFilter = String(event.target.value || 'all');
    renderLibrary();
});

document.getElementById('editorModelName')?.addEventListener('input', () => {
    setBuilderDirty(true);
    updateEditorHeader();
});

document.getElementById('editorModelStatus')?.addEventListener('change', () => {
    setBuilderDirty(true);
});

renderLibrary();
updateEditorHeader();
</script>
