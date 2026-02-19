<?php
/**
 * eventos_cliente_dj.php
 * Página pública para cliente preencher formulários públicos da reunião final
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
$portal_config = null;
$link_section = 'dj_protocolo';
$link_visivel = true;
$link_editavel = true;
$section_meta = [
    'page_title' => 'Organização do Evento - DJ/Músicas',
    'header_title' => '🎧 Organização - DJ / Músicas',
    'form_heading' => '🎵 Músicas e Protocolos',
    'default_form_title_prefix' => 'Formulário DJ / Protocolos - Quadro ',
    'upload_prefix' => 'cliente_dj',
    'notify_dj' => true,
];

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
 * Normaliza notas enviadas para anexos múltiplos.
 */
function eventos_cliente_normalizar_notas_upload($raw_notes): array {
    if (!is_array($raw_notes)) {
        return [];
    }
    $notes = [];
    foreach ($raw_notes as $note) {
        $notes[] = trim((string)$note);
    }
    return $notes;
}

/**
 * Escapa HTML com segurança.
 */
function eventos_cliente_e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * Codifica payload de preenchimento para reabrir o formulário.
 */
function eventos_cliente_encode_payload(array $payload): string {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return '';
    }
    return base64_encode($json);
}

/**
 * Extrai payload salvo no HTML da resposta do cliente.
 */
function eventos_cliente_extract_payload_from_html(string $html): array {
    if ($html === '') {
        return [];
    }
    if (!preg_match('/data-smile-client-payload="([^"]+)"/', $html, $matches)) {
        return [];
    }
    $encoded = html_entity_decode((string)($matches[1] ?? ''), ENT_QUOTES, 'UTF-8');
    if ($encoded === '') {
        return [];
    }
    $decoded = base64_decode($encoded, true);
    if ($decoded === false || $decoded === '') {
        return [];
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload) || !isset($payload['values']) || !is_array($payload['values'])) {
        return [];
    }
    $values = [];
    foreach ($payload['values'] as $key => $value) {
        $values[(string)$key] = (string)$value;
    }
    return $values;
}

/**
 * Lista de seções permitidas para link público.
 */
function eventos_cliente_parse_allowed_sections($raw): array {
    $sections = [];
    if (is_array($raw)) {
        $sections = $raw;
    } elseif (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $sections = $decoded;
        }
    }

    $allowed = [];
    foreach ($sections as $section) {
        $normalized = strtolower(trim((string)$section));
        if (in_array($normalized, ['dj_protocolo', 'observacoes_gerais'], true)) {
            $allowed[] = $normalized;
        }
    }
    if (empty($allowed)) {
        $allowed[] = 'dj_protocolo';
    }
    return array_values(array_unique($allowed));
}

/**
 * Resolve a seção principal atendida pelo token público.
 */
function eventos_cliente_resolver_secao_link(array $link): string {
    $link_type = strtolower(trim((string)($link['link_type'] ?? '')));
    if ($link_type === 'cliente_observacoes') {
        return 'observacoes_gerais';
    }
    if ($link_type === 'cliente_dj') {
        return 'dj_protocolo';
    }

    $allowed = eventos_cliente_parse_allowed_sections($link['allowed_sections'] ?? null);
    if (in_array('observacoes_gerais', $allowed, true)) {
        return 'observacoes_gerais';
    }
    return 'dj_protocolo';
}

/**
 * Metadados visuais/comportamentais por seção pública.
 */
function eventos_cliente_get_section_meta(string $section): array {
    $map = [
        'dj_protocolo' => [
            'page_title' => 'Organização do Evento - DJ/Músicas',
            'header_title' => '🎧 Organização - DJ / Músicas',
            'form_heading' => '🎵 Músicas e Protocolos',
            'default_form_title_prefix' => 'Formulário DJ / Protocolos - Quadro ',
            'upload_prefix' => 'cliente_dj',
            'notify_dj' => true,
        ],
        'observacoes_gerais' => [
            'page_title' => 'Organização do Evento - Observações Gerais',
            'header_title' => '📝 Organização - Observações Gerais',
            'form_heading' => '📝 Observações Gerais',
            'default_form_title_prefix' => 'Formulário de Observações Gerais - Quadro ',
            'upload_prefix' => 'cliente_observacoes',
            'notify_dj' => false,
        ],
    ];
    return $map[$section] ?? $map['dj_protocolo'];
}

