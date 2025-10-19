<?php
// me_proxy.php — Proxy de leitura para Meeventos (GET /api/v1/events)
// Prévia pronta para uso — mantém os campos originais do backend (sem renomear).
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
  // 1) Config (use variáveis de ambiente em produção)
  $base = getenv('ME_BASE_URL') ?: 'https://app2.meeventos.com.br/lisbonbuffet';
  $key  = getenv('ME_API_KEY')  ?: '5qlrv-crt91-s0e0d-drri2-gxhlm'; // troque para env no servidor

  if (!$base || !$key) {
    throw new Exception('ME_BASE_URL/ME_API_KEY ausentes.');
  }

  // 2) Coleta e normaliza parâmetros que o front envia
  // (o botão "Buscar" usa 'search', 'start', 'end', e pode enviar paginação/sort)
  $search     = isset($_GET['search']) ? trim($_GET['search']) : '';
  $start      = isset($_GET['start'])  ? trim($_GET['start'])  : '';
  $end        = isset($_GET['end'])    ? trim($_GET['end'])    : '';
  $page       = isset($_GET['page'])   ? (int)$_GET['page']    : 1;
  $limit      = isset($_GET['limit'])  ? (int)$_GET['limit']   : 50;
  $sort       = isset($_GET['sort'])        ? trim($_GET['sort'])        : 'desc';
  $field_sort = isset($_GET['field_sort'])  ? trim($_GET['field_sort'])  : 'id';

  // 3) Monta URL destino
  $url = rtrim($base, '/').'/api/v1/events';
  $params = [
    'page'       => max(1, $page),
    'limit'      => max(1, min(100, $limit)),
    'sort'       => $sort,
    'field_sort' => $field_sort,
  ];
  if ($search !== '') $params['search'] = $search;
  if ($start  !== '') $params['start']  = $start;
  if ($end    !== '') $params['end']    = $end;

  $url .= '?'.http_build_query($params);

  // 4) Chamada HTTP
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_HTTPHEADER     => [
      'Accept: application/json',
      'Authorization: Bearer '.$key, // esquema Bearer é o mais comum
    ],
  ]);
  $resp  = curl_exec($ch);
  $err   = curl_error($ch);
  $code  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
  curl_close($ch);

  if ($err) {
    throw new Exception('Erro cURL: '.$err);
  }
  if ($code < 200 || $code >= 300) {
    throw new Exception('HTTP '.$code.' da Meeventos');
  }

  // 5) Normaliza retorno: o front aceita j.data (preferível) ou array direto
  $j = json_decode($resp, true);
  if (!is_array($j)) {
    throw new Exception('JSON inválido da Meeventos');
  }

  // Alguns endpoints retornam {data:[...]}, outros retornam [...] direto.
  // Não renomeamos campos para deixar o map do front funcionar (tipoEvento, horaevento, observacao, dataevento, id, etc.)
  if (isset($j['data']) && is_array($j['data'])) {
    $payload = $j['data'];
  } else {
    $payload = $j; // já é uma lista
  }

  echo json_encode(['ok' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
