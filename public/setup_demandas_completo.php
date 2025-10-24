<?php
// setup_demandas_completo.php — Configuração completa do sistema de demandas
require_once __DIR__ . '/conexao.php';

function setupDemandasCompleto() {
    echo "<h1>🚀 Configuração Completa do Sistema de Demandas</h1>";
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
        .btn { padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin: 5px; }
        .btn:hover { background: #1d4ed8; }
        .btn-success { background: #10b981; }
        .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; }
        .btn-warning:hover { background: #d97706; }
    </style>";
    
    $total_steps = 8;
    $completed_steps = 0;
    
    echo "<div class='section info'>";
    echo "<h2>🚀 Configuração Completa do Sistema de Demandas</h2>";
    echo "<p>Este script executa todos os passos necessários para configurar o sistema de demandas.</p>";
    echo "<p><strong>Total de passos:</strong> {$total_steps}</p>";
    echo "</div>";
    
    // Passo 1: Corrigir problemas do banco
    echo "<div class='step'>";
    echo "<h3>🔧 Passo 1: Corrigir Problemas do Banco</h3>";
    echo "<div class='step-result'>";
    echo "<p>Executando correções básicas do banco de dados...</p>";
    echo "<a href='fix_demandas_database.php' class='btn' target='_blank'>🔧 Executar Correções</a>";
    echo "<p class='warning'>⚠️ Execute este passo primeiro para corrigir problemas básicos</p>";
    echo "</div></div>";
    
    // Passo 2: Executar SQL completo
    echo "<div class='step'>";
    echo "<h3>🗄️ Passo 2: Executar SQL Completo</h3>";
    echo "<div class='step-result'>";
    echo "<p>Executando o arquivo SQL completo do sistema de demandas...</p>";
    echo "<a href='execute_demandas_sql.php' class='btn' target='_blank'>🗄️ Executar SQL</a>";
    echo "<p class='info'>📋 Este passo cria todas as tabelas, funções e configurações</p>";
    echo "</div></div>";
    
    // Passo 3: Configurar e-mail
    echo "<div class='step'>";
    echo "<h3>📧 Passo 3: Configurar Sistema de E-mail</h3>";
    echo "<div class='step-result'>";
    echo "<p>Configurando o sistema de e-mail com as credenciais fornecidas...</p>";
    echo "<a href='setup_email_completo.php' class='btn' target='_blank'>📧 Configurar E-mail</a>";
    echo "<p class='info'>📧 Configura SMTP: mail.smileeventos.com.br:465</p>";
    echo "</div></div>";
    
    // Passo 4: Configurar permissões
    echo "<div class='step'>";
    echo "<h3>🔐 Passo 4: Configurar Permissões</h3>";
    echo "<div class='step-result'>";
    echo "<p>Configurando permissões por perfil de usuário...</p>";
    echo "<a href='config_demandas_permissions.php' class='btn' target='_blank'>🔐 Configurar Permissões</a>";
    echo "<p class='info'>👥 Configura permissões para ADM, GERENTE, OPER, CONSULTA</p>";
    echo "</div></div>";
    
    // Passo 5: Testar sistema
    echo "<div class='step'>";
    echo "<h3>🧪 Passo 5: Testar Sistema</h3>";
    echo "<div class='step-result'>";
    echo "<p>Executando testes completos do sistema...</p>";
    echo "<a href='test_demandas_complete.php' class='btn' target='_blank'>🧪 Testar Sistema</a>";
    echo "<p class='info'>🔍 Verifica se todas as funcionalidades estão funcionando</p>";
    echo "</div></div>";
    
    // Passo 6: Testar e-mail
    echo "<div class='step'>";
    echo "<h3>📧 Passo 6: Testar Sistema de E-mail</h3>";
    echo "<div class='step-result'>";
    echo "<p>Testando o envio de e-mails do sistema...</p>";
    echo "<a href='test_email_sistema.php' class='btn' target='_blank'>📧 Testar E-mail</a>";
    echo "<p class='info'>📨 Testa envio de notificações por e-mail</p>";
    echo "</div></div>";
    
    // Passo 7: Executar suite de testes
    echo "<div class='step'>";
    echo "<h3>🔬 Passo 7: Executar Suite de Testes</h3>";
    echo "<div class='step-result'>";
    echo "<p>Executando todos os testes disponíveis...</p>";
    echo "<a href='run_demandas_tests.php' class='btn' target='_blank'>🔬 Suite de Testes</a>";
    echo "<p class='info'>🧪 Executa todos os testes do sistema</p>";
    echo "</div></div>";
    
    // Passo 8: Acessar sistema
    echo "<div class='step'>";
    echo "<h3>🎯 Passo 8: Acessar Sistema</h3>";
    echo "<div class='step-result'>";
    echo "<p>Acessando o sistema de demandas...</p>";
    echo "<a href='demandas.php' class='btn btn-success' target='_blank'>🎯 Acessar Sistema</a>";
    echo "<p class='info'>📋 Acessa o dashboard principal do sistema</p>";
    echo "</div></div>";
    
    // Resultado final
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Sistema de Demandas Pronto!</h2>";
    echo "<p>O sistema de demandas foi configurado com todas as funcionalidades:</p>";
    echo "<ul>";
    echo "<li>📋 <strong>Dashboard:</strong> Agenda do dia com notificações</li>";
    echo "<li>👥 <strong>Quadros:</strong> Criação e gerenciamento de quadros</li>";
    echo "<li>📝 <strong>Cartões:</strong> Tarefas com prioridades e vencimentos</li>";
    echo "<li>🔔 <strong>Notificações:</strong> E-mail automático para todos os eventos</li>";
    echo "<li>🔄 <strong>Recorrência:</strong> Tarefas recorrentes automáticas</li>";
    echo "<li>📊 <strong>Produtividade:</strong> KPIs e relatórios</li>";
    echo "<li>📧 <strong>E-mail:</strong> SMTP configurado com identidade visual</li>";
    echo "<li>🔐 <strong>Permissões:</strong> Sistema granular por perfil</li>";
    echo "<li>🤖 <strong>Automação:</strong> Reset semanal e arquivamento</li>";
    echo "<li>📁 <strong>Anexos:</strong> Upload via Magalu Object Storage</li>";
    echo "</ul>";
    
    echo "<h3>🚀 Links Úteis:</h3>";
    echo "<div style='display: flex; flex-wrap: wrap; gap: 10px;'>";
    echo "<a href='demandas.php' class='btn btn-success'>📋 Dashboard</a>";
    echo "<a href='test_demandas_complete.php' class='btn'>🧪 Testes</a>";
    echo "<a href='test_email_sistema.php' class='btn'>📧 E-mail</a>";
    echo "<a href='config_demandas_permissions.php' class='btn'>🔐 Permissões</a>";
    echo "<a href='setup_email_completo.php' class='btn'>⚙️ Configurações</a>";
    echo "</div>";
    
    echo "<h3>📚 Documentação:</h3>";
    echo "<ul>";
    echo "<li>📋 <strong>Sistema:</strong> <a href='demandas.php'>demandas.php</a></li>";
    echo "<li>🔧 <strong>Helper:</strong> <a href='demandas_helper.php'>demandas_helper.php</a></li>";
    echo "<li>🗄️ <strong>SQL:</strong> <a href='../sql/016_sistema_demandas.sql'>sql/016_sistema_demandas.sql</a></li>";
    echo "<li>📧 <strong>E-mail:</strong> <a href='email_helper.php'>email_helper.php</a></li>";
    echo "<li>📊 <strong>Resumo:</strong> <a href='../DEMANDAS_SYSTEM_SUMMARY.md'>DEMANDAS_SYSTEM_SUMMARY.md</a></li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section warning-bg'>";
    echo "<h2>⚠️ Importante!</h2>";
    echo "<p>Execute os passos na ordem indicada para evitar problemas:</p>";
    echo "<ol>";
    echo "<li><strong>Passo 1:</strong> Corrigir problemas do banco (obrigatório)</li>";
    echo "<li><strong>Passo 2:</strong> Executar SQL completo (obrigatório)</li>";
    echo "<li><strong>Passo 3:</strong> Configurar e-mail (obrigatório)</li>";
    echo "<li><strong>Passo 4:</strong> Configurar permissões (obrigatório)</li>";
    echo "<li><strong>Passo 5-7:</strong> Testes (recomendado)</li>";
    echo "<li><strong>Passo 8:</strong> Acessar sistema (final)</li>";
    echo "</ol>";
    echo "<p class='warning'>⚠️ Não pule os passos obrigatórios!</p>";
    echo "</div>";
}

// Executar configuração completa
setupDemandasCompleto();
?>
