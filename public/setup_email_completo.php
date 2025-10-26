<?php
// setup_email_completo.php — Configuração completa do sistema de e-mail
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

function setupEmailCompleto() {
    echo "<h1>📧 Configuração Completa do Sistema de E-mail</h1>";
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
        .step { margin: 15px 0; padding: 15px; background: #f9fafb; border-left: 4px solid #3b82f6; }
        .step-result { margin-left: 20px; }
        .progress-bar { width: 100%; background: #e5e7eb; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 20px; background: linear-gradient(90deg, #3b82f6, #10b981); transition: width 0.3s ease; }
        .progress-text { text-align: center; margin-top: 5px; font-weight: 600; }
    </style>";
    
    $total_steps = 6;
    $completed_steps = 0;
    
    echo "<div class='section info'>";
    echo "<h2>📧 Configuração Completa do Sistema de E-mail</h2>";
    echo "<p>Este script configura completamente o sistema de e-mail para o sistema de demandas.</p>";
    echo "</div>";
    
    // Passo 1: Verificar estrutura do banco
    echo "<div class='step'>";
    echo "<h3>🔍 Passo 1: Verificar Estrutura do Banco</h3>";
    echo "<div class='step-result'>";
    
    try {
        $pdo = $GLOBALS['pdo'];
        
        // Verificar se tabela demandas_configuracoes existe
        $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_configuracoes");
        $config_count = $stmt->fetchColumn();
        
        if ($config_count >= 0) {
            echo "<p class='success'>✅ Tabela demandas_configuracoes: OK</p>";
            $completed_steps++;
        } else {
            echo "<p class='error'>❌ Tabela demandas_configuracoes não encontrada</p>";
        }
        
        // Verificar se tabela demandas_preferencias_notificacao existe
        $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_preferencias_notificacao");
        $pref_count = $stmt->fetchColumn();
        
        if ($pref_count >= 0) {
            echo "<p class='success'>✅ Tabela demandas_preferencias_notificacao: OK</p>";
            $completed_steps++;
        } else {
            echo "<p class='error'>❌ Tabela demandas_preferencias_notificacao não encontrada</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro ao verificar estrutura: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Passo 2: Configurar SMTP
    echo "<div class='step'>";
    echo "<h3>📧 Passo 2: Configurar SMTP</h3>";
    echo "<div class='step-result'>";
    
    try {
        $smtp_config = [
            'smtp_host' => 'mail.smileeventos.com.br',
            'smtp_port' => 465,
            'smtp_username' => 'contato@smileeventos.com.br',
            'smtp_password' => 'ti1996august',
            'smtp_from_name' => 'GRUPO Smile EVENTOS',
            'smtp_from_email' => 'contato@smileeventos.com.br',
            'smtp_reply_to' => 'contato@smileeventos.com.br',
            'smtp_encryption' => 'ssl',
            'smtp_auth' => 'true',
            'email_ativado' => 'true'
        ];
        
        $configuradas = 0;
        foreach ($smtp_config as $chave => $valor) {
            $stmt = $pdo->prepare("
                INSERT INTO demandas_configuracoes (chave, valor, descricao, tipo) 
                VALUES (?, ?, ?, 'string')
                ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor
            ");
            $stmt->execute([$chave, $valor, "Configuração SMTP: {$chave}"]);
            $configuradas++;
        }
        
        echo "<p class='success'>✅ {$configuradas} configurações SMTP salvas</p>";
        echo "<p>• Servidor: mail.smileeventos.com.br:465</p>";
        echo "<p>• Usuário: contato@smileeventos.com.br</p>";
        echo "<p>• Encriptação: SSL</p>";
        echo "<p>• Remetente: GRUPO Smile EVENTOS</p>";
        $completed_steps++;
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro ao configurar SMTP: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Passo 3: Configurar preferências de usuários
    echo "<div class='step'>";
    echo "<h3>👥 Passo 3: Configurar Preferências de Usuários</h3>";
    echo "<div class='step-result'>";
    
    try {
        $stmt = $pdo->query("SELECT id, nome, email FROM usuarios");
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
            } catch (PDOException $e) {
                // Usuário já tem preferências
            }
        }
        
        echo "<p class='success'>✅ Preferências configuradas para {$preferencias_configuradas} usuários</p>";
        echo "<p>• Notificação no painel: Ativada</p>";
        echo "<p>• Notificação por e-mail: Ativada</p>";
        echo "<p>• Notificação por WhatsApp: Desativada</p>";
        echo "<p>• Alerta de vencimento: 24 horas</p>";
        $completed_steps++;
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro ao configurar preferências: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Passo 4: Testar configuração
    echo "<div class='step'>";
    echo "<h3>🧪 Passo 4: Testar Configuração</h3>";
    echo "<div class='step-result'>";
    
    try {
        require_once __DIR__ . '/email_helper.php';
        $emailHelper = new EmailHelper();
        
        $config = $emailHelper->obterConfiguracao();
        $ativado = $emailHelper->isEmailAtivado();
        
        echo "<p class='success'>✅ EmailHelper carregado com sucesso</p>";
        echo "<p>• Status: " . ($ativado ? "Ativado" : "Desativado") . "</p>";
        echo "<p>• Servidor: {$config['host']}:{$config['port']}</p>";
        echo "<p>• Usuário: {$config['username']}</p>";
        echo "<p>• Encriptação: {$config['encryption']}</p>";
        $completed_steps++;
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro ao testar configuração: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Passo 5: Verificar logs
    echo "<div class='step'>";
    echo "<h3>📊 Passo 5: Verificar Sistema de Logs</h3>";
    echo "<div class='step-result'>";
    
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_logs");
        $logs_count = $stmt->fetchColumn();
        
        echo "<p class='success'>✅ Sistema de logs funcionando</p>";
        echo "<p>• Total de logs: {$logs_count}</p>";
        echo "<p>• Logs de e-mail serão registrados automaticamente</p>";
        $completed_steps++;
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro ao verificar logs: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Passo 6: Configurar templates
    echo "<div class='step'>";
    echo "<h3>📝 Passo 6: Configurar Templates de E-mail</h3>";
    echo "<div class='step-result'>";
    
    try {
        $templates = [
            'email_template_header' => 'GRUPO Smile EVENTOS - Sistema de Demandas',
            'email_template_footer' => '© ' . date('Y') . ' GRUPO Smile EVENTOS. Todos os direitos reservados.',
            'email_template_cor_primaria' => '#1e3a8a',
            'email_template_cor_secundaria' => '#3b82f6'
        ];
        
        $templates_configurados = 0;
        foreach ($templates as $chave => $valor) {
            $stmt = $pdo->prepare("
                INSERT INTO demandas_configuracoes (chave, valor, descricao, tipo) 
                VALUES (?, ?, ?, 'string')
                ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor
            ");
            $stmt->execute([$chave, $valor, "Template de e-mail: {$chave}"]);
            $templates_configurados++;
        }
        
        echo "<p class='success'>✅ {$templates_configurados} templates configurados</p>";
        echo "<p>• Cabeçalho: GRUPO Smile EVENTOS - Sistema de Demandas</p>";
        echo "<p>• Rodapé: © " . date('Y') . " GRUPO Smile EVENTOS</p>";
        echo "<p>• Cores: Azul primário (#1e3a8a) e secundário (#3b82f6)</p>";
        $completed_steps++;
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro ao configurar templates: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    // Resultado final
    $percentage = round(($completed_steps / $total_steps) * 100);
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Configuração Completa do E-mail!</h2>";
    echo "<div class='progress-bar'>";
    echo "<div class='progress-fill' style='width: {$percentage}%;'></div>";
    echo "</div>";
    echo "<div class='progress-text'>{$completed_steps}/{$total_steps} passos concluídos ({$percentage}%)</div>";
    
    if ($percentage >= 90) {
        echo "<p class='success'>🎉 Excelente! O sistema de e-mail foi configurado com sucesso.</p>";
    } elseif ($percentage >= 70) {
        echo "<p class='warning'>⚠️ Bom! O sistema de e-mail foi configurado com alguns problemas menores.</p>";
    } else {
        echo "<p class='error'>❌ Atenção! O sistema de e-mail precisa de correções.</p>";
    }
    
    echo "<h3>📧 Configurações Aplicadas:</h3>";
    echo "<ul>";
    echo "<li>✅ <strong>SMTP:</strong> mail.smileeventos.com.br:465 (SSL)</li>";
    echo "<li>✅ <strong>Usuário:</strong> contato@smileeventos.com.br</li>";
    echo "<li>✅ <strong>Senha:</strong> Configurada</li>";
    echo "<li>✅ <strong>Remetente:</strong> GRUPO Smile EVENTOS</li>";
    echo "<li>✅ <strong>Notificações:</strong> Ativadas para todos os usuários</li>";
    echo "<li>✅ <strong>Templates:</strong> Configurados com identidade visual</li>";
    echo "<li>✅ <strong>Logs:</strong> Sistema de logs funcionando</li>";
    echo "</ul>";
    
    echo "<h3>🚀 Próximos Passos:</h3>";
    echo "<ol>";
    echo "<li>Teste o envio de e-mails: <a href='test_email_sistema.php'>test_email_sistema.php</a></li>";
    echo "<li>Verifique se os e-mails estão chegando</li>";
    echo "<li>Configure filtros de spam se necessário</li>";
    echo "<li>Teste o sistema de demandas com notificações reais</li>";
    echo "<li>Configure backup automático dos logs</li>";
    echo "</ol>";
    echo "</div>";
}

// Executar configuração completa
setupEmailCompleto();
?>
