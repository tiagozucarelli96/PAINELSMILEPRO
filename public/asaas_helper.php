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
        
        // IMPORTANTE: Conforme documentação oficial Asaas
        // Documentação: https://docs.asaas.com/docs/autenticacao-1
        // "A chave de API deve ser utilizada EXATAMENTE como foi gerada, 
        //  incluindo o prefixo $aact_prod_ para chaves de produção"
        // 
        // Ou seja: usar a chave COM o $ no header!
        $api_key_original = $this->api_key;
        $api_key_to_use = trim($api_key_original);
        
        // NÃO remover o $ - a documentação diz para usar exatamente como gerada
        
        // Asaas API v3 - Formato EXATO conforme documentação oficial
        // Documentação: https://docs.asaas.com/docs/autenticacao-1
        // Headers obrigatórios:
        //   "Content-Type": "application/json"
        //   "User-Agent": "nome_da_sua_aplicação"
        //   "access_token": "sua_api_key" (COM $ se a chave tiver $)
        // 
        // Em headers HTTP cURL, o formato é: "Nome: Valor" (com espaço após dois pontos)
        
        $headers = [
            'Content-Type: application/json',
            'User-Agent: PainelSmilePRO/1.0',
            'access_token: ' . $api_key_to_use  // COM espaço após dois pontos, COM $ se a chave tiver
        ];
        
        // Log detalhado para debug
        error_log("AsaasHelper - Chave usada (primeiros 35): " . substr($api_key_to_use, 0, 35) . "...");
        error_log("AsaasHelper - Header access_token (primeiros 50 chars): " . substr('access_token: ' . $api_key_to_use, 0, 50) . "...");
        
        // Log para debug - mostrar chave completa para verificar se está correta
        error_log("Asaas API Request - Method: $method, Endpoint: $endpoint");
        error_log("Asaas API Key (exatamente como será enviada): " . $api_key_to_use);
        error_log("Asaas API Key - Tamanho original: " . strlen($api_key_original) . " caracteres");
        error_log("Asaas API Key - Tamanho limpa: " . strlen($api_key_to_use) . " caracteres");
        error_log("Asaas API Key - Contém \$ no início: " . (strpos($api_key_original, '$') === 0 ? 'SIM' : 'NÃO'));
        
        // Log da URL completa antes de fazer a requisição
        error_log("AsaasHelper - URL completa: $endpoint");
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_VERBOSE => false
        ]);
        
        // Log dos headers que serão enviados (debug)
        error_log("AsaasHelper - Headers que serão enviados: " . json_encode($headers, JSON_UNESCAPED_SLASHES));
        
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
     * Criar Checkout Asaas (formulário de pagamento pronto)
     * Documentação: https://docs.asaas.com/docs/checkout-asaas
     */
    public function createCheckout($data) {
        $endpoint = $this->base_url . '/checkouts';
        
        // Validar campos obrigatórios conforme documentação
        if (empty($data['billingTypes']) || !is_array($data['billingTypes'])) {
            throw new Exception('billingTypes é obrigatório e deve ser um array');
        }
        
        if (empty($data['chargeTypes']) || !is_array($data['chargeTypes'])) {
            throw new Exception('chargeTypes é obrigatório e deve ser um array');
        }
        
        if (empty($data['callback']) || !is_array($data['callback'])) {
            throw new Exception('callback é obrigatório e deve conter cancelUrl, expiredUrl e successUrl');
        }
        
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new Exception('items é obrigatório e deve ser um array');
        }
        
        // Montar payload conforme documentação
        $payload = [
            'billingTypes' => $data['billingTypes'], // Ex: ["PIX"] ou ["PIX", "CREDIT_CARD"]
            'chargeTypes' => $data['chargeTypes'],   // Ex: ["DETACHED"] para pagamento único
            'callback' => [
                'cancelUrl' => $data['callback']['cancelUrl'],
                'expiredUrl' => $data['callback']['expiredUrl'],
                'successUrl' => $data['callback']['successUrl']
            ],
            'items' => []
        ];
        
        // Adicionar items (obrigatório) - formato exato da documentação
        foreach ($data['items'] as $item) {
            // IMPORTANTE: value deve ser numérico (float), não string formatada
            $payload['items'][] = [
                'name' => $item['name'],
                'description' => $item['description'] ?? '',
                'quantity' => (int)($item['quantity'] ?? 1),
                'value' => (float)($item['value'] ?? 0)  // Float, não string formatada
            ];
        }
        
        // Tempo de expiração (opcional, em minutos)
        if (isset($data['minutesToExpire'])) {
            $payload['minutesToExpire'] = (int)$data['minutesToExpire'];
        } else {
            $payload['minutesToExpire'] = 60; // Padrão: 1 hora
        }
        
        // Dados do cliente (opcional - pré-preenche formulário)
        if (!empty($data['customerData'])) {
            $customerData = $data['customerData'];
            $payload['customerData'] = [
                'name' => $customerData['name'] ?? '',
                'email' => $customerData['email'] ?? '',
                'phone' => $customerData['phone'] ?? '',
                'cpfCnpj' => $customerData['cpfCnpj'] ?? ''
            ];
            
            // Endereço (opcional)
            if (!empty($customerData['address'])) {
                $payload['customerData']['address'] = $customerData['address'];
                $payload['customerData']['addressNumber'] = $customerData['addressNumber'] ?? '';
                $payload['customerData']['complement'] = $customerData['complement'] ?? '';
                $payload['customerData']['postalCode'] = $customerData['postalCode'] ?? '';
                $payload['customerData']['province'] = $customerData['province'] ?? '';
                $payload['customerData']['city'] = $customerData['city'] ?? '';
            }
        }
        
        // Referência externa (opcional)
        if (!empty($data['externalReference'])) {
            $payload['externalReference'] = $data['externalReference'];
        }
        
        // Fazer requisição
        $response = $this->makeRequest('POST', $endpoint, $payload);
        
        // Retornar resposta com ID do checkout
        if (isset($response['id'])) {
            // Construir URL do checkout conforme documentação
            $response['checkoutUrl'] = 'https://asaas.com/checkoutSession/show?id=' . $response['id'];
        }
        
        return $response;
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
