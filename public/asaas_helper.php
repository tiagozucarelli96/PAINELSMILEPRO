<?php
// asaas_helper.php — Helper para integração com ASAAS
class AsaasHelper {
    private $api_key;
    private $base_url;
    private $webhook_url;
    
    public function __construct() {
        $this->api_key = 'aact_prod_000MzkwODA2MWY2OGM3MWRlMDU2NWM3MzJlNzZmNGZhZGY6OmYyZDZiYzYwLTE4MDYtNGExYy05ODg1LTdmYWQ5OTRkZGI0MDo6JGFhY2hfMTY3YzU2YzctOGM0MS00MDczLWJkNTQtNTBmMTcyNjkwNjdh';
        $this->base_url = 'https://www.asaas.com/api/v3';
        $this->webhook_url = 'https://seudominio.com/public/asaas_webhook.php'; // Substitua pelo seu domínio
    }
    
    /**
     * Criar pagamento PIX
     */
    public function createPixPayment($data) {
        $endpoint = $this->base_url . '/payments';
        
        $payload = [
            'customer' => $data['customer_id'] ?? null,
            'billingType' => 'PIX',
            'value' => number_format($data['value'], 2, '.', ''),
            'dueDate' => $data['due_date'] ?? date('Y-m-d', strtotime('+3 days')),
            'description' => $data['description'] ?? 'Degustação GRUPO Smile EVENTOS',
            'externalReference' => $data['external_reference'] ?? null,
            'callback' => [
                'successUrl' => $data['success_url'] ?? '',
                'autoRedirect' => true
            ],
            'webhook' => [
                'url' => $this->webhook_url
            ]
        ];
        
        // Se não tem customer_id, criar customer
        if (!$data['customer_id']) {
            $customer = $this->createCustomer($data['customer_data']);
            $payload['customer'] = $customer['id'];
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
        
        $headers = [
            'access_token: ' . $this->api_key,
            'Content-Type: application/json'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30
        ]);
        
        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Erro cURL: $error");
        }
        
        $decoded_response = json_decode($response, true);
        
        if ($http_code >= 400) {
            $error_message = $decoded_response['errors'][0]['description'] ?? 'Erro desconhecido';
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
