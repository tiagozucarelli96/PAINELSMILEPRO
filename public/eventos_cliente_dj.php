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
$section_views = [];
$link_sections = ['dj_protocolo'];
$is_combined_reuniao = false;
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
 * Converte HTML rico para texto simples (com quebras de linha).
 */
function eventos_cliente_html_to_text(string $html): string {
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
    $text = preg_replace('/<\/p\s*>/i', "\n", $text) ?? $text;
    $text = preg_replace('/<\/div\s*>/i', "\n", $text) ?? $text;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
    return trim($text);
}

/**
 * Extrai payload salvo no HTML da resposta (cliente/interno).
 */
function eventos_cliente_extract_payload_from_html(string $html, string $section = ''): array {
    if ($html === '') {
        return [];
    }

    $encoded = '';
    $decoded_html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
    $attributes = ['data-smile-client-payload', 'data-smile-form-payload'];
    foreach ($attributes as $attribute) {
        $pattern = '/' . preg_quote($attribute, '/') . '\s*=\s*(["\'])(.*?)\1/i';
        if (!preg_match($pattern, $decoded_html, $matches)) {
            continue;
        }
        $encoded = trim((string)($matches[2] ?? ''));
        if ($encoded !== '') {
            break;
        }
    }
    if ($encoded === '') {
        return [];
    }

    $decoded = base64_decode($encoded, true);
    if ($decoded === false || $decoded === '') {
        return [];
    }

    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return [];
    }

    $values = [];
    if (isset($payload['values']) && is_array($payload['values'])) {
        foreach ($payload['values'] as $key => $value) {
            $values[(string)$key] = (string)$value;
        }
    }

    $payload_section = trim((string)($payload['section'] ?? $section));
    if ($payload_section !== '' && isset($payload['legacy_html'])) {
        $legacy_text = eventos_cliente_html_to_text((string)$payload['legacy_html']);
        if ($legacy_text !== '') {
            $values['legacy_portal_text_' . $payload_section] = $legacy_text;
        }
    }

    return $values;
}

/**
 * Mescla valores de payload, preservando o principal e completando campos vazios.
 */
function eventos_cliente_merge_payload_values(array $primary, array $fallback): array {
    if (empty($fallback)) {
        return $primary;
    }
    $merged = $primary;
    foreach ($fallback as $key => $value) {
        $field = (string)$key;
        if ($field === '') {
            continue;
        }
        $next = (string)$value;
        if (!array_key_exists($field, $merged) || trim((string)$merged[$field]) === '') {
            $merged[$field] = $next;
        }
    }
    return $merged;
}

/**
 * Normaliza identificadores usados em ids/nomes de campos.
 */
function eventos_cliente_normalizar_identificador(string $value, string $fallback = 'campo'): string {
    $normalized = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($value)) ?? '';
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        return $fallback;
    }
    return $normalized;
}

/**
 * Monta nome de input com prefixo opcional da seção para evitar colisões.
 */
function eventos_cliente_input_name(string $prefix, string $field_id, string $section = ''): string {
    $safe_field = eventos_cliente_normalizar_identificador($field_id);
    $safe_section = trim($section) !== '' ? eventos_cliente_normalizar_identificador($section, '') : '';
    return $prefix . '_' . ($safe_section !== '' ? $safe_section . '_' : '') . $safe_field;
}

/**
 * Nome do input principal de um campo do schema.
 */
function eventos_cliente_field_input_name(string $field_id, string $section = ''): string {
    return eventos_cliente_input_name('field', $field_id, $section);
}

/**
 * Nome do input de upload de um campo do schema.
 */
function eventos_cliente_file_input_name(string $field_id, string $section = ''): string {
    return eventos_cliente_input_name('file', $field_id, $section);
}

/**
 * Nome do input de observações dos uploads de um campo do schema.
 */
function eventos_cliente_file_note_input_name(string $field_id, string $section = ''): string {
    return eventos_cliente_input_name('file_note', $field_id, $section);
}

/**
 * Nome do input de conteúdo do editor livre por seção.
 */
function eventos_cliente_content_input_name(string $section): string {
    return 'content_html_' . eventos_cliente_normalizar_identificador($section, 'geral');
}

