<?php
// asaas_helper.php — Helper para integração com ASAAS
class AsaasHelper {
    private $api_key;
    private $base_url;
    private $webhook_url;
    
    public function __construct() {
        // Priorizar variável de ambiente sobre constante (ANTES de carregar config_env.php)
        $env_key = $_ENV['ASAAS_API_KEY'] ?? getenv('ASAAS_API_KEY') ?: null;
        $env_base = $_ENV['ASAAS_BASE_URL'] ?? getenv('ASAAS_BASE_URL') ?: null;
        $env_webhook = $_ENV['WEBHOOK_URL'] ?? getenv('WEBHOOK_URL') ?: null;
        
        require_once __DIR__ . '/config_env.php';
        
        // Usar variável de ambiente se disponível, senão usar constante
        $this->api_key = $env_key ?? ASAAS_API_KEY;
        $this->base_url = $env_base ?? ASAAS_BASE_URL;
        $this->webhook_url = $env_webhook ?? WEBHOOK_URL;
        
        // Log para debug (primeiros e últimos caracteres apenas)
        $key_preview = substr($this->api_key, 0, 30) . '...' . substr($this->api_key, -10);
        $fonte = $env_key ? 'ENV_VAR' : 'CONSTANTE';
        error_log("AsaasHelper inicializado - API Key preview: $key_preview");
        error_log("AsaasHelper - Fonte da chave: $fonte");
        error_log("AsaasHelper - Chave completa (para debug): " . $this->api_key);
    }
    
    /**
     * Criar pagamento PIX
     */
    public function createPixPayment($data) {
        $endpoint = $this->base_url . '/payments';
        
        // Verificar se tem customer_id ou precisa criar
        $customer_id = $data['customer_id'] ?? null;
        
        // Se não tem customer_id, criar customer
        if (!$customer_id && isset($data['customer_data'])) {
            $customer = $this->createCustomer($data['customer_data']);
            if (!$customer || !isset($customer['id'])) {
                throw new Exception('Erro ao criar cliente no ASAAS');
            }
            $customer_id = $customer['id'];
        }
        
        if (!$customer_id) {
            throw new Exception('customer_id ou customer_data é obrigatório');
        }
        
        $payload = [
            'customer' => $customer_id,
            'billingType' => 'PIX',
            'value' => number_format($data['value'], 2, '.', ''),
            'dueDate' => $data['due_date'] ?? date('Y-m-d', strtotime('+3 days')),
            'description' => $data['description'] ?? 'Degustação GRUPO Smile EVENTOS',
            'externalReference' => $data['external_reference'] ?? null
        ];
        
        // Adicionar callback se fornecido
        if (!empty($data['success_url'])) {
            $payload['callback'] = [
                'successUrl' => $data['success_url'],
                'autoRedirect' => true
            ];
        }
        
        // Adicionar webhook se configurado
        if ($this->webhook_url) {
            $payload['webhook'] = [
                'url' => $this->webhook_url
            ];
        }
        
        return $this->makeRequest('POST', $endpoint, $payload);
    }
    
    /**
     * Criar customer no ASAAS
     */
    public function createCustomer($customer_data) {
        $endpoint = $this->base_url . '/customers';
        
        $payload = [
            'name' => $customer_data['name'],
            'email' => $customer_data['email'],
            'phone' => $customer_data['phone'] ?? '',
            'cpfCnpj' => $customer_data['cpf_cnpj'] ?? '',
            'externalReference' => $customer_data['external_reference'] ?? null
        ];
        
        return $this->makeRequest('POST', $endpoint, $payload);
    }
    
    /**
     * Consultar status do pagamento
     */
    public function getPaymentStatus($payment_id) {
        $endpoint = $this->base_url . '/payments/' . $payment_id;
        return $this->makeRequest('GET', $endpoint);
    }
    
    /**
     * Gerar QR Code PIX
     */
    public function getPixQrCode($payment_id) {
        $endpoint = $this->base_url . '/payments/' . $payment_id . '/pixQrCode';
        return $this->makeRequest('GET', $endpoint);
    }
    
