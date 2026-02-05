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

// Listar usuários para o dropdown (todos os cadastrados; inativos continuam bloqueados no login)
$usuarios_login = [];
if (empty($erro) && isset($pdo) && $pdo) {
    try {
        // Descobrir schema (PostgreSQL: public ou current_schema)
        $cols = [];
        foreach (["table_schema = current_schema()", "table_schema = 'public'"] as $schemaCond) {
            $res = $pdo->query("
                SELECT column_name FROM information_schema.columns
                WHERE {$schemaCond} AND table_name = 'usuarios'
            ");
            $cols = $res->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($cols)) {
                break;
            }
        }
        $has = function(string $c) use ($cols) { return in_array($c, $cols, true); };
        $loginCol = null;
        foreach (['loguin','login','usuario','username','user','email'] as $c) {
            if ($has($c)) { $loginCol = $c; break; }
        }
        if ($loginCol) {
            // Sem filtro ativo: listar todos; login ainda bloqueia inativos
            $sql = "SELECT id, nome, " . $loginCol . " AS login_val FROM usuarios ORDER BY nome";
            $stmt = $pdo->query($sql);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $usuarios_login[] = [
                    'valor' => (string)$row['login_val'],
                    'nome'  => $row['nome'] ?? $row['login_val'],
                ];
            }
        }
    } catch (Throwable $e) {
        // em falha, dropdown fica vazio; não quebra a tela
    }
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
                    // ativo? (se houver coluna) - compatível com boolean e int
                    $is_ativo = true;
                    if (array_key_exists('ativo', $u)) {
                        $ativo_val = $u['ativo'];
                        // Verifica se está INATIVO (false, 0, 'f', 'false', null)
                        if ($ativo_val === false || $ativo_val === 0 || $ativo_val === '0' || 
                            $ativo_val === 'f' || $ativo_val === 'false' || $ativo_val === null) {
                            $is_ativo = false;
                        }
                    }
                    
                    if (!$is_ativo) {
                        $erro = 'Usuário desativado.';
                    } else {
                        $_SESSION['logado'] = 1;
                        $_SESSION['id']     = (int)($u['id'] ?? 0);
                        $_SESSION['nome']   = $u['nome'] ?? ($u['nome_completo'] ?? ($u['name'] ?? 'Usuário'));
                        // Permissões continuam sendo tratadas pelo permissoes_boot.php no index.php
                        
                        // Verificar se é usuário interno e precisa de push
                        require_once __DIR__ . '/permissoes_boot.php';
                        $is_superadmin = !empty($_SESSION['perm_superadmin']);
                        $is_internal = $is_superadmin
                            || !empty($_SESSION['perm_administrativo'])
                            || !empty($_SESSION['perm_agenda'])
                            || !empty($_SESSION['perm_demandas'])
                            || !empty($_SESSION['perm_comercial'])
                            || !empty($_SESSION['perm_logistico'])
                            || !empty($_SESSION['perm_configuracoes'])
                            || !empty($_SESSION['perm_cadastros'])
                            || !empty($_SESSION['perm_financeiro'])
                            || !empty($_SESSION['perm_banco_smile']);
                        
                        if ($is_internal) {
                            // Verificar consentimento de push
                            try {
                                require_once __DIR__ . '/core/push_schema.php';
                                push_ensure_schema($pdo);
                                $stmt = $pdo->prepare("
                                    SELECT COUNT(*) 
                                    FROM sistema_notificacoes_navegador 
                                    WHERE usuario_id = :usuario_id 
                                    AND consentimento_permitido = TRUE 
                                    AND ativo = TRUE
                                ");
                                $stmt->execute([':usuario_id' => $_SESSION['id']]);
                                $hasConsent = $stmt->fetchColumn() > 0;
                                
                                if (!$hasConsent) {
                                    // Redirecionar para tela de bloqueio
                                    header('Location: push_block_screen.php');
                                    exit;
                                }
                            } catch (Exception $e) {
                                error_log("Erro ao verificar consentimento push: " . $e->getMessage());
                                // Em caso de erro, permitir acesso (não bloquear)
                            }
                        }
                        
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/* Layout do login */
:root {
  --bg-1: #081126;
  --bg-2: #0b1b3a;
  --bg-3: #0d2147;
  --card: rgba(255,255,255,0.06);
  --card-border: rgba(255,255,255,0.12);
  --field: rgba(7, 15, 32, 0.72);
  --field-border: rgba(255,255,255,0.18);
  --text: #e9edf7;
  --muted: #b8c2d8;
  --brand: #2b6cff;
  --brand-strong: #1f56d8;
}
body.login-bg{
  min-height:100vh;margin:0;
  background:
    radial-gradient(700px 420px at 15% 20%, rgba(39,79,170,0.25), transparent 60%),
    radial-gradient(700px 420px at 85% 80%, rgba(24,119,242,0.18), transparent 60%),
    linear-gradient(135deg, var(--bg-2), var(--bg-1) 55%, #060c1c);
  display:grid;place-items:center;
  font-family:"Manrope", "Segoe UI", sans-serif;
  color:var(--text);
}
.login-container{width:100%;max-width:460px;padding:18px}
.login-box{
  background:var(--card);
  border:1px solid var(--card-border);
  border-radius:20px;
  padding:28px;
  box-shadow:0 20px 50px rgba(4, 9, 20, 0.6);
  backdrop-filter:blur(10px);
}
.brand-row{
  display:flex;
  align-items:center;
  justify-content:center;
  margin-bottom:22px;
}
/* Logo sem efeitos: sem filter, sem brightness, cores originais */
.login-logo{
  width:120px;height:120px;border-radius:0;
  display:block;
  object-fit:contain;
  background:transparent;
  padding:0;
  box-shadow:none;
  filter:none;
  -webkit-filter:none;
  opacity:1;
}
h2{text-align:center;margin:8px 0 22px;font-weight:800;font-size:1.8rem}
.login-erro{background:#ffeded;color:#8a0c0c;border:1px solid #ffb3b3;padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:14px}
.login-field{
  width:100%;
  padding:14px 16px;
  margin:10px 0;
  border-radius:12px;
  border:1px solid var(--field-border);
  background:var(--field);
  color:var(--text);
  outline:none;
  font-size:1rem;
}
.login-field::placeholder{color:var(--muted)}
select.login-field{
  cursor:pointer;
  appearance:auto;
  -webkit-appearance:menulist;
}
.login-field:focus{
  border-color:rgba(43,108,255,0.65);
  box-shadow:0 0 0 3px rgba(43,108,255,0.2);
}
.login-submit{
  width:100%;
  padding:14px 16px;
  margin-top:12px;
  border-radius:12px;
  border:0;
  background:linear-gradient(180deg,var(--brand),var(--brand-strong));
  color:#fff;
  font-weight:700;
  font-size:1rem;
  cursor:pointer;
  letter-spacing:0.2px;
}
.login-submit:hover{filter:brightness(1.05)}
.footer-note{text-align:center;margin-top:14px;font-size:12px;color:var(--muted)}
</style>
</head>
<body class="login-bg">
  <div class="login-container">
    <div class="login-box">
      <div class="brand-row">
        <img class="login-logo" src="logo.png" alt="Logo do Grupo Smile">
      </div>
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
        <?php if (!empty($usuarios_login)): ?>
        <select class="login-field" name="login" required>
          <option value="">Selecione o usuário</option>
          <?php foreach ($usuarios_login as $u): ?>
          <option value="<?php echo h($u['valor']); ?>"><?php echo h($u['nome']); ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input class="login-field" type="text" name="login" placeholder="Login (usuário ou e-mail)" required>
        <?php endif; ?>
        <input class="login-field" type="password" name="senha" placeholder="Senha" required>
        <button class="login-submit" type="submit">Entrar</button>
      </form>

      <div class="footer-note">© Grupo Smile Eventos</div>
    </div>
  </div>
</body>
</html>
