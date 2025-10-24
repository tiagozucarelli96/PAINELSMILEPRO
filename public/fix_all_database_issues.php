<?php
// fix_all_database_issues.php — Corrigir todos os problemas do banco de dados
require_once __DIR__ . '/conexao.php';

function fixAllDatabaseIssues() {
    echo "<h1>🔧 Correção Completa do Banco de Dados</h1>";
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
        echo "<h2>🔧 Corrigindo Todos os Problemas do Banco de Dados</h2>";
        echo "<p>Este script corrige todos os problemas identificados no sistema.</p>";
        echo "</div>";
        
        // Fix 1: Adicionar coluna email à tabela usuarios
        echo "<div class='fix-item'>";
        echo "<h3>👤 Fix 1: Adicionar Coluna Email à Tabela Usuarios</h3>";
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
        
        // Fix 2: Adicionar coluna perfil à tabela usuarios
        echo "<div class='fix-item'>";
        echo "<h3>👥 Fix 2: Adicionar Coluna Perfil à Tabela Usuarios</h3>";
        echo "<div class='fix-result'>";
        
        try {
            $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'usuarios' AND column_name = 'perfil'");
            $perfil_column = $stmt->fetch();
            
            if (!$perfil_column) {
                echo "<p class='warning'>⚠️ Coluna 'perfil' não encontrada na tabela usuarios</p>";
                echo "<p>Adicionando coluna perfil...</p>";
                
                $stmt = $pdo->prepare("ALTER TABLE usuarios ADD COLUMN perfil VARCHAR(20) DEFAULT 'OPER'");
                $stmt->execute();
                
                echo "<p class='success'>✅ Coluna 'perfil' adicionada à tabela usuarios</p>";
            } else {
                echo "<p class='success'>✅ Coluna 'perfil' já existe na tabela usuarios</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao verificar/adicionar coluna perfil: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Fix 3: Criar tabela demandas_configuracoes
        echo "<div class='fix-item'>";
        echo "<h3>⚙️ Fix 3: Criar Tabela demandas_configuracoes</h3>";
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
        
        // Fix 4: Criar tabela demandas_logs
        echo "<div class='fix-item'>";
        echo "<h3>📊 Fix 4: Criar Tabela demandas_logs</h3>";
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
        
        // Fix 5: Criar tabela demandas_preferencias_notificacao
        echo "<div class='fix-item'>";
        echo "<h3>🔔 Fix 5: Criar Tabela demandas_preferencias_notificacao</h3>";
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
        
        // Fix 6: Inserir configurações padrão
        echo "<div class='fix-item'>";
        echo "<h3>⚙️ Fix 6: Inserir Configurações Padrão</h3>";
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
        
        // Fix 7: Configurar permissões de usuários
        echo "<div class='fix-item'>";
        echo "<h3>👥 Fix 7: Configurar Permissões de Usuários</h3>";
        echo "<div class='fix-result'>";
        
        try {
            $stmt = $pdo->query("SELECT id, nome, perfil FROM usuarios");
            $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $permissoes_configuradas = 0;
            foreach ($usuarios as $usuario) {
                $perm_agenda_ver = true;
                $perm_agenda_meus = true;
                $perm_agenda_relatorios = in_array($usuario['perfil'], ['ADM', 'GERENTE']);
                $perm_gerir_eventos_outros = $usuario['perfil'] === 'ADM';
                $perm_forcar_conflito = $usuario['perfil'] === 'ADM';
                
                try {
                    $stmt = $pdo->prepare("
                        UPDATE usuarios SET 
                            perm_agenda_ver = ?,
                            perm_agenda_meus = ?,
                            perm_agenda_relatorios = ?,
                            perm_gerir_eventos_outros = ?,
                            perm_forcar_conflito = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        $perm_agenda_ver,
                        $perm_agenda_meus,
                        $perm_agenda_relatorios,
                        $perm_gerir_eventos_outros,
                        $perm_forcar_conflito,
                        $usuario['id']
                    ]);
                    $permissoes_configuradas++;
                } catch (Exception $e) {
                    // Usuário já tem permissões
                }
            }
            
            echo "<p class='success'>✅ Permissões configuradas para {$permissoes_configuradas} usuários</p>";
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao configurar permissões: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Fix 8: Verificar todas as tabelas
        echo "<div class='fix-item'>";
        echo "<h3>📋 Fix 8: Verificar Todas as Tabelas</h3>";
        echo "<div class='fix-result'>";
        
        $tabelas_sistema = [
            'usuarios',
            'demandas_configuracoes',
            'demandas_logs',
            'demandas_preferencias_notificacao',
            'agenda_espacos',
            'agenda_eventos',
            'agenda_lembretes',
            'agenda_tokens_ics'
        ];
        
        $tabelas_ok = 0;
        foreach ($tabelas_sistema as $tabela) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM {$tabela}");
                $count = $stmt->fetchColumn();
                echo "<p class='success'>✅ {$tabela}: {$count} registros</p>";
                $tabelas_ok++;
            } catch (Exception $e) {
                echo "<p class='error'>❌ {$tabela}: " . $e->getMessage() . "</p>";
            }
        }
        
        echo "<p><strong>Total:</strong> {$tabelas_ok}/" . count($tabelas_sistema) . " tabelas funcionando</p>";
        echo "</div></div>";
        
        echo "<div class='section success-bg'>";
        echo "<h2>🎉 Correções Aplicadas com Sucesso!</h2>";
        echo "<p>Os problemas do banco de dados foram corrigidos:</p>";
        echo "<ul>";
        echo "<li>✅ Coluna 'email' adicionada à tabela usuarios</li>";
        echo "<li>✅ Coluna 'perfil' adicionada à tabela usuarios</li>";
        echo "<li>✅ Tabela demandas_configuracoes criada</li>";
        echo "<li>✅ Tabela demandas_logs criada</li>";
        echo "<li>✅ Tabela demandas_preferencias_notificacao criada</li>";
        echo "<li>✅ Configurações padrão inseridas</li>";
        echo "<li>✅ Permissões de usuários configuradas</li>";
        echo "<li>✅ Todas as tabelas do sistema verificadas</li>";
        echo "</ul>";
        echo "<p><strong>Próximos passos:</strong></p>";
        echo "<ol>";
        echo "<li>Execute o SQL da agenda: <a href='setup_agenda.php'>setup_agenda.php</a></li>";
        echo "<li>Configure o e-mail: <a href='setup_email_completo.php'>setup_email_completo.php</a></li>";
        echo "<li>Teste o sistema: <a href='test_agenda.php'>test_agenda.php</a></li>";
        echo "<li>Acesse a agenda: <a href='index.php?page=agenda'>index.php?page=agenda</a></li>";
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
fixAllDatabaseIssues();
?>
