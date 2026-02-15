<?php
/**
 * eventos_reuniao_final.php
 * Tela de Reunião Final com abas e editor rico
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/eventos_me_helper.php';
require_once __DIR__ . '/upload_magalu.php';

// Verificar permissão
if (empty($_SESSION['perm_eventos']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? 0;
$meeting_id = (int)($_GET['id'] ?? $_POST['meeting_id'] ?? 0);
$me_event_id = (int)($_GET['me_event_id'] ?? $_POST['me_event_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$reuniao = null;
$secoes = [];
$error = '';
$success = '';

// Processar ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    switch ($action) {
        case 'criar_reuniao':
            if ($me_event_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Selecione um evento']);
                exit;
            }
            $result = eventos_reuniao_get_or_create($pdo, $me_event_id, $user_id);
            echo json_encode($result);
            exit;
            
        case 'salvar_secao':
            $section = $_POST['section'] ?? '';
            $content = $_POST['content_html'] ?? '';
            $note = $_POST['note'] ?? '';
            $form_schema_json = $_POST['form_schema_json'] ?? null;
            
            if ($meeting_id <= 0 || !$section) {
                echo json_encode(['ok' => false, 'error' => 'Dados inválidos']);
                exit;
            }
            
            $result = eventos_reuniao_salvar_secao($pdo, $meeting_id, $section, $content, $user_id, $note, 'interno', $form_schema_json);
            echo json_encode($result);
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
            $save_template = eventos_form_template_salvar(
                $pdo,
                $template_name,
                $template_category,
                $schema,
                (int)$user_id,
                $template_id > 0 ? $template_id : null
            );
            echo json_encode($save_template);
            exit;

        case 'listar_templates_form':
            echo json_encode(['ok' => true, 'templates' => eventos_form_templates_listar($pdo)]);
            exit;

        case 'arquivar_template_form':
            $template_id = (int)($_POST['template_id'] ?? 0);
            $archive = eventos_form_template_arquivar($pdo, $template_id);
            echo json_encode($archive);
            exit;
            
        case 'gerar_link_cliente':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $schema_payload = $_POST['form_schema_json'] ?? null;
            $content_snapshot = trim((string)($_POST['content_html'] ?? ''));
            $form_title = trim((string)($_POST['form_title'] ?? ''));

            $schema_array = null;
            if ($schema_payload !== null && $schema_payload !== '') {
                $decoded = json_decode((string)$schema_payload, true);
                if (!is_array($decoded)) {
                    echo json_encode(['ok' => false, 'error' => 'Schema inválido para gerar o link']);
                    exit;
                }
                $schema_array = $decoded;
            }

            $result = eventos_reuniao_gerar_link_cliente(
                $pdo,
                $meeting_id,
                (int)$user_id,
                $schema_array,
                $content_snapshot !== '' ? $content_snapshot : null,
                $form_title !== '' ? $form_title : null,
                $slot_index
            );
            if ($result['ok']) {
                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $result['url'] = $base_url . '/index.php?page=eventos_cliente_dj&token=' . $result['link']['token'];
                $result['slot_index'] = $slot_index;
            }
            echo json_encode($result);
            exit;
            
        case 'get_versoes':
            $section = $_POST['section'] ?? '';
            if ($meeting_id <= 0 || !$section) {
                echo json_encode(['ok' => false, 'error' => 'Dados inválidos']);
                exit;
            }
            $versoes = eventos_reuniao_get_versoes($pdo, $meeting_id, $section);
            echo json_encode(['ok' => true, 'versoes' => $versoes]);
            exit;
            
        case 'restaurar_versao':
            $version_id = (int)($_POST['version_id'] ?? 0);
            if ($version_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Versão inválida']);
                exit;
            }
            $result = eventos_reuniao_restaurar_versao($pdo, $version_id, $user_id);
            echo json_encode($result);
            exit;
            
        case 'destravar_secao':
            $section = $_POST['section'] ?? '';
            if ($meeting_id <= 0 || !$section) {
                echo json_encode(['ok' => false, 'error' => 'Dados inválidos']);
                exit;
            }
            $result = eventos_reuniao_destravar_secao($pdo, $meeting_id, $section, $user_id);
            echo json_encode($result);
            exit;

        case 'destravar_dj_slot':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $result = eventos_reuniao_destravar_dj_slot($pdo, $meeting_id, $slot_index, (int)$user_id);
            echo json_encode($result);
            exit;

        case 'excluir_dj_slot':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $result = eventos_reuniao_excluir_dj_slot($pdo, $meeting_id, $slot_index, (int)$user_id);
            echo json_encode($result);
            exit;
            
        case 'atualizar_status':
            $status = $_POST['status'] ?? '';
            if ($meeting_id <= 0 || !in_array($status, ['rascunho', 'concluida'])) {
                echo json_encode(['ok' => false, 'error' => 'Dados inválidos']);
                exit;
            }
            $ok = eventos_reuniao_atualizar_status($pdo, $meeting_id, $status, $user_id);
            echo json_encode(['ok' => $ok]);
            exit;
            
        case 'upload_imagem':
            $mid = (int)($_POST['meeting_id'] ?? 0);
            $file = null;
            foreach (['file', 'blobid0', 'imagetools0'] as $key) {
                if (!empty($_FILES[$key]) && $_FILES[$key]['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES[$key];
                    break;
                }
            }
            if ($mid <= 0 || !$file) {
                echo json_encode(['location' => '', 'error' => 'Dados ou arquivo inválido']);
                exit;
            }
            try {
                $uploader = new MagaluUpload();
                $prefix = 'eventos/reunioes/' . $mid;
                $result = $uploader->upload($file, $prefix);
                $url = $result['url'] ?? '';
                if ($url) {
                    echo json_encode(['location' => $url]);
                } else {
                    echo json_encode(['location' => '', 'error' => 'Falha no upload']);
                }
            } catch (Exception $e) {
                echo json_encode(['location' => '', 'error' => $e->getMessage()]);
            }
            exit;
    }
}

// Carregar reunião existente
if ($meeting_id > 0) {
    $reuniao = eventos_reuniao_get($pdo, $meeting_id);
    if ($reuniao) {
        // Carregar seções
        foreach (['decoracao', 'observacoes_gerais', 'dj_protocolo'] as $sec) {
            $secoes[$sec] = eventos_reuniao_get_secao($pdo, $meeting_id, $sec);
        }
    }
}

eventos_form_template_seed_protocolo_15anos($pdo, $user_id);
$form_templates = eventos_form_templates_listar($pdo);
$links_cliente_dj = $meeting_id > 0 ? eventos_reuniao_listar_links_cliente($pdo, $meeting_id) : [];
$active_tab_query = trim((string)($_GET['tab'] ?? ''));
$decoracao_schema_raw = $secoes['decoracao']['form_schema_json'] ?? '[]';
$decoracao_schema_decoded = json_decode((string)$decoracao_schema_raw, true);
$decoracao_schema_saved = is_array($decoracao_schema_decoded) ? $decoracao_schema_decoded : [];
$observacoes_schema_raw = $secoes['observacoes_gerais']['form_schema_json'] ?? '[]';
$observacoes_schema_decoded = json_decode((string)$observacoes_schema_raw, true);
$observacoes_schema_saved = is_array($observacoes_schema_decoded) ? $observacoes_schema_decoded : [];
$dj_schema_raw = $secoes['dj_protocolo']['form_schema_json'] ?? '[]';
$dj_schema_decoded = json_decode((string)$dj_schema_raw, true);
$dj_schema_saved = is_array($dj_schema_decoded) ? $dj_schema_decoded : [];

// Seções disponíveis
$section_labels = [
    'decoracao' => ['icon' => '🎨', 'label' => 'Decoração'],
    'observacoes_gerais' => ['icon' => '📝', 'label' => 'Observações Gerais'],
    'dj_protocolo' => ['icon' => '🎧', 'label' => 'DJ / Protocolos']
];

includeSidebar($meeting_id > 0 ? 'Reunião Final' : 'Nova Reunião Final');
?>

<style>
    .reuniao-container {
        padding: 2rem;
        max-width: 1400px;
        margin: 0 auto;
        background: #f8fafc;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 2rem;
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .page-title {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e3a8a;
        margin: 0;
    }
    
    .page-subtitle {
        color: #64748b;
        font-size: 0.875rem;
        margin-top: 0.25rem;
    }
    
    .header-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    
    .btn {
        padding: 0.625rem 1.25rem;
        border-radius: 8px;
        font-size: 0.875rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        text-decoration: none;
    }

    .btn-mini {
        padding: 0.5rem 0.75rem;
        font-size: 0.825rem;
    }
    
    .btn-primary {
        background: #1e3a8a;
        color: white;
    }
    
    .btn-primary:hover {
        background: #2563eb;
    }
    
    .btn-secondary {
        background: #f1f5f9;
        color: #475569;
        border: 1px solid #e2e8f0;
    }
    
    .btn-secondary:hover {
        background: #e2e8f0;
    }
    
    .btn-success {
        background: #059669;
        color: white;
    }
    
    .btn-success:hover {
        background: #10b981;
    }
    
    /* Seletor de Evento */
    .event-selector {
        background: white;
        border-radius: 12px;
        padding: 1.5rem;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        margin-bottom: 2rem;
    }
    
    .event-selector h3 {
        margin: 0 0 1rem 0;
        font-size: 1rem;
        color: #374151;
    }
    
    .search-wrapper {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .search-hint {
        margin-top: -0.25rem;
        margin-bottom: 0.75rem;
        color: #64748b;
        font-size: 0.8rem;
    }
    
    .search-input {
        flex: 1;
        padding: 0.75rem 1rem;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.875rem;
    }
    
    .search-input:focus {
        outline: none;
        border-color: #1e3a8a;
        box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
    }
    
    .events-list {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
    }
    
    .event-item {
        padding: 1rem;
        border-bottom: 1px solid #e5e7eb;
        cursor: pointer;
        transition: background 0.2s;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .event-item:last-child {
        border-bottom: none;
    }
    
    .event-item:hover {
        background: #f8fafc;
    }
    
    .event-item.selected {
        background: #eff6ff;
        border-left: 3px solid #1e3a8a;
    }

    .event-item-label {
        font-size: 0.75rem;
        color: #1d4ed8;
        font-weight: 700;
        margin-top: 0.35rem;
    }

    .selected-event-summary {
        display: none;
        margin-top: 0.75rem;
        border: 1px solid #c7d2fe;
        border-radius: 8px;
        background: #eef2ff;
        color: #1e3a8a;
        font-size: 0.85rem;
        padding: 0.75rem;
    }
    
    .event-info h4 {
        margin: 0;
        font-size: 0.95rem;
        color: #1e293b;
    }
    
    .event-info p {
        margin: 0.25rem 0 0 0;
        font-size: 0.8rem;
        color: #64748b;
    }
    
    .event-date {
        font-size: 0.875rem;
        font-weight: 600;
        color: #1e3a8a;
        white-space: nowrap;
    }
    
    /* Info do Evento Selecionado */
    .event-header {
        background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
        color: white;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }
    
    .event-header h2 {
        margin: 0;
        font-size: 1.25rem;
    }
    
    .event-meta {
        display: flex;
        gap: 2rem;
        margin-top: 0.75rem;
        flex-wrap: wrap;
    }
    
    .event-meta-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.875rem;
        opacity: 0.9;
    }
    
    /* Tabs */
    .tabs-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        overflow: hidden;
    }
    
    .tabs-header {
        display: flex;
        border-bottom: 1px solid #e5e7eb;
        background: #f8fafc;
    }
    
    .tab-btn {
        flex: 1;
        padding: 1rem 1.5rem;
        background: none;
        border: none;
        font-size: 0.875rem;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        border-bottom: 3px solid transparent;
    }
    
    .tab-btn:hover {
        background: #f1f5f9;
        color: #1e293b;
    }
    
    .tab-btn.active {
        color: #1e3a8a;
        background: white;
        border-bottom-color: #1e3a8a;
    }
    
    .tab-btn .locked-badge {
        background: #fef3c7;
        color: #92400e;
        font-size: 0.7rem;
        padding: 0.125rem 0.5rem;
        border-radius: 9999px;
    }
    
    .tab-content {
        padding: 1.5rem;
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    /* Editor */
    .editor-toolbar {
        display: flex;
        gap: 0.5rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }
    
    .editor-wrapper {
        border: 1px solid #d1d5db;
        border-radius: 8px;
        min-height: 400px;
        background: white;
    }
    
    .editor-content {
        padding: 1rem;
        min-height: 350px;
        outline: none;
    }
    
    .editor-content:focus {
        box-shadow: inset 0 0 0 2px rgba(30, 58, 138, 0.2);
    }
    
    .section-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
        flex-wrap: wrap;
    }
    
    /* DJ Section Specific */
    .dj-builder-shell {
        background: #f8fafc;
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .dj-builder-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 0.9rem;
    }

    .dj-builder-title {
        margin: 0;
        font-size: 1rem;
        color: #0f172a;
    }

    .dj-builder-subtitle {
        margin-top: 0.2rem;
        font-size: 0.8rem;
        color: #64748b;
    }

    .dj-head-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        align-items: center;
    }

    .dj-top-actions {
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
    }

    .dj-top-actions .btn {
        min-width: 160px;
        justify-content: center;
    }

    .dj-slots-controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.8rem;
        flex-wrap: wrap;
        margin-bottom: 0.8rem;
    }

    .dj-slots-controls h4 {
        margin: 0;
        font-size: 1rem;
        color: #0f172a;
    }

    .dj-slots-controls p {
        margin: 0.2rem 0 0 0;
        font-size: 0.82rem;
        color: #64748b;
    }

    .dj-slots-stack {
        display: flex;
        flex-direction: column;
        gap: 0.9rem;
    }

    .btn-slot-remove {
        background: #fff1f2;
        border-color: #fecdd3;
        color: #b91c1c;
        min-width: 0 !important;
    }

    .btn-slot-remove:hover {
        background: #ffe4e6;
    }

    .dj-builder-create-only {
        margin-top: 0.95rem;
        background: #ffffff;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 0.95rem;
    }

    .dj-builder-empty-state {
        margin-top: 0.95rem;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        padding: 1rem;
        color: #64748b;
        background: #ffffff;
    }

    .dj-dirty-badge {
        display: none;
        padding: 0.35rem 0.6rem;
        border-radius: 999px;
        font-size: 0.75rem;
        font-weight: 700;
        background: #fff7ed;
        color: #9a3412;
        border: 1px solid #fed7aa;
    }

    .dj-dirty-badge.show {
        display: inline-flex;
        align-items: center;
    }

    .prefill-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.75rem;
    }

    .prefill-field {
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
    }

    .prefill-field label {
        font-size: 0.78rem;
        color: #475569;
        font-weight: 600;
    }

    .prefill-field input,
    .prefill-field select,
    .prefill-field textarea {
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.55rem 0.65rem;
        font-size: 0.85rem;
    }

    .prefill-actions {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
        margin-top: 0.75rem;
    }

    .prefill-actions .btn {
        padding: 0.5rem 0.75rem;
        font-size: 0.8rem;
    }

    .prefill-note {
        margin-top: 0.5rem;
        font-size: 0.76rem;
        color: #64748b;
    }

    .builder-fields-list {
        margin-top: 0.9rem;
        display: flex;
        flex-direction: column;
        gap: 0.55rem;
    }

    .builder-field-card {
        background: #ffffff;
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        padding: 0.7rem;
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        align-items: flex-start;
    }

    .builder-field-title {
        font-size: 0.85rem;
        font-weight: 700;
        color: #0f172a;
        margin-bottom: 0.2rem;
    }

    .builder-field-meta {
        font-size: 0.75rem;
        color: #64748b;
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

    .builder-preview-box {
        background: #fff;
        border: 1px solid #dbe3ef;
        border-radius: 8px;
        padding: 0.85rem;
    }

    .builder-preview-title {
        font-size: 0.8rem;
        font-weight: 700;
        color: #334155;
        margin-bottom: 0.65rem;
    }

    .builder-preview-item {
        margin-bottom: 0.75rem;
    }

    .builder-preview-item label {
        display: block;
        margin-bottom: 0.35rem;
        color: #334155;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .builder-preview-item input,
    .builder-preview-item textarea,
    .builder-preview-item select {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 0.45rem 0.55rem;
        font-size: 0.8rem;
        background: #f8fafc;
    }

    .legacy-editor-toggle {
        margin: 0.75rem 0;
        padding: 0.8rem;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid #dbe3ef;
        display: flex;
        gap: 0.75rem;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
    }

    .legacy-editor-wrap {
        margin-top: 0.75rem;
    }

    .section-form-fields {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    .section-form-item label {
        display: block;
        margin-bottom: 0.35rem;
        color: #334155;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .section-form-item input,
    .section-form-item textarea,
    .section-form-item select {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.55rem 0.65rem;
        font-size: 0.85rem;
        background: #fff;
    }

    .section-form-item textarea {
        min-height: 110px;
        resize: vertical;
    }

    .section-form-divider {
        border: 0;
        border-top: 1px solid #dbe3ef;
        margin: 0.25rem 0;
    }

    .section-form-title {
        margin: 0.4rem 0 0.2rem 0;
        color: #1e3a8a;
        font-size: 0.9rem;
        font-weight: 700;
    }

    .section-form-note {
        margin: 0.2rem 0 0.3rem 0;
        padding: 0.6rem 0.7rem;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid #dbe3ef;
        color: #475569;
        font-size: 0.83rem;
        line-height: 1.45;
    }

    .link-display {
        display: flex;
        gap: 0.5rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .link-input {
        flex: 1;
        min-width: 250px;
        padding: 0.5rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.8rem;
        background: white;
    }

    .share-hint {
        margin: 0.85rem 0 0.6rem 0;
        color: #64748b;
        font-size: 0.8rem;
    }

    .template-list {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        max-height: 260px;
        overflow-y: auto;
        margin-top: 0.75rem;
    }

    .template-item {
        padding: 0.75rem;
        border-bottom: 1px solid #e2e8f0;
        cursor: pointer;
        background: #fff;
    }

    .template-item:last-child {
        border-bottom: none;
    }

    .template-item:hover {
        background: #f8fafc;
    }

    .template-item.selected {
        background: #eff6ff;
        border-left: 3px solid #1d4ed8;
    }

    .template-item strong {
        display: block;
        color: #0f172a;
        font-size: 0.84rem;
    }

    .template-item span {
        font-size: 0.75rem;
        color: #64748b;
    }

    .template-save-grid {
        margin-top: 0.85rem;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.7rem;
    }
    
    .locked-notice {
        background: #fef3c7;
        border: 1px solid #fcd34d;
        color: #92400e;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    /* Modal de Versões */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s;
    }
    
    .modal-overlay.show {
        opacity: 1;
        visibility: visible;
    }
    
    .modal-content {
        background: white;
        border-radius: 12px;
        width: 90%;
        max-width: 800px;
        max-height: 80vh;
        overflow-y: auto;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
    }
    
    .modal-header {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .modal-header h3 {
        margin: 0;
        font-size: 1.125rem;
    }
    
    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #64748b;
    }
    
    .modal-body {
        padding: 1.5rem;
    }
    
    .version-item {
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .version-item.active {
        border-color: #1e3a8a;
        background: #eff6ff;
    }
    
    .version-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }
    
    .version-number {
        font-weight: 600;
        color: #1e3a8a;
    }
    
    .version-meta {
        font-size: 0.8rem;
        color: #64748b;
    }
    
    .version-note {
        font-size: 0.875rem;
        color: #475569;
        font-style: italic;
    }
    
    /* Responsivo */
    @media (max-width: 768px) {
        .reuniao-container {
            padding: 1rem;
        }
        
        .tabs-header {
            flex-direction: column;
        }
        
        .tab-btn {
            border-bottom: none;
            border-left: 3px solid transparent;
        }
        
        .tab-btn.active {
            border-left-color: #1e3a8a;
        }
        
        .event-meta {
            flex-direction: column;
            gap: 0.5rem;
        }

        .prefill-grid {
            grid-template-columns: 1fr;
        }

        .template-save-grid {
            grid-template-columns: 1fr;
        }

        .dj-top-actions .btn {
            min-width: 100%;
        }

        .dj-slots-controls .btn {
            width: 100%;
        }
    }
</style>

<div class="reuniao-container">
    <?php if (!$reuniao): ?>
    <!-- Seletor de Evento -->
    <div class="page-header">
        <div>
            <h1 class="page-title">📝 Nova Reunião Final</h1>
            <p class="page-subtitle">Selecione um evento da ME para criar a reunião</p>
        </div>
        <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
    </div>
    
    <div class="event-selector">
        <h3>🔍 Buscar Evento</h3>
        <div class="search-wrapper">
            <input type="text" id="eventSearch" class="search-input" placeholder="Digite nome, cliente, local ou data...">
            <button type="button" class="btn btn-primary" onclick="searchEvents(null, true)">Buscar</button>
        </div>
        <div class="search-hint">Busca inteligente: digitou, filtrou. A lista também usa cache para reduzir atraso.</div>
        <div id="eventsList" class="events-list" style="display: none;"></div>
        <div id="loadingEvents" style="display: none; padding: 2rem; text-align: center; color: #64748b;">
            Carregando eventos...
        </div>
        <div id="selectedEventSummary" class="selected-event-summary"></div>
        <div id="selectedEvent" style="display: none; margin-top: 1rem;">
            <button type="button" class="btn btn-success" onclick="criarReuniao()">
                ✓ Criar Reunião para este Evento
            </button>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Reunião Existente -->
    <?php 
    $snapshot = json_decode($reuniao['me_event_snapshot'], true) ?: [];
    $nome_evento = trim((string)($snapshot['nome'] ?? ''));
    if ($nome_evento === '') {
        $nome_evento = 'Evento';
    }
    $data_evento = $snapshot['data'] ?? '';
    $data_fmt = $data_evento ? date('d/m/Y', strtotime($data_evento)) : 'Sem data';
    $hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? $snapshot['horainicio'] ?? $snapshot['horario_inicio'] ?? ''));
    $hora_fim = trim((string)($snapshot['hora_fim'] ?? $snapshot['horafim'] ?? $snapshot['horatermino'] ?? $snapshot['hora_termino'] ?? ''));
    $horario_evento = $hora_inicio !== '' ? $hora_inicio : 'Horário não informado';
    if ($hora_inicio !== '' && $hora_fim !== '') {
        $horario_evento .= ' - ' . $hora_fim;
    }
    $cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? $snapshot['nomecliente'] ?? 'Cliente não informado'));
    $cliente_telefone = trim((string)($snapshot['cliente']['telefone'] ?? $snapshot['telefonecliente'] ?? ''));
    $cliente_email = trim((string)($snapshot['cliente']['email'] ?? $snapshot['emailcliente'] ?? ''));
    $tipo_evento = trim((string)($snapshot['tipo_evento'] ?? $snapshot['tipoevento'] ?? ''));
    $unidade_evento = trim((string)($snapshot['unidade'] ?? ''));
    $local_evento = trim((string)($snapshot['local'] ?? $snapshot['nomelocal'] ?? ''));
    if ($local_evento === '') {
        $local_evento = 'Local não definido';
    }
    $convidados_evento = (int)($snapshot['convidados'] ?? $snapshot['nconvidados'] ?? 0);
    $evento_me_id = (int)($snapshot['id'] ?? $reuniao['me_event_id'] ?? 0);
    ?>
    
    <div class="page-header">
        <div>
            <h1 class="page-title">📝 Reunião Final</h1>
            <p class="page-subtitle">
                Status: 
                <strong style="color: <?= $reuniao['status'] === 'concluida' ? '#059669' : '#f59e0b' ?>">
                    <?= $reuniao['status'] === 'concluida' ? 'Concluída' : 'Rascunho' ?>
                </strong>
            </p>
        </div>
        <div class="header-actions">
            <?php if ($reuniao['status'] === 'rascunho'): ?>
            <button type="button" class="btn btn-success" onclick="concluirReuniao()">✓ Marcar como Concluída</button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary" onclick="reabrirReuniao()">↺ Reabrir</button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary btn-mini" onclick="abrirModalImpressao()" title="Imprimir / PDF" aria-label="Imprimir / PDF">🖨️</button>
            <a href="index.php?page=eventos" class="btn btn-secondary">← Voltar</a>
        </div>
    </div>
    
    <!-- Header do Evento -->
    <div class="event-header">
        <h2><?= htmlspecialchars($nome_evento) ?></h2>
        <div class="event-meta">
            <div class="event-meta-item">
                <span>📅</span>
                <span><?= $data_fmt ?> • <?= htmlspecialchars($horario_evento) ?></span>
            </div>
            <div class="event-meta-item">
                <span>📍</span>
                <span><?= htmlspecialchars($local_evento) ?></span>
            </div>
            <div class="event-meta-item">
                <span>👥</span>
                <span><?= $convidados_evento ?> convidados</span>
            </div>
            <div class="event-meta-item">
                <span>👤</span>
                <span><?= htmlspecialchars($cliente_nome) ?></span>
            </div>
            <?php if ($cliente_telefone !== ''): ?>
            <div class="event-meta-item">
                <span>📞</span>
                <span><?= htmlspecialchars($cliente_telefone) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($cliente_email !== ''): ?>
            <div class="event-meta-item">
                <span>✉️</span>
                <span><?= htmlspecialchars($cliente_email) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($tipo_evento !== ''): ?>
            <div class="event-meta-item">
                <span>🏷️</span>
                <span><?= htmlspecialchars($tipo_evento) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($unidade_evento !== ''): ?>
            <div class="event-meta-item">
                <span>🏢</span>
                <span><?= htmlspecialchars($unidade_evento) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($evento_me_id > 0): ?>
            <div class="event-meta-item">
                <span>#️⃣</span>
                <span>ID ME: <?= $evento_me_id ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Tabs de Seções -->
    <div class="tabs-container">
        <div class="tabs-header">
            <?php foreach ($section_labels as $key => $info): 
                $secao = $secoes[$key] ?? null;
                $is_locked = $secao && !empty($secao['is_locked']);
                if ($key === 'dj_protocolo') {
                    $is_locked = false;
                }
            ?>
            <button type="button" class="tab-btn <?= $key === 'decoracao' ? 'active' : '' ?>" onclick="switchTab('<?= $key ?>')">
                <span><?= $info['icon'] ?></span>
                <span><?= $info['label'] ?></span>
                <?php if ($is_locked): ?>
                <span class="locked-badge">🔒</span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        
        <?php foreach ($section_labels as $key => $info): 
            $secao = $secoes[$key] ?? null;
            $content = $secao['content_html'] ?? '';
            $is_locked = $secao && !empty($secao['is_locked']);
            if ($key === 'dj_protocolo') {
                $is_locked = false;
            }
        ?>
        <div class="tab-content <?= $key === 'decoracao' ? 'active' : '' ?>" id="tab-<?= $key ?>">
            
            <?php if ($key === 'dj_protocolo'): ?>
            <div class="dj-builder-shell">
                <div class="dj-slots-controls">
                    <div>
                        <h4>🧩 DJ / Protocolos</h4>
                        <p>Comece sem quadros. Adicione somente quando precisar gerar um novo link para o cliente.</p>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="addDjSlot()">+ Adicionar quadro</button>
                </div>
                <div id="djSlotsEmptyState" class="dj-builder-empty-state">Nenhum quadro criado. Clique em "Adicionar quadro" para começar.</div>
                <div id="djSlotsContainer" class="dj-slots-stack"></div>
            </div>
            <?php endif; ?>

            <?php if ($key === 'decoracao' || $key === 'observacoes_gerais'): ?>
            <div class="dj-builder-shell">
                <div class="dj-builder-head">
                    <div>
                        <h4 class="dj-builder-title">🧩 Formulário interno</h4>
                        <?php if ($key === 'observacoes_gerais'): ?>
                        <div class="dj-builder-subtitle">Ao selecionar um formulário, ele aparece abaixo para preenchimento da equipe.</div>
                        <?php else: ?>
                        <div class="dj-builder-subtitle">Selecione um formulário e preencha os campos diretamente nesta aba.</div>
                        <?php endif; ?>
                    </div>
                    <div class="dj-top-actions">
                        <button type="button" class="btn btn-secondary" onclick="aplicarTemplateNaSecao('<?= $key ?>')" <?= $is_locked ? 'disabled' : '' ?>>Carregar formulário</button>
                    </div>
                </div>
                <div class="prefill-field" style="margin-top: 0.5rem;">
                    <label for="sectionTemplateSelect-<?= $key ?>">Formulário salvo (opcional)</label>
                    <select id="sectionTemplateSelect-<?= $key ?>" onchange="onChangeSectionTemplateSelect('<?= $key ?>')" <?= $is_locked ? 'disabled' : '' ?>>
                        <option value="">Nenhum formulário</option>
                        <?php foreach ($form_templates as $template): ?>
                        <option value="<?= (int)($template['id'] ?? 0) ?>">
                            <?= htmlspecialchars((string)($template['nome'] ?? 'Modelo sem nome') . ' - ' . (string)($template['categoria'] ?? 'geral')) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="builder-field-meta" id="sectionTemplateMeta-<?= $key ?>" style="margin-top: 0.55rem;">Nenhum formulário selecionado.</div>

                <div class="builder-preview-box" id="sectionFormBox-<?= $key ?>" style="display:none; margin-top:0.85rem;">
                    <div class="builder-preview-title">Preenchimento interno por formulário</div>
                    <div class="section-form-fields" id="sectionFormFields-<?= $key ?>"></div>
                    <p class="prefill-note" id="sectionFormHint-<?= $key ?>">Preencha os campos e salve a seção para registrar uma nova versão.</p>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($is_locked): ?>
            <div class="locked-notice">
                <span style="font-size: 1.5rem;">🔒</span>
                <div>
                    <strong>Seção travada</strong>
                    <p style="margin: 0.25rem 0 0 0; font-size: 0.875rem;">O cliente já enviou as informações. Clique em "Destravar" para permitir edições.</p>
                </div>
                <button type="button" class="btn btn-secondary" onclick="destravarSecao('<?= $key ?>')" style="margin-left: auto;">
                    🔓 Destravar
                </button>
            </div>
            <?php endif; ?>
            
            <?php if ($key === 'decoracao' || $key === 'observacoes_gerais'): ?>
            <div class="legacy-editor-toggle">
                <div>
                    <strong>Texto livre (opcional)</strong>
                    <div class="builder-field-meta">Mantido para observações extras. Clique para abrir/fechar.</div>
                </div>
                <button type="button" class="btn btn-secondary" id="btnToggleEditor-<?= $key ?>" onclick="toggleLegacyEditor('<?= $key ?>')">Abrir texto</button>
            </div>
            <?php endif; ?>

            <?php
            $editor_wrap_attrs = '';
            if ($key === 'dj_protocolo' || $key === 'decoracao' || $key === 'observacoes_gerais') {
                $editor_wrap_attrs = ' style="display:none;"';
            }
            ?>
            <div class="editor-wrapper legacy-editor-wrap" id="legacyEditorWrap-<?= $key ?>"<?= $editor_wrap_attrs ?>>
                <?php 
                $safe_content = str_replace('</textarea>', '&lt;/textarea&gt;', $content);
                ?>
                <textarea id="editor-<?= $key ?>" 
                          data-section="<?= $key ?>"
                          <?= $is_locked ? 'readonly' : '' ?>
                          style="width:100%; min-height: 400px; border: 0;"><?= $safe_content ?></textarea>
            </div>
            
            <div class="section-actions">
                <?php if (!$is_locked && $key !== 'dj_protocolo'): ?>
                <button type="button" class="btn btn-primary" onclick="salvarSecao('<?= $key ?>')">💾 Salvar</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="verVersoes('<?= $key ?>')">📋 Histórico de Versões</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- TinyMCE carregado via script dinâmico (evita ficar travado em "Carregando editor...") -->
<!-- Modal de Versões -->
<div class="modal-overlay" id="modalVersoes">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📋 Histórico de Versões</h3>
            <button type="button" class="modal-close" onclick="fecharModal()">&times;</button>
        </div>
        <div class="modal-body" id="versoesContent">
            <!-- Preenchido via JS -->
        </div>
    </div>
</div>

<!-- Modal de Impressão / PDF -->
<div class="modal-overlay" id="modalImpressao">
    <div class="modal-content" style="max-width: 520px;">
        <div class="modal-header">
            <h3>🖨️ Imprimir / PDF</h3>
            <button type="button" class="modal-close" onclick="fecharModalImpressao()">&times;</button>
        </div>
        <div class="modal-body">
            <p style="margin: 0; color: #64748b;">Escolha a aba para imprimir ou gerar PDF.</p>
            <div style="margin-top: 1rem; display: grid; gap: 0.75rem;">
                <div>
                    <label for="printSectionSelect" style="display:block; font-weight: 700; color:#334155; font-size: 0.85rem; margin-bottom: 0.35rem;">Aba</label>
                    <select id="printSectionSelect" style="width:100%; padding: 0.65rem 0.8rem; border:1px solid #e2e8f0; border-radius: 10px; background:#fff;">
                        <option value="decoracao">Decoração</option>
                        <option value="observacoes_gerais">Observações Gerais</option>
                        <option value="dj_protocolo">DJ / Protocolos</option>
                    </select>
                </div>
                <div style="display:flex; gap: 0.75rem; justify-content: flex-end; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary" onclick="emitirDocumentoReuniao('print')">Imprimir</button>
                    <button type="button" class="btn btn-primary" onclick="emitirDocumentoReuniao('pdf')">Baixar PDF</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const meetingId = <?= $meeting_id ?: 'null' ?>;
const legacyDjSectionLocked = <?= !empty($secoes['dj_protocolo']['is_locked']) ? 'true' : 'false' ?>;
const initialTab = <?= json_encode(in_array($active_tab_query, array_keys($section_labels), true) ? $active_tab_query : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialDecoracaoSchema = <?= json_encode($decoracao_schema_saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialObservacoesSchema = <?= json_encode($observacoes_schema_saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialDjSchema = <?= json_encode($dj_schema_saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialDjLinks = <?= json_encode(array_map(static function (array $link): array {
    return [
        'id' => (int)($link['id'] ?? 0),
        'token' => (string)($link['token'] ?? ''),
        'slot_index' => (int)($link['slot_index'] ?? 1),
        'form_title' => (string)($link['form_title'] ?? ''),
        'submitted_at' => array_key_exists('submitted_at', $link) && $link['submitted_at'] !== null ? (string)$link['submitted_at'] : null,
        'form_schema' => is_array($link['form_schema'] ?? null) ? $link['form_schema'] : [],
    ];
}, $links_cliente_dj), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let selectedEventId = null;
let selectedEventData = null;
let searchDebounceTimer = null;
let searchAbortController = null;
let eventsCacheLoaded = false;
let eventsMasterCache = [];
const eventsQueryCache = new Map();
let savedFormTemplates = <?= json_encode(array_map(static function(array $template): array {
    return [
        'id' => (int)($template['id'] ?? 0),
        'nome' => (string)($template['nome'] ?? ''),
        'categoria' => (string)($template['categoria'] ?? 'geral'),
        'updated_at' => (string)($template['updated_at'] ?? ''),
        'created_by_user_id' => (int)($template['created_by_user_id'] ?? 0),
        'schema' => is_array($template['schema'] ?? null) ? $template['schema'] : [],
    ];
}, $form_templates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const DJ_SLOT_MIN = 1;
const DJ_SLOT_MAX = 50;
let djSlotOrder = [];
let selectedDjTemplateIds = {};
let lastSavedDjSchemaSignatures = {};
let djLinksBySlot = {};
let selectedSectionTemplateIds = {
    decoracao: null,
    observacoes_gerais: null,
};
let lastSavedSectionSchemaSignatures = {
    decoracao: '',
    observacoes_gerais: '',
};
const sectionLockedState = <?= json_encode([
    'decoracao' => !empty($secoes['decoracao']['is_locked']),
    'observacoes_gerais' => !empty($secoes['observacoes_gerais']['is_locked']),
    'dj_protocolo' => !empty($secoes['dj_protocolo']['is_locked']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let sectionFormDraftValues = {
    decoracao: {},
    observacoes_gerais: {},
};

var tinymceLoadTimeout = null;
var tinymceRetryCount = 0;
var TINYMCE_CDNS = [
    'https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js',
    'https://unpkg.com/tinymce@6/tinymce.min.js'
];

function showEditorLoadError(msg, src) {
    var firstWrap = document.querySelector('.editor-wrapper');
    document.querySelectorAll('[id^="editor-"]').forEach(function(el) { el.placeholder = ''; });
    if (firstWrap && !firstWrap.querySelector('.editor-load-error')) {
        var div = document.createElement('div');
        div.className = 'editor-load-error';
        div.style.cssText = 'padding:1rem;background:#fef2f2;color:#b91c1c;border-radius:8px;margin-bottom:8px;';
        var sourceInfo = src ? '<br><small>URL: ' + src + '</small>' : '';
        div.innerHTML = '<p style="margin:0 0 8px 0;">' + msg + sourceInfo + '</p><button type="button" class="btn btn-primary" onclick="retryLoadTinyMCE()">Tentar novamente</button>';
        firstWrap.insertBefore(div, firstWrap.firstChild);
    }
}

function retryLoadTinyMCE() {
    document.querySelectorAll('.editor-load-error').forEach(function(el) { el.remove(); });
    tinymceRetryCount = 0;
    loadTinyMCEAndInit();
}

function loadTinyMCEAndInit() {
    if (!meetingId) return;
    var ta = document.getElementById('editor-decoracao');
    if (ta) ta.placeholder = 'Carregando editor...';

    if (typeof tinymce !== 'undefined') {
        initEditoresReuniao();
        return;
    }

    if (tinymceLoadTimeout) clearTimeout(tinymceLoadTimeout);
    var cdnIndex = Math.min(tinymceRetryCount, TINYMCE_CDNS.length - 1);
    var scriptUrl = TINYMCE_CDNS[cdnIndex];
    var script = document.createElement('script');
    script.src = scriptUrl;
    script.async = false;
    script.onload = function() {
        if (tinymceLoadTimeout) clearTimeout(tinymceLoadTimeout);
        tinymceRetryCount = 0;
        initEditoresReuniao();
    };
    script.onerror = function() {
        if (tinymceLoadTimeout) clearTimeout(tinymceLoadTimeout);
        tinymceRetryCount++;
        if (tinymceRetryCount < TINYMCE_CDNS.length) {
            loadTinyMCEAndInit();
        } else {
            showEditorLoadError('Editor não carregou (rede ou bloqueador). Tente desativar bloqueador de anúncios ou use outro navegador.', scriptUrl);
        }
    };
    document.head.appendChild(script);
    tinymceLoadTimeout = setTimeout(function() {
        tinymceLoadTimeout = null;
        if (typeof tinymce === 'undefined') {
            showEditorLoadError('Editor demorou para carregar. Verifique sua conexão e tente novamente.', scriptUrl);
        }
    }, 15000);
}

// Inicializar TinyMCE nos editores da reunião (toolbar completa + imagens)
function initEditoresReuniao() {
    if (!meetingId) return;
    if (typeof tinymce === 'undefined') return;
    document.querySelectorAll('.editor-load-error').forEach(function(el) { el.remove(); });
    document.querySelectorAll('[id^="editor-"]').forEach(function(el) { el.placeholder = ''; });
    var sections = ['decoracao', 'observacoes_gerais', 'dj_protocolo'];
    sections.forEach(function(section) {
        var textarea = document.getElementById('editor-' + section);
        if (!textarea) return;
        var isReadonly = textarea.readOnly;
        tinymce.init({
            selector: '#editor-' + section,
            base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
            suffix: '.min',
            plugins: 'lists link image table code',
            toolbar: 'undo redo | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright justify | bullist numlist outdent indent | link image table | removeformat',
            menubar: false,
            height: 420,
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; }',
            readonly: isReadonly,
            paste_data_images: true,
            automatic_uploads: true,
            images_upload_handler: function (blobInfo, progress) {
                return new Promise(function (resolve, reject) {
                    var xhr = new XMLHttpRequest();
                    var formData = new FormData();
                    formData.append('meeting_id', String(meetingId));
                    formData.append('file', blobInfo.blob(), blobInfo.filename());
                    var uploadUrl = (window.location.origin || '') + (window.location.pathname || '/') + '?page=eventos_upload_imagem';
                    xhr.open('POST', uploadUrl);
                    xhr.onload = function () {
                        if (xhr.status < 200 || xhr.status >= 300) {
                            reject('Upload falhou: ' + xhr.status);
                            return;
                        }
                        try {
                            var j = JSON.parse(xhr.responseText);
                            if (j.location) resolve(j.location);
                            else reject(j.error || 'Resposta inválida');
                        } catch (e) {
                            reject('Resposta inválida');
                        }
                    };
                    xhr.onerror = function () { reject('Erro de rede'); };
                    xhr.send(formData);
                });
            }
        });
    });
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
    if (!q) {
        return eventsMasterCache.slice(0, 50);
    }
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

function renderEventsList(events, query = '') {
    const list = document.getElementById('eventsList');
    if (!list) return;

    if (!events || events.length === 0) {
        list.innerHTML = `<div style="padding: 1rem; color: #64748b;">Nenhum evento encontrado</div>`;
        list.style.display = 'block';
        return;
    }

    const selectedId = Number(selectedEventId || 0);
    list.innerHTML = events.map((ev) => {
        const label = ev.label || `${ev.nome || 'Evento'} - ${ev.data_formatada || ''}`;
        const isSelected = selectedId > 0 && Number(ev.id) === selectedId;
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

async function fetchRemoteEvents(query = '', forceRefresh = false) {
    const key = `${query}::${forceRefresh ? '1' : '0'}`;
    if (!forceRefresh && eventsQueryCache.has(key)) {
        return { ok: true, events: eventsQueryCache.get(key), fromCache: true };
    }

    if (searchAbortController) {
        searchAbortController.abort();
    }
    searchAbortController = new AbortController();

    const url = `index.php?page=eventos_me_proxy&action=list&search=${encodeURIComponent(query)}&days=120${forceRefresh ? '&refresh=1' : ''}`;
    const resp = await fetch(url, { signal: searchAbortController.signal });
    const data = await resp.json();
    if (!data.ok) {
        throw new Error(data.error || 'Erro ao buscar eventos');
    }
    const events = data.events || [];
    eventsQueryCache.set(key, events);

    if (!query) {
        eventsMasterCache = events;
        eventsCacheLoaded = true;
    } else if (eventsMasterCache.length > 0) {
        const existingIds = new Set(eventsMasterCache.map((e) => e.id));
        events.forEach((ev) => {
            if (!existingIds.has(ev.id)) {
                eventsMasterCache.push(ev);
            }
        });
    }

    return { ok: true, events, fromCache: false };
}

// Buscar eventos da ME (smart search com cache local + debounce)
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
            const remote = await fetchRemoteEvents('', false);
            renderEventsList(remote.events, query);
        }

        const localResults = localFilterEvents(query);
        renderEventsList(localResults, query);
        loading.style.display = 'none';

        if ((query.length >= 2 && forceRemote) || (query.length >= 3 && localResults.length < 8) || (forceRemote && query.length === 0)) {
            const remote = await fetchRemoteEvents(query, forceRemote);
            renderEventsList(remote.events, query);
        }
    } catch (err) {
        if (err && err.name === 'AbortError') {
            return;
        }
        loading.style.display = 'none';
        list.innerHTML = `<div style="padding: 1rem; color: #dc2626;">Erro: ${err.message}</div>`;
        list.style.display = 'block';
    }
}

function renderSelectedEventSummary(ev) {
    const summary = document.getElementById('selectedEventSummary');
    if (!summary) return;
    if (!ev) {
        summary.innerHTML = '';
        summary.style.display = 'none';
        return;
    }
    summary.innerHTML = `
        <strong>Selecionado:</strong> ${ev.nome || 'Evento'}<br>
        <span>${ev.data_formatada || '-'} • ${ev.hora || '-'} • ${ev.local || 'Local não informado'} • ${ev.cliente || 'Cliente'}</span>
    `;
    summary.style.display = 'block';
}

// Selecionar evento
function selectEvent(el, id) {
    selectedEventId = id;
    selectedEventData = (eventsMasterCache || []).find((ev) => Number(ev.id) === Number(id))
        || Array.from(eventsQueryCache.values()).flat().find((ev) => Number(ev.id) === Number(id))
        || null;

    document.querySelectorAll('.event-item').forEach(el => el.classList.remove('selected'));
    if (el) {
        el.classList.add('selected');
    }

    renderSelectedEventSummary(selectedEventData);
    document.getElementById('selectedEvent').style.display = 'block';
}

// Criar reunião
async function criarReuniao() {
    if (!selectedEventId) {
        alert('Selecione um evento primeiro');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'criar_reuniao');
        formData.append('me_event_id', selectedEventId);
        
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        
        if (data.ok && data.reuniao) {
            window.location.href = `index.php?page=eventos_reuniao_final&id=${data.reuniao.id}`;
        } else {
            alert(data.error || 'Erro ao criar reunião');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

// Trocar aba
function switchTab(section) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
    
    document.querySelector(`.tab-btn[onclick="switchTab('${section}')"]`).classList.add('active');
    document.getElementById(`tab-${section}`).classList.add('active');
}

function applyInitialTabFromQuery() {
    if (!initialTab) return;
    const tab = document.getElementById(`tab-${initialTab}`);
    if (!tab) return;
    switchTab(initialTab);
}

function escapeHtmlForField(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

function getEditorContent(section = 'dj_protocolo') {
    if (typeof tinymce !== 'undefined' && tinymce.get('editor-' + section)) {
        return tinymce.get('editor-' + section).getContent() || '';
    }
    const el = document.getElementById('editor-' + section);
    return el ? (el.value || '') : '';
}

function setEditorContent(content, section = 'dj_protocolo') {
    if (typeof tinymce !== 'undefined' && tinymce.get('editor-' + section)) {
        tinymce.get('editor-' + section).setContent(content || '');
        return;
    }
    const el = document.getElementById('editor-' + section);
    if (el) {
        el.value = content || '';
    }
}

function appendEditorContent(content, section = 'dj_protocolo') {
    const current = getEditorContent(section);
    const html = current ? `${current}\n${content}` : content;
    setEditorContent(html, section);
}

function normalizeFormSchema(schema) {
    if (!Array.isArray(schema)) return [];
    const allowedTypes = ['text', 'textarea', 'yesno', 'select', 'file', 'section', 'divider', 'note'];
    return schema.map((field) => {
        let type = String(field.type || 'text').trim().toLowerCase();
        if (!allowedTypes.includes(type)) type = 'text';
        const options = Array.isArray(field.options) ? field.options.map((v) => String(v).trim()).filter(Boolean) : [];
        const neverRequired = ['section', 'divider', 'note'].includes(type);
        return {
            id: String(field.id || ('f_' + Math.random().toString(36).slice(2, 10))),
            type: type,
            label: String(field.label || '').trim(),
            required: neverRequired ? false : !!field.required,
            options: options
        };
    }).filter((field) => {
        if (field.type === 'divider') return true;
        return field.label !== '';
    });
}

function hasUsefulSchemaFields(schema) {
    if (!Array.isArray(schema)) return false;
    return schema.some((field) => {
        const type = String(field && field.type ? field.type : '').toLowerCase();
        const label = String(field && field.label ? field.label : '').trim();
        return ['text', 'textarea', 'yesno', 'select', 'file'].includes(type) && label !== '';
    });
}

function stripHtmlToText(html) {
    const div = document.createElement('div');
    div.innerHTML = html || '';
    return (div.textContent || div.innerText || '').trim();
}

function isLegacyGeneratedSchemaHtml(html) {
    const text = stripHtmlToText(html || '');
    if (!text) return false;
    return text.includes('Estrutura gerada por campos dinâmicos') || text.includes('Campo de upload de arquivo');
}

function normalizeSlotIndex(slot) {
    const parsed = Number(slot);
    if (!Number.isInteger(parsed)) return null;
    if (parsed < DJ_SLOT_MIN || parsed > DJ_SLOT_MAX) return null;
    return parsed;
}

function ensureDjSlotState(slot) {
    if (!Object.prototype.hasOwnProperty.call(selectedDjTemplateIds, slot)) {
        selectedDjTemplateIds[slot] = null;
    }
    if (!Object.prototype.hasOwnProperty.call(lastSavedDjSchemaSignatures, slot)) {
        lastSavedDjSchemaSignatures[slot] = '';
    }
    if (!Object.prototype.hasOwnProperty.call(djLinksBySlot, slot)) {
        djLinksBySlot[slot] = null;
    }
}

function getSortedDjSlots() {
    return djSlotOrder
        .map((slot) => normalizeSlotIndex(slot))
        .filter((slot) => slot !== null)
        .sort((a, b) => a - b);
}

function djSlotExists(slot) {
    const normalized = normalizeSlotIndex(slot);
    if (normalized === null) return false;
    return djSlotOrder.includes(normalized);
}

function findNextDjSlotIndex() {
    const used = new Set(getSortedDjSlots());
    for (let slot = DJ_SLOT_MIN; slot <= DJ_SLOT_MAX; slot += 1) {
        if (!used.has(slot)) {
            return slot;
        }
    }
    return null;
}

function buildDjSlotCardHtml(slot) {
    return `
        <div class="dj-builder-shell" data-dj-slot="${slot}">
            <div class="dj-builder-head">
                <div>
                    <h4 class="dj-builder-title">🧩 Formulário DJ / Protocolos • Quadro ${slot}</h4>
                    <div class="dj-builder-subtitle">Selecione um formulário salvo e gere o link deste quadro para o cliente.</div>
                </div>
                <div class="dj-top-actions">
                    <button type="button" class="btn btn-primary" onclick="gerarLinkCliente(${slot})" id="btnGerarLink-${slot}">Gerar link</button>
                    <button type="button" class="btn btn-secondary" onclick="destravarDjSlot(${slot})" id="btnDestravarDjSlot-${slot}" style="display:none;">🔓 Destravar</button>
                    <button type="button" class="btn btn-secondary btn-slot-remove" onclick="excluirDjSlot(${slot})">🗑 Excluir quadro</button>
                </div>
            </div>
            <div class="prefill-field" style="margin-top: 0.5rem;">
                <label for="djTemplateSelect-${slot}">Formulário salvo</label>
                <select id="djTemplateSelect-${slot}" onchange="onChangeDjTemplateSelect(${slot})">
                    <option value="">Selecione um formulário...</option>
                </select>
            </div>
            <div class="builder-field-meta" id="selectedDjTemplateMeta-${slot}" style="margin-top: 0.55rem;">Nenhum formulário selecionado.</div>
            <p class="share-hint" id="shareHint-${slot}">Selecione um formulário para habilitar o compartilhamento.</p>
            <div class="link-display">
                <input type="text" id="clienteLinkInput-${slot}" class="link-input" readonly placeholder="Clique em 'Gerar link' para criar">
                <button type="button" class="btn btn-secondary" onclick="copiarLink(${slot})" id="btnCopiar-${slot}" style="display:none;">📋 Copiar</button>
            </div>
        </div>
    `;
}

function renderDjSlots() {
    const container = document.getElementById('djSlotsContainer');
    const empty = document.getElementById('djSlotsEmptyState');
    if (!container || !empty) return;

    const slots = getSortedDjSlots();
    if (slots.length === 0) {
        container.innerHTML = '';
        empty.style.display = 'block';
        return;
    }

    empty.style.display = 'none';
    container.innerHTML = slots.map((slot) => buildDjSlotCardHtml(slot)).join('');

    slots.forEach((slot) => {
        ensureDjSlotState(slot);
        renderDjTemplateSelect(slot);
        const link = djLinksBySlot[slot] || null;
        if (link && link.token) {
            setDjLinkOutput(slot, `${window.location.origin}/index.php?page=eventos_cliente_dj&token=${link.token}`);
        } else {
            setDjLinkOutput(slot, '');
        }
        updateShareAvailability(slot);
    });
}

function addDjSlot(preferredSlot = null) {
    const slot = preferredSlot !== null ? normalizeSlotIndex(preferredSlot) : findNextDjSlotIndex();
    if (slot === null) {
        alert('Limite de quadros atingido (máximo de 50).');
        return null;
    }
    if (!djSlotExists(slot)) {
        djSlotOrder.push(slot);
    }
    djSlotOrder = getSortedDjSlots();
    ensureDjSlotState(slot);
    renderDjSlots();

    const select = document.getElementById(`djTemplateSelect-${slot}`);
    if (select) {
        select.focus();
    }
    return slot;
}

async function excluirDjSlot(slot = 1) {
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !djSlotExists(slotIndex)) {
        return;
    }

    const link = djLinksBySlot[slotIndex] || null;
    if (link && link.submitted_at) {
        alert('Este quadro já foi enviado pelo cliente e não pode ser excluído.');
        return;
    }

    if (!confirm(`Excluir o quadro ${slotIndex}?`)) {
        return;
    }

    if (meetingId) {
        try {
            const formData = new FormData();
            formData.append('action', 'excluir_dj_slot');
            formData.append('meeting_id', String(meetingId));
            formData.append('slot_index', String(slotIndex));
            const resp = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const data = await resp.json();
            if (!data.ok) {
                alert(data.error || 'Erro ao excluir quadro');
                return;
            }
        } catch (err) {
            alert('Erro: ' + err.message);
            return;
        }
    }

    djSlotOrder = getSortedDjSlots().filter((item) => item !== slotIndex);
    delete selectedDjTemplateIds[slotIndex];
    delete lastSavedDjSchemaSignatures[slotIndex];
    delete djLinksBySlot[slotIndex];
    renderDjSlots();
}

function getSelectedDjTemplateData(slot) {
    if (!djSlotExists(slot)) {
        return { template: null, schema: [] };
    }
    const templateId = Number(selectedDjTemplateIds[slot] || 0);
    if (templateId <= 0) {
        return { template: null, schema: [] };
    }
    const template = savedFormTemplates.find((item) => Number(item.id) === templateId) || null;
    const schema = normalizeFormSchema(template && Array.isArray(template.schema) ? template.schema : []);
    return { template, schema };
}

function isDjSlotLocked(slot) {
    const link = djLinksBySlot[slot] || null;
    if (link && link.submitted_at) {
        return true;
    }
    if (legacyDjSectionLocked && Number(slot) === 1 && link) {
        return true;
    }
    return false;
}

function updateShareAvailability(slot = 1) {
    const shareBtn = document.getElementById(`btnGerarLink-${slot}`);
    const hint = document.getElementById(`shareHint-${slot}`);
    const unlockBtn = document.getElementById(`btnDestravarDjSlot-${slot}`);
    const select = document.getElementById(`djTemplateSelect-${slot}`);
    if (!shareBtn) return;

    let disabled = false;
    let hintText = 'Selecione um formulário para habilitar o compartilhamento.';

    if (isDjSlotLocked(slot)) {
        disabled = true;
        hintText = 'Este quadro está travado (cliente já enviou). Clique em "Destravar" para permitir nova edição.';
        if (unlockBtn) unlockBtn.style.display = 'inline-flex';
        if (select) select.disabled = true;
    } else {
        if (unlockBtn) unlockBtn.style.display = 'none';
        if (select) select.disabled = false;

        const selected = getSelectedDjTemplateData(slot);
        if (!selected.template) {
            disabled = true;
        } else if (!hasUsefulSchemaFields(selected.schema)) {
            disabled = true;
            hintText = 'O formulário selecionado não possui campos válidos.';
        } else {
            hintText = `Clique em Gerar link para criar/usar o link do quadro ${slot}.`;
        }
    }

    shareBtn.disabled = disabled;
    if (hint) hint.textContent = hintText;
}

function setDjLinkOutput(slot, url) {
    const input = document.getElementById(`clienteLinkInput-${slot}`);
    const copyBtn = document.getElementById(`btnCopiar-${slot}`);
    if (input) {
        input.value = url || '';
    }
    if (copyBtn) {
        copyBtn.style.display = url ? 'inline-flex' : 'none';
    }
}

function buildSchemaHtmlForStorage(schema, title = 'Formulário DJ / Protocolos') {
    if (!Array.isArray(schema) || schema.length === 0) return '';
    let html = `<h2>${escapeHtmlForField(title)}</h2>`;
    html += '<p><em>Estrutura gerada por campos dinâmicos (estilo formulário).</em></p>';
    schema.forEach((field) => {
        const label = escapeHtmlForField(field.label || '');
        const req = field.required ? ' <span style="color:#b91c1c">*</span>' : '';
        if (field.type === 'divider') {
            html += '<hr>';
            return;
        }
        if (field.type === 'section') {
            html += `<h3>${label}</h3>`;
            return;
        }
        if (field.type === 'note') {
            html += `<p><em>${label}</em></p>`;
            return;
        }
        if (field.type === 'yesno') {
            html += `<p><strong>${label}${req}</strong><br>( ) Sim &nbsp;&nbsp; ( ) Não</p>`;
            return;
        }
        if (field.type === 'select') {
            const options = Array.isArray(field.options) ? field.options : [];
            html += `<p><strong>${label}${req}</strong></p><ul>${options.map((opt) => `<li>${escapeHtmlForField(opt)}</li>`).join('')}</ul>`;
            return;
        }
        if (field.type === 'file') {
            html += `<p><strong>${label}${req}</strong><br><em>Campo de upload de arquivo</em></p>`;
            return;
        }
        html += `<p><strong>${label}${req}</strong><br>________________________________________</p>`;
    });
    return html;
}

function getSchemaSignature(schema) {
    return JSON.stringify(normalizeFormSchema(schema || []));
}

function renderDjTemplateSelect(slot = 1) {
    const select = document.getElementById(`djTemplateSelect-${slot}`);
    if (!select) return;

    const current = selectedDjTemplateIds[slot] ? String(selectedDjTemplateIds[slot]) : '';
    const options = ['<option value="">Selecione um formulário...</option>'];
    (savedFormTemplates || []).forEach((template) => {
        const id = Number(template.id || 0);
        if (!id) return;
        const label = `${String(template.nome || 'Modelo sem nome')} - ${String(template.categoria || 'geral')}`;
        const selected = String(id) === current ? ' selected' : '';
        options.push(`<option value="${id}"${selected}>${escapeHtmlForField(label)}</option>`);
    });
    select.innerHTML = options.join('');
    updateSelectedDjTemplateMeta(slot);
}

function updateSelectedDjTemplateMeta(slot = 1) {
    const meta = document.getElementById(`selectedDjTemplateMeta-${slot}`);
    if (!meta) return;

    const templateId = Number(selectedDjTemplateIds[slot] || 0);
    if (templateId <= 0) {
        meta.textContent = 'Nenhum formulário selecionado.';
        return;
    }

    const template = savedFormTemplates.find((item) => Number(item.id) === templateId);
    if (!template) {
        meta.textContent = 'Formulário selecionado não encontrado.';
        return;
    }

    const stamp = template.updated_at ? formatDate(template.updated_at) : 'Sem data';
    meta.textContent = `${String(template.nome || 'Modelo sem nome')} • ${String(template.categoria || 'geral')} • Atualizado em ${stamp}`;
}

function onChangeDjTemplateSelect(slot = 1) {
    const select = document.getElementById(`djTemplateSelect-${slot}`);
    selectedDjTemplateIds[slot] = select && select.value ? Number(select.value) : null;
    updateSelectedDjTemplateMeta(slot);
    updateShareAvailability(slot);
}

function renderAllDjTemplateSelects() {
    renderDjSlots();
}

function getSectionFormTitle(section) {
    if (section === 'decoracao') {
        return 'Formulário de Decoração';
    }
    if (section === 'observacoes_gerais') {
        return 'Formulário de Observações Gerais';
    }
    return 'Formulário';
}

function getSectionLegacyTitle(section) {
    if (section === 'decoracao') {
        return 'Observações complementares da decoração';
    }
    if (section === 'observacoes_gerais') {
        return 'Observações gerais complementares';
    }
    return 'Observações complementares';
}

function getSelectedSectionSchema(section) {
    const templateId = selectedSectionTemplateIds[section] || null;
    if (!templateId) return [];
    const template = savedFormTemplates.find((item) => Number(item.id) === Number(templateId));
    if (!template) return [];
    const normalizedSchema = normalizeFormSchema(Array.isArray(template.schema) ? template.schema : []);
    if (!hasUsefulSchemaFields(normalizedSchema)) return [];
    return normalizedSchema;
}

function encodePayloadBase64(payload) {
    try {
        const json = JSON.stringify(payload);
        return btoa(unescape(encodeURIComponent(json)));
    } catch (err) {
        return '';
    }
}

function decodePayloadBase64(encoded) {
    try {
        const json = decodeURIComponent(escape(atob(encoded)));
        return JSON.parse(json);
    } catch (err) {
        return null;
    }
}

function extractSectionPayloadFromContent(contentHtml) {
    if (!contentHtml) return null;
    const wrapper = document.createElement('div');
    wrapper.innerHTML = contentHtml;
    const marker = wrapper.querySelector('[data-smile-form-payload]');
    if (!marker) return null;
    const encoded = String(marker.getAttribute('data-smile-form-payload') || '');
    if (!encoded) return null;
    return decodePayloadBase64(encoded);
}

function sectionHasAnyDraftValue(section) {
    const draft = sectionFormDraftValues[section] || {};
    return Object.values(draft).some((value) => String(value || '').trim() !== '');
}

function getFieldDomId(section, fieldId) {
    const safe = String(fieldId || '').replace(/[^a-zA-Z0-9_-]/g, '_');
    return `section-field-${section}-${safe}`;
}

function getSectionFormValuesFromDom(section) {
    const container = document.getElementById(`sectionFormFields-${section}`);
    if (!container) return {};
    const values = {};
    container.querySelectorAll('[data-section-field]').forEach((el) => {
        const fieldId = String(el.getAttribute('data-field-id') || '').trim();
        if (!fieldId) return;
        values[fieldId] = String(el.value || '');
    });
    return values;
}

function syncSectionFormDraft(section) {
    sectionFormDraftValues[section] = getSectionFormValuesFromDom(section);
}

function renderSectionTemplateForm(section) {
    const box = document.getElementById(`sectionFormBox-${section}`);
    const fieldsWrap = document.getElementById(`sectionFormFields-${section}`);
    const hint = document.getElementById(`sectionFormHint-${section}`);
    if (!box || !fieldsWrap) return;

    const schema = getSelectedSectionSchema(section);
    if (!schema.length) {
        box.style.display = 'none';
        fieldsWrap.innerHTML = '';
        if (hint) {
            hint.textContent = 'Selecione um formulário para preencher esta seção.';
        }
        return;
    }

    box.style.display = 'block';
    const disabledAttr = sectionLockedState[section] ? ' disabled' : '';
    fieldsWrap.innerHTML = schema.map((field) => {
        const type = String(field.type || 'text');
        const label = escapeHtmlForField(String(field.label || 'Campo'));
        const required = !!field.required;
        const requiredMark = required ? ' *' : '';
        const requiredAttr = required ? ' required' : '';
        const fieldId = String(field.id || '');
        const domId = getFieldDomId(section, fieldId);
        const dataAttrs = `data-section-field="1" data-field-id="${escapeHtmlForField(fieldId)}"`;

        if (type === 'divider') {
            return '<hr class="section-form-divider">';
        }
        if (type === 'section') {
            return `<h4 class="section-form-title">${label}</h4>`;
        }
        if (type === 'note') {
            return `<p class="section-form-note">${label}</p>`;
        }
        if (type === 'textarea') {
            return `
                <div class="section-form-item">
                    <label for="${domId}">${label}${requiredMark}</label>
                    <textarea id="${domId}" ${dataAttrs}${requiredAttr}${disabledAttr}></textarea>
                </div>
            `;
        }
        if (type === 'yesno') {
            return `
                <div class="section-form-item">
                    <label for="${domId}">${label}${requiredMark}</label>
                    <select id="${domId}" ${dataAttrs}${requiredAttr}${disabledAttr}>
                        <option value="">Selecione...</option>
                        <option value="sim">Sim</option>
                        <option value="nao">Não</option>
                    </select>
                </div>
            `;
        }
        if (type === 'select') {
            const options = Array.isArray(field.options) ? field.options : [];
            const optionsHtml = options.map((opt) => {
                const text = escapeHtmlForField(String(opt || ''));
                return `<option value="${text}">${text}</option>`;
            }).join('');
            return `
                <div class="section-form-item">
                    <label for="${domId}">${label}${requiredMark}</label>
                    <select id="${domId}" ${dataAttrs}${requiredAttr}${disabledAttr}>
                        <option value="">Selecione...</option>
                        ${optionsHtml}
                    </select>
                </div>
            `;
        }
        if (type === 'file') {
            return `
                <div class="section-form-item">
                    <label for="${domId}">${label}${requiredMark}</label>
                    <input type="text" id="${domId}" ${dataAttrs}${requiredAttr}${disabledAttr} placeholder="Informe nome, link ou referência do arquivo">
                </div>
            `;
        }
        return `
            <div class="section-form-item">
                <label for="${domId}">${label}${requiredMark}</label>
                <input type="text" id="${domId}" ${dataAttrs}${requiredAttr}${disabledAttr}>
            </div>
        `;
    }).join('');

    const draft = sectionFormDraftValues[section] || {};
    fieldsWrap.querySelectorAll('[data-section-field]').forEach((el) => {
        const fieldId = String(el.getAttribute('data-field-id') || '').trim();
        if (!fieldId) return;
        if (Object.prototype.hasOwnProperty.call(draft, fieldId)) {
            el.value = String(draft[fieldId] ?? '');
        }
        const eventName = el.tagName === 'SELECT' ? 'change' : 'input';
        el.addEventListener(eventName, () => syncSectionFormDraft(section));
        if (el.tagName !== 'SELECT') {
            el.addEventListener('change', () => syncSectionFormDraft(section));
        }
    });

    if (hint) {
        hint.textContent = sectionLockedState[section]
            ? 'Seção travada. Os campos aparecem apenas para consulta.'
            : 'Preencha os campos e clique em Salvar para registrar uma nova versão.';
    }
}

function toggleLegacyEditor(section) {
    const wrap = document.getElementById(`legacyEditorWrap-${section}`);
    const btn = document.getElementById(`btnToggleEditor-${section}`);
    if (!wrap || !btn) return;
    const isOpen = wrap.style.display !== 'none';
    wrap.style.display = isOpen ? 'none' : 'block';
    btn.textContent = isOpen ? 'Abrir texto' : 'Fechar texto';
}

function findTemplateIdBySchemaSignature(signature) {
    if (!signature) return null;
    const match = (savedFormTemplates || []).find((template) => {
        const normalized = normalizeFormSchema(Array.isArray(template.schema) ? template.schema : []);
        return getSchemaSignature(normalized) === signature;
    });
    return match ? Number(match.id || 0) : null;
}

function renderSectionTemplateSelect(section) {
    const select = document.getElementById(`sectionTemplateSelect-${section}`);
    if (!select) return;

    const current = selectedSectionTemplateIds[section] ? String(selectedSectionTemplateIds[section]) : '';
    const options = ['<option value="">Nenhum formulário</option>'];
    (savedFormTemplates || []).forEach((template) => {
        const id = Number(template.id || 0);
        if (!id) return;
        const label = `${String(template.nome || 'Modelo sem nome')} - ${String(template.categoria || 'geral')}`;
        const selected = String(id) === current ? ' selected' : '';
        options.push(`<option value="${id}"${selected}>${escapeHtmlForField(label)}</option>`);
    });
    select.innerHTML = options.join('');
    updateSectionTemplateMeta(section);
}

function updateSectionTemplateMeta(section) {
    const meta = document.getElementById(`sectionTemplateMeta-${section}`);
    if (!meta) return;

    const templateId = selectedSectionTemplateIds[section] || null;
    if (!templateId) {
        meta.textContent = 'Nenhum formulário selecionado.';
        return;
    }

    const template = savedFormTemplates.find((item) => Number(item.id) === Number(templateId));
    if (!template) {
        meta.textContent = 'Formulário selecionado não encontrado.';
        return;
    }

    const stamp = template.updated_at ? formatDate(template.updated_at) : 'Sem data';
    meta.textContent = `${String(template.nome || 'Modelo sem nome')} • ${String(template.categoria || 'geral')} • Atualizado em ${stamp}`;
}

function onChangeSectionTemplateSelect(section) {
    const select = document.getElementById(`sectionTemplateSelect-${section}`);
    const previousTemplateId = selectedSectionTemplateIds[section] || null;
    const nextTemplateId = select && select.value ? Number(select.value) : null;

    if (previousTemplateId !== nextTemplateId && sectionHasAnyDraftValue(section)) {
        const confirmed = confirm('Trocar o formulário vai limpar o preenchimento atual desta seção. Continuar?');
        if (!confirmed) {
            if (select) {
                select.value = previousTemplateId ? String(previousTemplateId) : '';
            }
            return;
        }
        sectionFormDraftValues[section] = {};
    }

    selectedSectionTemplateIds[section] = nextTemplateId;
    updateSectionTemplateMeta(section);
    renderSectionTemplateForm(section);
}

function aplicarTemplateNaSecao(section) {
    const templateId = selectedSectionTemplateIds[section] || null;
    if (!templateId) {
        alert('Selecione um formulário para aplicar nesta seção.');
        return;
    }

    const template = savedFormTemplates.find((item) => Number(item.id) === Number(templateId));
    if (!template) {
        alert('Formulário selecionado não encontrado.');
        return;
    }

    const normalizedSchema = normalizeFormSchema(Array.isArray(template.schema) ? template.schema : []);
    if (!hasUsefulSchemaFields(normalizedSchema)) {
        alert('O formulário selecionado não possui campos válidos.');
        return;
    }
    renderSectionTemplateForm(section);
}

function renderAllSectionTemplateSelects() {
    ['decoracao', 'observacoes_gerais'].forEach((section) => {
        renderSectionTemplateSelect(section);
        renderSectionTemplateForm(section);
    });
}

function hydrateSectionFormDraftFromSavedContent(section) {
    const payload = extractSectionPayloadFromContent(getEditorContent(section));
    if (!payload || typeof payload !== 'object') return;

    const payloadTemplateId = Number(payload.template_id || 0) || null;
    if (!selectedSectionTemplateIds[section] && payloadTemplateId) {
        selectedSectionTemplateIds[section] = payloadTemplateId;
    }

    if (payload.values && typeof payload.values === 'object') {
        const normalizedValues = {};
        Object.keys(payload.values).forEach((key) => {
            normalizedValues[String(key)] = String(payload.values[key] ?? '');
        });
        sectionFormDraftValues[section] = normalizedValues;
    }

    if (typeof payload.legacy_html === 'string') {
        setEditorContent(payload.legacy_html, section);
    } else {
        setEditorContent('', section);
    }
}

function hydrateAllSectionFormDraftsFromSavedContent() {
    ['decoracao', 'observacoes_gerais'].forEach((section) => {
        hydrateSectionFormDraftFromSavedContent(section);
    });
}

function buildSectionContentFromForm(section, schema, values, legacyContentHtml) {
    const errors = [];
    const parts = [];
    const title = getSectionFormTitle(section);
    const templateId = selectedSectionTemplateIds[section] || null;

    parts.push(`<h2>${escapeHtmlForField(title)}</h2>`);
    parts.push('<p><em>Preenchimento interno por formulário.</em></p>');

    schema.forEach((field) => {
        const type = String(field.type || 'text');
        const label = String(field.label || 'Campo').trim();
        const required = !!field.required;
        const valueRaw = String(values[String(field.id || '')] || '').trim();

        if (type === 'divider') {
            parts.push('<hr>');
            return;
        }
        if (type === 'section') {
            parts.push(`<h3>${escapeHtmlForField(label)}</h3>`);
            return;
        }
        if (type === 'note') {
            parts.push(`<p><em>${escapeHtmlForField(label)}</em></p>`);
            return;
        }

        if (required && valueRaw === '') {
            errors.push(`Preencha o campo obrigatório: ${label}`);
            return;
        }

        if (type === 'yesno' && valueRaw !== '' && !['sim', 'nao'].includes(valueRaw)) {
            errors.push(`Valor inválido em: ${label}`);
            return;
        }

        if (type === 'select' && valueRaw !== '') {
            const options = Array.isArray(field.options) ? field.options.map((opt) => String(opt)) : [];
            if (!options.includes(valueRaw)) {
                errors.push(`Opção inválida em: ${label}`);
                return;
            }
        }

        let displayValue = valueRaw;
        if (type === 'yesno') {
            displayValue = valueRaw === 'sim' ? 'Sim' : (valueRaw === 'nao' ? 'Não' : '');
        }

        const answer = displayValue !== ''
            ? escapeHtmlForField(displayValue).replace(/\n/g, '<br>')
            : '<em>Não informado</em>';

        parts.push(`<p><strong>${escapeHtmlForField(label)}</strong><br>${answer}</p>`);
    });

    if (errors.length > 0) {
        return {
            ok: false,
            errors: errors,
            content_html: ''
        };
    }

    const trimmedLegacy = stripHtmlToText(legacyContentHtml);
    if (trimmedLegacy !== '') {
        parts.push('<hr>');
        parts.push(`<h3>${escapeHtmlForField(getSectionLegacyTitle(section))}</h3>`);
        parts.push(legacyContentHtml);
    }

    const payload = encodePayloadBase64({
        section: section,
        template_id: templateId,
        schema_signature: getSchemaSignature(schema),
        values: values,
        legacy_html: trimmedLegacy !== '' ? legacyContentHtml : '',
    });
    if (payload !== '') {
        parts.push(`<div data-smile-form-payload="${payload}" style="display:none;"></div>`);
    }

    return {
        ok: true,
        errors: [],
        content_html: parts.join('\n'),
    };
}

async function fetchTemplates() {
    const formData = new FormData();
    formData.append('action', 'listar_templates_form');
    const resp = await fetch(window.location.href, {
        method: 'POST',
        body: formData
    });
    const data = await resp.json();
    if (!data.ok) {
        throw new Error(data.error || 'Erro ao listar modelos');
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
    getSortedDjSlots().forEach((slot) => {
        const templateId = selectedDjTemplateIds[slot] || null;
        if (!templateId) return;
        const exists = savedFormTemplates.some((item) => Number(item.id) === Number(templateId));
        if (!exists) {
            selectedDjTemplateIds[slot] = null;
        }
    });
    ['decoracao', 'observacoes_gerais'].forEach((section) => {
        const templateId = selectedSectionTemplateIds[section] || null;
        if (!templateId) return;
        const exists = savedFormTemplates.some((item) => Number(item.id) === Number(templateId));
        if (!exists) {
            selectedSectionTemplateIds[section] = null;
        }
    });
    renderAllDjTemplateSelects();
    renderAllSectionTemplateSelects();
}

async function refreshDjTemplates() {
    try {
        await fetchTemplates();
    } catch (err) {
        console.error(err);
    }
    renderAllDjTemplateSelects();
}

function initDjTemplateSelection() {
    djSlotOrder = [];
    selectedDjTemplateIds = {};
    lastSavedDjSchemaSignatures = {};
    djLinksBySlot = {};

    if (Array.isArray(initialDjLinks)) {
        initialDjLinks.forEach((link) => {
            const slot = normalizeSlotIndex(link && link.slot_index ? link.slot_index : 1);
            if (slot === null) return;
            if (!link || !link.token) return;
            if (djSlotExists(slot) && djLinksBySlot[slot]) return;

            if (!djSlotExists(slot)) {
                djSlotOrder.push(slot);
            }
            ensureDjSlotState(slot);
            djLinksBySlot[slot] = link;

            const schema = normalizeFormSchema(Array.isArray(link.form_schema) ? link.form_schema : []);
            if (hasUsefulSchemaFields(schema)) {
                const signature = getSchemaSignature(schema);
                lastSavedDjSchemaSignatures[slot] = signature;
                const templateId = findTemplateIdBySchemaSignature(signature);
                if (templateId) {
                    selectedDjTemplateIds[slot] = templateId;
                }
            }
        });
    }

    djSlotOrder = getSortedDjSlots();
    renderDjSlots();
}

function initSectionTemplateSelection() {
    lastSavedSectionSchemaSignatures.decoracao = getSchemaSignature(initialDecoracaoSchema);
    lastSavedSectionSchemaSignatures.observacoes_gerais = getSchemaSignature(initialObservacoesSchema);

    const decoracaoTemplateId = findTemplateIdBySchemaSignature(lastSavedSectionSchemaSignatures.decoracao);
    if (decoracaoTemplateId) {
        selectedSectionTemplateIds.decoracao = decoracaoTemplateId;
    }

    const observacoesTemplateId = findTemplateIdBySchemaSignature(lastSavedSectionSchemaSignatures.observacoes_gerais);
    if (observacoesTemplateId) {
        selectedSectionTemplateIds.observacoes_gerais = observacoesTemplateId;
    }

    hydrateAllSectionFormDraftsFromSavedContent();
    renderAllSectionTemplateSelects();
}

// Salvar seção (conteúdo vem do TinyMCE)
async function salvarSecao(section) {
    let content = getEditorContent(section);
    let formSchemaJson = null;

    if (section === 'decoracao' || section === 'observacoes_gerais') {
        const normalizedSchema = getSelectedSectionSchema(section);
        if (normalizedSchema.length > 0) {
            syncSectionFormDraft(section);
            const values = sectionFormDraftValues[section] || {};
            let legacyContent = content;
            if (!extractSectionPayloadFromContent(legacyContent) && isLegacyGeneratedSchemaHtml(legacyContent)) {
                legacyContent = '';
            }
            const built = buildSectionContentFromForm(section, normalizedSchema, values, legacyContent);
            if (!built.ok) {
                alert((built.errors || ['Preencha os campos obrigatórios do formulário.']).join(' | '));
                return;
            }
            content = String(built.content_html || '');
            formSchemaJson = JSON.stringify(normalizedSchema);
            lastSavedSectionSchemaSignatures[section] = getSchemaSignature(normalizedSchema);
        }
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'salvar_secao');
        formData.append('meeting_id', meetingId);
        formData.append('section', section);
        formData.append('content_html', content);
        if (formSchemaJson !== null) {
            formData.append('form_schema_json', formSchemaJson);
        }
        
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        
        if (data.ok) {
            alert('Salvo com sucesso! Versão #' + data.version);
        } else {
            alert(data.error || 'Erro ao salvar');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

// Gerar link para cliente
async function gerarLinkCliente(slot = 1) {
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !djSlotExists(slotIndex)) {
        alert('Quadro inválido.');
        return;
    }
    updateShareAvailability(slotIndex);
    const btn = document.getElementById(`btnGerarLink-${slotIndex}`);
    if (btn && btn.disabled) {
        const hint = document.getElementById(`shareHint-${slotIndex}`);
        alert(hint ? hint.textContent : 'Salve a seção antes de compartilhar com o cliente.');
        return;
    }

    try {
        const selected = getSelectedDjTemplateData(slotIndex);
        if (!selected.template) {
            alert('Selecione um formulário antes de gerar o link.');
            return;
        }
        if (!hasUsefulSchemaFields(selected.schema)) {
            alert('O formulário selecionado não possui campos válidos.');
            return;
        }

        const formTitle = String(selected.template.nome || `Formulário DJ / Protocolos - Quadro ${slotIndex}`);
        const contentHtml = buildSchemaHtmlForStorage(selected.schema, formTitle);

        const formData = new FormData();
        formData.append('action', 'gerar_link_cliente');
        formData.append('meeting_id', meetingId);
        formData.append('slot_index', String(slotIndex));
        formData.append('form_schema_json', JSON.stringify(selected.schema));
        formData.append('content_html', contentHtml);
        formData.append('form_title', formTitle);
        
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        
        if (data.ok && data.url) {
            setDjLinkOutput(slotIndex, data.url);
            djLinksBySlot[slotIndex] = {
                id: Number(data.link && data.link.id ? data.link.id : 0),
                token: String(data.link && data.link.token ? data.link.token : ''),
                slot_index: slotIndex,
                form_title: formTitle,
                form_schema: selected.schema
            };
            lastSavedDjSchemaSignatures[slotIndex] = getSchemaSignature(selected.schema);
            
            if (!data.created) {
                alert('Link já existente recuperado');
            }
        } else {
            alert(data.error || 'Erro ao gerar link');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

function copiarLink(slot = 1) {
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !djSlotExists(slotIndex)) {
        alert('Quadro inválido.');
        return;
    }
    const input = document.getElementById(`clienteLinkInput-${slotIndex}`);
    if (!input || !input.value) {
        alert('Nenhum link para copiar.');
        return;
    }
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(input.value).then(() => {
            alert('Link copiado!');
        }).catch(() => {
            input.select();
            document.execCommand('copy');
            alert('Link copiado!');
        });
        return;
    }
    input.select();
    document.execCommand('copy');
    alert('Link copiado!');
}

// Ver versões
async function verVersoes(section) {
    try {
        const formData = new FormData();
        formData.append('action', 'get_versoes');
        formData.append('meeting_id', meetingId);
        formData.append('section', section);
        
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        
        if (data.ok) {
            const container = document.getElementById('versoesContent');
            
            if (!data.versoes || data.versoes.length === 0) {
                container.innerHTML = '<p style="color: #64748b;">Nenhuma versão registrada ainda.</p>';
            } else {
                container.innerHTML = data.versoes.map(v => `
                    <div class="version-item ${v.is_active ? 'active' : ''}">
                        <div class="version-header">
                            <span class="version-number">Versão #${v.version_number} ${v.is_active ? '(atual)' : ''}</span>
                            <span class="version-meta">${formatDate(v.created_at)} • ${v.autor_nome || v.created_by_type}</span>
                        </div>
                        <p class="version-note">${v.note || 'Sem nota'}</p>
                        ${!v.is_active ? `<button class="btn btn-secondary" onclick="restaurarVersao(${v.id})">↺ Restaurar</button>` : ''}
                    </div>
                `).join('');
            }
            
            document.getElementById('modalVersoes').classList.add('show');
        } else {
            alert(data.error || 'Erro ao buscar versões');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

function fecharModal() {
    document.getElementById('modalVersoes').classList.remove('show');
}

function getActiveSectionForExport() {
    const active = document.querySelector('.tab-content.active');
    if (!active) return 'decoracao';
    const id = active.id || '';
    if (id.startsWith('tab-')) {
        const section = id.slice(4);
        if (['decoracao', 'observacoes_gerais', 'dj_protocolo'].includes(section)) {
            return section;
        }
    }
    return 'decoracao';
}

function abrirModalImpressao() {
    const modal = document.getElementById('modalImpressao');
    if (!modal) return;
    const select = document.getElementById('printSectionSelect');
    if (select) {
        select.value = getActiveSectionForExport();
    }
    modal.classList.add('show');
}

function fecharModalImpressao() {
    const modal = document.getElementById('modalImpressao');
    if (!modal) return;
    modal.classList.remove('show');
}

function emitirDocumentoReuniao(mode) {
    if (!meetingId) {
        alert('Reunião inválida.');
        return;
    }
    const select = document.getElementById('printSectionSelect');
    const section = select ? (select.value || 'decoracao') : 'decoracao';
    const m = (mode === 'pdf') ? 'pdf' : 'print';
    const url = `index.php?page=eventos_pdf&id=${meetingId}&section=${encodeURIComponent(section)}&mode=${encodeURIComponent(m)}`;
    window.open(url, '_blank');
    fecharModalImpressao();
}

document.addEventListener('click', function(ev) {
    const modal = document.getElementById('modalImpressao');
    if (!modal) return;
    if (ev.target === modal) {
        fecharModalImpressao();
    }
});

document.addEventListener('keydown', function(ev) {
    if (ev.key !== 'Escape') return;
    const modal = document.getElementById('modalImpressao');
    if (modal && modal.classList.contains('show')) {
        fecharModalImpressao();
    }
});

async function restaurarVersao(versionId) {
    if (!confirm('Restaurar esta versão? Uma nova versão será criada.')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'restaurar_versao');
        formData.append('version_id', versionId);
        
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        
        if (data.ok) {
            alert('Versão restaurada!');
            location.reload();
        } else {
            alert(data.error || 'Erro ao restaurar');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

// Destravar seção
async function destravarSecao(section) {
    if (!confirm('Destravar esta seção permitirá edições. Continuar?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'destravar_secao');
        formData.append('meeting_id', meetingId);
        formData.append('section', section);
        
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        
        if (data.ok) {
            location.reload();
        } else {
            alert(data.error || 'Erro ao destravar');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

// Destravar quadro do DJ (slot)
async function destravarDjSlot(slot = 1) {
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !djSlotExists(slotIndex)) {
        alert('Quadro inválido.');
        return;
    }
    if (!confirm(`Destravar o quadro ${slotIndex} permite que o cliente edite e reenvie. Continuar?`)) return;

    try {
        const formData = new FormData();
        formData.append('action', 'destravar_dj_slot');
        formData.append('meeting_id', meetingId);
        formData.append('slot_index', String(slotIndex));

        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();

        if (data.ok) {
            location.reload();
        } else {
            alert(data.error || 'Erro ao destravar quadro');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

// Atualizar status
async function concluirReuniao() {
    if (!confirm('Marcar reunião como concluída?')) return;
    await atualizarStatus('concluida');
}

async function reabrirReuniao() {
    if (!confirm('Reabrir reunião para edição?')) return;
    await atualizarStatus('rascunho');
}

async function atualizarStatus(status) {
    try {
        const formData = new FormData();
        formData.append('action', 'atualizar_status');
        formData.append('meeting_id', meetingId);
        formData.append('status', status);
        
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        
        if (data.ok) {
            location.reload();
        } else {
            alert(data.error || 'Erro ao atualizar status');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

function formatDate(dateStr) {
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) {
        return '-';
    }
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

window.addEventListener('click', function (e) {
    if (e.target && e.target.id === 'modalVersoes') {
        fecharModal();
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    fecharModal();
});

function bindSearchEvents() {
    const searchInput = document.getElementById('eventSearch');
    if (!searchInput) return;

    searchInput.addEventListener('input', function () {
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => {
            searchEvents(searchInput.value, false);
        }, 280);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchEvents(searchInput.value, true);
        }
    });

    searchEvents('', false);
}

// Inicializar editores ricos quando existir reunião (carrega TinyMCE dinamicamente)
if (meetingId) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            applyInitialTabFromQuery();
            loadTinyMCEAndInit();
            initSectionTemplateSelection();
            initDjTemplateSelection();
            refreshDjTemplates();
        });
    } else {
        applyInitialTabFromQuery();
        loadTinyMCEAndInit();
        initSectionTemplateSelection();
        initDjTemplateSelection();
        refreshDjTemplates();
    }
} else {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindSearchEvents);
    } else {
        bindSearchEvents();
    }
}
</script>

<?php endSidebar(); ?>