/**
 * Sanitiza HTML de texto informativo (note) mantendo tags básicas.
 */
function eventos_cliente_sanitizar_note_html(string $html): string {
    $raw = trim($html);
    if ($raw === '') {
        return '';
    }
    $clean = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $raw) ?? '';
    $clean = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $clean) ?? '';
    $clean = preg_replace('#<(iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $clean) ?? '';
    $clean = strip_tags($clean, '<p><br><strong><b><em><i><u><ul><ol><li><a><span><div><table><thead><tbody><tr><th><td><h1><h2><h3><h4><h5><h6><blockquote><hr>');
    $clean = preg_replace('/\s+on[a-z]+\s*=\s*"[^"]*"/i', '', $clean) ?? $clean;
    $clean = preg_replace("/\s+on[a-z]+\s*=\s*'[^']*'/i", '', $clean) ?? $clean;
    $clean = preg_replace('/\s+on[a-z]+\s*=\s*[^\s>]+/i', '', $clean) ?? $clean;
    $clean = preg_replace('/(href|src)\s*=\s*"javascript:[^"]*"/i', '$1="#"', $clean) ?? $clean;
    $clean = preg_replace("/(href|src)\s*=\s*'javascript:[^']*'/i", '$1="#"', $clean) ?? $clean;
    return trim($clean);
}

/**
 * Retorna HTML seguro para campo de texto informativo.
 */
function eventos_cliente_note_html(array $field): string {
    $raw_html = (string)($field['content_html'] ?? '');
    $sanitized = eventos_cliente_sanitizar_note_html($raw_html);
    if ($sanitized !== '') {
        return $sanitized;
    }
    $fallback = trim((string)($field['label'] ?? ''));
    if ($fallback === '') {
        return '';
    }
    return '<em>' . eventos_cliente_e($fallback) . '</em>';
}

/**
 * Normaliza schema dinâmico recebido da seção DJ.
 */
function eventos_cliente_normalizar_schema($raw): array {
    if (!is_array($raw)) {
        return [];
    }
    $allowed_types = ['text', 'textarea', 'yesno', 'select', 'file', 'section', 'divider', 'note'];
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
        $content_html = $type === 'note' ? eventos_cliente_sanitizar_note_html((string)($item['content_html'] ?? '')) : '';
        if ($label === '' && $type !== 'divider' && !($type === 'note' && $content_html !== '')) {
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
            'required' => !empty($item['required']) && $type !== 'section' && $type !== 'divider' && $type !== 'note',
            'options' => $options,
            'content_html' => $type === 'note' ? $content_html : '',
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
    $values = [];

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
        if ($field_type === 'note') {
            $note_html = eventos_cliente_note_html($field);
            if ($note_html !== '') {
                $parts[] = '<div>' . $note_html . '</div>';
            }
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
            $display_value = $value === 'sim' ? 'Sim' : ($value === 'nao' ? 'Não' : '');
        } else {
            $display_value = $value;
        }

        $values[$field_id] = $value;
        $answer = $display_value !== '' ? nl2br(eventos_cliente_e($display_value)) : '<em>Não informado</em>';
        $parts[] = '<p><strong>' . eventos_cliente_e($label) . '</strong><br>' . $answer . '</p>';
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
        'content_html' => implode("\n", $parts),
        'values' => $values,
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

        // Resolver seção permitida pelo token e carregar dados.
        $link_section = eventos_cliente_resolver_secao_link($link);
        $section_meta = eventos_cliente_get_section_meta($link_section);

        // Buscar reunião e seção
        $reuniao = eventos_reuniao_get($pdo, $link['meeting_id']);
        $secao = eventos_reuniao_get_secao($pdo, $link['meeting_id'], $link_section);
        $anexos = eventos_reuniao_get_anexos($pdo, $link['meeting_id'], $link_section);
        $portal_config = eventos_cliente_portal_get($pdo, (int)$link['meeting_id']);

        $link_has_slot_rules = ($link_section === 'dj_protocolo') && !empty($link['portal_configured']);
        if ($link_has_slot_rules) {
            $link_visivel = !empty($link['portal_visible']);
            $link_editavel = !empty($link['portal_editable']);
        }

        if (is_array($portal_config) && !empty($portal_config)) {
            if ($link_section === 'observacoes_gerais') {
                $link_visivel = !empty($portal_config['visivel_reuniao']);
                $link_editavel = !empty($portal_config['editavel_reuniao']);
            } else {
                if (!$link_has_slot_rules) {
                    $link_visivel = !empty($portal_config['visivel_dj']);
                    $link_editavel = !empty($portal_config['editavel_dj']);
                } else {
                    $link_visivel = $link_visivel && !empty($portal_config['visivel_dj']);
                    $link_editavel = $link_editavel && !empty($portal_config['editavel_dj']);
                }
            }
        }

        if (!$link_visivel) {
            $error = 'Este conteúdo não está disponível no portal do cliente no momento.';
        }
    }
}

