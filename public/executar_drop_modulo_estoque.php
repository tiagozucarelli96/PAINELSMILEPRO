<?php
// executar_drop_modulo_estoque.php
// Script para executar o SQL de remoção do módulo Estoque + Lista de Compras

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar permissão (apenas administradores)
if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    die('Acesso negado. Apenas administradores podem executar este script.');
}

require_once __DIR__ . '/conexao.php';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Executar Remoção do Módulo Estoque + Lista de Compras</title>
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
        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .success {
            background: #d1fae5;
            border-left: 4px solid #059669;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .error {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .info {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }
        .sql-item {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .sql-result {
            margin-top: 0.5rem;
            font-family: monospace;
            font-size: 0.875rem;
        }
        button {
            background: #dc2626;
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
            background: #b91c1c;
        }
        button:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin: 1rem 0;
        }
        .stat-box {
            background: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            text-align: center;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #1e3a8a;
        }
        .stat-label {
            color: #6b7280;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗑️ Remover Módulo Estoque + Lista de Compras</h1>
        
        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar'])) {
            try {
                echo '<div class="warning">';
                echo '<h2>⚠️ ATENÇÃO: Executando remoção permanente!</h2>';
                echo '<p>Este processo irá remover todas as tabelas, views, funções e triggers do módulo de Estoque e Lista de Compras.</p>';
                echo '<p><strong>Esta ação não pode ser desfeita!</strong></p>';
                echo '</div>';
                
                // Ler arquivo SQL
                $sql_file = __DIR__ . '/../sql/drop_modulo_estoque_compras.sql';
                
                if (!file_exists($sql_file)) {
                    echo '<div class="error">';
                    echo '<h2>❌ Erro</h2>';
                    echo '<p>Arquivo SQL não encontrado: ' . htmlspecialchars($sql_file) . '</p>';
                    echo '</div>';
                    exit;
                }
                
                $sql_content = file_get_contents($sql_file);
                
                if (empty($sql_content)) {
                    echo '<div class="error">';
                    echo '<h2>❌ Erro</h2>';
                    echo '<p>Arquivo SQL está vazio</p>';
                    echo '</div>';
                    exit;
                }
                
                echo '<div class="sql-item">';
                echo '<h3>📋 Carregando Arquivo SQL</h3>';
                echo '<div class="sql-result">';
                echo '<p><strong>Arquivo:</strong> ' . htmlspecialchars(basename($sql_file)) . '</p>';
                echo '<p><strong>Tamanho:</strong> ' . strlen($sql_content) . ' bytes</p>';
                echo '<p><strong>Linhas:</strong> ' . substr_count($sql_content, "\n") . '</p>';
                echo '</div></div>';
                
                // Executar SQL
                echo '<div class="sql-item">';
                echo '<h3>⚙️ Executando Script SQL</h3>';
                echo '<div class="sql-result">';
                
                // Executar o script completo
                $pdo->beginTransaction();
                
                try {
                    // Dividir em comandos (respeitando blocos DO $$)
                    $commands = [];
                    $current_command = '';
                    $in_block = false;
                    $block_delimiter = '';
                    
                    $lines = explode("\n", $sql_content);
                    foreach ($lines as $line) {
                        $trimmed = trim($line);
                        
                        // Detectar início de bloco DO $$
                        if (preg_match('/^\s*DO\s+\$\$/', $trimmed)) {
                            $in_block = true;
                            $block_delimiter = '$$';
                            $current_command .= $line . "\n";
                            continue;
                        }
                        
                        // Detectar fim de bloco $$
                        if ($in_block && preg_match('/\$\$/', $trimmed)) {
                            $current_command .= $line . "\n";
                            if (!empty(trim($current_command))) {
                                $commands[] = trim($current_command);
                            }
                            $current_command = '';
                            $in_block = false;
                            $block_delimiter = '';
                            continue;
                        }
                        
                        if ($in_block) {
                            $current_command .= $line . "\n";
                            continue;
                        }
                        
                        // Comandos normais
                        if (empty($trimmed) || strpos($trimmed, '--') === 0) {
                            continue; // Ignorar linhas vazias e comentários
                        }
                        
                        $current_command .= $line . "\n";
                        
                        // Se termina com ;, é um comando completo
                        if (substr(rtrim($trimmed), -1) === ';') {
                            if (!empty(trim($current_command))) {
                                $commands[] = trim($current_command);
                            }
                            $current_command = '';
                        }
                    }
                    
                    // Adicionar último comando se houver
                    if (!empty(trim($current_command))) {
                        $commands[] = trim($current_command);
                    }
                    
                    $executed = 0;
                    $errors = 0;
                    
                    foreach ($commands as $idx => $command) {
                        if (empty(trim($command))) {
                            continue;
                        }
                        
                        try {
                            $pdo->exec($command);
                            $executed++;
                            echo '<p style="color: green;">✅ Comando ' . ($idx + 1) . ' executado com sucesso</p>';
                        } catch (PDOException $e) {
                            $errors++;
                            // Ignorar erros de "não existe" (IF EXISTS)
                            if (strpos($e->getMessage(), 'does not exist') !== false || 
                                strpos($e->getMessage(), 'não existe') !== false) {
                                echo '<p style="color: orange;">⚠️ Comando ' . ($idx + 1) . ': ' . htmlspecialchars($e->getMessage()) . ' (ignorado - IF EXISTS)</p>';
                            } else {
                                echo '<p style="color: red;">❌ Comando ' . ($idx + 1) . ': ' . htmlspecialchars($e->getMessage()) . '</p>';
                            }
                        }
                    }
                    
                    $pdo->commit();
                    
                    echo '</div></div>';
                    
                    // Verificação final
                    echo '<div class="sql-item">';
                    echo '<h3>🔍 Verificação Final</h3>';
                    echo '<div class="sql-result">';
                    
                    $tabelas_verificar = [
                        'estoque_contagens', 'estoque_contagem_itens',
                        'lc_movimentos_estoque', 'lc_eventos_baixados', 'lc_ajustes_estoque', 
                        'lc_perdas_devolucoes', 'lc_config_estoque',
                        'lc_listas', 'lc_listas_eventos', 'lc_compras_consolidadas',
                        'lc_encomendas_itens', 'lc_encomendas_overrides', 'lc_config',
                        'lc_fichas', 'lc_ficha_componentes', 'lc_itens', 'lc_itens_fixos',
                        'lc_insumos', 'lc_insumos_substitutos', 'lc_categorias', 'lc_unidades'
                    ];
                    
                    $tabelas_restantes = 0;
                    foreach ($tabelas_verificar as $tabela) {
                        try {
                            $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'smilee12_painel_smile' AND table_name = '$tabela'");
                            $exists = $stmt->fetchColumn() > 0;
                            if ($exists) {
                                $tabelas_restantes++;
                                echo '<p style="color: orange;">⚠️ Tabela ainda existe: ' . $tabela . '</p>';
                            } else {
                                echo '<p style="color: green;">✅ Tabela removida: ' . $tabela . '</p>';
                            }
                        } catch (Exception $e) {
                            echo '<p style="color: red;">❌ Erro ao verificar ' . $tabela . ': ' . htmlspecialchars($e->getMessage()) . '</p>';
                        }
                    }
                    
                    echo '</div></div>';
                    
                    // Estatísticas
                    echo '<div class="stats">';
                    echo '<div class="stat-box">';
                    echo '<div class="stat-number">' . $executed . '</div>';
                    echo '<div class="stat-label">Comandos Executados</div>';
                    echo '</div>';
                    echo '<div class="stat-box">';
                    echo '<div class="stat-number" style="color: ' . ($errors > 0 ? '#dc2626' : '#059669') . '">' . $errors . '</div>';
                    echo '<div class="stat-label">Erros</div>';
                    echo '</div>';
                    echo '<div class="stat-box">';
                    echo '<div class="stat-number" style="color: ' . ($tabelas_restantes > 0 ? '#f59e0b' : '#059669') . '">' . $tabelas_restantes . '</div>';
                    echo '<div class="stat-label">Tabelas Restantes</div>';
                    echo '</div>';
                    echo '</div>';
                    
                    if ($tabelas_restantes === 0 && $errors === 0) {
                        echo '<div class="success">';
                        echo '<h2>✅ SUCESSO!</h2>';
                        echo '<p>Todas as tabelas do módulo foram removidas com sucesso!</p>';
                        echo '</div>';
                    } else {
                        echo '<div class="warning">';
                        echo '<h2>⚠️ ATENÇÃO</h2>';
                        echo '<p>Algumas tabelas ainda existem ou ocorreram erros. Verifique os detalhes acima.</p>';
                        echo '</div>';
                    }
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                
                echo '<div style="margin-top: 2rem;">';
                echo '<a href="index.php?page=administrativo" style="color: #1e3a8a; text-decoration: underline;">← Voltar para Administrativo</a>';
                echo '</div>';
                
            } catch (Exception $e) {
                echo '<div class="error">';
                echo '<h2>❌ Erro durante execução</h2>';
                echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
                echo '</div>';
            }
        } else {
            // Mostrar formulário de confirmação
            ?>
            <div class="warning">
                <h2>⚠️ ATENÇÃO!</h2>
                <p>Este script irá <strong>remover permanentemente</strong> todas as tabelas, views, funções e triggers do módulo de Estoque e Lista de Compras.</p>
                <p><strong>Esta ação não pode ser desfeita!</strong></p>
            </div>
            
            <div class="info">
                <h3>📋 O que será removido:</h3>
                <ul style="margin-left: 1.5rem; margin-top: 0.5rem;">
                    <li>7 tabelas de Estoque</li>
                    <li>6 tabelas de Lista de Compras</li>
                    <li>5 tabelas de Fichas/Insumos</li>
                    <li>2 Views</li>
                    <li>2 Funções</li>
                    <li>1 Trigger</li>
                </ul>
            </div>
            
            <form method="POST">
                <button type="submit" name="confirmar" onclick="return confirm('⚠️ ATENÇÃO: Esta ação é PERMANENTE e não pode ser desfeita!\n\nTem certeza que deseja continuar?')">
                    🗑️ Confirmar e Executar Remoção
                </button>
                <a href="index.php?page=administrativo" style="margin-left: 1rem; color: #1e3a8a; text-decoration: underline;">Cancelar</a>
            </form>
            <?php
        }
        ?>
    </div>
</body>
</html>
