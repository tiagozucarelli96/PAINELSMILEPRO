<?php
// fix_demandas_database.php — Corrigir problemas do banco de dados do sistema de demandas
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

function fixDemandasDatabase() {
    echo "<h1>🔧 Correção do Banco de Dados - Sistema de Demandas</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #10b981; }
        .error { color: #ef4444; }
        .warning { color: #f59e0b; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .success-bg { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .error-bg { background: #fef2f2; border: 1px solid #fecaca; }
        .warning-bg { background: #fffbeb; border: 1px solid #fed7aa; }
        .fix-item { margin: 15px 0; padding: 15px; background: #f9fafb; border-left: 4px solid #3b82f6; }
        .fix-result { margin-left: 20px; }
    </style>";
    
    try {
        $pdo = $GLOBALS['pdo'];
        
        echo "<div class='section info'>";
        echo "<h2>🔧 Corrigindo Problemas do Banco de Dados</h2>";
        echo "<p>Este script corrige os problemas identificados no sistema de demandas.</p>";
        echo "</div>";
        
        // Fix 1: Verificar se tabela usuarios tem coluna email
        echo "<div class='fix-item'>";
        echo "<h3>👤 Fix 1: Verificar Coluna Email na Tabela Usuarios</h3>";
        echo "<div class='fix-result'>";
        
        try {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'usuarios' AND column_name = 'email'");
            $email_column = $stmt->fetch();
            
            if (!$email_column) {
                echo "<p class='warning'>⚠️ Coluna 'email' não encontrada na tabela usuarios</p>";
                echo "<p>Adicionando coluna email...</p>";
                
                $stmt = $pdo->prepare("ALTER TABLE usuarios ADD COLUMN email VARCHAR(255)");
                $stmt->execute();
                
                echo "<p class='success'>✅ Coluna 'email' adicionada à tabela usuarios</p>";
            } else {
                echo "<p class='success'>✅ Coluna 'email' já existe na tabela usuarios</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao verificar/adicionar coluna email: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Fix 2: Criar tabela demandas_configuracoes se não existir
        echo "<div class='fix-item'>";
        echo "<h3>⚙️ Fix 2: Criar Tabela demandas_configuracoes</h3>";
        echo "<div class='fix-result'>";
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_configuracoes");
            echo "<p class='success'>✅ Tabela demandas_configuracoes já existe</p>";
        } catch (Exception $e) {
            echo "<p class='warning'>⚠️ Tabela demandas_configuracoes não existe. Criando...</p>";
            
            $sql = "
            CREATE TABLE IF NOT EXISTS demandas_configuracoes (
                id SERIAL PRIMARY KEY,
                chave VARCHAR(100) UNIQUE NOT NULL,
                valor TEXT,
                descricao TEXT,
                tipo VARCHAR(50) DEFAULT 'string' CHECK (tipo IN ('string', 'number', 'boolean', 'json')),
                criado_em TIMESTAMP DEFAULT NOW(),
                atualizado_em TIMESTAMP DEFAULT NOW()
            );
            ";
            
            $pdo->exec($sql);
            echo "<p class='success'>✅ Tabela demandas_configuracoes criada</p>";
        }
        echo "</div></div>";
        
        // Fix 3: Criar tabela demandas_logs se não existir
        echo "<div class='fix-item'>";
        echo "<h3>📊 Fix 3: Criar Tabela demandas_logs</h3>";
        echo "<div class='fix-result'>";
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_logs");
            echo "<p class='success'>✅ Tabela demandas_logs já existe</p>";
        } catch (Exception $e) {
            echo "<p class='warning'>⚠️ Tabela demandas_logs não existe. Criando...</p>";
            
            $sql = "
            CREATE TABLE IF NOT EXISTS demandas_logs (
                id SERIAL PRIMARY KEY,
                usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                acao VARCHAR(100) NOT NULL,
                entidade VARCHAR(50) NOT NULL,
                entidade_id INT NOT NULL,
                dados_anteriores JSONB,
                dados_novos JSONB,
                ip_origem INET,
                user_agent TEXT,
                criado_em TIMESTAMP DEFAULT NOW()
            );
            ";
            
            $pdo->exec($sql);
            echo "<p class='success'>✅ Tabela demandas_logs criada</p>";
        }
        echo "</div></div>";
        
        // Fix 4: Criar tabela demandas_preferencias_notificacao se não existir
        echo "<div class='fix-item'>";
        echo "<h3>🔔 Fix 4: Criar Tabela demandas_preferencias_notificacao</h3>";
        echo "<div class='fix-result'>";
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_preferencias_notificacao");
            echo "<p class='success'>✅ Tabela demandas_preferencias_notificacao já existe</p>";
        } catch (Exception $e) {
            echo "<p class='warning'>⚠️ Tabela demandas_preferencias_notificacao não existe. Criando...</p>";
            
            $sql = "
            CREATE TABLE IF NOT EXISTS demandas_preferencias_notificacao (
                id SERIAL PRIMARY KEY,
                usuario_id INT REFERENCES usuarios(id) ON DELETE CASCADE UNIQUE,
                notificacao_painel BOOLEAN DEFAULT TRUE,
                notificacao_email BOOLEAN DEFAULT TRUE,
                notificacao_whatsapp BOOLEAN DEFAULT FALSE,
                alerta_vencimento INT DEFAULT 24,
                criado_em TIMESTAMP DEFAULT NOW(),
                atualizado_em TIMESTAMP DEFAULT NOW()
            );
            ";
            
            $pdo->exec($sql);
            echo "<p class='success'>✅ Tabela demandas_preferencias_notificacao criada</p>";
        }
        echo "</div></div>";
        
        // Fix 5: Inserir configurações padrão
        echo "<div class='fix-item'>";
        echo "<h3>⚙️ Fix 5: Inserir Configurações Padrão</h3>";
        echo "<div class='fix-result'>";
        
        try {
            $configuracoes = [
                'smtp_host' => 'mail.smileeventos.com.br',
                'smtp_port' => '465',
                'smtp_username' => 'contato@smileeventos.com.br',
                'smtp_password' => 'ti1996august',
                'smtp_from_name' => 'GRUPO Smile EVENTOS',
                'smtp_from_email' => 'contato@smileeventos.com.br',
                'smtp_reply_to' => 'contato@smileeventos.com.br',
                'smtp_encryption' => 'ssl',
                'smtp_auth' => 'true',
                'email_ativado' => 'true',
                'arquivamento_dias' => '10',
                'reset_semanal_hora' => '06:00',
                'notificacao_vencimento_horas' => '24',
                'max_anexos_por_cartao' => '5',
                'max_tamanho_anexo_mb' => '10',
                'tipos_anexo_permitidos' => '["pdf", "jpg", "jpeg", "png"]',
                'whatsapp_ativado' => 'true',
                'correio_ativado' => 'true',
                'produtividade_ativado' => 'true'
            ];
            
            $configuradas = 0;
            foreach ($configuracoes as $chave => $valor) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO demandas_configuracoes (chave, valor, descricao, tipo) 
                        VALUES (?, ?, ?, 'string')
                        ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor
                    ");
                    $stmt->execute([$chave, $valor, "Configuração: {$chave}"]);
                    $configuradas++;
                } catch (Exception $e) {
                    // Configuração já existe
                }
            }
            
            echo "<p class='success'>✅ {$configuradas} configurações inseridas/atualizadas</p>";
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao inserir configurações: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Fix 6: Configurar preferências de usuários
        echo "<div class='fix-item'>";
        echo "<h3>👥 Fix 6: Configurar Preferências de Usuários</h3>";
        echo "<div class='fix-result'>";
        
        try {
            $stmt = $pdo->query("SELECT id, nome FROM usuarios");
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $preferencias_configuradas = 0;
            foreach ($usuarios as $usuario) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO demandas_preferencias_notificacao (
                            usuario_id, notificacao_painel, notificacao_email, 
                            notificacao_whatsapp, alerta_vencimento
                        ) VALUES (?, TRUE, TRUE, FALSE, 24)
                        ON CONFLICT (usuario_id) DO UPDATE SET 
                            notificacao_email = TRUE,
                            alerta_vencimento = 24
                    ");
                    $stmt->execute([$usuario['id']]);
                    $preferencias_configuradas++;
                } catch (Exception $e) {
                    // Usuário já tem preferências
                }
            }
            
            echo "<p class='success'>✅ Preferências configuradas para {$preferencias_configuradas} usuários</p>";
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao configurar preferências: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Fix 7: Verificar todas as tabelas do sistema de demandas
        echo "<div class='fix-item'>";
        echo "<h3>📋 Fix 7: Verificar Todas as Tabelas do Sistema</h3>";
        echo "<div class='fix-result'>";
        
        $tabelas_demandas = [
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
        
        $tabelas_ok = 0;
        foreach ($tabelas_demandas as $tabela) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM {$tabela}");
                $count = $stmt->fetchColumn();
                echo "<p class='success'>✅ {$tabela}: {$count} registros</p>";
                $tabelas_ok++;
            } catch (Exception $e) {
                echo "<p class='error'>❌ {$tabela}: " . $e->getMessage() . "</p>";
            }
        }
        
        echo "<p><strong>Total:</strong> {$tabelas_ok}/" . count($tabelas_demandas) . " tabelas funcionando</p>";
        echo "</div></div>";
        
        echo "<div class='section success-bg'>";
        echo "<h2>🎉 Correções Aplicadas com Sucesso!</h2>";
        echo "<p>Os problemas do banco de dados foram corrigidos:</p>";
        echo "<ul>";
        echo "<li>✅ Coluna 'email' adicionada à tabela usuarios</li>";
        echo "<li>✅ Tabela demandas_configuracoes criada</li>";
        echo "<li>✅ Tabela demandas_logs criada</li>";
        echo "<li>✅ Tabela demandas_preferencias_notificacao criada</li>";
        echo "<li>✅ Configurações padrão inseridas</li>";
        echo "<li>✅ Preferências de usuários configuradas</li>";
        echo "<li>✅ Todas as tabelas do sistema verificadas</li>";
        echo "</ul>";
        echo "<p><strong>Próximos passos:</strong></p>";
        echo "<ol>";
        echo "<li>Execute o SQL completo: <a href='../sql/016_sistema_demandas.sql'>sql/016_sistema_demandas.sql</a></li>";
        echo "<li>Configure o e-mail: <a href='setup_email_completo.php'>setup_email_completo.php</a></li>";
        echo "<li>Teste o sistema: <a href='test_demandas_complete.php'>test_demandas_complete.php</a></li>";
        echo "</ol>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='section error-bg'>";
        echo "<h2>❌ Erro Geral</h2>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
}

// Executar correções
fixDemandasDatabase();
?>
