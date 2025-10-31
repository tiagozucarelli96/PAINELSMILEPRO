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
    
    // Buscar eventos de um período maior para capturar mais locais únicos
    // Buscar eventos dos últimos 12 meses e próximos 12 meses
    $hoje = new DateTime();
    $inicio = clone $hoje;
    $inicio->modify('-12 months');
    $fim = clone $hoje;
    $fim->modify('+12 months');
    
    $url = rtrim($base, '/') . '/api/v1/events';
    $params = [
        'start' => $inicio->format('Y-m-d'),
        'end' => $fim->format('Y-m-d')
    ];
    $url .= '?' . http_build_query($params);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
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
    
    // Se não encontrou eventos com filtro de data, tentar sem filtro
    if (empty($events)) {
        $url_sem_filtro = rtrim($base, '/') . '/api/v1/events';
        $ch2 = curl_init($url_sem_filtro);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $key,
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        $resp2 = curl_exec($ch2);
        $code2 = curl_getinfo($ch2, CURLINFO_RESPONSE_CODE);
        curl_close($ch2);
        
        if ($code2 >= 200 && $code2 < 300) {
            $data2 = json_decode($resp2, true);
            if (is_array($data2)) {
                $events = $data2['data'] ?? $events;
            }
        }
    }
    
    // Extrair locais únicos
    $locais = [];
    foreach ($events as $e) {
        $local = trim($e['localevento'] ?? '');
        if (!empty($local) && !in_array($local, $locais)) {
            $locais[] = $local;
        }
    }
    
    // Se ainda estiver vazio, tentar buscar de webhooks salvos
    if (empty($locais)) {
        try {
            require_once __DIR__ . '/conexao.php';
            global $pdo;
            
            // Buscar locais dos webhooks salvos
            $stmt = $pdo->query("
                SELECT DISTINCT dados->>'localevento' as local
                FROM me_eventos_webhook
                WHERE dados->>'localevento' IS NOT NULL 
                  AND dados->>'localevento' != ''
                ORDER BY local
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $local = trim($row['local'] ?? '');
                if (!empty($local) && !in_array($local, $locais)) {
                    $locais[] = $local;
                }
            }
        } catch (Exception $e) {
            // Ignorar erro, continuar com locais da API
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
