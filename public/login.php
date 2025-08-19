<?php
// public/login.php — compatível com schema legado (login/usuario/email + senha_hash/md5/texto)
declare(strict_types=1);
ini_set('display_errors', getenv('APP_DEBUG')==='1' ? '1' : '0');
ini_set('display_startup_errors', getenv('APP_DEBUG')==='1' ? '1' : '0');
ini_set('log_errors','1'); ini_set('error_log','php://stderr');
session_start();

if (!empty($_SESSION['logado'])) { header('Location: dashboard.php'); exit; }
require_once __DIR__ . '/conexao.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $loginInput = trim($_POST['login'] ?? '');
  $senhaInput = (string)($_POST['senha'] ?? '');

  try {
    if ($loginInput === '' || $senhaInput === '') throw new Exception('Informe login e senha.');

    // Descobre colunas disponíveis
    $cols = $pdo->query("
      select column_name from information_schema.columns
      where table_schema = current_schema() and table_name = 'usuarios'
    ")->fetchAll(PDO::FETCH_COLUMN);

    $has = fn($c)=>in_array($c,$cols,true);
    $where = [];
    if ($has('login'))    $where[] = 'login = :l';
    if ($has('usuario'))  $where[] = 'usuario = :l';
    if ($has('email'))    $where[] = 'email = :l';
    if (!$where)          $where[] = 'login = :l'; // fallback

    $sql = "select * from usuarios where (".implode(' OR ',$where).") limit 1";
    $st = $pdo->prepare($sql);
    $st->execute([':l'=>$loginInput]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) throw new Exception('Usuário não encontrado.');

    // Ativo?
    if (array_key_exists('ativo',$u) && !$u['ativo']) {
      throw new Exception('Usuário inativo.');
    }

    // Verificação de senha: senha_hash (password_hash) > md5 > texto puro
    $ok = false;
    if (array_key_exists('senha_hash',$u) && $u['senha_hash']) {
      $ok = password_verify($senhaInput, (string)$u['senha_hash']);
    }
    if (!$ok && array_key_exists('senha',$u) && $u['senha']!=='') {
      $stored = (string)$u['senha'];
      if (preg_match('/^[a-f0-9]{32}$/i', $stored)) {
        $ok = (strtolower($stored) === md5($senhaInput));
      } else {
        // se por acaso antiga era texto puro
        $ok = hash_equals($stored, $senhaInput);
      }
    }
    if (!$ok) throw new Exception('Senha incorreta.');

    // Campos para sessão
    $nome = $u['nome'] ?? ($u['nome_completo'] ?? ($u['name'] ?? 'Usuário'));
    $_SESSION['logado'] = 1;
    $_SESSION['id']     = (int)($u['id'] ?? 0);
    $_SESSION['nome']   = $nome;

    header('Location: dashboard.php'); exit;

  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
body{font-family:system-ui;margin:0;background:#0b1220;color:#fff}
.wrap{max-width:420px;margin:60px auto;padding:24px;background:#0f1b35;border-radius:16px}
label{display:block;margin:8px 0 4px}
input{width:100%;padding:10px;border-radius:10px;border:1px solid #2b3a64;background:#0b1430;color:#fff}
.btn{margin-top:12px;width:100%;padding:12px;border:none;border-radius:10px;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}
.err{background:#5b1a1a;padding:10px;border-radius:8px;margin-bottom:10px}
a{color:#8ab4ff}
</style>
</head>
<body>
<div class="wrap">
  <h1>Acessar</h1>
  <?php if ($erro): ?><div class="err"><?=h($erro)?></div><?php endif; ?>
  <form method="post" action="login.php" novalidate>
    <label>Login / Usuário / E-mail</label>
    <input name="login" autocomplete="username" required>
    <label>Senha</label>
    <input name="senha" type="password" autocomplete="current-password" required>
    <button class="btn" type="submit">Entrar</button>
  </form>
  <p><a href="/">Voltar</a></p>
</div>
</body>
</html>
