<?php
// public/usuario_editar.php — edição com layout e labels melhorados
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403); echo "Acesso negado."; exit;
}

require_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { http_response_code(500); echo "Falha na conexão."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function truthy($v){ return (int)$v === 1; }

// Mapeia colunas do banco
function table_cols(PDO $pdo, string $t): array {
  $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :t");
  $st->execute([':t'=>$t]); return $st->fetchAll(PDO::FETCH_COLUMN);
}
$cols = table_cols($pdo, 'usuarios');

// Detecta colunas
$colNome = in_array('nome', $cols, true) ? 'nome' : (in_array('nome_completo', $cols, true) ? 'nome_completo' : (in_array('name', $cols, true) ? 'name' : null));
$colLogin = (function($cols){
  foreach(['loguin','login','usuario','username','user','email'] as $c){ if(in_array($c,$cols,true)) return $c; }
  return null;
})($cols);
$colSenha = (function($cols){
  foreach(['senha_hash','senha','password','pass'] as $c){ if(in_array($c,$cols,true)) return $c; }
  return null;
})($cols);
$colFuncao = in_array('funcao',$cols,true) ? 'funcao' : (in_array('cargo',$cols,true)?'cargo':null);
$colAtivo  = in_array('ativo',$cols,true) ? 'ativo' : (in_array('status',$cols,true)?'status':null);

// Permissões suportadas
$permKeys = array_values(array_intersect([
  'perm_usuarios','perm_pagamentos','perm_tarefas','perm_demandas',
  'perm_portao','perm_banco_smile','perm_banco_smile_admin',
  'perm_dados_contrato','perm_uso_fiorino','perm_estoque_logistico'
], $cols));

// Labels personalizados
$permLabels = [
  'perm_usuarios' => 'Usuários',
  'perm_pagamentos' => 'Pagamentos',
  'perm_tarefas' => 'Tarefas',
  'perm_demandas' => 'Demandas',
  'perm_portao' => 'Portão',
  'perm_banco_smile' => 'Banco Smile',
  'perm_banco_smile_admin' => 'Banco Smile (Admin)',
  'perm_dados_contrato' => 'Dados Contrato',
  'perm_uso_fiorino' => 'Uso do Fiorino',
  'perm_estoque_logistico' => 'Estoque Logístico',
];

// --- Carrega usuário
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo "ID inválido."; exit; }

$u = $pdo->prepare("SELECT * FROM usuarios WHERE id = :id LIMIT 1");
$u->execute([':id'=>$id]);
$u = $u->fetch(PDO::FETCH_ASSOC);
if (!$u) { http_response_code(404); echo "Usuário não encontrado."; exit; }

$erro = '';

// --- Salvar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!$colLogin) throw new Exception('Coluna de login não localizada.');

    $nome  = trim($_POST['nome'] ?? ($u[$colNome] ?? ''));
    $login = trim($_POST['login'] ?? ($u[$colLogin] ?? ''));
    $func  = trim($_POST['funcao'] ?? ($u[$colFuncao] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($login === '') throw new Exception('Informe o login.');

    $set = []; $bind = [':id'=>$id];
    if($colNome){  $set[]="{$colNome}=:nome";   $bind[':nome']=$nome; }
    $set[]="{$colLogin}=:login";                $bind[':login']=$login;
    if($colFuncao){$set[]="{$colFuncao}=:func"; $bind[':func']=$func; }
    if($colAtivo){ $set[]="{$colAtivo}=:ativo"; $bind[':ativo']=$ativo; }

    if ($colSenha && $senha !== '') {
      $hash = password_hash($senha, PASSWORD_DEFAULT);
      $set[] = "{$colSenha}=:sh"; $bind[':sh'] = $hash;
    }

    foreach ($permKeys as $pk){
      $set[] = "{$pk}=:p_{$pk}";
      $bind[":p_{$pk}"] = isset($_POST[$pk]) ? 1 : 0;
    }

    if ($set) {
      $sql = "UPDATE usuarios SET " . implode(',', $set) . " WHERE id = :id";
      $st = $pdo->prepare($sql);
      $st->execute($bind);
    }

    header('Location: index.php?page=usuarios&msg=' . urlencode('Usuário salvo.'));
    exit;

  } catch(Throwable $e) {
    $erro = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Editar usuário #<?php echo (int)$id; ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f3f7ff;color:#0b1430;}
.main-content{padding:24px;}
.page-title{margin:0 0 16px;font-weight:800;color:#0c3a91;}
.form{background:#fff;border:1px solid #dfe7f4;border-radius:16px;padding:20px;box-shadow:0 10px 24px rgba(13,51,125,.08);}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;}
.grid .full{grid-column:1/-1;}
.input{width:100%;padding:12px 14px;border:1px solid #cfe0ff;border-radius:10px;background:#fff;}
label.small{display:block;margin:4px 0 6px;color:#33518a;font-weight:700;font-size:13px;}

.perm-wrap{margin-top:18px;padding:16px;border:1px dashed #dfe7f4;border-radius:12px;background:#fff;}
.perm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;}
.perm-item{display:flex;gap:8px;align-items:center;white-space:nowrap;}
.perm-item label{font-size:14px;color:#22324d;}
.actions{display:flex;gap:10px;margin-top:20px;}
.btn{background:#004aad;color:#fff;border:none;border-radius:10px;padding:12px 16px;font-weight:800;cursor:pointer;}
.btn.gray{background:#e9efff;color:#004aad;}
.alert{padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px;}
.alert.err{background:#ffeded;color:#8a0c0c;border:1px solid #ffb3b3;}
</style>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
  <h1 class="page-title"> #<?php echo (int)$id; ?><?php if ($colNome) echo ' — '.h($u[$colNome] ?? ''); ?></h1>


  <div class="form">
    <?php if ($erro): ?>
      <div class="alert err"><?php echo h($erro); ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="grid">
        <div>
          <label class="small">Nome</label>
          <input class="input" type="text" name="nome" value="<?php echo h($colNome ? ($u[$colNome] ?? '') : ''); ?>" <?php echo $colNome ? '' : 'disabled'; ?>>
        </div>
        <div>
          <label class="small">Login</label>
          <input class="input" type="text" name="login" value="<?php echo h($u[$colLogin] ?? ''); ?>" required>
        </div>
        <div>
          <label class="small">Função</label>
          <input class="input" type="text" name="funcao" value="<?php echo h($colFuncao ? ($u[$colFuncao] ?? '') : ''); ?>" <?php echo $colFuncao ? '' : 'disabled'; ?>>
        </div>
        <div>
          <label class="small">Senha (deixe em branco para manter)</label>
          <input class="input" type="password" name="senha" placeholder="<?php echo $colSenha==='senha_hash'?'Será salva com hash seguro':''; ?>">
        </div>
        <div class="full">
          <label class="small">Status</label>
          <input type="checkbox" name="ativo" <?php echo ($colAtivo && truthy($u[$colAtivo] ?? 0)) ? 'checked' : ''; ?>> Ativo
        </div>
      </div>

      <?php if ($permKeys): ?>
      <div class="perm-wrap">
        <label class="small">Permissões</label>
        <div class="perm-grid">
          <?php foreach ($permKeys as $k): ?>
            <div class="perm-item">
              <input type="checkbox" name="<?php echo h($k); ?>" <?php echo truthy($u[$k] ?? 0) ? 'checked' : ''; ?>>
              <label><?php echo h($permLabels[$k] ?? $k); ?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="actions">
        <button class="btn" type="submit">Salvar</button>
        <a class="btn gray" href="index.php?page=usuarios">Voltar</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
