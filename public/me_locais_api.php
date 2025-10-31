<?php
// me_locais_api.php — API para buscar locais de eventos da ME Eventos
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/me_config.php';

try {
    $base = getenv('ME_BASE_URL') ?: ME_BASE_URL;
    $key = getenv('ME_API_KEY') ?: ME_API_KEY;
    
    if (!$base || !$key) {
        throw new Exception('ME_BASE_URL/ME_API_KEY ausentes.');
    }
    
    // Buscar eventos para extrair locais únicos
    $url = rtrim($base, '/') . '/api/v1/events';
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . $key,
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    
    if ($err) {
        throw new Exception('Erro cURL: ' . $err);
    }
    
    if ($code < 200 || $code >= 300) {
        throw new Exception('HTTP ' . $code . ' da ME Eventos');
    }
    
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new Exception('JSON inválido da ME Eventos');
    }
    
    $events = $data['data'] ?? [];
    
    // Extrair locais únicos
    $locais = [];
    foreach ($events as $e) {
        $local = trim($e['localevento'] ?? '');
        if (!empty($local) && !in_array($local, $locais)) {
            $locais[] = $local;
        }
    }
    
    // Ordenar alfabeticamente
    sort($locais);
    
    echo json_encode([
        'ok' => true,
        'locais' => $locais,
        'count' => count($locais)
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'locais' => []
    ], JSON_UNESCAPED_UNICODE);
}