/**
 * Nome do input de upload genérico por seção.
 */
function eventos_cliente_legacy_upload_input_name(string $section): string {
    return 'anexos_' . eventos_cliente_normalizar_identificador($section, 'geral');
}

/**
 * Nome do input de observações dos uploads genéricos por seção.
 */
function eventos_cliente_legacy_upload_note_name(string $section): string {
    return 'anexos_note_' . eventos_cliente_normalizar_identificador($section, 'geral');
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
        if (in_array($normalized, ['decoracao', 'dj_protocolo', 'observacoes_gerais'], true)) {
            $allowed[] = $normalized;
        }
    }
    if (empty($allowed)) {
        $allowed[] = 'dj_protocolo';
    }
    return array_values(array_unique($allowed));
}

/**
 * Resolve todas as seções permitidas pelo token público.
 */
function eventos_cliente_resolver_secoes_link(array $link): array {
    $link_type = strtolower(trim((string)($link['link_type'] ?? '')));
    $allowed = eventos_cliente_parse_allowed_sections($link['allowed_sections'] ?? null);

    if ($link_type === 'cliente_observacoes') {
        $sections = [];
        foreach (['decoracao', 'observacoes_gerais'] as $section) {
            if (in_array($section, $allowed, true)) {
                $sections[] = $section;
            }
        }
        if (empty($sections)) {
            $sections[] = 'decoracao';
        }
        return $sections;
    }

    if ($link_type === 'cliente_dj') {
        return ['dj_protocolo'];
    }

    $sections = [];
    foreach (['decoracao', 'observacoes_gerais', 'dj_protocolo'] as $section) {
        if (in_array($section, $allowed, true)) {
            $sections[] = $section;
        }
    }

    if (empty($sections)) {
        $sections[] = 'dj_protocolo';
    }

    return $sections;
}

/**
 * Resolve a seção principal atendida pelo token público.
 */
function eventos_cliente_resolver_secao_link(array $link): string {
    $sections = eventos_cliente_resolver_secoes_link($link);
    return (string)($sections[0] ?? 'dj_protocolo');
}

/**
 * Metadados visuais/comportamentais por seção pública.
 */
