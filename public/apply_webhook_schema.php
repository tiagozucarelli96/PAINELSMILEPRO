<?php
/**
 * apply_webhook_schema.php
 * Script para aplicar o schema de webhooks ME Eventos
 */
require_once __DIR__ . '/conexao.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplicar Schema Webhooks ME Eventos</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 900px;
            margin: 2rem auto;
            padding: 2rem;
            background: #f3f4f6;
        }
        .container {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1e3a8a;
            margin-bottom: 1.5rem;
        }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .warning { color: #f59e0b; font-weight: bold; }
        pre {
            background: #1f2937;
            color: #f9fafb;
            padding: 1rem;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 0.875rem;
        }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin: 1rem 0;
        }
        .message-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        .message-error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }
        .btn {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            margin-top: 1rem;
        }
        .btn:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📦 Aplicar Schema Webhooks ME Eventos</h1>
        
        <?php
        $pdo = $GLOBALS['pdo'];
        $erros = [];
        $sucessos = [];
        
        // Verificar se a tabela já existe
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
                echo '<div class="message message-success">';
                echo '✅ Tabela <code>me_eventos_webhook</code> já existe!';
                echo '</div>';
                
                // Verificar estrutura
                $stmt = $pdo->query("
                    SELECT column_name, data_type
                    FROM information_schema.columns
                    WHERE table_name = 'me_eventos_webhook'
                    ORDER BY ordinal_position
                ");
                $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<h2>Colunas existentes:</h2>';
                echo '<ul>';
                foreach ($colunas as $col) {
                    echo '<li><code>' . htmlspecialchars($col['column_name']) . '</code> (' . htmlspecialchars($col['data_type']) . ')</li>';
                }
                echo '</ul>';
                
            } else {
                echo '<div class="message message-error">';
                echo '❌ Tabela <code>me_eventos_webhook</code> não existe. Criando...';
                echo '</div>';
                
                // Criar tabela
                try {
                    $sql = "
                    CREATE TABLE IF NOT EXISTS me_eventos_webhook (
                        id SERIAL PRIMARY KEY,
                        evento_id VARCHAR(100) NOT NULL,
                        nome VARCHAR(255) NOT NULL,
                        data_evento DATE,
                        status VARCHAR(50) DEFAULT 'ativo',
                        tipo_evento VARCHAR(100),
                        cliente_nome VARCHAR(255),
                        cliente_email VARCHAR(255),
                        valor DECIMAL(10,2),
                        webhook_tipo VARCHAR(50) NOT NULL,
                        webhook_data JSONB,
                        recebido_em TIMESTAMP DEFAULT NOW(),
                        processado BOOLEAN DEFAULT FALSE,
                        UNIQUE(evento_id, webhook_tipo)
                    );
                    ";
                    
                    $pdo->exec($sql);
                    $sucessos[] = "Tabela me_eventos_webhook criada com sucesso!";
                    
                    // Criar índices
                    try {
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_me_eventos_webhook_evento_id ON me_eventos_webhook(evento_id);");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_me_eventos_webhook_recebido_em ON me_eventos_webhook(recebido_em);");
                        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_me_eventos_webhook_tipo ON me_eventos_webhook(webhook_tipo);");
                        $sucessos[] = "Índices criados com sucesso!";
                    } catch (Exception $e) {
                        $erros[] = "Erro ao criar índices: " . $e->getMessage();
                    }
                    
                } catch (Exception $e) {
                    $erros[] = "Erro ao criar tabela: " . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $erros[] = "Erro ao verificar tabela: " . $e->getMessage();
        }
        
        // Verificar tabela de estatísticas
        try {
            $stmt = $pdo->query("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = 'me_eventos_stats'
                );
            ");
            $stats_existe = $stmt->fetchColumn();
            
            if (!$stats_existe) {
                try {
                    $sql = "
                    CREATE TABLE IF NOT EXISTS me_eventos_stats (
                        id SERIAL PRIMARY KEY,
                        mes_ano VARCHAR(7) NOT NULL,
                        eventos_criados INTEGER DEFAULT 0,
                        eventos_excluidos INTEGER DEFAULT 0,
                        eventos_ativos INTEGER DEFAULT 0,
                        valor_total DECIMAL(12,2) DEFAULT 0.00,
                        contratos_fechados INTEGER DEFAULT 0,
                        leads_total INTEGER DEFAULT 0,
                        leads_negociacao INTEGER DEFAULT 0,
                        vendas_realizadas INTEGER DEFAULT 0,
                        atualizado_em TIMESTAMP DEFAULT NOW(),
                        UNIQUE(mes_ano)
                    );
                    ";
                    
                    $pdo->exec($sql);
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_me_eventos_stats_mes_ano ON me_eventos_stats(mes_ano);");
                    $sucessos[] = "Tabela me_eventos_stats criada com sucesso!";
                } catch (Exception $e) {
                    $erros[] = "Erro ao criar tabela de estatísticas: " . $e->getMessage();
                }
            } else {
                $sucessos[] = "Tabela me_eventos_stats já existe!";
            }
        } catch (Exception $e) {
            $erros[] = "Erro ao verificar tabela de estatísticas: " . $e->getMessage();
        }
        
        // Mostrar resultados
        if (!empty($sucessos)) {
            echo '<div class="message message-success">';
            echo '<h2>✅ Sucessos:</h2><ul>';
            foreach ($sucessos as $sucesso) {
                echo '<li>' . htmlspecialchars($sucesso) . '</li>';
            }
            echo '</ul></div>';
        }
        
        if (!empty($erros)) {
            echo '<div class="message message-error">';
            echo '<h2>❌ Erros:</h2><ul>';
            foreach ($erros as $erro) {
                echo '<li>' . htmlspecialchars($erro) . '</li>';
            }
            echo '</ul></div>';
        }
        
        if (empty($erros)) {
            echo '<div class="message message-success">';
            echo '<h2>🎉 Schema aplicado com sucesso!</h2>';
            echo '<p>Agora os webhooks podem ser recebidos e processados corretamente.</p>';
            echo '<a href="index.php?page=diagnostico_webhook_eventos" class="btn">🔍 Verificar Diagnóstico</a>';
            echo '</div>';
        }
        ?>
    </div>
</body>
</html>

