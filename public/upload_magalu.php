<?php
// upload_magalu.php - Helper de upload para Magalu Cloud
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se usuário está logado
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autorizado']);
    exit;
}

require_once __DIR__ . '/conexao.php';

class MagaluUpload {
    private $bucket;
    private $region;
    private $endpoint;
    private $accessKey;
    private $secretKey;
    private $maxSizeMB;
    
    public function __construct() {
        $this->bucket = getenv('MAGALU_BUCKET') ?: 'SmilePainel';
        $this->region = getenv('MAGALU_REGION') ?: 'br-se1';
        $this->endpoint = getenv('MAGALU_ENDPOINT') ?: 'https://br-se1.magaluobjects.com';
        $this->accessKey = getenv('MAGALU_ACCESS_KEY');
        $this->secretKey = getenv('MAGALU_SECRET_KEY');
        $this->maxSizeMB = (int)(getenv('UPLOAD_MAX_MB') ?: 10);
        
        if (!$this->accessKey || !$this->secretKey) {
            throw new Exception('Credenciais Magalu não configuradas');
        }
    }
    
    public function upload($file, $prefix = 'demandas') {
        // Validar arquivo
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new Exception('Arquivo inválido');
        }
        
        // Validar tamanho
        $maxBytes = $this->maxSizeMB * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            throw new Exception("Arquivo muito grande. Máximo: {$this->maxSizeMB}MB");
        }
        
        // Validar MIME type
        $allowedTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'text/plain', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Tipo de arquivo não permitido');
        }
        
        // Gerar chave de armazenamento
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $uuid = uniqid();
        $year = date('Y');
        $month = date('m');
        $key = "{$prefix}/{$year}/{$month}/{$uuid}.{$extension}";
        
        // Upload para Magalu Cloud (simulado - implementar SDK real)
        $url = $this->uploadToMagalu($file['tmp_name'], $key, $mimeType);
        
        return [
            'chave_storage' => $key,
            'url' => $url,
            'nome_original' => $file['name'],
            'mime_type' => $mimeType,
            'tamanho_bytes' => $file['size']
        ];
    }
    
    private function uploadToMagalu($tmpFile, $key, $mimeType) {
        // TODO: Implementar upload real para Magalu Cloud
        // Por enquanto, simular sucesso
        return "https://{$this->bucket}.{$this->region}.magaluobjects.com/{$key}";
    }
    
    public function delete($key) {
        // Implementação de delete no Magalu Cloud
        // Usando API REST do S3-compatible (Magalu Cloud)
        
        if (empty($key)) {
            return false;
        }
        
        try {
            // Construir URL do objeto
            $url = "{$this->endpoint}/{$this->bucket}/{$key}";
            
            // Preparar requisição DELETE
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: AWS ' . $this->accessKey . ':' . $this->generateSignature('DELETE', $key)
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // 204 (No Content) ou 200 (OK) indicam sucesso
            return $httpCode === 204 || $httpCode === 200;
            
        } catch (Exception $e) {
            error_log("Erro ao deletar do Magalu Cloud: " . $e->getMessage());
            return false;
        }
    }
    
    private function generateSignature($method, $key) {
        // Gerar assinatura AWS S3-compatible para autenticação
        // Simplificado - em produção, usar AWS SDK ou biblioteca adequada
        $date = gmdate('Ymd\THis\Z');
        $stringToSign = "{$method}\n\n\n{$date}\n/{$this->bucket}/{$key}";
        return base64_encode(hash_hmac('sha1', $stringToSign, $this->secretKey, true));
    }
}

// API endpoint
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $uploader = new MagaluUpload();
        
        if (!isset($_FILES['file'])) {
            throw new Exception('Nenhum arquivo enviado');
        }
        
        $result = $uploader->upload($_FILES['file']);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $result
        ]);
        
    } catch (Exception $e) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
}
