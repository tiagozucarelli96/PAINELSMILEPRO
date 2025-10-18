<?php
// me_proxy.php — proxy simples p/ Meeventos (GET /api/v1/events)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
  $base = getenv('ME_BASE_URL') ?: 'https://app2.meeventos.com.br/lisbonbuffet';
  $key  = getenv('ME_API_KEY')  ?: '5qlrv-crt91-s0e0d-drri2-gxhlm'; // ajuste no .env se necessário

  if (!$base || !$key) throw new Exception('ME_BASE_URL/ME_API_KEY ausentes.');

  // filtros opcionais
  $q     = isset($_GET['q'])     ? trim($_GET['q'])     : '';
  $start = isset($_GET['start']) ? trim($_GET['start']) : '';
  $end   = isset($_GET['end'])   ? trim($_GET['end'])   : '';

  // monta URL (ajuste se seu backend já enviar datas no fetch do modal)
  $url = rtrim($base, '/').'/api/v1/events';
  $params = [];
  if ($q !== '')     $params['search'] = $q;
  if ($start !== '') $params['start']  = $start;
  if ($end !== '')   $params['end']    = $end;
  if ($params) $url .= '?'.http_build_query($params);

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Authorization: '.$key]
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($err)  throw new Exception('Erro cURL: '.$err);
  if ($code < 200 || $code >= 300) throw new Exception('HTTP '.$code.' da Meeventos');

  // → padroniza retorno mínimo pro modal
  $data = json_decode($resp, true);
  if (!is_array($data)) throw new Exception('JSON inválido da Meeventos');

  // mapeamento defensivo (ajuste conforme payload real)
  $out = [];
  foreach ($data as $e) {
    $out[] = [
      'id'        => $e['id']           ?? null,
      'titulo'    => $e['title']        ?? ($e['nome'] ?? 'Evento'),
      'data'      => $e['date']         ?? ($e['data'] ?? null),
      'hora'      => $e['time']         ?? ($e['hora'] ?? null),
      'espaco'    => $e['location']     ?? ($e['espaco'] ?? null),
      'convidados'=> $e['guests']       ?? ($e['convidados'] ?? null),
    ];
  }

  echo json_encode(['ok'=>true,'count'=>count($out),'items'=>$out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(200);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
