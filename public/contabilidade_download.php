<?php
// contabilidade_download.php — Download seguro de arquivos da contabilidade
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

function contabilidadeColunaExiste(PDO $pdo, string $tabela, string $coluna): bool
{
    $stmt = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :tabela AND column_name = :coluna"
    );
    $stmt->execute([':tabela' => $tabela, ':coluna' => $coluna]);

    return (bool) $stmt->fetchColumn();
}

function contabilidadeDownloadErro(string $mensagem, ?string $detalhe = null): void
{
    $voltar = $_SERVER['HTTP_REFERER'] ?? 'contabilidade_painel.php';
    $mensagem_segura = htmlspecialchars($mensagem);
    $detalhe_seguro = $detalhe ? htmlspecialchars($detalhe) : '';

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Arquivo indisponivel</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:32px}';
    echo '.card{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:24px;box-shadow:0 4px 12px rgba(15,23,42,.08)}';
    echo '.title{font-size:20px;font-weight:700;margin-bottom:8px}';
    echo '.desc{color:#475569;margin-bottom:16px}';
    echo '.detail{background:#f1f5f9;border-radius:8px;padding:12px;color:#0f172a;font-size:14px}';
    echo '.actions{margin-top:20px}';
    echo '.btn{display:inline-block;background:#1e40af;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px}';
    echo '</style></head><body><div class="card">';
    echo '<div class="title">Arquivo indisponivel</div>';
    echo '<div class="desc">' . $mensagem_segura . '</div>';
    if ($detalhe_seguro) {
        echo '<div class="detail">' . $detalhe_seguro . '</div>';
    }
    echo '<div class="actions"><a class="btn" href="' . htmlspecialchars($voltar) . '">Voltar</a></div>';
    echo '</div></body></html>';
    exit;
}

// Verificar permissão (admin ou contabilidade logada; ou dono do holerite individual)
$is_admin = !empty($_SESSION['logado']) && !empty($_SESSION['perm_administrativo']);
$is_contabilidade = !empty($_SESSION['contabilidade_logado']) && $_SESSION['contabilidade_logado'] === true;
$tipo = $_GET['tipo'] ?? '';
$permite_holerite_individual = ($tipo === 'holerite_individual' && !empty($_SESSION['logado']));
$permite_gestao_documento = ($tipo === 'gestao_documento' && !empty($_SESSION['logado']));

if (!$is_admin && !$is_contabilidade && !$permite_holerite_individual && !$permite_gestao_documento) {
    http_response_code(403);
    contabilidadeDownloadErro('Acesso negado. Você precisa estar logado como administrador ou contador.');
}

$id = (int)($_GET['id'] ?? 0);
$debug = !empty($_GET['debug']) && $is_admin;

if (empty($tipo) || $id <= 0) {
    http_response_code(400);
    contabilidadeDownloadErro('Parametros invalidos para download.');
}

