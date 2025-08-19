<?php
// public/usuario_editar.php — edição com layout alinhado (flexível ao schema)
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403); echo "Acesso negado."; exit;
}

require_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { http_response_code(500); echo "Falha na conexão."; exit; }

// Helpers de schema
function cols(PDO $pdo, string $table): array {
  $st = $pdo->prepare("select column_name from information_schema.columns where table_schema=current_schema() and table_name=:t");
  $st->execute([":t"=>$table]);
  return $st->fetchAll(PDO::FETCH_COLUMN);
}
function hascol(array $cols, string $c): bool { return in_array($c, $cols, true); }
function truthy($v): bool { $s=strtolower((string)$v); return $s==='1'||$s==='t'||$s==='true'||$s==='on'||$s==='y'||$s==='yes'; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$T = 'usuarios';
$C = cols($pdo, $T);

$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { header("Location: index.php?page=usuarios"); exit; }

// Descobre colunas “principais”
$colNome   = hascol($C,'nome') ? 'nome' : (hascol($C,'nome_completo') ? 'nome_completo' : (hascol($C,'name') ? 'name' : null));
$loginCands= array_values(array_filter(['loguin','login','usuario','username','user','email'], fn($c)=>hascol($C,$c)));
$colLogin  = $loginCands[0] ?? null;
$colAtivo  = hascol($C,'ativo') ? 'ativo' : (hascol($C,'status') ? 'status' : null);
$colFuncao = hascol($C,'funcao') ? 'funcao' : (hascol($C,'cargo') ? 'cargo' : null);
$colSenhaHash = hascol($C,'senha_hash') ? 'senha_hash' : null;
$colSenhaText = hascol($C,'senha') ? 'senha' : null;

// Permissões conhecidas
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

// Carrega usuário
$st = $pdo->prepare("select * from {$T} where id=:id limit 1");
$st->execute([':id'=>$id]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { header("Location: index.php?page=usuarios"); exit; }

// POST: salvar
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    $set = []; $bind = [':id'=>$id];

    if ($colNome)  { $set[]="{$colNome}=:nome";   $bind[':nome']  = trim((string)($_POST['nome']  ?? $u[$colNome]  ?? '')); }
    if ($colLogin) { $set[]="{$colLogin}=:login"; $bind[':login'] = trim((string)($_POST['login'] ?? $u[$colLogin] ?? '')); }
    if ($colFuncao){ $set[]="{$colFuncao}=:funcao"; $bind[':funcao']= trim((string)($_POST['funcao']?? $u[$colFuncao] ?? '')); }

    if ($colAtivo){
      $ativo = isset($_POST['ativo']) ? 1 : 0;
      $set[]="{$colAtivo}=:ativo"; $bind[':ativo']=$ativo;
    }

    // senha: só atualiza se preenchida
    $nova = (string)($_POST['senha'] ?? '');
    if ($nova !== '') {
      if ($colSenhaHash){
        $set[]="{$colSenhaHash}=:sh"; $bind[':sh'] = password_hash($nova, PASSWORD_DEFAULT);
      } elseif ($colSenhaText){
        $set[]="{$colSenhaText}=:st"; $bind[':st'] = $nova; // legado
      }
    }

    foreach ($permKeys as $k){
      $set[]="{$k}=:{$k}";
      $bind[":{$k}"] = isset($_POST[$k]) ? 1 : 0;
    }

    if (!$set) { throw new RuntimeException("Nada para atualizar."); }
    $sql = "update {$T} set ".implode(', ',$set)." where id=:id";
    $st = $pdo->prepare($sql); $st->execute($bind);

    header("Location: index.php?page=usuarios&msg=".urlencode("Usuário atualizado."));
    exit;
  } catch(Throwable $e){ $err = $e->getMessage(); }
}

// Helpers view
$val = function($key, $def='') use ($u){ return h($u[$key] ?? $def); };

?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Editar usuário</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
:root{ --bg:#eaf2ff; --text:#0b1430; }
body{margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background:#f3f7ff; color:#0b1430;}
.main-content{ padding:24px; }

.form-wrap{background:#fff; border:1px solid #dfe7f4; border-radius:16px; padding:18px 20px; box-shadow:0 10px 24px rgba(13,51,125,.08);}
.form-title{margin:0 0 14px; font-weight:800; color:#0c3a91; letter-spacing:.2px}

.form-grid{ display:grid; gap:12px; grid-template-columns: 1fr 1fr; }
.form-grid .full{ grid-column: 1 / -1; }
@media (max-width: 980px){ .form-grid{ grid-template-columns: 1fr; } }

.field{ display:flex; flex-direction:column; gap:6px; }
.field label{ font-size:14px; color:#2b3a64; }
.field input[type="text"],
.field input[type="password"],
.field input[type="email"],
.field select, .field textarea{
  width:100%; padding:10px 12px; border-radius:10px; border:1px solid #cfe0ff; background:#fff; color:#0b1430; outline:none;
}
.field small{ color:#6b7aa6; }

.checks{ display:grid; gap:8px; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)); background:#fafcff; border:1px dashed #cfe0ff; padding:12px; border-radius:12px; }
.checks label{ display:flex; align-items:center; gap:8px; font-size:14px; color:#0b1430; }

.form-actions{ display:flex; gap:10px; margin-top:14px; }
.btn{background:#004aad;color:#fff;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
.btn.btn-outline{ background:#f5f8ff; border:1px solid #cfe0ff; color:#004aad; }
</style>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) { include __DIR__.'/sidebar.php'; } ?>
<div class="main-content">
  <div class="form-wrap">
    <h1 class="form-title">Editar usuário #<?php echo (int)$id; ?></h1>

    <?php if (!empty($err)): ?>
      <div style="background:#ffeded;color:#8a0c0c;border:1px solid #ffb3b3;padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px">
        <strong>Atenção:</strong> <?php echo h($err); ?>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="form-grid">
        <div class="field">
          <label>Nome</label>
          <input type="text" name="nome" value="<?php echo $colNome ? $val($colNome) : ''; ?>">
        </div>

        <div class="field">
          <label>Login</label>
          <input type="text" name="login" value="<?php echo $colLogin ? $val($colLogin) : ''; ?>">
        </div>

        <?php if ($colFuncao): ?>
        <div class="field">
          <label>Função</label>
          <input type="text" name="funcao" value="<?php echo $val($colFuncao); ?>">
        </div>
        <?php endif; ?>

        <div class="field">
          <label>Senha (deixe em branco para não alterar)</label>
          <input type="password" name="senha" value="">
          <small>Se existir <code>senha_hash</code>, salvaremos com hash seguro.</small>
        </div>

        <?php if ($colAtivo): ?>
        <div class="field">
          <label>Status</label>
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" name="ativo" <?php echo truthy($u[$colAtivo] ?? 1) ? 'checked' : ''; ?>> Ativo
          </label>
        </div>
        <?php endif; ?>

        <?php if ($permKeys): ?>
        <div class="field full">
          <label>Permissões</label>
          <div class="checks">
            <?php foreach ($permKeys as $k): ?>
              <label><input type="checkbox" name="<?php echo h($k); ?>" <?php echo truthy($u[$k] ?? 0) ? 'checked' : ''; ?>> <?php echo h(str_replace('_',' ', $k)); ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <div class="form-actions">
        <button class="btn" type="submit">Salvar</button>
        <a class="btn btn-outline" href="index.php?page=usuarios">Voltar</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
