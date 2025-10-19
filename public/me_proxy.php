<?php
// me_proxy.php — proxy simples p/ Meeventos (GET /api/v1/events)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
  // Carrega configurações da ME Eventos
  require_once __DIR__ . '/me_config.php';
  
  $base = getenv('ME_BASE_URL') ?: ME_BASE_URL;
  $key  = getenv('ME_API_KEY')  ?: ME_API_KEY;

  // Log de debug
  error_log("ME Proxy Debug - Base URL: $base");
  error_log("ME Proxy Debug - API Key: " . substr($key, 0, 10) . "...");

  if (!$base || !$key) throw new Exception('ME_BASE_URL/ME_API_KEY ausentes.');

  // filtros opcionais
  $q     = isset($_GET['q'])     ? trim($_GET['q'])     : '';
  $start = isset($_GET['start']) ? trim($_GET['start']) : '';
  $end   = isset($_GET['end'])   ? trim($_GET['end'])   : '';

  // Monta URL conforme documentação da ME Eventos
  $url = rtrim($base, '/').'/api/v1/events';
  $params = [];
  if ($q !== '')     $params['search'] = $q;
  if ($start !== '') $params['start']  = $start;
  if ($end !== '')   $params['end']    = $end;
  if ($params) $url .= '?'.http_build_query($params);

  // Log de debug
  error_log("ME Proxy Debug - URL final: $url");

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => [
      'Authorization: '.$key,  // Conforme documentação oficial - SEM Bearer
      'Content-Type: application/json',
      'Accept: application/json'
    ]
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  // Log de debug
  error_log("ME Proxy Debug - HTTP Code: $code");
  error_log("ME Proxy Debug - Response: " . substr($resp, 0, 200) . "...");

  if ($err)  throw new Exception('Erro cURL: '.$err);
  if ($code < 200 || $code >= 300) {
    // Log mais detalhado para debug
    error_log("ME Proxy Debug - Erro HTTP $code - Response completa: " . $resp);
    throw new Exception('HTTP '.$code.' da Meeventos - Verifique a API key e URL');
  }

  // → padroniza retorno mínimo pro modal
  $data = json_decode($resp, true);
  if (!is_array($data)) throw new Exception('JSON inválido da Meeventos');

  // Log de debug para ver a estrutura real
  error_log("ME Proxy Debug - Estrutura da resposta: " . json_encode($data, JSON_PRETTY_PRINT));

  // A API da ME Eventos retorna os dados diretamente no array, não em 'data'
  $events = $data;
  
  // mapeamento conforme API da ME Eventos - campos corretos da documentação
  $out = [];
  foreach ($events as $e) {
    $out[] = [
      'id'            => $e['id'] ?? null,
      'observacao'    => $e['observacao'] ?? '',
      'tipoEvento'    => $e['tipoEvento'] ?? '',
      'convidados'    => $e['convidados'] ?? 0,
      'dataevento'    => $e['dataevento'] ?? '',
      'horaevento'    => $e['horaevento'] ?? '',
      'nomeCliente'   => $e['nomeCliente'] ?? '',
      'nomeevento'    => $e['nomeevento'] ?? '',
    ];
  }

  echo json_encode(['ok'=>true,'count'=>count($out),'data'=>$out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
