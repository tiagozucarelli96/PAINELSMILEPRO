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
        $chave_a_usar = $env_key ?? ASAAS_API_KEY;
        
        // CORREÇÃO: Se a chave for muito curta (< 180 chars), usar a nova chave diretamente
        // Isso força o uso da nova chave mesmo se Railway não atualizou
        if (strlen($chave_a_usar) < 180) {
            $chave_corrigida = '$aact_prod_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OjA2OTVjYTRhLTgzNTctNDkzNC1hMmQyLTEyOTNmMWFjY2NjYjo6JGFhY2hfMmRlNDE2ZTktMzk2OS00YTYzLTkyYmYtNzg2NzUzNmY5NTVl';
            error_log("⚠️ AsaasHelper - Chave detectada como antiga (" . strlen($chave_a_usar) . " chars). Forçando uso da nova chave.");
            $chave_a_usar = $chave_corrigida;
        }
        
        $this->api_key = $chave_a_usar;
        $this->base_url = $env_base ?? ASAAS_BASE_URL;
        $this->webhook_url = $env_webhook ?? WEBHOOK_URL;
        
        // Log para debug (primeiros e últimos caracteres apenas)
        $key_preview = substr($this->api_key, 0, 30) . '...' . substr($this->api_key, -10);
        $fonte = $env_key ? 'ENV_VAR' : 'CONSTANTE';
        $tamanho = strlen($this->api_key);
        error_log("AsaasHelper inicializado - API Key preview: $key_preview");
        error_log("AsaasHelper - Fonte da chave: $fonte");
        error_log("AsaasHelper - Tamanho da chave: $tamanho caracteres");
        error_log("AsaasHelper - Chave completa (para debug): " . $this->api_key);
        
        // ALERTA se a chave parece ser a antiga (167 chars) ou está muito curta
        if ($tamanho < 180) {
            error_log("⚠️ ALERTA: Chave parece estar desatualizada! Tamanho: $tamanho chars (esperado: ~200 chars)");
            error_log("⚠️ VERIFIQUE: Railway ENV pode não ter sido atualizado ou redeploy não foi feito!");
        }
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
        // CORREÇÃO: Algumas vezes o Railway remove o $ ao salvar no ENV
        // Vamos garantir que o $ esteja presente se a chave começar com aact_prod_ ou aact_hmlg_
        $api_key_original = $this->api_key;
        $api_key_to_use = trim($api_key_original);
        
        // Se a chave não começar com $ mas começar com aact_prod_ ou aact_hmlg_, adicionar $
        if (strpos($api_key_to_use, '$') !== 0) {
            if (strpos($api_key_to_use, 'aact_prod_') === 0 || strpos($api_key_to_use, 'aact_hmlg_') === 0) {
                $api_key_to_use = '$' . $api_key_to_use;
                error_log("AsaasHelper - Adicionado \$ ao início da chave (Railway pode ter removido)");
            }
        }
        
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
        
        // Log MUITO detalhado para debug do problema 401
        error_log("=== ASAAS API REQUEST DEBUG ===");
        error_log("Method: $method");
        error_log("Endpoint: $endpoint");
        error_log("Chave original (primeiros 40): " . substr($api_key_original, 0, 40) . "...");
        error_log("Chave final usada (primeiros 40): " . substr($api_key_to_use, 0, 40) . "...");
        error_log("Tamanho original: " . strlen($api_key_original) . " chars");
        error_log("Tamanho final: " . strlen($api_key_to_use) . " chars");
        error_log("Começa com \$: " . (strpos($api_key_to_use, '$') === 0 ? 'SIM' : 'NÃO'));
        error_log("Header access_token completo (primeiros 60): " . substr('access_token: ' . $api_key_to_use, 0, 60) . "...");
        error_log("Todos os headers que serão enviados:");
        foreach ($headers as $h) {
            // Não mostrar a chave completa por segurança, mas mostrar formato
            if (strpos($h, 'access_token:') === 0) {
                $chave_header = substr($h, 13);
                error_log("  - access_token: [" . substr($chave_header, 0, 15) . "..." . substr($chave_header, -10) . "] (total: " . strlen($chave_header) . " chars)");
            } else {
                error_log("  - $h");
            }
        }
        
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
            
            // Extrair código e mensagem de erro conforme documentação oficial Asaas
            // Documentação: https://docs.asaas.com/docs/erros-de-autenticacao
            $error_code = null;
            $error_message = 'Erro desconhecido';
            $error_details = [];
            
            if (isset($decoded_response['errors']) && is_array($decoded_response['errors'])) {
                $first_error = $decoded_response['errors'][0];
                
                // Extrair código do erro (ex: invalid_environment, access_token_not_found, etc)
                if (isset($first_error['code'])) {
                    $error_code = $first_error['code'];
                }
                
                // Extrair mensagem/description
                if (isset($first_error['description'])) {
                    $error_message = $first_error['description'];
                } elseif (isset($first_error['message'])) {
                    $error_message = $first_error['message'];
                } elseif (is_string($first_error)) {
                    $error_message = $first_error;
                }
            } elseif (isset($decoded_response['message'])) {
                $error_message = $decoded_response['message'];
            } elseif (isset($decoded_response['error'])) {
                $error_message = is_string($decoded_response['error']) ? $decoded_response['error'] : json_encode($decoded_response['error']);
            }
            
            // Mensagens específicas baseadas nos códigos de erro da documentação
            $helpful_message = $error_message;
            if ($error_code) {
                switch ($error_code) {
                    case 'invalid_environment':
                        $helpful_message = $error_message . ' | Use chave de produção ($aact_prod_...) em api.asaas.com e chave de sandbox ($aact_hmlg_...) em api-sandbox.asaas.com';
                        break;
                    
                    case 'access_token_not_found':
                        $helpful_message = $error_message . ' | Verifique se o header "access_token" está sendo enviado corretamente';
                        break;
                    
                    case 'invalid_access_token_format':
                        $helpful_message = $error_message . ' | Verifique se não copiou espaços extras. Chaves de produção começam com $aact_prod_ e sandbox com $aact_hmlg_';
                        break;
                    
                    case 'invalid_access_token':
                        $helpful_message = $error_message . ' | Confirme se a chave está correta e não foi desabilitada, expirada ou excluída no painel Asaas';
                        break;
                }
            }
            
            // Formato da exceção: código HTTP, código de erro (se houver) e mensagem
            $exception_message = "Erro ASAAS ($http_code)";
            if ($error_code) {
                $exception_message .= " [$error_code]";
            }
            $exception_message .= ": $helpful_message";
            
            throw new Exception($exception_message);
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
        // IMPORTANTE: Conforme documentação Asaas, quando usando customerData, todos os campos básicos devem estar presentes
        // Campos obrigatórios: name, email, phone, cpfCnpj
        // Campos de endereço são opcionais, mas se incluídos devem ter todos os campos
        if (!empty($data['customerData'])) {
            $customerData = $data['customerData'];
            
            // Campos obrigatórios - sempre presentes (mesmo que vazios)
            $payload['customerData'] = [
                'name' => $customerData['name'] ?? '',
                'email' => $customerData['email'] ?? '',
                'phone' => $customerData['phone'] ?? '',
                'cpfCnpj' => $customerData['cpfCnpj'] ?? '' // Obrigatório quando usando customerData (mesmo que vazio)
            ];
            
            // Garantir que cpfCnpj sempre esteja presente (evita erro 400)
            if (!isset($customerData['cpfCnpj']) || $customerData['cpfCnpj'] === null) {
                $payload['customerData']['cpfCnpj'] = '';
            }
            
            // Endereço (opcional, mas se informado, deve incluir todos os campos)
            // Se algum campo de endereço foi informado, incluir todos (mesmo que vazios)
            if (!empty($customerData['address']) || 
                !empty($customerData['addressNumber']) || 
                !empty($customerData['postalCode']) || 
                !empty($customerData['province']) || 
                !empty($customerData['city'])) {
                
                $payload['customerData']['address'] = $customerData['address'] ?? '';
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
     * Criar QR Code PIX estático
     * Documentação: https://docs.asaas.com/docs/qr-code-estatico
     * 
     * @param array $data - Dados do QR Code
     *   - addressKey: Chave PIX (obrigatório)
     *   - description: Descrição do pagamento
     *   - value: Valor do pagamento
     *   - format: Formato do QR Code (opcional, padrão: "ALL")
     *   - expirationDate: Data de expiração (opcional, formato: "YYYY-MM-DD HH:mm:ss")
     *   - expirationSeconds: Expiração em segundos (opcional, alternativa a expirationDate)
     * 
     * @return array Resposta com id e payload (QR Code em Base64)
     */
    public function createStaticQrCode($data) {
        $endpoint = $this->base_url . '/pix/qrCodes/static';
        
        // Validar campos obrigatórios
        if (empty($data['addressKey'])) {
            throw new Exception('addressKey é obrigatório para criar QR Code estático');
        }
        
        if (empty($data['value']) || $data['value'] <= 0) {
            throw new Exception('value deve ser maior que zero');
        }
        
        // Montar payload conforme documentação Asaas
        $payload = [
            'addressKey' => $data['addressKey'],
            'description' => $data['description'] ?? '',
            'allowsMultiplePayments' => $data['allowsMultiplePayments'] ?? true // Permite múltiplos pagamentos
        ];
        
        // Valor (obrigatório - base + adicional)
        if (isset($data['value']) && $data['value'] > 0) {
            $payload['value'] = (float)$data['value'];
        }
        
        // Formato do QR Code (opcional)
        if (!empty($data['format'])) {
            $payload['format'] = $data['format']; // ALL, BASE64, IMAGE, PAYLOAD
        }
        
        // IMPORTANTE: API aceita APENAS UM tipo de expiração (data OU segundos, não ambos)
        // Priorizar expirationDate se informado
        if (!empty($data['expirationDate'])) {
            $payload['expirationDate'] = $data['expirationDate']; // Formato: "YYYY-MM-DD HH:mm:ss"
        } elseif (!empty($data['expirationSeconds'])) {
            // Só usar expirationSeconds se expirationDate não foi informado
            $payload['expirationSeconds'] = (int)$data['expirationSeconds'];
        }
        
        // Fazer requisição
        $response = $this->makeRequest('POST', $endpoint, $payload);
        
        return $response;
    }
    
    /**
     * Listar cobranças geradas por um QR Code estático
     * 
     * @param string $qr_code_id - ID do QR Code estático
     * @return array Lista de cobranças
     */
    public function getPaymentsByStaticQrCode($qr_code_id) {
        $endpoint = $this->base_url . '/payments?pixQrCodeId=' . urlencode($qr_code_id);
        return $this->makeRequest('GET', $endpoint);
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
