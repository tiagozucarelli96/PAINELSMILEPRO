<?php
// diagnostico_modal.php - Diagnóstico do problema do modal
session_start();

// Simular sessão
$_SESSION['logado'] = true;
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;

echo "<!DOCTYPE html>
<html>
<head>
    <title>Diagnóstico Modal</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .test-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .success { background: #d4edda; border-color: #c3e6cb; }
        .error { background: #f8d7da; border-color: #f5c6cb; }
        .info { background: #d1ecf1; border-color: #bee5eb; }
        button { padding: 10px 20px; margin: 5px; cursor: pointer; }
    </style>
</head>
<body>
    <h1>🔍 Diagnóstico do Modal de Usuários</h1>
    
    <div class='test-section info'>
        <h3>📊 Informações da Sessão</h3>
        <p><strong>Logado:</strong> " . ($_SESSION['logado'] ? 'Sim' : 'Não') . "</p>
        <p><strong>Perfil:</strong> " . ($_SESSION['perfil'] ?? 'não definido') . "</p>
        <p><strong>Perm Usuários:</strong> " . ($_SESSION['perm_usuarios'] ?? 'não definido') . "</p>
    </div>
    
    <div class='test-section'>
        <h3>🧪 Teste de JavaScript</h3>
        <button onclick='testModal()'>Testar Modal</button>
        <button onclick='testConsole()'>Testar Console</button>
        <div id='test-results'></div>
    </div>
    
    <div class='test-section'>
        <h3>🔗 Links de Teste</h3>
        <p><a href='usuarios.php' target='_blank'>usuarios.php (original)</a></p>
        <p><a href='test_modal_bypass.php' target='_blank'>test_modal_bypass.php (bypass)</a></p>
        <p><a href='modal_test_simple.php' target='_blank'>modal_test_simple.php (simples)</a></p>
    </div>
    
    <script>
        function testModal() {
            console.log('🧪 Testando modal...');
            const results = document.getElementById('test-results');
            
            try {
                // Criar modal temporário
                const modal = document.createElement('div');
                modal.id = 'testModal';
                modal.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';
                modal.innerHTML = '<div style=\"background: white; padding: 20px; border-radius: 8px;\"><h3>Modal de Teste</h3><p>Se você está vendo isso, o modal funciona!</p><button onclick=\"this.parentElement.parentElement.remove()\">Fechar</button></div>';
                
                document.body.appendChild(modal);
                
                // Remover após 3 segundos
                setTimeout(() => {
                    if (modal.parentElement) {
                        modal.remove();
                    }
                }, 3000);
                
                results.innerHTML = '<p style=\"color: green;\">✅ Modal criado com sucesso!</p>';
                console.log('✅ Modal de teste criado');
            } catch (error) {
                results.innerHTML = '<p style=\"color: red;\">❌ Erro ao criar modal: ' + error.message + '</p>';
                console.error('❌ Erro no modal:', error);
            }
        }
        
        function testConsole() {
            console.log('🔍 Testando console...');
            const results = document.getElementById('test-results');
            results.innerHTML = '<p style=\"color: blue;\">📝 Verifique o console do navegador (F12) para ver as mensagens de teste.</p>';
        }
        
        // Teste automático ao carregar
        window.addEventListener('load', function() {
            console.log('🚀 Página carregada - JavaScript funcionando');
            console.log('🔍 Verificando elementos...');
            
            // Verificar se há conflitos de JavaScript
            const scripts = document.querySelectorAll('script');
            console.log('📜 Scripts encontrados:', scripts.length);
            
            // Verificar se há erros de JavaScript
            window.addEventListener('error', function(e) {
                console.error('❌ Erro JavaScript:', e.error);
            });
        });
    </script>
</body>
</html>";
?>
