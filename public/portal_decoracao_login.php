<?php
/**
 * portal_decoracao_login.php
 * Login do Portal Decoração (acesso externo)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';

$redirect_raw = trim((string)($_GET['redirect'] ?? ''));
$redirect_target = '';
$starts_ok = (substr($redirect_raw, 0, 9) === '/index.php' || substr($redirect_raw, 0, 8) === 'index.php');
if ($redirect_raw !== '' && $starts_ok && strpos($redirect_raw, 'page=portal_decoracao') !== false) {
    $redirect_target = $redirect_raw;
}

// Se já logado, redirecionar
if (!empty($_SESSION['portal_decoracao_logado']) && $_SESSION['portal_decoracao_logado'] === true) {
    header('Location: ' . ($redirect_target !== '' ? $redirect_target : 'index.php?page=portal_decoracao'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $senha = $_POST['senha'] ?? '';
    
    if (!$login || !$senha) {
        $error = 'Preencha login e senha';
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM eventos_fornecedores 
            WHERE login = :login AND tipo = 'decoracao' AND ativo = TRUE
        ");
        $stmt->execute([':login' => $login]);
        $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fornecedor) {
            $error = 'Login não encontrado ou inativo';
        } elseif (!password_verify($senha, $fornecedor['senha_hash'])) {
            $error = 'Senha incorreta';
        } else {
            // Criar sessão
            $token = bin2hex(random_bytes(32));
            $expira = date('Y-m-d H:i:s', strtotime('+8 hours'));
            
            $stmt = $pdo->prepare("
                INSERT INTO eventos_fornecedores_sessoes (fornecedor_id, token, ip_address, user_agent, criado_em, expira_em, ativo)
                VALUES (:fid, :token, :ip, :ua, NOW(), :expira, TRUE)
            ");
            $stmt->execute([
                ':fid' => $fornecedor['id'],
                ':token' => $token,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ':expira' => $expira
            ]);
            
            $_SESSION['portal_decoracao_logado'] = true;
            $_SESSION['portal_decoracao_fornecedor_id'] = $fornecedor['id'];
            $_SESSION['portal_decoracao_nome'] = $fornecedor['nome'];
            $_SESSION['portal_decoracao_token'] = $token;
            
            header('Location: ' . ($redirect_target !== '' ? $redirect_target : 'index.php?page=portal_decoracao'));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Decoração - Login</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .login-card {
            background: white;
            border-radius: 16px;
            padding: 2.5rem;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header .icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .login-header h1 {
            font-size: 1.5rem;
            color: #1e293b;
        }
        .login-header p {
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        .form-group {
            margin-bottom: 1.25rem;
        }
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 0.375rem;
            color: #374151;
        }
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
        }
        .form-input:focus {
            outline: none;
            border-color: #059669;
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }
        .btn {
            width: 100%;
            padding: 0.875rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: white;
            transition: all 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
        }
        .alert {
            background: #fee2e2;
            color: #991b1b;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.875rem;
        }
        .footer {
            text-align: center;
            margin-top: 2rem;
            color: #64748b;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="icon">🎨</div>
            <h1>Portal Decoração</h1>
            <p>Acesse sua agenda de eventos</p>
        </div>
        
        <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Login</label>
                <input type="text" name="login" class="form-input" required autofocus>
            </div>
            <div class="form-group">
                <label class="form-label">Senha</label>
                <input type="password" name="senha" class="form-input" required>
            </div>
            <button type="submit" class="btn">Entrar</button>
        </form>
        
        <div class="footer">
            Grupo Smile - Portal de Fornecedores
        </div>
    </div>
</body>
</html>
