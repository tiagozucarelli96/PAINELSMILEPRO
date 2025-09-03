<?php
// me_proxy.php — Proxy seguro para ME Eventos (GET /api/v1/events)
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
// DESBLOQUEIO TEMPORÁRIO PARA TESTE — REMOVER EM PRODUÇÃO
// (deixe sem checagem de sessão enquanto valida a ME)

header('Content-Type: application/json; charset=utf-8');

// ===== CONFIG =====
// Use VARIÁVEIS DE AMBIENTE em produção:
$ME_BASE_URL  = rtrim(getenv('ME_BASE_URL')  ?: 'https://app2.meeventos.com.br/lisbonbuffet', '/');
$ME_API_TOKEN = getenv('ME_API_TOKEN') ?: '';

// (opcional) fallback rápido só para teste local (evite deixar token hardcoded)
if (!$ME_API_TOKEN && isset($_GET['token'])) {
    $ME_API_TOKEN = (string)$_GET['token'];
}

// ===== Sanitiza filtros aceitos pela ME =====
// Doc oficial: GET /api/v1/events com filtros start,end,search,page,limit,field_sort,sort
// https://docs.meeventos.com.br/endpoints/eventos
$allowed = ['start','end','search','page','limit','field_sort','sort'];
$q = [];
foreach ($allowed as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') {
        $q[$k] = trim((string)$_GET[$k]);
    }
}
// Defaults sensatos
$q['page']  = (string)max(1, (int)($q['page']  ?? 1));
$q['limit'] = (string)min(200, max(1, (int)($q['limit'] ?? 50)));

$endpoint = $ME_BASE_URL . '/api/v1/events';
$url = $endpoint . (strpos($endpoint,'?')===false ? '?' : '&') . http_build_query($q);

// ===== Requisição cURL =====
$ch = curl_init($url);
$headers = [
    'Accept: application/json',
    'Content-Type: application/json',
];
if ($ME_API_TOKEN !== '') {
    // Doc: enviar 'Authorization: <token>'
    $headers[] = 'Authorization: ' . $ME_API_TOKEN;
}

curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST  => 'GET',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 20, // dica da doc: --max-time 20
    CURLOPT_HTTPHEADER     => $headers,
]);

$resp   = curl_exec($ch);
$errno  = curl_errno($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($errno) {
    http_response_code(502);
    echo json_encode(['ok'=>false,'erro'=>'Erro de rede (cURL '.$errno.')']);
    exit;
}
if ($status >= 400 || $resp === false) {
    http_response_code($status ?: 500);
    echo is_string($resp) && $resp !== '' ? $resp : json_encode(['ok'=>false,'erro'=>'Falha na ME']);
    exit;
}

// ===== Devolve o JSON original da ME =====
echo $resp;
