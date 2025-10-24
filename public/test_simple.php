<?php
/**
 * test_simple.php — Teste simples sem redirecionamentos
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Simples - Sem Redirecionamentos</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #2E7D32; background: #e8f5e8; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { color: #1976D2; background: #e3f2fd; padding: 10px; border-radius: 5px; margin: 10px 0; }
        h1 { color: #333; text-align: center; }
        .button { display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .button:hover { background: #1976D2; }
        .button.success { background: #4CAF50; }
        .button.success:hover { background: #45a049; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Teste Simples - Sem Redirecionamentos</h1>
        
        <div class="success">
            <h3>✅ Página Funcionando!</h3>
            <p>Esta página não tem redirecionamentos e deve funcionar normalmente.</p>
        </div>
        
        <div class="info">
            <h3>📋 Informações do Sistema</h3>
            <p><strong>Ambiente:</strong> LOCAL</p>
            <p><strong>Data/Hora:</strong> 24/10/2025 20:44:11</p>
            <p><strong>PHP Version:</strong> 8.4.14</p>
            <p><strong>Server:</strong> N/A</p>
        </div>
        
        <div style="text-align: center; margin: 20px 0;">
            <a href="test_simple.php" class="button">🔄 Recarregar Página</a>
            <a href="fix_redirect_loop_web.php" class="button success">🔧 Corrigir Redirecionamentos</a>
        </div>
    </div>
</body>
</html>