    /**
     * Fazer requisição para API ASAAS
     */
    private function makeRequest($method, $endpoint, $data = null) {
        $ch = curl_init();
        
        // Asaas API v3 - Testar diferentes formatos de autenticação
        // A API Asaas pode aceitar diferentes formatos, vamos testar o mais comum
        
        // IMPORTANTE: Remover $ do início da chave se existir (pode estar vindo do ENV com $)
        $api_key_clean = $this->api_key;
        if (strpos($api_key_clean, '$') === 0) {
            $api_key_clean = substr($api_key_clean, 1);
            error_log("AsaasHelper - Removido \$ do início da chave");
        }
        
        // Formato 1: access_token como header (formato oficial Asaas)
        $headers = [
            'access_token: ' . $api_key_clean,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: PainelSmilePRO/1.0'
        ];
        
        // Log para debug - mostrar chave completa para verificar se está correta
        error_log("Asaas API Request - Method: $method, Endpoint: $endpoint");
        error_log("Asaas API Key ORIGINAL: " . $this->api_key);
        error_log("Asaas API Key LIMPA (sem \$): " . $api_key_clean);
        error_log("Asaas API Key - Tamanho original: " . strlen($this->api_key) . " caracteres");
        error_log("Asaas API Key - Tamanho limpa: " . strlen($api_key_clean) . " caracteres");
        error_log("Asaas API Key - Contém \$ no início: " . (strpos($this->api_key, '$') === 0 ? 'SIM' : 'NÃO'));
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_VERBOSE => false
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
            error_log("Asaas API Request Body: " . substr($json_data, 0, 200) . "...");
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            error_log("Asaas API cURL Error: $error");
            throw new Exception("Erro cURL: $error");
        }
        
        $decoded_response = json_decode($response, true);
        
        // Log da resposta para debug
        error_log("Asaas API Response - HTTP Code: $http_code");
        if ($http_code >= 400) {
            error_log("Asaas API Error Response: " . json_encode($decoded_response, JSON_UNESCAPED_UNICODE));
            
            // Tentar extrair mensagem de erro de diferentes formatos da API Asaas
            $error_message = 'Erro desconhecido';
            if (isset($decoded_response['errors']) && is_array($decoded_response['errors'])) {
                if (isset($decoded_response['errors'][0]['description'])) {
                    $error_message = $decoded_response['errors'][0]['description'];
                } elseif (isset($decoded_response['errors'][0]['message'])) {
                    $error_message = $decoded_response['errors'][0]['message'];
                } elseif (is_string($decoded_response['errors'][0])) {
                    $error_message = $decoded_response['errors'][0];
                }
            } elseif (isset($decoded_response['message'])) {
                $error_message = $decoded_response['message'];
            } elseif (isset($decoded_response['error'])) {
                $error_message = is_string($decoded_response['error']) ? $decoded_response['error'] : json_encode($decoded_response['error']);
            }
            
            throw new Exception("Erro ASAAS ($http_code): $error_message");
        }
        
        return $decoded_response;
    }
    
    /**
     * Calcular valor total baseado nas opções
     */
    public function calculateTotal($tipo_festa, $qtd_pessoas, $precos) {
        $preco_base = $tipo_festa === 'casamento' ? $precos['casamento'] : $precos['15anos'];
        $incluidos = $tipo_festa === 'casamento' ? $precos['incluidos_casamento'] : $precos['incluidos_15anos'];
        $preco_extra = $precos['extra'];
        
        $extras = max(0, $qtd_pessoas - $incluidos);
        $valor_total = $preco_base + ($extras * $preco_extra);
        
        return [
            'valor_base' => $preco_base,
            'extras' => $extras,
            'valor_extras' => $extras * $preco_extra,
            'valor_total' => $valor_total
        ];
    }
    
    /**
     * Validar webhook (verificar assinatura se configurado)
     */
    public function validateWebhook($headers, $body) {
        // Se configurar X-Signature no ASAAS, implementar validação aqui
        return true;
    }
}
