<?php
// logistica_upload.php — Upload Magalu para Catálogo Logístico
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['logado']) || (empty($_SESSION['perm_logistico']) && empty($_SESSION['perm_superadmin']))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sem permissão para upload.']);
    exit;
}

require_once __DIR__ . '/upload_magalu.php';

function gerarUrlPreviewMagalu(?string $chave_storage, ?string $fallback_url): ?string {
    if (!empty($chave_storage)) {
        if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            if (class_exists('Aws\\S3\\S3Client')) {
                try {
                    $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
                    $region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
                    $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
                    $accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
                    $secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
                    $bucket = strtolower($bucket);

                    if ($accessKey && $secretKey) {
                        $s3Client = new \Aws\S3\S3Client([
                            'region' => $region,
                            'version' => 'latest',
                            'credentials' => [
                                'key' => $accessKey,
                                'secret' => $secretKey,
                            ],
                            'endpoint' => $endpoint,
                            'use_path_style_endpoint' => true,
                        ]);

                        $cmd = $s3Client->getCommand('GetObject', [
                            'Bucket' => $bucket,
                            'Key' => $chave_storage,
                        ]);
                        $presignedUrl = $s3Client->createPresignedRequest($cmd, '+1 hour')->getUri();
                        return (string)$presignedUrl;
                    }
                } catch (Throwable $e) {
                    error_log("Erro ao gerar URL presigned (upload logistica): " . $e->getMessage());
                }
            }
        }

        $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
        $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
        return rtrim($endpoint, '/') . '/' . strtolower($bucket) . '/' . ltrim($chave_storage, '/');
    }

    return $fallback_url ?: null;
}

try {
    if (empty($_FILES['file'])) {
        throw new Exception('Arquivo não enviado.');
    }

    $context = $_POST['context'] ?? 'catalogo';
    $prefix = 'logistica/catalogo';
    if ($context === 'insumo') {
        $prefix = 'logistica/insumos';
    } elseif ($context === 'receita') {
        $prefix = 'logistica/receitas';
    }

    $uploader = new MagaluUpload();
    $result = $uploader->upload($_FILES['file'], $prefix);

    if (empty($result['url']) && empty($result['chave_storage'])) {
        throw new Exception('Upload não retornou URL.');
    }

    $previewUrl = gerarUrlPreviewMagalu($result['chave_storage'] ?? null, $result['url'] ?? null);

    echo json_encode([
        'ok' => true,
        'url' => $previewUrl,
        'chave_storage' => $result['chave_storage'] ?? null,
        'nome_original' => $result['nome_original'] ?? null
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
