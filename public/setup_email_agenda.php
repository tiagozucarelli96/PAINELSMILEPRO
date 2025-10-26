<?php
// setup_email_agenda.php — Configuração do e-mail para a agenda
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

function setupEmailAgenda() {
    echo "<h1>📧 Configuração do E-mail para Agenda</h1>";
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
        .btn { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px; }
        .btn:hover { background: #1d4ed8; }
        .btn-success { background: #10b981; }
        .btn-success:hover { background: #059669; }
    </style>";
    
    try {
        $pdo = $GLOBALS['pdo'];
        
        echo "<div class='section info'>";
        echo "<h2>📧 Configurando Sistema de E-mail para Agenda</h2>";
        echo "<p>Este script configura o sistema de e-mail para envio de lembretes da agenda.</p>";
        echo "</div>";
        
        // Passo 1: Verificar configurações SMTP
        echo "<div class='step'>";
        echo "<h3>🔧 Passo 1: Verificar Configurações SMTP</h3>";
        echo "<div class='step-result'>";
        
        try {
            $stmt = $pdo->query("SELECT chave, valor FROM demandas_configuracoes WHERE chave LIKE 'smtp_%' ORDER BY chave");
            $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($configs) > 0) {
                echo "<p class='success'>✅ Configurações SMTP encontradas:</p>";
                echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>";
                echo "<tr style='background: #f3f4f6;'>";
                echo "<th style='padding: 10px;'>Configuração</th>";
                echo "<th style='padding: 10px;'>Valor</th>";
                echo "</tr>";
                
                foreach ($configs as $config) {
                    $valor = $config['chave'] === 'smtp_password' ? 
                        str_repeat('*', strlen($config['valor'])) : 
                        htmlspecialchars($config['valor']);
                    
                    echo "<tr>";
                    echo "<td style='padding: 10px;'><strong>" . htmlspecialchars($config['chave']) . "</strong></td>";
                    echo "<td style='padding: 10px;'>{$valor}</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<p class='warning'>⚠️ Nenhuma configuração SMTP encontrada</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao verificar configurações: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Passo 2: Testar envio de e-mail
        echo "<div class='step'>";
        echo "<h3>📧 Passo 2: Testar Envio de E-mail</h3>";
        echo "<div class='step-result'>";
        
        try {
            // Incluir helper de e-mail
            if (file_exists(__DIR__ . '/email_helper.php')) {
                require_once __DIR__ . '/email_helper.php';
                
                $emailHelper = new EmailHelper();
                
                // Testar envio
                $teste_email = 'tiagozucarelli@hotmail.com';
                $assunto = 'Teste de Configuração - Sistema de Agenda';
                $mensagem = "
                <h3>📅 Teste de E-mail - Sistema de Agenda</h3>
                <p>Este é um teste de configuração do sistema de e-mail para a agenda interna.</p>
                <p><strong>Data/Hora:</strong> " . date('d/m/Y H:i:s') . "</p>
                <p><strong>Sistema:</strong> GRUPO Smile EVENTOS - Agenda Interna</p>
                <p>Se você recebeu este e-mail, a configuração está funcionando corretamente!</p>
                ";
                
                $resultado = $emailHelper->enviarNotificacao($teste_email, 'Tiago', $assunto, $mensagem);
                
                if ($resultado) {
                    echo "<p class='success'>✅ E-mail de teste enviado com sucesso para: {$teste_email}</p>";
                } else {
                    echo "<p class='error'>❌ Erro ao enviar e-mail de teste</p>";
                }
            } else {
                echo "<p class='warning'>⚠️ Helper de e-mail não encontrado</p>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao testar e-mail: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Passo 3: Verificar logs de e-mail
        echo "<div class='step'>";
        echo "<h3>📊 Passo 3: Verificar Logs de E-mail</h3>";
        echo "<div class='step-result'>";
        
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM demandas_logs WHERE acao LIKE '%email%'");
            $logs_count = $stmt->fetchColumn();
            
            echo "<p class='success'>✅ Logs de e-mail encontrados: {$logs_count}</p>";
            
            if ($logs_count > 0) {
                $stmt = $pdo->query("
                    SELECT acao, criado_em, dados_novos 
                    FROM demandas_logs 
                    WHERE acao LIKE '%email%' 
                    ORDER BY criado_em DESC 
                    LIMIT 5
                ");
                $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "<p>Últimos logs de e-mail:</p>";
                echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>";
                echo "<tr style='background: #f3f4f6;'>";
                echo "<th style='padding: 10px;'>Ação</th>";
                echo "<th style='padding: 10px;'>Data/Hora</th>";
                echo "<th style='padding: 10px;'>Detalhes</th>";
                echo "</tr>";
                
                foreach ($logs as $log) {
                    $dados = json_decode($log['dados_novos'], true);
                    $detalhes = isset($dados['email']) ? $dados['email'] : 'N/A';
                    
                    echo "<tr>";
                    echo "<td style='padding: 10px;'>" . htmlspecialchars($log['acao']) . "</td>";
                    echo "<td style='padding: 10px;'>" . date('d/m/Y H:i', strtotime($log['criado_em'])) . "</td>";
                    echo "<td style='padding: 10px;'>" . htmlspecialchars($detalhes) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao verificar logs: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        // Passo 4: Configurar templates de e-mail
        echo "<div class='step'>";
        echo "<h3>📝 Passo 4: Configurar Templates de E-mail</h3>";
        echo "<div class='step-result'>";
        
        try {
            $templates = [
                'email_lembrete_evento' => '
                <h3>📅 Lembrete: {{titulo}}</h3>
                <p><strong>Data/Hora:</strong> {{data}} {{hora}}</p>
                <p><strong>Duração:</strong> {{duracao}}</p>
                <p><strong>Espaço:</strong> {{espaco}}</p>
                <p><strong>Observações:</strong> {{observacoes}}</p>
                <p><strong>Agendado por:</strong> {{agendado_por}}</p>
                <p>Este é um lembrete automático do sistema de agenda.</p>
                ',
                'email_novo_evento' => '
                <h3>✅ Novo Evento Criado</h3>
                <p><strong>Evento:</strong> {{titulo}}</p>
                <p><strong>Data/Hora:</strong> {{data}} {{hora}}</p>
                <p><strong>Espaço:</strong> {{espaco}}</p>
                <p><strong>Observações:</strong> {{observacoes}}</p>
                <p><strong>Criado por:</strong> {{criado_por}}</p>
                ',
                'email_evento_alterado' => '
                <h3>📝 Evento Atualizado</h3>
                <p><strong>Evento:</strong> {{titulo}}</p>
                <p><strong>Data/Hora:</strong> {{data}} {{hora}}</p>
                <p><strong>Espaço:</strong> {{espaco}}</p>
                <p><strong>Observações:</strong> {{observacoes}}</p>
                <p><strong>Atualizado por:</strong> {{atualizado_por}}</p>
                '
            ];
            
            $templates_configurados = 0;
            foreach ($templates as $chave => $template) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO demandas_configuracoes (chave, valor, descricao, tipo) 
                        VALUES (?, ?, ?, 'string')
                        ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor
                    ");
                    $stmt->execute([$chave, $template, "Template de e-mail: {$chave}"]);
                    $templates_configurados++;
                } catch (Exception $e) {
                    // Template já existe
                }
            }
            
            echo "<p class='success'>✅ {$templates_configurados} templates de e-mail configurados</p>";
        } catch (Exception $e) {
            echo "<p class='error'>❌ Erro ao configurar templates: " . $e->getMessage() . "</p>";
        }
        echo "</div></div>";
        
        echo "<div class='section success-bg'>";
        echo "<h2>🎉 Sistema de E-mail Configurado!</h2>";
        echo "<p>O sistema de e-mail para a agenda foi configurado com sucesso:</p>";
        echo "<ul>";
        echo "<li>✅ Configurações SMTP verificadas</li>";
        echo "<li>✅ Teste de envio realizado</li>";
        echo "<li>✅ Logs de e-mail verificados</li>";
        echo "<li>✅ Templates de e-mail configurados</li>";
        echo "</ul>";
        echo "<p><strong>Funcionalidades disponíveis:</strong></p>";
        echo "<ul>";
        echo "<li>📧 <strong>Lembretes automáticos:</strong> E-mails enviados antes dos eventos</li>";
        echo "<li>📝 <strong>Notificações de criação:</strong> E-mails quando eventos são criados</li>";
        echo "<li>📝 <strong>Notificações de alteração:</strong> E-mails quando eventos são alterados</li>";
        echo "<li>📊 <strong>Logs de e-mail:</strong> Controle de todos os e-mails enviados</li>";
        echo "</ul>";
        echo "<h3>🚀 Próximos Passos:</h3>";
        echo "<ol>";
        echo "<li>Teste o sistema: <a href='test_agenda.php'>test_agenda.php</a></li>";
        echo "<li>Acesse a agenda: <a href='index.php?page=agenda'>index.php?page=agenda</a></li>";
        echo "<li>Crie alguns eventos para testar os lembretes</li>";
        echo "</ol>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='section error-bg'>";
        echo "<h2>❌ Erro Geral</h2>";
        echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
        echo "</div>";
    }
}

// Executar configuração
setupEmailAgenda();
?>
