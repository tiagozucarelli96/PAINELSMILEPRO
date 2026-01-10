<?php
// contabilidade_login.php — Login público da contabilidade (link externo)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

$erro = '';
$token = '';

// Verificar se já está logado
if (!empty($_SESSION['contabilidade_logado']) && $_SESSION['contabilidade_logado'] === true) {
    header('Location: contabilidade_painel.php');
    exit;
}

// Processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = trim($_POST['senha'] ?? '');
    
    if (empty($senha)) {
        $erro = 'Senha é obrigatória';
    } else {
        try {
            // Buscar configuração de acesso
            $stmt = $pdo->prepare("SELECT * FROM contabilidade_acesso WHERE status = 'ativo' LIMIT 1");
            $stmt->execute();
            $acesso = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$acesso) {
                $erro = 'Acesso não configurado ou inativo';
            } else {
                // Verificar senha
                if (password_verify($senha, $acesso['senha_hash'])) {
                    // Criar token de sessão
                    $token = bin2hex(random_bytes(32));
                    $expira_em = date('Y-m-d H:i:s', strtotime('+8 hours'));
                    
                    // Salvar sessão no banco
                    $stmt = $pdo->prepare("
                        INSERT INTO contabilidade_sessoes (token, acesso_id, ip_address, user_agent, expira_em)
                        VALUES (:token, :acesso_id, :ip, :ua, :expira)
                    ");
                    $stmt->execute([
                        ':token' => $token,
                        ':acesso_id' => $acesso['id'],
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                        ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
                        ':expira' => $expira_em
                    ]);
                    
                    // Criar sessão PHP
                    $_SESSION['contabilidade_logado'] = true;
                    $_SESSION['contabilidade_token'] = $token;
                    $_SESSION['contabilidade_acesso_id'] = $acesso['id'];
                    
                    // Redirecionar para o painel
                    header('Location: contabilidade_painel.php');
                    exit;
                } else {
                    $erro = 'Senha inválida';
                }
            }
        } catch (Exception $e) {
            $erro = 'Erro ao processar login. Tente novamente.';
            error_log("Erro no login da contabilidade: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Contabilidade</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            padding: 3rem;
            width: 100%;
            max-width: 400px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .login-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 0.5rem;
        }
        
        .login-header p {
            color: #64748b;
            font-size: 0.875rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-label {
            display: block;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 1rem;
            transition: all 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        
        .btn-login {
            width: 100%;
            padding: 0.75rem;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-login:hover {
            background: #1e3a8a;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }
        
        .alert {
            padding: 0.75rem;
            border-radius: 6px;
            margin-bottom: 1rem;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>📑 Contabilidade</h1>
            <p>Acesso ao sistema</p>
        </div>
        
        <?php if ($erro): ?>
        <div class="alert alert-error">
            <?= htmlspecialchars($erro) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Senha de Acesso</label>
                <input type="password" name="senha" class="form-input" 
                       placeholder="Digite sua senha" required autofocus>
            </div>
            
            <button type="submit" class="btn-login">
                🔓 Acessar
            </button>
        </form>
    </div>
</body>
</html>
