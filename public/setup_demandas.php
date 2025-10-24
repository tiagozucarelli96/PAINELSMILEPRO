<?php
// setup_demandas.php — Script para configurar o sistema de demandas
require_once __DIR__ . '/conexao.php';

function setupDemandasSystem() {
    echo "<h1>🔧 Configuração do Sistema de Demandas</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .success-bg { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .error-bg { background: #fef2f2; border: 1px solid #fecaca; }
    </style>";
    
    try {
        // Ler arquivo SQL
        $sql_file = __DIR__ . '/../sql/016_sistema_demandas.sql';
        
        if (!file_exists($sql_file)) {
            echo "<div class='section error-bg'>";
            echo "<h2>❌ Erro</h2>";
            echo "<p>Arquivo SQL não encontrado: {$sql_file}</p>";
            echo "</div>";
            return;
        }
        
        $sql_content = file_get_contents($sql_file);
        
        if (empty($sql_content)) {
            echo "<div class='section error-bg'>";
            echo "<h2>❌ Erro</h2>";
            echo "<p>Arquivo SQL está vazio</p>";
            echo "</div>";
            return;
        }
        
        echo "<div class='section info'>";
        echo "<h2>📋 Executando SQL do Sistema de Demandas</h2>";
        echo "<p>Arquivo: {$sql_file}</p>";
        echo "<p>Tamanho: " . strlen($sql_content) . " bytes</p>";
        echo "</div>";
        
        // Executar SQL
        $pdo = $GLOBALS['pdo'];
        
        // Dividir em comandos individuais
        $commands = explode(';', $sql_content);
        $executed = 0;
        $errors = 0;
        
        foreach ($commands as $command) {
            $command = trim($command);
            if (empty($command) || strpos($command, '--') === 0) {
                continue;
            }
            
            try {
                $pdo->exec($command);
                $executed++;
            } catch (PDOException $e) {
                $errors++;
                echo "<div class='section error-bg'>";
                echo "<h3>⚠️ Erro no comando SQL</h3>";
                echo "<p><strong>Comando:</strong> " . htmlspecialchars(substr($command, 0, 100)) . "...</p>";
                echo "<p><strong>Erro:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
                echo "</div>";
            }
        }
        
        echo "<div class='section success-bg'>";
        echo "<h2>✅ Resultado da Execução</h2>";
        echo "<p><strong>Comandos executados:</strong> {$executed}</p>";
        echo "<p><strong>Erros encontrados:</strong> {$errors}</p>";
        echo "</div>";
        
        // Verificar tabelas criadas
        echo "<div class='section info'>";
        echo "<h2>🔍 Verificando Tabelas Criadas</h2>";
        
        $tables = [
            'demandas_quadros',
            'demandas_colunas', 
            'demandas_cartoes',
            'demandas_participantes',
            'demandas_comentarios',
            'demandas_anexos',
            'demandas_recorrencia',
            'demandas_notificacoes',
            'demandas_preferencias_notificacao',
            'demandas_logs',
            'demandas_configuracoes',
            'demandas_produtividade',
            'demandas_correio',
            'demandas_mensagens_email',
            'demandas_anexos_email'
        ];
        
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
                $count = $stmt->fetchColumn();
                echo "<p class='success'>✅ {$table}: {$count} registros</p>";
            } catch (PDOException $e) {
                echo "<p class='error'>❌ {$table}: " . $e->getMessage() . "</p>";
            }
        }
        echo "</div>";
        
        // Verificar permissões
        echo "<div class='section info'>";
        echo "<h2>🔐 Verificando Permissões</h2>";
        
        try {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'usuarios' AND column_name LIKE 'perm_demandas%'");
            $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($permissions) > 0) {
                echo "<p class='success'>✅ Permissões de demandas adicionadas:</p>";
                foreach ($permissions as $permission) {
                    echo "<p>• {$permission}</p>";
                }
            } else {
                echo "<p class='warning'>⚠️ Permissões de demandas não encontradas</p>";
            }
        } catch (PDOException $e) {
            echo "<p class='error'>❌ Erro ao verificar permissões: " . $e->getMessage() . "</p>";
        }
        echo "</div>";
        
        // Verificar funções
        echo "<div class='section info'>";
        echo "<h2>⚙️ Verificando Funções</h2>";
        
        $functions = [
            'lc_can_access_demandas',
            'lc_can_create_quadros', 
            'lc_can_view_produtividade',
            'gerar_proximo_cartao_recorrente',
            'executar_reset_semanal',
            'arquivar_cartoes_antigos'
        ];
        
        foreach ($functions as $function) {
            try {
                $stmt = $pdo->query("SELECT routine_name FROM information_schema.routines WHERE routine_name = '{$function}'");
                if ($stmt->fetch()) {
                    echo "<p class='success'>✅ Função {$function}: OK</p>";
                } else {
                    echo "<p class='error'>❌ Função {$function}: Não encontrada</p>";
                }
            } catch (PDOException $e) {
                echo "<p class='error'>❌ Erro ao verificar função {$function}: " . $e->getMessage() . "</p>";
            }
        }
        echo "</div>";
        
        // Configurar usuário admin
        echo "<div class='section info'>";
        echo "<h2>👤 Configurando Usuário Admin</h2>";
        
        try {
            $stmt = $pdo->prepare("
                UPDATE usuarios 
                SET perm_demandas = TRUE, 
                    perm_demandas_criar_quadros = TRUE,
                    perm_demandas_ver_produtividade = TRUE
                WHERE perfil = 'ADM'
            ");
            $stmt->execute();
            $affected = $stmt->rowCount();
            echo "<p class='success'>✅ {$affected} usuários ADM configurados com permissões de demandas</p>";
        } catch (PDOException $e) {
            echo "<p class='error'>❌ Erro ao configurar usuário admin: " . $e->getMessage() . "</p>";
        }
        echo "</div>";
        
        // Inserir configurações padrão
        echo "<div class='section info'>";
        echo "<h2>⚙️ Configurações Padrão</h2>";
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_configuracoes");
            $config_count = $stmt->fetchColumn();
            echo "<p class='success'>✅ {$config_count} configurações inseridas</p>";
        } catch (PDOException $e) {
            echo "<p class='error'>❌ Erro ao verificar configurações: " . $e->getMessage() . "</p>";
        }
        echo "</div>";
        
        echo "<div class='section success-bg'>";
        echo "<h2>🎉 Sistema de Demandas Configurado!</h2>";
        echo "<p>O sistema de demandas foi configurado com sucesso. Agora você pode:</p>";
        echo "<ul>";
        echo "<li>📋 Acessar o sistema em <strong>/public/demandas.php</strong></li>";
        echo "<li>👥 Criar quadros e convidar participantes</li>";
        echo "<li>📅 Gerenciar tarefas e prazos</li>";
        echo "<li>🔔 Configurar notificações</li>";
        echo "<li>📊 Visualizar métricas de produtividade</li>";
        echo "<li>📧 Configurar leitura de e-mails</li>";
        echo "<li>💬 Integrar com WhatsApp</li>";
        echo "<li>🤖 Configurar automações</li>";
        echo "</ul>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='section error-bg'>";
        echo "<h2>❌ Erro Geral</h2>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
}

// Executar configuração
setupDemandasSystem();
?>
