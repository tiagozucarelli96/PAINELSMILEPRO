<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';


// ----- Config -----
$CLIENT_ID     = getenv('CLICKUP_CLIENT_ID') ?: '';
$CLIENT_SECRET = getenv('CLICKUP_CLIENT_SECRET') ?: '';
$REDIRECT_URI  = 'https://painelsmilepro-production.up.railway.app/auth/clickup/callback';
$RETURN_TO     = '/index.php?page=demandas'; // para onde voltar depois

if (!$CLIENT_ID || !$CLIENT_SECRET) {
  http_response_code(500);
  exit('ClickUp OAuth não configurado. Verifique variáveis de ambiente.');
}

// ----- Verifica sessão -----
if (empty($_SESSION['user_id'])) {
  // Redireciona para login do seu painel
  header('Location: /login.php');
  exit;
}

$userId = (int) $_SESSION['user_id'];

// ----- Recebe code -----
$code = $_GET['code'] ?? null;
if (!$code) {
  // Caso o usuário negue a autorização ou a URL venha sem code
  header('Location: ' . $RETURN_TO . '?oauth=denied');
  exit;
}

// ----- Troca code por token -----
$tokenUrl = 'https://api.clickup.com/api/v2/oauth/token';
$payload = [
  'client_id'     => $CLIENT_ID,
  'client_secret' => $CLIENT_SECRET,
  'code'          => $code,
];

$ch = curl_init();
curl_setopt_array($ch, [
  CURLOPT_URL            => $tokenUrl,
  CURLOPT_POST           => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  CURLOPT_TIMEOUT        => 20,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($raw === false || $http < 200 || $http >= 300) {
  $msg = $raw ?: $err;
  header('Location: ' . $RETURN_TO . '?oauth=error&detail=' . urlencode((string)$msg));
  exit;
}

$data = json_decode($raw, true);
$accessToken  = $data['access_token']  ?? null;
$refreshToken = $data['refresh_token'] ?? null;
$expiresIn    = $data['expires_in']    ?? null;

// ----- Descobre o usuário ClickUp (opcional, mas útil) -----
$clickupUserId = null;
if ($accessToken) {
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://api.clickup.com/api/v2/user',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 20,
  ]);
  $rawUser = curl_exec($ch);
  $httpUser = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($rawUser !== false && $httpUser >= 200 && $httpUser < 300) {
    $u = json_decode($rawUser, true);
    $clickupUserId = $u['user']['id'] ?? null;
  }
}

// ----- Calcula expires_at (se houver) -----
$expiresAt = null;
if ($expiresIn && is_numeric($expiresIn)) {
  // alguns retornos podem não trazer; trate como null
  $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
      ->add(new DateInterval('PT' . (int)$expiresIn . 'S'))
      ->format('Y-m-d H:i:sP'); // ISO
}

// ----- Upsert no banco -----
$sql = <<<SQL
INSERT INTO clickup_tokens (user_id, clickup_user_id, access_token, refresh_token, expires_at, created_at, updated_at)
VALUES (:user_id, :clickup_user_id, :access_token, :refresh_token, :expires_at, NOW(), NOW())
ON CONFLICT (user_id) DO UPDATE
SET clickup_user_id = EXCLUDED.clickup_user_id,
    access_token    = EXCLUDED.access_token,
    refresh_token   = EXCLUDED.refresh_token,
    expires_at      = EXCLUDED.expires_at,
    updated_at      = NOW();
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':user_id'         => $userId,
  ':clickup_user_id' => $clickupUserId,
  ':access_token'    => $accessToken,
  ':refresh_token'   => $refreshToken,
  ':expires_at'      => $expiresAt,
]);

// ----- Volta para a tela de tarefas -----
header('Location: ' . $RETURN_TO . '?oauth=ok');
exit;
