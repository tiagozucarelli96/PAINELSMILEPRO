<?php
// login.php
ini_set('display_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php'; // deve definir $pdo e $db_error (se houver)
$erro = '';

// Utilitários para detectar colunas existentes
function colExists(PDO $pdo, string $table, string $col): bool {
    $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :t
              AND COLUMN_NAME = :c";
    $st = $pdo->prepare($sql);
    $st->execute([':t' => $table, ':c' => $col]);
    return (bool)$st->fetchColumn();
}
function firstExistingCol(PDO $pdo, string $table, array $candidates, ?string $fallback = null): ?string {
    foreach ($candidates as $c) {
        if (colExists($pdo, $table, $c)) return $c;
    }
    return $fallback;
}

// Se já logado, vai para o painel
if (!empty($_SESSION['logado']) && $_SESSION['logado'] == 1) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Se houve erro de conexão no conexao.php, exibe banner e evita tentar login
if (!empty($db_error ?? '')) {
    $erro = $db_error;
}

// Processa login somente se há PDO ativo e sem erro pendente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($erro)) {
    $loginInput = trim($_POST['loguin'] ?? $_POST['login'] ?? $_POST['email'] ?? '');
    $senhaInput = $_POST['senha'] ?? ''; // não usar trim()

    if ($loginInput === '' || $senhaInput === '') {
        $erro = 'Informe login e senha.';
    } else {
        try {
            if (!isset($pdo) || !$pdo) throw new Exception('Conexão não disponível.');

            // Detecta nomes reais das colunas na tabela `usuarios`
            $loginCol = firstExistingCol($pdo, 'usuarios', ['loguin','login','usuario','username','user','email']);
            $senhaCol = firstExistingCol($pdo, 'usuarios', ['senha','senha_hash','password','pass']);

            if (!$loginCol) throw new Exception('Não encontrei a coluna de login na tabela `usuarios`.');
            if (!$senhaCol) throw new Exception('Não encontrei a coluna de senha na tabela `usuarios`.');

            // Permissões/estado: se não existirem no esquema, caem para valor padrão via alias
            $permCols = [
                'perm_usuarios'         => 0,
                'perm_financeiro_admin' => 0,
                'perm_portao'           => 0,
                'ativo'                 => 1,
            ];
            $selectParts = ['id', 'nome', "{$loginCol} AS login_val", "{$senhaCol} AS senha_val"];
            foreach ($permCols as $c => $def) {
                if (colExists($pdo, 'usuarios', $c)) {
                    $selectParts[] = $c;
                } else {
                    // constante com alias para garantir índice no array retornado
                    $selectParts[] = (is_int($def) ? $def : ("'".$def."'")) . " AS {$c}";
                }
            }

            $sql = "SELECT ".implode(', ', $selectParts)."
                    FROM usuarios
                    WHERE {$loginCol} = :login
                    LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([':login' => $loginInput]);
            $u = $st->fetch();

            if (!$u) {
                $erro = 'Usuário não encontrado.';
            } else {
                $dbHash = (string)$u['senha_val'];
                $ok = false;

                // 1) hash seguro (bcrypt/argon)
                if (preg_match('/^\$2[ayb]\$|\$argon2/i', $dbHash) && password_verify($senhaInput, $dbHash)) {
                    $ok = true;
                }
                // 2) texto puro
                if (!$ok && hash_equals($dbHash, $senhaInput)) {
                    $ok = true;
                }
                // 3) md5 (legado)
                if (!$ok && hash_equals($dbHash, md5($senhaInput))) {
                    $ok = true;
                }

                if ($ok) {
                    if ((int)$u['ativo'] !== 1) {
                        $erro = 'Usuário desativado.';
                    } else {
                        $_SESSION['logado']                 = 1;
                        $_SESSION['id_usuario']             = (int)$u['id'];
                        $_SESSION['nome']                   = $u['nome'];
                        $_SESSION['perm_usuarios']          = (int)$u['perm_usuarios'];
                        $_SESSION['perm_financeiro_admin']  = (int)$u['perm_financeiro_admin'];
                        $_SESSION['perm_portao']            = (int)$u['perm_portao'];
                        $_SESSION['ativo']                  = (int)$u['ativo'];

                        header('Location: index.php?page=dashboard');
                        exit;
                    }
                } else {
                    $erro = 'Senha inválida.';
                }
            }
        } catch (Throwable $e) {
            $erro = 'Erro no login: ' . $e->getMessage();
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
/* fallback visual */
body.login-bg{min-height:100vh;margin:0;background:linear-gradient(135deg,#0b1b3a,#0a1630 60%,#081024);display:grid;place-items:center;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#fff}
.login-container{width:100%;max-width:420px;padding:16px}
.login-box{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.45);backdrop-filter:blur(6px)}
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
                <div class="login-erro"><strong>Atenção:</strong> <div><small><?= htmlspecialchars($erro) ?></small></div></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="text" name="loguin" placeholder="Login (usuário ou e-mail)" required>
                <input type="password" name="senha" placeholder="Senha" required>
                <button type="submit">Entrar</button>
            </form>

            <div class="footer-note">© Grupo Smile Eventos</div>
        </div>
    </div>
</body>
</html>
