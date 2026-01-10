<?php
// push_block_screen.php — Tela de bloqueio obrigatória para ativação de push
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar se está logado
if (empty($_SESSION['logado']) || empty($_SESSION['id'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';

$usuario_id = (int)$_SESSION['id'];
$is_admin = !empty($_SESSION['perm_administrativo']);
$is_internal = $is_admin || !empty($_SESSION['perm_agenda']) || !empty($_SESSION['perm_demandas']) || 
               !empty($_SESSION['perm_logistico']) || !empty($_SESSION['perm_financeiro']);

// Se não for usuário interno, redirecionar
if (!$is_internal) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Verificar se já tem consentimento
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM sistema_notificacoes_navegador 
        WHERE usuario_id = :usuario_id 
        AND consentimento_permitido = TRUE 
        AND ativo = TRUE
    ");
    $stmt->execute([':usuario_id' => $usuario_id]);
    $hasConsent = $stmt->fetchColumn() > 0;
    
    if ($hasConsent) {
        // Já tem consentimento, liberar acesso
        header('Location: index.php?page=dashboard');
        exit;
    }
} catch (Exception $e) {
    error_log("Erro ao verificar consentimento: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ativação Obrigatória - Portal Grupo Smile</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .block-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            padding: 3rem;
            text-align: center;
        }
        
        .icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
        }
        
        h1 {
            color: #1e40af;
            font-size: 2rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }
        
        .message {
            color: #374151;
            font-size: 1.125rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        
        .policy-text {
            background: #f3f4f6;
            border-left: 4px solid #1e40af;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            text-align: left;
            color: #4b5563;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        
        .policy-text strong {
            color: #1e40af;
        }
        
        .btn-activate {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 1rem 3rem;
            font-size: 1.125rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }
        
        .btn-activate:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 64, 175, 0.4);
        }
        
        .btn-activate:active {
            transform: translateY(0);
        }
        
        .btn-activate:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 1rem;
            border-radius: 8px;
            margin-top: 1rem;
            display: none;
        }
        
        .error-message.show {
            display: block;
        }
        
        .loading {
            display: none;
            margin-top: 1rem;
            color: #6b7280;
        }
        
        .loading.show {
            display: block;
        }
    </style>
</head>
<body>
    <div class="block-container">
        <div class="icon">🔔</div>
        <h1>Ativação de Notificações Obrigatória</h1>
        <p class="message">
            Para utilizar o Portal Grupo Smile, é necessário ativar as notificações no navegador.
        </p>
        
        <div class="policy-text">
            <strong>Política Interna:</strong><br>
            As notificações push no navegador são obrigatórias para todos os usuários internos do sistema. 
            Isso garante que você receba atualizações importantes em tempo real sobre eventos, demandas e 
            outras informações relevantes do sistema.
        </div>
        
        <button id="btnActivate" class="btn-activate" onclick="activateNotifications()">
            Ativar Notificações
        </button>
        
        <div id="loading" class="loading">
            Processando...
        </div>
        
        <div id="errorMessage" class="error-message"></div>
    </div>
    
    <script src="/js/push-notifications.js"></script>
    <script>
        let pushManager = window.pushNotificationsManager;
        const userId = <?= $usuario_id ?>;
        const isUserInternal = true;
        
        // Inicializar (sem solicitar permissão ainda)
        pushManager.init(userId, isUserInternal);
        
        /**
         * Ativar notificações
         */
        async function activateNotifications() {
            const btn = document.getElementById('btnActivate');
            const loading = document.getElementById('loading');
            const errorMessage = document.getElementById('errorMessage');
            
            btn.disabled = true;
            loading.classList.add('show');
            errorMessage.classList.remove('show');
            
            try {
                // Solicitar permissão
                await pushManager.requestPermission();
                
                // Registrar service worker
                await pushManager.registerServiceWorker();
                
                // Criar subscription
                await pushManager.subscribe();
                
                // Sucesso - redirecionar
                window.location.href = 'index.php?page=dashboard';
                
            } catch (error) {
                console.error('Erro ao ativar notificações:', error);
                
                errorMessage.textContent = 'Erro ao ativar notificações: ' + error.message;
                errorMessage.classList.add('show');
                
                btn.disabled = false;
                loading.classList.remove('show');
            }
        }
        
        // Prevenir fechamento da página
        window.addEventListener('beforeunload', (e) => {
            e.preventDefault();
            e.returnValue = '';
        });
        
        // Prevenir navegação
        document.addEventListener('keydown', (e) => {
            // Bloquear F5, Ctrl+R, etc
            if (e.key === 'F5' || (e.ctrlKey && e.key === 'r')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
