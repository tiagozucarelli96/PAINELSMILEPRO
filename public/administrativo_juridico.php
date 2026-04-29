<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';
require_once __DIR__ . '/setup_administrativo_juridico.php';
require_once __DIR__ . '/core/clicksign_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

setupAdministrativoJuridico($pdo);

function aj_usuario_logado_id(): int
{
    return (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
}

function aj_format_bytes(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '-';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $size = (float)$bytes;
    $index = 0;
    while ($size >= 1024 && $index < count($units) - 1) {
        $size /= 1024;
        $index++;
    }

    return number_format($size, $index === 0 ? 0 : 2, ',', '.') . ' ' . $units[$index];
}

function aj_base_url(): string
{
    $base = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));
    if ($base !== '') {
        return rtrim($base, '/');
    }

    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $schemeForwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = $schemeForwarded !== ''
        ? strtolower($schemeForwarded)
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

    return $scheme . '://' . $host;
}

function aj_table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    static $cache = [];
    $cacheKey = $tableName . '.' . $columnName;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.columns
         WHERE table_schema = current_schema()
           AND table_name = :table_name
           AND column_name = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    $cache[$cacheKey] = (bool)$stmt->fetchColumn();
    return $cache[$cacheKey];
}

function aj_usuario_nome_assinatura_sql(PDO $pdo, string $alias = 'u'): string
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'u';
    if (aj_table_has_column($pdo, 'usuarios', 'nome_completo')) {
        return "COALESCE(NULLIF(TRIM({$alias}.nome_completo), ''), {$alias}.nome)";
    }

    return "{$alias}.nome";
}

function aj_signature_default_deadline_local(): string
{
    return (new DateTime('+20 days'))->format('Y-m-d\TH:i');
}

function aj_signature_envelope_name(string $documentTitle, string $signerName = ''): string
{
    $documentTitle = trim($documentTitle);
    if ($documentTitle === '') {
        $documentTitle = 'Documento';
    }

    $signerName = ClicksignHelper::normalizeSignerName($signerName);
    if ($signerName !== '') {
        return $documentTitle . ' - ' . $signerName;
    }

    return $documentTitle;
}

function aj_signature_deadline_payload(?string $deadlineInput): array
{
    $deadlineInput = trim((string)$deadlineInput);
    if ($deadlineInput === '') {
        $dt = new DateTime('+20 days');
    } else {
        try {
            $dt = new DateTime($deadlineInput);
        } catch (Exception $e) {
            throw new Exception('Informe um prazo válido para assinatura.');
        }
    }

    return [
        'local' => $dt->format('Y-m-d\TH:i'),
        'rfc3339' => $dt->format(DateTime::RFC3339),
    ];
}

function aj_signature_modal_signers_from_post(): array
{
    $names = $_POST['signer_name'] ?? [];
    $emails = $_POST['signer_email'] ?? [];
    $groups = $_POST['signer_group'] ?? [];
    if (!is_array($names)) {
        $names = [$names];
    }
    if (!is_array($emails)) {
        $emails = [$emails];
    }
    if (!is_array($groups)) {
        $groups = [$groups];
    }

    $signers = [];
    $max = max(count($names), count($emails), count($groups));
    for ($i = 0; $i < $max; $i++) {
        $name = ClicksignHelper::normalizeSignerName((string)($names[$i] ?? ''));
        $email = trim((string)($emails[$i] ?? ''));
        $group = max(1, (int)($groups[$i] ?? 1));
        if ($name === '' && $email === '') {
            continue;
        }
        $signers[] = [
            'name' => $name,
            'email' => $email,
            'group' => $group,
        ];
    }

    return $signers;
}

function aj_signature_summary_from_payload(array $arquivo): array
{
    $payloadRaw = $arquivo['clicksign_payload'] ?? null;
    if (is_string($payloadRaw) && $payloadRaw !== '') {
        $payload = json_decode($payloadRaw, true);
    } elseif (is_array($payloadRaw)) {
        $payload = $payloadRaw;
    } else {
        $payload = [];
    }

    $summaryData = $payload['summary'] ?? $payload;
    $documentLinks = $summaryData['document_links'] ?? [];
    $signers = $summaryData['signers'] ?? [];
    $events = $summaryData['events'] ?? [];

    return [
        'document_links' => [
            'original' => (string)($documentLinks['original'] ?? ''),
            'signed' => (string)($documentLinks['signed'] ?? ''),
            'ziped' => (string)($documentLinks['ziped'] ?? ''),
        ],
        'signers' => is_array($signers) ? $signers : [],
        'events' => is_array($events) ? $events : [],
        'status_clicksign' => (string)($summaryData['status_clicksign'] ?? ''),
        'status_local' => (string)($summaryData['status_local'] ?? ($arquivo['status_assinatura'] ?? 'nao_solicitada')),
    ];
}

function aj_signature_action_label(string $status): string
{
    $status = trim($status);
    if ($status === 'nao_solicitada' || $status === 'erro' || $status === 'cancelado') {
        return 'Solicitar assinatura';
    }

    return 'Acompanhar assinatura';
}

function aj_signature_badge_class(string $status): string
{
    $status = trim($status);
    if ($status === 'assinado') {
        return 'success';
    }
    if ($status === 'erro' || $status === 'cancelado') {
        return 'error';
    }
    if ($status === 'enviado' || $status === 'pendente_envio') {
        return 'warn';
    }

    return '';
}

function aj_signature_event_label(string $name): string
{
    $map = [
        'upload' => 'Documento enviado',
        'add_signer' => 'Signatário adicionado',
        'signature_started' => 'Assinatura iniciada',
        'sign' => 'Documento assinado',
        'refuse' => 'Assinatura recusada',
        'auto_close' => 'Envelope encerrado',
        'document_closed' => 'Documento finalizado',
    ];

    $name = trim($name);
    return $map[$name] ?? ucfirst(str_replace('_', ' ', $name));
}

function aj_page_url(?int $folderId = null): string
{
    $base = 'index.php?page=administrativo_juridico';
    if ($folderId !== null && $folderId > 0) {
        $base .= '&pasta=' . $folderId;
    }

    return $base;
}