// Processar envio do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $link && !$error) {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'salvar') {
        if (!$link_editavel) {
            $error = 'Este formulário está em modo somente visualização.';
        } elseif (!empty($link['submitted_at'])) {
            $error = 'Este formulário já foi enviado e está travado. Aguarde o desbloqueio da equipe para editar novamente.';
        } else {
            $uploads = [];

            $schema_submit = [];
            if (!empty($link['form_schema']) && is_array($link['form_schema'])) {
                $schema_submit = eventos_cliente_normalizar_schema($link['form_schema']);
            } elseif (!empty($secao['form_schema_json'])) {
                $decoded_schema = json_decode((string)$secao['form_schema_json'], true);
                $schema_submit = eventos_cliente_normalizar_schema($decoded_schema);
            }

            if (!empty($schema_submit)) {
                $compiled = eventos_cliente_montar_resposta_schema($schema_submit, $_POST);
                if (empty($compiled['ok'])) {
                    $error = implode(' | ', array_slice((array)($compiled['errors'] ?? []), 0, 2));
                } else {
                    $content = (string)($compiled['content_html'] ?? '');
                    $slot_index = max(1, (int)($link['slot_index'] ?? 1));
                    $form_title = trim((string)($link['form_title'] ?? ''));
                    if ($form_title === '') {
                        $form_title = (string)($section_meta['default_form_title_prefix'] ?? 'Formulário - Quadro ') . $slot_index;
                    }
                    $content = '<h2>' . eventos_cliente_e($form_title) . '</h2>' . "\n" . $content;

                    $payload = eventos_cliente_encode_payload([
                        'slot_index' => $slot_index,
                        'values' => (array)($compiled['values'] ?? []),
                    ]);
                    if ($payload !== '') {
                        $content .= "\n" . '<div data-smile-client-payload="' . eventos_cliente_e($payload) . '" style="display:none;"></div>';
                    }

                    // Campos de upload dentro do schema
                    foreach ($schema_submit as $field) {
                        if (($field['type'] ?? '') !== 'file') {
                            continue;
                        }
                        $field_id = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($field['id'] ?? ''));
                        $field_input = 'file_' . $field_id;
                        $field_uploads = eventos_cliente_normalizar_uploads($_FILES, $field_input);
                        $has_existing_attachments = !empty($anexos);
                        if (!empty($field['required']) && empty($field_uploads) && !$has_existing_attachments) {
                            $error = 'Campo obrigatório sem anexo: ' . (string)($field['label'] ?? 'Arquivo');
                            break;
                        }
                        if (!empty($field_uploads)) {
                            $field_notes = eventos_cliente_normalizar_notas_upload($_POST['file_note_' . $field_id] ?? []);
                            foreach ($field_uploads as $upload_index => $field_file) {
                                $uploads[] = [
                                    'file' => $field_file,
                                    'note' => (string)($field_notes[$upload_index] ?? ''),
                                ];
                            }
                        }
                    }
                }
            } else {
                $content = (string)($_POST['content_html'] ?? '');
                // Modo legado (editor livre): mantém upload genérico opcional.
                $legacy_uploads = eventos_cliente_normalizar_uploads($_FILES, 'anexos');
                $legacy_notes = eventos_cliente_normalizar_notas_upload($_POST['anexos_note'] ?? []);
                foreach ($legacy_uploads as $upload_index => $legacy_file) {
                    $uploads[] = [
                        'file' => $legacy_file,
                        'note' => (string)($legacy_notes[$upload_index] ?? ''),
                    ];
                }
            }

            if ($error === '') {
                // Salvar conteúdo (como cliente)
                $result = eventos_reuniao_salvar_secao(
                    $pdo,
                    $link['meeting_id'],
                    $link_section,
                    $content,
                    0, // user_id = 0 para cliente
                    'Envio do cliente',
                    'cliente',
                    !empty($schema_submit) ? json_encode($schema_submit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
                );
                
                if ($result['ok']) {
                    $upload_errors = [];
                    if (!empty($uploads)) {
                        $uploader = new MagaluUpload(100);
                        foreach ($uploads as $upload_item) {
                            $file = is_array($upload_item['file'] ?? null) ? $upload_item['file'] : [];
                            $file_note = trim((string)($upload_item['note'] ?? ''));
                            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                                $upload_errors[] = 'Falha no arquivo: ' . ($file['name'] ?? 'sem nome');
                                continue;
                            }

                            try {
                                $prefix = 'eventos/reunioes/' . (int)$link['meeting_id'] . '/' . (string)($section_meta['upload_prefix'] ?? 'cliente_dj');
                                $upload_result = $uploader->upload($file, $prefix);
                                $save_result = eventos_reuniao_salvar_anexo(
                                    $pdo,
                                    (int)$link['meeting_id'],
                                    $link_section,
                                    $upload_result,
                                    'cliente',
                                    null,
                                    $file_note !== '' ? $file_note : null
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
                        // Mantém snapshot atualizado para permitir reenvio após corrigir anexos.
                        eventos_link_publico_salvar_snapshot($pdo, (int)$link['id'], $content);
                    } else {
                        $saved_link = eventos_link_publico_registrar_envio($pdo, (int)$link['id'], $content);
                        if (empty($saved_link)) {
                            $error = 'Conteúdo salvo, mas não foi possível finalizar o envio. Tente novamente.';
                            eventos_link_publico_salvar_snapshot($pdo, (int)$link['id'], $content);
                        } else {
                            $success = true;
                            if (!empty($section_meta['notify_dj'])) {
                                // Notifica o DJ por e-mail (assunto padrão exigido pelo negócio).
                                try {
                                    if (function_exists('eventos_notificar_cliente_enviou_dj')) {
                                        eventos_notificar_cliente_enviou_dj($pdo, (int)$link['meeting_id']);
                                    }
                                } catch (Throwable $e) {
                                    error_log("Falha ao notificar envio DJ: " . $e->getMessage());
                                }
                            }
                        }
                    }

                    // Recarregar seção e anexos
                    $secao = eventos_reuniao_get_secao($pdo, $link['meeting_id'], $link_section);
                    $anexos = eventos_reuniao_get_anexos($pdo, $link['meeting_id'], $link_section);
                } else {
                    $error = $result['error'] ?? 'Erro ao salvar';
                }
            }
        }
    }
}

