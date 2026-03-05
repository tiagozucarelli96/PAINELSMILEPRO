<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/setup_administrativo_juridico.php';

setupAdministrativoJuridico($pdo);

function jd_error(string $mensagem, int $status = 400): void
{
    http_response_code($status);
    $mensagemSegura = htmlspecialchars($mensagem);

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Arquivo indisponível</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:32px}';
    echo '.card{max-width:680px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:22px;box-shadow:0 4px 12px rgba(15,23,42,.08)}';
    echo '.title{font-size:20px;font-weight:700;margin-bottom:8px}';
    echo '.desc{color:#475569;margin-bottom:18px}';
    echo '.btn{display:inline-block;background:#1e40af;color:#fff;text-decoration:none;padding:10px 15px;border-radius:8px}';
    echo '</style></head><body><div class="card">';
    echo '<div class="title">Arquivo indisponível</div>';
    echo '<div class="desc">' . $mensagemSegura . '</div>';
    echo '<a class="btn" href="javascript:history.back()">Voltar</a>';
    echo '</div></body></html>';
    exit;
}

$isAdmin = !empty($_SESSION['logado']) && !empty($_SESSION['perm_administrativo']);
$isJuridico = !empty($_SESSION['juridico_logado']) && !empty($_SESSION['juridico_usuario_id']);

if (!$isAdmin && !$isJuridico) {
    jd_error('Acesso negado. Faça login no portal jurídico para visualizar o arquivo.', 403);
}

if (!$isAdmin && $isJuridico) {
    try {
        $stmtUsuario = $pdo->prepare('SELECT id FROM administrativo_juridico_usuarios WHERE id = :id AND ativo = TRUE LIMIT 1');
        $stmtUsuario->execute([':id' => (int)$_SESSION['juridico_usuario_id']]);
        if (!$stmtUsuario->fetchColumn()) {
            jd_error('Sessão jurídica inválida ou usuário inativo.', 403);
        }
    } catch (Exception $e) {
        error_log('Juridico download - validar usuario: ' . $e->getMessage());
        jd_error('Não foi possível validar seu acesso no momento.', 500);
    }
}

$arquivoId = (int)($_GET['id'] ?? 0);
if ($arquivoId <= 0) {
    jd_error('Parâmetros inválidos para download.', 400);
}

$arquivo = null;
try {
    $stmt = $pdo->prepare(
        'SELECT id, arquivo_nome, arquivo_url, chave_storage
         FROM administrativo_juridico_arquivos
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $arquivoId]);
    $arquivo = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    error_log('Juridico download - buscar arquivo: ' . $e->getMessage());
    jd_error('Erro ao localizar o arquivo solicitado.', 500);
}

if (!$arquivo) {
    jd_error('Arquivo não encontrado.', 404);
}

$arquivoNome = (string)($arquivo['arquivo_nome'] ?? 'arquivo');
$arquivoUrl = trim((string)($arquivo['arquivo_url'] ?? ''));
$chaveStorage = trim((string)($arquivo['chave_storage'] ?? ''));

if ($chaveStorage !== '' && file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';

    if (class_exists('Aws\\S3\\S3Client')) {
        $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
        $region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
        $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
        $accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
        $secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');

        if (!empty($accessKey) && !empty($secretKey)) {
            try {
                $s3Client = new \Aws\S3\S3Client([
                    'region' => $region,
                    'version' => 'latest',
                    'credentials' => [
                        'key' => $accessKey,
                        'secret' => $secretKey,
                    ],
                    'endpoint' => $endpoint,
                    'use_path_style_endpoint' => true,
                    'signature_version' => 'v4',
                ]);

                $cmd = $s3Client->getCommand('GetObject', [
                    'Bucket' => strtolower((string)$bucket),
                    'Key' => $chaveStorage,
                    'ResponseContentDisposition' => 'inline; filename="' . addslashes($arquivoNome) . '"',
                ]);

                $urlAssinada = (string)$s3Client->createPresignedRequest($cmd, '+1 hour')->getUri();
                if ($urlAssinada !== '') {
                    header('Location: ' . $urlAssinada);
                    exit;
                }
            } catch (Exception $e) {
                error_log('Juridico download - presigned URL: ' . $e->getMessage());
            }
        }
    }
}

if ($arquivoUrl !== '') {
    header('Location: ' . $arquivoUrl);
    exit;
}

jd_error('Não foi possível gerar o acesso ao arquivo solicitado.', 500);
