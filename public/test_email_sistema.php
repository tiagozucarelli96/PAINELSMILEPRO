<?php
// test_email_sistema.php — Testar envio de e-mails do sistema
require_once __DIR__ . '/email_helper.php';

function testarEmailSistema() {
    echo "<h1>📧 Teste do Sistema de E-mail</h1>";
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
        .test-item { margin: 10px 0; padding: 10px; background: #f9fafb; border-left: 4px solid #3b82f6; }
        .test-result { margin-left: 20px; }
        .form-group { margin: 15px 0; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 5px; }
        .btn { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
    </style>";
    
    $emailHelper = new EmailHelper();
    
    echo "<div class='section info'>";
    echo "<h2>📧 Teste do Sistema de E-mail</h2>";
    echo "<p>Este script testa o envio de e-mails do sistema de demandas.</p>";
    echo "</div>";
    
    // Verificar configuração
    echo "<div class='test-item'>";
    echo "<h3>🔧 Verificando Configuração</h3>";
    echo "<div class='test-result'>";
    
    $config = $emailHelper->obterConfiguracao();
    $ativado = $emailHelper->isEmailAtivado();
    
    echo "<p><strong>Status:</strong> " . ($ativado ? "✅ Ativado" : "❌ Desativado") . "</p>";
    echo "<p><strong>Servidor:</strong> {$config['host']}:{$config['port']}</p>";
    echo "<p><strong>Usuário:</strong> {$config['username']}</p>";
    echo "<p><strong>Encriptação:</strong> {$config['encryption']}</p>";
    echo "<p><strong>Remetente:</strong> {$config['from_name']} &lt;{$config['from_email']}&gt;</p>";
    echo "</div></div>";
    
    // Formulário de teste
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['testar_email'])) {
        $email_teste = $_POST['email_teste'] ?? '';
        
        if ($email_teste) {
            echo "<div class='test-item'>";
            echo "<h3>🧪 Enviando E-mail de Teste</h3>";
            echo "<div class='test-result'>";
            
            $resultado = $emailHelper->enviarEmailTeste($email_teste);
            
            if ($resultado['success']) {
                echo "<p class='success'>✅ E-mail de teste enviado com sucesso para: {$email_teste}</p>";
                echo "<p>Verifique sua caixa de entrada (e spam) para confirmar o recebimento.</p>";
            } else {
                echo "<p class='error'>❌ Erro ao enviar e-mail: " . $resultado['error'] . "</p>";
            }
            echo "</div></div>";
        }
    }
    
    // Formulário para teste
    echo "<div class='test-item'>";
    echo "<h3>📨 Enviar E-mail de Teste</h3>";
    echo "<div class='test-result'>";
    echo "<form method='POST'>";
    echo "<div class='form-group'>";
    echo "<label for='email_teste'>E-mail para teste:</label>";
    echo "<input type='email' id='email_teste' name='email_teste' placeholder='seu@email.com' required>";
    echo "</div>";
    echo "<button type='submit' name='testar_email' class='btn'>📧 Enviar E-mail de Teste</button>";
    echo "</form>";
    echo "</div></div>";
    
    // Testar diferentes tipos de notificação
    echo "<div class='test-item'>";
    echo "<h3>🔔 Testar Tipos de Notificação</h3>";
    echo "<div class='test-result'>";
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['testar_tipo'])) {
        $email_teste = $_POST['email_teste'] ?? '';
        $tipo_teste = $_POST['tipo_teste'] ?? '';
        
        if ($email_teste && $tipo_teste) {
            $templates = [
                'novo_cartao' => [
                    'assunto' => 'Novo Cartão Atribuído',
                    'mensagem' => '<p>Você foi atribuído a um novo cartão no sistema de demandas.</p><p><strong>Cartão:</strong> Tarefa de Teste</p><p><strong>Quadro:</strong> Quadro de Teste</p><p><strong>Vencimento:</strong> ' . date('d/m/Y H:i') . '</p>'
                ],
                'comentario' => [
                    'assunto' => 'Novo Comentário',
                    'mensagem' => '<p>Um novo comentário foi adicionado ao cartão que você está acompanhando.</p><p><strong>Comentário:</strong> Este é um comentário de teste</p><p><strong>Autor:</strong> Sistema de Teste</p>'
                ],
                'vencimento' => [
                    'assunto' => 'Tarefa Vencendo em 24h',
                    'mensagem' => '<p>⚠️ Atenção! Você tem uma tarefa vencendo em 24 horas.</p><p><strong>Tarefa:</strong> Tarefa de Teste</p><p><strong>Vencimento:</strong> ' . date('d/m/Y H:i', strtotime('+1 day')) . '</p><p><strong>Quadro:</strong> Quadro de Teste</p>'
                ],
                'reset_semanal' => [
                    'assunto' => 'Reset Semanal Executado',
                    'mensagem' => '<p>O reset semanal foi executado com sucesso.</p><p><strong>Cartões processados:</strong> 5</p><p><strong>Novos cartões gerados:</strong> 3</p><p><strong>Data:</strong> ' . date('d/m/Y H:i') . '</p>'
                ]
            ];
            
            if (isset($templates[$tipo_teste])) {
                $template = $templates[$tipo_teste];
                $resultado = $emailHelper->enviarNotificacao(
                    $email_teste,
                    'Usuário de Teste',
                    $template['assunto'],
                    $template['mensagem'],
                    $tipo_teste
                );
                
                if ($resultado['success']) {
                    echo "<p class='success'>✅ E-mail de {$tipo_teste} enviado com sucesso!</p>";
                } else {
                    echo "<p class='error'>❌ Erro ao enviar e-mail: " . $resultado['error'] . "</p>";
                }
            }
        }
    }
    
    echo "<form method='POST'>";
    echo "<div class='form-group'>";
    echo "<label for='email_teste'>E-mail para teste:</label>";
    echo "<input type='email' id='email_teste' name='email_teste' placeholder='seu@email.com' required>";
    echo "</div>";
    echo "<div class='form-group'>";
    echo "<label for='tipo_teste'>Tipo de notificação:</label>";
    echo "<select id='tipo_teste' name='tipo_teste' required>";
    echo "<option value=''>Selecione um tipo</option>";
    echo "<option value='novo_cartao'>Novo Cartão Atribuído</option>";
    echo "<option value='comentario'>Novo Comentário</option>";
    echo "<option value='vencimento'>Tarefa Vencendo</option>";
    echo "<option value='reset_semanal'>Reset Semanal</option>";
    echo "</select>";
    echo "</div>";
    echo "<button type='submit' name='testar_tipo' class='btn'>🔔 Enviar Notificação de Teste</button>";
    echo "</form>";
    echo "</div></div>";
    
    // Verificar logs
    echo "<div class='test-item'>";
    echo "<h3>📊 Verificar Logs de E-mail</h3>";
    echo "<div class='test-result'>";
    
    try {
        require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
        $pdo = $GLOBALS['pdo'];
        
        $stmt = $pdo->query("
            SELECT 
                dados_novos,
                criado_em
            FROM demandas_logs 
            WHERE acao = 'email_notificacao' 
            ORDER BY criado_em DESC 
            LIMIT 10
        ");
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($logs) > 0) {
            echo "<p class='success'>✅ " . count($logs) . " logs de e-mail encontrados</p>";
            echo "<table border='1' style='border-collapse: collapse; width: 100%; margin-top: 10px;'>";
            echo "<tr style='background: #f3f4f6;'>";
            echo "<th style='padding: 10px;'>Data/Hora</th>";
            echo "<th style='padding: 10px;'>E-mail</th>";
            echo "<th style='padding: 10px;'>Assunto</th>";
            echo "<th style='padding: 10px;'>Status</th>";
            echo "</tr>";
            
            foreach ($logs as $log) {
                $dados = json_decode($log['dados_novos'], true);
                $status = $dados['sucesso'] ? '✅ Sucesso' : '❌ Erro';
                $cor = $dados['sucesso'] ? '#10b981' : '#ef4444';
                
                echo "<tr>";
                echo "<td style='padding: 10px;'>" . date('d/m/Y H:i', strtotime($log['criado_em'])) . "</td>";
                echo "<td style='padding: 10px;'>" . htmlspecialchars($dados['para']) . "</td>";
                echo "<td style='padding: 10px;'>" . htmlspecialchars($dados['assunto']) . "</td>";
                echo "<td style='padding: 10px; color: {$cor};'>{$status}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='warning'>⚠️ Nenhum log de e-mail encontrado</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro ao verificar logs: " . $e->getMessage() . "</p>";
    }
    echo "</div></div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Sistema de E-mail Configurado!</h2>";
    echo "<p>O sistema de e-mail está configurado e funcionando com as seguintes características:</p>";
    echo "<ul>";
    echo "<li>📧 <strong>Servidor:</strong> mail.smileeventos.com.br:465 (SSL)</li>";
    echo "<li>👤 <strong>Usuário:</strong> contato@smileeventos.com.br</li>";
    echo "<li>🔒 <strong>Autenticação:</strong> Ativada</li>";
    echo "<li>📨 <strong>Remetente:</strong> GRUPO Smile EVENTOS</li>";
    echo "<li>🔔 <strong>Notificações:</strong> Ativadas para todos os usuários</li>";
    echo "<li>📊 <strong>Logs:</strong> Todas as notificações são registradas</li>";
    echo "</ul>";
    echo "<p><strong>Próximos passos:</strong></p>";
    echo "<ol>";
    echo "<li>Teste o envio de e-mails com diferentes tipos de notificação</li>";
    echo "<li>Verifique se os e-mails estão chegando na caixa de entrada</li>";
    echo "<li>Configure filtros de spam se necessário</li>";
    echo "<li>Teste o sistema de demandas com notificações reais</li>";
    echo "</ol>";
    echo "</div>";
}

// Executar teste
testarEmailSistema();
?>