try {
    $chave_storage = null;
    $nome_arquivo = null;
    $arquivo_url = null;
    $mime_type = null;

    $has_chave_storage_guias = contabilidadeColunaExiste($pdo, 'contabilidade_guias', 'chave_storage');
    $has_chave_storage_holerites = contabilidadeColunaExiste($pdo, 'contabilidade_holerites', 'chave_storage');
    $has_chave_storage_honorarios = contabilidadeColunaExiste($pdo, 'contabilidade_honorarios', 'chave_storage');
    $has_chave_storage_conversas = contabilidadeColunaExiste($pdo, 'contabilidade_conversas_mensagens', 'chave_storage');
    $has_chave_storage_docs = contabilidadeColunaExiste($pdo, 'contabilidade_colaboradores_documentos', 'chave_storage');
    
    // Buscar chave_storage de acordo com o tipo
    switch ($tipo) {
        case 'guia':
            $select_cols = $has_chave_storage_guias ? 'chave_storage, arquivo_nome, arquivo_url' : 'arquivo_nome, arquivo_url';
            $stmt = $pdo->prepare("SELECT {$select_cols} FROM contabilidade_guias WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'] ?? null;
                $nome_arquivo = $arquivo['arquivo_nome'];
                $arquivo_url = $arquivo['arquivo_url'] ?? null;
            }
            break;
            
        case 'holerite':
            $select_cols = $has_chave_storage_holerites ? 'chave_storage, arquivo_nome, arquivo_url' : 'arquivo_nome, arquivo_url';
            $stmt = $pdo->prepare("SELECT {$select_cols} FROM contabilidade_holerites WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'] ?? null;
                $nome_arquivo = $arquivo['arquivo_nome'];
                $arquivo_url = $arquivo['arquivo_url'] ?? null;
            }
            break;
            
        case 'honorario':
            $select_cols = $has_chave_storage_honorarios ? 'chave_storage, arquivo_nome, arquivo_url' : 'arquivo_nome, arquivo_url';
            $stmt = $pdo->prepare("SELECT {$select_cols} FROM contabilidade_honorarios WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'] ?? null;
                $nome_arquivo = $arquivo['arquivo_nome'];
                $arquivo_url = $arquivo['arquivo_url'] ?? null;
            }
            break;
            
        case 'conversa_anexo':
            $select_cols = $has_chave_storage_conversas ? 'chave_storage, anexo_nome, anexo_url' : 'anexo_nome, anexo_url';
            $stmt = $pdo->prepare("SELECT {$select_cols} FROM contabilidade_conversas_mensagens WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'] ?? null;
                $nome_arquivo = $arquivo['anexo_nome'];
                $arquivo_url = $arquivo['anexo_url'] ?? null;
            }
            break;
            
        case 'colaborador_doc':
            $select_cols = $has_chave_storage_docs ? 'chave_storage, arquivo_nome, arquivo_url' : 'arquivo_nome, arquivo_url';
            $stmt = $pdo->prepare("SELECT {$select_cols} FROM contabilidade_colaboradores_documentos WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $chave_storage = $arquivo['chave_storage'] ?? null;
                $nome_arquivo = $arquivo['arquivo_nome'];
                $arquivo_url = $arquivo['arquivo_url'] ?? null;
            }
            break;

        case 'holerite_individual':
            $stmt = $pdo->prepare("SELECT usuario_id, chave_storage, arquivo_nome, arquivo_url FROM contabilidade_holerites_individual WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $session_uid = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
                if (!$is_admin && $arquivo['usuario_id'] != $session_uid) {
                    http_response_code(403);
                    contabilidadeDownloadErro('Acesso negado. Este holerite não pertence ao seu usuário.');
                }
                $chave_storage = $arquivo['chave_storage'] ?? null;
                $nome_arquivo = $arquivo['arquivo_nome'];
                $arquivo_url = $arquivo['arquivo_url'] ?? null;
            }
            break;

        case 'gestao_documento':
            $stmt = $pdo->prepare("
                SELECT usuario_id, exibir_minha_conta, chave_storage, arquivo_nome, arquivo_url
                FROM administrativo_documentos_colaboradores
                WHERE id = :id
            ");
            $stmt->execute([':id' => $id]);
            $arquivo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($arquivo) {
                $session_uid = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
                $pertenceAoUsuario = ((int)$arquivo['usuario_id'] === $session_uid);
                $podeVerMinhaConta = !empty($arquivo['exibir_minha_conta']);

                if (!$is_admin && !$pertenceAoUsuario) {
                    http_response_code(403);
                    contabilidadeDownloadErro('Acesso negado. Este documento não pertence ao seu usuário.');
                }
                if (!$is_admin && $pertenceAoUsuario && !$podeVerMinhaConta) {
                    http_response_code(403);
                    contabilidadeDownloadErro('Este documento não está disponível na sua área Minha conta.');
                }

                $chave_storage = $arquivo['chave_storage'] ?? null;
                $nome_arquivo = $arquivo['arquivo_nome'] ?? 'documento';
                $arquivo_url = $arquivo['arquivo_url'] ?? null;
            }
            break;
            
        default:
            http_response_code(400);
            die('Tipo inválido');
    }
    
    if (empty($chave_storage) && empty($arquivo_url)) {
        http_response_code(404);
        contabilidadeDownloadErro('Arquivo nao encontrado ou chave de storage indisponivel.');
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
    if (!empty($arquivo_url)) {
        header("Location: {$arquivo_url}");
        exit;
    }

    $bucket = $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET') ?: 'smilepainel';
    $endpoint = $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
    $bucket = strtolower($bucket);
    $url = "{$endpoint}/{$bucket}/{$chave_storage}";
    
    // Redirecionar para URL do arquivo (pode falhar se não for público)
    header("Location: {$url}");
    exit;
    
} catch (Exception $e) {
    $context = [
        'tipo' => $tipo ?? null,
        'id' => $id ?? null,
        'has_sdk' => class_exists('Aws\\S3\\S3Client'),
        'chave_storage' => $chave_storage ?? null,
        'arquivo_url' => $arquivo_url ?? null,
        'bucket' => $bucket ?? null,
        'endpoint' => $endpoint ?? null,
    ];
    error_log("Erro em contabilidade_download.php: " . $e->getMessage() . " | " . json_encode($context));
    http_response_code(500);
    if ($debug) {
        echo 'Erro ao processar download: ' . htmlspecialchars($e->getMessage());
        exit;
    }
    contabilidadeDownloadErro('Erro ao processar o download.', $e->getMessage());
}