// Dados do evento
$snapshot = $reuniao ? json_decode($reuniao['me_event_snapshot'], true) : [];
$is_locked = !empty($link['submitted_at']) || !$link_editavel;
$content = trim((string)($link['content_html_snapshot'] ?? ''));
if ($content === '') {
    $content = $secao['content_html'] ?? '';
}
$form_schema = [];
if (!empty($link['form_schema']) && is_array($link['form_schema'])) {
    $form_schema = eventos_cliente_normalizar_schema($link['form_schema']);
} elseif (!empty($secao['form_schema_json'])) {
    $decoded = json_decode((string)$secao['form_schema_json'], true);
    $form_schema = eventos_cliente_normalizar_schema($decoded);
}
$form_values = eventos_cliente_extract_payload_from_html($content);

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
    <title><?= eventos_cliente_e((string)($section_meta['page_title'] ?? 'Organização do Evento')) ?></title>
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
            margin-bottom: 0.55rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 0.45rem 0.55rem;
            background: #fff;
        }

        .attachment-item-head {
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }

        .attachment-note {
            margin-top: 0.3rem;
            margin-left: 1.4rem;
            font-size: 0.78rem;
            color: #475569;
            line-height: 1.4;
            white-space: pre-wrap;
        }

        .attachments-list a {
            color: #1d4ed8;
            text-decoration: none;
        }

        .attachments-list a:hover {
            text-decoration: underline;
        }

        .file-note-wrap {
            display: none;
            margin-top: 0.5rem;
            background: #f8fafc;
            border: 1px solid #dbe3ef;
            border-radius: 8px;
            padding: 0.6rem;
        }

        .file-note-wrap.active {
            display: block;
        }

        .file-note-item {
            margin-bottom: 0.5rem;
        }

        .file-note-item:last-child {
            margin-bottom: 0;
        }

        .file-note-item label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 0.25rem;
        }

        .file-note-item textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 0.5rem;
            font-size: 0.84rem;
            resize: vertical;
            min-height: 62px;
            background: #fff;
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
        <h1><?= eventos_cliente_e((string)($section_meta['header_title'] ?? 'Organização do Evento')) ?></h1>
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
            <h3>🔒 Formulário bloqueado</h3>
            <p>
                <?= !$link_editavel
                    ? 'Este formulário está disponível somente para visualização no momento.'
                    : 'Você já enviou as informações deste formulário. Se precisar fazer alterações, entre em contato com nossa equipe.' ?>
            </p>
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
                        <div class="attachment-item-head">
                            <span>📎</span>
                            <?php if (!empty($anexo['public_url'])): ?>
                            <a href="<?= htmlspecialchars($anexo['public_url']) ?>" target="_blank" rel="noopener noreferrer">
                                <?= htmlspecialchars($anexo['original_name'] ?? 'arquivo') ?>
                            </a>
                            <?php else: ?>
                            <span><?= htmlspecialchars($anexo['original_name'] ?? 'arquivo') ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (trim((string)($anexo['note'] ?? '')) !== ''): ?>
                        <div class="attachment-note"><strong>Observação:</strong> <?= eventos_cliente_e((string)$anexo['note']) ?></div>
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
                <h3><?= eventos_cliente_e((string)($section_meta['form_heading'] ?? 'Formulário')) ?></h3>
                
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
                        $field_raw_id = (string)($field['id'] ?? '');
                        $field_id = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $field_raw_id);
                        $field_name = 'field_' . $field_id;
                        $file_name = 'file_' . $field_id;
                        $label = (string)($field['label'] ?? '');
                        $required = !empty($field['required']);
                        $required_attr = $required ? ' required' : '';
                        $file_required_attr = ($required && empty($anexos)) ? ' required' : '';
                        $field_value = isset($form_values[$field_raw_id]) ? (string)$form_values[$field_raw_id] : '';
                    ?>
                    <?php if (($field['type'] ?? '') === 'divider'): ?>
                        <hr style="margin: 1rem 0; border: 0; border-top: 1px solid #e2e8f0;">
                    <?php elseif (($field['type'] ?? '') === 'section'): ?>
                        <h4 style="margin-top: 1.1rem; margin-bottom: 0.6rem; color: #1e3a8a;"><?= eventos_cliente_e($label) ?></h4>
                    <?php elseif (($field['type'] ?? '') === 'note'): ?>
                        <?php $note_html = eventos_cliente_note_html($field); ?>
                        <?php if ($note_html !== ''): ?>
                        <div style="margin: 0.2rem 0 0.9rem 0; color:#475569; font-size:0.9rem; background:#f8fafc; border:1px solid #dbe3ef; border-radius:8px; padding:0.65rem 0.75rem;">
                            <?= $note_html ?>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div style="margin-bottom: 1rem;">
                            <label style="display:block; font-weight:600; color:#334155; margin-bottom:0.35rem;" for="<?= eventos_cliente_e($field_name) ?>">
                                <?= eventos_cliente_e($label) ?><?= $required ? ' *' : '' ?>
                            </label>

                            <?php if (($field['type'] ?? '') === 'textarea'): ?>
                                <textarea id="<?= eventos_cliente_e($field_name) ?>" name="<?= eventos_cliente_e($field_name) ?>" rows="4" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.6rem;"<?= $required_attr ?>><?= eventos_cliente_e($field_value) ?></textarea>
                            <?php elseif (($field['type'] ?? '') === 'yesno'): ?>
                                <select id="<?= eventos_cliente_e($field_name) ?>" name="<?= eventos_cliente_e($field_name) ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.6rem;"<?= $required_attr ?>>
                                    <option value="">Selecione...</option>
                                    <option value="sim"<?= $field_value === 'sim' ? ' selected' : '' ?>>Sim</option>
                                    <option value="nao"<?= $field_value === 'nao' ? ' selected' : '' ?>>Não</option>
                                </select>
                            <?php elseif (($field['type'] ?? '') === 'select'): ?>
                                <select id="<?= eventos_cliente_e($field_name) ?>" name="<?= eventos_cliente_e($field_name) ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.6rem;"<?= $required_attr ?>>
                                    <option value="">Selecione...</option>
                                    <?php foreach (($field['options'] ?? []) as $opt): ?>
                                        <?php $opt_value = (string)$opt; ?>
                                        <option value="<?= eventos_cliente_e($opt_value) ?>"<?= $field_value === $opt_value ? ' selected' : '' ?>><?= eventos_cliente_e($opt_value) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (($field['type'] ?? '') === 'file'): ?>
                                <input type="file"
                                       id="<?= eventos_cliente_e($file_name) ?>"
                                       name="<?= eventos_cliente_e($file_name) ?>[]"
                                       multiple
                                       accept=".png,.jpg,.jpeg,.gif,.webp,.heic,.heif,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.xlsm,.ppt,.pptx,.odt,.ods,.odp,.mp3,.wav,.ogg,.aac,.m4a,.mp4,.mov,.webm,.avi,.zip,.rar,.7z,.xml,.ofx"
                                       style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.55rem;"
                                       data-note-target="<?= eventos_cliente_e($file_name) ?>Notes"
                                       data-note-name="file_note_<?= eventos_cliente_e($field_id) ?>[]"
                                       <?= $file_required_attr ?>>
                                <div class="file-note-wrap" id="<?= eventos_cliente_e($file_name) ?>Notes"></div>
                            <?php else: ?>
                                <input type="text" id="<?= eventos_cliente_e($field_name) ?>" name="<?= eventos_cliente_e($field_name) ?>" value="<?= eventos_cliente_e($field_value) ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.6rem;"<?= $required_attr ?>>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php else: ?>
                <div class="instructions">
                    <strong>Instruções:</strong>
                    <ul>
                        <?php if ($link_section === 'observacoes_gerais'): ?>
                        <li>Preencha os pontos e observações gerais com o máximo de clareza.</li>
                        <li>Use os anexos para enviar referências, documentos ou listas complementares.</li>
                        <li>Campos obrigatórios devem ser preenchidos antes do envio.</li>
                        <?php else: ?>
                        <li>Para cada música, informe o <strong>link do YouTube</strong> e o <strong>tempo de início</strong></li>
                        <li>Exemplo: <em>Valsa 0:20 - https://youtube.com/...</em></li>
                        <li>Inclua músicas para: entrada, valsas, momentos especiais, abertura de pista</li>
                        <li>Informe também seu gosto musical e ritmos preferidos</li>
                        <?php endif; ?>
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
                    <input type="file"
                           id="anexosInput"
                           name="anexos[]"
                           multiple
                           accept=".png,.jpg,.jpeg,.gif,.webp,.heic,.heif,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.xlsm,.ppt,.pptx,.odt,.ods,.odp,.mp3,.wav,.ogg,.aac,.m4a,.mp4,.mov,.webm,.avi,.zip,.rar,.7z,.xml,.ofx"
                           data-note-target="legacyAnexosNotes"
                           data-note-name="anexos_note[]">
                    <div class="file-note-wrap" id="legacyAnexosNotes"></div>
                    <p class="attachments-help">Envie vários arquivos de uma vez (até 100MB por arquivo): playlist, roteiro, arte do convite e materiais de referência.</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($anexos)): ?>
                <div class="attachments-list">
                    <h4>Arquivos já enviados</h4>
                    <ul>
                        <?php foreach ($anexos as $anexo): ?>
                        <li>
                            <div class="attachment-item-head">
                                <span>📎</span>
                                <?php if (!empty($anexo['public_url'])): ?>
                                <a href="<?= htmlspecialchars($anexo['public_url']) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= htmlspecialchars($anexo['original_name'] ?? 'arquivo') ?>
                                </a>
                                <?php else: ?>
                                <span><?= htmlspecialchars($anexo['original_name'] ?? 'arquivo') ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (trim((string)($anexo['note'] ?? '')) !== ''): ?>
                            <div class="attachment-note"><strong>Observação:</strong> <?= eventos_cliente_e((string)$anexo['note']) ?></div>
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
                Após o envio, a edição fica bloqueada até a equipe destravar novamente.
            </p>
        </form>
        
        <script>
            function execCmd(cmd) {
                document.execCommand(cmd, false, null);
            }

            function escapeHtmlClient(text) {
                const div = document.createElement('div');
                div.textContent = text || '';
                return div.innerHTML;
            }

            function renderUploadNotesForInput(input) {
                if (!input) return;
                const targetId = input.getAttribute('data-note-target') || '';
                const noteName = input.getAttribute('data-note-name') || '';
                if (!targetId || !noteName) return;

                const wrap = document.getElementById(targetId);
                if (!wrap) return;

                const files = Array.from(input.files || []);
                if (!files.length) {
                    wrap.classList.remove('active');
                    wrap.innerHTML = '';
                    return;
                }

                wrap.classList.add('active');
                wrap.innerHTML = files.map((file, index) => {
                    const fileLabel = file && file.name ? file.name : `arquivo-${index + 1}`;
                    return `
                        <div class="file-note-item">
                            <label for="${targetId}-note-${index}">Observação para ${escapeHtmlClient(fileLabel)} (opcional)</label>
                            <textarea id="${targetId}-note-${index}" name="${noteName}" rows="2" placeholder="Ex.: tocar no telão após a valsa, versão editada, prioridade etc."></textarea>
                        </div>
                    `;
                }).join('');
            }

            function bindUploadNoteInputs() {
                document.querySelectorAll('input[type="file"][data-note-target]').forEach((input) => {
                    input.addEventListener('change', () => renderUploadNotesForInput(input));
                });
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

            bindUploadNoteInputs();
        </script>
        <?php endif; ?>
    </div>
</body>
</html>
