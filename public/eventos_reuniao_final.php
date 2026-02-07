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
            $result = eventos_reuniao_gerar_link_cliente($pdo, $meeting_id, $user_id);
            if ($result['ok']) {
                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $result['url'] = $base_url . '/index.php?page=eventos_cliente_dj&token=' . $result['link']['token'];
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

$form_templates = eventos_form_templates_listar($pdo);
$dj_form_schema_initial = [];
if (!empty($secoes['dj_protocolo']['form_schema_json'])) {
    $decoded_schema = json_decode((string)$secoes['dj_protocolo']['form_schema_json'], true);
    if (is_array($decoded_schema)) {
        $dj_form_schema_initial = $decoded_schema;
    }
}

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
    .dj-link-section {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    .dj-link-section h4 {
        margin: 0 0 0.75rem 0;
        font-size: 0.95rem;
        color: #374151;
    }

    .prefill-builder {
        background: #f8fafc;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .prefill-builder h4 {
        margin: 0 0 0.75rem 0;
        font-size: 0.95rem;
        color: #0f172a;
    }

    .prefill-block {
        background: #ffffff;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 0.85rem;
        margin-bottom: 0.85rem;
    }

    .prefill-block h5 {
        margin: 0 0 0.65rem 0;
        font-size: 0.84rem;
        color: #1e3a8a;
        font-weight: 700;
    }

    .prefill-divider {
        border: 0;
        border-top: 1px dashed #cbd5e1;
        margin: 0.7rem 0 0.95rem 0;
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
        margin-top: 0.9rem;
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
    }

    .legacy-editor-wrap {
        margin-top: 0.75rem;
    }
    
    .link-display {
        display: flex;
        gap: 0.5rem;
        align-items: center;
    }
    
    .link-input {
        flex: 1;
        padding: 0.5rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 0.8rem;
        background: white;
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
        ?>
        <div class="tab-content <?= $key === 'decoracao' ? 'active' : '' ?>" id="tab-<?= $key ?>">
            
            <?php if ($key === 'dj_protocolo'): ?>
            <!-- Seção DJ - Link para cliente -->
            <div class="dj-link-section">
                <h4>🔗 Link para Cliente Preencher</h4>
                <div class="link-display">
                    <input type="text" id="clienteLinkInput" class="link-input" readonly placeholder="Clique em 'Gerar Link' para criar">
                    <button type="button" class="btn btn-primary" onclick="gerarLinkCliente()">Gerar Link</button>
                    <button type="button" class="btn btn-secondary" onclick="copiarLink()" id="btnCopiar" style="display: none;">📋 Copiar</button>
                </div>
            </div>

            <div class="prefill-builder">
                <h4>🧩 Construtor de Formulário</h4>

                <div class="prefill-block">
                    <h5>Modelos salvos</h5>
                    <div class="prefill-grid">
                        <div class="prefill-field" style="grid-column: 1 / -1;">
                            <label for="savedTemplateSelect">Escolher modelo</label>
                            <select id="savedTemplateSelect" <?= $is_locked ? 'disabled' : '' ?>>
                                <option value="">Selecionar modelo salvo...</option>
                                <?php foreach ($form_templates as $template): ?>
                                    <option value="<?= (int)$template['id'] ?>"><?= htmlspecialchars((string)$template['nome']) ?><?= !empty($template['categoria']) ? ' - ' . htmlspecialchars((string)$template['categoria']) : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="prefill-actions">
                        <button type="button" class="btn btn-secondary" onclick="carregarModeloSalvo()" <?= $is_locked ? 'disabled' : '' ?>>📥 Carregar modelo salvo</button>
                    </div>
                </div>

                <hr class="prefill-divider">

                <div class="prefill-block">
                    <h5>Criação e preenchimento</h5>
                    <div class="prefill-grid">
                        <div class="prefill-field">
                            <label for="fieldType">Tipo de campo</label>
                            <select id="fieldType" onchange="onChangeFieldType()" <?= $is_locked ? 'disabled' : '' ?>>
                                <option value="text">Texto curto</option>
                                <option value="textarea">Texto longo</option>
                                <option value="yesno">Opção Sim/Não</option>
                                <option value="select">Múltipla escolha</option>
                                <option value="file">Upload de arquivo</option>
                                <option value="section">Título de seção</option>
                            </select>
                        </div>
                        <div class="prefill-field">
                            <label for="fieldRequired">Obrigatório</label>
                            <select id="fieldRequired" <?= $is_locked ? 'disabled' : '' ?>>
                                <option value="1">Sim</option>
                                <option value="0">Não</option>
                            </select>
                        </div>
                        <div class="prefill-field" style="grid-column: 1 / -1;">
                            <label for="fieldQuestion">Pergunta / título</label>
                            <input id="fieldQuestion" type="text" placeholder="Digite a pergunta..." <?= $is_locked ? 'disabled' : '' ?>>
                        </div>
                        <div class="prefill-field" id="fieldOptionsWrap" style="grid-column: 1 / -1; display: none;">
                            <label for="fieldOptions">Opções (uma por linha)</label>
                            <textarea id="fieldOptions" rows="3" placeholder="Opção 1&#10;Opção 2&#10;Opção 3" <?= $is_locked ? 'disabled' : '' ?>></textarea>
                        </div>
                    </div>
                    <div class="prefill-actions">
                        <button type="button" class="btn btn-secondary" onclick="adicionarCampoFormulario()" <?= $is_locked ? 'disabled' : '' ?>>➕ Adicionar campo</button>
                        <button type="button" class="btn btn-secondary" onclick="inserirSeparadorFormulario()" <?= $is_locked ? 'disabled' : '' ?>>➖ Separador</button>
                    </div>
                </div>

                <hr class="prefill-divider">

                <div class="prefill-block">
                    <h5>Salvar modelo atual</h5>
                    <div class="prefill-grid">
                        <div class="prefill-field">
                            <label for="templateCategory">Categoria do modelo</label>
                            <select id="templateCategory" <?= $is_locked ? 'disabled' : '' ?>>
                                <option value="15anos">15 anos</option>
                                <option value="casamento">Casamento</option>
                                <option value="infantil">Infantil</option>
                                <option value="geral">Geral</option>
                            </select>
                        </div>
                        <div class="prefill-field" style="grid-column: 1 / -1;">
                            <label for="templateSaveName">Nome para salvar modelo</label>
                            <input id="templateSaveName" type="text" placeholder="Ex.: 15 anos completo padrão Smile" <?= $is_locked ? 'disabled' : '' ?>>
                        </div>
                    </div>
                    <div class="prefill-actions">
                        <button type="button" class="btn btn-secondary" onclick="salvarModeloAtual()" <?= $is_locked ? 'disabled' : '' ?>>💾 Salvar como modelo</button>
                    </div>
                </div>

                <p class="prefill-note">Monte o formulário por campos (estilo Google Forms). Você pode salvar modelos e reutilizar nos próximos eventos.</p>
                <div id="builderFieldsList" class="builder-fields-list"></div>
                <div class="builder-preview-box">
                    <div class="builder-preview-title">Pré-visualização do formulário do cliente</div>
                    <div id="builderPreview"></div>
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
            
            <?php if ($key === 'dj_protocolo'): ?>
            <div class="legacy-editor-toggle">
                <button type="button" class="btn btn-secondary" onclick="toggleLegacyEditor()">📝 Mostrar/ocultar editor avançado</button>
            </div>
            <?php endif; ?>
            <?php $legacy_wrap_attrs = ($key === 'dj_protocolo') ? ' id="legacyEditorWrapDj" style="display:none;"' : ''; ?>
            <div class="editor-wrapper legacy-editor-wrap"<?= $legacy_wrap_attrs ?>>
                <?php 
                $safe_content = str_replace('</textarea>', '&lt;/textarea&gt;', $content);
                ?>
                <textarea id="editor-<?= $key ?>" 
                          data-section="<?= $key ?>"
                          <?= $is_locked ? 'readonly' : '' ?>
                          style="width:100%; min-height: 400px; border: 0;"><?= $safe_content ?></textarea>
            </div>
            
            <div class="section-actions">
                <?php if (!$is_locked): ?>
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

<script>
const meetingId = <?= $meeting_id ?: 'null' ?>;
let selectedEventId = null;
let selectedEventData = null;
let searchDebounceTimer = null;
let searchAbortController = null;
let eventsCacheLoaded = false;
let eventsMasterCache = [];
const eventsQueryCache = new Map();
const savedFormTemplates = <?= json_encode(array_map(static function(array $template): array {
    return [
        'id' => (int)($template['id'] ?? 0),
        'nome' => (string)($template['nome'] ?? ''),
        'categoria' => (string)($template['categoria'] ?? 'geral'),
        'schema' => is_array($template['schema'] ?? null) ? $template['schema'] : [],
    ];
}, $form_templates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const initialDjFormSchema = <?= json_encode($dj_form_schema_initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let formBuilderFields = Array.isArray(initialDjFormSchema) ? initialDjFormSchema.slice() : [];

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

function onChangeFieldType() {
    const fieldTypeEl = document.getElementById('fieldType');
    const type = fieldTypeEl ? (fieldTypeEl.value || 'text') : 'text';
    const optionsWrap = document.getElementById('fieldOptionsWrap');
    if (!optionsWrap) return;
    optionsWrap.style.display = type === 'select' ? 'block' : 'none';
}

function normalizeFormSchema(schema) {
    if (!Array.isArray(schema)) return [];
    return schema.map((field) => {
        const type = String(field.type || 'text');
        const options = Array.isArray(field.options) ? field.options.map((v) => String(v).trim()).filter(Boolean) : [];
        return {
            id: String(field.id || ('f_' + Math.random().toString(36).slice(2, 10))),
            type: type,
            label: String(field.label || '').trim(),
            required: !!field.required,
            options: options
        };
    }).filter((field) => {
        if (field.type === 'divider') return true;
        return field.label !== '';
    });
}

function getFieldTypeLabel(type) {
    const map = {
        text: 'Texto curto',
        textarea: 'Texto longo',
        yesno: 'Sim/Não',
        select: 'Múltipla escolha',
        file: 'Upload',
        section: 'Título de seção',
        divider: 'Separador'
    };
    return map[type] || type;
}

function renderFormBuilderUI() {
    const list = document.getElementById('builderFieldsList');
    const preview = document.getElementById('builderPreview');

    if (list) {
        if (!Array.isArray(formBuilderFields) || formBuilderFields.length === 0) {
            list.innerHTML = '<div class="builder-field-meta">Nenhum campo criado ainda. Monte seu formulário aqui.</div>';
        } else {
            list.innerHTML = formBuilderFields.map((field, idx) => `
                <div class="builder-field-card">
                    <div>
                        <div class="builder-field-title">${idx + 1}. ${escapeHtmlForField(field.label || '(sem título)')}</div>
                        <div class="builder-field-meta">
                            ${escapeHtmlForField(getFieldTypeLabel(field.type))}
                            ${field.required ? ' • Obrigatório' : ' • Opcional'}
                            ${field.options && field.options.length ? ' • ' + field.options.length + ' opção(ões)' : ''}
                        </div>
                    </div>
                    <div class="builder-field-actions">
                        <button type="button" class="btn btn-secondary" onclick="moverCampoBuilder(${idx}, -1)">↑</button>
                        <button type="button" class="btn btn-secondary" onclick="moverCampoBuilder(${idx}, 1)">↓</button>
                        <button type="button" class="btn btn-secondary" onclick="alternarObrigatorioCampo(${idx})">Obrig.</button>
                        <button type="button" class="btn btn-danger" onclick="removerCampoBuilder(${idx})">Excluir</button>
                    </div>
                </div>
            `).join('');
        }
    }

    if (preview) {
        if (!Array.isArray(formBuilderFields) || formBuilderFields.length === 0) {
            preview.innerHTML = '<div class="builder-field-meta">Pré-visualização vazia.</div>';
        } else {
            preview.innerHTML = formBuilderFields.map((field) => {
                if (field.type === 'divider') {
                    return '<hr>';
                }
                if (field.type === 'section') {
                    return `<div class="builder-preview-item"><h4>${escapeHtmlForField(field.label)}</h4></div>`;
                }

                const req = field.required ? ' *' : '';
                if (field.type === 'textarea') {
                    return `<div class="builder-preview-item"><label>${escapeHtmlForField(field.label)}${req}</label><textarea rows="3" disabled></textarea></div>`;
                }
                if (field.type === 'yesno') {
                    return `<div class="builder-preview-item"><label>${escapeHtmlForField(field.label)}${req}</label><select disabled><option>Selecione...</option><option>Sim</option><option>Não</option></select></div>`;
                }
                if (field.type === 'select') {
                    const opts = (field.options || []).map((opt) => `<option>${escapeHtmlForField(opt)}</option>`).join('');
                    return `<div class="builder-preview-item"><label>${escapeHtmlForField(field.label)}${req}</label><select disabled><option>Selecione...</option>${opts}</select></div>`;
                }
                if (field.type === 'file') {
                    return `<div class="builder-preview-item"><label>${escapeHtmlForField(field.label)}${req}</label><input type="text" value="Campo de upload" disabled></div>`;
                }
                return `<div class="builder-preview-item"><label>${escapeHtmlForField(field.label)}${req}</label><input type="text" disabled></div>`;
            }).join('');
        }
    }

    const editorHtml = buildSchemaHtmlForStorage(formBuilderFields);
    if (editorHtml) {
        setEditorContent(editorHtml, 'dj_protocolo');
    }
}

function buildSchemaHtmlForStorage(schema) {
    if (!Array.isArray(schema) || schema.length === 0) return '';
    let html = '<h2>Formulário DJ / Protocolos</h2>';
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

function adicionarCampoFormulario() {
    const fieldTypeEl = document.getElementById('fieldType');
    const fieldQuestionEl = document.getElementById('fieldQuestion');
    const fieldOptionsEl = document.getElementById('fieldOptions');
    const fieldRequiredEl = document.getElementById('fieldRequired');
    const type = fieldTypeEl ? (fieldTypeEl.value || 'text') : 'text';
    const question = ((fieldQuestionEl ? fieldQuestionEl.value : '') || '').trim();
    const options = ((fieldOptionsEl ? fieldOptionsEl.value : '') || '').trim();
    const required = (fieldRequiredEl ? fieldRequiredEl.value : '1') === '1';

    if (type !== 'divider' && !question) {
        alert('Digite a pergunta/título para adicionar o campo.');
        return;
    }

    const field = {
        id: 'f_' + Math.random().toString(36).slice(2, 10),
        type: type,
        label: question,
        required: type === 'section' || type === 'divider' ? false : required,
        options: []
    };

    if (type === 'select') {
        field.options = options.split('\n').map((v) => v.trim()).filter(Boolean);
        if (!field.options.length) {
            alert('Para múltipla escolha, informe pelo menos uma opção.');
            return;
        }
    }

    formBuilderFields.push(field);
    renderFormBuilderUI();
    hideLegacyEditorIfSchemaExists();
    if (fieldQuestionEl) fieldQuestionEl.value = '';
    if (fieldOptionsEl) fieldOptionsEl.value = '';
}

function inserirSeparadorFormulario() {
    formBuilderFields.push({
        id: 'f_' + Math.random().toString(36).slice(2, 10),
        type: 'divider',
        label: '---',
        required: false,
        options: []
    });
    renderFormBuilderUI();
    hideLegacyEditorIfSchemaExists();
}

function removerCampoBuilder(index) {
    if (!Array.isArray(formBuilderFields) || index < 0 || index >= formBuilderFields.length) return;
    formBuilderFields.splice(index, 1);
    renderFormBuilderUI();
}

function moverCampoBuilder(index, direction) {
    if (!Array.isArray(formBuilderFields) || index < 0 || index >= formBuilderFields.length) return;
    const target = index + direction;
    if (target < 0 || target >= formBuilderFields.length) return;
    const item = formBuilderFields[index];
    formBuilderFields[index] = formBuilderFields[target];
    formBuilderFields[target] = item;
    renderFormBuilderUI();
}

function alternarObrigatorioCampo(index) {
    if (!Array.isArray(formBuilderFields) || index < 0 || index >= formBuilderFields.length) return;
    const field = formBuilderFields[index];
    if (!field || field.type === 'section' || field.type === 'divider') return;
    field.required = !field.required;
    renderFormBuilderUI();
}

function refreshSavedTemplateSelect(selectedId = null) {
    const select = document.getElementById('savedTemplateSelect');
    if (!select) return;

    const current = selectedId !== null ? String(selectedId) : String(select.value || '');
    const options = ['<option value="">Selecionar modelo salvo...</option>'];
    savedFormTemplates.forEach((template) => {
        const selected = String(template.id) === current ? ' selected' : '';
        const label = `${template.nome}${template.categoria ? ' - ' + template.categoria : ''}`;
        options.push(`<option value="${template.id}"${selected}>${escapeHtmlForField(label)}</option>`);
    });
    select.innerHTML = options.join('');
}

function carregarModeloSalvo() {
    const select = document.getElementById('savedTemplateSelect');
    if (!select || !select.value) {
        alert('Selecione um modelo salvo.');
        return;
    }
    const template = savedFormTemplates.find((item) => String(item.id) === String(select.value));
    if (!template) {
        alert('Modelo não encontrado.');
        return;
    }
    const replace = formBuilderFields.length === 0 || confirm('Substituir o formulário atual pelo modelo salvo?');
    if (!replace) return;
    formBuilderFields = normalizeFormSchema(template.schema || []);
    renderFormBuilderUI();
    hideLegacyEditorIfSchemaExists();
}

async function salvarModeloAtual() {
    if (!Array.isArray(formBuilderFields) || formBuilderFields.length === 0) {
        alert('Adicione campos no formulário antes de salvar como modelo.');
        return;
    }
    const nameInput = document.getElementById('templateSaveName');
    const categoryInput = document.getElementById('templateCategory');
    const templateName = (nameInput ? nameInput.value : '').trim();
    if (!templateName) {
        alert('Informe um nome para salvar o modelo.');
        return;
    }
    const templateCategory = (categoryInput ? categoryInput.value : 'geral') || 'geral';

    try {
        const formData = new FormData();
        formData.append('action', 'salvar_template_form');
        formData.append('template_name', templateName);
        formData.append('template_category', templateCategory);
        formData.append('schema_json', JSON.stringify(formBuilderFields));

        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        if (!data.ok || !data.template) {
            alert(data.error || 'Erro ao salvar modelo');
            return;
        }

        const existing = savedFormTemplates.findIndex((item) => String(item.id) === String(data.template.id));
        const payload = {
            id: Number(data.template.id),
            nome: String(data.template.nome || templateName),
            categoria: String(data.template.categoria || templateCategory),
            schema: Array.isArray(data.template.schema) ? data.template.schema : formBuilderFields.slice()
        };
        if (existing >= 0) {
            savedFormTemplates[existing] = payload;
        } else {
            savedFormTemplates.unshift(payload);
        }
        refreshSavedTemplateSelect(payload.id);
        alert('Modelo salvo com sucesso!');
    } catch (err) {
        alert('Erro ao salvar modelo: ' + err.message);
    }
}

function toggleLegacyEditor() {
    const wrap = document.getElementById('legacyEditorWrapDj');
    if (!wrap) return;
    wrap.style.display = (wrap.style.display === 'none') ? 'block' : 'none';
}

function hideLegacyEditorIfSchemaExists() {
    const wrap = document.getElementById('legacyEditorWrapDj');
    if (!wrap) return;
    if (Array.isArray(formBuilderFields) && formBuilderFields.length > 0) {
        wrap.style.display = 'none';
    }
}

// Salvar seção (conteúdo vem do TinyMCE)
async function salvarSecao(section) {
    let content = '';
    let formSchemaJson = null;

    if (section === 'dj_protocolo') {
        const normalized = normalizeFormSchema(formBuilderFields);
        if (normalized.length > 0) {
            formBuilderFields = normalized;
            content = buildSchemaHtmlForStorage(formBuilderFields);
            formSchemaJson = JSON.stringify(formBuilderFields);
        } else {
            formSchemaJson = JSON.stringify([]);
        }
    }

    if (!content) {
        if (typeof tinymce !== 'undefined' && tinymce.get('editor-' + section)) {
            content = tinymce.get('editor-' + section).getContent();
        } else {
            const el = document.getElementById('editor-' + section);
            content = el ? el.value : '';
        }
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'salvar_secao');
        formData.append('meeting_id', meetingId);
        formData.append('section', section);
        formData.append('content_html', content);
        if (section === 'dj_protocolo' && formSchemaJson !== null) {
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
async function gerarLinkCliente() {
    try {
        const formData = new FormData();
        formData.append('action', 'gerar_link_cliente');
        formData.append('meeting_id', meetingId);
        
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await resp.json();
        
        if (data.ok && data.url) {
            document.getElementById('clienteLinkInput').value = data.url;
            document.getElementById('btnCopiar').style.display = 'inline-flex';
            
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

function copiarLink() {
    const input = document.getElementById('clienteLinkInput');
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
    return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
}

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

function initDjBuilderIfExists() {
    const hasBuilder = document.getElementById('builderFieldsList') !== null;
    if (!hasBuilder) return;
    formBuilderFields = normalizeFormSchema(formBuilderFields);
    refreshSavedTemplateSelect();
    renderFormBuilderUI();
    hideLegacyEditorIfSchemaExists();
    onChangeFieldType();
}

// Inicializar editores ricos quando existir reunião (carrega TinyMCE dinamicamente)
if (meetingId) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            loadTinyMCEAndInit();
            initDjBuilderIfExists();
        });
    } else {
        loadTinyMCEAndInit();
        initDjBuilderIfExists();
    }
} else {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindSearchEvents);
    } else {
        bindSearchEvents();
    }
}

onChangeFieldType();
</script>

<?php endSidebar(); ?>