function aj_assinatura_label(string $status): string
{
    $map = [
        'nao_solicitada' => 'Sem assinatura',
        'enviado' => 'Assinatura enviada',
        'pendente_envio' => 'Preparando envio',
        'assinado' => 'Assinado',
        'erro' => 'Erro na assinatura',
        'cancelado' => 'Assinatura cancelada',
    ];

    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function aj_pasta_path_label(array $pastasById, int $folderId): string
{
    if ($folderId <= 0 || !isset($pastasById[$folderId])) {
        return 'Raiz';
    }

    $labels = [];
    $cursor = $folderId;
    $guard = 0;
    while ($cursor > 0 && isset($pastasById[$cursor]) && $guard < 20) {
        $labels[] = (string)($pastasById[$cursor]['nome'] ?? 'Pasta');
        $cursor = (int)($pastasById[$cursor]['parent_id'] ?? 0);
        $guard++;
    }

    return implode(' / ', array_reverse($labels));
}

function aj_folder_option_tags(array $childrenByParent, array $pastasById, int $parentId = 0, int $level = 0): string
{
    $html = '';
    foreach ($childrenByParent[$parentId] ?? [] as $folderId) {
        $folder = $pastasById[$folderId] ?? null;
        if (!$folder) {
            continue;
        }

        $prefix = str_repeat('&nbsp;&nbsp;&nbsp;', $level);
        $html .= '<option value="' . $folderId . '">' . $prefix . htmlspecialchars((string)$folder['nome']) . '</option>';
        $html .= aj_folder_option_tags($childrenByParent, $pastasById, $folderId, $level + 1);
    }

    return $html;
}

function aj_buscar_bytes_arquivo(array $arquivo): array
{
    $chaveStorage = trim((string)($arquivo['chave_storage'] ?? ''));
    if ($chaveStorage !== '') {
        $uploader = new MagaluUpload();
        $result = $uploader->getObject($chaveStorage);
        if ($result && !empty($result['body'])) {
            return [
                'body' => (string)$result['body'],
                'content_type' => (string)($result['content_type'] ?? 'application/octet-stream'),
            ];
        }
    }

    $arquivoUrl = trim((string)($arquivo['arquivo_url'] ?? ''));
    if ($arquivoUrl !== '') {
        $body = @file_get_contents($arquivoUrl);
        if ($body !== false && $body !== '') {
            return [
                'body' => $body,
                'content_type' => (string)($arquivo['mime_type'] ?? 'application/octet-stream'),
            ];
        }
    }

    throw new Exception('Não foi possível obter o conteúdo do arquivo para assinatura.');
}

$mensagem = '';
$erro = '';
$currentPastaId = (int)($_GET['pasta'] ?? 0);
$signatureModalState = null;
$lastPostAction = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = trim((string)($_POST['acao'] ?? ''));
    $lastPostAction = $acao;

    try {
        if ($acao === 'criar_pasta') {
            $nomePasta = trim((string)($_POST['nome_pasta'] ?? ''));
            $descricaoPasta = trim((string)($_POST['descricao_pasta'] ?? ''));
            $parentId = (int)($_POST['parent_id'] ?? 0);

            if ($nomePasta === '') {
                throw new Exception('Informe o nome da pasta.');
            }

            $parentFolder = null;
            $usuarioEmpresaId = null;
            if ($parentId > 0) {
                $stmtParent = $pdo->prepare(
                    'SELECT id, usuario_empresa_id
                     FROM administrativo_juridico_pastas
                     WHERE id = :id
                     LIMIT 1'
                );
                $stmtParent->execute([':id' => $parentId]);
                $parentFolder = $stmtParent->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$parentFolder) {
                    throw new Exception('Pasta pai não encontrada.');
                }
                $usuarioEmpresaId = !empty($parentFolder['usuario_empresa_id']) ? (int)$parentFolder['usuario_empresa_id'] : null;
            }

            $stmtExiste = $pdo->prepare(
                'SELECT id
                 FROM administrativo_juridico_pastas
                 WHERE COALESCE(parent_id, 0) = :parent_id
                   AND LOWER(nome) = LOWER(:nome)
                 LIMIT 1'
            );
            $stmtExiste->execute([
                ':parent_id' => $parentId,
                ':nome' => $nomePasta,
            ]);
            if ($stmtExiste->fetchColumn()) {
                throw new Exception('Já existe uma pasta com esse nome neste local.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO administrativo_juridico_pastas
                 (nome, descricao, usuario_empresa_id, parent_id, criado_por_usuario_id)
                 VALUES
                 (:nome, :descricao, :usuario_empresa_id, :parent_id, :criado_por)'
            );
            $stmt->execute([
                ':nome' => $nomePasta,
                ':descricao' => $descricaoPasta !== '' ? $descricaoPasta : null,
                ':usuario_empresa_id' => $usuarioEmpresaId,
                ':parent_id' => $parentId > 0 ? $parentId : null,
                ':criado_por' => aj_usuario_logado_id() ?: null,
            ]);

            $mensagem = 'Pasta criada com sucesso.';
            $currentPastaId = $parentId;
        }

        if ($acao === 'adicionar_arquivo') {
            $pastaId = (int)($_POST['pasta_id'] ?? 0);
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $descricao = trim((string)($_POST['descricao'] ?? ''));

            if ($pastaId <= 0) {
                throw new Exception('Selecione a pasta para o arquivo.');
            }

            $stmtPasta = $pdo->prepare(
                'SELECT id, nome
                 FROM administrativo_juridico_pastas
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmtPasta->execute([':id' => $pastaId]);
            $pasta = $stmtPasta->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$pasta) {
                throw new Exception('Pasta não encontrada.');
            }

            if (!isset($_FILES['arquivo']) || (int)($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new Exception('Anexe um arquivo para continuar.');
            }

            $uploader = new MagaluUpload();
            $resultadoUpload = $uploader->upload($_FILES['arquivo'], 'administrativo/juridico/' . $pastaId);
            $arquivoNome = (string)($resultadoUpload['nome_original'] ?? ($_FILES['arquivo']['name'] ?? 'arquivo'));
            $tituloFinal = $titulo !== '' ? $titulo : $arquivoNome;

            $stmtInsert = $pdo->prepare(
                'INSERT INTO administrativo_juridico_arquivos
                 (pasta_id, titulo, descricao, arquivo_nome, arquivo_url, chave_storage, mime_type, tamanho_bytes, criado_por_usuario_id)
                 VALUES
                 (:pasta_id, :titulo, :descricao, :arquivo_nome, :arquivo_url, :chave_storage, :mime_type, :tamanho_bytes, :criado_por)'
            );
            $stmtInsert->execute([
                ':pasta_id' => $pastaId,
                ':titulo' => $tituloFinal,
                ':descricao' => $descricao !== '' ? $descricao : null,
                ':arquivo_nome' => $arquivoNome,
                ':arquivo_url' => $resultadoUpload['url'] ?? null,
                ':chave_storage' => $resultadoUpload['chave_storage'] ?? null,
                ':mime_type' => $resultadoUpload['mime_type'] ?? null,
                ':tamanho_bytes' => $resultadoUpload['tamanho_bytes'] ?? null,
                ':criado_por' => aj_usuario_logado_id() ?: null,
            ]);

            $mensagem = 'Arquivo adicionado com sucesso.';
            $currentPastaId = $pastaId;
        }

        if ($acao === 'mover_arquivo') {
            $arquivoId = (int)($_POST['arquivo_id'] ?? 0);
            $novaPastaId = (int)($_POST['nova_pasta_id'] ?? 0);

            if ($arquivoId <= 0 || $novaPastaId <= 0) {
                throw new Exception('Selecione o arquivo e a pasta de destino.');
            }

            $stmtArquivo = $pdo->prepare(
                'SELECT a.id, a.titulo, a.pasta_id
                 FROM administrativo_juridico_arquivos a
                 WHERE a.id = :id
                 LIMIT 1'
            );
            $stmtArquivo->execute([':id' => $arquivoId]);
            $arquivo = $stmtArquivo->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$arquivo) {
                throw new Exception('Arquivo não encontrado.');
            }

            if ((int)$arquivo['pasta_id'] === $novaPastaId) {
                throw new Exception('O arquivo já está nesta pasta.');
            }

            $stmtNovaPasta = $pdo->prepare('SELECT id, nome FROM administrativo_juridico_pastas WHERE id = :id LIMIT 1');
            $stmtNovaPasta->execute([':id' => $novaPastaId]);
            $novaPasta = $stmtNovaPasta->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$novaPasta) {
                throw new Exception('Pasta de destino não encontrada.');
            }

            $stmtMover = $pdo->prepare(
                'UPDATE administrativo_juridico_arquivos
                 SET pasta_id = :pasta_id,
                     atualizado_em = NOW()
                 WHERE id = :id'
            );
            $stmtMover->execute([
                ':pasta_id' => $novaPastaId,
                ':id' => $arquivoId,
            ]);

            $mensagem = 'Arquivo movido com sucesso.';
            $currentPastaId = $novaPastaId;
        }

        if ($acao === 'excluir_arquivo') {
            $arquivoId = (int)($_POST['arquivo_id'] ?? 0);
            if ($arquivoId <= 0) {
                throw new Exception('Arquivo inválido para exclusão.');
            }

            $stmtArquivo = $pdo->prepare(
                'SELECT id, pasta_id, chave_storage
                 FROM administrativo_juridico_arquivos
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmtArquivo->execute([':id' => $arquivoId]);
            $arquivo = $stmtArquivo->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$arquivo) {
                throw new Exception('Arquivo não encontrado.');
            }

            $chaveStorage = trim((string)($arquivo['chave_storage'] ?? ''));
            if ($chaveStorage !== '') {
                try {
                    $uploader = new MagaluUpload();
                    $uploader->delete($chaveStorage);
                } catch (Exception $e) {
                    error_log('Juridico - erro ao remover storage: ' . $e->getMessage());
                }
            }

            $stmtDelete = $pdo->prepare('DELETE FROM administrativo_juridico_arquivos WHERE id = :id');
            $stmtDelete->execute([':id' => $arquivoId]);

            $mensagem = 'Arquivo excluído com sucesso.';
            $currentPastaId = (int)($arquivo['pasta_id'] ?? 0);
        }

        if ($acao === 'excluir_pasta') {
            $pastaId = (int)($_POST['pasta_id'] ?? 0);
            if ($pastaId <= 0) {
                throw new Exception('Pasta inválida para exclusão.');
            }

            $stmtPasta = $pdo->prepare(
                'SELECT id, nome, parent_id, usuario_empresa_id
                 FROM administrativo_juridico_pastas
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmtPasta->execute([':id' => $pastaId]);
            $pasta = $stmtPasta->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$pasta) {
                throw new Exception('Pasta não encontrada.');
            }

            if (!empty($pasta['usuario_empresa_id']) && empty($pasta['parent_id'])) {
                throw new Exception('A pasta principal do funcionário não pode ser excluída.');
            }

            $stmtChild = $pdo->prepare('SELECT COUNT(*) FROM administrativo_juridico_pastas WHERE parent_id = :id');
            $stmtChild->execute([':id' => $pastaId]);
            if ((int)$stmtChild->fetchColumn() > 0) {
                throw new Exception('Exclua primeiro as subpastas desta pasta.');
            }

            $stmtFiles = $pdo->prepare('SELECT COUNT(*) FROM administrativo_juridico_arquivos WHERE pasta_id = :id');
            $stmtFiles->execute([':id' => $pastaId]);
            if ((int)$stmtFiles->fetchColumn() > 0) {
                throw new Exception('Exclua ou mova primeiro os arquivos desta pasta.');
            }

            $stmtDelete = $pdo->prepare('DELETE FROM administrativo_juridico_pastas WHERE id = :id');
            $stmtDelete->execute([':id' => $pastaId]);

            $mensagem = 'Pasta excluída com sucesso.';
            $currentPastaId = (int)($pasta['parent_id'] ?? 0);
        }

        if ($acao === 'solicitar_assinatura') {
            $arquivoId = (int)($_POST['arquivo_id'] ?? 0);
            if ($arquivoId <= 0) {
                throw new Exception('Arquivo inválido para solicitação de assinatura.');
            }

            $colaboradorNomeSql = aj_usuario_nome_assinatura_sql($pdo, 'u');
            $stmtArquivo = $pdo->prepare(
                "SELECT a.*, p.usuario_empresa_id,
                        {$colaboradorNomeSql} AS colaborador_nome_assinatura,
                        u.nome AS colaborador_nome_cadastrado,
                        u.email AS colaborador_email
                 FROM administrativo_juridico_arquivos a
                 INNER JOIN administrativo_juridico_pastas p ON p.id = a.pasta_id
                 LEFT JOIN usuarios u ON u.id = p.usuario_empresa_id
                 WHERE a.id = :id
                 LIMIT 1"
            );
            $stmtArquivo->execute([':id' => $arquivoId]);
            $arquivo = $stmtArquivo->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$arquivo) {
                throw new Exception('Arquivo não encontrado.');
            }

            $colaboradorEmailBase = trim((string)($arquivo['colaborador_email'] ?? ''));
            $colaboradorNomeBase = ClicksignHelper::normalizeSignerName((string)($arquivo['colaborador_nome_assinatura'] ?? $arquivo['colaborador_nome_cadastrado'] ?? ''));
            $envelopeName = trim((string)($_POST['envelope_name'] ?? ''));
            $signers = aj_signature_modal_signers_from_post();
            if (empty($signers)) {
                $signers[] = [
                    'name' => $colaboradorNomeBase,
                    'email' => $colaboradorEmailBase,
                    'group' => 1,
                ];
            }
            $primarySignerName = ClicksignHelper::normalizeSignerName((string)($signers[0]['name'] ?? $colaboradorNomeBase));
            if ($envelopeName === '') {
                $envelopeName = aj_signature_envelope_name((string)($arquivo['titulo'] ?? 'Documento'), $primarySignerName);
            }
            $deadlineInput = trim((string)($_POST['deadline_at'] ?? ''));
            $signatureModalState = [
                'arquivo_id' => $arquivoId,
                'arquivo_titulo' => (string)($arquivo['titulo'] ?? 'Documento'),
                'arquivo_nome' => (string)($arquivo['arquivo_nome'] ?? ''),
                'signers' => $signers,
                'envelope_name' => $envelopeName,
                'deadline_at' => $deadlineInput !== '' ? $deadlineInput : aj_signature_default_deadline_local(),
            ];
            $deadlinePayload = aj_signature_deadline_payload($deadlineInput);

            $payloadArquivo = aj_buscar_bytes_arquivo($arquivo);
            $clicksign = new ClicksignHelper();
            if (!$clicksign->isConfigured()) {
                throw new Exception($clicksign->getConfigurationError());
            }

            $resAssinatura = $clicksign->criarFluxoAssinatura([
                'envelope_name' => $envelopeName,
                'filename' => (string)($arquivo['arquivo_nome'] ?? 'documento.pdf'),
                'content_base64' => base64_encode((string)$payloadArquivo['body']),
                'content_type' => (string)($payloadArquivo['content_type'] ?? $arquivo['mime_type'] ?? 'application/pdf'),
                'signers' => $signers,
                'deadline_at' => $deadlinePayload['rfc3339'],
            ]);

            if (!($resAssinatura['success'] ?? false)) {
                $stmtErro = $pdo->prepare(
                    'UPDATE administrativo_juridico_arquivos
                     SET status_assinatura = :status,
                         clicksign_ultimo_erro = :erro,
                         atualizado_em = NOW()
                     WHERE id = :id'
                );
                $stmtErro->execute([
                    ':status' => 'erro',
                    ':erro' => (string)($resAssinatura['error'] ?? 'Erro desconhecido ao solicitar assinatura.'),
                    ':id' => $arquivoId,
                ]);

                throw new Exception((string)($resAssinatura['error'] ?? 'Erro ao solicitar assinatura.'));
            }

            $stmtOk = $pdo->prepare(
                'UPDATE administrativo_juridico_arquivos
                 SET status_assinatura = :status,
                     clicksign_envelope_id = :envelope_id,
                     clicksign_document_id = :document_id,
                     clicksign_signer_id = :signer_id,
                     clicksign_sign_url = :sign_url,
                     clicksign_payload = :payload::jsonb,
                     clicksign_ultimo_erro = NULL,
                     enviado_assinatura_em = NOW(),
                     atualizado_em = NOW()
                 WHERE id = :id'
            );
            $stmtOk->execute([
                ':status' => 'enviado',
                ':envelope_id' => $resAssinatura['envelope_id'] ?? null,
                ':document_id' => $resAssinatura['document_id'] ?? null,
                ':signer_id' => $resAssinatura['signer_id'] ?? null,
                ':sign_url' => $resAssinatura['sign_url'] ?? null,
                ':payload' => json_encode($resAssinatura['raw'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => $arquivoId,
            ]);

            $mensagem = 'Solicitação de assinatura enviada com sucesso para ' . count($signers) . ' signatário(s).';
            $currentPastaId = (int)($arquivo['pasta_id'] ?? 0);
        }

        if ($acao === 'atualizar_assinatura' || $acao === 'reenviar_assinatura') {
            $arquivoId = (int)($_POST['arquivo_id'] ?? 0);
            if ($arquivoId <= 0) {
                throw new Exception('Arquivo inválido para acompanhamento de assinatura.');
            }

            $stmtArquivo = $pdo->prepare(
                'SELECT id, pasta_id, clicksign_envelope_id, clicksign_document_id
                 FROM administrativo_juridico_arquivos
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmtArquivo->execute([':id' => $arquivoId]);
            $arquivo = $stmtArquivo->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$arquivo || empty($arquivo['clicksign_envelope_id'])) {
                throw new Exception('Este arquivo ainda não possui envelope de assinatura vinculado.');
            }

            $clicksign = new ClicksignHelper();
            if (!$clicksign->isConfigured()) {
                throw new Exception($clicksign->getConfigurationError());
            }

            if ($acao === 'reenviar_assinatura') {
                $resReenvio = $clicksign->reenviarNotificacao((string)$arquivo['clicksign_envelope_id'], '');
                if (!($resReenvio['success'] ?? false)) {
                    throw new Exception((string)($resReenvio['error'] ?? 'Não foi possível reenviar a notificação.'));
                }
            }

            $resResumo = $clicksign->consultarFluxoAssinatura(
                (string)$arquivo['clicksign_envelope_id'],
                (string)($arquivo['clicksign_document_id'] ?? '')
            );
            if (!($resResumo['success'] ?? false)) {
                throw new Exception((string)($resResumo['error'] ?? 'Falha ao atualizar andamento da assinatura.'));
            }

            $stmtAtualiza = $pdo->prepare(
                'UPDATE administrativo_juridico_arquivos
                 SET status_assinatura = :status,
                     clicksign_sign_url = :sign_url,
                     clicksign_payload = :payload::jsonb,
                     clicksign_ultimo_erro = NULL,
                     atualizado_em = NOW()
                 WHERE id = :id'
            );
            $stmtAtualiza->execute([
                ':status' => $resResumo['status_local'] ?? 'enviado',
                ':sign_url' => $resResumo['signers'][0]['signature_url'] ?? null,
                ':payload' => json_encode($resResumo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':id' => $arquivoId,
            ]);

            $mensagem = $acao === 'reenviar_assinatura'
                ? 'Lembrete de assinatura reenviado e andamento atualizado.'
                : 'Andamento da assinatura atualizado com sucesso.';
            $currentPastaId = (int)($arquivo['pasta_id'] ?? 0);
        }

        if ($acao === 'cadastrar_usuario') {
            $nomeUsuario = trim((string)($_POST['nome_usuario'] ?? ''));
            $emailUsuario = trim((string)($_POST['email_usuario'] ?? ''));
            $senha = (string)($_POST['senha_usuario'] ?? '');
            $confirmarSenha = (string)($_POST['senha_usuario_confirmacao'] ?? '');

            if ($nomeUsuario === '') {
                throw new Exception('Informe o nome do usuário jurídico.');
            }
            if ($senha === '' || strlen($senha) < 6) {
                throw new Exception('A senha precisa ter no mínimo 6 caracteres.');
            }
            if ($senha !== $confirmarSenha) {
                throw new Exception('As senhas informadas não conferem.');
            }
            if ($emailUsuario !== '' && !filter_var($emailUsuario, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Informe um e-mail válido ou deixe em branco.');
            }

            $stmtExiste = $pdo->prepare('SELECT id FROM administrativo_juridico_usuarios WHERE LOWER(nome) = LOWER(:nome) LIMIT 1');
            $stmtExiste->execute([':nome' => $nomeUsuario]);
            if ($stmtExiste->fetchColumn()) {
                throw new Exception('Já existe um usuário jurídico com esse nome.');
            }

            $senhaHash = password_hash($senha, PASSWORD_DEFAULT);
            if ($senhaHash === false) {
                throw new Exception('Não foi possível gerar a senha segura para este usuário.');
            }

            $stmtInsert = $pdo->prepare(
                'INSERT INTO administrativo_juridico_usuarios (nome, email, senha_hash, ativo, criado_por_usuario_id)
                 VALUES (:nome, :email, :senha_hash, TRUE, :criado_por)'
            );
            $stmtInsert->execute([
                ':nome' => $nomeUsuario,
                ':email' => $emailUsuario !== '' ? $emailUsuario : null,
                ':senha_hash' => $senhaHash,
                ':criado_por' => aj_usuario_logado_id() ?: null,
            ]);

            $mensagem = 'Usuário jurídico cadastrado com sucesso.';
        }

        if ($acao === 'atualizar_usuario') {
            $usuarioId = (int)($_POST['usuario_id'] ?? 0);
            $nomeUsuario = trim((string)($_POST['nome_usuario'] ?? ''));
            $emailUsuario = trim((string)($_POST['email_usuario'] ?? ''));
            $senhaNova = (string)($_POST['senha_usuario_nova'] ?? '');
            $senhaNovaConfirmacao = (string)($_POST['senha_usuario_nova_confirmacao'] ?? '');

            if ($usuarioId <= 0) {
                throw new Exception('Usuário jurídico inválido para atualização.');
            }
            if ($nomeUsuario === '') {
                throw new Exception('Informe o nome do usuário jurídico.');
            }
            if ($emailUsuario !== '' && !filter_var($emailUsuario, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Informe um e-mail válido ou deixe em branco.');
            }

            $stmtAtual = $pdo->prepare('SELECT id FROM administrativo_juridico_usuarios WHERE id = :id LIMIT 1');
            $stmtAtual->execute([':id' => $usuarioId]);
            if (!$stmtAtual->fetchColumn()) {
                throw new Exception('Usuário jurídico não encontrado.');
            }

            $stmtNome = $pdo->prepare('SELECT id FROM administrativo_juridico_usuarios WHERE LOWER(nome) = LOWER(:nome) AND id <> :id LIMIT 1');
            $stmtNome->execute([
                ':nome' => $nomeUsuario,
                ':id' => $usuarioId,
            ]);
            if ($stmtNome->fetchColumn()) {
                throw new Exception('Já existe outro usuário jurídico com esse nome.');
            }

            $params = [
                ':id' => $usuarioId,
                ':nome' => $nomeUsuario,
                ':email' => $emailUsuario !== '' ? $emailUsuario : null,
            ];

            $sqlUpdate = 'UPDATE administrativo_juridico_usuarios
                          SET nome = :nome,
                              email = :email,
                              atualizado_em = NOW()';

            if ($senhaNova !== '') {
                if (strlen($senhaNova) < 6) {
                    throw new Exception('A nova senha precisa ter no mínimo 6 caracteres.');
                }
                if ($senhaNova !== $senhaNovaConfirmacao) {
                    throw new Exception('A confirmação da nova senha não confere.');
                }

                $senhaHash = password_hash($senhaNova, PASSWORD_DEFAULT);
                if ($senhaHash === false) {
                    throw new Exception('Não foi possível gerar a nova senha segura.');
                }

                $sqlUpdate .= ', senha_hash = :senha_hash';
                $params[':senha_hash'] = $senhaHash;
            }

            $sqlUpdate .= ' WHERE id = :id';

            $stmtUpdate = $pdo->prepare($sqlUpdate);
            $stmtUpdate->execute($params);

            $mensagem = 'Usuário jurídico atualizado com sucesso.';
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

administrativoJuridicoSincronizarPastasColaboradores($pdo);

$pastas = [];
$pastasById = [];
$childrenByParent = [];
$usuariosJuridico = [];
$arquivosAtuais = [];

try {
    $stmtPastas = $pdo->query(
        'SELECT p.id, p.nome, p.descricao, p.parent_id, p.usuario_empresa_id, p.criado_em,
                ue.nome AS usuario_empresa_nome,
                ue.email AS usuario_empresa_email,
                ue.cargo AS usuario_empresa_cargo,
                COUNT(a.id) AS total_arquivos
         FROM administrativo_juridico_pastas p
         LEFT JOIN usuarios ue ON ue.id = p.usuario_empresa_id
         LEFT JOIN administrativo_juridico_arquivos a ON a.pasta_id = p.id
         GROUP BY p.id, ue.nome, ue.email, ue.cargo
         ORDER BY LOWER(p.nome) ASC'
    );
    $pastas = $stmtPastas->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($pastas as $pasta) {
        $folderId = (int)($pasta['id'] ?? 0);
        $pastasById[$folderId] = $pasta;
        $parentId = (int)($pasta['parent_id'] ?? 0);
        if (!isset($childrenByParent[$parentId])) {
            $childrenByParent[$parentId] = [];
        }
        $childrenByParent[$parentId][] = $folderId;
    }
} catch (Exception $e) {
    $erro = $erro !== '' ? $erro : 'Erro ao carregar pastas.';
    error_log('Juridico - listar pastas: ' . $e->getMessage());
}

if ($currentPastaId > 0 && !isset($pastasById[$currentPastaId])) {
    $currentPastaId = 0;
}

$currentPasta = $currentPastaId > 0 ? ($pastasById[$currentPastaId] ?? null) : null;
$pastasFilhas = [];
foreach ($childrenByParent[$currentPastaId] ?? [] as $childId) {
    if (isset($pastasById[$childId])) {
        $pastasFilhas[] = $pastasById[$childId];
    }
}

if ($currentPastaId > 0) {
    try {
        $stmtArquivos = $pdo->prepare(
            'SELECT a.id, a.pasta_id, a.titulo, a.descricao, a.arquivo_nome, a.arquivo_url, a.chave_storage, a.mime_type,
                    a.criado_em, a.tamanho_bytes, a.status_assinatura, a.clicksign_sign_url, a.clicksign_ultimo_erro,
                    a.clicksign_payload, a.clicksign_envelope_id, a.clicksign_document_id,
                    u.nome AS criado_por_nome
             FROM administrativo_juridico_arquivos a
             LEFT JOIN usuarios u ON u.id = a.criado_por_usuario_id
             WHERE a.pasta_id = :pasta_id
             ORDER BY LOWER(a.titulo) ASC, a.criado_em DESC'
        );
        $stmtArquivos->execute([':pasta_id' => $currentPastaId]);
        $arquivosAtuais = $stmtArquivos->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($arquivosAtuais as &$arquivoAtual) {
            $signatureSummary = aj_signature_summary_from_payload($arquivoAtual);
            $arquivoAtual['_signature_summary'] = $signatureSummary;
            $arquivoAtual['_signed_file_url'] = $signatureSummary['document_links']['signed'] ?? '';
            $arquivoAtual['_original_file_url'] = $signatureSummary['document_links']['original'] ?? '';
            $arquivoAtual['_zip_file_url'] = $signatureSummary['document_links']['ziped'] ?? '';
            $arquivoAtual['_signers'] = $signatureSummary['signers'] ?? [];
            $arquivoAtual['_events'] = $signatureSummary['events'] ?? [];
        }
        unset($arquivoAtual);
    } catch (Exception $e) {
        $erro = $erro !== '' ? $erro : 'Erro ao carregar arquivos da pasta.';
        error_log('Juridico - listar arquivos da pasta: ' . $e->getMessage());
    }
}

try {
    $stmt = $pdo->query(
        'SELECT id, nome, email, ativo, criado_em
         FROM administrativo_juridico_usuarios
         ORDER BY ativo DESC, nome ASC'
    );
    $usuariosJuridico = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    error_log('Juridico - listar usuarios juridicos: ' . $e->getMessage());
}

$breadcrumbs = [];
$cursorId = $currentPastaId;
$guard = 0;
while ($cursorId > 0 && isset($pastasById[$cursorId]) && $guard < 20) {
    $breadcrumbs[] = $pastasById[$cursorId];
    $cursorId = (int)($pastasById[$cursorId]['parent_id'] ?? 0);
    $guard++;
}
$breadcrumbs = array_reverse($breadcrumbs);

$baseUrl = aj_base_url();
$juridicoLoginLink = ($baseUrl !== '' ? $baseUrl : '') . '/index.php?page=juridico_login';
if ($baseUrl === '') {
    $juridicoLoginLink = 'index.php?page=juridico_login';
}

$folderOptionsHtml = aj_folder_option_tags($childrenByParent, $pastasById);
$defaultSignatureDeadlineLocal = aj_signature_default_deadline_local();

ob_start();
?>
<style>
    .aj-shell { max-width: 1440px; margin: 0 auto; padding: 1.3rem; }
    .aj-title { margin: 0; color: #0f172a; font-size: 2rem; font-weight: 800; }
    .aj-subtitle { margin: .35rem 0 1rem; color: #64748b; }
    .aj-alert { border-radius: 12px; padding: .9rem 1rem; margin-bottom: 1rem; font-weight: 700; }
    .aj-alert.ok { background: #dcfce7; border: 1px solid #86efac; color: #166534; }
    .aj-alert.err { background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; }

    .aj-topbar { display: flex; flex-wrap: wrap; gap: .7rem; margin-bottom: 1rem; }
    .aj-btn,
    .aj-btn-outline,
    .aj-action-btn {
        border-radius: 10px;
        padding: .68rem 1rem;
        font-weight: 800;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        border: 0;
        transition: background .15s ease, color .15s ease, border-color .15s ease, transform .15s ease;
    }
    .aj-btn { background: #1d4ed8; color: #fff; }
    .aj-btn:hover { background: #1e40af; }
    .aj-btn.secondary { background: #0f766e; }
    .aj-btn.secondary:hover { background: #115e59; }
    .aj-btn.dark { background: #334155; }
    .aj-btn.dark:hover { background: #1e293b; }
    .aj-btn.danger { background: #b91c1c; }
    .aj-btn.danger:hover { background: #991b1b; }
    .aj-btn-outline { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; }
    .aj-btn-outline:hover { background: #f8fafc; }
    .aj-action-btn {
        font-size: .82rem;
        padding: .58rem .82rem;
        border: 1px solid transparent;
        box-shadow: 0 2px 8px rgba(15, 23, 42, .06);
    }
    .aj-action-btn:hover { transform: translateY(-1px); }
    .aj-action-btn.primary { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
    .aj-action-btn.primary:hover { background: #bfdbfe; }
    .aj-action-btn.neutral { background: #f8fafc; color: #334155; border-color: #cbd5e1; }
    .aj-action-btn.neutral:hover { background: #e2e8f0; }
    .aj-action-btn.success { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .aj-action-btn.success:hover { background: #bbf7d0; }
    .aj-action-btn.warn { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .aj-action-btn.warn:hover { background: #fde68a; }
    .aj-action-btn.danger { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
    .aj-action-btn.danger:hover { background: #fecaca; }

    .aj-panel {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        box-shadow: 0 12px 30px rgba(15, 23, 42, .06);
    }
    .aj-main h2 { margin: 0 0 .85rem; color: #0f172a; font-size: 1.05rem; }
    .aj-main { padding: 1rem; }
    .aj-breadcrumbs {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .45rem;
        margin-bottom: 1rem;
        color: #475569;
        font-size: .9rem;
    }
    .aj-breadcrumbs a { color: #1d4ed8; text-decoration: none; font-weight: 700; }
    .aj-main-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }
    .aj-main-meta { color: #64748b; font-size: .88rem; line-height: 1.45; }
    .aj-main-actions { display: flex; flex-wrap: wrap; gap: .6rem; }

    .aj-folder-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: .9rem;
        margin-bottom: 1rem;
    }
    .aj-folder-tile {
        display: block;
        text-decoration: none;
        color: inherit;
        border: 1px solid #dbe3ef;
        border-radius: 16px;
        background: linear-gradient(180deg, #fff8db 0%, #fffdf3 100%);
        padding: 1rem;
        min-height: 140px;
        position: relative;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .05);
    }
    .aj-folder-tile:hover { transform: translateY(-1px); }
    .aj-folder-icon { font-size: 2rem; margin-bottom: .7rem; display: block; }
    .aj-folder-name { font-size: 1rem; font-weight: 800; color: #0f172a; line-height: 1.25; }
    .aj-folder-actions {
        margin-top: .8rem;
        display: flex;
        flex-wrap: wrap;
        gap: .45rem;
    }
    .aj-mini-btn {
        border: 0;
        border-radius: 8px;
        padding: .38rem .58rem;
        font-size: .76rem;
        font-weight: 800;
        cursor: pointer;
    }
    .aj-mini-btn.secondary { background: #dbeafe; color: #1d4ed8; }
    .aj-mini-btn.danger { background: #fee2e2; color: #991b1b; }

    .aj-file-table {
        width: 100%;
        border-collapse: collapse;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        overflow: hidden;
    }
    .aj-file-table th,
    .aj-file-table td {
        padding: .85rem;
        border-bottom: 1px solid #e2e8f0;
        vertical-align: top;
        text-align: left;
    }
    .aj-file-table th { background: #f8fafc; color: #334155; font-size: .82rem; text-transform: uppercase; }
    .aj-file-table tr:last-child td { border-bottom: 0; }
    .aj-file-title { font-weight: 800; color: #0f172a; margin-bottom: .18rem; }
    .aj-file-subtitle { color: #64748b; font-size: .82rem; line-height: 1.35; }
    .aj-file-actions { display: flex; flex-wrap: wrap; gap: .45rem; }
    .aj-file-actions form { margin: 0; }
    .aj-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: .28rem .58rem;
        font-size: .74rem;
        font-weight: 800;
        white-space: nowrap;
    }
    .aj-badge.owner { background: #dcfce7; color: #166534; }
    .aj-badge.legacy { background: #fef3c7; color: #92400e; }
    .aj-badge.sign { background: #e0f2fe; color: #075985; }
    .aj-badge.sign.success { background: #dcfce7; color: #166534; }
    .aj-badge.sign.warn { background: #fef3c7; color: #92400e; }
    .aj-badge.sign.error { background: #fee2e2; color: #991b1b; }
    .aj-empty {
        padding: 1rem;
        border-radius: 14px;
        border: 1px dashed #cbd5e1;
        background: #f8fafc;
        color: #64748b;
    }

    .aj-section { margin-top: 1rem; }
    .aj-users-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: .8rem;
    }
    .aj-user-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: .9rem;
        background: #fff;
    }
    .aj-user-card h3 { margin: 0 0 .3rem; color: #0f172a; font-size: 1rem; }
    .aj-user-card p { margin: 0; color: #64748b; }

    .aj-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 4100;
        background: rgba(2, 6, 23, .6);
        padding: 1rem;
    }
    .aj-modal.open { display: flex; align-items: center; justify-content: center; }
    .aj-modal-dialog {
        width: 100%;
        max-width: 720px;
        background: #fff;
        border-radius: 18px;
        overflow: hidden;
        box-shadow: 0 24px 60px rgba(2, 6, 23, .35);
    }
    .aj-modal-header {
        background: #1d4ed8;
        color: #fff;
        padding: 1rem 1.1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .aj-modal-header h3 { margin: 0; font-size: 1rem; }
    .aj-modal-close {
        background: transparent;
        border: 0;
        color: #fff;
        font-size: 1.25rem;
        cursor: pointer;
    }
    .aj-modal-body { padding: 1rem; }
    .aj-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .8rem; }
    .aj-field { display: flex; flex-direction: column; }
    .aj-field label { margin-bottom: .35rem; color: #334155; font-weight: 700; font-size: .88rem; }
    .aj-field input,
    .aj-field textarea,
    .aj-field select {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: .7rem .78rem;
        font-size: .93rem;
    }
    .aj-field textarea { min-height: 100px; resize: vertical; }
    .aj-help { margin-top: .32rem; color: #64748b; font-size: .77rem; line-height: 1.35; }
    .aj-modal-actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: 1rem; }
    .aj-signers-stack { display: grid; gap: .75rem; }
    .aj-signer-row {
        border: 1px solid #dbe3ef;
        border-radius: 14px;
        padding: .85rem;
        background: #f8fafc;
    }
    .aj-signer-row-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: .75rem;
        gap: .75rem;
    }
    .aj-signer-row-title { font-size: .82rem; font-weight: 800; color: #334155; text-transform: uppercase; }
    .aj-signer-row-remove {
        background: transparent;
        border: 0;
        color: #b91c1c;
        font-weight: 800;
        cursor: pointer;
    }
    .aj-status-board {
        display: grid;
        gap: .8rem;
    }
    .aj-status-card {
        border: 1px solid #dbe3ef;
        border-radius: 14px;
        padding: .85rem;
        background: #fff;
    }
    .aj-status-head {
        display: flex;
        justify-content: space-between;
        gap: .75rem;
        align-items: center;
        flex-wrap: wrap;
    }
    .aj-status-title { font-weight: 800; color: #0f172a; }
    .aj-status-meta { color: #64748b; font-size: .8rem; }
    .aj-status-list { display: grid; gap: .55rem; margin-top: .8rem; }
    .aj-status-item {
        display: flex;
        justify-content: space-between;
        gap: .75rem;
        border-top: 1px solid #eef2f7;
        padding-top: .55rem;
    }
    .aj-status-item:first-child { border-top: 0; padding-top: 0; }
    .aj-status-timeline {
        margin-top: .8rem;
        display: grid;
        gap: .5rem;
        max-height: 220px;
        overflow: auto;
    }
    .aj-status-event {
        border-left: 3px solid #cbd5e1;
        padding-left: .65rem;
        color: #475569;
        font-size: .8rem;
    }
    .aj-link-list {
        margin-top: .8rem;
        display: flex;
        flex-wrap: wrap;
        gap: .55rem;
    }
    .aj-hidden-template { display: none !important; }

    .aj-link-box {
        margin-top: .9rem;
        padding: .8rem;
        border: 1px solid #dbeafe;
        background: #eff6ff;
        border-radius: 12px;
    }
    .aj-copy-row { display: flex; gap: .5rem; margin-top: .45rem; }
    .aj-copy-row input { flex: 1; }

    @media (max-width: 760px) {
        .aj-grid { grid-template-columns: 1fr; }
        .aj-file-table,
        .aj-file-table tbody,
        .aj-file-table tr,
        .aj-file-table td,
        .aj-file-table th { display: block; width: 100%; }
        .aj-file-table thead { display: none; }
        .aj-file-table td { padding-top: .55rem; padding-bottom: .55rem; }
    }
</style>

<div class="aj-shell">
    <h1 class="aj-title">Jurídico</h1>
    <p class="aj-subtitle">Explorador de documentos por funcionário, com subpastas, exclusão e solicitação de assinatura.</p>

    <?php if ($mensagem !== ''): ?>
        <div class="aj-alert ok"><?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>
    <?php if ($erro !== ''): ?>
        <div class="aj-alert err"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <div class="aj-topbar">
        <button type="button" class="aj-btn" onclick="openAjModal('modalPasta')">📁 Nova pasta</button>
        <button type="button" class="aj-btn secondary" onclick="openAjModal('modalArquivo')">📎 Novo arquivo</button>
        <button type="button" class="aj-btn dark" onclick="openAjModal('modalUsuario')">👤 Acesso do jurídico</button>
    </div>

    <main class="aj-panel aj-main">
            <div class="aj-breadcrumbs">
                <a href="<?= htmlspecialchars(aj_page_url()) ?>">Raiz</a>
                <?php foreach ($breadcrumbs as $index => $item): ?>
                    <span>/</span>
                    <?php if ($index === count($breadcrumbs) - 1): ?>
                        <strong><?= htmlspecialchars((string)$item['nome']) ?></strong>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars(aj_page_url((int)$item['id'])) ?>"><?= htmlspecialchars((string)$item['nome']) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div class="aj-main-header">
                <div>
                    <h2><?= $currentPasta ? htmlspecialchars((string)$currentPasta['nome']) : 'Raiz do jurídico' ?></h2>
                    <div class="aj-main-meta">
                        <?php if ($currentPasta): ?>
                            <?= !empty($currentPasta['descricao']) ? nl2br(htmlspecialchars((string)$currentPasta['descricao'])) : 'Pasta ativa no explorador.' ?>
                        <?php else: ?>
                            Escolha uma pasta para entrar.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="aj-main-actions">
                    <?php if ($currentPastaId > 0): ?>
                        <button type="button" class="aj-btn" onclick="openAjModal('modalPasta')">➕ Subpasta</button>
                        <?php if (empty($currentPasta['usuario_empresa_id']) || !empty($currentPasta['parent_id'])): ?>
                            <form method="POST" onsubmit="return confirm('Deseja excluir esta pasta? Ela precisa estar vazia.');">
                                <input type="hidden" name="acao" value="excluir_pasta">
                                <input type="hidden" name="pasta_id" value="<?= $currentPastaId ?>">
                                <button type="submit" class="aj-btn danger">🗑 Excluir pasta</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($currentPastaId > 0 && !empty($pastasFilhas)): ?>
                <div class="aj-section">
                    <div class="aj-folder-grid">
                        <?php foreach ($pastasFilhas as $pasta): ?>
                            <div class="aj-folder-tile">
                                <a href="<?= htmlspecialchars(aj_page_url((int)$pasta['id'])) ?>" style="text-decoration:none; color:inherit;">
                                    <span class="aj-folder-icon">📁</span>
                                    <div class="aj-folder-name"><?= htmlspecialchars((string)$pasta['nome']) ?></div>
                                </a>
                                <div class="aj-folder-actions">
                                    <a href="<?= htmlspecialchars(aj_page_url((int)$pasta['id'])) ?>" class="aj-mini-btn secondary" style="text-decoration:none;">Abrir</a>
                                    <?php if (empty($pasta['usuario_empresa_id']) || !empty($pasta['parent_id'])): ?>
                                        <form method="POST" onsubmit="return confirm('Deseja excluir esta pasta? Ela precisa estar vazia.');">
                                            <input type="hidden" name="acao" value="excluir_pasta">
                                            <input type="hidden" name="pasta_id" value="<?= (int)$pasta['id'] ?>">
                                            <button type="submit" class="aj-mini-btn danger">Excluir</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="aj-section">
                <?php if (!$currentPasta): ?>
                    <div class="aj-folder-grid">
                        <?php foreach ($childrenByParent[0] ?? [] as $rootFolderId): ?>
                            <?php if (!isset($pastasById[$rootFolderId])) continue; $pasta = $pastasById[$rootFolderId]; ?>
                            <div class="aj-folder-tile">
                                <a href="<?= htmlspecialchars(aj_page_url((int)$pasta['id'])) ?>" style="text-decoration:none; color:inherit;">
                                    <span class="aj-folder-icon">📁</span>
                                    <div class="aj-folder-name"><?= htmlspecialchars((string)$pasta['nome']) ?></div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (empty($arquivosAtuais)): ?>
                    <?php if (empty($pastasFilhas)): ?>
                        <div class="aj-empty">Esta pasta está vazia.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <table class="aj-file-table">
                        <thead>
                            <tr>
                                <th>Arquivo</th>
                                <th>Assinatura</th>
                                <th>Criado em</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($arquivosAtuais as $arquivo): ?>
                                <?php
                                $signatureStatus = (string)($arquivo['status_assinatura'] ?? 'nao_solicitada');
                                $signatureActionLabel = aj_signature_action_label($signatureStatus);
                                $signatureBadgeClass = aj_signature_badge_class($signatureStatus);
                                $signatureSummary = $arquivo['_signature_summary'] ?? [];
                                $signatureSigners = $arquivo['_signers'] ?? [];
                                $signatureEvents = $arquivo['_events'] ?? [];
                                $signatureDocumentLinks = $signatureSummary['document_links'] ?? [];
                                $prefillSigners = [];
                                if (!empty($signatureSigners)) {
                                    foreach ($signatureSigners as $signerItem) {
                                        $prefillSigners[] = [
                                            'name' => (string)($signerItem['name'] ?? ''),
                                            'email' => (string)($signerItem['email'] ?? ''),
                                            'group' => (int)($signerItem['group'] ?? 1),
                                        ];
                                    }
                                }
                                if (empty($prefillSigners)) {
                                    $prefillSigners[] = [
                                        'name' => (string)($currentPasta['usuario_empresa_nome'] ?? ''),
                                        'email' => (string)($currentPasta['usuario_empresa_email'] ?? ''),
                                        'group' => 1,
                                    ];
                                }
                                $signatureOpenPayload = [
                                    'arquivo_id' => (int)$arquivo['id'],
                                    'arquivo_titulo' => (string)($arquivo['titulo'] ?? 'Documento'),
                                    'arquivo_nome' => (string)($arquivo['arquivo_nome'] ?? ''),
                                    'envelope_name' => aj_signature_envelope_name(
                                        (string)($arquivo['titulo'] ?? 'Documento'),
                                        (string)($prefillSigners[0]['name'] ?? '')
                                    ),
                                    'deadline_at' => $defaultSignatureDeadlineLocal,
                                    'signers' => $prefillSigners,
                                ];
                                $signatureStatusPayload = [
                                    'arquivo_id' => (int)$arquivo['id'],
                                    'titulo' => (string)($arquivo['titulo'] ?? 'Documento'),
                                    'status_label' => aj_assinatura_label($signatureStatus),
                                    'status_class' => $signatureBadgeClass,
                                    'envelope_id' => (string)($arquivo['clicksign_envelope_id'] ?? ''),
                                    'document_id' => (string)($arquivo['clicksign_document_id'] ?? ''),
                                    'original_url' => (string)($signatureDocumentLinks['original'] ?? ''),
                                    'signed_url' => (string)($signatureDocumentLinks['signed'] ?? ''),
                                    'zip_url' => (string)($signatureDocumentLinks['ziped'] ?? ''),
                                    'signers' => $signatureSigners,
                                    'events' => $signatureEvents,
                                ];
                                ?>
                                <tr>
                                    <td>
                                        <div class="aj-file-title"><?= htmlspecialchars((string)($arquivo['titulo'] ?? 'Documento')) ?></div>
                                        <div class="aj-file-subtitle">
                                            <?= htmlspecialchars((string)($arquivo['arquivo_nome'] ?? '')) ?>
                                            • <?= htmlspecialchars(aj_format_bytes(isset($arquivo['tamanho_bytes']) ? (int)$arquivo['tamanho_bytes'] : null)) ?>
                                            <?php if (!empty($arquivo['descricao'])): ?>
                                                <br><?= nl2br(htmlspecialchars((string)$arquivo['descricao'])) ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="aj-badge sign <?= htmlspecialchars($signatureBadgeClass) ?>"><?= htmlspecialchars(aj_assinatura_label($signatureStatus)) ?></span>
                                        <?php if (!empty($signatureSigners)): ?>
                                            <div class="aj-file-subtitle" style="margin-top:.35rem;">
                                                <?= count($signatureSigners) ?> signatário(s)
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($arquivo['clicksign_ultimo_erro'])): ?>
                                            <div class="aj-file-subtitle" style="margin-top:.35rem; color:#991b1b;"><?= htmlspecialchars((string)$arquivo['clicksign_ultimo_erro']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="aj-file-subtitle">
                                            <?= !empty($arquivo['criado_em']) ? date('d/m/Y H:i', strtotime((string)$arquivo['criado_em'])) : '-' ?><br>
                                            por <?= htmlspecialchars((string)($arquivo['criado_por_nome'] ?? 'sistema')) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="aj-file-actions">
                                            <a href="<?= htmlspecialchars('juridico_download.php?id=' . (int)$arquivo['id']) ?>" class="aj-action-btn neutral" target="_blank">Visualizar</a>
                                            <button type="button" class="aj-action-btn primary" onclick='openMoveFileModal(<?= (int)$arquivo["id"] ?>, <?= json_encode((string)($arquivo["titulo"] ?? "Arquivo"), JSON_UNESCAPED_UNICODE) ?>, <?= (int)$currentPastaId ?>, <?= json_encode((string)($currentPasta["nome"] ?? ""), JSON_UNESCAPED_UNICODE) ?>)'>Mover</button>
                                            <form method="POST" onsubmit="return confirm('Deseja excluir este arquivo?');">
                                                <input type="hidden" name="acao" value="excluir_arquivo">
                                                <input type="hidden" name="arquivo_id" value="<?= (int)$arquivo['id'] ?>">
                                                <button type="submit" class="aj-action-btn danger">Excluir</button>
                                            </form>
                                            <?php if ($signatureActionLabel === 'Solicitar assinatura'): ?>
                                                <button type="button" class="aj-action-btn success" onclick='openSignatureModal(<?= json_encode($signatureOpenPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>)'>
                                                    Solicitar assinatura
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="aj-action-btn warn" onclick='openSignatureStatusModal(<?= json_encode($signatureStatusPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>)'>
                                                    Acompanhar assinatura
                                                </button>
                                            <?php endif; ?>
                                            <?php if (!empty($signatureDocumentLinks['signed'])): ?>
                                                <a href="<?= htmlspecialchars((string)$signatureDocumentLinks['signed']) ?>" class="aj-action-btn success" target="_blank">Ver assinado</a>
                                            <?php endif; ?>
                                            <?php if (!empty($arquivo['clicksign_sign_url']) && $signatureActionLabel !== 'Solicitar assinatura'): ?>
                                                <a href="<?= htmlspecialchars((string)$arquivo['clicksign_sign_url']) ?>" class="aj-action-btn primary" target="_blank">Abrir assinatura</a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
    </main>
</div>

<div class="aj-modal" id="modalAssinatura" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header" style="background:#0f766e;">
            <h3>Gerar assinatura</h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalAssinatura')">×</button>
        </div>
        <div class="aj-modal-body">
            <form method="POST">
                <input type="hidden" name="acao" value="solicitar_assinatura">
                <input type="hidden" name="arquivo_id" id="signatureArquivoId" value="">

                <div class="aj-grid">
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Documento</label>
                        <input type="text" id="signatureArquivoTitulo" readonly>
                        <div class="aj-help" id="signatureArquivoNome"></div>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Nome do envelope *</label>
                        <input type="text" name="envelope_name" id="signatureEnvelopeName" maxlength="255" required>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Prazo para assinatura *</label>
                        <input type="datetime-local" name="deadline_at" id="signatureDeadlineAt" required>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Signatários *</label>
                        <div class="aj-help" style="margin-bottom:.6rem;">Você pode adicionar mais pessoas. A ordem define a sequência da assinatura.</div>
                        <div class="aj-signers-stack" id="signatureSignersStack"></div>
                        <button type="button" class="aj-action-btn primary" style="margin-top:.75rem;" onclick="addSignatureSignerRow()">+ Adicionar pessoa</button>
                    </div>
                </div>

                <div class="aj-modal-actions">
                    <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalAssinatura')">Cancelar</button>
                    <button type="submit" class="aj-btn secondary">Gerar assinatura</button>
                </div>
            </form>
        </div>
    </div>
</div>

<template id="signatureSignerRowTemplate">
    <div class="aj-signer-row">
        <div class="aj-signer-row-head">
            <div class="aj-signer-row-title">Signatário <span class="signature-signer-index">1</span></div>
            <button type="button" class="aj-signer-row-remove" onclick="removeSignatureSignerRow(this)">Remover</button>
        </div>
        <div class="aj-grid">
            <div class="aj-field">
                <label>Nome completo *</label>
                <input type="text" name="signer_name[]" maxlength="255" required>
            </div>
            <div class="aj-field">
                <label>E-mail *</label>
                <input type="email" name="signer_email[]" maxlength="180" required>
            </div>
            <div class="aj-field" style="grid-column: 1 / -1;">
                <label>Ordem de assinatura *</label>
                <input type="number" name="signer_group[]" min="1" step="1" value="1" required>
                <div class="aj-help">Use o mesmo número para assinarem em paralelo. Números diferentes criam ordem sequencial.</div>
            </div>
        </div>
    </div>
</template>

<div class="aj-modal" id="modalSignatureStatus" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header" style="background:#334155;">
            <h3>Acompanhamento da assinatura</h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalSignatureStatus')">×</button>
        </div>
        <div class="aj-modal-body">
            <div class="aj-status-board">
                <div class="aj-status-card">
                    <div class="aj-status-head">
                        <div>
                            <div class="aj-status-title" id="signatureStatusTitle">Documento</div>
                            <div class="aj-status-meta" id="signatureStatusMeta">Envelope</div>
                        </div>
                        <span class="aj-badge sign" id="signatureStatusBadge">Sem assinatura</span>
                    </div>
                    <div class="aj-status-list" id="signatureStatusSigners"></div>
                    <div class="aj-link-list" id="signatureStatusLinks"></div>
                </div>
                <div class="aj-status-card">
                    <div class="aj-status-title">Linha do tempo</div>
                    <div class="aj-status-timeline" id="signatureStatusTimeline"></div>
                </div>
            </div>

            <div class="aj-modal-actions">
                <form method="POST">
                    <input type="hidden" name="acao" value="reenviar_assinatura">
                    <input type="hidden" name="arquivo_id" id="signatureStatusResendId" value="">
                    <button type="submit" class="aj-action-btn primary">Reenviar lembrete</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="acao" value="atualizar_assinatura">
                    <input type="hidden" name="arquivo_id" id="signatureStatusRefreshId" value="">
                    <button type="submit" class="aj-action-btn warn">Atualizar andamento</button>
                </form>
                <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalSignatureStatus')">Fechar</button>
            </div>
        </div>
    </div>
</div>

<div class="aj-modal" id="modalMoverArquivo" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header" style="background:#334155;">
            <h3>Mover arquivo</h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalMoverArquivo')">×</button>
        </div>
        <div class="aj-modal-body">
            <form method="POST">
                <input type="hidden" name="acao" value="mover_arquivo">
                <input type="hidden" name="arquivo_id" id="moveArquivoId" value="">

                <div class="aj-grid">
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Arquivo</label>
                        <input type="text" id="moveArquivoTitulo" readonly>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Pasta atual</label>
                        <input type="text" id="moveArquivoPastaAtual" readonly>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Nova pasta *</label>
                        <select name="nova_pasta_id" id="moveNovaPastaId" required>
                            <option value="">Selecione a pasta</option>
                            <?= $folderOptionsHtml ?>
                        </select>
                    </div>
                </div>

                <div class="aj-modal-actions">
                    <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalMoverArquivo')">Cancelar</button>
                    <button type="submit" class="aj-btn dark">Mover arquivo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="aj-modal" id="modalPasta" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header">
            <h3><?= $currentPastaId > 0 ? 'Criar subpasta' : 'Criar pasta' ?></h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalPasta')">×</button>
        </div>
        <div class="aj-modal-body">
            <form method="POST">
                <input type="hidden" name="acao" value="criar_pasta">
                <input type="hidden" name="parent_id" value="<?= $currentPastaId ?>">

                <div class="aj-grid">
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Nome da pasta *</label>
                        <input type="text" name="nome_pasta" maxlength="150" required>
                        <div class="aj-help">
                            <?= $currentPastaId > 0 ? 'A subpasta será criada dentro de ' . htmlspecialchars((string)($currentPasta['nome'] ?? 'Pasta atual')) . '.' : 'Sem pasta atual, a nova pasta será criada na raiz como pasta geral/legada.' ?>
                        </div>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Descrição</label>
                        <textarea name="descricao_pasta" placeholder="Descrição opcional da pasta"></textarea>
                    </div>
                </div>

                <div class="aj-modal-actions">
                    <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalPasta')">Cancelar</button>
                    <button type="submit" class="aj-btn">Salvar pasta</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="aj-modal" id="modalArquivo" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header" style="background:#0f766e;">
            <h3>Adicionar arquivo</h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalArquivo')">×</button>
        </div>
        <div class="aj-modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="adicionar_arquivo">

                <div class="aj-grid">
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Pasta *</label>
                        <select name="pasta_id" required>
                            <option value="">Selecione a pasta</option>
                            <?= $folderOptionsHtml ?>
                        </select>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Título do arquivo</label>
                        <input type="text" name="titulo" maxlength="255" placeholder="Se vazio, usa o nome original do arquivo">
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Descrição</label>
                        <textarea name="descricao" placeholder="Descrição opcional do arquivo"></textarea>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Arquivo *</label>
                        <input type="file" name="arquivo" required>
                    </div>
                </div>

                <div class="aj-modal-actions">
                    <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalArquivo')">Cancelar</button>
                    <button type="submit" class="aj-btn secondary">Enviar arquivo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="aj-modal" id="modalUsuario" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header" style="background:#334155;">
            <h3>Acesso do jurídico</h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalUsuario')">×</button>
        </div>
        <div class="aj-modal-body">
            <?php if (!empty($usuariosJuridico)): ?>
                <div class="aj-users-list" style="margin-bottom:1rem;">
                    <?php foreach ($usuariosJuridico as $user): ?>
                        <div class="aj-user-card">
                            <h3><?= htmlspecialchars((string)($user['nome'] ?? '')) ?></h3>
                            <p><?= !empty($user['email']) ? htmlspecialchars((string)$user['email']) : 'Sem e-mail' ?></p>
                            <div style="margin-top:.7rem;">
                                <button
                                    type="button"
                                    class="aj-btn-outline"
                                    onclick='openEditUserModal(<?= (int)($user["id"] ?? 0) ?>, <?= json_encode((string)($user["nome"] ?? ""), JSON_UNESCAPED_UNICODE) ?>, <?= json_encode((string)($user["email"] ?? ""), JSON_UNESCAPED_UNICODE) ?>)'>
                                    Editar acesso
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="acao" value="cadastrar_usuario">

                <div class="aj-grid">
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Nome *</label>
                        <input type="text" name="nome_usuario" maxlength="120" required>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>E-mail</label>
                        <input type="email" name="email_usuario" maxlength="180" placeholder="Opcional">
                    </div>
                    <div class="aj-field">
                        <label>Senha *</label>
                        <input type="password" name="senha_usuario" minlength="6" required>
                    </div>
                    <div class="aj-field">
                        <label>Confirmar senha *</label>
                        <input type="password" name="senha_usuario_confirmacao" minlength="6" required>
                    </div>
                </div>

                <div class="aj-link-box">
                    <strong>Link do portal jurídico</strong>
                    <div class="aj-copy-row">
                        <input type="text" id="juridicoLoginLink" value="<?= htmlspecialchars($juridicoLoginLink) ?>" readonly>
                        <button type="button" class="aj-btn dark" onclick="copyJuridicoLink()">Copiar</button>
                    </div>
                </div>

                <div class="aj-modal-actions">
                    <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalUsuario')">Cancelar</button>
                    <button type="submit" class="aj-btn dark">Salvar acesso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="aj-modal" id="modalEditarUsuario" aria-hidden="true">
    <div class="aj-modal-dialog">
        <div class="aj-modal-header" style="background:#334155;">
            <h3>Editar acesso do jurídico</h3>
            <button type="button" class="aj-modal-close" onclick="closeAjModal('modalEditarUsuario')">×</button>
        </div>
        <div class="aj-modal-body">
            <form method="POST">
                <input type="hidden" name="acao" value="atualizar_usuario">
                <input type="hidden" name="usuario_id" id="editUsuarioId" value="">

                <div class="aj-grid">
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>Nome *</label>
                        <input type="text" name="nome_usuario" id="editNomeUsuario" maxlength="120" required>
                    </div>
                    <div class="aj-field" style="grid-column: 1 / -1;">
                        <label>E-mail</label>
                        <input type="email" name="email_usuario" id="editEmailUsuario" maxlength="180" placeholder="Opcional">
                    </div>
                    <div class="aj-field">
                        <label>Nova senha</label>
                        <input type="password" name="senha_usuario_nova" minlength="6">
                        <div class="aj-help">Deixe em branco para manter a senha atual.</div>
                    </div>
                    <div class="aj-field">
                        <label>Confirmar nova senha</label>
                        <input type="password" name="senha_usuario_nova_confirmacao" minlength="6">
                    </div>
                </div>

                <div class="aj-modal-actions">
                    <button type="button" class="aj-btn-outline" onclick="closeAjModal('modalEditarUsuario')">Cancelar</button>
                    <button type="submit" class="aj-btn dark">Atualizar acesso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAjModal(id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
}

function closeAjModal(id) {
    var modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
}

document.querySelectorAll('.aj-modal').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        }
    });
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('.aj-modal.open').forEach(function (modal) {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
    });
});

function openEditUserModal(id, nome, email) {
    document.getElementById('editUsuarioId').value = id || '';
    document.getElementById('editNomeUsuario').value = nome || '';
    document.getElementById('editEmailUsuario').value = email || '';
    openAjModal('modalEditarUsuario');
}

function getSignatureSignersStack() {
    return document.getElementById('signatureSignersStack');
}

function refreshSignatureSignerIndexes() {
    var stack = getSignatureSignersStack();
    if (!stack) return;
    Array.prototype.forEach.call(stack.querySelectorAll('.aj-signer-row'), function (row, index) {
        var indexLabel = row.querySelector('.signature-signer-index');
        var removeButton = row.querySelector('.aj-signer-row-remove');
        if (indexLabel) {
            indexLabel.textContent = String(index + 1);
        }
        if (removeButton) {
            removeButton.style.display = index === 0 && stack.children.length <= 1 ? 'none' : 'inline-flex';
        }
    });
}

function addSignatureSignerRow(data) {
    var template = document.getElementById('signatureSignerRowTemplate');
    var stack = getSignatureSignersStack();
    if (!template || !stack) return;

    var fragment = template.content.cloneNode(true);
    var row = fragment.querySelector('.aj-signer-row');
    if (!row) return;

    var nameInput = row.querySelector('input[name="signer_name[]"]');
    var emailInput = row.querySelector('input[name="signer_email[]"]');
    var groupInput = row.querySelector('input[name="signer_group[]"]');

    if (nameInput) nameInput.value = data && data.name ? data.name : '';
    if (emailInput) emailInput.value = data && data.email ? data.email : '';
    if (groupInput) groupInput.value = data && data.group ? data.group : String(stack.children.length + 1);

    stack.appendChild(fragment);
    refreshSignatureSignerIndexes();
}

function removeSignatureSignerRow(button) {
    var stack = getSignatureSignersStack();
    if (!stack) return;
    var row = button && button.closest ? button.closest('.aj-signer-row') : null;
    if (!row) return;
    if (stack.children.length <= 1) {
        row.querySelectorAll('input').forEach(function (input) { input.value = ''; });
        refreshSignatureSignerIndexes();
        return;
    }
    row.remove();
    refreshSignatureSignerIndexes();
}

function resetSignatureSignerRows(signers) {
    var stack = getSignatureSignersStack();
    if (!stack) return;
    stack.innerHTML = '';
    if (Array.isArray(signers) && signers.length) {
        signers.forEach(function (signer) {
            addSignatureSignerRow(signer || {});
        });
    } else {
        addSignatureSignerRow({});
    }
}

function applySignatureModalState(state) {
    if (!state) return;
    document.getElementById('signatureArquivoId').value = state.arquivo_id || '';
    document.getElementById('signatureArquivoTitulo').value = state.arquivo_titulo || '';
    document.getElementById('signatureArquivoNome').textContent = state.arquivo_nome || '';
    document.getElementById('signatureEnvelopeName').value = state.envelope_name || '';
    document.getElementById('signatureDeadlineAt').value = state.deadline_at || '';
    resetSignatureSignerRows(state.signers || []);
}

function openSignatureModal(data) {
    applySignatureModalState(data || {});
    openAjModal('modalAssinatura');
}

function openSignatureStatusModal(data) {
    data = data || {};

    var badge = document.getElementById('signatureStatusBadge');
    var signersList = document.getElementById('signatureStatusSigners');
    var timeline = document.getElementById('signatureStatusTimeline');
    var links = document.getElementById('signatureStatusLinks');

    document.getElementById('signatureStatusTitle').textContent = data.titulo || 'Documento';
    document.getElementById('signatureStatusMeta').textContent = data.envelope_id ? ('Envelope ' + data.envelope_id) : 'Envelope ainda não gerado';
    document.getElementById('signatureStatusRefreshId').value = data.arquivo_id || '';
    document.getElementById('signatureStatusResendId').value = data.arquivo_id || '';

    if (badge) {
        badge.className = 'aj-badge sign ' + (data.status_class || '');
        badge.textContent = data.status_label || 'Sem assinatura';
    }

    if (signersList) {
        signersList.innerHTML = '';
        if (Array.isArray(data.signers) && data.signers.length) {
            data.signers.forEach(function (signer) {
                var item = document.createElement('div');
                item.className = 'aj-status-item';
                item.innerHTML =
                    '<div><strong>' + escapeHtml(signer.name || 'Signatário') + '</strong><div class="aj-status-meta">' + escapeHtml(signer.email || '') + '</div></div>' +
                    '<div style="text-align:right;"><span class="aj-badge sign ' + statusBadgeClassForSigner(signer.status || '') + '">' + escapeHtml(signer.status_label || 'Aguardando') + '</span>' +
                    ((signer.signature_url || '') ? '<div style="margin-top:.4rem;"><a class="aj-action-btn primary" href="' + escapeAttribute(signer.signature_url) + '" target="_blank">Abrir assinatura</a></div>' : '') +
                    '</div>';
                signersList.appendChild(item);
            });
        } else {
            signersList.innerHTML = '<div class="aj-status-meta">Ainda não há signatários sincronizados para este documento.</div>';
        }
    }

    if (timeline) {
        timeline.innerHTML = '';
        if (Array.isArray(data.events) && data.events.length) {
            data.events.forEach(function (eventItem) {
                var item = document.createElement('div');
                item.className = 'aj-status-event';
                item.innerHTML =
                    '<strong>' + escapeHtml(eventLabel(eventItem.name || '')) + '</strong>' +
                    (eventItem.signer_name ? '<div>' + escapeHtml(eventItem.signer_name) + '</div>' : '') +
                    (eventItem.created ? '<div class="aj-status-meta">' + escapeHtml(formatDateTime(eventItem.created)) + '</div>' : '');
                timeline.appendChild(item);
            });
        } else {
            timeline.innerHTML = '<div class="aj-status-meta">Nenhum evento retornado ainda pela Clicksign.</div>';
        }
    }

    if (links) {
        links.innerHTML = '';
        appendStatusLink(links, data.original_url, 'Ver original', 'neutral');
        appendStatusLink(links, data.signed_url, 'Ver assinado', 'success');
        appendStatusLink(links, data.zip_url, 'Baixar pacote', 'primary');
    }

    openAjModal('modalSignatureStatus');
}

function openMoveFileModal(id, titulo, pastaIdAtual, pastaNomeAtual) {
    var selectNovaPasta = document.getElementById('moveNovaPastaId');
    document.getElementById('moveArquivoId').value = id || '';
    document.getElementById('moveArquivoTitulo').value = titulo || '';
    document.getElementById('moveArquivoPastaAtual').value = pastaNomeAtual || '';

    if (selectNovaPasta) {
        selectNovaPasta.value = '';
        Array.prototype.forEach.call(selectNovaPasta.options, function (option) {
            option.disabled = String(option.value || '') === String(pastaIdAtual || '');
        });
    }

    openAjModal('modalMoverArquivo');
}

function appendStatusLink(container, url, label, tone) {
    if (!container || !url) return;
    var link = document.createElement('a');
    link.href = url;
    link.target = '_blank';
    link.className = 'aj-action-btn ' + (tone || 'neutral');
    link.textContent = label;
    container.appendChild(link);
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeAttribute(value) {
    return escapeHtml(value);
}

function formatDateTime(value) {
    if (!value) return '';
    var parsed = new Date(value);
    if (isNaN(parsed.getTime())) return value;
    return parsed.toLocaleString('pt-BR');
}

function eventLabel(name) {
    var labels = {
        upload: 'Documento enviado',
        add_signer: 'Signatário adicionado',
        signature_started: 'Assinatura iniciada',
        sign: 'Documento assinado',
        refuse: 'Assinatura recusada',
        auto_close: 'Envelope encerrado',
        document_closed: 'Documento finalizado'
    };
    return labels[name] || name || 'Evento';
}

function statusBadgeClassForSigner(status) {
    if (status === 'assinado') return 'success';
    if (status === 'recusado') return 'error';
    if (status === 'em_andamento') return 'warn';
    return '';
}

function copyJuridicoLink() {
    var input = document.getElementById('juridicoLoginLink');
    if (!input) return;
    var value = input.value;

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(function () {
            if (typeof customAlert === 'function') {
                customAlert('Link copiado para a área de transferência.', 'Sucesso');
            } else {
                alert('Link copiado com sucesso.');
            }
        });
        return;
    }

    input.select();
    document.execCommand('copy');
}

resetSignatureSignerRows([]);

<?php if ($signatureModalState !== null && $erro !== '' && $lastPostAction === 'solicitar_assinatura'): ?>
applySignatureModalState(<?= json_encode($signatureModalState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
openAjModal('modalAssinatura');
<?php endif; ?>
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Jurídico');
echo $conteudo;
endSidebar();
