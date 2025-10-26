<?php
// public/login.php — layout original + lógica compatível (sem mexer no fluxo)
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$debug = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
ini_set('log_errors','1'); ini_set('error_log','php://stderr');
error_reporting($debug ? E_ALL : (E_ALL & ~E_NOTICE));

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php'; // define $pdo / $db_error
$erro = '';



// Se já logado, vai para o painel
if (!empty($_SESSION['logado']) && $_SESSION['logado'] == 1) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Se houve erro de conexão, mostra o motivo
if (!empty($db_error ?? '')) {
    $erro = $db_error;
}

// ==== LÓGICA DE LOGIN (compatível com seu banco) ==== //
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($erro)) {
    // aceita "loguin" (legado), "login" ou "email"
    $loginInput = trim($_POST['loguin'] ?? ($_POST['login'] ?? ($_POST['email'] ?? '')));
    $senhaInput = (string)($_POST['senha'] ?? '');

    if ($loginInput === '' || $senhaInput === '') {
        $erro = 'Informe login e senha.';
    } else {
        try {
            if (!isset($pdo) || !$pdo) { throw new RuntimeException('Conexão não disponível.'); }

            // colunas existentes na tabela usuarios (no schema atual)
            $cols = $pdo->query("
                select column_name from information_schema.columns
                where table_schema = current_schema() and table_name = 'usuarios'
            ")->fetchAll(PDO::FETCH_COLUMN);

            $has = function(string $c) use ($cols){ return in_array($c, $cols, true); };

            // candidato de colunas para login e senha
            $loginWhere = [];
            foreach (['loguin','login','usuario','username','user','email'] as $c) {
                if ($has($c)) { $loginWhere[] = $c.' = :l'; }
            }
            if (!$loginWhere) { $loginWhere[] = 'login = :l'; } // fallback

            $senhaCol = null;
            foreach (['senha','senha_hash','password','pass'] as $c) {
                if ($has($c)) { $senhaCol = $c; break; }
            }
            if ($senhaCol === null) { throw new RuntimeException('Não encontrei a coluna de senha na tabela `usuarios`.'); }

            // busca usuário
            $sql = "select * from usuarios where (".implode(' OR ', $loginWhere).") limit 1";
            $st  = $pdo->prepare($sql);
            $st->execute([':l'=>$loginInput]);
            $u = $st->fetch(PDO::FETCH_ASSOC);

            if (!$u) {
                $erro = 'Usuário não encontrado.';
            } else {
                $stored = (string)$u[$senhaCol];
                $ok = false;

                // 1) password_hash (bcrypt/argon)
                if (!$ok && (preg_match('/^\$2[ayb]\$|\$argon2/i', $stored) === 1)) {
                    $ok = password_verify($senhaInput, $stored);
                }
                // 2) md5 legado
                if (!$ok && preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
                    $ok = (strtolower($stored) === md5($senhaInput));
                }
                // 3) texto puro
                if (!$ok) {
                    $ok = hash_equals($stored, $senhaInput);
                }

                if (!$ok) {
                    $erro = 'Senha inválida.';
                } else {
                    // ativo? (se houver coluna)
                    if (array_key_exists('ativo', $u) && (int)$u['ativo'] !== 1) {
                        $erro = 'Usuário desativado.';
                    } else {
                        $_SESSION['logado'] = 1;
                        $_SESSION['id']     = (int)($u['id'] ?? 0);
                        $_SESSION['nome']   = $u['nome'] ?? ($u['nome_completo'] ?? ($u['name'] ?? 'Usuário'));
                        // Permissões continuam sendo tratadas pelo permissoes_boot.php no index.php
                        header('Location: index.php?page=dashboard');
                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            $erro = 'Erro no login: '.$e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel Smile – Login</title>
<link rel="stylesheet" href="estilo.css">
<style>
/* Layout do login (igual ao antigo) */
body.login-bg{
  min-height:100vh;margin:0;
  background:linear-gradient(135deg,#0b1b3a,#0a1630 60%,#081024);
  display:grid;place-items:center;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#fff
}
.login-container{width:100%;max-width:420px;padding:16px}
.login-box{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);
  border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.45);backdrop-filter:blur(6px)}
.login-logo{width:140px;display:block;margin:0 auto 12px;filter:drop-shadow(0 2px 8px rgba(0,0,0,.35))}
h2{text-align:center;margin:6px 0 18px;font-weight:600}
.login-erro{background:#ffeded;color:#8a0c0c;border:1px solid #ffb3b3;padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px}
form input{width:100%;padding:12px 14px;margin:8px 0;border-radius:10px;border:1px solid rgba(255,255,255,.25);background:rgba(0,0,0,.35);color:#fff;outline:none}
form input::placeholder{color:#cdd3e1}
form button{width:100%;padding:12px 14px;margin-top:10px;border-radius:10px;border:0;background:#1e66ff;color:#fff;font-weight:600;cursor:pointer}
form button:hover{filter:brightness(1.08)}
.footer-note{text-align:center;margin-top:10px;font-size:12px;opacity:.8}
</style>
</head>
<body class="login-bg">
  <div class="login-container">
    <div class="login-box">
      <img class="login-logo" src="logo.png" alt="Logo do Grupo Smile">
      <h2>Acessar o Painel</h2>

      <?php if (!empty($_GET['erro']) && $_GET['erro'] === 'desativado'): ?>
        <div class="login-erro">Usuário desativado.</div>
      <?php endif; ?>

      <?php if (!empty($erro)): ?>
        <div class="login-erro"><strong>Atenção:</strong>
          <div><small><?php echo h($erro); ?></small></div>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <!-- Mantemos o nome "login" e também aceitamos "loguin" no PHP -->
        <input type="text" name="login" placeholder="Login (usuário ou e-mail)" required>
        <input type="password" name="senha" placeholder="Senha" required>
        <button type="submit">Entrar</button>
      </form>

      <div class="footer-note">© Grupo Smile Eventos</div>
    </div>
  </div>
</body>
</html>
