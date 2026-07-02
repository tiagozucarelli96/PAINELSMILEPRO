<?php
/**
 * eventos_documentos.php
 * Contratos e documentos gerais do evento.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/clicksign_helper.php';
require_once __DIR__ . '/eventos_documentos_helper.php';

if (empty($_SESSION['perm_agenda_eventos']) && empty($_SESSION['perm_superadmin'])) {
    echo '<div style="padding:24px;font-family:Arial,sans-serif;color:#991b1b;">Acesso negado.</div>';
    return;
}

eventos_documentos_ensure_schema($pdo);

$eventoId = (int)($_GET['evento_id'] ?? $_POST['evento_id'] ?? 0);
$evento = $eventoId > 0 ? eventos_documentos_evento($pdo, $eventoId) : null;
$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? 0);
$messages = [];
$errors = [];
$previewDocumento = null;

if (!$evento) {
    echo '<div style="padding:24px;font-family:Arial,sans-serif;color:#991b1b;">Evento não encontrado.</div>';
    return;
}

$usuarioAssinatura = eventos_documentos_usuario_assinatura($pdo, $userId);

if (!empty($_SESSION['eventos_documentos_message'])) {
    $messages[] = (string)$_SESSION['eventos_documentos_message'];
    unset($_SESSION['eventos_documentos_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'generate_documento') {
            $modeloId = (int)($_POST['modelo_id'] ?? 0);
            $modelo = $modeloId > 0 ? eventos_documentos_buscar_modelo($pdo, $modeloId) : null;
            if (!$modelo) {
                throw new RuntimeException('Selecione um modelo válido.');
            }
            $clienteNome = trim((string)($evento['cliente_nome'] ?? ''));
            $sufixo = $clienteNome !== '' ? $clienteNome : (string)($evento['nome_evento'] ?? 'Evento');
            $titulo = trim((string)$modelo['nome']) . ' - ' . $sufixo;
            $conteudo = eventos_documentos_renderizar_modelo(
                (string)($modelo['conteudo_html'] ?? ''),
                eventos_documentos_mapa_tags($pdo, $eventoId, $evento)
            );
            $previewDocumento = [
                'modelo_id' => $modeloId,
                'titulo' => $titulo,
                'conteudo_html' => $conteudo,
            ];
        }

        if ($action === 'confirm_generate_documento') {
            $modeloId = (int)($_POST['modelo_id'] ?? 0);
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $conteudo = (string)($_POST['conteudo_html'] ?? '');
            if ($modeloId <= 0 || !eventos_documentos_buscar_modelo($pdo, $modeloId)) {
                throw new RuntimeException('Modelo inválido.');
            }
            if ($titulo === '') {
                throw new RuntimeException('Informe um título válido.');
            }
            if (trim(strip_tags($conteudo)) === '' && trim($conteudo) === '') {
                throw new RuntimeException('O documento está vazio.');
            }
            eventos_documentos_criar($pdo, $eventoId, $modeloId, $titulo, $conteudo, $userId);
            $_SESSION['eventos_documentos_message'] = 'Documento emitido.';
            header('Location: index.php?page=eventos_documentos&evento_id=' . $eventoId);
            exit;
        }

        if ($action === 'save_documento') {
            $documentoId = (int)($_POST['documento_id'] ?? 0);
            $origem = (string)($_POST['origem'] ?? 'geral');
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $conteudo = (string)($_POST['conteudo_html'] ?? '');
            if ($documentoId <= 0 || $titulo === '') {
                throw new RuntimeException('Informe um título válido.');
            }
            if ($origem === 'formatura') {
                $stmt = $pdo->prepare("
                    UPDATE eventos_formatura_documentos
                    SET titulo = :titulo,
                        conteudo_html = :conteudo_html,
                        updated_at = NOW()
                    WHERE id = :id
                      AND evento_id = :evento_id
                      AND deleted_at IS NULL
                ");
                $stmt->execute([
                    ':titulo' => $titulo,
                    ':conteudo_html' => $conteudo,
                    ':id' => $documentoId,
                    ':evento_id' => $eventoId,
                ]);
            } else {
                eventos_documentos_atualizar($pdo, $eventoId, $documentoId, $titulo, $conteudo);
            }
            $_SESSION['eventos_documentos_message'] = 'Documento atualizado.';
            header('Location: index.php?page=eventos_documentos&evento_id=' . $eventoId);
            exit;
        }

        if ($action === 'delete_documento') {
            $documentoId = (int)($_POST['documento_id'] ?? 0);
            $origem = (string)($_POST['origem'] ?? 'geral');
            if ($documentoId <= 0) {
                throw new RuntimeException('Documento inválido.');
            }
            if ($origem === 'formatura') {
                $stmt = $pdo->prepare("
                    UPDATE eventos_formatura_documentos
                    SET deleted_at = NOW(),
                        updated_at = NOW()
                    WHERE id = :id
                      AND evento_id = :evento_id
                      AND deleted_at IS NULL
                ");
                $stmt->execute([':id' => $documentoId, ':evento_id' => $eventoId]);
            } else {
                eventos_documentos_excluir($pdo, $eventoId, $documentoId, $userId);
            }
            $_SESSION['eventos_documentos_message'] = 'Documento excluído.';
            header('Location: index.php?page=eventos_documentos&evento_id=' . $eventoId);
            exit;
        }

        if ($action === 'send_clicksign') {
            $documentoId = (int)($_POST['documento_id'] ?? 0);
            $origem = (string)($_POST['origem'] ?? 'geral');
            if ($documentoId <= 0) {
                throw new RuntimeException('Documento inválido para assinatura.');
            }
            $_SESSION['eventos_documentos_message'] = eventos_documentos_enviar_clicksign($pdo, $eventoId, $documentoId, $origem, $evento, $usuarioAssinatura);
            header('Location: index.php?page=eventos_documentos&evento_id=' . $eventoId);
            exit;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$modelos = eventos_documentos_modelos($pdo);
$documentosGerais = eventos_documentos_listar($pdo, $eventoId);
$documentosFormatura = eventos_documentos_listar_formatura($pdo, $eventoId);
$documentos = array_merge($documentosGerais, $documentosFormatura);
usort($documentos, static function (array $a, array $b): int {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});

function eventos_documentos_data_hora_br(?string $date): string
{
    $date = trim((string)$date);
    if ($date === '') {
        return '-';
    }
    $time = strtotime($date);
    return $time ? date('d/m/Y H:i', $time) : $date;
}

function eventos_documentos_filename_clicksign(string $titulo): string
{
    $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titulo);
    $base = is_string($base) ? $base : $titulo;
    $base = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $base), '-'));
    if ($base === '') {
        $base = 'documento';
    }
    return substr($base, 0, 90) . '.html';
}

function eventos_documentos_html_clicksign(string $titulo, string $conteudoHtml): string
{
    return '<!doctype html><html><head><meta charset="utf-8">'
        . '<title>' . eventos_documentos_e($titulo) . '</title>'
        . '<style>body{font-family:Arial,Helvetica,sans-serif;font-size:12pt;line-height:1.45;color:#111827;margin:32px;}table{border-collapse:collapse;}td,th{vertical-align:top;}</style>'
        . '</head><body>' . $conteudoHtml . '</body></html>';
}

function eventos_documentos_usuario_assinatura(PDO $pdo, int $userId): array
{
    if ($userId <= 0 || !eventos_documentos_table_exists($pdo, 'usuarios')) {
        return [];
    }
    $nomeExpr = eventos_documentos_column_exists($pdo, 'usuarios', 'nome') ? "COALESCE(NULLIF(TRIM(nome), ''), '')" : "''";
    $emailExpr = eventos_documentos_column_exists($pdo, 'usuarios', 'email') ? "COALESCE(NULLIF(TRIM(email), ''), '')" : "''";
    $telefoneParts = [];
    foreach (['celular', 'telefone', 'whatsapp'] as $column) {
        if (eventos_documentos_column_exists($pdo, 'usuarios', $column)) {
            $telefoneParts[] = "NULLIF(TRIM({$column}), '')";
        }
    }
    $telefoneExpr = $telefoneParts ? 'COALESCE(' . implode(', ', $telefoneParts) . ", '')" : "''";
    $stmt = $pdo->prepare("
        SELECT {$nomeExpr} AS nome,
               {$emailExpr} AS email,
               {$telefoneExpr} AS telefone
        FROM usuarios
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return is_array($row) ? $row : [];
}

function eventos_documentos_signatarios_documento(array $documento, string $origem, array $evento, array $usuario): array
{
    $signatarios = [];
    if ($origem === 'formatura') {
        $principal = [
            'tipo' => 'Responsável do formando',
            'name' => (string)($documento['signer_name'] ?? $documento['responsavel_nome'] ?? ''),
            'email' => (string)($documento['signer_email'] ?? $documento['responsavel_email'] ?? ''),
            'phone' => (string)($documento['signer_phone'] ?? $documento['responsavel_telefone'] ?? ''),
            'role' => 'sign',
            'group' => 1,
        ];
    } else {
        $principal = [
            'tipo' => 'Cliente',
            'name' => (string)($documento['signer_name'] ?? $evento['cliente_nome'] ?? ''),
            'email' => (string)($documento['signer_email'] ?? $evento['cliente_email'] ?? ''),
            'phone' => (string)($documento['signer_phone'] ?? $evento['cliente_telefone'] ?? ''),
            'role' => 'sign',
            'group' => 1,
        ];
    }
    $signatarios[] = $principal;

    $usuarioEmail = trim((string)($usuario['email'] ?? ''));
    if ($usuarioEmail !== '' && strcasecmp($usuarioEmail, trim((string)$principal['email'])) !== 0) {
        $signatarios[] = [
            'tipo' => 'Usuário do painel',
            'name' => (string)($usuario['nome'] ?? ''),
            'email' => $usuarioEmail,
            'phone' => (string)($usuario['telefone'] ?? ''),
            'role' => 'sign',
            'group' => 2,
        ];
    }

    return $signatarios;
}

function eventos_documentos_signatarios_clicksign_payload(array $documento): array
{
    $payload = $documento['clicksign_payload'] ?? null;
    if (is_string($payload) && trim($payload) !== '') {
        $decoded = json_decode($payload, true);
    } elseif (is_array($payload)) {
        $decoded = $payload;
    } else {
        $decoded = [];
    }
    if (!is_array($decoded)) {
        return [];
    }

    $summarySigners = $decoded['summary']['signers'] ?? $decoded['signers'] ?? [];
    $createdSigners = $decoded['signers'] ?? [];
    $byEmail = [];
    foreach ((array)$summarySigners as $signer) {
        if (!is_array($signer)) {
            continue;
        }
        $email = strtolower(trim((string)($signer['email'] ?? '')));
        if ($email === '') {
            continue;
        }
        $byEmail[$email] = [
            'status' => (string)($signer['status_label'] ?? $signer['status'] ?? ''),
            'url' => (string)($signer['signature_url'] ?? ''),
        ];
    }
    foreach ((array)$createdSigners as $signer) {
        if (!is_array($signer)) {
            continue;
        }
        $email = strtolower(trim((string)($signer['email'] ?? '')));
        if ($email === '') {
            continue;
        }
        $byEmail[$email]['name'] = (string)($signer['name'] ?? '');
    }
    return $byEmail;
}

function eventos_documentos_buscar_para_assinatura(PDO $pdo, int $eventoId, int $documentoId, string $origem, array $evento): ?array
{
    if ($origem === 'formatura') {
        if (!eventos_documentos_table_exists($pdo, 'eventos_formatura_documentos')) {
            return null;
        }
        eventos_documentos_ensure_clicksign_columns($pdo, 'eventos_formatura_documentos');
        $stmt = $pdo->prepare("
            SELECT d.*,
                   f.nome_formando,
                   COALESCE(NULLIF(TRIM(c.nome_completo), ''), '') AS signer_name,
                   COALESCE(NULLIF(TRIM(c.email), ''), '') AS signer_email,
                   '' AS signer_phone
            FROM eventos_formatura_documentos d
            LEFT JOIN eventos_formatura_formandos f ON f.id = d.formando_id
            LEFT JOIN comercial_cadastro_clientes c ON c.id = f.cliente_cadastro_id
            WHERE d.id = :id
              AND d.evento_id = :evento_id
              AND d.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $documentoId, ':evento_id' => $eventoId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($doc) ? $doc : null;
    }

    $stmt = $pdo->prepare("
        SELECT d.*,
               :signer_name AS signer_name,
               :signer_email AS signer_email,
               :signer_phone AS signer_phone
        FROM eventos_documentos d
        WHERE d.id = :id
          AND d.evento_id = :evento_id
          AND d.deleted_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $documentoId,
        ':evento_id' => $eventoId,
        ':signer_name' => (string)($evento['cliente_nome'] ?? ''),
        ':signer_email' => (string)($evento['cliente_email'] ?? ''),
        ':signer_phone' => (string)($evento['cliente_telefone'] ?? ''),
    ]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($doc) ? $doc : null;
}

function eventos_documentos_atualizar_assinatura(PDO $pdo, string $origem, int $documentoId, array $assinatura, int $totalSignatarios): void
{
    $table = $origem === 'formatura' ? 'eventos_formatura_documentos' : 'eventos_documentos';
    eventos_documentos_ensure_clicksign_columns($pdo, $table);
    $stmt = $pdo->prepare("
        UPDATE {$table}
        SET status = 'em_curso',
            status_assinatura = :status_assinatura,
            clicksign_envelope_id = :envelope_id,
            clicksign_document_id = :document_id,
            clicksign_signer_id = :signer_id,
            clicksign_sign_url = :sign_url,
            clicksign_payload = :payload::jsonb,
            clicksign_ultimo_erro = NULL,
            enviado_assinatura_em = NOW(),
            assinaturas_realizadas = 0,
            assinaturas_total = :assinaturas_total,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status_assinatura' => 'enviado',
        ':envelope_id' => $assinatura['envelope_id'] ?? null,
        ':document_id' => $assinatura['document_id'] ?? null,
        ':signer_id' => $assinatura['signer_id'] ?? null,
        ':sign_url' => $assinatura['sign_url'] ?? null,
        ':payload' => json_encode($assinatura['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':assinaturas_total' => max(1, $totalSignatarios),
        ':id' => $documentoId,
    ]);
}

function eventos_documentos_gravar_erro_assinatura(PDO $pdo, string $origem, int $documentoId, string $erro): void
{
    $table = $origem === 'formatura' ? 'eventos_formatura_documentos' : 'eventos_documentos';
    eventos_documentos_ensure_clicksign_columns($pdo, $table);
    $stmt = $pdo->prepare("
        UPDATE {$table}
        SET status_assinatura = 'erro',
            clicksign_ultimo_erro = :erro,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':erro' => $erro, ':id' => $documentoId]);
}

function eventos_documentos_enviar_clicksign(PDO $pdo, int $eventoId, int $documentoId, string $origem, array $evento, array $usuario): string
{
    $doc = eventos_documentos_buscar_para_assinatura($pdo, $eventoId, $documentoId, $origem, $evento);
    if (!$doc) {
        throw new RuntimeException('Documento não encontrado para assinatura.');
    }
    if (!empty($doc['clicksign_envelope_id'])) {
        return 'Este documento já possui uma solicitação de assinatura na Clicksign.';
    }

    $status = strtolower(trim((string)($doc['status'] ?? '')));
    if ($status !== 'minuta_aprovada') {
        throw new RuntimeException('A minuta precisa ser aprovada antes de enviar para assinatura.');
    }

    $signatarios = eventos_documentos_signatarios_documento($doc, $origem, $evento, $usuario);
    $signers = [];
    foreach ($signatarios as $index => $signatario) {
        $name = ClicksignHelper::normalizeSignerName((string)($signatario['name'] ?? ''));
        $email = trim((string)($signatario['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException((string)($signatario['tipo'] ?? 'Signatário') . ' precisa ter um e-mail válido para assinatura na Clicksign.');
        }
        $nameError = ClicksignHelper::getSignerNameValidationError($name);
        if ($nameError !== null) {
            throw new RuntimeException((string)($signatario['tipo'] ?? 'Signatário') . ': ' . $nameError);
        }
        $signers[] = [
            'name' => $name,
            'email' => $email,
            'group' => max(1, (int)($signatario['group'] ?? ($index + 1))),
            'role' => (string)($signatario['role'] ?? 'sign'),
            'auth' => 'email',
        ];
    }

    $clicksign = new ClicksignHelper();
    if (!$clicksign->isConfigured()) {
        throw new RuntimeException($clicksign->getConfigurationError());
    }

    $titulo = (string)($doc['titulo'] ?? 'Documento');
    $html = eventos_documentos_html_clicksign($titulo, (string)($doc['conteudo_html'] ?? ''));
    $res = $clicksign->criarFluxoAssinatura([
        'envelope_name' => $titulo . ' - ' . $signers[0]['name'],
        'filename' => eventos_documentos_filename_clicksign($titulo),
        'content_base64' => base64_encode($html),
        'content_type' => 'text/html',
        'signers' => $signers,
        'deadline_at' => (new DateTimeImmutable('+20 days'))->format(DateTimeInterface::RFC3339),
        'notification_message' => 'Olá! Você possui um documento pendente para assinatura no Grupo Smile.',
    ]);

    if (!($res['success'] ?? false)) {
        $erro = (string)($res['error'] ?? 'Erro desconhecido ao solicitar assinatura.');
        eventos_documentos_gravar_erro_assinatura($pdo, $origem, $documentoId, $erro);
        throw new RuntimeException($erro);
    }

    eventos_documentos_atualizar_assinatura($pdo, $origem, $documentoId, $res, count($signers));
    return !empty($res['notify_error'])
        ? 'Assinatura criada na Clicksign, mas houve falha no envio da notificação ao signatário.'
        : 'Assinatura enviada para a Clicksign.';
}
includeSidebar('Contratos e Documentos');
?>
    <style>
        body { margin:0; background:#f4f7fb; color:#26364d; font-family: Arial, sans-serif; }
        .doc-page { padding:28px clamp(18px, 4vw, 56px); }
        .doc-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:22px; }
        .doc-title h1 { margin:0; color:#17357a; font-size:30px; line-height:1.15; }
        .doc-title p { margin:6px 0 0; color:#6b7b91; font-weight:700; }
        .doc-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:42px; padding:0 18px; border-radius:9px; border:0; text-decoration:none; cursor:pointer; font-weight:800; font-size:14px; }
        .doc-btn--primary { background:#223f91; color:#fff; }
        .doc-btn--green { background:#20c77a; color:#fff; }
        .doc-btn--warning { background:#f4bd32; color:#fff; }
        .doc-btn--light { background:#fff; color:#23344e; border:1px solid #d5dfec; }
        .doc-card { background:#fff; border:1px solid #dbe5f0; border-radius:10px; box-shadow:0 16px 42px rgba(31,50,82,.08); overflow:hidden; }
        .doc-card-header { display:flex; justify-content:space-between; gap:14px; align-items:center; padding:18px 20px; border-bottom:1px solid #e3ebf4; }
        .doc-card-header h2 { margin:0; font-size:20px; color:#23344e; }
        .doc-toolbar { display:flex; gap:10px; flex-wrap:wrap; padding:18px 20px; }
        .doc-alert { padding:12px 14px; border-radius:9px; margin-bottom:12px; font-weight:700; }
        .doc-alert--ok { background:#dcfce7; color:#166534; border:1px solid #86efac; }
        .doc-alert--err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .doc-table-wrap { padding:0 20px 22px; overflow:auto; }
        .doc-table { width:100%; border-collapse:collapse; min-width:760px; }
        .doc-table th { background:#eef3f8; color:#4b5e77; font-size:12px; text-align:left; padding:13px; border:1px solid #dce5ef; }
        .doc-table td { padding:13px; border:1px solid #e4ebf3; vertical-align:middle; }
        .doc-muted { color:#76869b; font-size:12px; margin-top:4px; }
        .doc-chip { display:inline-flex; align-items:center; min-height:24px; padding:0 9px; border-radius:999px; background:#dbeafe; color:#1d4ed8; font-weight:800; font-size:12px; }
        .doc-chip--formatura { background:#ede9fe; color:#6d28d9; }
        .doc-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .doc-actions form { margin:0; }
        .doc-action { display:inline-flex; align-items:center; justify-content:center; gap:6px; min-height:34px; padding:0 11px; border-radius:8px; border:1px solid #d5dfec; background:#fff; color:#23344e; cursor:pointer; text-decoration:none; font-weight:800; font-size:12px; line-height:1; white-space:nowrap; }
        .doc-action:hover { transform:translateY(-1px); box-shadow:0 8px 18px rgba(31,50,82,.12); }
        .doc-action--info { background:#f8fafc; color:#334155; position:relative; }
        .doc-action--info[data-tooltip]::after {
            content: attr(data-tooltip);
            position:absolute;
            left:50%;
            bottom:calc(100% + 10px);
            transform:translateX(-50%);
            width:max-content;
            max-width:280px;
            white-space:pre-line;
            background:#0f172a;
            color:#fff;
            padding:10px 12px;
            border-radius:8px;
            box-shadow:0 14px 30px rgba(15,23,42,.22);
            font-size:12px;
            line-height:1.35;
            text-align:left;
            opacity:0;
            pointer-events:none;
            transition:opacity .15s ease, transform .15s ease;
            z-index:20;
        }
        .doc-action--info[data-tooltip]::before {
            content:'';
            position:absolute;
            left:50%;
            bottom:calc(100% + 4px);
            transform:translateX(-50%);
            border:6px solid transparent;
            border-top-color:#0f172a;
            opacity:0;
            pointer-events:none;
            transition:opacity .15s ease;
            z-index:21;
        }
        .doc-action--info[data-tooltip]:hover::after,
        .doc-action--info[data-tooltip]:focus-visible::after {
            opacity:1;
            transform:translateX(-50%) translateY(-2px);
        }
        .doc-action--info[data-tooltip]:hover::before,
        .doc-action--info[data-tooltip]:focus-visible::before {
            opacity:1;
        }
        .doc-action--minuta { background:#21a8c7; color:#fff; border-color:#21a8c7; }
        .doc-action--edit { background:#f4bd32; color:#3b2f08; border-color:#f4bd32; }
        .doc-action--danger { background:#ef4444; color:#fff; border-color:#ef4444; }
        .doc-action--sign { background:#f59e0b; color:#fff; border-color:#f59e0b; }
        .doc-action--sign-link { background:#16a34a; color:#fff; border-color:#16a34a; }
        .doc-empty { padding:28px 20px; color:#6b7b91; font-weight:700; }
        .doc-modal { position:fixed; inset:0; background:rgba(15,23,42,.55); display:none; align-items:center; justify-content:center; padding:24px; z-index:50; }
        .doc-modal:target, .doc-modal.is-open { display:flex; }
        .doc-dialog { width:min(860px, 100%); max-height:90vh; overflow:auto; background:#fff; border-radius:12px; box-shadow:0 20px 60px rgba(0,0,0,.25); }
        .doc-dialog--wide { width:min(1120px, 100%); }
        .doc-dialog-header { display:flex; justify-content:space-between; align-items:center; padding:18px 20px; border-bottom:1px solid #e3ebf4; }
        .doc-dialog-header h3 { margin:0; font-size:21px; }
        .doc-close { width:36px; height:36px; border-radius:999px; display:inline-flex; align-items:center; justify-content:center; background:#f1f5f9; color:#24364f; text-decoration:none; font-weight:900; }
        .doc-form { padding:20px; }
        .doc-field { margin-bottom:16px; }
        .doc-field label { display:block; margin-bottom:7px; font-weight:800; }
        .doc-field select, .doc-field input, .doc-field textarea { width:100%; box-sizing:border-box; border:1px solid #cad7e6; border-radius:9px; min-height:44px; padding:10px 12px; font:inherit; }
        .doc-field textarea { min-height:360px; font-family:Menlo, Consolas, monospace; font-size:13px; }
        .doc-sign-card { border:1px solid #f4bd32; border-radius:9px; overflow:hidden; margin-bottom:16px; background:#fff; }
        .doc-sign-card--signed { border-color:#22c55e; }
        .doc-sign-card-header { background:#f4bd32; color:#fff; padding:12px 16px; font-weight:900; }
        .doc-sign-card--signed .doc-sign-card-header { background:#22c55e; }
        .doc-sign-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px 18px; padding:16px; }
        .doc-sign-grid .doc-field { margin:0; }
        .doc-sign-grid .doc-field--full { grid-column:1 / -1; }
        .doc-field input[readonly] { background:#f8fafc; color:#334155; }
        .doc-sign-note { font-size:12px; font-weight:700; margin-top:6px; color:#b45309; }
        .doc-sign-card--signed .doc-sign-note { color:#15803d; }
        .doc-dialog-actions { display:flex; justify-content:flex-end; gap:10px; padding:16px 20px; border-top:1px solid #e3ebf4; }
        @media (max-width: 760px) {
            .doc-header, .doc-card-header { flex-direction:column; align-items:stretch; }
            .doc-title h1 { font-size:24px; }
            .doc-sign-grid { grid-template-columns:1fr; }
        }
    </style>
    <div class="doc-page">
        <div class="doc-header">
            <div class="doc-title">
                <h1>Contratos e Documentos</h1>
                <p><?= eventos_documentos_e((string)$evento['nome_evento']) ?> · <?= eventos_documentos_e((string)$evento['space_visivel']) ?></p>
            </div>
            <a class="doc-btn doc-btn--warning" href="index.php?page=agenda_eventos&evento_id=<?= (int)$eventoId ?>">Voltar ao evento</a>
        </div>

        <?php foreach ($messages as $message): ?>
            <div class="doc-alert doc-alert--ok"><?= eventos_documentos_e($message) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $error): ?>
            <div class="doc-alert doc-alert--err"><?= eventos_documentos_e($error) ?></div>
        <?php endforeach; ?>

        <section class="doc-card">
            <div class="doc-card-header">
                <h2>Arquivos do evento</h2>
                <span class="doc-muted">Modelos cadastrados em Cadastros &gt; Contratos.</span>
            </div>
            <div class="doc-toolbar">
                <a class="doc-btn doc-btn--green" href="#novo-documento">＋ Novo Documento</a>
            </div>

            <?php if (!$documentos): ?>
                <div class="doc-empty">Nenhum documento gerado.</div>
            <?php else: ?>
                <div class="doc-table-wrap">
                    <table class="doc-table">
                        <thead>
                            <tr>
                                <th>Arquivos</th>
                                <th>Status</th>
                                <th>Assinaturas</th>
                                <th>Opções</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($documentos as $documento): ?>
                            <?php
                            $origem = (string)($documento['origem'] ?? 'geral');
                            $isFormatura = $origem === 'formatura';
                            $statusRaw = strtolower(trim((string)($documento['status'] ?? 'criado')));
                            $statusMap = [
                                'criado' => 'Criado',
                                'minuta_aprovada' => 'Minuta aprovada',
                                'em_curso' => 'Em curso',
                                'assinado' => 'Assinado',
                            ];
                            $status = $statusMap[$statusRaw] ?? ucfirst(str_replace('_', ' ', $statusRaw));
                            $assinaturasTotal = (int)($documento['assinaturas_total'] ?? 0);
                            $assinaturas = $assinaturasTotal > 0
                                ? (int)($documento['assinaturas_realizadas'] ?? 0) . '/' . $assinaturasTotal
                                : '-';
                            $info = 'Criado por: ' . (string)($documento['criado_por_nome'] ?? 'Sistema') . "\n" . 'Criado em: ' . eventos_documentos_data_hora_br((string)($documento['created_at'] ?? ''));
                            $minutaUrl = $isFormatura
                                ? eventos_documentos_public_url('formatura_minuta.php?token=' . rawurlencode((string)($documento['minuta_token'] ?? '')))
                                : eventos_documentos_public_url('evento_documento_minuta.php?token=' . rawurlencode((string)($documento['minuta_token'] ?? '')));
                            $clicksignUrl = trim((string)($documento['clicksign_sign_url'] ?? ''));
                            $clicksignErro = trim((string)($documento['clicksign_ultimo_erro'] ?? ''));
                            ?>
                            <tr>
                                <td>
                                    <strong>📄 <?= eventos_documentos_e((string)$documento['titulo']) ?></strong>
                                    <div class="doc-muted">
                                        <span class="doc-chip <?= $isFormatura ? 'doc-chip--formatura' : '' ?>"><?= $isFormatura ? 'Formatura' : 'Evento' ?></span>
                                        <?php if ($isFormatura && !empty($documento['nome_formando'])): ?>
                                            <?= eventos_documentos_e((string)$documento['nome_formando']) ?>
                                        <?php endif; ?>
                                        <?php if ($clicksignErro !== ''): ?>
                                            <div class="doc-muted" style="color:#991b1b;"><?= eventos_documentos_e($clicksignErro) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="doc-chip"><?= eventos_documentos_e($status) ?></span></td>
                                <td><?= eventos_documentos_e($assinaturas) ?></td>
                                <td>
                                    <div class="doc-actions">
                                        <button class="doc-action doc-action--info" type="button" data-tooltip="<?= eventos_documentos_e($info) ?>" aria-label="<?= eventos_documentos_e($info) ?>">ℹ Info</button>
                                        <a class="doc-action doc-action--minuta" href="<?= eventos_documentos_e($minutaUrl) ?>" target="_blank" rel="noopener" title="Abrir minuta">▣ Minuta</a>
                                        <a
                                            class="doc-action doc-action--edit"
                                            href="#editar-documento"
                                            title="Editar documento"
                                            data-edit-doc
                                            data-id="<?= (int)$documento['id'] ?>"
                                            data-origem="<?= eventos_documentos_e($origem) ?>"
                                            data-titulo="<?= eventos_documentos_e((string)$documento['titulo']) ?>"
                                            data-conteudo="<?= eventos_documentos_e((string)$documento['conteudo_html']) ?>"
                                        >✎ Editar</a>
                                        <form method="post" onsubmit="return confirm('Excluir este documento?');">
                                            <input type="hidden" name="action" value="delete_documento">
                                            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                                            <input type="hidden" name="documento_id" value="<?= (int)$documento['id'] ?>">
                                            <input type="hidden" name="origem" value="<?= eventos_documentos_e($origem) ?>">
                                            <button class="doc-action doc-action--danger" type="submit" title="Excluir documento">🗑 Excluir</button>
                                        </form>
                                        <a class="doc-action <?= $clicksignUrl !== '' ? 'doc-action--sign-link' : 'doc-action--sign' ?>" href="#assinatura-<?= eventos_documentos_e($origem) ?>-<?= (int)$documento['id'] ?>" title="Conferir assinatura">✍ Assinatura</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

<?php foreach ($documentos as $documento): ?>
    <?php
    $origem = (string)($documento['origem'] ?? 'geral');
    $modalId = 'assinatura-' . $origem . '-' . (int)$documento['id'];
    $signatarios = eventos_documentos_signatarios_documento($documento, $origem, $evento, $usuarioAssinatura);
    $clicksignPorEmail = eventos_documentos_signatarios_clicksign_payload($documento);
    $clicksignEnvelope = trim((string)($documento['clicksign_envelope_id'] ?? ''));
    $clicksignUrlGeral = trim((string)($documento['clicksign_sign_url'] ?? ''));
    ?>
    <div id="<?= eventos_documentos_e($modalId) ?>" class="doc-modal">
        <div class="doc-dialog doc-dialog--wide">
            <div class="doc-dialog-header">
                <h3>Assinatura do Documento</h3>
                <a class="doc-close" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">×</a>
            </div>
            <div class="doc-form">
                <div class="doc-field">
                    <label>Nome do arquivo</label>
                    <input type="text" readonly value="<?= eventos_documentos_e((string)$documento['titulo']) ?>">
                </div>
                <div class="doc-muted" style="margin-bottom:14px;">Quem vai assinar?</div>
                <?php foreach ($signatarios as $idx => $signatario): ?>
                    <?php
                    $emailKey = strtolower(trim((string)($signatario['email'] ?? '')));
                    $clicksignSigner = $clicksignPorEmail[$emailKey] ?? [];
                    $signUrl = trim((string)($clicksignSigner['url'] ?? ''));
                    if ($signUrl === '' && count($signatarios) === 1) {
                        $signUrl = $clicksignUrlGeral;
                    }
                    $statusAssinatura = trim((string)($clicksignSigner['status'] ?? ''));
                    if ($statusAssinatura === '') {
                        $statusAssinatura = $clicksignEnvelope !== '' ? 'Aguardando assinatura' : 'Aguardando envio';
                    }
                    $assinado = stripos($statusAssinatura, 'assinado') !== false;
                    ?>
                    <div class="doc-sign-card <?= $assinado ? 'doc-sign-card--signed' : '' ?>">
                        <div class="doc-sign-card-header">
                            <?= eventos_documentos_e((string)($signatario['tipo'] ?? 'Signatário')) ?> - <?= eventos_documentos_e($statusAssinatura) ?>
                        </div>
                        <div class="doc-sign-grid">
                            <div class="doc-field">
                                <label>Nome</label>
                                <input type="text" readonly value="<?= eventos_documentos_e((string)($signatario['name'] ?? '')) ?>">
                            </div>
                            <div class="doc-field">
                                <label>Função do signatário</label>
                                <input type="text" readonly value="Assinar">
                            </div>
                            <div class="doc-field">
                                <label>E-mail</label>
                                <input type="text" readonly value="<?= eventos_documentos_e((string)($signatario['email'] ?? '')) ?>">
                            </div>
                            <div class="doc-field">
                                <label>Telefone</label>
                                <input type="text" readonly value="<?= eventos_documentos_e((string)($signatario['phone'] ?? '')) ?>">
                            </div>
                            <div class="doc-field doc-field--full">
                                <label>URL</label>
                                <input type="text" readonly value="<?= eventos_documentos_e($signUrl) ?>" placeholder="A URL será gerada após enviar para a Clicksign.">
                                <?php if ($signUrl !== ''): ?>
                                    <div class="doc-sign-note"><a href="<?= eventos_documentos_e($signUrl) ?>" target="_blank" rel="noopener">Abrir link de assinatura</a></div>
                                <?php elseif ($clicksignEnvelope === ''): ?>
                                    <div class="doc-sign-note">O link será disponibilizado pela Clicksign depois do envio.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="doc-dialog-actions">
                <a class="doc-btn doc-btn--light" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">Fechar</a>
                <?php if ($clicksignEnvelope === ''): ?>
                    <form method="post" onsubmit="return confirm('Enviar este documento para assinatura via Clicksign?');">
                        <input type="hidden" name="action" value="send_clicksign">
                        <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
                        <input type="hidden" name="documento_id" value="<?= (int)$documento['id'] ?>">
                        <input type="hidden" name="origem" value="<?= eventos_documentos_e($origem) ?>">
                        <button class="doc-btn doc-btn--green" type="submit">Enviar para assinatura</button>
                    </form>
                <?php elseif ($clicksignUrlGeral !== ''): ?>
                    <a class="doc-btn doc-btn--green" href="<?= eventos_documentos_e($clicksignUrlGeral) ?>" target="_blank" rel="noopener">Abrir assinatura</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div id="novo-documento" class="doc-modal">
    <div class="doc-dialog">
        <div class="doc-dialog-header">
            <h3>Selecionar Modelo de Contrato</h3>
            <a class="doc-close" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">×</a>
        </div>
        <form method="post" class="doc-editor-form">
            <input type="hidden" name="action" value="generate_documento">
            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
            <div class="doc-form">
                <div class="doc-field">
                    <label for="modelo_id">Meus modelos</label>
                    <select id="modelo_id" name="modelo_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($modelos as $modelo): ?>
                            <option value="<?= (int)$modelo['id'] ?>"><?= eventos_documentos_e((string)$modelo['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="doc-dialog-actions">
                <a class="doc-btn doc-btn--light" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">Cancelar</a>
                <button class="doc-btn doc-btn--green" type="submit">Continuar</button>
            </div>
        </form>
    </div>
</div>

<div id="previsualizar-documento" class="doc-modal <?= $previewDocumento ? 'is-open' : '' ?>">
    <div class="doc-dialog doc-dialog--wide">
        <div class="doc-dialog-header">
            <h3>Prévia do Documento</h3>
            <a class="doc-close" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">×</a>
        </div>
        <form method="post" class="doc-editor-form">
            <input type="hidden" name="action" value="confirm_generate_documento">
            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
            <input type="hidden" name="modelo_id" value="<?= (int)($previewDocumento['modelo_id'] ?? 0) ?>">
            <div class="doc-form">
                <div class="doc-field">
                    <label for="previewTitulo">Título</label>
                    <input id="previewTitulo" name="titulo" type="text" required value="<?= eventos_documentos_e((string)($previewDocumento['titulo'] ?? '')) ?>">
                </div>
                <div class="doc-field">
                    <label for="previewConteudo">Conteúdo do documento</label>
                    <textarea id="previewConteudo" class="doc-rich-editor" name="conteudo_html"><?= eventos_documentos_e((string)($previewDocumento['conteudo_html'] ?? '')) ?></textarea>
                </div>
            </div>
            <div class="doc-dialog-actions">
                <a class="doc-btn doc-btn--light" href="#novo-documento">Voltar ao modelo</a>
                <a class="doc-btn doc-btn--light" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">Cancelar</a>
                <button class="doc-btn doc-btn--green" type="submit">Emitir</button>
            </div>
        </form>
    </div>
</div>

<div id="editar-documento" class="doc-modal">
    <div class="doc-dialog">
        <div class="doc-dialog-header">
            <h3>Editar Documento</h3>
            <a class="doc-close" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">×</a>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="save_documento">
            <input type="hidden" name="evento_id" value="<?= (int)$eventoId ?>">
            <input type="hidden" id="editDocumentoId" name="documento_id" value="">
            <input type="hidden" id="editDocumentoOrigem" name="origem" value="geral">
            <div class="doc-form">
                <div class="doc-field">
                    <label for="editTitulo">Título</label>
                    <input id="editTitulo" name="titulo" type="text" required>
                </div>
                <div class="doc-field">
                    <label for="editConteudo">Conteúdo HTML</label>
                    <textarea id="editConteudo" class="doc-rich-editor" name="conteudo_html"></textarea>
                </div>
            </div>
            <div class="doc-dialog-actions">
                <a class="doc-btn doc-btn--light" href="index.php?page=eventos_documentos&evento_id=<?= (int)$eventoId ?>">Cancelar</a>
                <button class="doc-btn doc-btn--green" type="submit">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
<script>
function initDocumentEditors() {
    if (typeof tinymce === 'undefined') return;
    document.querySelectorAll('textarea.doc-rich-editor').forEach((textarea) => {
        if (!textarea.id || tinymce.get(textarea.id)) return;
        if (textarea.offsetParent === null) return;
        tinymce.init({
            selector: `#${textarea.id}`,
            menubar: true,
            branding: false,
            promotion: false,
            plugins: 'advlist autolink lists link table code fullscreen wordcount',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link table | removeformat | code fullscreen',
            height: textarea.id === 'previewConteudo' ? 560 : 420,
            content_style: 'body { font-family: Arial, Helvetica, sans-serif; font-size: 12pt; line-height: 1.45; color: #111827; }'
        });
    });
}

document.querySelectorAll('.doc-editor-form').forEach((form) => {
    form.addEventListener('submit', () => {
        if (typeof tinymce !== 'undefined') {
            tinymce.triggerSave();
        }
    });
});

document.querySelectorAll('[data-edit-doc]').forEach((button) => {
    button.addEventListener('click', () => {
        document.getElementById('editDocumentoId').value = button.dataset.id || '';
        document.getElementById('editDocumentoOrigem').value = button.dataset.origem || 'geral';
        document.getElementById('editTitulo').value = button.dataset.titulo || '';
        const content = button.dataset.conteudo || '';
        document.getElementById('editConteudo').value = content;
        window.setTimeout(() => {
            initDocumentEditors();
            const editor = typeof tinymce !== 'undefined' ? tinymce.get('editConteudo') : null;
            if (editor) {
                editor.setContent(content);
            }
        }, 80);
    });
});
initDocumentEditors();
</script>
<?php endSidebar(); ?>
