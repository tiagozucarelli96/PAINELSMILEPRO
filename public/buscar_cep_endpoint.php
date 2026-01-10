<?php
/**
 * Endpoint para busca de CEP via API ViaCEP
 * Retorna dados do endereço em JSON
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Verificar se está logado
if (empty($_SESSION['logado'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

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
    // Buscar CEP via API ViaCEP
    $url = "https://viacep.com.br/ws/{$cep}/json/";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
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
    echo json_encode([
        'success' => true,
        'data' => [
            'cep' => $data['cep'] ?? '',
            'logradouro' => $data['logradouro'] ?? '',
            'complemento' => $data['complemento'] ?? '',
            'bairro' => $data['bairro'] ?? '',
            'cidade' => $data['localidade'] ?? '',
            'estado' => $data['uf'] ?? ''
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

