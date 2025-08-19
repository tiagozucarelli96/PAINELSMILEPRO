<?php
declare(strict_types=1);

// Debug (controlado por APP_DEBUG na Railway)
$APP_DEBUG = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', $APP_DEBUG ? '1' : '0');
error_reporting($APP_DEBUG ? E_ALL : 0);

// Fuso Brasil
date_default_timezone_set('America/Sao_Paulo');

// Sessão
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly','1');
    ini_set('session.use_strict_mode','1');
    session_name('SMILESESS');
    session_start();
}

// Usa apenas seus arquivos já existentes
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/config.php';

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $senha = (string)($_POST['senha'] ?? '');

    if ($login === '' || $senha === '') {
        $erro = 'Informe login e senha.';
    } else {
        try {
            $sql = "SELECT id, nome, login, senha, funcao,
                           COALESCE(perm_pagamentos,0) AS perm_pagamentos,
                           COALESCE(perm_tarefas,0)    AS perm_tarefas,
                           COALESCE(perm_demandas,0)   AS perm_demandas,
                           COALESCE(perm_usuarios,0)   AS perm_usuarios,
                           COALESCE(perm_portao,0)     AS perm_portao,
                           status
                    FROM usuarios
                    WHERE login = :login
                    LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([':login' => $login]);
            $u = $st->fetch();

            if (!$u) {
                $erro = 'Usuário não encontrado.';
            } else {
                $hash = (string)$u['senha'];
                $ok = false;

                // aceita texto puro (legado), md5 (legado) e password_hash (moderno)
                if ($hash === $senha) {
                    $ok = true;
                } elseif (strlen($hash) === 32 && $hash === md5($senha)) {
                    $ok = true;
                } elseif (password_get_info($hash)['algoName'] ?? false) {
                    $ok = password_verify($senha, $hash);
                }

                if (!$ok) {
                    $erro = 'Senha inválida.';
                } elseif (strtolower((string)$u['status']) !== 'ativo') {
                    $erro = 'Usuário inativo.';
                } else {
                    // mesmas variáveis de sessão que o sistema espera
                    $_SESSION['logado']          = 1;
                    $_SESSION['id_usuario']      = (int)$u['id'];
                    $_SESSION['nome']            = (string)$u['nome'];
                    $_SESSION['login']           = (string)$u['login'];
                    $_SESSION['funcao']          = (string)($u['funcao'] ?? '');
                    $_SESSION['perm_pagamentos'] = (int)$u['perm_pagamentos'];
                    $_SESSION['perm_tarefas']    = (int)$u['perm_tarefas'];
                    $_SESSION['perm_demandas']   = (int)$u['perm_demandas'];
                    $_SESSION['perm_usuarios']   = (int)$u['perm_usuarios'];
                    $_SESSION['perm_portao']     = (int)$u['perm_portao'];
                    $_SESSION['status']          = (string)$u['status'];

                    session_regenerate_id(true);
                    header('Location: /index.php?page=dashboard');
                    exit;
                }
            }
        } catch (Throwable $e) {
            $erro = 'Falha ao autenticar.' . ($APP_DEBUG ? ' Detalhes: '.$e->getMessage() : '');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Login • Painel Smile</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" href="/favicon.ico">
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif;background:#0b1a33;color:#fff;display:flex;min-height:100vh;align-items:center;justify-content:center}
.card{background:#0f2250;border:1px solid #1d3a7a;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.25);padding:28px;max-width:380px;width:100%}
h1{margin:0 0 16px;font-size:20px}
label{display:block;font-size:13px;margin:14px 0 6px}
input{width:100%;padding:12px;border-radius:10px;border:1px solid #355fb3;background:#0b1e44;color:#fff;outline:none}
input::placeholder{color:#9bb4e8}
.btn{margin-top:18px;width:100%;padding:12px 14px;border-radius:10px;border:0;background:#2d6bff;color:#fff;font-weight:700;cursor:pointer}
.err{background:#3a0f1b;border:1px solid #932c45;color:#ffdbe4;padding:10px 12px;border-radius:10px;margin-bottom:10px;font-size:13px}
.footer{font-size:12px;opacity:.7;text-align:center;margin-top:10px}
</style>
</head>
<body>
  <form class="card" method="post" action="/login.php" autocomplete="off">
    <h1>Entrar</h1>
    <?php if ($erro): ?><div class="err"><?=htmlspecialchars($erro)?></div><?php endif; ?>
    <label for="login">Login</label>
    <input id="login" name="login" type="text" placeholder="seu usuário" autofocus required>
    <label for="senha">Senha</label>
    <input id="senha" name="senha" type="password" placeholder="sua senha" required>
    <button class="btn" type="submit">Acessar</button>
    <div class="footer">© Grupo Smile Eventos</div>
  </form>
</body>
</html>
