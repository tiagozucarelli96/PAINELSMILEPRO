<?php
/**
 * eventos_cliente_dj.php
 * Página pública para cliente preencher informações de DJ
 * Acessada via token único (sem login)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/upload_magalu.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = '';
$success = false;
$link = null;
$reuniao = null;
$secao = null;
$anexos = [];

/**
 * Converter estrutura de upload múltiplo para lista de arquivos.
 */
function eventos_cliente_normalizar_uploads(array $files, string $field): array {
    if (empty($files[$field])) {
        return [];
    }

    $entry = $files[$field];
    if (!isset($entry['name'])) {
        return [];
    }

    if (!is_array($entry['name'])) {
        if (($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        return [$entry];
    }

    $normalized = [];
    $count = count($entry['name']);
    for ($i = 0; $i < $count; $i++) {
        if (($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $normalized[] = [
            'name' => $entry['name'][$i] ?? '',
            'type' => $entry['type'][$i] ?? '',
            'tmp_name' => $entry['tmp_name'][$i] ?? '',
            'error' => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $entry['size'][$i] ?? 0,
        ];
    }

    return $normalized;
}

/**
 * Escapa HTML com segurança.
 */
function eventos_cliente_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Normaliza schema dinâmico recebido da seção DJ.
 */
function eventos_cliente_normalizar_schema($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $allowed_types = ['text', 'textarea', 'yesno', 'select', 'file', 'section', 'divider'];
    $schema = [];
    foreach ($raw as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = strtolower(trim((string)($item['type'] ?? 'text')));
        if (!in_array($type, $allowed_types, true)) {
            $type = 'text';
        }
        $label = trim((string)($item['label'] ?? ''));
        if ($label === '' && $type !== 'divider') {
            continue;
        }
        $options = [];
        if ($type === 'select' && !empty($item['options']) && is_array($item['options'])) {
            foreach ($item['options'] as $opt) {
                $opt_text = trim((string)$opt);
                if ($opt_text !== '') {
                    $options[] = $opt_text;
                }
            }
        }
        $id = trim((string)($item['id'] ?? ''));
        if ($id === '') {
            $id = 'f_' . bin2hex(random_bytes(4));
        }
        $schema[] = [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'required' => !empty($item['required']) && $type !== 'section' && $type !== 'divider',
            'options' => $options,
        ];
    }
    return $schema;
}

/**
 * Monta HTML de resposta do cliente a partir do schema.
 */
function eventos_cliente_montar_resposta_schema(array $schema, array $post): array {
    $errors = [];
    $parts = [];

    foreach ($schema as $field) {
        $field_id = trim((string)($field['id'] ?? ''));
        $field_type = (string)($field['type'] ?? 'text');
        $label = trim((string)($field['label'] ?? 'Campo'));
        $required = !empty($field['required']);
        $input_name = 'field_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $field_id);

        if ($field_type === 'divider') {
            $parts[] = '<hr>';
            continue;
        }
        if ($field_type === 'section') {
            $parts[] = '<h3>' . eventos_cliente_e($label) . '</h3>';
            continue;
        }
        if ($field_type === 'file') {
            // Upload de arquivo é processado separadamente.
            $parts[] = '<p><strong>' . eventos_cliente_e($label) . '</strong><br><em>Arquivo anexado separadamente.</em></p>';
            continue;
        }

        $value = isset($post[$input_name]) ? trim((string)$post[$input_name]) : '';
        if ($required && $value === '') {
            $errors[] = 'Preencha o campo obrigatório: ' . $label;
            continue;
        }

        if ($field_type === 'select') {
            $allowed = array_map('strval', (array)($field['options'] ?? []));
            if ($value !== '' && !in_array($value, $allowed, true)) {
                $errors[] = 'Opção inválida em: ' . $label;
                continue;
            }
        }

        if ($field_type === 'yesno') {
            if ($value !== '' && !in_array($value, ['sim', 'nao'], true)) {
                $errors[] = 'Valor inválido em: ' . $label;
                continue;
            }
            $value = $value === 'sim' ? 'Sim' : ($value === 'nao' ? 'Não' : '');
        }

        $answer = $value !== '' ? nl2br(eventos_cliente_e($value)) : '<em>Não informado</em>';
        $parts[] = '<p><strong>' . eventos_cliente_e($label) . '</strong><br>' . $answer . '</p>';
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
        'content_html' => implode("\n", $parts),
    ];
}

// Validar token
if (empty($token)) {
    $error = 'Link inválido';
} else {
    $link = eventos_link_publico_get($pdo, $token);
    
    if (!$link) {
        $error = 'Link inválido ou expirado';
    } elseif (!$link['is_active']) {
        $error = 'Este link foi desativado';
    } elseif ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
        $error = 'Este link expirou';
    } else {
        // Registrar acesso
        eventos_link_publico_registrar_acesso($pdo, $link['id']);
        
        // Buscar reunião e seção
        $reuniao = eventos_reuniao_get($pdo, $link['meeting_id']);
        $secao = eventos_reuniao_get_secao($pdo, $link['meeting_id'], 'dj_protocolo');
        $anexos = eventos_reuniao_get_anexos($pdo, $link['meeting_id'], 'dj_protocolo');
    }
}

// Processar envio do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $link && !$error) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'salvar') {
        // Verificar se já está travado
        if ($secao && $secao['is_locked']) {
            $error = 'Este formulário já foi enviado e não pode ser alterado.';
        } else {
            $uploads = eventos_cliente_normalizar_uploads($_FILES, 'anexos');

            $schema_submit = [];
            if (!empty($secao['form_schema_json'])) {
                $decoded_schema = json_decode((string)$secao['form_schema_json'], true);
                $schema_submit = eventos_cliente_normalizar_schema($decoded_schema);
            }

            if (!empty($schema_submit)) {
                $compiled = eventos_cliente_montar_resposta_schema($schema_submit, $_POST);
                if (empty($compiled['ok'])) {
                    $error = implode(' | ', array_slice((array)($compiled['errors'] ?? []), 0, 2));
                } else {
                    $content = (string)($compiled['content_html'] ?? '');

                    // Campos de upload dentro do schema
                    foreach ($schema_submit as $field) {
                        if (($field['type'] ?? '') !== 'file') {
                            continue;
                        }
                        $field_id = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($field['id'] ?? ''));
                        $field_input = 'file_' . $field_id;
                        $field_uploads = eventos_cliente_normalizar_uploads($_FILES, $field_input);
                        if (!empty($field['required']) && empty($field_uploads)) {
                            $error = 'Campo obrigatório sem anexo: ' . (string)($field['label'] ?? 'Arquivo');
                            break;
                        }
                        if (!empty($field_uploads)) {
                            $uploads = array_merge($uploads, $field_uploads);
                        }
                    }
                }
            } else {
                $content = (string)($_POST['content_html'] ?? '');
            }

            if ($error === '') {
                // Salvar conteúdo (como cliente)
                $result = eventos_reuniao_salvar_secao(
                    $pdo,
                    $link['meeting_id'],
                    'dj_protocolo',
                    $content,
                    0, // user_id = 0 para cliente
                    'Envio do cliente',
                    'cliente',
                    !empty($schema_submit) ? json_encode($schema_submit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
                );
                
                if ($result['ok']) {
                    $upload_errors = [];
                    if (!empty($uploads)) {
                        $uploader = new MagaluUpload();
                        foreach ($uploads as $file) {
                            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                                $upload_errors[] = 'Falha no arquivo: ' . ($file['name'] ?? 'sem nome');
                                continue;
                            }

                            try {
                                $prefix = 'eventos/reunioes/' . (int)$link['meeting_id'] . '/cliente_dj';
                                $upload_result = $uploader->upload($file, $prefix);
                                $save_result = eventos_reuniao_salvar_anexo(
                                    $pdo,
                                    (int)$link['meeting_id'],
                                    'dj_protocolo',
                                    $upload_result,
                                    'cliente',
                                    null
                                );
                                if (empty($save_result['ok'])) {
                                    $upload_errors[] = ($file['name'] ?? 'arquivo') . ': ' . ($save_result['error'] ?? 'erro ao salvar metadados');
                                }
                            } catch (Throwable $e) {
                                $upload_errors[] = ($file['name'] ?? 'arquivo') . ': ' . $e->getMessage();
                            }
                        }
                    }

                    if (!empty($upload_errors)) {
                        $error = 'Conteúdo salvo, mas alguns anexos falharam: ' . implode(' | ', array_slice($upload_errors, 0, 2));
                    } else {
                        // Travar seção somente quando tudo foi salvo sem erro
                        eventos_reuniao_travar_secao($pdo, $link['meeting_id'], 'dj_protocolo', 0);
                        $success = true;
                    }

                    // Recarregar seção e anexos
                    $secao = eventos_reuniao_get_secao($pdo, $link['meeting_id'], 'dj_protocolo');
                    $anexos = eventos_reuniao_get_anexos($pdo, $link['meeting_id'], 'dj_protocolo');
                } else {
                    $error = $result['error'] ?? 'Erro ao salvar';
                }
            }
        }
    }
}

