<?php
// test_magalu_contabilidade.php — Teste de configuração Magalu para Contabilidade
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Permitir acesso apenas para administradores ou contabilidade logada
$is_admin = !empty($_SESSION['logado']) && !empty($_SESSION['perm_administrativo']);
$is_contabilidade = !empty($_SESSION['contabilidade_logado']) && $_SESSION['contabilidade_logado'] === true;

if (!$is_admin && !$is_contabilidade) {
    die('Acesso negado. Faça login como administrador ou contabilidade.');
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/magalu_storage_helper.php';
require_once __DIR__ . '/magalu_integration_helper.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Magalu - Contabilidade</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1e3a8a;
            margin-bottom: 1rem;
        }
        .section {
            margin: 2rem 0;
            padding: 1.5rem;
            background: #f9fafb;
            border-radius: 8px;
        }
        .section h2 {
            color: #374151;
            margin-bottom: 1rem;
            font-size: 1.25rem;
        }
        .ok { color: #059669; }
        .erro { color: #dc2626; }
        .warning { color: #f59e0b; }
        .info { color: #3b82f6; }
        .status-item {
            padding: 0.5rem;
            margin: 0.25rem 0;
            border-left: 4px solid;
        }
        .status-item.ok { border-color: #059669; background: #d1fae5; }
        .status-item.erro { border-color: #dc2626; background: #fee2e2; }
        .status-item.warning { border-color: #f59e0b; background: #fef3c7; }
        .code-block {
            background: #1f2937;
            color: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.875rem;
            overflow-x: auto;
            margin: 1rem 0;
        }
        .test-form {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: white;
            border-radius: 8px;
            border: 2px dashed #d1d5db;
        }
        button {
            background: #1e40af;
            color: white;
            padding: 0.75rem 2rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            margin-top: 1rem;
        }
        button:hover {
            background: #2563eb;
        }
        input[type="file"] {
            margin: 1rem 0;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            width: 100%;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Teste de Configuração Magalu - Contabilidade</h1>
        
        <?php
        // Teste 1: Verificar variáveis de ambiente
        echo '<div class="section">';
        echo '<h2>1️⃣ Variáveis de Ambiente</h2>';
        
        $vars = [
            'MAGALU_ACCESS_KEY' => $_ENV['MAGALU_ACCESS_KEY'] ?? getenv('MAGALU_ACCESS_KEY'),
            'MAGALU_SECRET_KEY' => $_ENV['MAGALU_SECRET_KEY'] ?? getenv('MAGALU_SECRET_KEY'),
            'MAGALU_BUCKET' => $_ENV['MAGALU_BUCKET'] ?? getenv('MAGALU_BUCKET'),
            'MAGALU_REGION' => $_ENV['MAGALU_REGION'] ?? getenv('MAGALU_REGION'),
            'MAGALU_ENDPOINT' => $_ENV['MAGALU_ENDPOINT'] ?? getenv('MAGALU_ENDPOINT'),
        ];
        
        foreach ($vars as $name => $value) {
            $status = !empty($value) ? 'ok' : 'erro';
            $icon = !empty($value) ? '✅' : '❌';
            $display = !empty($value) ? (strpos($name, 'KEY') !== false ? substr($value, 0, 10) . '...' : $value) : 'NÃO CONFIGURADO';
            echo "<div class='status-item $status'>$icon <strong>$name:</strong> $display</div>";
        }
        echo '</div>';
        
        // Teste 2: Verificar isConfigured()
        echo '<div class="section">';
        echo '<h2>2️⃣ Verificação de Configuração</h2>';
        
        try {
            $magalu = new MagaluStorageHelper();
            $is_configured = $magalu->isConfigured();
            
            if ($is_configured) {
                echo '<div class="status-item ok">✅ Magalu Storage Helper está configurado corretamente</div>';
            } else {
                echo '<div class="status-item erro">❌ Magalu Storage Helper NÃO está configurado</div>';
                echo '<div class="code-block">Verifique os logs acima para ver quais variáveis estão faltando.</div>';
            }
        } catch (Exception $e) {
            echo '<div class="status-item erro">❌ Erro ao inicializar: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        echo '</div>';
        
        // Teste 3: Testar conexão
        echo '<div class="section">';
        echo '<h2>3️⃣ Teste de Conexão</h2>';
        
        try {
            $magalu = new MagaluStorageHelper();
            $test_result = $magalu->testConnection();
            
            if ($test_result['success']) {
                echo '<div class="status-item ok">✅ Conexão com Magalu OK: ' . htmlspecialchars($test_result['message'] ?? 'Sucesso') . '</div>';
            } else {
                echo '<div class="status-item erro">❌ Falha na conexão: ' . htmlspecialchars($test_result['error'] ?? 'Erro desconhecido') . '</div>';
            }
        } catch (Exception $e) {
            echo '<div class="status-item erro">❌ Erro ao testar conexão: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
        echo '</div>';
        
        // Teste 4: Formulário de upload de teste
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo_teste'])) {
            echo '<div class="section">';
            echo '<h2>4️⃣ Resultado do Upload de Teste</h2>';
            
            try {
                $magalu_helper = new MagaluIntegrationHelper();
                $resultado = $magalu_helper->uploadContabilidade($_FILES['arquivo_teste'], 'contabilidade/teste');
                
                if ($resultado['sucesso']) {
                    echo '<div class="status-item ok">✅ Upload bem-sucedido!</div>';
                    echo '<div class="code-block">';
                    echo 'URL: ' . htmlspecialchars($resultado['url'] ?? 'N/A') . "\n";
                    echo 'Key: ' . htmlspecialchars($resultado['key'] ?? 'N/A') . "\n";
                    echo 'Filename: ' . htmlspecialchars($resultado['filename'] ?? 'N/A');
                    echo '</div>';
                } else {
                    echo '<div class="status-item erro">❌ Upload falhou: ' . htmlspecialchars($resultado['erro'] ?? 'Erro desconhecido') . '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="status-item erro">❌ Exceção: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
            echo '</div>';
        }
        
        // Formulário de teste
        echo '<div class="section">';
        echo '<h2>4️⃣ Teste de Upload</h2>';
        echo '<p>Faça upload de um arquivo de teste para verificar se o sistema está funcionando:</p>';
        echo '<form method="POST" enctype="multipart/form-data" class="test-form">';
        echo '<input type="file" name="arquivo_teste" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx,.csv" required>';
        echo '<button type="submit">📤 Fazer Upload de Teste</button>';
        echo '</form>';
        echo '</div>';
        ?>
        
        <div style="margin-top: 2rem; padding: 1rem; background: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 4px;">
            <p><strong>💡 Dicas:</strong></p>
            <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                <li>Verifique os logs do Railway para mensagens detalhadas de debug</li>
                <li>Se "Access Denied", verifique permissões das credenciais no painel Magalu</li>
                <li>Se "não configurado", verifique se as variáveis estão no Railway</li>
                <li>Se "arquivo muito grande", o limite é 10MB</li>
            </ul>
        </div>
    </div>
</body>
</html>
