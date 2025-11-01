<?php
/**
 * Script para criar a tabela asaas_webhook_events se não existir
 * Esta tabela é essencial para idempotência dos webhooks
 */

require_once __DIR__ . '/conexao.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Criar Tabela Webhook Asaas</title>
    <style>
        body {
            font-family: system-ui, -apple-system, sans-serif;
            max-width: 800px;
            margin: 40px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #333; margin-top: 0; }
        .success { color: #28a745; background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .info { color: #004085; background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 10px 0; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔧 Criar Tabela Webhook Asaas</h1>
        
        <?php
        try {
            // Verificar se tabela já existe
            $stmt = $pdo->query("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = 'asaas_webhook_events'
                );
            ");
            $exists = $stmt->fetchColumn();
            
            if ($exists) {
                echo '<div class="info">✅ A tabela <code>asaas_webhook_events</code> já existe no banco de dados.</div>';
                
                // Verificar estrutura
                $stmt = $pdo->query("
                    SELECT column_name, data_type 
                    FROM information_schema.columns 
                    WHERE table_name = 'asaas_webhook_events'
                    ORDER BY ordinal_position;
                ");
                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo '<h3>📋 Estrutura da Tabela:</h3>';
                echo '<ul>';
                foreach ($columns as $col) {
                    echo '<li><code>' . htmlspecialchars($col['column_name']) . '</code> - ' . htmlspecialchars($col['data_type']) . '</li>';
                }
                echo '</ul>';
                
                // Verificar quantos eventos já foram processados
                $stmt = $pdo->query("SELECT COUNT(*) FROM asaas_webhook_events;");
                $count = $stmt->fetchColumn();
                echo '<div class="info">📊 Total de eventos processados: <strong>' . $count . '</strong></div>';
                
            } else {
                echo '<div class="info">📝 A tabela não existe. Criando...</div>';
                
                // Criar tabela
                $sql = "
                CREATE TABLE IF NOT EXISTS asaas_webhook_events (
                    id SERIAL PRIMARY KEY,
                    asaas_event_id TEXT UNIQUE NOT NULL,
                    event_type VARCHAR(100) NOT NULL,
                    payload JSONB NOT NULL,
                    processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    created_at TIMESTAMP NOT NULL DEFAULT NOW()
                );
                ";
                
                $pdo->exec($sql);
                echo '<div class="success">✅ Tabela <code>asaas_webhook_events</code> criada com sucesso!</div>';
                
                // Criar índices
                $indexes = [
                    "CREATE INDEX IF NOT EXISTS idx_asaas_webhook_events_event_id ON asaas_webhook_events(asaas_event_id);",
                    "CREATE INDEX IF NOT EXISTS idx_asaas_webhook_events_event_type ON asaas_webhook_events(event_type);",
                    "CREATE INDEX IF NOT EXISTS idx_asaas_webhook_events_processed_at ON asaas_webhook_events(processed_at);"
                ];
                
                foreach ($indexes as $index_sql) {
                    try {
                        $pdo->exec($index_sql);
                    } catch (PDOException $e) {
                        echo '<div class="error">⚠️ Erro ao criar índice: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    }
                }
                
                echo '<div class="success">✅ Índices criados com sucesso!</div>';
                
                // Adicionar comentários (se suportado)
                try {
                    $pdo->exec("COMMENT ON TABLE asaas_webhook_events IS 'Armazena eventos de webhook do Asaas para garantir idempotência (processar apenas uma vez)';");
                } catch (PDOException $e) {
                    // Comentários podem não ser suportados, ignorar erro
                }
            }
            
        } catch (PDOException $e) {
            echo '<div class="error">❌ Erro: ' . htmlspecialchars($e->getMessage()) . '</div>';
            echo '<div class="info">💡 Verifique as permissões do usuário do banco de dados.</div>';
        }
        ?>
        
        <hr>
        <h3>📚 Informações:</h3>
        <ul>
            <li>Esta tabela é usada para garantir <strong>idempotência</strong> dos webhooks</li>
            <li>Evita processar o mesmo evento mais de uma vez</li>
            <li>Armazena o payload completo para auditoria</li>
        </ul>
        
        <p><a href="index.php">← Voltar ao sistema</a></p>
    </div>
</body>
</html>