// Dados do evento
$snapshot = $reuniao ? json_decode($reuniao['me_event_snapshot'], true) : [];
$is_locked = $secao && !empty($secao['is_locked']);
$content = $secao['content_html'] ?? '';
$form_schema = [];
if (!empty($secao['form_schema_json'])) {
    $decoded = json_decode((string)$secao['form_schema_json'], true);
    $form_schema = eventos_cliente_normalizar_schema($decoded);
}

$evento_nome = trim((string)($snapshot['nome'] ?? 'Seu Evento'));
$data_evento_raw = trim((string)($snapshot['data'] ?? ''));
$data_evento_fmt = $data_evento_raw !== '' ? date('d/m/Y', strtotime($data_evento_raw)) : 'Não informada';
$hora_inicio = trim((string)($snapshot['hora_inicio'] ?? $snapshot['hora'] ?? $snapshot['horainicio'] ?? ''));
$hora_fim = trim((string)($snapshot['hora_fim'] ?? $snapshot['horafim'] ?? $snapshot['horatermino'] ?? ''));
$horario_evento = $hora_inicio !== '' ? $hora_inicio : 'Não informado';
if ($hora_inicio !== '' && $hora_fim !== '') {
    $horario_evento .= ' - ' . $hora_fim;
}
$local_evento = trim((string)($snapshot['local'] ?? $snapshot['nomelocal'] ?? 'Não informado'));
$convidados_evento = (int)($snapshot['convidados'] ?? $snapshot['nconvidados'] ?? 0);
$cliente_nome = trim((string)($snapshot['cliente']['nome'] ?? $snapshot['nomecliente'] ?? 'Não informado'));
$cliente_telefone = trim((string)($snapshot['cliente']['telefone'] ?? $snapshot['telefonecliente'] ?? ''));
$cliente_email = trim((string)($snapshot['cliente']['email'] ?? $snapshot['emailcliente'] ?? ''));
$tipo_evento = trim((string)($snapshot['tipo_evento'] ?? $snapshot['tipoevento'] ?? ''));
$unidade_evento = trim((string)($snapshot['unidade'] ?? ''));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organização do Evento - DJ/Músicas</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .header img {
            max-width: 180px;
            margin-bottom: 1rem;
            filter: none;
            background: transparent;
            border-radius: 0;
        }
        
        .header h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .event-info {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .event-info h2 {
            font-size: 1.25rem;
            color: #1e3a8a;
            margin-bottom: 1rem;
        }
        
        .event-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }
        
        .detail-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .detail-item span:first-child {
            font-size: 1.25rem;
        }
        
        .detail-item strong {
            font-size: 0.875rem;
            color: #64748b;
            display: block;
        }
        
        .detail-item span:last-child {
            font-size: 0.95rem;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .form-section h3 {
            font-size: 1.125rem;
            color: #374151;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .instructions {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            color: #1e40af;
        }
        
        .instructions ul {
            margin: 0.5rem 0 0 1.5rem;
        }

        .attachments-box {
            margin-top: 1rem;
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
            padding: 0.875rem;
            background: #f8fafc;
        }

        .attachments-box label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.5rem;
        }

        .attachments-box input[type="file"] {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #fff;
        }

        .attachments-help {
            margin-top: 0.4rem;
            font-size: 0.78rem;
            color: #64748b;
        }

        .attachments-list {
            margin-top: 1rem;
            border-top: 1px solid #e5e7eb;
            padding-top: 0.75rem;
        }

        .attachments-list h4 {
            margin: 0 0 0.5rem 0;
            font-size: 0.9rem;
            color: #334155;
        }

        .attachments-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .attachments-list li {
            font-size: 0.84rem;
            margin-bottom: 0.35rem;
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }

        .attachments-list a {
            color: #1d4ed8;
            text-decoration: none;
        }

        .attachments-list a:hover {
            text-decoration: underline;
        }
        
        .editor-wrapper {
            border: 1px solid #d1d5db;
            border-radius: 8px;
            min-height: 300px;
            background: white;
        }
        
        .editor-toolbar {
            display: flex;
            gap: 0.5rem;
            padding: 0.75rem;
            border-bottom: 1px solid #e5e7eb;
            background: #f8fafc;
            border-radius: 8px 8px 0 0;
            flex-wrap: wrap;
        }
        
        .editor-toolbar button {
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-size: 0.875rem;
        }
        
        .editor-toolbar button:hover {
            background: #f1f5f9;
        }
        
        .editor-content {
            padding: 1rem;
            min-height: 250px;
            outline: none;
        }
        
        .editor-content:focus {
            box-shadow: inset 0 0 0 2px rgba(30, 58, 138, 0.2);
        }
        
        .btn {
            padding: 0.875rem 2rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
            color: white;
            width: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
        }
        
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        
        .locked-notice {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            color: #92400e;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
        }
        
        .locked-notice h3 {
            margin-bottom: 0.5rem;
        }
        
        .success-box {
            text-align: center;
            padding: 3rem;
        }
        
        .success-box .icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem auto;
            font-size: 2.5rem;
            color: white;
        }
        
        .success-box h2 {
            color: #059669;
            margin-bottom: 0.5rem;
        }
        
        .success-box p {
            color: #64748b;
        }
        
        @media (max-width: 600px) {
            .container {
                padding: 1rem;
            }
            
            .event-details {
                grid-template-columns: 1fr 1fr;
            }
            
            .editor-toolbar {
                gap: 0.25rem;
            }
            
            .editor-toolbar button {
                padding: 0.375rem 0.5rem;
                font-size: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="logo.png" alt="Grupo Smile" onerror="this.style.display='none'">
        <h1>🎧 Organização - DJ / Músicas</h1>
        <p><?= htmlspecialchars($evento_nome) ?> • <?= htmlspecialchars($data_evento_fmt) ?> • <?= htmlspecialchars($horario_evento) ?></p>
        <p>Cliente: <?= htmlspecialchars($cliente_nome) ?></p>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
        <div class="alert alert-error">
            <strong>Erro:</strong> <?= htmlspecialchars($error) ?>
        </div>
        <?php elseif ($success): ?>
        <div class="form-section">
            <div class="success-box">
                <div class="icon">✓</div>
                <h2>Enviado com sucesso!</h2>
                <p>Recebemos suas informações. Nossa equipe entrará em contato se houver dúvidas.</p>
                <p style="margin-top: 1rem; font-size: 0.875rem;">Você pode fechar esta página.</p>
            </div>
        </div>
        <?php elseif ($is_locked): ?>
        <div class="event-info">
            <h2><?= htmlspecialchars($evento_nome) ?></h2>
            <div class="event-details">
                <div class="detail-item">
                    <span>📅</span>
                    <div>
                        <strong>Data</strong>
                        <span><?= htmlspecialchars($data_evento_fmt) ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>⏰</span>
                    <div>
                        <strong>Horário</strong>
                        <span><?= htmlspecialchars($horario_evento) ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>📍</span>
                    <div>
                        <strong>Local</strong>
                        <span><?= htmlspecialchars($local_evento) ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>👥</span>
                    <div>
                        <strong>Convidados</strong>
                        <span><?= $convidados_evento ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>👤</span>
                    <div>
                        <strong>Cliente</strong>
                        <span><?= htmlspecialchars($cliente_nome) ?></span>
                    </div>
                </div>
                <?php if ($cliente_telefone !== ''): ?>
                <div class="detail-item">
                    <span>📞</span>
                    <div>
                        <strong>Telefone</strong>
                        <span><?= htmlspecialchars($cliente_telefone) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($cliente_email !== ''): ?>
                <div class="detail-item">
                    <span>✉️</span>
                    <div>
                        <strong>E-mail</strong>
                        <span><?= htmlspecialchars($cliente_email) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($tipo_evento !== ''): ?>
                <div class="detail-item">
                    <span>🏷️</span>
                    <div>
                        <strong>Tipo</strong>
                        <span><?= htmlspecialchars($tipo_evento) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($unidade_evento !== ''): ?>
                <div class="detail-item">
                    <span>🏢</span>
                    <div>
                        <strong>Unidade</strong>
                        <span><?= htmlspecialchars($unidade_evento) ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="locked-notice">
            <h3>🔒 Formulário já enviado</h3>
            <p>Você já enviou as informações deste formulário. Se precisar fazer alterações, entre em contato com nossa equipe.</p>
        </div>
        
        <div class="form-section">
            <h3>Suas informações enviadas:</h3>
            <div style="padding: 1rem; background: #f8fafc; border-radius: 8px; margin-top: 1rem;">
                <?= $content ?: '<em>Sem conteúdo</em>' ?>
            </div>
            <?php if (!empty($anexos)): ?>
            <div class="attachments-list">
                <h4>Anexos enviados</h4>
                <ul>
                    <?php foreach ($anexos as $anexo): ?>
                    <li>
                        <span>📎</span>
                        <?php if (!empty($anexo['public_url'])): ?>
                        <a href="<?= htmlspecialchars($anexo['public_url']) ?>" target="_blank" rel="noopener noreferrer">
                            <?= htmlspecialchars($anexo['original_name'] ?? 'arquivo') ?>
                        </a>
                        <?php else: ?>
                        <span><?= htmlspecialchars($anexo['original_name'] ?? 'arquivo') ?></span>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <!-- Formulário editável -->
        <div class="event-info">
            <h2><?= htmlspecialchars($evento_nome) ?></h2>
            <div class="event-details">
                <div class="detail-item">
                    <span>📅</span>
                    <div>
                        <strong>Data</strong>
                        <span><?= htmlspecialchars($data_evento_fmt) ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>⏰</span>
                    <div>
                        <strong>Horário</strong>
                        <span><?= htmlspecialchars($horario_evento) ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>📍</span>
                    <div>
                        <strong>Local</strong>
                        <span><?= htmlspecialchars($local_evento) ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>👥</span>
                    <div>
                        <strong>Convidados</strong>
                        <span><?= $convidados_evento ?></span>
                    </div>
                </div>
                <div class="detail-item">
                    <span>👤</span>
                    <div>
                        <strong>Cliente</strong>
                        <span><?= htmlspecialchars($cliente_nome) ?></span>
                    </div>
                </div>
                <?php if ($cliente_telefone !== ''): ?>
                <div class="detail-item">
                    <span>📞</span>
                    <div>
                        <strong>Telefone</strong>
                        <span><?= htmlspecialchars($cliente_telefone) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($cliente_email !== ''): ?>
                <div class="detail-item">
                    <span>✉️</span>
                    <div>
                        <strong>E-mail</strong>
                        <span><?= htmlspecialchars($cliente_email) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($tipo_evento !== ''): ?>
                <div class="detail-item">
                    <span>🏷️</span>
                    <div>
                        <strong>Tipo</strong>
                        <span><?= htmlspecialchars($tipo_evento) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($unidade_evento !== ''): ?>
                <div class="detail-item">
                    <span>🏢</span>
                    <div>
                        <strong>Unidade</strong>
                        <span><?= htmlspecialchars($unidade_evento) ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <form method="POST" id="djForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="salvar">
            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
            
            <div class="form-section">
                <h3>🎵 Músicas e Protocolos</h3>
                
                <?php if (!empty($form_schema)): ?>
                <div class="instructions">
                    <strong>Preencha os campos abaixo:</strong>
                    <ul>
                        <li>Os campos com <strong>*</strong> são obrigatórios.</li>
                        <li>Use respostas claras e objetivas para alinharmos seu evento.</li>
                    </ul>
                </div>

                <?php foreach ($form_schema as $field): ?>
                    <?php
                        $field_id = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($field['id'] ?? ''));
                        $field_name = 'field_' . $field_id;
                        $file_name = 'file_' . $field_id;
                        $label = (string)($field['label'] ?? '');
                        $required = !empty($field['required']);
                        $required_attr = $required ? ' required' : '';
                    ?>
                    <?php if (($field['type'] ?? '') === 'divider'): ?>
                        <hr style="margin: 1rem 0; border: 0; border-top: 1px solid #e2e8f0;">
                    <?php elseif (($field['type'] ?? '') === 'section'): ?>
                        <h4 style="margin-top: 1.1rem; margin-bottom: 0.6rem; color: #1e3a8a;"><?= eventos_cliente_e($label) ?></h4>
                    <?php else: ?>
                        <div style="margin-bottom: 1rem;">
                            <label style="display:block; font-weight:600; color:#334155; margin-bottom:0.35rem;" for="<?= eventos_cliente_e($field_name) ?>">
                                <?= eventos_cliente_e($label) ?><?= $required ? ' *' : '' ?>
                            </label>

                            <?php if (($field['type'] ?? '') === 'textarea'): ?>
                                <textarea id="<?= eventos_cliente_e($field_name) ?>" name="<?= eventos_cliente_e($field_name) ?>" rows="4" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.6rem;"<?= $required_attr ?>></textarea>
                            <?php elseif (($field['type'] ?? '') === 'yesno'): ?>
                                <select id="<?= eventos_cliente_e($field_name) ?>" name="<?= eventos_cliente_e($field_name) ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.6rem;"<?= $required_attr ?>>
                                    <option value="">Selecione...</option>
                                    <option value="sim">Sim</option>
                                    <option value="nao">Não</option>
                                </select>
                            <?php elseif (($field['type'] ?? '') === 'select'): ?>
                                <select id="<?= eventos_cliente_e($field_name) ?>" name="<?= eventos_cliente_e($field_name) ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.6rem;"<?= $required_attr ?>>
                                    <option value="">Selecione...</option>
                                    <?php foreach (($field['options'] ?? []) as $opt): ?>
                                        <option value="<?= eventos_cliente_e((string)$opt) ?>"><?= eventos_cliente_e((string)$opt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (($field['type'] ?? '') === 'file'): ?>
                                <input type="file" id="<?= eventos_cliente_e($file_name) ?>" name="<?= eventos_cliente_e($file_name) ?>[]" multiple accept=".png,.jpg,.jpeg,.webp,.pdf,.doc,.docx,.xls,.xlsx,.xlsm,.txt,.csv" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.55rem;"<?= $required_attr ?>>
                            <?php else: ?>
                                <input type="text" id="<?= eventos_cliente_e($field_name) ?>" name="<?= eventos_cliente_e($field_name) ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.6rem;"<?= $required_attr ?>>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>

                <div class="attachments-box">
                    <label for="anexosInput">Anexos adicionais (opcional)</label>
                    <input type="file" id="anexosInput" name="anexos[]" multiple accept=".png,.jpg,.jpeg,.webp,.pdf,.doc,.docx,.xls,.xlsx,.xlsm,.txt,.csv">
                    <p class="attachments-help">Use para anexar arquivos extras não previstos em campos específicos.</p>
                </div>
                <?php else: ?>
                <div class="instructions">
                    <strong>Instruções:</strong>
                    <ul>
                        <li>Para cada música, informe o <strong>link do YouTube</strong> e o <strong>tempo de início</strong></li>
                        <li>Exemplo: <em>Valsa 0:20 - https://youtube.com/...</em></li>
                        <li>Inclua músicas para: entrada, valsas, momentos especiais, abertura de pista</li>
                        <li>Informe também seu gosto musical e ritmos preferidos</li>
                    </ul>
                </div>
                
                <div class="editor-wrapper">
                    <div class="editor-toolbar">
                        <button type="button" onclick="execCmd('bold')"><b>B</b></button>
                        <button type="button" onclick="execCmd('italic')"><i>I</i></button>
                        <button type="button" onclick="execCmd('underline')"><u>U</u></button>
                        <button type="button" onclick="execCmd('insertUnorderedList')">• Lista</button>
                    </div>
                    <div class="editor-content" 
                         id="editor" 
                         contenteditable="true"><?= $content ?></div>
                </div>
                <input type="hidden" name="content_html" id="contentInput">

                <div class="attachments-box">
                    <label for="anexosInput">Anexos (opcional)</label>
                    <input type="file" id="anexosInput" name="anexos[]" multiple accept=".png,.jpg,.jpeg,.webp,.pdf,.doc,.docx,.xls,.xlsx,.xlsm,.txt,.csv">
                    <p class="attachments-help">Envie playlist, roteiro, arte do convite e materiais de referência.</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($anexos)): ?>
                <div class="attachments-list">
                    <h4>Arquivos já enviados</h4>
                    <ul>
                        <?php foreach ($anexos as $anexo): ?>
                        <li>
                            <span>📎</span>
                            <?php if (!empty($anexo['public_url'])): ?>
                            <a href="<?= htmlspecialchars($anexo['public_url']) ?>" target="_blank" rel="noopener noreferrer">
                                <?= htmlspecialchars($anexo['original_name'] ?? 'arquivo') ?>
                            </a>
                            <?php else: ?>
                            <span><?= htmlspecialchars($anexo['original_name'] ?? 'arquivo') ?></span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
            
            <button type="submit" class="btn btn-primary" id="submitBtn">
                ✓ Enviar Informações
            </button>
            
            <p style="text-align: center; margin-top: 1rem; font-size: 0.875rem; color: #64748b;">
                Após o envio, as informações não poderão ser alteradas.
            </p>
        </form>
        
        <script>
            function execCmd(cmd) {
                document.execCommand(cmd, false, null);
            }
            
            document.getElementById('djForm').addEventListener('submit', function(e) {
                const editor = document.getElementById('editor');
                const contentInput = document.getElementById('contentInput');
                if (editor && contentInput) {
                    contentInput.value = editor.innerHTML;
                    if (!editor.innerText.trim()) {
                        e.preventDefault();
                        alert('Por favor, preencha as informações antes de enviar.');
                        return false;
                    }
                }
                
                if (!confirm('Confirma o envio das informações? Após enviar, não será possível alterar.')) {
                    e.preventDefault();
                    return false;
                }
                
                document.getElementById('submitBtn').disabled = true;
                document.getElementById('submitBtn').innerText = 'Enviando...';
            });
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
