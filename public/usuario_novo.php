<?php
// public/usuario_novo.php — novo usuário com layout alinhado (flexível ao schema)
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403); echo "Acesso negado."; exit;
}

require_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { http_response_code(500); echo "Falha na conexão."; exit; }

// Helpers
function cols(PDO $pdo, string $table): array {
  $st = $pdo->prepare("select column_name from information_schema.columns where table_schema=current_schema() and table_name=:t");
  $st->execute([":t"=>$table]);
  return $st->fetchAll(PDO::FETCH_COLUMN);
}
function hascol(array $cols, string $c): bool { return in_array($c, $cols, true); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$T='usuarios'; $C=cols($pdo,$T);

$colNome   = hascol($C,'nome') ? 'nome' : (hascol($C,'nome_completo') ? 'nome_completo' : (hascol($C,'name') ? 'name' : null));
$loginCands= array_values(array_filter(['loguin','login','usuario','username','user','email'], fn($c)=>hascol($C,$c)));
$colLogin  = $loginCands[0] ?? null;
$colAtivo  = hascol($C,'ativo') ? 'ativo' : (hascol($C,'status') ? 'status' : null);
$colFuncao = hascol($C,'funcao') ? 'funcao' : (hascol($C,'cargo') ? 'cargo' : null);
$colSenhaHash = hascol($C,'senha_hash') ? 'senha_hash' : null;
$colSenhaText = hascol($C,'senha') ? 'senha' : null;

$permKeys = array_values(array_filter([
  hascol($C,'perm_usuarios') ? 'perm_usuarios' : null,
  hascol($C,'perm_pagamentos') ? 'perm_pagamentos' : null,
  hascol($C,'perm_tarefas') ? 'perm_tarefas' : null,
  hascol($C,'perm_demandas') ? 'perm_demandas' : null,
  hascol($C,'perm_portao') ? 'perm_portao' : null,
  hascol($C,'perm_banco_smile') ? 'perm_banco_smile' : null,
  hascol($C,'perm_banco_smile_admin') ? 'perm_banco_smile_admin' : null,
  hascol($C,'perm_notas_fiscais') ? 'perm_notas_fiscais' : null,
  hascol($C,'perm_estoque_logistico') ? 'perm_estoque_logistico' : null,
  hascol($C,'perm_dados_contrato') ? 'perm_dados_contrato' : null,
  hascol($C,'perm_uso_fiorino') ? 'perm_uso_fiorino' : null,
]));

$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $colsIns=[]; $vals=[]; $bind=[];

    if ($colNome){ $colsIns[]=$colNome; $vals[]=':nome';  $bind[':nome']=trim((string)($_POST['nome']??'')); }
    if ($colLogin){$colsIns[]=$colLogin; $vals[]=':login'; $bind[':login']=trim((string)($_POST['login']??'')); }
    if ($colFuncao){$colsIns[]=$colFuncao; $vals[]=':funcao'; $bind[':funcao']=trim((string)($_POST['funcao']??'')); }

    if ($colAtivo){ $colsIns[]=$colAtivo; $vals[]=':ativo'; $bind[':ativo']= isset($_POST['ativo']) ? 1 : 0; }

    $senha = (string)($_POST['senha'] ?? '');
    if ($senha !== ''){
      if ($colSenhaHash){ $colsIns[]=$colSenhaHash; $vals[]=':sh'; $bind[':sh']=password_hash($senha, PASSWORD_DEFAULT); }
      elseif ($colSenhaText){ $colsIns[]=$colSenhaText; $vals[]=':st'; $bind[':st']=$senha; }
    }

    foreach ($permKeys as $k){ $colsIns[]=$k; $vals[]=':'.$k; $bind[':'.$k] = isset($_POST[$k]) ? 1 : 0; }

    if (!$colsIns) throw new RuntimeException("Nada para inserir.");
    $sql = "insert into {$T} (".implode(',', $colsIns).") values (".implode(',', $vals).")";
    $st = $pdo->prepare($sql); $st->execute($bind);

    header("Location: index.php?page=usuarios&msg=".urlencode("Usuário criado."));
    exit;
  } catch(Throwable $e){ $err=$e->getMessage(); }
}

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Novo usuário</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
:root{
  --bg:#0b1220; --panel:#0f1b35; --muted:#9fb0d9; --text:#fff;
  --b:#e5edff; --accent:#004aad;
}
body{margin:0; font-family:system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background:var(--bg); color:var(--text)}
.main-content{ padding:24px; }

.form-wrap{background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); border-radius:16px; padding:18px 20px; box-shadow:0 10px 30px rgba(0,0,0,.35); backdrop-filter: blur(6px);}
.form-title{margin:0 0 14px; font-weight:800; color:#cfe0ff; letter-spacing:.2px}

.form-grid{ display:grid; gap:12px; grid-template-columns: 1fr 1fr; }
.form-grid .full{ grid-column: 1 / -1; }
@media (max-width: 980px){ .form-grid{ grid-template-columns: 1fr; } }

.field{ display:flex; flex-direction:column; gap:6px; }
.field label{ font-size:14px; color:#d9e5ff; }
.field input[type="text"],
.field input[type="password"],
.field input[type="email"],
.field select, .field textarea{
  width:100%; padding:10px 12px; border-radius:10px; border:1px solid #2b3a64; background:#0b1430; color:#fff; outline:none;
}
.field small{ color:#9fb0d9; }

.checks{ display:grid; gap:8px; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)); background:#0b1430; border:1px dashed #2b3a64; padding:12px; border-radius:12px; }
.checks label{ display:flex; align-items:center; gap:8px; font-size:14px; color:#e6efff; }

.form-actions{ display:flex; gap:10px; margin-top:14px; }
.btn{background:#2563eb;color:#fff;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
.btn.btn-outline{ background:transparent; border:1px solid #2b3a64; color:#cfe0ff; }
</style>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) { include __DIR__.'/sidebar.php'; } ?>
<div class="main-content">
  <div class="form-wrap">
    <h1 class="form-title">Novo usuário</h1>

    <?php if (!empty($err)): ?><div class="login-erro" style="background:#ffeded;color:#8a0c0c;border:1px solid #ffb3b3;padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px"><b>Atenção:</b> <?php echo h($err); ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="form-grid">
        <div class="field">
          <label>Nome</label>
          <input type="text" name="nome" required>
        </div>

        <div class="field">
          <label>Login</label>
          <input type="text" name="login" required>
        </div>

        <?php if ($colFuncao): ?>
        <div class="field">
          <label>Função</label>
          <input type="text" name="funcao">
        </div>
        <?php endif; ?>

        <div class="field">
          <label>Senha</label>
          <input type="password" name="senha" required>
          <small>Se o banco tiver <code>senha_hash</code>, salvaremos com hash seguro.</small>
        </div>

        <?php if ($colAtivo): ?>
        <div class="field">
          <label>Status</label>
          <label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="ativo" checked> Ativo</label>
        </div>
        <?php endif; ?>

        <?php if ($permKeys): ?>
        <div class="field full">
          <label>Permissões</label>
          <div class="checks">
            <?php foreach ($permKeys as $k): ?>
              <label><input type="checkbox" name="<?php echo h($k); ?>"> <?php echo h(str_replace('_',' ', $k)); ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="form-actions">
        <button class="btn" type="submit">Criar</button>
        <a class="btn btn-outline" href="index.php?page=usuarios">Voltar</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
