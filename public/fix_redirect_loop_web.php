<?php
/**
 * fix_redirect_loop_web.php — Corrigir loop de redirecionamento via web
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Correção de Redirecionamentos</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #2E7D32; background: #e8f5e8; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .warning { color: #E65100; background: #fff3e0; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .error { color: #C62828; background: #ffebee; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { color: #1976D2; background: #e3f2fd; padding: 10px; border-radius: 5px; margin: 10px 0; }
        h1 { color: #333; text-align: center; }
        h2 { color: #555; border-bottom: 2px solid #2196F3; padding-bottom: 10px; }
        .button { display: inline-block; padding: 10px 20px; background: #2196F3; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        .button:hover { background: #1976D2; }
        .button.success { background: #4CAF50; }
        .button.success:hover { background: #45a049; }
        .code { background: #f5f5f5; padding: 10px; border-radius: 5px; font-family: monospace; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Correção de Loop de Redirecionamento</h1>
        
        <div class="info">
            <h3>🌍 Ambiente Detectado: LOCAL</h3>
            <p>Data/Hora: 24/10/2025 20:44:11</p>
        </div>
        
        <div class="success">
            <h3>✅ Página Funcionando!</h3>
            <p>Esta página não tem redirecionamentos e deve funcionar normalmente.</p>
        </div>
        
        <div class="warning">
            <h3>⚠️ Possíveis Causas do Loop de Redirecionamento:</h3>
            <ul>
                <li>Redirecionamentos infinitos entre páginas</li>
                <li>Problemas de sessão</li>
                <li>Configurações de cookies</li>
                <li>Problemas de autenticação</li>
                <li>Arquivos de roteamento com loops</li>
            </ul>
        </div>
        
        <div class="info">
            <h3>🔧 Soluções Recomendadas:</h3>
            <ol>
                <li><strong>Limpar cookies do navegador</strong></li>
                <li><strong>Verificar arquivos de roteamento</strong></li>
                <li><strong>Verificar configurações de sessão</strong></li>
                <li><strong>Testar páginas individualmente</strong></li>
                <li><strong>Verificar logs de erro</strong></li>
            </ol>
        </div>
        
        <div style="text-align: center; margin: 20px 0;">
            <a href="test_simple.php" class="button">🧪 Teste Simples</a>
            <a href="fix_redirect_loop_web.php" class="button">🔄 Recarregar</a>
            <a href="dashboard.php" class="button success">🏠 Dashboard</a>
        </div>
    </div>
</body>
</html>