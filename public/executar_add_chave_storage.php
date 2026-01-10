<?php
// executar_add_chave_storage.php — Executar SQL para adicionar chave_storage na contabilidade
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Permitir acesso apenas para administradores
if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    die('Acesso negado. Faça login como administrador.');
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executar SQL - Adicionar chave_storage</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 2rem;
        }
        .container {
            max-width: 800px;
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
        .result-item {
            padding: 1rem;
            margin: 0.5rem 0;
            border-radius: 8px;
            border-left: 4px solid;
        }
        .result-item.ok {
            background: #d1fae5;
            border-color: #059669;
            color: #065f46;
        }
        .result-item.erro {
            background: #fee2e2;
            border-color: #dc2626;
            color: #991b1b;
        }
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
        .btn-back {
            display: inline-block;
            margin-top: 2rem;
            padding: 0.75rem 1.5rem;
            background: #1e40af;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 500;
        }
        .btn-back:hover {
            background: #1e3a8a;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Executar SQL - Adicionar chave_storage</h1>
        
        <?php
        $sql_file = __DIR__ . '/../sql/add_chave_storage_contabilidade.sql';
        
        if (!file_exists($sql_file)) {
            echo '<div class="result-item erro">❌ Arquivo SQL não encontrado: ' . htmlspecialchars($sql_file) . '</div>';
            echo '<a href="index.php?page=contabilidade" class="btn-back">← Voltar</a>';
            exit;
        }
        
        $sql_content = file_get_contents($sql_file);
        
        if (empty($sql_content)) {
            echo '<div class="result-item erro">❌ Arquivo SQL está vazio</div>';
            echo '<a href="index.php?page=contabilidade" class="btn-back">← Voltar</a>';
            exit;
        }
        
        echo '<div class="code-block">' . htmlspecialchars($sql_content) . '</div>';
        
        // Dividir SQL em comandos individuais
        $commands = array_filter(
            array_map('trim', explode(';', $sql_content)),
            function($cmd) {
                return !empty($cmd) && !preg_match('/^\s*--/', $cmd);
            }
        );
        
        $sucesso = 0;
        $erros = 0;
        $resultados = [];
        
        foreach ($commands as $command) {
            if (empty(trim($command))) {
                continue;
            }
            
            // Remover comentários de linha
            $command = preg_replace('/--.*$/m', '', $command);
            $command = trim($command);
            
            if (empty($command)) {
                continue;
            }
            
            try {
                $pdo->exec($command);
                $resultados[] = [
                    'comando' => substr($command, 0, 100) . (strlen($command) > 100 ? '...' : ''),
                    'sucesso' => true,
                    'mensagem' => 'Executado com sucesso'
                ];
                $sucesso++;
            } catch (PDOException $e) {
                $resultados[] = [
                    'comando' => substr($command, 0, 100) . (strlen($command) > 100 ? '...' : ''),
                    'sucesso' => false,
                    'mensagem' => $e->getMessage()
                ];
                $erros++;
            }
        }
        
        // Exibir resultados
        echo '<h2 style="margin-top: 2rem; color: #374151;">📊 Resultados da Execução</h2>';
        echo '<p style="margin: 1rem 0; color: #64748b;">';
        echo '<strong>Sucesso:</strong> ' . $sucesso . ' | ';
        echo '<strong>Erros:</strong> ' . $erros;
        echo '</p>';
        
        foreach ($resultados as $result) {
            $classe = $result['sucesso'] ? 'ok' : 'erro';
            $icon = $result['sucesso'] ? '✅' : '❌';
            echo '<div class="result-item ' . $classe . '">';
            echo '<strong>' . $icon . ' ' . htmlspecialchars($result['comando']) . '</strong><br>';
            echo '<small>' . htmlspecialchars($result['mensagem']) . '</small>';
            echo '</div>';
        }
        
        if ($erros === 0) {
            echo '<div class="result-item ok" style="margin-top: 2rem;">';
            echo '<strong>✅ SQL executado com sucesso!</strong><br>';
            echo 'Todas as colunas chave_storage foram adicionadas nas tabelas da contabilidade.';
            echo '</div>';
        } else {
            echo '<div class="result-item erro" style="margin-top: 2rem;">';
            echo '<strong>⚠️ Alguns comandos falharam</strong><br>';
            echo 'Verifique os erros acima. Algumas colunas podem já existir (isso é normal).';
            echo '</div>';
        }
        ?>
        
        <a href="index.php?page=contabilidade" class="btn-back">← Voltar para Contabilidade</a>
    </div>
</body>
</html>