function eventos_cliente_get_section_meta(string $section): array {
    $map = [
        'decoracao' => [
            'page_title' => 'Reunião Final - Portal do Cliente',
            'header_title' => '📝 Reunião Final',
            'form_heading' => '📝 Reunião Final',
            'default_form_title_prefix' => 'Formulário da Reunião Final - Quadro ',
            'upload_prefix' => 'cliente_reuniao',
            'notify_dj' => false,
        ],
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
 * Rótulo amigável da seção.
 */
function eventos_cliente_section_label(string $section): string {
    $labels = [
        'decoracao' => 'Decoração',
        'observacoes_gerais' => 'Observações Gerais',
        'dj_protocolo' => 'DJ / Protocolos',
    ];
    return $labels[$section] ?? 'Formulário';
}

/**
 * Instruções padrão por seção pública.
 */
function eventos_cliente_section_instructions(string $section): array {
    if ($section === 'decoracao') {
        return [
            'Preencha os pontos da reunião final com o máximo de clareza.',
            'Use os anexos para enviar referências, documentos ou listas complementares.',
            'Campos obrigatórios devem ser preenchidos antes do envio.',
        ];
    }
    if ($section === 'observacoes_gerais') {
        return [
            'Preencha os pontos e observações gerais com o máximo de clareza.',
            'Use os anexos para enviar referências, documentos ou listas complementares.',
            'Campos obrigatórios devem ser preenchidos antes do envio.',
        ];
    }
    return [
        'Para cada música, informe o link do YouTube e o tempo de início.',
        'Exemplo: Valsa 0:20 - https://youtube.com/...',
        'Inclua músicas para entrada, valsas, momentos especiais e abertura de pista.',
        'Informe também seu gosto musical e ritmos preferidos.',
    ];
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
        $default_value = trim((string)($item['default_value'] ?? ''));
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
        if ($type === 'note' && strpos($id, 'legacy_portal_text_') === 0) {
            $type = 'textarea';
            if ($default_value === '' && $content_html !== '') {
                $default_value = eventos_cliente_html_to_text($content_html);
            }
            $content_html = '';
        }
        $schema[] = [
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'required' => !empty($item['required']) && $type !== 'section' && $type !== 'divider' && $type !== 'note',
            'options' => $options,
            'content_html' => $type === 'note' ? $content_html : '',
            'default_value' => $default_value,
        ];
    }
    return $schema;
}

/**
 * Garante a presença do campo de texto livre quando visível no portal.
 */
function eventos_cliente_schema_garantir_texto_livre(array $schema, string $section, string $default_value = ''): array {
    $field_id = 'legacy_portal_text_' . trim($section);
    foreach ($schema as &$field) {
        if (!is_array($field)) {
            continue;
        }
        $current_id = trim((string)($field['id'] ?? ''));
        if ($current_id !== $field_id) {
            continue;
        }
        $type = strtolower(trim((string)($field['type'] ?? 'text')));
        if ($type === 'note') {
            $field['type'] = 'textarea';
            $field['content_html'] = '';
        }
        if (trim((string)($field['label'] ?? '')) === '') {
            $field['label'] = 'Texto livre (opcional)';
        }
        $existing_default = trim((string)($field['default_value'] ?? ''));
        if ($existing_default === '' && trim($default_value) !== '') {
            $field['default_value'] = trim($default_value);
        }
        unset($field);
        return $schema;
    }
    unset($field);

    $schema[] = [
        'id' => $field_id,
        'type' => 'textarea',
        'label' => 'Texto livre (opcional)',
        'required' => false,
        'options' => [],
        'content_html' => '',
        'default_value' => trim($default_value),
    ];

    return $schema;
}

/**
 * Monta HTML de resposta do cliente a partir do schema.
 */
function eventos_cliente_montar_resposta_schema(array $schema, array $post, string $section = ''): array {
    $errors = [];
    $parts = [];
    $values = [];

    foreach ($schema as $field) {
        $field_id = trim((string)($field['id'] ?? ''));
        $field_type = (string)($field['type'] ?? 'text');
        $label = trim((string)($field['label'] ?? 'Campo'));
        $required = !empty($field['required']);
        $input_name = eventos_cliente_field_input_name($field_id, $section);

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

/**
 * Prepara dados de uma seção pública para renderização/submit.
 */
function eventos_cliente_preparar_secao_publica(PDO $pdo, int $meeting_id, string $section, ?array $link = null, bool $prefer_link_snapshot = false): array {
    $secao = eventos_reuniao_get_secao($pdo, $meeting_id, $section);
    $anexos = eventos_reuniao_get_anexos($pdo, $meeting_id, $section);

    $content_from_link_snapshot = $prefer_link_snapshot ? trim((string)($link['content_html_snapshot'] ?? '')) : '';
    $content_from_secao = trim((string)($secao['content_html'] ?? ''));
    $content = $content_from_link_snapshot !== '' ? $content_from_link_snapshot : $content_from_secao;

    $form_schema = [];
    if ($prefer_link_snapshot && !empty($link['form_schema']) && is_array($link['form_schema'])) {
        $form_schema = eventos_cliente_normalizar_schema($link['form_schema']);
    } elseif (!empty($secao['form_schema_json'])) {
        $decoded = json_decode((string)$secao['form_schema_json'], true);
        $form_schema = eventos_cliente_normalizar_schema($decoded);
    }

    $legacy_text_portal_visible = true;
    if (is_array($secao) && array_key_exists('legacy_text_portal_visible', $secao)) {
        $legacy_text_portal_visible = !empty($secao['legacy_text_portal_visible']);
    }

    if (!empty($form_schema) && !$legacy_text_portal_visible) {
        $form_schema = array_values(array_filter($form_schema, static function ($field): bool {
            $field_id = trim((string)($field['id'] ?? ''));
            return strpos($field_id, 'legacy_portal_text_') !== 0;
        }));
    }
    if (empty($form_schema) && !$legacy_text_portal_visible) {
        $content = '';
    }

    $form_values = eventos_cliente_extract_payload_from_html($content, $section);
    if ($prefer_link_snapshot && $content_from_link_snapshot !== '' && $content_from_secao !== '' && $content_from_link_snapshot !== $content_from_secao) {
        $fallback_values = eventos_cliente_extract_payload_from_html($content_from_secao, $section);
        $form_values = eventos_cliente_merge_payload_values($form_values, $fallback_values);
    }

    if ($legacy_text_portal_visible) {
        $legacy_field_id = 'legacy_portal_text_' . $section;
        $legacy_default = trim((string)($form_values[$legacy_field_id] ?? ''));
        $form_schema = eventos_cliente_schema_garantir_texto_livre($form_schema, $section, $legacy_default);
    }

    return [
        'section' => $section,
        'label' => eventos_cliente_section_label($section),
        'meta' => eventos_cliente_get_section_meta($section),
        'secao' => $secao,
        'anexos' => $anexos,
        'content' => $content,
        'form_schema' => $form_schema,
        'form_values' => $form_values,
        'legacy_text_portal_visible' => $legacy_text_portal_visible,
        'uses_link_snapshot' => $prefer_link_snapshot,
    ];
}

/**
 * Monta um snapshot agregado das seções exibidas no token.
 */
function eventos_cliente_compor_snapshot_sections(array $section_contents): string {
    $parts = [];
    foreach ($section_contents as $section => $content) {
        $content_html = trim((string)$content);
        if ($content_html === '') {
            continue;
        }
        $parts[] = '<section data-smile-public-section="' . eventos_cliente_e((string)$section) . '">' . "\n"
            . $content_html . "\n"
            . '</section>';
    }
    return implode("\n", $parts);
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

        $link_sections = eventos_cliente_resolver_secoes_link($link);
        $is_combined_reuniao = strtolower(trim((string)($link['link_type'] ?? ''))) === 'cliente_observacoes'
            && in_array('decoracao', $link_sections, true)
            && in_array('observacoes_gerais', $link_sections, true);
        $link_section = (string)($link_sections[0] ?? 'dj_protocolo');
        $section_meta = $is_combined_reuniao
            ? [
                'page_title' => 'Reunião Final - Portal do Cliente',
                'header_title' => '📝 Reunião Final',
                'form_heading' => '📝 Reunião Final',
                'default_form_title_prefix' => 'Reunião Final - ',
                'upload_prefix' => 'cliente_reuniao',
                'notify_dj' => false,
            ]
            : eventos_cliente_get_section_meta($link_section);

        $reuniao = eventos_reuniao_get($pdo, $link['meeting_id']);
        $portal_config = eventos_cliente_portal_get($pdo, (int)$link['meeting_id']);

        if (is_array($portal_config) && !empty($portal_config)
            && strtolower(trim((string)($link['link_type'] ?? ''))) === 'cliente_observacoes'
            && (!empty($portal_config['visivel_reuniao']) || !empty($portal_config['editavel_reuniao']))
        ) {
            foreach (['decoracao', 'observacoes_gerais'] as $portal_section) {
                if (!in_array($portal_section, $link_sections, true)) {
                    $link_sections[] = $portal_section;
                }
            }
            $ordered_sections = [];
            foreach (['decoracao', 'observacoes_gerais', 'dj_protocolo'] as $candidate_section) {
                if (in_array($candidate_section, $link_sections, true)) {
                    $ordered_sections[] = $candidate_section;
                }
            }
            $link_sections = array_values(array_unique($ordered_sections));
            $link_section = (string)($link_sections[0] ?? 'decoracao');
            $is_combined_reuniao = in_array('decoracao', $link_sections, true)
                && in_array('observacoes_gerais', $link_sections, true);
            if ($is_combined_reuniao) {
                $section_meta = [
                    'page_title' => 'Reunião Final - Portal do Cliente',
                    'header_title' => '📝 Reunião Final',
                    'form_heading' => '📝 Reunião Final',
                    'default_form_title_prefix' => 'Reunião Final - ',
                    'upload_prefix' => 'cliente_reuniao',
                    'notify_dj' => false,
                ];
            }
        }

        $link_has_slot_rules = ($link_section === 'dj_protocolo') && !empty($link['portal_configured']);
        if ($link_has_slot_rules) {
            $link_visivel = !empty($link['portal_visible']);
            $link_editavel = !empty($link['portal_editable']);
        }

        if (is_array($portal_config) && !empty($portal_config)) {
            if ($is_combined_reuniao || $link_section === 'observacoes_gerais' || $link_section === 'decoracao') {
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
        } else {
            foreach ($link_sections as $section_key) {
                $section_views[$section_key] = eventos_cliente_preparar_secao_publica(
                    $pdo,
                    (int)$link['meeting_id'],
                    $section_key,
                    $link,
                    !$is_combined_reuniao && $section_key === $link_section
                );
            }
            $secao = $section_views[$link_section]['secao'] ?? null;
            $anexos = $section_views[$link_section]['anexos'] ?? [];
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
            $slot_index = max(1, (int)($link['slot_index'] ?? 1));
            $pending_sections = [];

            foreach ($link_sections as $section_key) {
                $section_view = $section_views[$section_key] ?? eventos_cliente_preparar_secao_publica(
                    $pdo,
                    (int)$link['meeting_id'],
                    $section_key,
                    $link,
                    !$is_combined_reuniao && $section_key === $link_section
                );
                $schema_submit = (array)($section_view['form_schema'] ?? []);
                $section_uploads = [];
                $section_content = '';

                if (!empty($schema_submit)) {
                    $compiled = eventos_cliente_montar_resposta_schema($schema_submit, $_POST, $section_key);
                    if (empty($compiled['ok'])) {
                        $error = implode(' | ', array_slice((array)($compiled['errors'] ?? []), 0, 2));
                        break;
                    }

                    $form_title = $is_combined_reuniao
                        ? eventos_cliente_section_label($section_key)
                        : trim((string)($link['form_title'] ?? ''));
                    if ($form_title === '') {
                        $default_prefix = (string)($section_view['meta']['default_form_title_prefix'] ?? $section_meta['default_form_title_prefix'] ?? 'Formulário - Quadro ');
                        $form_title = $default_prefix . $slot_index;
                    }

                    $section_content = '<h2>' . eventos_cliente_e($form_title) . '</h2>' . "\n" . (string)($compiled['content_html'] ?? '');
                    $payload = eventos_cliente_encode_payload([
                        'slot_index' => $slot_index,
                        'section' => $section_key,
                        'values' => (array)($compiled['values'] ?? []),
                    ]);
                    if ($payload !== '') {
                        $section_content .= "\n" . '<div data-smile-client-payload="' . eventos_cliente_e($payload) . '" style="display:none;"></div>';
                    }

                    foreach ($schema_submit as $field) {
                        if (($field['type'] ?? '') !== 'file') {
                            continue;
                        }
                        $field_raw_id = (string)($field['id'] ?? '');
                        $field_input = eventos_cliente_file_input_name($field_raw_id, $section_key);
                        $field_uploads = eventos_cliente_normalizar_uploads($_FILES, $field_input);
                        $has_existing_attachments = !empty($section_view['anexos']);
                        if (!empty($field['required']) && empty($field_uploads) && !$has_existing_attachments) {
                            $error = 'Campo obrigatório sem anexo: ' . (string)($field['label'] ?? 'Arquivo');
                            break 2;
                        }
                        if (!empty($field_uploads)) {
                            $field_notes = eventos_cliente_normalizar_notas_upload($_POST[eventos_cliente_file_note_input_name($field_raw_id, $section_key)] ?? []);
                            foreach ($field_uploads as $upload_index => $field_file) {
                                $section_uploads[] = [
                                    'file' => $field_file,
                                    'note' => (string)($field_notes[$upload_index] ?? ''),
                                ];
                            }
                        }
                    }
                } else {
                    $section_content = (string)($_POST[eventos_cliente_content_input_name($section_key)] ?? '');
                    if (trim(strip_tags($section_content)) === '') {
                        $error = 'Por favor, preencha as informações de ' . eventos_cliente_section_label($section_key) . ' antes de enviar.';
                        break;
                    }
                    $legacy_uploads = eventos_cliente_normalizar_uploads($_FILES, eventos_cliente_legacy_upload_input_name($section_key));
                    $legacy_notes = eventos_cliente_normalizar_notas_upload($_POST[eventos_cliente_legacy_upload_note_name($section_key)] ?? []);
                    foreach ($legacy_uploads as $upload_index => $legacy_file) {
                        $section_uploads[] = [
                            'file' => $legacy_file,
                            'note' => (string)($legacy_notes[$upload_index] ?? ''),
                        ];
                    }
                }

                $pending_sections[$section_key] = [
                    'content' => $section_content,
                    'schema_submit' => $schema_submit,
                    'uploads' => $section_uploads,
                    'meta' => $section_view['meta'] ?? eventos_cliente_get_section_meta($section_key),
                ];
            }

            if ($error === '') {
                $saved_contents = [];
                $upload_errors = [];
                $uploader = null;

                foreach ($pending_sections as $section_key => $pending) {
                    $result = eventos_reuniao_salvar_secao(
                        $pdo,
                        (int)$link['meeting_id'],
                        $section_key,
                        (string)$pending['content'],
                        0,
                        'Envio do cliente',
                        'cliente',
                        !empty($pending['schema_submit']) ? json_encode($pending['schema_submit'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
                    );

                    if (empty($result['ok'])) {
                        $error = $result['error'] ?? 'Erro ao salvar';
                        break;
                    }

                    $saved_contents[$section_key] = (string)$pending['content'];

                    if (!empty($pending['uploads'])) {
                        if (!$uploader) {
                            $uploader = new MagaluUpload(100);
                        }
                        foreach ($pending['uploads'] as $upload_item) {
                            $file = is_array($upload_item['file'] ?? null) ? $upload_item['file'] : [];
                            $file_note = trim((string)($upload_item['note'] ?? ''));
                            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                                $upload_errors[] = 'Falha no arquivo: ' . ($file['name'] ?? 'sem nome');
                                continue;
                            }

                            try {
                                $prefix = 'eventos/reunioes/' . (int)$link['meeting_id'] . '/' . (string)(($pending['meta']['upload_prefix'] ?? 'cliente_reuniao'));
                                $upload_result = $uploader->upload($file, $prefix);
                                $save_result = eventos_reuniao_salvar_anexo(
                                    $pdo,
                                    (int)$link['meeting_id'],
                                    $section_key,
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
                }

                $snapshot_content = eventos_cliente_compor_snapshot_sections($saved_contents);
                if ($snapshot_content === '' && !empty($saved_contents[$link_section])) {
                    $snapshot_content = (string)$saved_contents[$link_section];
                }

                if ($error === '') {
                    if (!empty($upload_errors)) {
                        $error = 'Conteúdo salvo, mas alguns anexos falharam: ' . implode(' | ', array_slice($upload_errors, 0, 2));
                        eventos_link_publico_salvar_snapshot($pdo, (int)$link['id'], $snapshot_content);
                    } else {
                        $saved_link = eventos_link_publico_registrar_envio($pdo, (int)$link['id'], $snapshot_content);
                        if (empty($saved_link)) {
                            $error = 'Conteúdo salvo, mas não foi possível finalizar o envio. Tente novamente.';
                            eventos_link_publico_salvar_snapshot($pdo, (int)$link['id'], $snapshot_content);
                        } else {
                            $success = true;
                            if (!empty($section_meta['notify_dj'])) {
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
                }

                if ($error !== '' || $success) {
                    $section_views = [];
                    foreach ($link_sections as $section_key) {
                        $section_views[$section_key] = eventos_cliente_preparar_secao_publica(
                            $pdo,
                            (int)$link['meeting_id'],
                            $section_key,
                            $link,
                            !$is_combined_reuniao && $section_key === $link_section
                        );
                    }
                    $secao = $section_views[$link_section]['secao'] ?? null;
                    $anexos = $section_views[$link_section]['anexos'] ?? [];
                }
            }
        }
    }
}

// Dados do evento
$snapshot = $reuniao ? json_decode($reuniao['me_event_snapshot'], true) : [];
$is_locked = !empty($link['submitted_at']) || !$link_editavel;

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
$section_view_items = array_values($section_views);
$section_views_total = count($section_view_items);
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
        
        <?php foreach ($section_view_items as $section_view): ?>
        <?php
            $section_key = (string)($section_view['section'] ?? $link_section);
            $section_label = (string)($section_view['label'] ?? eventos_cliente_section_label($section_key));
            $section_content = (string)($section_view['content'] ?? '');
            $section_anexos = (array)($section_view['anexos'] ?? []);
            $read_only_heading = ($is_combined_reuniao || $section_views_total > 1)
                ? $section_label
                : 'Suas informações enviadas:';
        ?>
        <div class="form-section">
            <h3><?= eventos_cliente_e($read_only_heading) ?></h3>
            <div style="padding: 1rem; background: #f8fafc; border-radius: 8px; margin-top: 1rem;">
                <?= $section_content ?: '<em>Sem conteúdo</em>' ?>
            </div>
            <?php if (!empty($section_anexos)): ?>
            <div class="attachments-list">
                <h4>Anexos enviados</h4>
                <ul>
                    <?php foreach ($section_anexos as $anexo): ?>
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
        <?php endforeach; ?>
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
            
            <?php foreach ($section_view_items as $section_view): ?>
            <?php
                $section_key = (string)($section_view['section'] ?? $link_section);
                $section_label = (string)($section_view['label'] ?? eventos_cliente_section_label($section_key));
                $section_schema = (array)($section_view['form_schema'] ?? []);
                $section_values = (array)($section_view['form_values'] ?? []);
                $section_content = (string)($section_view['content'] ?? '');
                $section_anexos = (array)($section_view['anexos'] ?? []);
                $section_heading = ($is_combined_reuniao || $section_views_total > 1)
                    ? $section_label
                    : (string)($section_meta['form_heading'] ?? 'Formulário');
                $section_editor_id = 'editor_' . eventos_cliente_normalizar_identificador($section_key, 'geral');
                $section_content_input_id = 'content_input_' . eventos_cliente_normalizar_identificador($section_key, 'geral');
                $legacy_upload_input = eventos_cliente_legacy_upload_input_name($section_key);
                $legacy_note_name = eventos_cliente_legacy_upload_note_name($section_key);
                $legacy_note_target = $legacy_upload_input . '_notes';
            ?>
            <div class="form-section">
                <h3><?= eventos_cliente_e($section_heading) ?></h3>
                
                <?php if (!empty($section_schema)): ?>
                <div class="instructions">
                    <strong>Preencha os campos abaixo:</strong>
                    <ul>
                        <li>Os campos com <strong>*</strong> são obrigatórios.</li>
                        <li>Use respostas claras e objetivas para alinharmos seu evento.</li>
                    </ul>
                </div>

                <?php foreach ($section_schema as $field): ?>
                    <?php
                        $field_raw_id = (string)($field['id'] ?? '');
                        $field_name = eventos_cliente_field_input_name($field_raw_id, $section_key);
                        $file_name = eventos_cliente_file_input_name($field_raw_id, $section_key);
                        $file_note_name = eventos_cliente_file_note_input_name($field_raw_id, $section_key);
                        $file_note_target = $file_name . 'Notes';
                        $label = (string)($field['label'] ?? '');
                        $required = !empty($field['required']);
                        $required_attr = $required ? ' required' : '';
                        $file_required_attr = ($required && empty($section_anexos)) ? ' required' : '';
                        $field_default_value = isset($field['default_value']) ? (string)$field['default_value'] : '';
                        $field_value = isset($section_values[$field_raw_id]) ? (string)$section_values[$field_raw_id] : $field_default_value;
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
                                       data-note-target="<?= eventos_cliente_e($file_note_target) ?>"
                                       data-note-name="<?= eventos_cliente_e($file_note_name) ?>[]"
                                       <?= $file_required_attr ?>>
                                <div class="file-note-wrap" id="<?= eventos_cliente_e($file_note_target) ?>"></div>
                            <?php else: ?>
                                <input type="text" id="<?= eventos_cliente_e($field_name) ?>" name="<?= eventos_cliente_e($field_name) ?>" value="<?= eventos_cliente_e($field_value) ?>" style="width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:0.6rem;"<?= $required_attr ?>>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php else: ?>
                <?php $instructions = eventos_cliente_section_instructions($section_key); ?>
                <div class="instructions">
                    <strong>Instruções:</strong>
                    <ul>
                        <?php foreach ($instructions as $instruction): ?>
                        <li><?= eventos_cliente_e($instruction) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <div class="editor-wrapper">
                    <div class="editor-toolbar">
                        <button type="button" onclick="execCmd('bold', '<?= eventos_cliente_e($section_editor_id) ?>')"><b>B</b></button>
                        <button type="button" onclick="execCmd('italic', '<?= eventos_cliente_e($section_editor_id) ?>')"><i>I</i></button>
                        <button type="button" onclick="execCmd('underline', '<?= eventos_cliente_e($section_editor_id) ?>')"><u>U</u></button>
                        <button type="button" onclick="execCmd('insertUnorderedList', '<?= eventos_cliente_e($section_editor_id) ?>')">• Lista</button>
                    </div>
                    <div class="editor-content"
                         id="<?= eventos_cliente_e($section_editor_id) ?>"
                         contenteditable="true"
                         data-client-editor="legacy"
                         data-content-input="<?= eventos_cliente_e($section_content_input_id) ?>"
                         data-section-label="<?= eventos_cliente_e($section_label) ?>"><?= $section_content ?></div>
                </div>
                <input type="hidden" name="<?= eventos_cliente_e(eventos_cliente_content_input_name($section_key)) ?>" id="<?= eventos_cliente_e($section_content_input_id) ?>">

                <div class="attachments-box">
                    <label for="<?= eventos_cliente_e($legacy_upload_input) ?>">Anexos (opcional)</label>
                    <input type="file"
                           id="<?= eventos_cliente_e($legacy_upload_input) ?>"
                           name="<?= eventos_cliente_e($legacy_upload_input) ?>[]"
                           multiple
                           accept=".png,.jpg,.jpeg,.gif,.webp,.heic,.heif,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.xlsm,.ppt,.pptx,.odt,.ods,.odp,.mp3,.wav,.ogg,.aac,.m4a,.mp4,.mov,.webm,.avi,.zip,.rar,.7z,.xml,.ofx"
                           data-note-target="<?= eventos_cliente_e($legacy_note_target) ?>"
                           data-note-name="<?= eventos_cliente_e($legacy_note_name) ?>[]">
                    <div class="file-note-wrap" id="<?= eventos_cliente_e($legacy_note_target) ?>"></div>
                    <p class="attachments-help">Envie vários arquivos de uma vez (até 100MB por arquivo): playlist, roteiro, arte do convite e materiais de referência.</p>
                </div>
                <?php endif; ?>

                <?php if (!empty($section_anexos)): ?>
                <div class="attachments-list">
                    <h4>Arquivos já enviados</h4>
                    <ul>
                        <?php foreach ($section_anexos as $anexo): ?>
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
            <?php endforeach; ?>
            
            <button type="submit" class="btn btn-primary" id="submitBtn">
                ✓ Enviar Informações
            </button>
            
            <p style="text-align: center; margin-top: 1rem; font-size: 0.875rem; color: #64748b;">
                Após o envio, a edição fica bloqueada até a equipe destravar novamente.
            </p>
        </form>
        
        <script>
            function execCmd(cmd, editorId = '') {
                const editor = editorId ? document.getElementById(editorId) : document.querySelector('[data-client-editor="legacy"]');
                if (!editor) return;
                editor.focus();
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
                let emptyLegacySection = '';
                document.querySelectorAll('[data-client-editor="legacy"]').forEach((editor) => {
                    const inputId = editor.getAttribute('data-content-input') || '';
                    const contentInput = inputId ? document.getElementById(inputId) : null;
                    if (!contentInput) return;
                    contentInput.value = editor.innerHTML;
                    if (!emptyLegacySection && !editor.innerText.trim()) {
                        emptyLegacySection = editor.getAttribute('data-section-label') || 'o formulário';
                    }
                });
                if (emptyLegacySection !== '') {
                    e.preventDefault();
                    alert(`Por favor, preencha as informações de ${emptyLegacySection} antes de enviar.`);
                    return false;
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
