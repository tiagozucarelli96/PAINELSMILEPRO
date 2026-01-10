<?php
// contabilidade_download.php — Download seguro de arquivos da contabilidade
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar permissão (admin ou contabilidade logada)
$is_admin = !empty($_SESSION['logado']) && !empty($_SESSION['perm_administrativo']);
$is_contabilidade = !empty($_SESSION['contabilidade_logado']) && $_SESSION['contabilidade_logado'] === true;

if (!$is_admin && !$is_contabilidade) {
    http_response_code(403);
    die('Acesso negado');
}

$tipo = $_GET['tipo'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if (empty($tipo) || $id <= 0) {
    http_response_code(400);
    die('Parâmetros inválidos');
}

try {
    $chave_storage = null;
    $nome_arquivo = null;
    $mime_type = null;
    
    // Buscar chave_storage de acordo com o tipo
    switch ($tipo) {
        case 'guia':
            $stmt = $pdo->prepare("SELECT chave_storage, arquivo_nome FROM contabilidade_guias WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'];
                $nome_arquivo = $arquivo['arquivo_nome'];
            }
            break;
            
        case 'holerite':
            $stmt = $pdo->prepare("SELECT chave_storage, arquivo_nome FROM contabilidade_holerites WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'];
                $nome_arquivo = $arquivo['arquivo_nome'];
            }
            break;
            
        case 'honorario':
            $stmt = $pdo->prepare("SELECT chave_storage, arquivo_nome FROM contabilidade_honorarios WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'];
                $nome_arquivo = $arquivo['arquivo_nome'];
            }
            break;
            
        case 'conversa_anexo':
            $stmt = $pdo->prepare("SELECT chave_storage, anexo_nome FROM contabilidade_conversas_mensagens WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'];
                $nome_arquivo = $arquivo['anexo_nome'];
            }
            break;
            
        case 'colaborador_doc':
            $stmt = $pdo->prepare("SELECT chave_storage, arquivo_nome FROM contabilidade_colaboradores_documentos WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'];
                $nome_arquivo = $arquivo['arquivo_nome'];
            }
            break;
            
        default:
            http_response_code(400);
            die('Tipo inválido');
    }
    
    if (empty($chave_storage)) {
        http_response_code(404);
        die('Arquivo não encontrado ou chave de storage não disponível');
    }
    
    // Tentar usar AWS SDK para gerar presigned URL
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        
        if (class_exists('Aws\S3\S3Client')) {
            $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
            $region = $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION') ?: 'br-se1';
            $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
            $accessKey = $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY');
            $secretKey = $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY');
            $bucket = strtolower($bucket);
            
            if ($accessKey && $secretKey) {
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
                    
                    // Gerar URL pré-assinada (válida por 1 hora)
                    $cmd = $s3Client->getCommand('GetObject', [
                        'Bucket' => $bucket,
                        'Key' => $chave_storage,
                    ]);
                    
                    $presignedUrl = $s3Client->createPresignedRequest($cmd, '+1 hour')->getUri();
                    
                    // Redirecionar para URL pré-assinada
                    header("Location: " . (string)$presignedUrl);
                    exit;
                    
                } catch (\Aws\Exception\AwsException $e) {
                    error_log("Erro AWS SDK no presigned URL: " . $e->getMessage());
                    // Fallback: tentar baixar diretamente e servir
                    try {
                        $result = $s3Client->getObject([
                            'Bucket' => $bucket,
                            'Key' => $chave_storage,
                        ]);
                        
                        // Detectar MIME type
                        $mime_type = $result['ContentType'] ?? 'application/octet-stream';
                        if (empty($mime_type)) {
                            $finfo = finfo_open(FILEINFO_MIME_TYPE);
                            $mime_type = finfo_buffer($finfo, $result['Body']->getContents());
                            finfo_close($finfo);
                            $result['Body']->rewind();
                        }
                        
                        // Servir o arquivo
                        header('Content-Type: ' . $mime_type);
                        header('Content-Disposition: inline; filename="' . addslashes($nome_arquivo ?: 'arquivo') . '"');
                        header('Content-Length: ' . ($result['ContentLength'] ?? 0));
                        ob_clean();
                        echo $result['Body'];
                        exit;
                        
                    } catch (\Exception $e2) {
                        error_log("Erro ao baixar arquivo: " . $e2->getMessage());
                        http_response_code(500);
                        die('Erro ao baixar arquivo');
                    }
                }
            }
        }
    }
    
    // Fallback: construir URL direta (pode não funcionar se arquivo não for público)
    $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
    $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
    $bucket = strtolower($bucket);
    $url = "{$endpoint}/{$bucket}/{$chave_storage}";
    
    // Redirecionar para URL do arquivo (pode falhar se não for público)
    header("Location: {$url}");
    exit;
    
} catch (Exception $e) {
    error_log("Erro em contabilidade_download.php: " . $e->getMessage());
    http_response_code(500);
    die('Erro ao processar download');
}
