<?php
// File: public/tarefas.php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/conexao.php'; // $pdo (PDO Postgres)

if (empty($_SESSION['user_id'])) {
  header('Location: /login.php'); exit;
}
$userId = (int) $_SESSION['user_id'];

// ---- Helpers ----
function clickup_authorize_url(): string {
  $clientId    = getenv('CLICKUP_CLIENT_ID') ?: '';
  $redirectUri = 'https://painelsmilepro-production.up.railway.app/auth/clickup/callback';
  $scopes = [
    'user:read','team:read','space:read','list:read',
    'task:read','task:write','comment:write'
  ];
  $params = http_build_query([
    'client_id'   => $clientId,
    'redirect_uri'=> $redirectUri,
    'scope'       => implode(',', $scopes),
  ]);
  return "https://app.clickup.com/api?{$params}";
}

function get_clickup_token(PDO $pdo, int $userId): ?array {
  $stmt = $pdo->prepare("SELECT * FROM clickup_tokens WHERE user_id = :u LIMIT 1");
  $stmt->execute([':u'=>$userId]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

function cu_get(string $endpoint, string $accessToken, array $query=[]): array {
  $url = 'https://api.clickup.com/api/v2/' . ltrim($endpoint, '/');
  if ($query) {
    $url .= (str_contains($url,'?')?'&':'?') . http_build_query($query);
  }
  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_TIMEOUT        => 20,
  ]);
  $raw = curl_exec($ch);
  $http= curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  if ($raw === false || $http < 200 || $http >= 300) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

// ---- Estado de conexão ----
$tok = get_clickup_token($pdo, $userId);
$isConnected = $tok && !empty($tok['access_token']);
$accessToken = $isConnected ? $tok['access_token'] : null;

// ---- UI ----
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Tarefas (ClickUp)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,Helvetica,sans-serif;background:#f7f9fc;margin:0}
    header{background:#0b5ed7;color:#fff;padding:14px 18px;font-weight:600}
    .wrap{max-width:1000px;margin:18px auto;padding:0 12px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px;box-shadow:0 2px 10px rgba(0,0,0,.04)}
    .btn{background:#0b5ed7;color:#fff;border:none;padding:10px 14px;border-radius:10px;cursor:pointer}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    select,input{padding:8px;border:1px solid #d0d7e2;border-radius:10px}
    .list{margin-top:14px}
    .task{display:flex;justify-content:space-between;align-items:center;border:1px solid #eef2f7;border-radius:12px;padding:10px;margin:8px 0;background:#fff}
    .pill{font-size:12px;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#244;display:inline-block}
    .muted{color:#667085;font-size:13px}
    .title{font-weight:600}
    .empty{padding:20px;text-align:center;color:#6b7280}
  </style>
</head>
<body>
<header>📌 Tarefas – Integração ClickUp</header>
<div class="wrap">

<?php if(!$isConnected): ?>
  <div class="card">
    <p class="muted">Conecte sua conta ClickUp para ver e gerenciar suas demandas sem sair do Painel.</p>
    <a class="btn" href="<?=htmlspecialchars(clickup_authorize_url(),ENT_QUOTES)?>">Conectar ao ClickUp</a>
  </div>
<?php else: ?>
  <?php
    // 1) Times (workspaces)
    $teams = cu_get('team', $accessToken);
    $teamId = $teams['teams'][0]['id'] ?? null;

    // 2) Spaces do time
    $spaces = $teamId ? cu_get("team/{$teamId}/space", $accessToken) : [];
    $spaceId = $spaces['spaces'][0]['id'] ?? null;

    // 3) Lists (pode vir de folder OU direto do space)
    $lists = [];
    if ($spaceId) {
      // Primeiro tenta listas diretas do space:
      $spaceLists = cu_get("space/{$spaceId}/list", $accessToken);
      if (!empty($spaceLists['lists'])) $lists = $spaceLists['lists'];
      // (Opcional) Poderíamos ler folders e somar suas lists aqui.
    }

    // Seleção simples (MVP): primeira List disponível
    $listId = $lists[0]['id'] ?? null;

    // 4) Tarefas da List
    $tasks = $listId ? cu_get("list/{$listId}/task", $accessToken, ['page'=>0,'subtasks'=>true]) : ['tasks'=>[]];
    $tasksArr = $tasks['tasks'] ?? [];
  ?>

  <div class="card">
    <div class="row">
      <div>
        <div class="muted">Workspace</div>
        <select disabled>
          <?php foreach(($teams['teams'] ?? []) as $t): ?>
            <option><?=$t['name'] ?? ('Team '.$t['id'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <div class="muted">Space</div>
        <select disabled>
          <?php foreach(($spaces['spaces'] ?? []) as $s): ?>
            <option><?=$s['name'] ?? ('Space '.$s['id'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <div class="muted">List</div>
        <select disabled>
          <?php foreach(($lists ?? []) as $l): ?>
            <option><?=$l['name'] ?? ('List '.$l['id'])?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="margin-left:auto">
        <button class="btn" onclick="alert('MVP: aqui vamos abrir o modal de Nova Demanda (POST /list/{list_id}/task)')">+ Nova demanda</button>
      </div>
    </div>

    <div class="list">
      <?php if(empty($tasksArr)): ?>
        <div class="empty">Nenhuma tarefa encontrada na primeira lista do seu Space (MVP). Depois adicionamos filtros/seleção.</div>
      <?php else: ?>
        <?php foreach($tasksArr as $t):
              $title = $t['name'] ?? 'Sem título';
              $status= $t['status']['status'] ?? '—';
              $ass   = $t['assignees'][0]['username'] ?? ($t['assignees'][0]['email'] ?? '—');
              $due   = !empty($t['due_date']) ? date('d/m/Y H:i', (int)($t['due_date']/1000)) : 'sem prazo';
        ?>
          <div class="task">
            <div>
              <div class="title"><?=$title?></div>
              <div class="muted">Resp.: <?=$ass?> • Prazo: <?=$due?></div>
            </div>
            <div><span class="pill"><?=$status?></span></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

</div>
</body>
</html>
