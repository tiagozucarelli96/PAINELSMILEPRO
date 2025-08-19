<?php
// public/usuario_novo.php — criar usuário com layout idêntico ao editar
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403); echo "Acesso negado."; exit;
}

require_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { http_response_code(500); echo "Falha na conexão."; exit; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// mapeia colunas existentes
function table_cols(PDO $pdo, string $t): array{
  $st=$pdo->prepare("select column_name from information_schema.columns where table_schema=current_schema() and table_name=:t");
  $st->execute([':t'=>$t]); return $st->fetchAll(PDO::FETCH_COLUMN);
}
$cols = table_cols($pdo,'usuarios');

$colNome  = in_array('nome',$cols,true) ? 'nome' : (in_array('name',$cols,true)?'name':null);
$colLogin = (function($cols){
  foreach(['loguin','login','usuario','username','user','email'] as $c){ if(in_array($c,$cols,true)) return $c; }
  return null;
})($cols);
$colSenha   = (function($cols){
  foreach(['senha_hash','senha','password','pass'] as $c){ if(in_array($c,$cols,true)) return $c; }
  return null;
})($cols);
$colFuncao  = in_array('funcao',$cols,true) ? 'funcao' : (in_array('cargo',$cols,true)?'cargo':null);
$colAtivo   = in_array('ativo',$cols,true) ? 'ativo' : (in_array('status',$cols,true)?'status':null);

// permissões suportadas pela tabela
$permKeys = array_values(array_intersect([
  'perm_usuarios','perm_pagamentos','perm_tarefas','perm_demandas',
  'perm_portao','perm_banco_smile','perm_banco_smile_admin',
  'perm_dados_contrato','perm_uso_fiorino','perm_estoque_logistico'
], $cols));

// label bonito
function labelize($key){
  $k = str_replace('_',' ', (string)$key);
  $k = preg_replace('/\s+/', ' ', trim($k));
  $k = preg_replace('/^perm\b/i', 'perm', $k);
  return mb_convert_case($k, MB_CASE_TITLE, 'UTF-8');
}

$erro=''; $ok='';

// POST cria
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    if(!$colLogin) throw new Exception('Coluna de login não localizada na tabela `usuarios`.');

    $nome  = trim($_POST['nome'] ?? '');
    $login = trim($_POST['login'] ?? '');
    $func  = trim($_POST['funcao'] ?? '');
    $senha = (string)($_POST['senha'] ?? '');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($login==='') throw new Exception('Informe o login.');
    // monta INSERT conforme colunas que existem
    $fields=[]; $vals=[]; $bind=[];

    if($colNome){ $fields[]=$colNome; $vals[]=':nome';  $bind[':nome']=$nome; }
    $fields[]=$colLogin; $vals[]=':login'; $bind[':login']=$login;
    if($colFuncao){ $fields[]=$colFuncao; $vals[]=':func'; $bind[':func']=$func; }
    if($colAtivo){  $fields[]=$colAtivo;  $vals[]=':ativo'; $bind[':ativo']=$ativo; }

    if($colSenha){
      if ($senha!==''){
        // se a coluna aceita hash, guardamos com hash seguro
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $fields[]=$colSenha; $vals[]=':sh'; $bind[':sh']=$hash;
      } else {
        // sem senha => insere null se permitido
        $fields[]=$colSenha; $vals[]=':sh'; $bind[':sh']=null;
      }
    }

    // permissões
    foreach ($permKeys as $pk){
      $fields[] = $pk;
      $vals[]   = ':p_'.$pk;
      $bind[':p_'.$pk] = isset($_POST[$pk]) ? 1 : 0;
    }

    $sql = "insert into usuarios (".implode(',',$fields).") values (".implode(',',$vals).")";
    $st  = $pdo->prepare($sql);
    $st->execute($bind);

    header('Location: index.php?page=usuarios&msg='.urlencode('Usuário criado.'));
    exit;

  } catch(Throwable $e){
    $erro = $e->getMessage();
  }
}

?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Novo usuário</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f3f7ff;color:#0b1430;}
.main-content{padding:24px;}
.page-title{margin:0 0 16px;font-weight:800;color:#0c3a91;letter-spacing:.2px;}
.form{background:#fff;border:1px solid #dfe7f4;border-radius:16px;padding:18px 22px;box-shadow:0 10px 24px rgba(13,51,125,.08);}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;}
.grid .full{grid-column:1/-1;}
.input{width:100%;padding:12px 14px;border:1px solid #cfe0ff;border-radius:10px;background:#fff;}
label.small{display:block;margin:4px 0 6px;color:#33518a;font-weight:700;font-size:13px;}

.perm-wrap{border:1px dashed #dfe7f4;border-radius:12px;padding:14px 16px;background:#fff;}
.perm-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:14px 18px;align-items:center;}
.perm-item{display:flex;gap:8px;align-items:center;white-space:nowrap;}
.perm-item label{font-size:14px;color:#22324d;}
@media(max-width:1100px){ .perm-grid{grid-template-columns:repeat(3,1fr);} }
@media(max-width:700px){ .perm-grid{grid-template-columns:repeat(2,1fr);} }

.actions{display:flex;gap:10px;align-items:center;margin-top:14px;}
.btn{background:#004aad;color:#fff;border:none;border-radius:10px;padding:12px 16px;font-weight:800;cursor:pointer;}
.btn.gray{background:#e9efff;color:#004aad;}
.alert{padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px;}
.alert.err{background:#ffeded;color:#8a0c0c;border:1px solid #ffb3b3;}
</style>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) { include __DIR__.'/sidebar.php'; } ?>
<div class="main-content">
  <h1 class="page-title">Novo usuário</h1>

  <div class="form">
    <?php if ($erro): ?>
      <div class="alert err"><?php echo h($erro); ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
      <div class="grid">
        <div>
          <label class="small"><?php echo $colNome ? 'Nome' : 'Nome (opcional)'; ?></label>
          <input class="input" type="text" name="nome" value="">
        </div>
        <div>
          <label class="small">Login</label>
          <input class="input" type="text" name="login" required>
        </div>

        <div>
          <label class="small"><?php echo $colFuncao ? 'Função' : 'Função (opcional)'; ?></label>
          <input class="input" type="text" name="funcao" value="">
        </div>
        <div>
          <label class="small">Senha</label>
          <input class="input" type="password" name="senha" placeholder="Se a tabela tiver senha_hash, salvaremos com hash seguro.">
        </div>
      </div>

      <div class="grid" style="margin-top:10px">
        <div>
          <label class="small">Status</label>
          <div><input type="checkbox" name="ativo" checked> Ativo</div>
        </div>
      </div>

      <?php if ($permKeys): ?>
      <div class="perm-wrap" style="margin-top:16px">
        <div class="perm-grid">
          <?php foreach ($permKeys as $pk): ?>
            <div class="perm-item">
              <input type="checkbox" name="<?php echo h($pk); ?>">
              <label><?php echo h(labelize($pk)); ?></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="actions">
        <button class="btn" type="submit">Criar</button>
        <a class="btn gray" href="index.php?page=usuarios">Voltar</a>
      </div>
    </form>
  </div>
</div>
</body>
</html>
