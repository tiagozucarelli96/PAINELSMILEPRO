<?php
/**
 * Endpoint para busca de CEP via API ViaCEP
 * Retorna dados do endereço em JSON
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Limpar qualquer output anterior
while (ob_get_level() > 0) { ob_end_clean(); }

header('Content-Type: application/json; charset=utf-8');

$cep = preg_replace('/[^0-9]/', '', $_GET['cep'] ?? '');

if (empty($cep) || strlen($cep) !== 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'CEP inválido']);
    exit;
}

try {
    // Rate limit simples por sessão (evita abuso em endpoints públicos)
    $now = time();
    $last = (int)($_SESSION['cep_last_req'] ?? 0);
    if ($last > 0 && ($now - $last) < 1) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Aguarde um instante e tente novamente.']);
        exit;
    }
    $_SESSION['cep_last_req'] = $now;

    // Cache simples em sessão (24h)
    if (!isset($_SESSION['cep_cache']) || !is_array($_SESSION['cep_cache'])) {
        $_SESSION['cep_cache'] = [];
    }
    $cached = $_SESSION['cep_cache'][$cep] ?? null;
    if (is_array($cached) && !empty($cached['data']) && !empty($cached['ts']) && ($now - (int)$cached['ts'] < 86400)) {
        echo json_encode(['success' => true, 'data' => $cached['data']]);
        exit;
    }

    // Buscar CEP via API ViaCEP
    $url = "https://viacep.com.br/ws/{$cep}/json/";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PainelSmilePro/1.0 (+CEP lookup)');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("Erro ao buscar CEP: {$error}");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("Erro HTTP {$httpCode} ao buscar CEP");
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['erro'])) {
        echo json_encode([
            'success' => false,
            'message' => 'CEP não encontrado'
        ]);
        exit;
    }
    
    // Retornar dados formatados
    $payload = [
        'success' => true,
        'data' => [
            'cep' => $data['cep'] ?? '',
            'logradouro' => $data['logradouro'] ?? '',
            'complemento' => $data['complemento'] ?? '',
            'bairro' => $data['bairro'] ?? '',
            'cidade' => $data['localidade'] ?? '',
            'estado' => $data['uf'] ?? ''
        ]
    ];

    // Guardar cache
    $_SESSION['cep_cache'][$cep] = [
        'ts' => $now,
        'data' => $payload['data'],
    ];

    echo json_encode($payload);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

