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
        $allowed_types = ['pdf', 'jpg', 'jpeg', 'png'];
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
     */
    private function s3Upload($file, $key) {
        $url = $this->endpoint . '/' . $this->bucket . '/' . $key;
        
        $headers = [
            'Content-Type: ' . $file['type'],
            'Content-Length: ' . $file['size']
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, fopen($file['tmp_name'], 'rb'));
        curl_setopt($ch, CURLOPT_INFILESIZE, $file['size']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            return ['success' => false, 'error' => 'cURL Error: ' . $error];
        }
        
        if ($http_code === 200) {
            return ['success' => true];
        }
        
        return ['success' => false, 'error' => 'HTTP ' . $http_code . ': ' . $response];
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
