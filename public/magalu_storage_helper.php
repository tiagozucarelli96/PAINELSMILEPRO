<?php
// magalu_storage_helper.php — Helper para Magalu Object Storage
class MagaluStorageHelper {
    private $access_key;
    private $secret_key;
    private $bucket;
    private $region;
    private $endpoint;
    
    public function __construct() {
        $this->access_key = $_ENV['MAGALU_ACCESS_KEY'] ?? '';
        $this->secret_key = $_ENV['MAGALU_SECRET_KEY'] ?? '';
        $this->bucket = $_ENV['MAGALU_BUCKET'] ?? '';
        $this->region = $_ENV['MAGALU_REGION'] ?? 'br-se1';
        $this->endpoint = $_ENV['MAGALU_ENDPOINT'] ?? 'https://br-se1.magaluobjects.com';
    }
    
    /**
     * Upload de arquivo para Magalu Object Storage
     */
    public function uploadFile($file, $subfolder = '') {
        if (!$this->isConfigured()) {
            throw new Exception('Magalu Object Storage não configurado');
        }
        
        // Validar arquivo
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Erro no upload do arquivo');
        }
        
        // Validar tamanho (10MB máximo)
        if ($file['size'] > 10485760) {
            throw new Exception('Arquivo muito grande. Máximo: 10MB');
        }
        
