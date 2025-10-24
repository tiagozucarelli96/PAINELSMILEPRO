<?php
// run_demandas_tests.php — Executar todos os testes do sistema de demandas
require_once __DIR__ . '/conexao.php';

function runDemandasTests() {
    echo "<h1>🧪 Executando Todos os Testes - Sistema de Demandas</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #10b981; }
        .warning { color: #f59e0b; }
        .error { color: #ef4444; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .info { background: #f0f9ff; border: 1px solid #bae6fd; }
        .success-bg { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .error-bg { background: #fef2f2; border: 1px solid #fecaca; }
        .warning-bg { background: #fffbeb; border: 1px solid #fed7aa; }
        .test-item { margin: 10px 0; padding: 10px; background: #f9fafb; border-left: 4px solid #3b82f6; }
        .test-result { margin-left: 20px; }
        .progress-bar { width: 100%; background: #e5e7eb; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-fill { height: 20px; background: linear-gradient(90deg, #3b82f6, #10b981); transition: width 0.3s ease; }
        .progress-text { text-align: center; margin-top: 5px; font-weight: 600; }
        .test-link { display: inline-block; margin: 5px; padding: 10px 15px; background: #3b82f6; color: white; text-decoration: none; border-radius: 5px; }
        .test-link:hover { background: #1d4ed8; }
    </style>";
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Suite de Testes do Sistema de Demandas</h2>";
    echo "<p>Este script executa todos os testes disponíveis para o sistema de demandas.</p>";
    echo "<p>Clique nos links abaixo para executar cada teste individualmente:</p>";
    echo "</div>";
    
    $tests = [
        [
            'name' => 'Configuração do Sistema',
            'description' => 'Executa o SQL e configura o sistema',
            'url' => 'setup_demandas.php',
            'icon' => '🔧'
        ],
        [
            'name' => 'Configuração de Permissões',
            'description' => 'Configura permissões por perfil de usuário',
            'url' => 'config_demandas_permissions.php',
            'icon' => '🔐'
        ],
        [
            'name' => 'Teste do Sistema',
            'description' => 'Verifica se o sistema está funcionando',
            'url' => 'test_demandas_system.php',
            'icon' => '📋'
        ],
        [
            'name' => 'Teste de Funcionalidades',
            'description' => 'Testa todas as funcionalidades principais',
            'url' => 'test_demandas_features.php',
            'icon' => '🧪'
        ],
        [
            'name' => 'Teste Completo',
            'description' => 'Executa teste completo com métricas',
            'url' => 'test_demandas_complete.php',
            'icon' => '🎯'
        ]
    ];
    
    echo "<div class='section success-bg'>";
    echo "<h2>📋 Testes Disponíveis</h2>";
    
    foreach ($tests as $test) {
        echo "<div class='test-item'>";
        echo "<h3>{$test['icon']} {$test['name']}</h3>";
        echo "<p>{$test['description']}</p>";
        echo "<a href='{$test['url']}' class='test-link' target='_blank'>Executar Teste</a>";
        echo "</div>";
    }
    echo "</div>";
    
    echo "<div class='section warning-bg'>";
    echo "<h2>⚠️ Ordem Recomendada de Execução</h2>";
    echo "<ol>";
    echo "<li><strong>Configuração do Sistema:</strong> Execute primeiro para criar as tabelas</li>";
    echo "<li><strong>Configuração de Permissões:</strong> Configure as permissões dos usuários</li>";
    echo "<li><strong>Teste do Sistema:</strong> Verifique se tudo está funcionando</li>";
    echo "<li><strong>Teste de Funcionalidades:</strong> Teste cada funcionalidade</li>";
    echo "<li><strong>Teste Completo:</strong> Execute o teste final com métricas</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Configurações Necessárias</h2>";
    echo "<p>Antes de executar os testes, certifique-se de que:</p>";
    echo "<ul>";
    echo "<li>✅ Banco de dados PostgreSQL está funcionando</li>";
    echo "<li>✅ Arquivo sql/016_sistema_demandas.sql existe</li>";
    echo "<li>✅ Usuário tem permissões de administrador</li>";
    echo "<li>✅ Magalu Object Storage está configurado</li>";
    echo "<li>✅ SMTP está configurado para e-mails</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Benefícios do Sistema de Demandas</h2>";
    echo "<ul>";
    echo "<li>📋 <strong>Organização:</strong> Quadros visuais para gerenciar tarefas</li>";
    echo "<li>👥 <strong>Colaboração:</strong> Múltiplos usuários em um quadro</li>";
    echo "<li>⏰ <strong>Controle de tempo:</strong> Vencimentos e prioridades</li>";
    echo "<li>🔄 <strong>Automação:</strong> Tarefas recorrentes e reset semanal</li>";
    echo "<li>📊 <strong>Métricas:</strong> KPIs de produtividade</li>";
    echo "<li>🔔 <strong>Comunicação:</strong> Notificações integradas</li>";
    echo "<li>📧 <strong>E-mail:</strong> Leitura de correio no painel</li>";
    echo "<li>💬 <strong>WhatsApp:</strong> Integração para comunicação</li>";
    echo "<li>🔒 <strong>Segurança:</strong> Logs e validações</li>";
    echo "<li>📁 <strong>Armazenamento:</strong> Anexos via Magalu Object Storage</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>📚 Documentação</h2>";
    echo "<p>Para mais informações sobre o sistema de demandas:</p>";
    echo "<ul>";
    echo "<li>📋 <strong>Dashboard:</strong> /public/demandas.php</li>";
    echo "<li>🔧 <strong>Helper:</strong> /public/demandas_helper.php</li>";
    echo "<li>🗄️ <strong>SQL:</strong> /sql/016_sistema_demandas.sql</li>";
    echo "<li>🧪 <strong>Testes:</strong> /public/test_demandas_*.php</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>🚀 Próximos Passos</h2>";
    echo "<ol>";
    echo "<li>Execute os testes na ordem recomendada</li>";
    echo "<li>Configure as permissões dos usuários</li>";
    echo "<li>Teste o sistema com usuários reais</li>";
    echo "<li>Configure notificações por e-mail</li>";
    echo "<li>Configure integração com WhatsApp</li>";
    echo "<li>Configure leitura de e-mails via IMAP</li>";
    echo "<li>Configure automações agendadas</li>";
    echo "<li>Configure backup automático</li>";
    echo "</ol>";
    echo "</div>";
}

// Executar suite de testes
runDemandasTests();
?>
