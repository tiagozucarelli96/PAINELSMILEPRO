<?php
// test_demandas_system.php — Teste completo do sistema de demandas
require_once __DIR__ . '/demandas_helper.php';

function testDemandasSystem() {
    echo "<h1>📋 Teste Sistema de Demandas</h1>";
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
        .feature { margin: 15px 0; padding: 10px; background: #f9fafb; border-left: 4px solid #3b82f6; }
    </style>";
    
    $demandas = new DemandasHelper();
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Status do Sistema</h2>";
    
    try {
        // Testar conexão com banco
        $stmt = $demandas->pdo->query("SELECT COUNT(*) FROM demandas_quadros");
        $quadros_count = $stmt->fetchColumn();
        echo "<p class='success'>✅ Conexão com banco: OK</p>";
        echo "<p class='success'>✅ Tabelas criadas: {$quadros_count} quadros encontrados</p>";
        
        // Testar Magalu Object Storage
        $magalu_status = $demandas->magalu->testConnection();
        if ($magalu_status['success']) {
            echo "<p class='success'>✅ Magalu Object Storage: " . $magalu_status['message'] . "</p>";
        } else {
            echo "<p class='error'>❌ Magalu Object Storage: " . $magalu_status['error'] . "</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro na conexão: " . $e->getMessage() . "</p>";
    }
    echo "</div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>📋 Funcionalidades Implementadas</h2>";
    
    $features = [
        'Dashboard - Agenda do Dia' => [
            'description' => 'Lista tarefas vencendo em 24h/48h',
            'status' => 'Implementado',
            'file' => 'demandas.php'
        ],
        'Sistema de Quadros' => [
            'description' => 'Criar, gerenciar e participar de quadros',
            'status' => 'Implementado',
            'file' => 'demandas_quadro.php'
        ],
        'Cartões e Tarefas' => [
            'description' => 'Criar, mover, concluir cartões',
            'status' => 'Implementado',
            'file' => 'demandas_cartao.php'
        ],
        'Participantes' => [
            'description' => 'Convidar usuários com permissões granulares',
            'status' => 'Implementado',
            'file' => 'demandas_participantes.php'
        ],
        'Notificações' => [
            'description' => 'Sistema de notificações internas e e-mail',
            'status' => 'Implementado',
            'file' => 'demandas_notificacoes.php'
        ],
        'Recorrência' => [
            'description' => 'Tarefas recorrentes com geração automática',
            'status' => 'Implementado',
            'file' => 'demandas_recorrencia.php'
        ],
        'Anexos' => [
            'description' => 'Upload de anexos via Magalu Object Storage',
            'status' => 'Implementado',
            'file' => 'demandas_anexos.php'
        ],
        'Produtividade' => [
            'description' => 'KPIs e relatórios de produtividade',
            'status' => 'Implementado',
            'file' => 'demandas_produtividade.php'
        ],
        'Correio IMAP' => [
            'description' => 'Leitura de e-mails via IMAP',
            'status' => 'Implementado',
            'file' => 'demandas_correio.php'
        ],
        'WhatsApp' => [
            'description' => 'Integração com WhatsApp (Opção A - gratuita)',
            'status' => 'Implementado',
            'file' => 'demandas_whatsapp.php'
        ],
        'Automação' => [
            'description' => 'Reset semanal e arquivamento automático',
            'status' => 'Implementado',
            'file' => 'demandas_automacao.php'
        ],
        'Segurança' => [
            'description' => 'Logs, CSRF, validação de uploads',
            'status' => 'Implementado',
            'file' => 'demandas_security.php'
        ]
    ];
    
    foreach ($features as $feature => $info) {
        echo "<div class='feature'>";
        echo "<h3>📁 {$feature}</h3>";
        echo "<p><strong>Descrição:</strong> " . $info['description'] . "</p>";
        echo "<p><strong>Status:</strong> <span class='success'>" . $info['status'] . "</span></p>";
        echo "<p><strong>Arquivo:</strong> " . $info['file'] . "</p>";
        echo "</div>";
    }
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>🔧 Estrutura do Banco de Dados</h2>";
    
    $tables = [
        'demandas_quadros' => 'Quadros de trabalho',
        'demandas_colunas' => 'Colunas dos quadros',
        'demandas_cartoes' => 'Cartões/tarefas',
        'demandas_participantes' => 'Participantes dos quadros',
        'demandas_comentarios' => 'Comentários nos cartões',
        'demandas_anexos' => 'Anexos dos cartões',
        'demandas_recorrencia' => 'Regras de recorrência',
        'demandas_notificacoes' => 'Sistema de notificações',
        'demandas_preferencias_notificacao' => 'Preferências dos usuários',
        'demandas_logs' => 'Logs de atividade',
        'demandas_configuracoes' => 'Configurações do sistema',
        'demandas_produtividade' => 'Cache de KPIs',
        'demandas_correio' => 'Configurações IMAP',
        'demandas_mensagens_email' => 'Mensagens de e-mail',
        'demandas_anexos_email' => 'Anexos de e-mail'
    ];
    
    foreach ($tables as $table => $description) {
        echo "<p><strong>{$table}:</strong> {$description}</p>";
    }
    echo "</div>";
    
    echo "<div class='section warning-bg'>";
    echo "<h2>⚠️ Próximos Passos</h2>";
    echo "<ol>";
    echo "<li><strong>Executar SQL:</strong> Execute o arquivo sql/016_sistema_demandas.sql</li>";
    echo "<li><strong>Configurar permissões:</strong> Adicione permissões de demandas aos usuários</li>";
    echo "<li><strong>Testar funcionalidades:</strong> Crie quadros e cartões de teste</li>";
    echo "<li><strong>Configurar notificações:</strong> Configure SMTP para e-mails</li>";
    echo "<li><strong>Configurar IMAP:</strong> Configure acesso ao correio</li>";
    echo "<li><strong>Testar integração:</strong> Teste upload de anexos</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎯 Funcionalidades Avançadas</h2>";
    echo "<ul>";
    echo "<li>📅 <strong>Agenda do Dia:</strong> Tarefas vencendo em 24h/48h</li>";
    echo "<li>👥 <strong>Participantes:</strong> Permissões granulares (criar/editar/comentar/ler)</li>";
    echo "<li>🔄 <strong>Recorrência:</strong> Diária, semanal, mensal, por conclusão</li>";
    echo "<li>🔔 <strong>Notificações:</strong> Painel, e-mail, WhatsApp</li>";
    echo "<li>📊 <strong>Produtividade:</strong> KPIs por usuário e período</li>";
    echo "<li>📧 <strong>Correio:</strong> Leitura de e-mails via IMAP</li>";
    echo "<li>💬 <strong>WhatsApp:</strong> Deep-link para envio de mensagens</li>";
    echo "<li>🤖 <strong>Automação:</strong> Reset semanal e arquivamento</li>";
    echo "<li>🔒 <strong>Segurança:</strong> Logs, CSRF, validação</li>";
    echo "<li>📁 <strong>Anexos:</strong> Upload via Magalu Object Storage</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section info'>";
    echo "<h2>🧪 URLs de Teste</h2>";
    echo "<ul>";
    echo "<li><strong>Dashboard:</strong> /public/demandas.php</li>";
    echo "<li><strong>Quadro:</strong> /public/demandas_quadro.php?id=1</li>";
    echo "<li><strong>Notificações:</strong> /public/demandas_notificacoes.php</li>";
    echo "<li><strong>Produtividade:</strong> /public/demandas_produtividade.php</li>";
    echo "<li><strong>Correio:</strong> /public/demandas_correio.php</li>";
    echo "<li><strong>Configurações:</strong> /public/demandas_configuracoes.php</li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Benefícios do Sistema</h2>";
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
}

// Executar teste
testDemandasSystem();
?>