        // Validar tipo
        $allowed_types = ['pdf', 'jpg', 'jpeg', 'png', 'gif'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed_types)) {
            throw new Exception('Tipo de arquivo não permitido. Permitidos: ' . implode(', ', $allowed_types));
        }
        
        // Gerar nome único
        $filename = $this->generateFilename($file['name']);
        $key = $subfolder ? $subfolder . '/' . $filename : $filename;
        
        // Fazer upload via API S3-compatible
        $result = $this->s3Upload($file, $key);
        
        if ($result['success']) {
            return [
                'success' => true,
                'url' => $this->getPublicUrl($key),
                'filename' => $filename,
                'size' => $file['size'],
                'provider' => 'Magalu Object Storage',
                'key' => $key
            ];
        }
        
        return ['success' => false, 'error' => $result['error']];
    }
    
    /**
     * Upload via API S3-compatible
     * Aceita tanto arquivo temporário quanto caminho de arquivo
     * USA AUTENTICAÇÃO AWS S3 Signature Version 2 (mesmo que Trello)
     */
    private function s3Upload($file, $key) {
        error_log("DEBUG MAGALU S3: s3Upload chamado");
        error_log("DEBUG MAGALU S3: key: $key");
        error_log("DEBUG MAGALU S3: file type: " . (is_array($file) ? 'array' : gettype($file)));
        
        $url = $this->endpoint . '/' . $this->bucket . '/' . $key;
        error_log("DEBUG MAGALU S3: URL completa: $url");
        error_log("DEBUG MAGALU S3: endpoint: " . $this->endpoint);
        error_log("DEBUG MAGALU S3: bucket: " . $this->bucket);
        
        // Determinar se é array $_FILES ou caminho de arquivo
        $filePath = is_array($file) ? $file['tmp_name'] : $file;
        $fileSize = is_array($file) ? $file['size'] : filesize($file);
        $contentType = is_array($file) ? $file['type'] : mime_content_type($file);
        
        error_log("DEBUG MAGALU S3: filePath: $filePath");
        error_log("DEBUG MAGALU S3: fileSize: $fileSize");
        error_log("DEBUG MAGALU S3: contentType: $contentType");
        
        // Ler conteúdo do arquivo
        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            error_log("DEBUG MAGALU S3: ❌ Erro ao ler arquivo");
            return ['success' => false, 'error' => 'Erro ao ler arquivo'];
        }
        
        error_log("DEBUG MAGALU S3: ✅ Arquivo lido, tamanho do conteúdo: " . strlen($fileContent) . " bytes");
        
        // AUTENTICAÇÃO AWS S3 Signature Version 2 (mesmo que Trello usa)
        $date = gmdate('D, d M Y H:i:s \G\M\T');
        $canonicalizedResource = "/{$this->bucket}/{$key}";
        $stringToSign = "PUT\n\n{$contentType}\n{$date}\n{$canonicalizedResource}";
        
        // Gerar assinatura HMAC-SHA1 e codificar em base64
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $this->secret_key, true));
        
        error_log("DEBUG MAGALU S3: String to sign: " . str_replace("\n", "\\n", $stringToSign));
        error_log("DEBUG MAGALU S3: Signature gerada: " . substr($signature, 0, 20) . "...");
        error_log("DEBUG MAGALU S3: Access Key: " . substr($this->access_key, 0, 10) . "...");
        
        // Headers da requisição COM AUTENTICAÇÃO
        $headers = [
            'Date: ' . $date,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . $fileSize,
            'Authorization: AWS ' . $this->access_key . ':' . $signature
        ];
        
        error_log("DEBUG MAGALU S3: Headers: " . print_r($headers, true));
        error_log("DEBUG MAGALU S3: Iniciando requisição cURL com autenticação...");
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $fileContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_VERBOSE => true
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);
        
        error_log("DEBUG MAGALU S3: HTTP Code: $http_code");
        error_log("DEBUG MAGALU S3: cURL Error: " . ($error ?: 'Nenhum'));
        error_log("DEBUG MAGALU S3: Response: " . substr($response, 0, 500));
        error_log("DEBUG MAGALU S3: cURL Info: " . print_r($curl_info, true));
        
        if ($error) {
            error_log("DEBUG MAGALU S3: ❌ Erro cURL: $error");
            return ['success' => false, 'error' => 'cURL Error: ' . $error];
        }
        
        // Extrair mensagem de erro da resposta XML se houver
        if ($http_code !== 200 && $http_code !== 201) {
            error_log("DEBUG MAGALU S3: ❌ Upload falhou! HTTP $http_code");
            if ($response && strpos($response, '<Error>') !== false) {
                if (preg_match('/<Message>(.*?)<\/Message>/', $response, $matches)) {
                    error_log("DEBUG MAGALU S3: Mensagem de erro XML: " . $matches[1]);
                    return ['success' => false, 'error' => 'Erro no upload para Magalu Cloud: ' . $matches[1]];
                }
            }
            error_log("DEBUG MAGALU S3: Response completo: $response");
            return ['success' => false, 'error' => 'HTTP ' . $http_code . ': ' . substr($response, 0, 200)];
        }
        
        error_log("DEBUG MAGALU S3: ✅ Upload bem-sucedido! HTTP $http_code");
        return ['success' => true];
    }
    
    /**
     * Upload de arquivo já processado (redimensionado, etc)
     */
    public function uploadFileFromPath($filePath, $subfolder = '', $contentType = null) {
        error_log("DEBUG MAGALU STORAGE: uploadFileFromPath chamado");
        error_log("DEBUG MAGALU STORAGE: filePath: $filePath");
        error_log("DEBUG MAGALU STORAGE: subfolder: $subfolder");
        error_log("DEBUG MAGALU STORAGE: contentType: " . ($contentType ?? 'N/A'));
        
        if (!$this->isConfigured()) {
            error_log("DEBUG MAGALU STORAGE: ❌ Magalu não configurado!");
            throw new Exception('Magalu Object Storage não configurado');
        }
        
        if (!file_exists($filePath)) {
            error_log("DEBUG MAGALU STORAGE: ❌ Arquivo não encontrado: $filePath");
            throw new Exception('Arquivo não encontrado: ' . $filePath);
        }
        
        error_log("DEBUG MAGALU STORAGE: ✅ Arquivo existe, tamanho: " . filesize($filePath) . " bytes");
        
        // Gerar nome único
        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $filename = $this->generateFilename('file.' . $extension);
        $key = $subfolder ? $subfolder . '/' . $filename : $filename;
        
        error_log("DEBUG MAGALU STORAGE: Filename gerado: $filename");
        error_log("DEBUG MAGALU STORAGE: Key gerado: $key");
        
        // Determinar content type
        if (!$contentType) {
            $contentType = mime_content_type($filePath);
            error_log("DEBUG MAGALU STORAGE: Content type detectado: $contentType");
        }
        
        // Fazer upload via API S3-compatible
        error_log("DEBUG MAGALU STORAGE: Chamando s3Upload...");
        $result = $this->s3Upload($filePath, $key);
        
        error_log("DEBUG MAGALU STORAGE: Resultado do s3Upload: " . print_r($result, true));
        
        if ($result['success']) {
            $url = $this->getPublicUrl($key);
            error_log("DEBUG MAGALU STORAGE: ✅ Upload bem-sucedido! URL: $url");
            return [
                'success' => true,
                'url' => $url,
                'filename' => $filename,
                'size' => filesize($filePath),
                'provider' => 'Magalu Object Storage',
                'key' => $key
            ];
        }
        
        error_log("DEBUG MAGALU STORAGE: ❌ Upload falhou: " . ($result['error'] ?? 'Erro desconhecido'));
        return ['success' => false, 'error' => $result['error']];
    }
    
    /**
     * Obter URL pública do arquivo
     */
    public function getPublicUrl($key) {
        return $this->endpoint . '/' . $this->bucket . '/' . $key;
    }
    
    /**
     * Verificar se está configurado
     */
    public function isConfigured() {
        return !empty($this->access_key) && 
               !empty($this->secret_key) && 
               !empty($this->bucket);
    }
    
    /**
     * Obter configuração (mascarada)
     */
    public function getConfiguration() {
        return [
            'access_key' => $this->access_key ? '***' . substr($this->access_key, -4) : 'Não configurado',
            'secret_key' => $this->secret_key ? '***' . substr($this->secret_key, -4) : 'Não configurado',
            'bucket' => $this->bucket ?: 'Não configurado',
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'configured' => $this->isConfigured()
        ];
    }
    
    /**
     * Testar conexão
     */
    public function testConnection() {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Não configurado'];
        }
        
        $test_url = $this->endpoint . '/' . $this->bucket;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $test_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'cURL Error: ' . $error];
        }
        
        if ($http_code === 200 || $http_code === 403) {
            return ['success' => true, 'message' => 'Conexão OK com Magalu Object Storage'];
        }
        
        return ['success' => false, 'error' => 'HTTP ' . $http_code];
    }
    
    /**
     * Gerar nome único para arquivo
     */
    private function generateFilename($original_name) {
        $extension = pathinfo($original_name, PATHINFO_EXTENSION);
        $uuid = $this->generateUUID();
        return $uuid . '.' . $extension;
    }
    
    /**
     * Gerar UUID v4
     */
    private function generateUUID() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    /**
     * Obter estatísticas de uso
     */
    public function getUsageStats() {
        return [
            'message' => 'Estatísticas disponíveis no painel Magalu Object Storage',
            'suggestion' => 'Acesse o painel para ver uso detalhado',
            'provider' => 'Magalu Object Storage'
        ];
    }
    
    /**
     * Remover arquivo
     */
    public function deleteFile($key) {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Não configurado'];
        }
        
        $url = $this->endpoint . '/' . $this->bucket . '/' . $key;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 204) {
            return ['success' => true, 'message' => 'Arquivo removido com sucesso'];
        }
        
        return ['success' => false, 'error' => 'HTTP ' . $http_code];
    }
}
?>
