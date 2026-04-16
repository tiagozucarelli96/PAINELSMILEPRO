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
$can_eventos = !empty($_SESSION['perm_eventos']);
$can_realizar_evento = !empty($_SESSION['perm_eventos_realizar']);
$is_superadmin = !empty($_SESSION['perm_superadmin']);
$somente_realizar = (!$is_superadmin && !$can_eventos && $can_realizar_evento);

if (!$is_superadmin && !$can_eventos && !$can_realizar_evento) {
    header('Location: index.php?page=dashboard');
    exit;
}

$user_id = $_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0;
$meeting_id = (int)($_GET['id'] ?? $_POST['meeting_id'] ?? 0);
$me_event_id = (int)($_GET['me_event_id'] ?? $_POST['me_event_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$origin = strtolower(trim((string)($_GET['origin'] ?? $_POST['origin'] ?? '')));
if ($somente_realizar) {
    $origin = 'realizar';
}
$readonly_mode = $somente_realizar
    || ($origin === 'realizar')
    || ((string)($_GET['readonly'] ?? $_POST['readonly'] ?? '0') === '1');

$reuniao = null;
$secoes = [];
$error = '';
$success = '';
$observacoes_editor_blocks = [
    ['key' => 'legacy_text', 'label' => 'Texto livre (opcional)', 'description' => 'Área aberta para observações complementares.', 'public' => true, 'open' => false],
    ['key' => 'cronograma', 'label' => 'Cronograma', 'description' => 'Use este bloco para roteiro, horários e sequência do evento.', 'public' => true, 'open' => false],
    ['key' => 'fornecedores_externos', 'label' => 'Fornecedores externos', 'description' => 'Registre contatos, entregas e combinados com fornecedores parceiros.', 'public' => true, 'open' => false],
    ['key' => 'informacoes_importantes', 'label' => 'Informações importantes', 'description' => 'Pontos críticos que precisam ficar claros para a execução do evento.', 'public' => true, 'open' => false],
    ['key' => 'informacoes_internas', 'label' => 'Informações internas', 'description' => 'Uso exclusivo da equipe interna. Nunca exibido ao cliente.', 'public' => false, 'open' => false],
];

function eventos_reuniao_normalizar_uploads_field(array $files, string $field): array {
    if (empty($files[$field])) {
        return [];
    }

    $entry = $files[$field];
    if (!is_array($entry)) {
        return [];
    }

    if (!is_array($entry['name'] ?? null)) {
        if (($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        return [[
            'name' => (string)($entry['name'] ?? ''),
            'type' => (string)($entry['type'] ?? ''),
            'tmp_name' => (string)($entry['tmp_name'] ?? ''),
            'error' => (int)($entry['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($entry['size'] ?? 0),
        ]];
    }

    $items = [];
    $names = $entry['name'];
    $total = is_array($names) ? count($names) : 0;
    for ($i = 0; $i < $total; $i++) {
        if (($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $items[] = [
            'name' => (string)($entry['name'][$i] ?? ''),
            'type' => (string)($entry['type'][$i] ?? ''),
            'tmp_name' => (string)($entry['tmp_name'][$i] ?? ''),
            'error' => (int)($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($entry['size'][$i] ?? 0),
        ];
    }

    return $items;
}

function eventos_reuniao_serializar_anexo(array $anexo): array {
    return [
        'id' => (int)($anexo['id'] ?? 0),
        'original_name' => (string)($anexo['original_name'] ?? 'arquivo'),
        'mime_type' => (string)($anexo['mime_type'] ?? 'application/octet-stream'),
        'file_kind' => (string)($anexo['file_kind'] ?? 'outros'),
        'size_bytes' => (int)($anexo['size_bytes'] ?? 0),
        'public_url' => (string)($anexo['public_url'] ?? ''),
        'uploaded_at' => array_key_exists('uploaded_at', $anexo) && $anexo['uploaded_at'] !== null ? (string)$anexo['uploaded_at'] : null,
        'uploaded_by_type' => (string)($anexo['uploaded_by_type'] ?? ''),
        'note' => (string)($anexo['note'] ?? ''),
        'is_draft' => !empty($anexo['is_draft']),
    ];
}

function eventos_reuniao_serializar_lista_anexos(array $anexos): array {
    $serialized = [];
    foreach ($anexos as $anexo) {
        if (!is_array($anexo)) {
            continue;
        }
        $serialized[] = eventos_reuniao_serializar_anexo($anexo);
    }
    return $serialized;
}

function eventos_reuniao_serializar_link_formulario_payload(PDO $pdo, int $meeting_id, array $link, string $section = 'formulario'): array {
    $link_id = (int)($link['id'] ?? 0);
    $section_key = trim($section) !== '' ? trim($section) : 'formulario';
    $draft_attachments = [];
    $submitted_attachments = [];

    if ($link_id > 0) {
        try {
            $draft_attachments = eventos_reuniao_serializar_lista_anexos(
                eventos_reuniao_get_anexos_link_rascunho($pdo, $meeting_id, $section_key, $link_id)
            );
        } catch (Throwable $e) {
            error_log('eventos_reuniao_final draft attachments link ' . $link_id . ': ' . $e->getMessage());
        }

        try {
            $submitted_attachments = eventos_reuniao_serializar_lista_anexos(
                eventos_reuniao_get_anexos_link_finais($pdo, $meeting_id, $section_key, $link_id)
            );
        } catch (Throwable $e) {
            error_log('eventos_reuniao_final submitted attachments link ' . $link_id . ': ' . $e->getMessage());
        }
    }

    return [
        'id' => $link_id,
        'token' => (string)($link['token'] ?? ''),
        'is_active' => !empty($link['is_active']),
        'slot_index' => (int)($link['slot_index'] ?? 1),
        'form_title' => (string)($link['form_title'] ?? ''),
        'submitted_at' => array_key_exists('submitted_at', $link) && $link['submitted_at'] !== null ? (string)$link['submitted_at'] : null,
        'draft_saved_at' => array_key_exists('draft_saved_at', $link) && $link['draft_saved_at'] !== null ? (string)$link['draft_saved_at'] : null,
        'portal_visible' => !empty($link['portal_visible']),
        'portal_editable' => !empty($link['portal_editable']),
        'portal_configured' => !empty($link['portal_configured']),
        'draft_preview_text' => eventos_reuniao_resumir_snapshot_publico((string)($link['draft_content_html_snapshot'] ?? '')),
        'submitted_preview_text' => eventos_reuniao_resumir_snapshot_publico((string)($link['content_html_snapshot'] ?? '')),
        'draft_attachments' => $draft_attachments,
        'submitted_attachments' => $submitted_attachments,
        'form_schema' => is_array($link['form_schema'] ?? null) ? $link['form_schema'] : [],
    ];
}

function eventos_reuniao_json_script($value, string $fallback = 'null'): string {
    $options = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $options |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $encoded = json_encode($value, $options);
    if ($encoded === false) {
        error_log('eventos_reuniao_final json_encode script payload: ' . json_last_error_msg());
        return $fallback;
    }
    return $encoded;
}

function eventos_reuniao_validar_senha_usuario(PDO $pdo, int $user_id, string $senha): array {
    if ($user_id <= 0) {
        return ['ok' => false, 'error' => 'Sessão inválida. Faça login novamente.'];
    }

    $senha = (string)$senha;
    if ($senha === '') {
        return ['ok' => false, 'error' => 'Informe sua senha para confirmar a exclusão.'];
    }

    try {
        $cols_stmt = $pdo->query("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'usuarios'
        ");
        $cols = $cols_stmt ? $cols_stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $has = static function(string $column) use ($cols): bool {
            return in_array($column, $cols, true);
        };

        $senha_col = null;
        foreach (['senha', 'senha_hash', 'password', 'pass'] as $candidate) {
            if ($has($candidate)) {
                $senha_col = $candidate;
                break;
            }
        }

        if ($senha_col === null) {
            return ['ok' => false, 'error' => 'Não foi possível validar a senha neste ambiente.'];
        }

        $stmt = $pdo->prepare("SELECT id, {$senha_col} AS senha_armazenada FROM usuarios WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $user_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$usuario) {
            return ['ok' => false, 'error' => 'Usuário da sessão não encontrado.'];
        }

        $stored = (string)($usuario['senha_armazenada'] ?? '');
        if ($stored === '') {
            return ['ok' => false, 'error' => 'Senha inválida. Exclusão cancelada.'];
        }

        $ok = false;
        if (!$ok && preg_match('/^\$2[ayb]\$|\$argon2/i', $stored) === 1) {
            $ok = password_verify($senha, $stored);
        }
        if (!$ok && preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
            $ok = (strtolower($stored) === md5($senha));
        }
        if (!$ok) {
            $ok = hash_equals($stored, $senha);
        }

        if (!$ok) {
            return ['ok' => false, 'error' => 'Senha inválida. Exclusão cancelada.'];
        }

        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('eventos_reuniao_final validar senha exclusao: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Falha ao validar a senha. Tente novamente.'];
    }
}

// Processar ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if ($readonly_mode) {
        echo json_encode([
            'ok' => false,
            'error' => 'Modo realização: tela em somente leitura.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
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
            $legacy_text_portal_visible = null;
            if (array_key_exists('legacy_text_portal_visible', $_POST)) {
                $legacy_text_portal_visible = ((string)($_POST['legacy_text_portal_visible'] ?? '0') === '1');
            }
            
            if ($meeting_id <= 0 || !$section) {
                echo json_encode(['ok' => false, 'error' => 'Dados inválidos']);
                exit;
            }
            
            $result = eventos_reuniao_salvar_secao(
                $pdo,
                $meeting_id,
                $section,
                $content,
                $user_id,
                $note,
                'interno',
                $form_schema_json,
                $legacy_text_portal_visible
            );

            if (!empty($result['ok']) && $section === 'decoracao') {
                $portal_cfg = eventos_cliente_portal_get($pdo, $meeting_id);
                if (is_array($portal_cfg) && !empty($portal_cfg)) {
                    $sync_reuniao = eventos_cliente_portal_sincronizar_link_reuniao(
                        $pdo,
                        $meeting_id,
                        !empty($portal_cfg['visivel_reuniao']),
                        !empty($portal_cfg['editavel_reuniao']),
                        (int)$user_id
                    );
                    if (empty($sync_reuniao['ok'])) {
                        error_log('eventos_reuniao_final salvar_secao sync reuniao: ' . (string)($sync_reuniao['error'] ?? 'erro desconhecido'));
                    }
                }
            }
            if (!empty($result['ok']) && $section === 'dj_protocolo') {
                $portal_cfg = eventos_cliente_portal_get($pdo, $meeting_id);
                if (is_array($portal_cfg) && !empty($portal_cfg)) {
                    $sync_dj = eventos_cliente_portal_sincronizar_link_dj(
                        $pdo,
                        $meeting_id,
                        !empty($portal_cfg['visivel_dj']),
                        !empty($portal_cfg['editavel_dj']),
                        (int)$user_id
                    );
                    if (empty($sync_dj['ok'])) {
                        error_log('eventos_reuniao_final salvar_secao sync dj: ' . (string)($sync_dj['error'] ?? 'erro desconhecido'));
                    }
                }
            }
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

        case 'gerar_link_cliente_observacoes':
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
                $slot_index,
                'observacoes_gerais',
                'cliente_observacoes'
            );
            if ($result['ok']) {
                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $result['url'] = $base_url . '/index.php?page=eventos_cliente_dj&token=' . $result['link']['token'];
                $result['slot_index'] = $slot_index;
            }
            echo json_encode($result);
            exit;

        case 'atualizar_dj_slot_portal_config':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $portal_visible = ((string)($_POST['portal_visible'] ?? '0') === '1');
            $portal_editable = ((string)($_POST['portal_editable'] ?? '0') === '1');
            if ($portal_editable) {
                $portal_visible = true;
            }
            $schema_payload = $_POST['form_schema_json'] ?? null;
            $content_snapshot = trim((string)($_POST['content_html'] ?? ''));
            $form_title = trim((string)($_POST['form_title'] ?? ''));

            $schema_array = null;
            if ($schema_payload !== null && $schema_payload !== '') {
                $decoded = json_decode((string)$schema_payload, true);
                if (!is_array($decoded)) {
                    echo json_encode(['ok' => false, 'error' => 'Schema inválido para configurar o quadro']);
                    exit;
                }
                $schema_array = $decoded;
            }

            $result = eventos_reuniao_atualizar_slot_portal_config(
                $pdo,
                $meeting_id,
                $slot_index,
                'cliente_dj',
                $portal_visible,
                $portal_editable,
                (int)$user_id,
                $schema_array,
                $content_snapshot !== '' ? $content_snapshot : null,
                $form_title !== '' ? $form_title : null,
                'dj_protocolo'
            );
            if (!empty($result['ok']) && !empty($result['link']['token'])) {
                $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                $result['url'] = $base_url . '/index.php?page=eventos_cliente_dj&token=' . $result['link']['token'];
            }
            echo json_encode($result);
            exit;

        case 'atualizar_formulario_slot_portal_config':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $portal_visible = ((string)($_POST['portal_visible'] ?? '0') === '1');
            $portal_editable = ((string)($_POST['portal_editable'] ?? '0') === '1');
            if ($portal_editable) {
                $portal_visible = true;
            }
            $schema_payload = $_POST['form_schema_json'] ?? null;
            $content_snapshot = trim((string)($_POST['content_html'] ?? ''));
            $form_title = trim((string)($_POST['form_title'] ?? ''));

            $schema_array = null;
            if ($schema_payload !== null && $schema_payload !== '') {
                $decoded = json_decode((string)$schema_payload, true);
                if (!is_array($decoded)) {
                    echo json_encode(['ok' => false, 'error' => 'Schema inválido para configurar o formulário']);
                    exit;
                }
                $schema_array = $decoded;
            }

            $result = eventos_reuniao_atualizar_slot_portal_config(
                $pdo,
                $meeting_id,
                $slot_index,
                'cliente_formulario',
                $portal_visible,
                $portal_editable,
                (int)$user_id,
                $schema_array,
                $content_snapshot !== '' ? $content_snapshot : null,
                $form_title !== '' ? $form_title : null,
                'formulario'
            );
            if (!empty($result['ok']) && !empty($result['link']['token'])) {
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
            $confirm_password = (string)($_POST['confirm_password'] ?? '');
            $password_check = eventos_reuniao_validar_senha_usuario($pdo, (int)$user_id, $confirm_password);
            if (empty($password_check['ok'])) {
                echo json_encode(['ok' => false, 'error' => (string)($password_check['error'] ?? 'Senha inválida.')]);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $result = eventos_reuniao_excluir_slot_cliente(
                $pdo,
                $meeting_id,
                $slot_index,
                (int)$user_id,
                'cliente_dj',
                true
            );
            echo json_encode($result);
            exit;

        case 'destravar_observacoes_slot':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $result = eventos_reuniao_destravar_slot_cliente(
                $pdo,
                $meeting_id,
                $slot_index,
                (int)$user_id,
                'cliente_observacoes',
                'observacoes_gerais'
            );
            echo json_encode($result);
            exit;

        case 'excluir_observacoes_slot':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $result = eventos_reuniao_excluir_slot_cliente($pdo, $meeting_id, $slot_index, (int)$user_id, 'cliente_observacoes');
            echo json_encode($result);
            exit;

        case 'destravar_formulario_slot':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $result = eventos_reuniao_destravar_slot_cliente(
                $pdo,
                $meeting_id,
                $slot_index,
                (int)$user_id,
                'cliente_formulario',
                'formulario'
            );
            echo json_encode($result);
            exit;

        case 'excluir_formulario_slot':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $confirm_password = (string)($_POST['confirm_password'] ?? '');
            $password_check = eventos_reuniao_validar_senha_usuario($pdo, (int)$user_id, $confirm_password);
            if (empty($password_check['ok'])) {
                echo json_encode(['ok' => false, 'error' => (string)($password_check['error'] ?? 'Senha inválida.')]);
                exit;
            }
            $slot_index = max(1, (int)($_POST['slot_index'] ?? 1));
            $result = eventos_reuniao_excluir_slot_cliente(
                $pdo,
                $meeting_id,
                $slot_index,
                (int)$user_id,
                'cliente_formulario',
                true
            );
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

        case 'upload_anexos_dj':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }
            $upload_note = trim((string)($_POST['anexo_note'] ?? ''));
            if (strlen($upload_note) > 300) {
                $upload_note = substr($upload_note, 0, 300);
            }

            $uploads = eventos_reuniao_normalizar_uploads_field($_FILES, 'anexos');
            if (empty($uploads)) {
                echo json_encode(['ok' => false, 'error' => 'Nenhum arquivo enviado']);
                exit;
            }

            $uploaded = 0;
            $errors = [];

            try {
                $uploader = new MagaluUpload();
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'error' => 'Falha ao inicializar upload: ' . $e->getMessage()]);
                exit;
            }

            foreach ($uploads as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    $errors[] = 'Falha no arquivo: ' . (($file['name'] ?? '') !== '' ? $file['name'] : 'sem nome');
                    continue;
                }

                try {
                    $prefix = 'eventos/reunioes/' . $meeting_id . '/interno_dj';
                    $upload_result = $uploader->upload($file, $prefix);
                    $save_result = eventos_reuniao_salvar_anexo(
                        $pdo,
                        $meeting_id,
                        'dj_protocolo',
                        $upload_result,
                        'interno',
                        (int)$user_id,
                        $upload_note !== '' ? $upload_note : null
                    );
                    if (empty($save_result['ok'])) {
                        $errors[] = (($file['name'] ?? '') !== '' ? $file['name'] : 'arquivo') . ': ' . ($save_result['error'] ?? 'erro ao salvar metadados');
                        continue;
                    }
                    $uploaded++;
                } catch (Throwable $e) {
                    $errors[] = (($file['name'] ?? '') !== '' ? $file['name'] : 'arquivo') . ': ' . $e->getMessage();
                }
            }

            if ($uploaded <= 0) {
                echo json_encode(['ok' => false, 'error' => !empty($errors) ? implode(' | ', array_slice($errors, 0, 2)) : 'Não foi possível enviar os arquivos']);
                exit;
            }

            $anexos_dj_response = array_map(
                static function(array $anexo): array {
                    return eventos_reuniao_serializar_anexo($anexo);
                },
                eventos_reuniao_get_anexos($pdo, $meeting_id, 'dj_protocolo')
            );

            echo json_encode([
                'ok' => true,
                'uploaded' => $uploaded,
                'warning' => !empty($errors) ? implode(' | ', array_slice($errors, 0, 2)) : '',
                'anexos' => $anexos_dj_response
            ]);
            exit;

        case 'excluir_anexo_dj':
            if ($meeting_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Reunião inválida']);
                exit;
            }

            $anexo_id = (int)($_POST['anexo_id'] ?? 0);
            if ($anexo_id <= 0) {
                echo json_encode(['ok' => false, 'error' => 'Anexo inválido']);
                exit;
            }

            $stmt_anexo = $pdo->prepare("
                SELECT id, storage_key
                FROM eventos_reunioes_anexos
                WHERE id = :id
                  AND meeting_id = :meeting_id
                  AND section = 'dj_protocolo'
                  AND deleted_at IS NULL
                LIMIT 1
            ");
            $stmt_anexo->execute([
                ':id' => $anexo_id,
                ':meeting_id' => $meeting_id
            ]);
            $anexo = $stmt_anexo->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$anexo) {
                echo json_encode(['ok' => false, 'error' => 'Anexo não encontrado']);
                exit;
            }

            $delete_warning = '';
            $storage_key = trim((string)($anexo['storage_key'] ?? ''));
            if ($storage_key !== '') {
                try {
                    $uploader = new MagaluUpload();
                    $deleted = $uploader->delete($storage_key);
                    if (!$deleted) {
                        $delete_warning = 'Arquivo removido da lista, mas houve falha ao excluir no storage.';
                    }
                } catch (Throwable $e) {
                    $delete_warning = 'Arquivo removido da lista, mas houve falha ao excluir no storage.';
                }
            }

            $has_deleted_by_col = eventos_reuniao_has_column($pdo, 'eventos_reunioes_anexos', 'deleted_by');
            if ($has_deleted_by_col) {
                $stmt_delete = $pdo->prepare("
                    UPDATE eventos_reunioes_anexos
                    SET deleted_at = NOW(), deleted_by = :user_id
                    WHERE id = :id
                ");
                $stmt_delete->execute([
                    ':id' => $anexo_id,
                    ':user_id' => (int)$user_id
                ]);
            } else {
                $stmt_delete = $pdo->prepare("
                    UPDATE eventos_reunioes_anexos
                    SET deleted_at = NOW()
                    WHERE id = :id
                ");
                $stmt_delete->execute([':id' => $anexo_id]);
            }

            $anexos_dj_response = array_map(
                static function(array $item): array {
                    return eventos_reuniao_serializar_anexo($item);
                },
                eventos_reuniao_get_anexos($pdo, $meeting_id, 'dj_protocolo')
            );

            echo json_encode([
                'ok' => true,
                'warning' => $delete_warning,
                'anexos' => $anexos_dj_response
            ]);
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

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Ação inválida']);
            exit;
    }
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('eventos_reuniao_final POST [' . (string)$action . ']: ' . $e->getMessage());
        echo json_encode([
            'ok' => false,
            'error' => 'Falha interna ao processar a solicitação. Tente novamente.'
        ]);
        exit;
    }
}

// Carregar reunião existente
if ($meeting_id > 0) {
    $reuniao = eventos_reuniao_get($pdo, $meeting_id);
    if ($reuniao) {
        // Carregar seções
        foreach (['decoracao', 'observacoes_gerais', 'dj_protocolo', 'formulario'] as $sec) {
            $secoes[$sec] = eventos_reuniao_get_secao($pdo, $meeting_id, $sec);
        }
    }
}

eventos_form_template_seed_protocolo_15anos($pdo, $user_id);
$form_templates = eventos_form_templates_listar($pdo);
$links_cliente_dj = $meeting_id > 0 ? eventos_reuniao_listar_links_cliente($pdo, $meeting_id, 'cliente_dj') : [];
$links_cliente_observacoes = $meeting_id > 0 ? eventos_reuniao_listar_links_cliente($pdo, $meeting_id, 'cliente_observacoes') : [];
$links_cliente_formulario = $meeting_id > 0 ? eventos_reuniao_listar_links_cliente($pdo, $meeting_id, 'cliente_formulario') : [];
$anexos_dj = $meeting_id > 0 ? eventos_reuniao_get_anexos($pdo, $meeting_id, 'dj_protocolo') : [];
$links_cliente_dj_payload = [];
foreach ($links_cliente_dj as $link_dj) {
    if (!is_array($link_dj)) {
        continue;
    }
    try {
        $links_cliente_dj_payload[] = eventos_reuniao_serializar_link_formulario_payload($pdo, $meeting_id, $link_dj, 'dj_protocolo');
    } catch (Throwable $e) {
        error_log('eventos_reuniao_final serializar link dj: ' . $e->getMessage());
        $links_cliente_dj_payload[] = [
            'id' => (int)($link_dj['id'] ?? 0),
            'token' => (string)($link_dj['token'] ?? ''),
            'is_active' => !empty($link_dj['is_active']),
            'slot_index' => (int)($link_dj['slot_index'] ?? 1),
            'form_title' => (string)($link_dj['form_title'] ?? ''),
            'submitted_at' => array_key_exists('submitted_at', $link_dj) && $link_dj['submitted_at'] !== null ? (string)$link_dj['submitted_at'] : null,
            'draft_saved_at' => array_key_exists('draft_saved_at', $link_dj) && $link_dj['draft_saved_at'] !== null ? (string)$link_dj['draft_saved_at'] : null,
            'portal_visible' => !empty($link_dj['portal_visible']),
            'portal_editable' => !empty($link_dj['portal_editable']),
            'portal_configured' => !empty($link_dj['portal_configured']),
            'draft_preview_text' => '',
            'submitted_preview_text' => '',
            'draft_attachments' => [],
            'submitted_attachments' => [],
            'form_schema' => is_array($link_dj['form_schema'] ?? null) ? $link_dj['form_schema'] : [],
        ];
    }
}
$links_cliente_formulario_payload = [];
foreach ($links_cliente_formulario as $link_formulario) {
    if (!is_array($link_formulario)) {
        continue;
    }
    try {
        $links_cliente_formulario_payload[] = eventos_reuniao_serializar_link_formulario_payload($pdo, $meeting_id, $link_formulario);
    } catch (Throwable $e) {
        error_log('eventos_reuniao_final serializar link formulario: ' . $e->getMessage());
        $links_cliente_formulario_payload[] = [
            'id' => (int)($link_formulario['id'] ?? 0),
            'token' => (string)($link_formulario['token'] ?? ''),
            'is_active' => !empty($link_formulario['is_active']),
            'slot_index' => (int)($link_formulario['slot_index'] ?? 1),
            'form_title' => (string)($link_formulario['form_title'] ?? ''),
            'submitted_at' => array_key_exists('submitted_at', $link_formulario) && $link_formulario['submitted_at'] !== null ? (string)$link_formulario['submitted_at'] : null,
            'draft_saved_at' => array_key_exists('draft_saved_at', $link_formulario) && $link_formulario['draft_saved_at'] !== null ? (string)$link_formulario['draft_saved_at'] : null,
            'portal_visible' => !empty($link_formulario['portal_visible']),
            'portal_editable' => !empty($link_formulario['portal_editable']),
            'portal_configured' => !empty($link_formulario['portal_configured']),
            'draft_preview_text' => '',
            'submitted_preview_text' => '',
            'draft_attachments' => [],
            'submitted_attachments' => [],
            'form_schema' => is_array($link_formulario['form_schema'] ?? null) ? $link_formulario['form_schema'] : [],
        ];
    }
}
$active_tab_query = trim((string)($_GET['tab'] ?? ''));
$scope = strtolower(trim((string)($_GET['scope'] ?? '')));
$back_href = 'index.php?page=eventos';
if ($origin === 'organizacao') {
    $back_href = $meeting_id > 0
        ? 'index.php?page=eventos_organizacao&id=' . (int)$meeting_id
        : 'index.php?page=eventos_organizacao';
} elseif ($origin === 'realizar') {
    $back_href = $meeting_id > 0
        ? 'index.php?page=eventos_realizar&id=' . (int)$meeting_id
        : 'index.php?page=eventos_realizar';
}
$decoracao_schema_raw = $secoes['decoracao']['form_schema_json'] ?? '[]';
$decoracao_schema_decoded = json_decode((string)$decoracao_schema_raw, true);
$decoracao_schema_saved = is_array($decoracao_schema_decoded) ? $decoracao_schema_decoded : [];
$observacoes_schema_raw = $secoes['observacoes_gerais']['form_schema_json'] ?? '[]';
$observacoes_schema_decoded = json_decode((string)$observacoes_schema_raw, true);
$observacoes_schema_saved = is_array($observacoes_schema_decoded) ? $observacoes_schema_decoded : [];
$dj_schema_raw = $secoes['dj_protocolo']['form_schema_json'] ?? '[]';
$dj_schema_decoded = json_decode((string)$dj_schema_raw, true);
$dj_schema_saved = is_array($dj_schema_decoded) ? $dj_schema_decoded : [];
$formulario_schema_raw = $secoes['formulario']['form_schema_json'] ?? '[]';
$formulario_schema_decoded = json_decode((string)$formulario_schema_raw, true);
$formulario_schema_saved = is_array($formulario_schema_decoded) ? $formulario_schema_decoded : [];

// Seções disponíveis
$all_section_labels = [
    'decoracao' => ['icon' => '🎨', 'label' => 'Decoração'],
    'observacoes_gerais' => ['icon' => '📝', 'label' => 'Observações Gerais'],
    'dj_protocolo' => ['icon' => '🎧', 'label' => 'DJ / Protocolos'],
    'formulario' => ['icon' => '📋', 'label' => 'Formulário']
];
$section_labels = $all_section_labels;
if ($scope === 'dj') {
    $section_labels = [
        'dj_protocolo' => $all_section_labels['dj_protocolo'],
    ];
} elseif ($scope === 'formulario') {
    $section_labels = [
        'formulario' => $all_section_labels['formulario'],
    ];
}
$default_tab_key = (string)(array_key_first($section_labels) ?? 'decoracao');
if ($default_tab_key === '' || !isset($section_labels[$default_tab_key])) {
    $default_tab_key = 'decoracao';
}
if ($active_tab_query === '' || !isset($section_labels[$active_tab_query])) {
    $active_tab_query = $default_tab_key;
}

$sidebar_title = $meeting_id > 0 ? 'Reunião Final' : 'Nova Reunião Final';
if ($scope === 'dj') {
    $sidebar_title = 'DJ / Protocolos';
} elseif ($scope === 'formulario') {
    $sidebar_title = 'Formulário';
}
includeSidebar($sidebar_title);
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

    .dj-slots-actions {
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
        align-items: center;
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

    .dj-anexos-box {
        margin-top: 1rem;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #ffffff;
        padding: 0.9rem;
    }

    .dj-upload-cards {
        display: flex;
        flex-direction: column;
        gap: 0.7rem;
    }

    .dj-upload-card {
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 0.75rem;
        background: #f8fafc;
    }

    .dj-upload-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        margin-bottom: 0.55rem;
    }

    .dj-upload-card-title {
        margin: 0;
        font-size: 0.82rem;
        color: #334155;
        font-weight: 700;
    }

    .btn-upload-remove {
        color: #b91c1c;
        border-color: #fecdd3;
        background: #fff1f2;
    }

    .btn-upload-remove:hover {
        background: #ffe4e6;
    }

    .dj-anexos-upload {
        display: flex;
        gap: 0.65rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .dj-anexos-upload input[type="file"] {
        flex: 1;
        min-width: 260px;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.45rem 0.55rem;
        font-size: 0.82rem;
        background: #fff;
    }

    .dj-anexos-note {
        margin-top: 0.5rem;
    }

    .dj-anexos-note input {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.5rem 0.6rem;
        font-size: 0.82rem;
        background: #fff;
    }

    .dj-anexos-status {
        margin-top: 0.6rem;
        font-size: 0.8rem;
        color: #475569;
    }

    .dj-anexos-status.error {
        color: #b91c1c;
    }

    .dj-anexos-status.success {
        color: #047857;
    }

    .dj-anexos-list {
        margin-top: 0.75rem;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .dj-anexo-item {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 0.65rem 0.75rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
        background: #f8fafc;
    }

    .dj-anexo-info {
        display: flex;
        align-items: flex-start;
        gap: 0.55rem;
        min-width: 0;
        flex: 1;
    }

    .dj-anexo-icon {
        font-size: 1.05rem;
        line-height: 1;
        margin-top: 0.1rem;
    }

    .dj-anexo-name {
        font-size: 0.85rem;
        font-weight: 700;
        color: #1e293b;
        word-break: break-word;
    }

    .dj-anexo-meta {
        margin-top: 0.15rem;
        font-size: 0.75rem;
        color: #64748b;
    }

    .dj-anexo-note {
        margin-top: 0.2rem;
        font-size: 0.76rem;
        color: #475569;
    }

    .dj-anexo-actions {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .btn-upload-mini {
        min-width: 110px;
        justify-content: center;
    }

    .btn-anexo-delete {
        background: #fff1f2;
        border-color: #fecdd3;
        color: #b91c1c;
    }

    .btn-anexo-delete:hover {
        background: #ffe4e6;
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

    .slot-response-status {
        margin-top: 0.85rem;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #f8fafc;
        padding: 0.8rem 0.9rem;
    }

    .slot-response-status strong {
        color: #0f172a;
    }

    .slot-response-status p {
        margin: 0.2rem 0 0 0;
        color: #475569;
        font-size: 0.82rem;
    }

    .slot-response-actions {
        display: flex;
        gap: 0.55rem;
        flex-wrap: wrap;
        margin-top: 0.75rem;
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

    .observacoes-stack {
        display: flex;
        flex-direction: column;
        gap: 0.85rem;
    }

    .observacoes-panel {
        border: 1px solid #dbe3ef;
        border-radius: 12px;
        background: #ffffff;
        overflow: hidden;
    }

    .observacoes-toggle {
        width: 100%;
        border: 0;
        background: #f8fafc;
        color: #0f172a;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.95rem 1rem;
        cursor: pointer;
        text-align: left;
    }

    .observacoes-toggle:hover {
        background: #f1f5f9;
    }

    .observacoes-toggle-main {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        min-width: 0;
    }

    .observacoes-chevron {
        font-size: 0.95rem;
        color: #1d4ed8;
        width: 1rem;
        flex: 0 0 1rem;
    }

    .observacoes-toggle strong {
        display: block;
        font-size: 0.98rem;
    }

    .observacoes-toggle small {
        display: block;
        color: #64748b;
        font-size: 0.8rem;
        margin-top: 0.18rem;
    }

    .observacoes-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        padding: 0.2rem 0.55rem;
        font-size: 0.72rem;
        font-weight: 700;
        background: #e2e8f0;
        color: #334155;
        white-space: nowrap;
    }

    .observacoes-badge.internal {
        background: #fee2e2;
        color: #991b1b;
    }

    .observacoes-body {
        padding: 0 1rem 1rem 1rem;
        border-top: 1px solid #e2e8f0;
    }

    .observacoes-body.is-collapsed {
        display: none;
    }

    .cronograma-builder {
        display: grid;
        gap: 0.75rem;
        margin-top: 0.2rem;
    }

    .cronograma-help {
        margin: 0;
        color: #64748b;
        font-size: 0.8rem;
    }

    .cronograma-rows {
        display: grid;
        gap: 0.6rem;
    }

    .cronograma-row {
        display: grid;
        grid-template-columns: auto minmax(200px, 1fr) auto;
        gap: 0.55rem;
        align-items: center;
        padding: 0.55rem;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        background: #fff;
    }

    .cronograma-time-fields {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .cronograma-time-separator {
        color: #334155;
        font-size: 0.88rem;
        font-weight: 700;
    }

    .cronograma-time-input {
        width: 66px;
        padding: 0.45rem 0.5rem;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        font-size: 0.84rem;
        font-weight: 600;
        text-align: center;
        background: #fff;
    }

    .cronograma-text-input {
        width: 100%;
        min-width: 0;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        padding: 0.5rem 0.65rem;
        font-size: 0.85rem;
        color: #0f172a;
        background: #fff;
    }

    .cronograma-row-empty {
        opacity: 0.82;
        border-style: dashed;
    }

    .cronograma-row-actions {
        display: inline-flex;
        gap: 0.35rem;
    }

    .cronograma-empty {
        margin: 0;
        padding: 0.75rem;
        border: 1px dashed #cbd5e1;
        border-radius: 10px;
        color: #64748b;
        font-size: 0.82rem;
        background: #f8fafc;
    }

    .observacoes-hidden-storage {
        display: none;
    }

    .legacy-portal-option {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .legacy-portal-option label {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        color: #334155;
        font-size: 0.82rem;
        font-weight: 600;
        cursor: pointer;
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

    .portal-settings {
        margin-top: 0.65rem;
        border: 1px solid #dbe3ef;
        border-radius: 10px;
        padding: 0.75rem;
        background: #fff;
        display: grid;
        gap: 0.55rem;
    }

    .portal-settings-label {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 0.84rem;
        color: #334155;
    }

    .portal-settings-label input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: #1e3a8a;
    }

    .portal-settings-action {
        display: flex;
        justify-content: flex-end;
    }

    .portal-settings-action .btn {
        min-width: 220px;
        justify-content: center;
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

        .portal-settings-action .btn {
            width: 100%;
            min-width: 0;
        }

        .cronograma-row {
            grid-template-columns: 1fr;
        }

        .cronograma-time-fields {
            width: fit-content;
        }

        .cronograma-row-actions {
            width: 100%;
        }

        .cronograma-row-actions .btn {
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
        <a href="<?= htmlspecialchars($back_href) ?>" class="btn btn-secondary">← Voltar</a>
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
            <?php if (!$readonly_mode): ?>
            <button type="button" class="btn btn-success" onclick="criarReuniao()">
                <?= $origin === 'organizacao' ? 'Organizar este Evento' : '✓ Criar Reunião para este Evento' ?>
            </button>
            <?php else: ?>
            <div class="locked-notice" style="margin:0;">
                <span style="font-size: 1.2rem;">🔒</span>
                <div style="font-size:0.85rem;">Modo realização em somente leitura: criação/edição bloqueada.</div>
            </div>
            <?php endif; ?>
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
                <?php if ($readonly_mode): ?>
                <span style="display:inline-block; margin-left:0.55rem; background:#dbeafe; color:#1e40af; font-weight:700; font-size:0.76rem; padding:0.2rem 0.5rem; border-radius:999px; border:1px solid #93c5fd;">Somente leitura</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="header-actions">
            <?php if (!$readonly_mode): ?>
            <?php if ($reuniao['status'] === 'rascunho'): ?>
            <button type="button" class="btn btn-success" onclick="concluirReuniao()">✓ Marcar como Concluída</button>
            <?php else: ?>
            <button type="button" class="btn btn-secondary" onclick="reabrirReuniao()">↺ Reabrir</button>
            <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary btn-mini" onclick="abrirModalImpressao()" title="Imprimir / PDF" aria-label="Imprimir / PDF">🖨️</button>
            <a href="<?= htmlspecialchars($back_href) ?>" class="btn btn-secondary">← Voltar</a>
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
            <button type="button" class="tab-btn <?= $key === $default_tab_key ? 'active' : '' ?>" data-tab-section="<?= htmlspecialchars((string)$key) ?>">
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
            $legacy_text_portal_visible = true;
            if (is_array($secao) && array_key_exists('legacy_text_portal_visible', $secao)) {
                $legacy_text_portal_visible = !empty($secao['legacy_text_portal_visible']);
            }
            if ($key === 'dj_protocolo') {
                $is_locked = false;
            }
        ?>
        <div class="tab-content <?= $key === $default_tab_key ? 'active' : '' ?>" id="tab-<?= $key ?>">
            
            <?php if ($key === 'dj_protocolo'): ?>
            <div class="dj-builder-shell">
                <div class="dj-slots-controls">
                    <div>
                        <h4>🎧 DJ / Protocolos</h4>
                        <p>Crie formulários exclusivos do DJ, configure visibilidade no portal do cliente e acompanhe o envio por quadro.</p>
                    </div>
                    <div class="dj-slots-actions">
                        <?php if (!$readonly_mode): ?>
                        <button type="button" class="btn btn-primary" id="btnAddDjSlot">+ Adicionar formulário</button>
                        <button type="button" class="btn btn-secondary" onclick="addDjUploadCard()">+ Adicionar arquivo</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="djSlotsEmptyState" class="dj-builder-empty-state" style="display:none;">Nenhum formulário DJ criado. Clique em "Adicionar formulário" para começar.</div>
                <div id="djSlotsContainer" class="dj-slots-stack"></div>
                <div class="builder-field-meta" style="margin-top: 0.75rem;">
                    Arquivos abaixo são de apoio interno/cliente da seção DJ e não substituem os uploads dos campos do formulário.
                </div>

                <div class="dj-anexos-box" id="djAnexosBox" style="display:none;">
                    <div id="djUploadCardsContainer" class="dj-upload-cards"></div>
                    <div class="dj-anexos-status" id="djAnexosStatus" style="display:none;"></div>
                    <div class="dj-anexos-list" id="djAnexosList"></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($key === 'formulario'): ?>
            <div class="dj-builder-shell">
                <div class="dj-slots-controls">
                    <div>
                        <h4>📋 Formulários</h4>
                        <p>Selecione um ou mais formulários da biblioteca e defina quais o cliente poderá visualizar e preencher no portal.</p>
                    </div>
                    <div class="dj-slots-actions">
                        <?php if (!$readonly_mode): ?>
                        <button type="button" class="btn btn-primary" id="btnAddFormularioSlot">+ Adicionar formulário</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div id="formularioSlotsEmptyState" class="dj-builder-empty-state" style="display:none;">Nenhum formulário criado. Clique em "Adicionar formulário" para começar.</div>
                <div id="formularioSlotsContainer" class="dj-slots-stack"></div>
            </div>
            <?php endif; ?>

            <?php if ($key === 'decoracao'): ?>
            <div class="dj-builder-shell">
                <div class="dj-builder-head">
                    <div>
                        <h4 class="dj-builder-title">🧩 Formulário interno</h4>
                        <div class="dj-builder-subtitle">Selecione um formulário e preencha os campos diretamente nesta aba.</div>
                    </div>
                    <div class="dj-top-actions">
                        <?php if (!$readonly_mode): ?>
                        <button type="button" class="btn btn-secondary" onclick="aplicarTemplateNaSecao('<?= $key ?>')" <?= $is_locked ? 'disabled' : '' ?>>Carregar formulário</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="prefill-field" style="margin-top: 0.5rem;">
                    <label for="sectionTemplateSelect-<?= $key ?>">Formulário salvo (opcional)</label>
                    <select id="sectionTemplateSelect-<?= $key ?>" onchange="onChangeSectionTemplateSelect('<?= $key ?>')" <?= ($is_locked || $readonly_mode) ? 'disabled' : '' ?>>
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
                <?php if (!$readonly_mode): ?>
                <button type="button" class="btn btn-secondary" onclick="destravarSecao('<?= $key ?>')" style="margin-left: auto;">
                    🔓 Destravar
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($key === 'decoracao' || $key === 'dj_protocolo'): ?>
            <div class="legacy-editor-toggle">
                <div>
                    <strong>Texto livre (opcional)</strong>
                    <div class="builder-field-meta">Mantido para observações extras. Clique para abrir/fechar.</div>
                </div>
                <div class="legacy-portal-option">
                    <label for="legacyPortalVisible-<?= $key ?>">
                        <input
                            type="checkbox"
                            id="legacyPortalVisible-<?= $key ?>"
                            <?= $legacy_text_portal_visible ? 'checked' : '' ?>
                            <?= ($is_locked || $readonly_mode) ? 'disabled' : '' ?>
                        >
                        Mostrar no portal do cliente
                    </label>
                </div>
                <button type="button" class="btn btn-secondary" id="btnToggleEditor-<?= $key ?>" onclick="toggleLegacyEditor('<?= $key ?>')">Abrir texto</button>
            </div>
            <?php elseif ($key === 'observacoes_gerais'): ?>
            <div class="observacoes-stack" id="observacoesStack">
                <?php foreach ($observacoes_editor_blocks as $obs_block): ?>
                <?php $is_cronograma_block = (($obs_block['key'] ?? '') === 'cronograma'); ?>
                <div class="observacoes-panel" data-observacoes-panel="<?= htmlspecialchars($obs_block['key']) ?>">
                    <button type="button" class="observacoes-toggle" onclick="toggleObservacoesBlock('<?= htmlspecialchars($obs_block['key']) ?>')">
                        <span class="observacoes-toggle-main">
                            <span class="observacoes-chevron" id="obsChevron-<?= htmlspecialchars($obs_block['key']) ?>"><?= !empty($obs_block['open']) ? '▾' : '▸' ?></span>
                            <span>
                                <strong><?= htmlspecialchars($obs_block['label']) ?></strong>
                                <small><?= htmlspecialchars($obs_block['description']) ?></small>
                            </span>
                        </span>
                        <?php if (empty($obs_block['public'])): ?>
                        <span class="observacoes-badge internal">Interno</span>
                        <?php else: ?>
                        <span class="observacoes-badge">Portal</span>
                        <?php endif; ?>
                    </button>
                    <div class="observacoes-body<?= empty($obs_block['open']) ? ' is-collapsed' : '' ?>" id="obsBody-<?= htmlspecialchars($obs_block['key']) ?>">
                        <?php if ($is_cronograma_block): ?>
                        <div
                            class="cronograma-builder"
                            id="cronogramaBuilder"
                            data-cronograma-readonly="<?= ($is_locked || $readonly_mode) ? '1' : '0' ?>"
                        >
                            <p class="cronograma-help">Preencha hora e minuto + descrição. O cronograma se reorganiza automaticamente conforme os horários.</p>
                            <div class="cronograma-rows" id="cronogramaRows"></div>
                            <?php if (!$is_locked && !$readonly_mode): ?>
                            <div class="cronograma-row-actions">
                                <button type="button" class="btn btn-secondary" onclick="adicionarLinhaCronograma()">+ Adicionar horário</button>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <textarea id="editor-observacoes-bloco-<?= htmlspecialchars($obs_block['key']) ?>"
                                  data-observacoes-block="<?= htmlspecialchars($obs_block['key']) ?>"
                                  <?= ($is_locked || $readonly_mode) ? 'readonly' : '' ?>
                                  style="width:100%; min-height: 300px; border: 0;"></textarea>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php
            $editor_wrap_attrs = '';
            if ($key === 'dj_protocolo' || $key === 'decoracao') {
                $editor_wrap_attrs = ' style="display:none;"';
            } elseif ($key === 'observacoes_gerais') {
                $editor_wrap_attrs = ' class="editor-wrapper legacy-editor-wrap observacoes-hidden-storage"';
            }
            ?>
            <?php if ($key !== 'formulario'): ?>
            <div<?= $key === 'observacoes_gerais' ? ' class="observacoes-hidden-storage"' : ' class="editor-wrapper legacy-editor-wrap"' ?> id="legacyEditorWrap-<?= $key ?>"<?= $key !== 'observacoes_gerais' ? $editor_wrap_attrs : '' ?>>
                <?php 
                $safe_content = str_replace('</textarea>', '&lt;/textarea&gt;', $content);
                ?>
                <textarea id="editor-<?= $key ?>" 
                          data-section="<?= $key ?>"
                          <?= ($is_locked || $readonly_mode) ? 'readonly' : '' ?>
                          style="width:100%; min-height: 400px; border: 0;"><?= $safe_content ?></textarea>
            </div>
            <?php endif; ?>
            
            <?php if ($key !== 'formulario'): ?>
            <div class="section-actions">
                <?php if (!$is_locked && !$readonly_mode): ?>
                <button type="button" class="btn btn-primary" onclick="salvarSecao('<?= $key ?>')">💾 Salvar</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" onclick="verVersoes('<?= $key ?>')">📋 Histórico de Versões</button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- TinyMCE carregado via script dinâmico (evita ficar travado em "Carregando editor...") -->
<script>
(function() {
    function escapeSelectorValue(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value || '').replace(/["\\]/g, '\\$&');
    }

    function switchTab(section) {
        const sectionKey = String(section || '').trim();
        if (!sectionKey) return;

        const buttons = document.querySelectorAll('.tab-btn[data-tab-section]');
        const tabs = document.querySelectorAll('.tab-content');
        if (!buttons.length || !tabs.length) return;

        buttons.forEach((btn) => btn.classList.remove('active'));
        tabs.forEach((tab) => tab.classList.remove('active'));

        const selectorValue = escapeSelectorValue(sectionKey);
        const btn = document.querySelector(`.tab-btn[data-tab-section="${selectorValue}"]`);
        const tab = document.getElementById(`tab-${sectionKey}`);
        if (!btn || !tab) return;

        btn.classList.add('active');
        tab.classList.add('active');
    }

    function bindTabButtons() {
        document.querySelectorAll('.tab-btn[data-tab-section]').forEach((btn) => {
            if (btn.dataset.tabBound === '1') return;
            btn.dataset.tabBound = '1';
            btn.addEventListener('click', function() {
                switchTab(this.dataset.tabSection || '');
            });
        });
    }

    window.switchTab = switchTab;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindTabButtons);
    } else {
        bindTabButtons();
    }
})();
</script>

<!-- Modal de Versões -->
<div class="modal-overlay" id="modalVersoes">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalVersoesTitle">📋 Histórico de Versões</h3>
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
                        <?php foreach ($section_labels as $section_key => $section_info): ?>
                        <?php if ($section_key === 'formulario') { continue; } ?>
                        <option value="<?= htmlspecialchars($section_key) ?>"><?= htmlspecialchars((string)($section_info['label'] ?? $section_key)) ?></option>
                        <?php endforeach; ?>
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
const initialTab = <?= eventos_reuniao_json_script(in_array($active_tab_query, array_keys($section_labels), true) ? $active_tab_query : '', '""') ?>;
const observacoesEditorBlocks = <?= eventos_reuniao_json_script($observacoes_editor_blocks, '[]') ?>;
const initialDecoracaoSchema = <?= eventos_reuniao_json_script($decoracao_schema_saved, '[]') ?>;
const initialObservacoesSchema = <?= eventos_reuniao_json_script($observacoes_schema_saved, '[]') ?>;
const initialDjSchema = <?= eventos_reuniao_json_script($dj_schema_saved, '[]') ?>;
const initialFormularioSchema = <?= eventos_reuniao_json_script($formulario_schema_saved, '[]') ?>;
const initialDjLinks = <?= eventos_reuniao_json_script($links_cliente_dj_payload, '[]') ?>;
const initialObservacoesClientLinks = <?= eventos_reuniao_json_script(array_map(static function (array $link): array {
    return [
        'id' => (int)($link['id'] ?? 0),
        'token' => (string)($link['token'] ?? ''),
        'slot_index' => (int)($link['slot_index'] ?? 1),
        'form_title' => (string)($link['form_title'] ?? ''),
        'submitted_at' => array_key_exists('submitted_at', $link) && $link['submitted_at'] !== null ? (string)$link['submitted_at'] : null,
        'form_schema' => is_array($link['form_schema'] ?? null) ? $link['form_schema'] : [],
    ];
}, $links_cliente_observacoes), '[]') ?>;
const clientPortalBaseUrl = <?= eventos_reuniao_json_script(rtrim(eventos_cliente_portal_base_url(), '/'), '""') ?>;
const initialFormularioLinks = <?= eventos_reuniao_json_script($links_cliente_formulario_payload, '[]') ?>;
const initialDjAnexos = <?= eventos_reuniao_json_script(array_map(static function(array $anexo): array {
    return eventos_reuniao_serializar_anexo($anexo);
}, $anexos_dj), '[]') ?>;
let selectedEventId = null;
let selectedEventData = null;
let searchDebounceTimer = null;
let searchAbortController = null;
let eventsCacheLoaded = false;
let eventsMasterCache = [];
const eventsQueryCache = new Map();
let savedFormTemplates = <?= eventos_reuniao_json_script(array_map(static function(array $template): array {
    return [
        'id' => (int)($template['id'] ?? 0),
        'nome' => (string)($template['nome'] ?? ''),
        'categoria' => (string)($template['categoria'] ?? 'geral'),
        'updated_at' => (string)($template['updated_at'] ?? ''),
        'created_by_user_id' => (int)($template['created_by_user_id'] ?? 0),
        'schema' => is_array($template['schema'] ?? null) ? $template['schema'] : [],
    ];
}, $form_templates), '[]') ?>;
const DJ_SLOT_MIN = 1;
const DJ_SLOT_MAX = 50;
let djSlotOrder = [];
let selectedDjTemplateIds = {};
let lastSavedDjSchemaSignatures = {};
let djLinksBySlot = {};
let djPortalSaveInFlight = {};
let djPortalSavePending = {};
let djAnexos = Array.isArray(initialDjAnexos) ? initialDjAnexos.slice() : [];
let djUploadCardOrder = [];
let djUploadCardCounter = 0;
let observacoesSlotOrder = [];
let selectedObservacoesTemplateIds = {};
let observacoesLinksBySlot = {};
let formularioSlotOrder = [];
let selectedFormularioTemplateIds = {};
let formularioLinksBySlot = {};
let formularioPortalSaveInFlight = {};
let formularioPortalSavePending = {};
let selectedSectionTemplateIds = {
    decoracao: null,
    observacoes_gerais: null,
};
let lastSavedSectionSchemaSignatures = {
    decoracao: '',
    observacoes_gerais: '',
};
const sectionLockedState = <?= eventos_reuniao_json_script([
    'decoracao' => !empty($secoes['decoracao']['is_locked']),
    'observacoes_gerais' => !empty($secoes['observacoes_gerais']['is_locked']),
    'dj_protocolo' => !empty($secoes['dj_protocolo']['is_locked']),
    'formulario' => !empty($secoes['formulario']['is_locked']),
], '{"decoracao":false,"observacoes_gerais":false,"dj_protocolo":false,"formulario":false}') ?>;
const pageReadonly = <?= $readonly_mode ? 'true' : 'false' ?>;
let sectionFormDraftValues = {
    decoracao: {},
    observacoes_gerais: {},
};
const CRONOGRAMA_BLOCK_KEY = 'cronograma';
const CRONOGRAMA_ROW_EMPTY_TEMPLATE = {
    id: 0,
    hour: '',
    minute: '',
    text: '',
};
let cronogramaRows = [];
let cronogramaRowCounter = 0;

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
    function initTinyEditorById(editorId, height) {
        var textarea = document.getElementById(editorId);
        if (!textarea) return;
        if (tinymce.get(editorId)) return;
        var isReadonly = textarea.readOnly;
        tinymce.init({
            selector: '#' + editorId,
            base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
            suffix: '.min',
            plugins: 'lists link image table code',
            toolbar: 'undo redo | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright justify | bullist numlist outdent indent | link image table | removeformat',
            menubar: false,
            height: height,
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
    }

    ['decoracao', 'dj_protocolo'].forEach(function(section) {
        initTinyEditorById('editor-' + section, 420);
    });
    (observacoesEditorBlocks || []).forEach(function(block) {
        var key = String(block && block.key ? block.key : '').trim();
        if (!key) return;
        initTinyEditorById(getObservacoesBlockEditorId(key), 320);
    });
}

function getObservacoesBlockConfig(blockKey) {
    return (observacoesEditorBlocks || []).find(function(block) {
        return String(block && block.key ? block.key : '') === String(blockKey || '');
    }) || null;
}

function isCronogramaBlock(blockKey) {
    return String(blockKey || '').trim() === CRONOGRAMA_BLOCK_KEY;
}

function getObservacoesBlockEditorId(blockKey) {
    return 'editor-observacoes-bloco-' + String(blockKey || '').replace(/[^a-zA-Z0-9_-]/g, '_');
}

function getCronogramaBuilderState() {
    const builder = document.getElementById('cronogramaBuilder');
    const readonlyByDom = builder && String(builder.getAttribute('data-cronograma-readonly') || '') === '1';
    return {
        builder,
        readonly: !!(readonlyByDom || pageReadonly || sectionLockedState.observacoes_gerais)
    };
}

function nextCronogramaRowId() {
    cronogramaRowCounter += 1;
    return cronogramaRowCounter;
}

function ensureCronogramaRowCounter(rowId) {
    const parsed = Number(rowId || 0);
    if (Number.isInteger(parsed) && parsed > cronogramaRowCounter) {
        cronogramaRowCounter = parsed;
    }
}

function createCronogramaRow(row = {}) {
    const parsedId = Number(row && row.id ? row.id : 0);
    const id = Number.isInteger(parsedId) && parsedId > 0 ? parsedId : nextCronogramaRowId();
    ensureCronogramaRowCounter(id);
    return {
        id,
        hour: String(row && row.hour ? row.hour : ''),
        minute: String(row && row.minute ? row.minute : ''),
        text: String(row && row.text ? row.text : '')
    };
}

function sanitizeCronogramaTimePart(value, max) {
    const digits = String(value == null ? '' : value).replace(/[^\d]/g, '').slice(0, 2);
    if (digits === '') {
        return '';
    }
    const parsed = Number(digits);
    if (!Number.isInteger(parsed)) {
        return '';
    }
    return String(Math.max(0, Math.min(max, parsed)));
}

function normalizeCronogramaTimePart(value, max) {
    const sanitized = sanitizeCronogramaTimePart(value, max);
    if (sanitized === '') {
        return '';
    }
    return String(Number(sanitized)).padStart(2, '0');
}

function normalizeCronogramaRow(row) {
    const normalized = createCronogramaRow({
        id: Number(row && row.id ? row.id : 0),
        hour: normalizeCronogramaTimePart(row && row.hour ? row.hour : '', 23),
        minute: normalizeCronogramaTimePart(row && row.minute ? row.minute : '', 59),
        text: String(row && row.text ? row.text : '').trim()
    });
    return normalized;
}

function cronogramaRowHasContent(row) {
    if (!row || typeof row !== 'object') return false;
    return String(row.hour || '').trim() !== ''
        || String(row.minute || '').trim() !== ''
        || String(row.text || '').trim() !== '';
}

function cronogramaRowHasTime(row) {
    const hour = normalizeCronogramaTimePart(row && row.hour ? row.hour : '', 23);
    const minute = normalizeCronogramaTimePart(row && row.minute ? row.minute : '', 59);
    return hour !== '' && minute !== '';
}

function getCronogramaRowMinutes(row) {
    if (!cronogramaRowHasTime(row)) {
        return null;
    }
    const hour = Number(normalizeCronogramaTimePart(row && row.hour ? row.hour : '', 23));
    const minute = Number(normalizeCronogramaTimePart(row && row.minute ? row.minute : '', 59));
    if (!Number.isInteger(hour) || !Number.isInteger(minute)) {
        return null;
    }
    return (hour * 60) + minute;
}

function sortCronogramaRows(rows) {
    const withTime = [];
    const withoutTime = [];

    (Array.isArray(rows) ? rows : []).forEach((row, idx) => {
        const normalized = normalizeCronogramaRow(row);
        if (!cronogramaRowHasContent(normalized)) return;
        const minutes = getCronogramaRowMinutes(normalized);
        if (minutes === null) {
            withoutTime.push({ row: normalized, idx });
            return;
        }
        withTime.push({ row: normalized, idx, minutes });
    });

    const hasLateNight = withTime.some((item) => item.minutes >= (18 * 60));
    const hasEarlyMorning = withTime.some((item) => item.minutes <= (6 * 60));
    const useOvernightOrdering = hasLateNight && hasEarlyMorning;

    withTime.sort((a, b) => {
        const aOrder = useOvernightOrdering && a.minutes <= (6 * 60) ? a.minutes + 1440 : a.minutes;
        const bOrder = useOvernightOrdering && b.minutes <= (6 * 60) ? b.minutes + 1440 : b.minutes;
        if (aOrder !== bOrder) {
            return aOrder - bOrder;
        }
        return a.idx - b.idx;
    });

    withoutTime.sort((a, b) => a.idx - b.idx);
    return withTime.map((item) => item.row).concat(withoutTime.map((item) => item.row));
}

function getCronogramaContentRows() {
    const rows = sortCronogramaRows(Array.isArray(cronogramaRows) ? cronogramaRows : []);
    return rows.filter((row) => cronogramaRowHasContent(row));
}

function buildCronogramaHtmlFromRows() {
    const rows = getCronogramaContentRows();
    if (!rows.length) {
        return '';
    }

    const items = rows.map((row) => {
        const hour = normalizeCronogramaTimePart(row.hour, 23);
        const minute = normalizeCronogramaTimePart(row.minute, 59);
        const hasTime = hour !== '' && minute !== '';
        const timeLabel = hasTime ? `${hour}:${minute}` : '--:--';
        const text = String(row.text || '').trim();
        const textLabel = text !== '' ? text : 'Sem descrição';
        return `<li data-smile-cronograma-item="1" data-hour="${escapeHtmlForField(hour)}" data-minute="${escapeHtmlForField(minute)}" data-text="${escapeHtmlForField(text)}">`
            + `<strong data-smile-cronograma-time="1">${escapeHtmlForField(timeLabel)}</strong> `
            + `<span data-smile-cronograma-text="1">${escapeHtmlForField(textLabel)}</span>`
            + `</li>`;
    });

    return `<div data-smile-cronograma="1"><ul>${items.join('')}</ul></div>`;
}

function parseCronogramaRowFromTextLine(line) {
    const raw = String(line || '').trim();
    if (raw === '') {
        return null;
    }

    let match = raw.match(/^(\d{1,2})\s*[:hH]\s*(\d{1,2})\s*(?:[-–—:]?\s*)?(.*)$/i);
    if (match) {
        return createCronogramaRow({
            hour: normalizeCronogramaTimePart(match[1], 23),
            minute: normalizeCronogramaTimePart(match[2], 59),
            text: String(match[3] || '').trim()
        });
    }

    match = raw.match(/^(\d{1,2})\s*(?:h|hr|hrs|hora|horas)\b\.?\s*(?:[-–—:]?\s*)?(.*)$/i);
    if (match) {
        return createCronogramaRow({
            hour: normalizeCronogramaTimePart(match[1], 23),
            minute: '00',
            text: String(match[2] || '').trim()
        });
    }

    return createCronogramaRow({
        hour: '',
        minute: '',
        text: raw
    });
}

function parseCronogramaRowsFromHtml(contentHtml) {
    const rows = [];
    const wrapper = document.createElement('div');
    wrapper.innerHTML = String(contentHtml || '');

    const structuredItems = Array.from(wrapper.querySelectorAll('[data-smile-cronograma-item]'));
    if (structuredItems.length > 0) {
        structuredItems.forEach((item) => {
            const hourAttr = normalizeCronogramaTimePart(item.getAttribute('data-hour') || '', 23);
            const minuteAttr = normalizeCronogramaTimePart(item.getAttribute('data-minute') || '', 59);
            let text = String(item.getAttribute('data-text') || '').trim();
            if (text === '') {
                const textNode = item.querySelector('[data-smile-cronograma-text]');
                if (textNode) {
                    text = String(textNode.textContent || '').trim();
                } else {
                    text = String(item.textContent || '').trim();
                }
            }

            rows.push(createCronogramaRow({
                hour: hourAttr,
                minute: minuteAttr,
                text
            }));
        });
        return rows;
    }

    const text = String(wrapper.innerText || wrapper.textContent || '').replace(/\u00a0/g, ' ');
    if (text.trim() === '') {
        return [];
    }
    text.split(/\r?\n/).forEach((line) => {
        const parsed = parseCronogramaRowFromTextLine(line);
        if (parsed && cronogramaRowHasContent(parsed)) {
            rows.push(parsed);
        }
    });
    return rows;
}

function findCronogramaRowById(rowId) {
    const id = Number(rowId || 0);
    if (!Number.isInteger(id) || id <= 0) return null;
    return (Array.isArray(cronogramaRows) ? cronogramaRows : []).find((row) => Number(row && row.id ? row.id : 0) === id) || null;
}

function normalizeAndRenderCronogramaRows() {
    const state = getCronogramaBuilderState();
    const normalizedRows = getCronogramaContentRows();
    cronogramaRows = normalizedRows;

    if (!state.readonly) {
        cronogramaRows.push(createCronogramaRow(CRONOGRAMA_ROW_EMPTY_TEMPLATE));
    }

    renderCronogramaRows();
}

function renderCronogramaRows() {
    const rowsWrap = document.getElementById('cronogramaRows');
    if (!rowsWrap) return;
    const state = getCronogramaBuilderState();

    if (!Array.isArray(cronogramaRows)) {
        cronogramaRows = [];
    }
    if (!state.readonly && cronogramaRows.length === 0) {
        cronogramaRows.push(createCronogramaRow(CRONOGRAMA_ROW_EMPTY_TEMPLATE));
    }
    if (state.readonly && cronogramaRows.length === 0) {
        rowsWrap.innerHTML = '<p class="cronograma-empty">Nenhum horário informado.</p>';
        bindCronogramaRowsEvents();
        return;
    }

    rowsWrap.innerHTML = cronogramaRows.map((row) => {
        const rowId = Number(row && row.id ? row.id : 0);
        const hour = String(row && row.hour ? row.hour : '');
        const minute = String(row && row.minute ? row.minute : '');
        const text = String(row && row.text ? row.text : '');
        const isEmpty = !cronogramaRowHasContent(row);
        const disabledAttr = state.readonly ? ' disabled' : '';
        const removeHtml = state.readonly
            ? ''
            : `<button type="button" class="btn btn-secondary btn-mini" data-cronograma-action="remove" data-row-id="${rowId}">Remover</button>`;
        return `<div class="cronograma-row${isEmpty ? ' cronograma-row-empty' : ''}" data-cronograma-row="${rowId}">`
            + `<div class="cronograma-time-fields">`
            + `<input type="number" min="0" max="23" step="1" class="cronograma-time-input" placeholder="HH" data-cronograma-field="hour" data-row-id="${rowId}" value="${escapeHtmlForField(hour)}"${disabledAttr}>`
            + `<span class="cronograma-time-separator">:</span>`
            + `<input type="number" min="0" max="59" step="1" class="cronograma-time-input" placeholder="MM" data-cronograma-field="minute" data-row-id="${rowId}" value="${escapeHtmlForField(minute)}"${disabledAttr}>`
            + `</div>`
            + `<input type="text" class="cronograma-text-input" placeholder="Descreva o que acontece neste horário" data-cronograma-field="text" data-row-id="${rowId}" value="${escapeHtmlForField(text)}"${disabledAttr}>`
            + `<div class="cronograma-row-actions">${removeHtml}</div>`
            + `</div>`;
    }).join('');

    bindCronogramaRowsEvents();
}

function bindCronogramaRowsEvents() {
    const rowsWrap = document.getElementById('cronogramaRows');
    if (!rowsWrap || rowsWrap.dataset.bound === '1') return;
    rowsWrap.dataset.bound = '1';

    rowsWrap.addEventListener('input', function(event) {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;
        const field = String(target.getAttribute('data-cronograma-field') || '').trim();
        const rowId = Number(target.getAttribute('data-row-id') || 0);
        const row = findCronogramaRowById(rowId);
        if (!row) return;

        if (field === 'hour') {
            row.hour = sanitizeCronogramaTimePart(target.value, 23);
            target.value = row.hour;
            return;
        }
        if (field === 'minute') {
            row.minute = sanitizeCronogramaTimePart(target.value, 59);
            target.value = row.minute;
            return;
        }
        if (field === 'text') {
            row.text = String(target.value || '');
        }
    });

    rowsWrap.addEventListener('change', function(event) {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;
        const field = String(target.getAttribute('data-cronograma-field') || '').trim();
        const rowId = Number(target.getAttribute('data-row-id') || 0);
        const row = findCronogramaRowById(rowId);
        if (!row) return;

        if (field === 'hour') {
            row.hour = normalizeCronogramaTimePart(target.value, 23);
        } else if (field === 'minute') {
            row.minute = normalizeCronogramaTimePart(target.value, 59);
        } else if (field === 'text') {
            row.text = String(target.value || '').trim();
        }

        normalizeAndRenderCronogramaRows();
    });

    rowsWrap.addEventListener('click', function(event) {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const actionEl = target.closest('[data-cronograma-action]');
        if (!actionEl) return;
        const action = String(actionEl.getAttribute('data-cronograma-action') || '').trim();
        const rowId = Number(actionEl.getAttribute('data-row-id') || 0);
        if (action !== 'remove' || !Number.isInteger(rowId) || rowId <= 0) return;

        cronogramaRows = (Array.isArray(cronogramaRows) ? cronogramaRows : []).filter((row) => Number(row && row.id ? row.id : 0) !== rowId);
        normalizeAndRenderCronogramaRows();
    });
}

function hydrateCronogramaFromHtml(html) {
    cronogramaRows = parseCronogramaRowsFromHtml(html);
    normalizeAndRenderCronogramaRows();
}

function adicionarLinhaCronograma() {
    const state = getCronogramaBuilderState();
    if (state.readonly) return;

    if (!Array.isArray(cronogramaRows)) {
        cronogramaRows = [];
    }

    const lastRow = cronogramaRows.length > 0 ? cronogramaRows[cronogramaRows.length - 1] : null;
    if (lastRow && !cronogramaRowHasContent(lastRow)) {
        const lastInput = document.querySelector(`#cronogramaRows [data-row-id="${Number(lastRow.id || 0)}"][data-cronograma-field="hour"]`);
        if (lastInput) {
            lastInput.focus();
        }
        return;
    }

    cronogramaRows.push(createCronogramaRow(CRONOGRAMA_ROW_EMPTY_TEMPLATE));
    renderCronogramaRows();

    const newRow = cronogramaRows[cronogramaRows.length - 1];
    if (newRow) {
        const hourInput = document.querySelector(`#cronogramaRows [data-row-id="${Number(newRow.id || 0)}"][data-cronograma-field="hour"]`);
        if (hourInput) {
            hourInput.focus();
        }
    }
}

function parseObservacoesBlocksFromContent(contentHtml) {
    const parsed = {};
    (observacoesEditorBlocks || []).forEach((block) => {
        const key = String(block && block.key ? block.key : '').trim();
        if (key !== '') {
            parsed[key] = '';
        }
    });

    const rawHtml = String(contentHtml || '').trim();
    if (rawHtml === '') {
        return parsed;
    }

    const wrapper = document.createElement('div');
    wrapper.innerHTML = rawHtml;
    const sections = Array.from(wrapper.querySelectorAll('[data-smile-observacoes-block]'));
    if (!sections.length) {
        parsed.legacy_text = rawHtml;
        return parsed;
    }

    sections.forEach((section) => {
        const key = String(section.getAttribute('data-smile-observacoes-block') || '').trim();
        if (key === '' || !Object.prototype.hasOwnProperty.call(parsed, key)) return;
        const contentNode = section.querySelector('[data-smile-observacoes-content]');
        parsed[key] = String(contentNode ? contentNode.innerHTML : section.innerHTML || '').trim();
    });

    return parsed;
}

function setObservacoesBlockContent(blockKey, html) {
    if (isCronogramaBlock(blockKey)) {
        hydrateCronogramaFromHtml(html);
        return;
    }
    const editorId = getObservacoesBlockEditorId(blockKey);
    if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
        tinymce.get(editorId).setContent(String(html || ''));
        return;
    }
    const textarea = document.getElementById(editorId);
    if (textarea) {
        textarea.value = String(html || '');
    }
}

function getObservacoesBlockContent(blockKey) {
    if (isCronogramaBlock(blockKey)) {
        return buildCronogramaHtmlFromRows();
    }
    const editorId = getObservacoesBlockEditorId(blockKey);
    if (typeof tinymce !== 'undefined' && tinymce.get(editorId)) {
        return tinymce.get(editorId).getContent() || '';
    }
    const textarea = document.getElementById(editorId);
    return textarea ? String(textarea.value || '') : '';
}

function hydrateObservacoesBlocksFromSavedContent() {
    const parsed = parseObservacoesBlocksFromContent(getEditorContent('observacoes_gerais'));
    Object.keys(parsed).forEach((blockKey) => {
        setObservacoesBlockContent(blockKey, parsed[blockKey]);
    });
    if (!Object.prototype.hasOwnProperty.call(parsed, CRONOGRAMA_BLOCK_KEY)) {
        hydrateCronogramaFromHtml('');
    }
}

function buildObservacoesBlocksContent(options = {}) {
    const onlyPublic = !!(options && options.onlyPublic);
    const parts = [];
    (observacoesEditorBlocks || []).forEach((block) => {
        const key = String(block && block.key ? block.key : '').trim();
        const label = String(block && block.label ? block.label : '').trim();
        const isPublic = !block || !Object.prototype.hasOwnProperty.call(block, 'public') ? true : !!block.public;
        if (!key || (onlyPublic && !isPublic)) {
            return;
        }

        const html = sanitizeRichHtmlForField(getObservacoesBlockContent(key)).trim();
        if (stripHtmlToText(html) === '') {
            return;
        }

        parts.push(
            `<section data-smile-observacoes-block="${escapeHtmlForField(key)}" data-smile-client-visible="${isPublic ? '1' : '0'}">`
            + `<h3>${escapeHtmlForField(label)}</h3>`
            + `<div data-smile-observacoes-content="1">${html}</div>`
            + `</section>`
        );
    });
    return parts.join('\n');
}

function toggleObservacoesBlock(blockKey) {
    const body = document.getElementById(`obsBody-${blockKey}`);
    const chevron = document.getElementById(`obsChevron-${blockKey}`);
    if (!body || !chevron) return;
    const isCollapsed = body.classList.contains('is-collapsed');
    body.classList.toggle('is-collapsed', !isCollapsed);
    chevron.textContent = isCollapsed ? '▾' : '▸';
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

async function parseJsonResponse(resp, context = 'a requisição') {
    const status = Number(resp && resp.status ? resp.status : 0);
    const bodyText = await resp.text();
    if (bodyText.trim() === '') {
        if (status === 401 || status === 403) {
            throw new Error('Sessão expirada. Recarregue a página e faça login novamente.');
        }
        throw new Error(`Falha ao processar ${context}: resposta vazia do servidor (HTTP ${status}).`);
    }
    try {
        return JSON.parse(bodyText);
    } catch (err) {
        throw new Error(`Falha ao processar ${context}: resposta inválida do servidor (HTTP ${status}).`);
    }
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
    const data = await parseJsonResponse(resp, 'a busca de eventos');
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
        const data = await parseJsonResponse(resp, 'a criação da reunião');
        
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
    
    const escapedSection = (window.CSS && typeof window.CSS.escape === 'function')
        ? window.CSS.escape(String(section))
        : String(section).replace(/["\\]/g, '\\$&');
    const btn = document.querySelector(`.tab-btn[data-tab-section="${escapedSection}"]`);
    const tab = document.getElementById(`tab-${section}`);
    if (!btn || !tab) return;
    btn.classList.add('active');
    tab.classList.add('active');
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
        const contentHtml = type === 'note' ? String(field.content_html || '').trim() : '';
        return {
            id: String(field.id || ('f_' + Math.random().toString(36).slice(2, 10))),
            type: type,
            label: String(field.label || '').trim(),
            required: neverRequired ? false : !!field.required,
            options: options,
            content_html: contentHtml
        };
    }).filter((field) => {
        if (field.type === 'divider') return true;
        return field.label !== '' || (field.type === 'note' && String(field.content_html || '').trim() !== '');
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

function getDjPublicUrl(slot) {
    const link = djLinksBySlot[slot] || null;
    if (!link || !link.token) {
        return '';
    }
    const base = String(clientPortalBaseUrl || window.location.origin || '').replace(/\/+$/, '');
    return `${base}/index.php?page=eventos_cliente_dj&token=${encodeURIComponent(String(link.token))}`;
}

function abrirDjFormularioPublico(slot) {
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !djSlotExists(slotIndex)) {
        alert('Formulário DJ inválido.');
        return;
    }
    const url = getDjPublicUrl(slotIndex);
    if (!url) {
        alert('Nenhum link público disponível para este formulário DJ.');
        return;
    }
    window.open(url, '_blank');
}

function buildDjSlotResponseStatusHtml(slot) {
    const link = djLinksBySlot[slot] || null;
    if (!link) {
        return '';
    }

    const draftSavedAt = link.draft_saved_at ? formatDate(link.draft_saved_at) : '';
    const submittedAt = link.submitted_at ? formatDate(link.submitted_at) : '';
    const hasDraft = !!link.draft_saved_at;
    const hasSubmitted = !!link.submitted_at;
    const publicUrl = getDjPublicUrl(slot);
    const actions = [];

    if (publicUrl) {
        actions.push(`<button type="button" class="btn btn-secondary" onclick="abrirDjFormularioPublico(${slot})">↗ Abrir formulário</button>`);
    }
    if (hasDraft) {
        actions.push(`<button type="button" class="btn btn-secondary" onclick="abrirModalDjResposta(${slot}, 'draft')">👁 Ver rascunho</button>`);
    }
    if (hasSubmitted) {
        actions.push(`<button type="button" class="btn btn-secondary" onclick="abrirModalDjResposta(${slot}, 'submitted')">👁 Ver enviado</button>`);
    }

    if (!hasDraft && !hasSubmitted && !publicUrl) {
        return '';
    }

    const lines = [];
    if (publicUrl && !hasDraft && !hasSubmitted) {
        lines.push('<div><strong>Link ativo</strong><p>Aguardando preenchimento do cliente.</p></div>');
    }
    if (hasDraft) {
        lines.push(`<div><strong>Rascunho salvo</strong><p>${escapeHtmlForField(draftSavedAt)}</p></div>`);
    }
    if (hasSubmitted) {
        lines.push(`<div><strong>Formulário enviado</strong><p>${escapeHtmlForField(submittedAt)}</p></div>`);
    }

    return `
        <div class="slot-response-status">
            ${lines.join('')}
            ${actions.length ? `<div class="slot-response-actions">${actions.join('')}</div>` : ''}
        </div>
    `;
}

function buildDjSlotCardHtml(slot) {
    const link = djLinksBySlot[slot] || null;
    const portalVisibleChecked = link && link.portal_visible ? ' checked' : '';
    const portalEditableChecked = link && link.portal_editable ? ' checked' : '';
    const disabledAttr = pageReadonly ? ' disabled' : '';
    const destravarBtnHtml = pageReadonly
        ? ''
        : `<button type="button" class="btn btn-secondary" onclick="destravarDjSlot(${slot})" id="djBtnDestravar-${slot}" style="display:none;">🔓 Destravar</button>`;
    const removeBtnHtml = pageReadonly
        ? ''
        : `<button type="button" class="btn btn-secondary btn-slot-remove" onclick="excluirDjSlot(${slot})">🗑 Excluir formulário</button>`;
    const statusHtml = buildDjSlotResponseStatusHtml(slot);
    return `
        <div class="dj-builder-shell" data-dj-slot="${slot}">
            <div class="dj-builder-head">
                <div>
                    <h4 class="dj-builder-title">🎧 Formulário DJ • Quadro ${slot}</h4>
                    <div class="dj-builder-subtitle">Selecione um formulário exclusivo do DJ e configure a liberação para o portal.</div>
                </div>
                <div class="dj-top-actions">
                    ${destravarBtnHtml}
                    ${removeBtnHtml}
                </div>
            </div>
            <div class="prefill-field" style="margin-top: 0.5rem;">
                <label for="djTemplateSelect-${slot}">Formulário salvo</label>
                <select id="djTemplateSelect-${slot}" onchange="onChangeDjTemplateSelect(${slot})"${disabledAttr}>
                    <option value="">Selecione um formulário...</option>
                </select>
            </div>
            <div class="builder-field-meta" id="selectedDjTemplateMeta-${slot}" style="margin-top: 0.55rem;">Nenhum formulário selecionado.</div>
            <div class="portal-settings">
                <label class="portal-settings-label" for="djPortalVisible-${slot}">
                    <input type="checkbox" id="djPortalVisible-${slot}" onchange="onChangeDjPortalVisibility(${slot})"${portalVisibleChecked}${disabledAttr}>
                    Exibir este quadro no Portal do Cliente
                </label>
                <label class="portal-settings-label" for="djPortalEditable-${slot}">
                    <input type="checkbox" id="djPortalEditable-${slot}" onchange="onChangeDjPortalEditable(${slot})"${portalEditableChecked}${disabledAttr}>
                    Permitir preenchimento do cliente
                </label>
            </div>
            ${statusHtml}
            <p class="share-hint" id="shareHint-${slot}">Selecione um formulário para configurar este quadro no portal.</p>
        </div>
    `;
}

function renderDjSlots() {
    const container = document.getElementById('djSlotsContainer');
    const empty = document.getElementById('djSlotsEmptyState');
    if (!container) return;

    const slots = getSortedDjSlots();
    if (slots.length === 0) {
        container.innerHTML = '';
        if (empty) empty.style.display = 'block';
        return;
    }

    if (empty) empty.style.display = 'none';
    container.innerHTML = slots.map((slot) => buildDjSlotCardHtml(slot)).join('');

    slots.forEach((slot) => {
        ensureDjSlotState(slot);
        renderDjTemplateSelect(slot);
        syncDjPortalToggles(slot);
        updateShareAvailability(slot);
    });
}

function addDjSlot(preferredSlot = null) {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return null;
    }
    if (!meetingId) {
        alert('Crie a reunião antes de adicionar formulários DJ.');
        return null;
    }
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
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !djSlotExists(slotIndex)) {
        return;
    }

    if (!confirm(`Excluir o quadro ${slotIndex}?`)) {
        return;
    }
    const senhaConfirmacao = window.prompt('Digite sua senha para confirmar a exclusão deste formulário DJ:');
    if (senhaConfirmacao === null) return;
    if (!String(senhaConfirmacao).trim()) {
        alert('Informe sua senha para concluir a exclusão.');
        return;
    }

    if (meetingId) {
        try {
            const formData = new FormData();
            formData.append('action', 'excluir_dj_slot');
            formData.append('meeting_id', String(meetingId));
            formData.append('slot_index', String(slotIndex));
            formData.append('confirm_password', String(senhaConfirmacao));
            const resp = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const data = await parseJsonResponse(resp, 'a exclusão do quadro');
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
    delete djPortalSaveInFlight[slotIndex];
    delete djPortalSavePending[slotIndex];
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

function syncDjPortalToggles(slot = 1) {
    const visibleInput = document.getElementById(`djPortalVisible-${slot}`);
    const editableInput = document.getElementById(`djPortalEditable-${slot}`);
    if (!visibleInput || !editableInput) {
        return;
    }
    if (editableInput.checked && !visibleInput.checked) {
        visibleInput.checked = true;
    }
}

function onChangeDjPortalVisibility(slot = 1) {
    if (pageReadonly) return;
    const visibleInput = document.getElementById(`djPortalVisible-${slot}`);
    const editableInput = document.getElementById(`djPortalEditable-${slot}`);
    if (visibleInput && editableInput && editableInput.checked && !visibleInput.checked) {
        visibleInput.checked = true;
    }
    updateShareAvailability(slot);
    requestDjSlotPortalAutoSave(slot);
}

function onChangeDjPortalEditable(slot = 1) {
    if (pageReadonly) return;
    syncDjPortalToggles(slot);
    updateShareAvailability(slot);
    requestDjSlotPortalAutoSave(slot);
}

function requestDjSlotPortalAutoSave(slot = 1) {
    if (pageReadonly) return;
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !djSlotExists(slotIndex)) {
        return;
    }
    void salvarDjSlotPortalConfig(slotIndex, {
        silentSuccess: true,
        suppressValidationAlert: true,
    });
}

function updateShareAvailability(slot = 1) {
    const hint = document.getElementById(`shareHint-${slot}`);
    const visibleInput = document.getElementById(`djPortalVisible-${slot}`);
    const editableInput = document.getElementById(`djPortalEditable-${slot}`);
    const select = document.getElementById(`djTemplateSelect-${slot}`);
    const unlockBtn = document.getElementById(`djBtnDestravar-${slot}`);

    if (pageReadonly) {
        if (select) select.disabled = true;
        if (visibleInput) visibleInput.disabled = true;
        if (editableInput) editableInput.disabled = true;
        if (unlockBtn) unlockBtn.style.display = 'none';
        if (hint) hint.textContent = 'Modo somente leitura.';
        return;
    }

    let disabled = false;
    let hintText = 'Selecione um formulário para configurar este quadro no portal.';

    syncDjPortalToggles(slot);

    if (isDjSlotLocked(slot)) {
        hintText = 'Cliente já enviou este formulário DJ. Clique em "Destravar" para permitir novo preenchimento.';
        if (select) select.disabled = true;
        if (unlockBtn) unlockBtn.style.display = 'inline-flex';
    } else {
        if (select) select.disabled = false;
        if (unlockBtn) unlockBtn.style.display = 'none';
    }

    const selected = getSelectedDjTemplateData(slot);
    if (!selected.template) {
        disabled = true;
    } else if (!hasUsefulSchemaFields(selected.schema)) {
        disabled = true;
        hintText = 'O formulário selecionado não possui campos válidos.';
    } else if (visibleInput && editableInput) {
        if (!visibleInput.checked && !editableInput.checked) {
            hintText = 'Quadro oculto do portal do cliente.';
        } else if (visibleInput.checked && !editableInput.checked) {
            hintText = 'Quadro visível no portal em modo somente leitura.';
        } else if (visibleInput.checked && editableInput.checked) {
            hintText = 'Quadro visível no portal com edição liberada para o cliente.';
        }
    }

    if (visibleInput) visibleInput.disabled = disabled;
    if (editableInput) editableInput.disabled = disabled;
    if (hint) hint.textContent = hintText;
}

function formatDjAnexoSize(sizeBytes) {
    const size = Number(sizeBytes || 0);
    if (!Number.isFinite(size) || size <= 0) return '-';
    if (size < 1024) return `${size} B`;
    if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`;
    if (size < 1024 * 1024 * 1024) return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    return `${(size / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

function getDjAnexoIcon(anexo) {
    const kind = String(anexo && anexo.file_kind ? anexo.file_kind : '').toLowerCase();
    if (kind === 'imagem') return '🖼️';
    if (kind === 'video') return '🎬';
    if (kind === 'audio') return '🎵';
    if (kind === 'pdf') return '📄';
    return '📎';
}

function setDjAnexosStatus(message, type = '') {
    const status = document.getElementById('djAnexosStatus');
    if (!status) return;
    status.textContent = message || '';
    status.classList.remove('error', 'success');
    if (type === 'error' || type === 'success') {
        status.classList.add(type);
    }
    status.style.display = String(message || '').trim() !== '' ? 'block' : 'none';
    refreshDjAnexosVisibility();
}

function refreshDjAnexosVisibility() {
    const box = document.getElementById('djAnexosBox');
    const status = document.getElementById('djAnexosStatus');
    if (!box) return;

    const hasCards = Array.isArray(djUploadCardOrder) && djUploadCardOrder.length > 0;
    const hasAnexos = Array.isArray(djAnexos) && djAnexos.length > 0;
    const hasStatus = !!(status && status.style.display !== 'none' && String(status.textContent || '').trim() !== '');
    box.style.display = (hasCards || hasAnexos || hasStatus) ? 'block' : 'none';
}

function normalizeDjUploadCardId(cardId) {
    const parsed = Number(cardId);
    if (!Number.isInteger(parsed) || parsed <= 0) return null;
    return parsed;
}

function nextDjUploadCardId() {
    djUploadCardCounter += 1;
    return djUploadCardCounter;
}

function buildDjUploadCardHtml(cardId, orderIndex) {
    const uploadLabel = orderIndex + 1;
    return `
        <div class="dj-upload-card" data-upload-card="${cardId}">
            <div class="dj-upload-card-head">
                <div class="dj-upload-card-title">📎 Upload ${uploadLabel}</div>
                <button type="button" class="btn btn-secondary btn-mini btn-upload-remove" onclick="removeDjUploadCard(${cardId})">Remover</button>
            </div>
            <div class="dj-anexos-upload">
                <input type="file"
                       id="djAnexosInput-${cardId}"
                       multiple
                       accept=".pdf,.png,.jpg,.jpeg,.webp,.heic,.heif,.mp3,.wav,.ogg,.aac,.m4a,.mp4,.mov,.webm,.avi,.doc,.docx,.xls,.xlsx,.xlsm,.txt,.csv">
                <button type="button" class="btn btn-primary btn-upload-mini" id="btnUploadDjAnexos-${cardId}" onclick="uploadDjAnexos(${cardId})">Enviar arquivos</button>
            </div>
            <div class="dj-anexos-note">
                <input type="text"
                       id="djAnexosNote-${cardId}"
                       maxlength="300"
                       placeholder="Observação do upload (opcional). Ex.: materiais para abertura de pista">
            </div>
        </div>
    `;
}

function renderDjUploadCards() {
    const container = document.getElementById('djUploadCardsContainer');
    if (!container) return;

    if (!Array.isArray(djUploadCardOrder) || djUploadCardOrder.length === 0) {
        container.innerHTML = '';
        refreshDjAnexosVisibility();
        return;
    }

    container.innerHTML = djUploadCardOrder.map((cardId, orderIndex) => buildDjUploadCardHtml(cardId, orderIndex)).join('');
    refreshDjAnexosVisibility();
}

function addDjUploadCard() {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return null;
    }
    const cardId = nextDjUploadCardId();
    djUploadCardOrder.push(cardId);
    renderDjUploadCards();
    const input = document.getElementById(`djAnexosInput-${cardId}`);
    if (input) {
        input.focus();
    }
    return cardId;
}

function removeDjUploadCard(cardId) {
    const normalizedId = normalizeDjUploadCardId(cardId);
    if (normalizedId === null) return;
    djUploadCardOrder = djUploadCardOrder.filter((item) => item !== normalizedId);
    renderDjUploadCards();
}

function renderDjAnexosList() {
    const list = document.getElementById('djAnexosList');
    if (!list) return;

    if (!Array.isArray(djAnexos) || djAnexos.length === 0) {
        list.innerHTML = '';
        refreshDjAnexosVisibility();
        return;
    }

    list.innerHTML = djAnexos.map((anexo) => {
        const anexoId = Number(anexo && anexo.id ? anexo.id : 0);
        const name = escapeHtmlForField(String(anexo && anexo.original_name ? anexo.original_name : 'arquivo'));
        const mime = escapeHtmlForField(String(anexo && anexo.mime_type ? anexo.mime_type : 'application/octet-stream'));
        const size = formatDjAnexoSize(anexo && anexo.size_bytes ? anexo.size_bytes : 0);
        const uploadedAt = anexo && anexo.uploaded_at ? formatDate(anexo.uploaded_at) : '-';
        const uploadedBy = escapeHtmlForField(String(anexo && anexo.uploaded_by_type ? anexo.uploaded_by_type : 'interno'));
        const noteRaw = String(anexo && anexo.note ? anexo.note : '').trim();
        const noteHtml = noteRaw !== '' ? `<div class="dj-anexo-note"><strong>Obs:</strong> ${escapeHtmlForField(noteRaw)}</div>` : '';
        const url = String(anexo && anexo.public_url ? anexo.public_url : '').trim();
        const urlEsc = escapeHtmlForField(url);
        const icon = getDjAnexoIcon(anexo);
        const deleteBtnHtml = (!pageReadonly && anexoId > 0)
            ? `<button type="button" class="btn btn-secondary btn-mini btn-anexo-delete" onclick="excluirDjAnexo(${anexoId})">Excluir</button>`
            : '';
        const actionsHtml = url !== ''
            ? `<div class="dj-anexo-actions">
                    <a class="btn btn-secondary btn-mini" href="${urlEsc}" target="_blank" rel="noopener noreferrer">Abrir</a>
                    <a class="btn btn-primary btn-mini" href="${urlEsc}" target="_blank" rel="noopener noreferrer" download>Download</a>
                    ${deleteBtnHtml}
               </div>`
            : `<div class="dj-anexo-actions">
                    ${deleteBtnHtml}
               </div>`;

        return `
            <div class="dj-anexo-item">
                <div class="dj-anexo-info">
                    <span class="dj-anexo-icon">${icon}</span>
                    <div>
                        <div class="dj-anexo-name">${name}</div>
                        <div class="dj-anexo-meta">${mime} • ${size} • ${uploadedAt} • ${uploadedBy}</div>
                        ${noteHtml}
                    </div>
                </div>
                ${actionsHtml}
            </div>
        `;
    }).join('');
    refreshDjAnexosVisibility();
}

async function uploadDjAnexos(cardId) {
    if (pageReadonly) {
        setDjAnexosStatus('Modo somente leitura.', 'error');
        return;
    }
    if (!meetingId) {
        alert('Reunião inválida.');
        return;
    }

    const normalizedCardId = normalizeDjUploadCardId(cardId);
    if (normalizedCardId === null) {
        alert('Upload inválido.');
        return;
    }

    const input = document.getElementById(`djAnexosInput-${normalizedCardId}`);
    const noteInput = document.getElementById(`djAnexosNote-${normalizedCardId}`);
    const button = document.getElementById(`btnUploadDjAnexos-${normalizedCardId}`);
    if (!input || !button) return;

    const files = Array.from(input.files || []);
    if (!files.length) {
        alert('Selecione ao menos um arquivo.');
        return;
    }

    button.disabled = true;
    setDjAnexosStatus(`Enviando arquivos do upload ${normalizedCardId}...`, '');

    try {
        const formData = new FormData();
        formData.append('action', 'upload_anexos_dj');
        formData.append('meeting_id', String(meetingId));
        const uploadNote = noteInput ? String(noteInput.value || '').trim() : '';
        if (uploadNote !== '') {
            formData.append('anexo_note', uploadNote);
        }
        files.forEach((file) => {
            formData.append('anexos[]', file, file.name || 'arquivo');
        });

        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await parseJsonResponse(resp, 'o upload de anexos');

        if (!data.ok) {
            setDjAnexosStatus(data.error || 'Falha ao enviar anexos.', 'error');
            return;
        }

        if (Array.isArray(data.anexos)) {
            djAnexos = data.anexos;
        }
        renderDjAnexosList();
        removeDjUploadCard(normalizedCardId);

        const uploadedCount = Number(data.uploaded || files.length || 0);
        const warning = String(data.warning || '').trim();
        if (warning !== '') {
            setDjAnexosStatus(`Upload concluído (${uploadedCount} arquivo(s)). Aviso: ${warning}`, 'success');
        } else {
            setDjAnexosStatus(`Upload concluído (${uploadedCount} arquivo(s)).`, 'success');
        }
    } catch (err) {
        setDjAnexosStatus('Erro ao enviar anexos: ' + err.message, 'error');
    } finally {
        button.disabled = false;
    }
}

async function excluirDjAnexo(anexoId) {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
    if (!meetingId) {
        alert('Reunião inválida.');
        return;
    }

    const id = Number(anexoId || 0);
    if (!Number.isInteger(id) || id <= 0) {
        alert('Anexo inválido.');
        return;
    }

    if (!confirm('Deseja excluir este anexo?')) {
        return;
    }

    setDjAnexosStatus('Excluindo anexo...', '');

    try {
        const formData = new FormData();
        formData.append('action', 'excluir_anexo_dj');
        formData.append('meeting_id', String(meetingId));
        formData.append('anexo_id', String(id));

        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await parseJsonResponse(resp, 'a exclusão do anexo');

        if (!data.ok) {
            setDjAnexosStatus(data.error || 'Falha ao excluir anexo.', 'error');
            return;
        }

        if (Array.isArray(data.anexos)) {
            djAnexos = data.anexos;
        } else {
            djAnexos = (Array.isArray(djAnexos) ? djAnexos : []).filter((item) => Number(item && item.id ? item.id : 0) !== id);
        }
        renderDjAnexosList();

        const warning = String(data.warning || '').trim();
        if (warning !== '') {
            setDjAnexosStatus('Anexo excluído. Aviso: ' + warning, 'success');
        } else {
            setDjAnexosStatus('Anexo excluído com sucesso.', 'success');
        }
    } catch (err) {
        setDjAnexosStatus('Erro ao excluir anexo: ' + err.message, 'error');
    }
}

function sanitizeRichHtmlForField(html) {
    const wrapper = document.createElement('div');
    wrapper.innerHTML = String(html || '');
    wrapper.querySelectorAll('script, style, iframe, object, embed').forEach((node) => node.remove());
    wrapper.querySelectorAll('*').forEach((node) => {
        Array.from(node.attributes).forEach((attr) => {
            const attrName = String(attr.name || '').toLowerCase();
            const attrValue = String(attr.value || '').toLowerCase();
            if (attrName.startsWith('on')) {
                node.removeAttribute(attr.name);
                return;
            }
            if ((attrName === 'href' || attrName === 'src') && attrValue.startsWith('javascript:')) {
                node.removeAttribute(attr.name);
            }
        });
    });
    return wrapper.innerHTML;
}

function getFieldNoteHtml(field) {
    const raw = String(field && field.content_html ? field.content_html : '').trim();
    if (raw !== '') {
        return sanitizeRichHtmlForField(raw);
    }
    const fallback = escapeHtmlForField(String(field && field.label ? field.label : ''));
    return fallback !== '' ? `<em>${fallback}</em>` : '';
}

function isLegacyPortalVisible(section) {
    const input = document.getElementById(`legacyPortalVisible-${section}`);
    if (!input) return true;
    return !!input.checked;
}

function buildPortalSchemaWithLegacyText(section, schema) {
    const normalized = normalizeFormSchema(Array.isArray(schema) ? schema : []);
    if (!isLegacyPortalVisible(section)) {
        return normalized;
    }

    const legacyRaw = section === 'observacoes_gerais'
        ? buildObservacoesBlocksContent({ onlyPublic: true })
        : getEditorContent(section);
    const legacyHtml = sanitizeRichHtmlForField(String(legacyRaw || '')).trim();
    if (stripHtmlToText(legacyHtml) === '') {
        return normalized;
    }

    const noteId = `legacy_portal_text_${section}`;
    const filtered = normalized.filter((field) => {
        const fieldId = String(field && field.id ? field.id : '').trim();
        return fieldId !== noteId;
    });

    filtered.push({
        id: noteId,
        type: 'textarea',
        label: 'Texto livre (opcional)',
        required: false,
        options: [],
        content_html: '',
        default_value: stripHtmlToText(legacyHtml)
    });
    return filtered;
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
            const noteHtml = getFieldNoteHtml(field);
            if (noteHtml !== '') {
                html += `<div>${noteHtml}</div>`;
            }
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
    select.disabled = !!pageReadonly;
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
    if (pageReadonly) return;
    const select = document.getElementById(`djTemplateSelect-${slot}`);
    selectedDjTemplateIds[slot] = select && select.value ? Number(select.value) : null;
    updateSelectedDjTemplateMeta(slot);
    updateShareAvailability(slot);
    requestDjSlotPortalAutoSave(slot);
}

function renderAllDjTemplateSelects() {
    renderDjSlots();
}

function getSortedObservacoesSlots() {
    return observacoesSlotOrder
        .map((slot) => normalizeSlotIndex(slot))
        .filter((slot) => slot !== null)
        .sort((a, b) => a - b);
}

function observacoesSlotExists(slot) {
    const normalized = normalizeSlotIndex(slot);
    if (normalized === null) return false;
    return observacoesSlotOrder.includes(normalized);
}

function ensureObservacoesSlotState(slot) {
    if (!Object.prototype.hasOwnProperty.call(selectedObservacoesTemplateIds, slot)) {
        selectedObservacoesTemplateIds[slot] = null;
    }
    if (!Object.prototype.hasOwnProperty.call(observacoesLinksBySlot, slot)) {
        observacoesLinksBySlot[slot] = null;
    }
}

function findNextObservacoesSlotIndex() {
    const used = new Set(getSortedObservacoesSlots());
    for (let slot = DJ_SLOT_MIN; slot <= DJ_SLOT_MAX; slot += 1) {
        if (!used.has(slot)) return slot;
    }
    return null;
}

function setObservacoesLinkOutput(slot, url) {
    const input = document.getElementById(`obsClienteLinkInput-${slot}`);
    const copyBtn = document.getElementById(`obsBtnCopiar-${slot}`);
    if (input) {
        input.value = url || '';
    }
    if (copyBtn) {
        copyBtn.style.display = url ? 'inline-flex' : 'none';
    }
}

function getSelectedObservacoesTemplateData(slot) {
    if (!observacoesSlotExists(slot)) {
        return { template: null, schema: [] };
    }
    const templateId = Number(selectedObservacoesTemplateIds[slot] || 0);
    if (templateId <= 0) {
        return { template: null, schema: [] };
    }
    const template = savedFormTemplates.find((item) => Number(item.id) === templateId) || null;
    const schema = normalizeFormSchema(template && Array.isArray(template.schema) ? template.schema : []);
    return { template, schema };
}

function isObservacoesSlotLocked(slot) {
    const link = observacoesLinksBySlot[slot] || null;
    return !!(link && link.submitted_at);
}

function updateObservacoesShareAvailability(slot) {
    const shareBtn = document.getElementById(`obsBtnGerarLink-${slot}`);
    const hint = document.getElementById(`obsShareHint-${slot}`);
    const unlockBtn = document.getElementById(`obsBtnDestravar-${slot}`);
    const select = document.getElementById(`obsTemplateSelect-${slot}`);
    if (!shareBtn) return;

    if (pageReadonly) {
        shareBtn.disabled = true;
        if (hint) hint.textContent = 'Modo somente leitura.';
        if (unlockBtn) unlockBtn.style.display = 'none';
        if (select) select.disabled = true;
        return;
    }

    let disabled = false;
    let hintText = 'Selecione um formulário para habilitar o compartilhamento.';

    if (isObservacoesSlotLocked(slot)) {
        disabled = true;
        hintText = 'Este quadro está travado (cliente já enviou). Clique em "Destravar" para permitir nova edição.';
        if (unlockBtn) unlockBtn.style.display = 'inline-flex';
        if (select) select.disabled = true;
    } else {
        if (unlockBtn) unlockBtn.style.display = 'none';
        if (select) select.disabled = false;

        const selected = getSelectedObservacoesTemplateData(slot);
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

function buildObservacoesSlotCardHtml(slot) {
    const disabledAttr = pageReadonly ? ' disabled' : '';
    const gerarBtnHtml = pageReadonly
        ? ''
        : `<button type="button" class="btn btn-primary" onclick="gerarLinkClienteObservacoes(${slot})" id="obsBtnGerarLink-${slot}">Gerar link</button>`;
    const destravarBtnHtml = pageReadonly
        ? ''
        : `<button type="button" class="btn btn-secondary" onclick="destravarObservacoesSlot(${slot})" id="obsBtnDestravar-${slot}" style="display:none;">🔓 Destravar</button>`;
    const excluirBtnHtml = pageReadonly
        ? ''
        : `<button type="button" class="btn btn-secondary btn-slot-remove" onclick="excluirObservacoesSlot(${slot})">🗑 Excluir quadro</button>`;
    return `
        <div class="dj-builder-shell" data-obs-slot="${slot}">
            <div class="dj-builder-head">
                <div>
                    <h4 class="dj-builder-title">🧩 Observações Gerais • Quadro ${slot}</h4>
                    <div class="dj-builder-subtitle">Selecione um formulário e gere o link público para o cliente preencher.</div>
                </div>
                <div class="dj-top-actions">
                    ${gerarBtnHtml}
                    ${destravarBtnHtml}
                    ${excluirBtnHtml}
                </div>
            </div>
            <div class="prefill-field" style="margin-top: 0.5rem;">
                <label for="obsTemplateSelect-${slot}">Formulário salvo</label>
                <select id="obsTemplateSelect-${slot}" onchange="onChangeObservacoesTemplateSelect(${slot})"${disabledAttr}>
                    <option value="">Selecione um formulário...</option>
                </select>
            </div>
            <div class="builder-field-meta" id="obsSelectedTemplateMeta-${slot}" style="margin-top: 0.55rem;">Nenhum formulário selecionado.</div>
            <p class="share-hint" id="obsShareHint-${slot}">Selecione um formulário para habilitar o compartilhamento.</p>
            <div class="link-display">
                <input type="text" id="obsClienteLinkInput-${slot}" class="link-input" readonly placeholder="Clique em 'Gerar link' para criar">
                <button type="button" class="btn btn-secondary" onclick="copiarLinkObservacoes(${slot})" id="obsBtnCopiar-${slot}" style="display:none;">📋 Copiar</button>
            </div>
        </div>
    `;
}

function renderObservacoesTemplateSelect(slot) {
    const select = document.getElementById(`obsTemplateSelect-${slot}`);
    if (!select) return;
    const current = selectedObservacoesTemplateIds[slot] ? String(selectedObservacoesTemplateIds[slot]) : '';
    const options = ['<option value="">Selecione um formulário...</option>'];
    (savedFormTemplates || []).forEach((template) => {
        const id = Number(template.id || 0);
        if (!id) return;
        const label = `${String(template.nome || 'Modelo sem nome')} - ${String(template.categoria || 'geral')}`;
        const selected = String(id) === current ? ' selected' : '';
        options.push(`<option value="${id}"${selected}>${escapeHtmlForField(label)}</option>`);
    });
    select.innerHTML = options.join('');
    select.disabled = !!pageReadonly;
    updateSelectedObservacoesTemplateMeta(slot);
}

function updateSelectedObservacoesTemplateMeta(slot) {
    const meta = document.getElementById(`obsSelectedTemplateMeta-${slot}`);
    if (!meta) return;
    const templateId = Number(selectedObservacoesTemplateIds[slot] || 0);
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

function onChangeObservacoesTemplateSelect(slot) {
    if (pageReadonly) return;
    const select = document.getElementById(`obsTemplateSelect-${slot}`);
    selectedObservacoesTemplateIds[slot] = select && select.value ? Number(select.value) : null;
    updateSelectedObservacoesTemplateMeta(slot);
    updateObservacoesShareAvailability(slot);
}

function renderObservacoesClientSlots() {
    const container = document.getElementById('obsSlotsContainer');
    const empty = document.getElementById('obsSlotsEmptyState');
    if (!container || !empty) return;

    const slots = getSortedObservacoesSlots();
    if (slots.length === 0) {
        container.innerHTML = '';
        empty.style.display = 'block';
        return;
    }
    empty.style.display = 'none';
    container.innerHTML = slots.map((slot) => buildObservacoesSlotCardHtml(slot)).join('');
    slots.forEach((slot) => {
        ensureObservacoesSlotState(slot);
        renderObservacoesTemplateSelect(slot);
        const link = observacoesLinksBySlot[slot] || null;
        if (link && link.token) {
            setObservacoesLinkOutput(slot, `${window.location.origin}/index.php?page=eventos_cliente_dj&token=${link.token}`);
        } else {
            setObservacoesLinkOutput(slot, '');
        }
        updateObservacoesShareAvailability(slot);
    });
}

function addObservacoesClientSlot(preferredSlot = null) {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return null;
    }
    if (!meetingId) {
        alert('Crie a reunião antes de adicionar links públicos.');
        return null;
    }
    const slot = preferredSlot !== null ? normalizeSlotIndex(preferredSlot) : findNextObservacoesSlotIndex();
    if (slot === null) {
        alert('Limite de quadros atingido (máximo de 50).');
        return null;
    }
    if (!observacoesSlotExists(slot)) {
        observacoesSlotOrder.push(slot);
    }
    observacoesSlotOrder = getSortedObservacoesSlots();
    ensureObservacoesSlotState(slot);
    renderObservacoesClientSlots();
    const select = document.getElementById(`obsTemplateSelect-${slot}`);
    if (select) select.focus();
    return slot;
}

async function excluirObservacoesSlot(slot = 1) {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !observacoesSlotExists(slotIndex)) return;
    const link = observacoesLinksBySlot[slotIndex] || null;
    if (link && link.submitted_at) {
        alert('Este quadro já foi enviado pelo cliente e não pode ser excluído.');
        return;
    }
    if (!confirm(`Excluir o quadro ${slotIndex}?`)) return;

    try {
        const formData = new FormData();
        formData.append('action', 'excluir_observacoes_slot');
        formData.append('meeting_id', String(meetingId));
        formData.append('slot_index', String(slotIndex));
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp, 'a exclusão do quadro');
        if (!data.ok) {
            alert(data.error || 'Erro ao excluir quadro');
            return;
        }
    } catch (err) {
        alert('Erro: ' + err.message);
        return;
    }

    observacoesSlotOrder = getSortedObservacoesSlots().filter((item) => item !== slotIndex);
    delete selectedObservacoesTemplateIds[slotIndex];
    delete observacoesLinksBySlot[slotIndex];
    renderObservacoesClientSlots();
}

async function gerarLinkClienteObservacoes(slot = 1) {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !observacoesSlotExists(slotIndex)) {
        alert('Quadro inválido.');
        return;
    }
    updateObservacoesShareAvailability(slotIndex);
    const btn = document.getElementById(`obsBtnGerarLink-${slotIndex}`);
    if (btn && btn.disabled) {
        const hint = document.getElementById(`obsShareHint-${slotIndex}`);
        alert(hint ? hint.textContent : 'Selecione um formulário válido antes de gerar o link.');
        return;
    }

    try {
        const selected = getSelectedObservacoesTemplateData(slotIndex);
        if (!selected.template) {
            alert('Selecione um formulário antes de gerar o link.');
            return;
        }
        if (!hasUsefulSchemaFields(selected.schema)) {
            alert('O formulário selecionado não possui campos válidos.');
            return;
        }

        const formTitle = String(selected.template.nome || `Observações Gerais - Quadro ${slotIndex}`);
        const schemaForPortal = buildPortalSchemaWithLegacyText('observacoes_gerais', selected.schema);
        const contentHtml = buildSchemaHtmlForStorage(schemaForPortal, formTitle);
        const formData = new FormData();
        formData.append('action', 'gerar_link_cliente_observacoes');
        formData.append('meeting_id', String(meetingId));
        formData.append('slot_index', String(slotIndex));
        formData.append('form_schema_json', JSON.stringify(schemaForPortal));
        formData.append('content_html', contentHtml);
        formData.append('form_title', formTitle);

        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp, 'a geração do link');
        if (data.ok && data.url) {
            setObservacoesLinkOutput(slotIndex, data.url);
            observacoesLinksBySlot[slotIndex] = {
                id: Number(data.link && data.link.id ? data.link.id : 0),
                token: String(data.link && data.link.token ? data.link.token : ''),
                slot_index: slotIndex,
                form_title: formTitle,
                form_schema: schemaForPortal,
                submitted_at: data.link && data.link.submitted_at ? String(data.link.submitted_at) : null
            };
            if (!data.created) {
                alert('Link já existente recuperado');
            }
            updateObservacoesShareAvailability(slotIndex);
        } else {
            alert(data.error || 'Erro ao gerar link');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

function copiarLinkObservacoes(slot = 1) {
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !observacoesSlotExists(slotIndex)) {
        alert('Quadro inválido.');
        return;
    }
    const input = document.getElementById(`obsClienteLinkInput-${slotIndex}`);
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

async function destravarObservacoesSlot(slot = 1) {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !observacoesSlotExists(slotIndex)) {
        alert('Quadro inválido.');
        return;
    }
    if (!confirm(`Destravar o quadro ${slotIndex} permite que o cliente edite e reenvie. Continuar?`)) return;
    try {
        const formData = new FormData();
        formData.append('action', 'destravar_observacoes_slot');
        formData.append('meeting_id', String(meetingId));
        formData.append('slot_index', String(slotIndex));
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp, 'o destravamento do quadro');
        if (data.ok) {
            location.reload();
        } else {
            alert(data.error || 'Erro ao destravar quadro');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

function initObservacoesClientTemplateSelection() {
    observacoesSlotOrder = [];
    selectedObservacoesTemplateIds = {};
    observacoesLinksBySlot = {};

    if (Array.isArray(initialObservacoesClientLinks)) {
        initialObservacoesClientLinks.forEach((link) => {
            const slot = normalizeSlotIndex(link && link.slot_index ? link.slot_index : 1);
            if (slot === null) return;
            if (!link || !link.token) return;
            if (!observacoesSlotExists(slot)) {
                observacoesSlotOrder.push(slot);
            }
            ensureObservacoesSlotState(slot);
            observacoesLinksBySlot[slot] = link;

            const schema = normalizeFormSchema(Array.isArray(link.form_schema) ? link.form_schema : []);
            if (hasUsefulSchemaFields(schema)) {
                const signature = getSchemaSignature(schema);
                const templateId = findTemplateIdBySchemaSignature(signature);
                if (templateId) {
                    selectedObservacoesTemplateIds[slot] = templateId;
                }
            }
        });
    }

    observacoesSlotOrder = getSortedObservacoesSlots();
    renderObservacoesClientSlots();
}

function getSortedFormularioSlots() {
    return formularioSlotOrder
        .map((slot) => normalizeSlotIndex(slot))
        .filter((slot) => slot !== null)
        .sort((a, b) => a - b);
}

function formularioSlotExists(slot) {
    const normalized = normalizeSlotIndex(slot);
    if (normalized === null) return false;
    return formularioSlotOrder.includes(normalized);
}

function ensureFormularioSlotState(slot) {
    if (!Object.prototype.hasOwnProperty.call(selectedFormularioTemplateIds, slot)) {
        selectedFormularioTemplateIds[slot] = null;
    }
    if (!Object.prototype.hasOwnProperty.call(formularioLinksBySlot, slot)) {
        formularioLinksBySlot[slot] = null;
    }
}

function findNextFormularioSlotIndex() {
    const used = new Set(getSortedFormularioSlots());
    for (let slot = DJ_SLOT_MIN; slot <= DJ_SLOT_MAX; slot += 1) {
        if (!used.has(slot)) return slot;
    }
    return null;
}

function getFormularioPublicUrl(slot) {
    const link = formularioLinksBySlot[slot] || null;
    if (!link || !link.token) {
        return '';
    }
    const base = String(clientPortalBaseUrl || window.location.origin || '').replace(/\/+$/, '');
    return `${base}/index.php?page=eventos_cliente_dj&token=${encodeURIComponent(String(link.token))}`;
}

function abrirFormularioPublico(slot) {
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !formularioSlotExists(slotIndex)) {
        alert('Formulário inválido.');
        return;
    }
    const url = getFormularioPublicUrl(slotIndex);
    if (!url) {
        alert('Nenhum link público disponível para este formulário.');
        return;
    }
    window.open(url, '_blank');
}

function buildFormularioSlotCardHtml(slot) {
    const link = formularioLinksBySlot[slot] || null;
    const portalVisibleChecked = link && link.portal_visible ? ' checked' : '';
    const portalEditableChecked = link && link.portal_editable ? ' checked' : '';
    const disabledAttr = pageReadonly ? ' disabled' : '';
    const destravarBtnHtml = pageReadonly
        ? ''
        : `<button type="button" class="btn btn-secondary" onclick="destravarFormularioSlot(${slot})" id="formularioBtnDestravar-${slot}" style="display:none;">🔓 Destravar</button>`;
    const excluirBtnHtml = pageReadonly
        ? ''
        : `<button type="button" class="btn btn-secondary btn-slot-remove" onclick="excluirFormularioSlot(${slot})">🗑 Excluir formulário</button>`;
    const statusHtml = buildFormularioSlotResponseStatusHtml(slot);
    return `
        <div class="dj-builder-shell" data-formulario-slot="${slot}">
            <div class="dj-builder-head">
                <div>
                    <h4 class="dj-builder-title">📋 Formulário • Quadro ${slot}</h4>
                    <div class="dj-builder-subtitle">Escolha um formulário da biblioteca e configure a liberação para o portal do cliente.</div>
                </div>
                <div class="dj-top-actions">
                    ${destravarBtnHtml}
                    ${excluirBtnHtml}
                </div>
            </div>
            <div class="prefill-field" style="margin-top: 0.5rem;">
                <label for="formularioTemplateSelect-${slot}">Formulário salvo</label>
                <select id="formularioTemplateSelect-${slot}" onchange="onChangeFormularioTemplateSelect(${slot})"${disabledAttr}>
                    <option value="">Selecione um formulário...</option>
                </select>
            </div>
            <div class="builder-field-meta" id="formularioSelectedTemplateMeta-${slot}" style="margin-top: 0.55rem;">Nenhum formulário selecionado.</div>
            <div class="portal-settings">
                <label class="portal-settings-label" for="formularioPortalVisible-${slot}">
                    <input type="checkbox" id="formularioPortalVisible-${slot}" onchange="onChangeFormularioPortalVisibility(${slot})"${portalVisibleChecked}${disabledAttr}>
                    Exibir este formulário no Portal do Cliente
                </label>
                <label class="portal-settings-label" for="formularioPortalEditable-${slot}">
                    <input type="checkbox" id="formularioPortalEditable-${slot}" onchange="onChangeFormularioPortalEditable(${slot})"${portalEditableChecked}${disabledAttr}>
                    Permitir preenchimento do cliente
                </label>
            </div>
            ${statusHtml}
            <p class="share-hint" id="formularioShareHint-${slot}">Selecione um formulário para configurar este quadro no portal.</p>
        </div>
    `;
}

function buildFormularioSlotResponseStatusHtml(slot) {
    const link = formularioLinksBySlot[slot] || null;
    if (!link) {
        return '';
    }

    const draftSavedAt = link.draft_saved_at ? formatDate(link.draft_saved_at) : '';
    const submittedAt = link.submitted_at ? formatDate(link.submitted_at) : '';
    const hasDraft = !!link.draft_saved_at;
    const hasSubmitted = !!link.submitted_at;
    const publicUrl = getFormularioPublicUrl(slot);
    const actions = [];

    if (publicUrl) {
        actions.push(`<button type="button" class="btn btn-secondary" onclick="abrirFormularioPublico(${slot})">↗ Abrir formulário</button>`);
    }
    if (hasDraft) {
        actions.push(`<button type="button" class="btn btn-secondary" onclick="abrirModalFormularioResposta(${slot}, 'draft')">👁 Ver rascunho</button>`);
    }
    if (hasSubmitted) {
        actions.push(`<button type="button" class="btn btn-secondary" onclick="abrirModalFormularioResposta(${slot}, 'submitted')">👁 Ver enviado</button>`);
    }

    if (!hasDraft && !hasSubmitted && !publicUrl) {
        return '';
    }

    const lines = [];
    if (publicUrl && !hasDraft && !hasSubmitted) {
        lines.push('<div><strong>Link ativo</strong><p>Aguardando preenchimento do cliente.</p></div>');
    }
    if (hasDraft) {
        lines.push(`<div><strong>Rascunho salvo</strong><p>${escapeHtmlForField(draftSavedAt)}</p></div>`);
    }
    if (hasSubmitted) {
        lines.push(`<div><strong>Formulário enviado</strong><p>${escapeHtmlForField(submittedAt)}</p></div>`);
    }

    return `
        <div class="slot-response-status">
            ${lines.join('')}
            ${actions.length ? `<div class="slot-response-actions">${actions.join('')}</div>` : ''}
        </div>
    `;
}

function renderFormularioSlots() {
    const container = document.getElementById('formularioSlotsContainer');
    const empty = document.getElementById('formularioSlotsEmptyState');
    if (!container || !empty) return;

    const slots = getSortedFormularioSlots();
    if (slots.length === 0) {
        container.innerHTML = '';
        empty.style.display = 'block';
        return;
    }

    empty.style.display = 'none';
    container.innerHTML = slots.map((slot) => buildFormularioSlotCardHtml(slot)).join('');
    slots.forEach((slot) => {
        ensureFormularioSlotState(slot);
        renderFormularioTemplateSelect(slot);
        syncFormularioPortalToggles(slot);
        updateFormularioShareAvailability(slot);
    });
}

function addFormularioSlot(preferredSlot = null) {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return null;
    }
    if (!meetingId) {
        alert('Crie a reunião antes de adicionar formulários.');
        return null;
    }
    const slot = preferredSlot !== null ? normalizeSlotIndex(preferredSlot) : findNextFormularioSlotIndex();
    if (slot === null) {
        alert('Limite de formulários atingido (máximo de 50).');
        return null;
    }
    if (!formularioSlotExists(slot)) {
        formularioSlotOrder.push(slot);
    }
    formularioSlotOrder = getSortedFormularioSlots();
    ensureFormularioSlotState(slot);
    renderFormularioSlots();
    const select = document.getElementById(`formularioTemplateSelect-${slot}`);
    if (select) select.focus();
    return slot;
}

async function excluirFormularioSlot(slot = 1) {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !formularioSlotExists(slotIndex)) return;
    if (!confirm(`Excluir o formulário ${slotIndex}?`)) return;

    const senhaConfirmacao = window.prompt('Digite sua senha para confirmar a exclusão deste formulário:');
    if (senhaConfirmacao === null) return;
    if (!String(senhaConfirmacao).trim()) {
        alert('Informe sua senha para concluir a exclusão.');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'excluir_formulario_slot');
        formData.append('meeting_id', String(meetingId));
        formData.append('slot_index', String(slotIndex));
        formData.append('confirm_password', String(senhaConfirmacao));
        const resp = await fetch(window.location.href, { method: 'POST', body: formData });
        const data = await parseJsonResponse(resp, 'a exclusão do formulário');
        if (!data.ok) {
            alert(data.error || 'Erro ao excluir formulário');
            return;
        }
    } catch (err) {
        alert('Erro: ' + err.message);
        return;
    }

    formularioSlotOrder = getSortedFormularioSlots().filter((item) => item !== slotIndex);
    delete selectedFormularioTemplateIds[slotIndex];
    delete formularioLinksBySlot[slotIndex];
    delete formularioPortalSaveInFlight[slotIndex];
    delete formularioPortalSavePending[slotIndex];
    renderFormularioSlots();
}

function getSelectedFormularioTemplateData(slot) {
    if (!formularioSlotExists(slot)) {
        return { template: null, schema: [] };
    }
    const templateId = Number(selectedFormularioTemplateIds[slot] || 0);
    if (templateId <= 0) {
        return { template: null, schema: [] };
    }
    const template = savedFormTemplates.find((item) => Number(item.id) === templateId) || null;
    const schema = normalizeFormSchema(template && Array.isArray(template.schema) ? template.schema : []);
    return { template, schema };
}

function isFormularioSlotLocked(slot) {
    const link = formularioLinksBySlot[slot] || null;
    return !!(link && link.submitted_at);
}

function syncFormularioPortalToggles(slot = 1) {
    const visibleInput = document.getElementById(`formularioPortalVisible-${slot}`);
    const editableInput = document.getElementById(`formularioPortalEditable-${slot}`);
    if (!visibleInput || !editableInput) return;
    if (editableInput.checked && !visibleInput.checked) {
        visibleInput.checked = true;
    }
}

function onChangeFormularioPortalVisibility(slot = 1) {
    if (pageReadonly) return;
    const visibleInput = document.getElementById(`formularioPortalVisible-${slot}`);
    const editableInput = document.getElementById(`formularioPortalEditable-${slot}`);
    if (visibleInput && editableInput && editableInput.checked && !visibleInput.checked) {
        visibleInput.checked = true;
    }
    updateFormularioShareAvailability(slot);
    requestFormularioSlotPortalAutoSave(slot);
}

function onChangeFormularioPortalEditable(slot = 1) {
    if (pageReadonly) return;
    syncFormularioPortalToggles(slot);
    updateFormularioShareAvailability(slot);
    requestFormularioSlotPortalAutoSave(slot);
}

function requestFormularioSlotPortalAutoSave(slot = 1) {
    if (pageReadonly) return;
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !formularioSlotExists(slotIndex)) return;
    void salvarFormularioSlotPortalConfig(slotIndex, {
        silentSuccess: true,
        suppressValidationAlert: true,
    });
}

function updateFormularioShareAvailability(slot = 1) {
    const hint = document.getElementById(`formularioShareHint-${slot}`);
    const visibleInput = document.getElementById(`formularioPortalVisible-${slot}`);
    const editableInput = document.getElementById(`formularioPortalEditable-${slot}`);
    const select = document.getElementById(`formularioTemplateSelect-${slot}`);
    const unlockBtn = document.getElementById(`formularioBtnDestravar-${slot}`);

    if (pageReadonly) {
        if (select) select.disabled = true;
        if (visibleInput) visibleInput.disabled = true;
        if (editableInput) editableInput.disabled = true;
        if (unlockBtn) unlockBtn.style.display = 'none';
        if (hint) hint.textContent = 'Modo somente leitura.';
        return;
    }

    let disabled = false;
    let hintText = 'Selecione um formulário para configurar este quadro no portal.';

    syncFormularioPortalToggles(slot);

    if (isFormularioSlotLocked(slot)) {
        hintText = 'Cliente já enviou este formulário. Clique em "Destravar" para permitir novo preenchimento.';
        if (select) select.disabled = true;
        if (unlockBtn) unlockBtn.style.display = 'inline-flex';
    } else {
        if (select) select.disabled = false;
        if (unlockBtn) unlockBtn.style.display = 'none';
    }

    const selected = getSelectedFormularioTemplateData(slot);
    if (!selected.template) {
        disabled = true;
    } else if (!hasUsefulSchemaFields(selected.schema)) {
        disabled = true;
        hintText = 'O formulário selecionado não possui campos válidos.';
    } else if (visibleInput && editableInput) {
        if (!visibleInput.checked && !editableInput.checked) {
            hintText = 'Formulário oculto do portal do cliente.';
        } else if (visibleInput.checked && !editableInput.checked) {
            hintText = 'Formulário visível no portal em modo somente leitura.';
        } else if (visibleInput.checked && editableInput.checked) {
            hintText = 'Formulário visível no portal com preenchimento liberado para o cliente.';
        }
    }

    if (visibleInput) visibleInput.disabled = disabled;
    if (editableInput) editableInput.disabled = disabled;
    if (hint) hint.textContent = hintText;
}

function renderFormularioTemplateSelect(slot) {
    const select = document.getElementById(`formularioTemplateSelect-${slot}`);
    if (!select) return;
    const current = selectedFormularioTemplateIds[slot] ? String(selectedFormularioTemplateIds[slot]) : '';
    const options = ['<option value="">Selecione um formulário...</option>'];
    (savedFormTemplates || []).forEach((template) => {
        const id = Number(template.id || 0);
        if (!id) return;
        const label = `${String(template.nome || 'Modelo sem nome')} - ${String(template.categoria || 'geral')}`;
        const selected = String(id) === current ? ' selected' : '';
        options.push(`<option value="${id}"${selected}>${escapeHtmlForField(label)}</option>`);
    });
    select.innerHTML = options.join('');
    select.disabled = !!pageReadonly;
    updateSelectedFormularioTemplateMeta(slot);
}

function updateSelectedFormularioTemplateMeta(slot) {
    const meta = document.getElementById(`formularioSelectedTemplateMeta-${slot}`);
    if (!meta) return;
    const templateId = Number(selectedFormularioTemplateIds[slot] || 0);
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

function onChangeFormularioTemplateSelect(slot) {
    if (pageReadonly) return;
    const select = document.getElementById(`formularioTemplateSelect-${slot}`);
    selectedFormularioTemplateIds[slot] = select && select.value ? Number(select.value) : null;
    updateSelectedFormularioTemplateMeta(slot);
    updateFormularioShareAvailability(slot);
    requestFormularioSlotPortalAutoSave(slot);
}

async function salvarFormularioSlotPortalConfig(slot = 1, options = {}) {
    if (pageReadonly) {
        return false;
    }
    const slotIndex = normalizeSlotIndex(slot);
    const silentSuccess = !!(options && options.silentSuccess);
    const suppressValidationAlert = !!(options && options.suppressValidationAlert);
    if (slotIndex === null || !formularioSlotExists(slotIndex)) {
        if (!suppressValidationAlert) {
            alert('Formulário inválido.');
        }
        return false;
    }
    updateFormularioShareAvailability(slotIndex);
    if (formularioPortalSaveInFlight[slotIndex]) {
        formularioPortalSavePending[slotIndex] = true;
        return false;
    }

    try {
        const selected = getSelectedFormularioTemplateData(slotIndex);
        if (!selected.template) {
            if (!suppressValidationAlert) {
                alert('Selecione um formulário antes de salvar as regras.');
            }
            return false;
        }
        if (!hasUsefulSchemaFields(selected.schema)) {
            if (!suppressValidationAlert) {
                alert('O formulário selecionado não possui campos válidos.');
            }
            return false;
        }

        const visibleInput = document.getElementById(`formularioPortalVisible-${slotIndex}`);
        const editableInput = document.getElementById(`formularioPortalEditable-${slotIndex}`);
        if (!visibleInput || !editableInput || visibleInput.disabled || editableInput.disabled) {
            if (!suppressValidationAlert) {
                const hint = document.getElementById(`formularioShareHint-${slotIndex}`);
                alert(hint ? hint.textContent : 'Selecione um formulário válido para configurar o portal.');
            }
            return false;
        }

        let portalVisible = !!visibleInput.checked;
        const portalEditable = !!editableInput.checked;
        if (portalEditable && !portalVisible) {
            portalVisible = true;
            visibleInput.checked = true;
        }

        const formTitle = String(selected.template.nome || `Formulário do Evento - Quadro ${slotIndex}`);
        const schemaForPortal = normalizeFormSchema(selected.schema);
        const contentHtml = buildSchemaHtmlForStorage(schemaForPortal, formTitle);

        const formData = new FormData();
        formData.append('action', 'atualizar_formulario_slot_portal_config');
        formData.append('meeting_id', String(meetingId));
        formData.append('slot_index', String(slotIndex));
        formData.append('portal_visible', portalVisible ? '1' : '0');
        formData.append('portal_editable', portalEditable ? '1' : '0');
        formData.append('form_schema_json', JSON.stringify(schemaForPortal));
        formData.append('content_html', contentHtml);
        formData.append('form_title', formTitle);
        formularioPortalSaveInFlight[slotIndex] = true;

        try {
            const resp = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const data = await parseJsonResponse(resp, 'a configuração do formulário');
            if (data.ok) {
                const link = data.link && typeof data.link === 'object' ? data.link : null;
                if (link) {
                    const existingLink = formularioLinksBySlot[slotIndex] || {};
                    formularioLinksBySlot[slotIndex] = {
                        id: Number(link.id || 0),
                        token: String(link.token || ''),
                        is_active: !('is_active' in link) ? true : !!link.is_active,
                        slot_index: Number(link.slot_index || slotIndex),
                        form_title: String(link.form_title || formTitle),
                        form_schema: Array.isArray(link.form_schema) ? normalizeFormSchema(link.form_schema) : schemaForPortal,
                        submitted_at: link.submitted_at ? String(link.submitted_at) : null,
                        draft_saved_at: link.draft_saved_at ? String(link.draft_saved_at) : null,
                        portal_visible: !!link.portal_visible,
                        portal_editable: !!link.portal_editable,
                        portal_configured: !!link.portal_configured,
                        draft_preview_text: link.draft_saved_at
                            ? (typeof link.draft_preview_text === 'string' ? link.draft_preview_text : String(existingLink.draft_preview_text || ''))
                            : '',
                        submitted_preview_text: link.submitted_at
                            ? (typeof link.submitted_preview_text === 'string' ? link.submitted_preview_text : String(existingLink.submitted_preview_text || ''))
                            : '',
                        draft_attachments: Array.isArray(link.draft_attachments) ? link.draft_attachments : (Array.isArray(existingLink.draft_attachments) ? existingLink.draft_attachments : []),
                        submitted_attachments: Array.isArray(link.submitted_attachments) ? link.submitted_attachments : (Array.isArray(existingLink.submitted_attachments) ? existingLink.submitted_attachments : [])
                    };
                } else {
                    formularioLinksBySlot[slotIndex] = null;
                }
                updateFormularioShareAvailability(slotIndex);
                if (!silentSuccess) {
                    alert('Configuração do formulário salva para o portal.');
                }
                return true;
            }
            alert(data.error || 'Erro ao salvar configuração do formulário');
            return false;
        } finally {
            formularioPortalSaveInFlight[slotIndex] = false;
            updateFormularioShareAvailability(slotIndex);
            if (formularioPortalSavePending[slotIndex]) {
                formularioPortalSavePending[slotIndex] = false;
                void salvarFormularioSlotPortalConfig(slotIndex, {
                    silentSuccess: true,
                    suppressValidationAlert: true,
                });
            }
        }
    } catch (err) {
        alert('Erro: ' + err.message);
        return false;
    }
}

async function destravarFormularioSlot(slot = 1) {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null || !formularioSlotExists(slotIndex)) {
        alert('Formulário inválido.');
        return;
    }
    if (!confirm(`Destravar o formulário ${slotIndex} permite que o cliente preencha novamente. Continuar?`)) return;

    try {
        const formData = new FormData();
        formData.append('action', 'destravar_formulario_slot');
        formData.append('meeting_id', String(meetingId));
        formData.append('slot_index', String(slotIndex));

        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await parseJsonResponse(resp, 'o destravamento do formulário');
        if (data.ok) {
            location.reload();
        } else {
            alert(data.error || 'Erro ao destravar formulário');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

function initFormularioTemplateSelection() {
    formularioSlotOrder = [];
    selectedFormularioTemplateIds = {};
    formularioLinksBySlot = {};
    formularioPortalSaveInFlight = {};
    formularioPortalSavePending = {};

    if (Array.isArray(initialFormularioLinks)) {
        initialFormularioLinks.forEach((link) => {
            const slot = normalizeSlotIndex(link && link.slot_index ? link.slot_index : 1);
            if (slot === null || !link || !link.token) return;
            if (!formularioSlotExists(slot)) {
                formularioSlotOrder.push(slot);
            }
            ensureFormularioSlotState(slot);
            formularioLinksBySlot[slot] = link;

            const schema = normalizeFormSchema(Array.isArray(link.form_schema) ? link.form_schema : []);
            if (hasUsefulSchemaFields(schema)) {
                const signature = getSchemaSignature(schema);
                const templateId = findTemplateIdBySchemaSignature(signature);
                if (templateId) {
                    selectedFormularioTemplateIds[slot] = templateId;
                }
            }
        });
    }

    formularioSlotOrder = getSortedFormularioSlots();
    renderFormularioSlots();
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
    const disabledAttr = (sectionLockedState[section] || pageReadonly) ? ' disabled' : '';
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
            const noteHtml = getFieldNoteHtml(field);
            return `<div class="section-form-note">${noteHtml}</div>`;
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
        hint.textContent = (sectionLockedState[section] || pageReadonly)
            ? 'Seção em modo consulta. Edição desabilitada.'
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
    if (pageReadonly) return;
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
            const noteHtml = getFieldNoteHtml(field);
            if (noteHtml !== '') {
                parts.push(`<div>${noteHtml}</div>`);
            }
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
    const data = await parseJsonResponse(resp, 'a listagem de modelos');
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
    getSortedObservacoesSlots().forEach((slot) => {
        const templateId = selectedObservacoesTemplateIds[slot] || null;
        if (!templateId) return;
        const exists = savedFormTemplates.some((item) => Number(item.id) === Number(templateId));
        if (!exists) {
            selectedObservacoesTemplateIds[slot] = null;
        }
    });
    getSortedFormularioSlots().forEach((slot) => {
        const templateId = selectedFormularioTemplateIds[slot] || null;
        if (!templateId) return;
        const exists = savedFormTemplates.some((item) => Number(item.id) === Number(templateId));
        if (!exists) {
            selectedFormularioTemplateIds[slot] = null;
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
    renderObservacoesClientSlots();
    renderFormularioSlots();
    renderAllSectionTemplateSelects();
}

async function refreshDjTemplates() {
    try {
        await fetchTemplates();
    } catch (err) {
        console.error(err);
    }
    renderAllDjTemplateSelects();
    renderFormularioSlots();
}

function initDjTemplateSelection() {
    djSlotOrder = [];
    selectedDjTemplateIds = {};
    lastSavedDjSchemaSignatures = {};
    djLinksBySlot = {};
    djPortalSaveInFlight = {};
    djPortalSavePending = {};

    if (Array.isArray(initialDjLinks)) {
        initialDjLinks.forEach((link) => {
            const slot = normalizeSlotIndex(link && link.slot_index ? link.slot_index : 1);
            if (slot === null) return;
            if (!link || !link.token) return;
            if (djSlotExists(slot) && djLinksBySlot[slot]) return;

            const schema = normalizeFormSchema(Array.isArray(link.form_schema) ? link.form_schema : []);
            const formTitle = String(link.form_title || '').trim().toLowerCase();
            const hasUsefulNonLegacyFields = schema.some((field) => {
                const type = String(field && field.type ? field.type : '').toLowerCase();
                const label = String(field && field.label ? field.label : '').trim();
                const fieldId = String(field && field.id ? field.id : '').trim();
                if (!['text', 'textarea', 'yesno', 'select', 'file'].includes(type)) {
                    return false;
                }
                if (label === '') {
                    return false;
                }
                if (fieldId.startsWith('legacy_portal_text_')) {
                    return false;
                }
                return true;
            });
            const isDirectTextFallback = !hasUsefulNonLegacyFields
                && slot === 1
                && formTitle === 'dj / protocolos';
            if (isDirectTextFallback) {
                return;
            }

            if (!djSlotExists(slot)) {
                djSlotOrder.push(slot);
            }
            ensureDjSlotState(slot);
            djLinksBySlot[slot] = link;

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
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
    let content = getEditorContent(section);
    let formSchemaJson = null;

    if (section === 'decoracao') {
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
    if (section === 'observacoes_gerais') {
        content = buildObservacoesBlocksContent();
        setEditorContent(content, section);
        formSchemaJson = JSON.stringify([]);
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
        const legacyPortalInput = document.getElementById(`legacyPortalVisible-${section}`);
        if (legacyPortalInput) {
            formData.append('legacy_text_portal_visible', legacyPortalInput.checked ? '1' : '0');
        }
        
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        const data = await parseJsonResponse(resp, 'o salvamento da seção');
        
        if (data.ok) {
            alert('Salvo com sucesso! Versão #' + data.version);
        } else {
            alert(data.error || 'Erro ao salvar');
        }
    } catch (err) {
        alert('Erro: ' + err.message);
    }
}

// Salvar visibilidade/edição do quadro DJ no portal do cliente
async function salvarDjSlotPortalConfig(slot = 1, options = {}) {
    if (pageReadonly) {
        return false;
    }
    const slotIndex = normalizeSlotIndex(slot);
    const silentSuccess = !!(options && options.silentSuccess);
    const suppressValidationAlert = !!(options && options.suppressValidationAlert);
    if (slotIndex === null || !djSlotExists(slotIndex)) {
        if (!suppressValidationAlert) {
            alert('Quadro inválido.');
        }
        return false;
    }
    updateShareAvailability(slotIndex);
    if (djPortalSaveInFlight[slotIndex]) {
        djPortalSavePending[slotIndex] = true;
        return false;
    }

    try {
        const selected = getSelectedDjTemplateData(slotIndex);
        if (!selected.template) {
            if (!suppressValidationAlert) {
                alert('Selecione um formulário antes de salvar as regras.');
            }
            return false;
        }
        if (!hasUsefulSchemaFields(selected.schema)) {
            if (!suppressValidationAlert) {
                alert('O formulário selecionado não possui campos válidos.');
            }
            return false;
        }

        const visibleInput = document.getElementById(`djPortalVisible-${slotIndex}`);
        const editableInput = document.getElementById(`djPortalEditable-${slotIndex}`);
        if (!visibleInput || !editableInput || visibleInput.disabled || editableInput.disabled) {
            if (!suppressValidationAlert) {
                const hint = document.getElementById(`shareHint-${slotIndex}`);
                alert(hint ? hint.textContent : 'Selecione um formulário válido para configurar o portal.');
            }
            return false;
        }

        let portalVisible = !!(visibleInput && visibleInput.checked);
        const portalEditable = !!editableInput.checked;
        if (portalEditable && !portalVisible) {
            portalVisible = true;
            visibleInput.checked = true;
        }

        const formTitle = String(selected.template.nome || `Formulário DJ / Protocolos - Quadro ${slotIndex}`);
        const schemaForPortal = buildPortalSchemaWithLegacyText('dj_protocolo', selected.schema);
        const contentHtml = buildSchemaHtmlForStorage(schemaForPortal, formTitle);

        const formData = new FormData();
        formData.append('action', 'atualizar_dj_slot_portal_config');
        formData.append('meeting_id', String(meetingId));
        formData.append('slot_index', String(slotIndex));
        formData.append('portal_visible', portalVisible ? '1' : '0');
        formData.append('portal_editable', portalEditable ? '1' : '0');
        formData.append('form_schema_json', JSON.stringify(schemaForPortal));
        formData.append('content_html', contentHtml);
        formData.append('form_title', formTitle);
        djPortalSaveInFlight[slotIndex] = true;
        
        try {
            const resp = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });
            const data = await parseJsonResponse(resp, 'a configuração do portal');

            if (data.ok) {
                const link = data.link && typeof data.link === 'object' ? data.link : null;
                if (link) {
                    const existingLink = djLinksBySlot[slotIndex] || {};
                    djLinksBySlot[slotIndex] = {
                        id: Number(link.id || 0),
                        token: String(link.token || ''),
                        is_active: !('is_active' in link) ? true : !!link.is_active,
                        slot_index: Number(link.slot_index || slotIndex),
                        form_title: String(link.form_title || formTitle),
                        form_schema: Array.isArray(link.form_schema) ? normalizeFormSchema(link.form_schema) : schemaForPortal,
                        submitted_at: link.submitted_at ? String(link.submitted_at) : null,
                        draft_saved_at: link.draft_saved_at ? String(link.draft_saved_at) : null,
                        portal_visible: !!link.portal_visible,
                        portal_editable: !!link.portal_editable,
                        portal_configured: !!link.portal_configured,
                        draft_preview_text: link.draft_saved_at
                            ? (typeof link.draft_preview_text === 'string' ? link.draft_preview_text : String(existingLink.draft_preview_text || ''))
                            : '',
                        submitted_preview_text: link.submitted_at
                            ? (typeof link.submitted_preview_text === 'string' ? link.submitted_preview_text : String(existingLink.submitted_preview_text || ''))
                            : '',
                        draft_attachments: Array.isArray(link.draft_attachments) ? link.draft_attachments : (Array.isArray(existingLink.draft_attachments) ? existingLink.draft_attachments : []),
                        submitted_attachments: Array.isArray(link.submitted_attachments) ? link.submitted_attachments : (Array.isArray(existingLink.submitted_attachments) ? existingLink.submitted_attachments : [])
                    };
                } else {
                    djLinksBySlot[slotIndex] = null;
                }
                lastSavedDjSchemaSignatures[slotIndex] = getSchemaSignature(selected.schema);
                updateShareAvailability(slotIndex);
                if (!silentSuccess) {
                    alert('Configuração do portal salva para este quadro.');
                }
                return true;
            } else {
                alert(data.error || 'Erro ao salvar configuração do portal');
                return false;
            }
        } finally {
            djPortalSaveInFlight[slotIndex] = false;
            updateShareAvailability(slotIndex);
            if (djPortalSavePending[slotIndex]) {
                djPortalSavePending[slotIndex] = false;
                void salvarDjSlotPortalConfig(slotIndex, {
                    silentSuccess: true,
                    suppressValidationAlert: true,
                });
            }
        }
    } catch (err) {
        alert('Erro: ' + err.message);
        return false;
    }
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
        const data = await parseJsonResponse(resp, 'a consulta de versões');
        
        if (data.ok) {
            const container = document.getElementById('versoesContent');
            const title = document.getElementById('modalVersoesTitle');
            if (title) {
                title.textContent = '📋 Histórico de Versões';
            }
            
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

function abrirModalRespostaSlot(slot, responseType, sourceType = 'formulario') {
    const slotIndex = normalizeSlotIndex(slot);
    if (slotIndex === null) return;
    const source = String(sourceType || 'formulario').toLowerCase();
    const linksMap = source === 'dj' ? djLinksBySlot : formularioLinksBySlot;
    const sourceLabel = source === 'dj' ? 'Formulário DJ' : 'Formulário';
    const link = linksMap[slotIndex] || null;
    if (!link) return;

    const isDraft = responseType === 'draft';
    const previewText = isDraft
        ? String(link.draft_preview_text || '')
        : String(link.submitted_preview_text || '');
    const savedAt = isDraft
        ? (link.draft_saved_at ? formatDate(link.draft_saved_at) : '')
        : (link.submitted_at ? formatDate(link.submitted_at) : '');
    const attachments = isDraft
        ? (Array.isArray(link.draft_attachments) ? link.draft_attachments : [])
        : (Array.isArray(link.submitted_attachments) ? link.submitted_attachments : []);

    if (previewText.trim() === '' && attachments.length === 0) {
        alert(isDraft ? 'Nenhum rascunho salvo neste formulário.' : 'Nenhum envio concluído neste formulário.');
        return;
    }

    const title = document.getElementById('modalVersoesTitle');
    const container = document.getElementById('versoesContent');
    if (!container) return;

    if (title) {
        title.textContent = isDraft
            ? `📋 ${sourceLabel} ${slotIndex} • Rascunho`
            : `📋 ${sourceLabel} ${slotIndex} • Enviado`;
    }

    const statusLabel = isDraft ? 'Rascunho salvo em' : 'Enviado em';
    const attachmentsHtml = attachments.length ? `
        <div style="margin-top: 1rem;">
            <h4 style="margin: 0 0 0.6rem 0;">Anexos ${isDraft ? 'do rascunho' : 'enviados'}</h4>
            <ul style="margin:0; padding-left:1.1rem; color:#334155;">
                ${attachments.map((anexo) => {
                    const name = escapeHtmlForField(String(anexo && anexo.original_name ? anexo.original_name : 'arquivo'));
                    const url = String(anexo && anexo.public_url ? anexo.public_url : '').trim();
                    const note = escapeHtmlForField(String(anexo && anexo.note ? anexo.note : ''));
                    const uploadedAt = anexo && anexo.uploaded_at ? formatDate(anexo.uploaded_at) : '-';
                    const linkHtml = url !== ''
                        ? `<a href="${escapeHtmlForField(url)}" target="_blank" rel="noopener noreferrer">${name}</a>`
                        : name;
                    return `<li style="margin-bottom:0.55rem;">${linkHtml}<div style="font-size:0.8rem; color:#64748b;">${escapeHtmlForField(uploadedAt)}${note !== '' ? ` • Obs: ${note}` : ''}</div></li>`;
                }).join('')}
            </ul>
        </div>
    ` : '';
    container.innerHTML = `
        <div class="version-item active">
            <div class="version-header">
                <span class="version-number">${isDraft ? 'Rascunho' : 'Concluído / Enviado'}</span>
                <span class="version-meta">${escapeHtmlForField(statusLabel)} ${escapeHtmlForField(savedAt || '-')}</span>
            </div>
            <div class="version-note" style="white-space: pre-wrap; line-height: 1.55;">${previewText.trim() !== '' ? escapeHtmlForField(previewText) : '<em>Sem conteúdo textual.</em>'}</div>
            ${attachmentsHtml}
        </div>
    `;

    document.getElementById('modalVersoes').classList.add('show');
}

function abrirModalFormularioResposta(slot, responseType) {
    abrirModalRespostaSlot(slot, responseType, 'formulario');
}

function abrirModalDjResposta(slot, responseType) {
    abrirModalRespostaSlot(slot, responseType, 'dj');
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
        const data = await parseJsonResponse(resp, 'a restauração da versão');
        
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
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
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
        const data = await parseJsonResponse(resp, 'o destravamento da seção');
        
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
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
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
        const data = await parseJsonResponse(resp, 'o destravamento do quadro');

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
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
    if (!confirm('Marcar reunião como concluída?')) return;
    await atualizarStatus('concluida');
}

async function reabrirReuniao() {
    if (pageReadonly) {
        alert('Modo somente leitura.');
        return;
    }
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
        const data = await parseJsonResponse(resp, 'a atualização de status');
        
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

function exposeInlineHandlersToWindow() {
    if (typeof searchEvents === 'function') window.searchEvents = searchEvents;
    if (typeof criarReuniao === 'function') window.criarReuniao = criarReuniao;
    if (typeof concluirReuniao === 'function') window.concluirReuniao = concluirReuniao;
    if (typeof reabrirReuniao === 'function') window.reabrirReuniao = reabrirReuniao;
    if (typeof abrirModalImpressao === 'function') window.abrirModalImpressao = abrirModalImpressao;
    if (typeof fecharModalImpressao === 'function') window.fecharModalImpressao = fecharModalImpressao;
    if (typeof emitirDocumentoReuniao === 'function') window.emitirDocumentoReuniao = emitirDocumentoReuniao;
    if (typeof retryLoadTinyMCE === 'function') window.retryLoadTinyMCE = retryLoadTinyMCE;
    if (typeof selectEvent === 'function') window.selectEvent = selectEvent;

    if (typeof aplicarTemplateNaSecao === 'function') window.aplicarTemplateNaSecao = aplicarTemplateNaSecao;
    if (typeof onChangeSectionTemplateSelect === 'function') window.onChangeSectionTemplateSelect = onChangeSectionTemplateSelect;
    if (typeof toggleLegacyEditor === 'function') window.toggleLegacyEditor = toggleLegacyEditor;
    if (typeof toggleObservacoesBlock === 'function') window.toggleObservacoesBlock = toggleObservacoesBlock;
    if (typeof salvarSecao === 'function') window.salvarSecao = salvarSecao;
    if (typeof verVersoes === 'function') window.verVersoes = verVersoes;
    if (typeof fecharModal === 'function') window.fecharModal = fecharModal;
    if (typeof restaurarVersao === 'function') window.restaurarVersao = restaurarVersao;
    if (typeof destravarSecao === 'function') window.destravarSecao = destravarSecao;

    if (typeof addDjUploadCard === 'function') window.addDjUploadCard = addDjUploadCard;
    if (typeof removeDjUploadCard === 'function') window.removeDjUploadCard = removeDjUploadCard;
    if (typeof uploadDjAnexos === 'function') window.uploadDjAnexos = uploadDjAnexos;
    if (typeof excluirDjAnexo === 'function') window.excluirDjAnexo = excluirDjAnexo;
    if (typeof addDjSlot === 'function') window.addDjSlot = addDjSlot;
    if (typeof excluirDjSlot === 'function') window.excluirDjSlot = excluirDjSlot;
    if (typeof destravarDjSlot === 'function') window.destravarDjSlot = destravarDjSlot;
    if (typeof onChangeDjTemplateSelect === 'function') window.onChangeDjTemplateSelect = onChangeDjTemplateSelect;
    if (typeof onChangeDjPortalVisibility === 'function') window.onChangeDjPortalVisibility = onChangeDjPortalVisibility;
    if (typeof onChangeDjPortalEditable === 'function') window.onChangeDjPortalEditable = onChangeDjPortalEditable;
    if (typeof abrirDjFormularioPublico === 'function') window.abrirDjFormularioPublico = abrirDjFormularioPublico;
    if (typeof abrirModalDjResposta === 'function') window.abrirModalDjResposta = abrirModalDjResposta;

    if (typeof gerarLinkClienteObservacoes === 'function') window.gerarLinkClienteObservacoes = gerarLinkClienteObservacoes;
    if (typeof destravarObservacoesSlot === 'function') window.destravarObservacoesSlot = destravarObservacoesSlot;
    if (typeof excluirObservacoesSlot === 'function') window.excluirObservacoesSlot = excluirObservacoesSlot;
    if (typeof copiarLinkObservacoes === 'function') window.copiarLinkObservacoes = copiarLinkObservacoes;
    if (typeof onChangeObservacoesTemplateSelect === 'function') window.onChangeObservacoesTemplateSelect = onChangeObservacoesTemplateSelect;
    if (typeof adicionarLinhaCronograma === 'function') window.adicionarLinhaCronograma = adicionarLinhaCronograma;

    if (typeof addFormularioSlot === 'function') window.addFormularioSlot = addFormularioSlot;
    if (typeof excluirFormularioSlot === 'function') window.excluirFormularioSlot = excluirFormularioSlot;
    if (typeof destravarFormularioSlot === 'function') window.destravarFormularioSlot = destravarFormularioSlot;
    if (typeof onChangeFormularioTemplateSelect === 'function') window.onChangeFormularioTemplateSelect = onChangeFormularioTemplateSelect;
    if (typeof onChangeFormularioPortalVisibility === 'function') window.onChangeFormularioPortalVisibility = onChangeFormularioPortalVisibility;
    if (typeof onChangeFormularioPortalEditable === 'function') window.onChangeFormularioPortalEditable = onChangeFormularioPortalEditable;
    if (typeof abrirModalFormularioResposta === 'function') window.abrirModalFormularioResposta = abrirModalFormularioResposta;
}

exposeInlineHandlersToWindow();

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

function bindMeetingActionButtons() {
    const addDjBtn = document.getElementById('btnAddDjSlot');
    if (addDjBtn && addDjBtn.dataset.bound !== '1') {
        addDjBtn.dataset.bound = '1';
        addDjBtn.addEventListener('click', function () {
            addDjSlot();
        });
    }

    const addFormularioBtn = document.getElementById('btnAddFormularioSlot');
    if (addFormularioBtn && addFormularioBtn.dataset.bound !== '1') {
        addFormularioBtn.dataset.bound = '1';
        addFormularioBtn.addEventListener('click', function () {
            addFormularioSlot();
        });
    }
}

// Inicializar editores ricos quando existir reunião (carrega TinyMCE dinamicamente)
if (meetingId) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bindMeetingActionButtons();
            renderDjUploadCards();
            renderDjAnexosList();
            applyInitialTabFromQuery();
            hydrateObservacoesBlocksFromSavedContent();
            loadTinyMCEAndInit();
            initSectionTemplateSelection();
            initDjTemplateSelection();
            initObservacoesClientTemplateSelection();
            initFormularioTemplateSelection();
            refreshDjTemplates();
        });
    } else {
        bindMeetingActionButtons();
        renderDjUploadCards();
        renderDjAnexosList();
        applyInitialTabFromQuery();
        hydrateObservacoesBlocksFromSavedContent();
        loadTinyMCEAndInit();
        initSectionTemplateSelection();
        initDjTemplateSelection();
        initObservacoesClientTemplateSelection();
        initFormularioTemplateSelection();
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
