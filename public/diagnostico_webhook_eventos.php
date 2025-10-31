<?php
/**
 * diagnostico_webhook_eventos.php
 * Script para diagnosticar problemas com webhooks ME Eventos
 */
require_once __DIR__ . '/conexao.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnóstico Webhooks ME Eventos</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
            background: #f3f4f6;
        }
        .section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1e3a8a;
            margin-bottom: 2rem;
        }
        h2 {
            color: #374151;
            border-bottom: 2px solid #3b82f6;
            padding-bottom: 0.5rem;
            margin-bottom: 1rem;
        }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th {
            background: #f8fafc;
            font-weight: 600;
            color: #374151;
        }
        tr:hover {
            background: #f9fafb;
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-error { background: #fee2e2; color: #991b1b; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        code {
            background: #f3f4f6;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-family: 'Monaco', 'Courier New', monospace;
            font-size: 0.875rem;
        }
        pre {
            background: #1f2937;
            color: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <h1>🔍 Diagnóstico Webhooks ME Eventos</h1>
    
    <?php
    $pdo = $GLOBALS['pdo'];
    $erros = [];
    $avisos = [];
    
    // 1. Verificar se a tabela existe
    echo '<div class="section">';
    echo '<h2>1. Verificação da Tabela</h2>';
    
    try {
        $stmt = $pdo->query("
            SELECT EXISTS (
                SELECT FROM information_schema.tables 
                WHERE table_schema = 'public' 
                AND table_name = 'me_eventos_webhook'
            );
        ");
        $tabela_existe = $stmt->fetchColumn();
        
        if ($tabela_existe) {
            echo '<p class="success">✅ Tabela <code>me_eventos_webhook</code> existe</p>';
            
            // Verificar estrutura da tabela
            $stmt = $pdo->query("
                SELECT column_name, data_type, is_nullable
                FROM information_schema.columns
                WHERE table_name = 'me_eventos_webhook'
                ORDER BY ordinal_position
            ");
            $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo '<table>';
            echo '<tr><th>Coluna</th><th>Tipo</th><th>Pode ser NULL</th></tr>';
            foreach ($colunas as $col) {
                echo '<tr>';
                echo '<td><code>' . htmlspecialchars($col['column_name']) . '</code></td>';
                echo '<td>' . htmlspecialchars($col['data_type']) . '</td>';
                echo '<td>' . ($col['is_nullable'] === 'YES' ? 'Sim' : 'Não') . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<p class="error">❌ Tabela <code>me_eventos_webhook</code> NÃO existe</p>';
            $erros[] = 'Tabela me_eventos_webhook não existe. Execute o script webhooks_me_eventos.sql';
        }
    } catch (Exception $e) {
        echo '<p class="error">❌ Erro ao verificar tabela: ' . htmlspecialchars($e->getMessage()) . '</p>';
        $erros[] = $e->getMessage();
    }
    echo '</div>';
    
    // 2. Verificar dados na tabela
    if ($tabela_existe) {
        echo '<div class="section">';
        echo '<h2>2. Dados na Tabela</h2>';
        
        try {
            // Total de registros
            $stmt = $pdo->query("SELECT COUNT(*) FROM me_eventos_webhook");
            $total = $stmt->fetchColumn();
            echo '<p><strong>Total de webhooks recebidos:</strong> <span class="' . ($total > 0 ? 'success' : 'warning') . '">' . $total . '</span></p>';
            
            if ($total > 0) {
                // Por tipo
                $stmt = $pdo->query("
                    SELECT webhook_tipo, COUNT(*) as total
                    FROM me_eventos_webhook
                    GROUP BY webhook_tipo
                    ORDER BY total DESC
                ");
                $por_tipo = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<table>';
                echo '<tr><th>Tipo de Webhook</th><th>Quantidade</th></tr>';
                foreach ($por_tipo as $tipo) {
                    echo '<tr>';
                    echo '<td><code>' . htmlspecialchars($tipo['webhook_tipo']) . '</code></td>';
                    echo '<td>' . $tipo['total'] . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
                
                // Eventos do mês atual
                $stmt = $pdo->query("
                    SELECT COUNT(*) 
                    FROM me_eventos_webhook 
                    WHERE webhook_tipo = 'created'
                    AND DATE_TRUNC('month', recebido_em) = DATE_TRUNC('month', CURRENT_DATE)
                ");
                $eventos_mes = $stmt->fetchColumn();
                echo '<p><strong>Eventos criados neste mês:</strong> <span class="' . ($eventos_mes > 0 ? 'success' : 'warning') . '">' . $eventos_mes . '</span></p>';
                
                // Últimos 10 webhooks recebidos
                $stmt = $pdo->query("
                    SELECT evento_id, nome, webhook_tipo, recebido_em, status
                    FROM me_eventos_webhook
                    ORDER BY recebido_em DESC
                    LIMIT 10
                ");
                $ultimos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<h3>Últimos 10 Webhooks Recebidos:</h3>';
                echo '<table>';
                echo '<tr><th>Evento ID</th><th>Nome</th><th>Tipo</th><th>Recebido Em</th><th>Status</th></tr>';
                foreach ($ultimos as $webhook) {
                    $tipo_badge = 'badge-success';
                    if ($webhook['webhook_tipo'] === 'deleted') $tipo_badge = 'badge-error';
                    elseif ($webhook['webhook_tipo'] === 'updated') $tipo_badge = 'badge-warning';
                    
                    echo '<tr>';
                    echo '<td><code>' . htmlspecialchars($webhook['evento_id']) . '</code></td>';
                    echo '<td>' . htmlspecialchars($webhook['nome']) . '</td>';
                    echo '<td><span class="badge ' . $tipo_badge . '">' . htmlspecialchars($webhook['webhook_tipo']) . '</span></td>';
                    echo '<td>' . htmlspecialchars($webhook['recebido_em']) . '</td>';
                    echo '<td>' . htmlspecialchars($webhook['status']) . '</td>';
                    echo '</tr>';
                }
                echo '</table>';
            } else {
                $avisos[] = 'Nenhum webhook foi recebido ainda. Verifique se os webhooks estão configurados corretamente.';
            }
        } catch (Exception $e) {
            echo '<p class="error">❌ Erro ao consultar dados: ' . htmlspecialchars($e->getMessage()) . '</p>';
            $erros[] = $e->getMessage();
        }
        echo '</div>';
    }
    
    // 3. Verificar query da dashboard
    echo '<div class="section">';
    echo '<h2>3. Query da Dashboard</h2>';
    
    try {
        if ($tabela_existe) {
            $query_dashboard = "
                SELECT COUNT(*) as total 
                FROM me_eventos_webhook 
                WHERE webhook_tipo = 'created'
                AND DATE_TRUNC('month', recebido_em) = DATE_TRUNC('month', CURRENT_DATE)
            ";
            
            echo '<p><strong>Query usada na dashboard:</strong></p>';
            echo '<pre>' . htmlspecialchars($query_dashboard) . '</pre>';
            
            $stmt = $pdo->prepare($query_dashboard);
            $stmt->execute();
            $resultado = $stmt->fetchColumn();
            
            echo '<p><strong>Resultado da query:</strong> <span class="' . ($resultado > 0 ? 'success' : 'warning') . '">' . $resultado . '</span></p>';
            
            // Verificar se há webhooks 'created' mas de outros meses
            $stmt = $pdo->query("
                SELECT COUNT(*) 
                FROM me_eventos_webhook 
                WHERE webhook_tipo = 'created'
            ");
            $total_created = $stmt->fetchColumn();
            
            if ($total_created > 0 && $resultado == 0) {
                $avisos[] = "Existem {$total_created} webhooks 'created' no total, mas nenhum do mês atual. Verifique a data do campo recebido_em.";
            }
        }
    } catch (Exception $e) {
        echo '<p class="error">❌ Erro ao executar query: ' . htmlspecialchars($e->getMessage()) . '</p>';
        $erros[] = $e->getMessage();
    }
    echo '</div>';
    
    // 4. Verificar logs
    echo '<div class="section">';
    echo '<h2>4. Logs do Webhook</h2>';
    
    $log_file = __DIR__ . '/logs/webhook_me_eventos.log';
    if (file_exists($log_file)) {
        $log_content = file_get_contents($log_file);
        $log_lines = explode("\n", $log_content);
        $ultimas_linhas = array_slice($log_lines, -20); // Últimas 20 linhas
        
        echo '<p><strong>Últimas 20 linhas do log:</strong></p>';
        echo '<pre style="max-height: 400px; overflow-y: auto;">';
        echo htmlspecialchars(implode("\n", $ultimas_linhas));
        echo '</pre>';
        
        echo '<p><strong>Tamanho do arquivo:</strong> ' . number_format(filesize($log_file) / 1024, 2) . ' KB</p>';
    } else {
        echo '<p class="warning">⚠️ Arquivo de log não encontrado: <code>' . htmlspecialchars($log_file) . '</code></p>';
        $avisos[] = 'Arquivo de log não existe. Isso pode indicar que nenhum webhook foi processado ainda.';
    }
    echo '</div>';
    
    // 5. Verificar endpoint
    echo '<div class="section">';
    echo '<h2>5. Configuração do Endpoint</h2>';
    
    $webhook_file = __DIR__ . '/webhook_me_eventos.php';
    if (file_exists($webhook_file)) {
        echo '<p class="success">✅ Arquivo <code>webhook_me_eventos.php</code> existe</p>';
        
        $webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . 
                       '://' . $_SERVER['HTTP_HOST'] . '/webhook_me_eventos.php';
        echo '<p><strong>URL do Webhook:</strong> <code>' . htmlspecialchars($webhook_url) . '</code></p>';
        
        $file_content = file_get_contents($webhook_file);
        if (strpos($file_content, 'smile-token-2025') !== false) {
            echo '<p class="success">✅ Token de autenticação configurado: <code>smile-token-2025</code></p>';
        } else {
            echo '<p class="error">❌ Token não encontrado no código</p>';
        }
    } else {
        echo '<p class="error">❌ Arquivo <code>webhook_me_eventos.php</code> não encontrado</p>';
        $erros[] = 'Arquivo webhook_me_eventos.php não existe';
    }
    echo '</div>';
    
    // Resumo
    echo '<div class="section">';
    echo '<h2>📊 Resumo</h2>';
    
    if (empty($erros)) {
        echo '<p class="success">✅ Nenhum erro crítico encontrado</p>';
    } else {
        echo '<p class="error">❌ Erros encontrados:</p>';
        echo '<ul>';
        foreach ($erros as $erro) {
            echo '<li class="error">' . htmlspecialchars($erro) . '</li>';
        }
        echo '</ul>';
    }
    
    if (!empty($avisos)) {
        echo '<p class="warning">⚠️ Avisos:</p>';
        echo '<ul>';
        foreach ($avisos as $aviso) {
            echo '<li class="warning">' . htmlspecialchars($aviso) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
    ?>
</body>
</html>

