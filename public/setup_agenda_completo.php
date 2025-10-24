<?php
// setup_agenda_completo.php — Configuração completa do sistema de agenda
require_once __DIR__ . '/conexao.php';

function setupAgendaCompleto() {
    echo "<h1>🚀 Configuração Completa do Sistema de Agenda</h1>";
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
        .btn-warning { background: #f59e0b; }
        .btn-warning:hover { background: #d97706; }
    </style>";
    
    $total_steps = 6;
    $completed_steps = 0;
    
    echo "<div class='section info'>";
    echo "<h2>🚀 Configuração Completa do Sistema de Agenda</h2>";
    echo "<p>Este script executa todos os passos necessários para configurar o sistema de agenda interna.</p>";
    echo "<p><strong>Total de passos:</strong> {$total_steps}</p>";
    echo "</div>";
    
    // Passo 1: Executar SQL
    echo "<div class='step'>";
    echo "<h3>🗄️ Passo 1: Executar SQL do Sistema</h3>";
    echo "<div class='step-result'>";
    echo "<p>Executando o arquivo SQL completo do sistema de agenda...</p>";
    echo "<a href='setup_agenda.php' class='btn' target='_blank'>🗄️ Executar SQL</a>";
    echo "<p class='info'>📋 Este passo cria todas as tabelas, funções e configurações</p>";
    echo "</div></div>";
    
    // Passo 2: Verificar instalação
    echo "<div class='step'>";
    echo "<h3>🔍 Passo 2: Verificar Instalação</h3>";
    echo "<div class='step-result'>";
    echo "<p>Verificando se todas as tabelas e funções foram criadas corretamente...</p>";
    echo "<a href='test_agenda.php' class='btn' target='_blank'>🔍 Testar Sistema</a>";
    echo "<p class='info'>🧪 Verifica se todas as funcionalidades estão funcionando</p>";
    echo "</div></div>";
    
    // Passo 3: Configurar permissões
    echo "<div class='step'>";
    echo "<h3>🔐 Passo 3: Configurar Permissões</h3>";
    echo "<div class='step-result'>";
    echo "<p>Configurando permissões por perfil de usuário...</p>";
    echo "<a href='usuarios.php' class='btn' target='_blank'>🔐 Gerenciar Usuários</a>";
    echo "<p class='info'>👥 Configure permissões para ADM, GERENTE, OPER, CONSULTA</p>";
    echo "</div></div>";
    
    // Passo 4: Testar funcionalidades
    echo "<div class='step'>";
    echo "<h3>🧪 Passo 4: Testar Funcionalidades</h3>";
    echo "<div class='step-result'>";
    echo "<p>Testando todas as funcionalidades do sistema...</p>";
    echo "<a href='test_agenda.php' class='btn' target='_blank'>🧪 Executar Testes</a>";
    echo "<p class='info'>🔍 Verifica se todas as funcionalidades estão funcionando</p>";
    echo "</div></div>";
    
    // Passo 5: Configurar e-mail
    echo "<div class='step'>";
    echo "<h3>📧 Passo 5: Configurar Sistema de E-mail</h3>";
    echo "<div class='step-result'>";
    echo "<p>Configurando o sistema de e-mail para lembretes...</p>";
    echo "<a href='setup_email_completo.php' class='btn' target='_blank'>📧 Configurar E-mail</a>";
    echo "<p class='info'>📧 Configura SMTP: mail.smileeventos.com.br:465</p>";
    echo "</div></div>";
    
    // Passo 6: Acessar sistema
    echo "<div class='step'>";
    echo "<h3>🎯 Passo 6: Acessar Sistema</h3>";
    echo "<div class='step-result'>";
    echo "<p>Acessando o sistema de agenda...</p>";
    echo "<a href='index.php?page=agenda' class='btn btn-success' target='_blank'>🎯 Acessar Agenda</a>";
    echo "<p class='info'>📋 Acessa o calendário principal do sistema</p>";
    echo "</div></div>";
    
    // Resultado final
    echo "<div class='section success-bg'>";
    echo "<h2>🎉 Sistema de Agenda Pronto!</h2>";
    echo "<p>O sistema de agenda interna foi configurado com todas as funcionalidades:</p>";
    echo "<ul>";
    echo "<li>📅 <strong>Agenda Interna:</strong> Calendário com eventos e visitas</li>";
    echo "<li>🏢 <strong>Espaços:</strong> Garden, Diverkids, Cristal, Lisbon</li>";
    echo "<li>👤 <strong>Visitas:</strong> Agendamento de clientes</li>";
    echo "<li>🔒 <strong>Bloqueios:</strong> Bloqueio de horários pessoais</li>";
    echo "<li>🔔 <strong>Lembretes:</strong> Notificações por e-mail</li>";
    echo "<li>📱 <strong>Sincronização:</strong> Exportação ICS para calendários externos</li>";
    echo "<li>📊 <strong>Relatórios:</strong> Conversão de visitas em contratos</li>";
    echo "<li>🔐 <strong>Permissões:</strong> Controle granular por perfil</li>";
    echo "<li>⚡ <strong>Conflitos:</strong> Detecção automática de sobreposições</li>";
    echo "<li>🎨 <strong>Cores:</strong> Identificação visual por usuário</li>";
    echo "</ul>";
    
    echo "<h3>🚀 Links Úteis:</h3>";
    echo "<div style='display: flex; flex-wrap: wrap; gap: 10px;'>";
    echo "<a href='index.php?page=agenda' class='btn btn-success'>📅 Agenda</a>";
    echo "<a href='index.php?page=agenda_config' class='btn'>⚙️ Configurações</a>";
    echo "<a href='index.php?page=agenda_relatorios' class='btn'>📊 Relatórios</a>";
    echo "<a href='test_agenda.php' class='btn'>🧪 Testes</a>";
    echo "<a href='setup_agenda.php' class='btn'>🗄️ SQL</a>";
    echo "</div>";
    
    echo "<h3>📚 Documentação:</h3>";
    echo "<ul>";
    echo "<li>📅 <strong>Sistema:</strong> <a href='index.php?page=agenda'>index.php?page=agenda</a></li>";
    echo "<li>🔧 <strong>Helper:</strong> <a href='agenda_helper.php'>agenda_helper.php</a></li>";
    echo "<li>🗄️ <strong>SQL:</strong> <a href='../sql/017_agenda_interna.sql'>sql/017_agenda_interna.sql</a></li>";
    echo "<li>📧 <strong>E-mail:</strong> <a href='email_helper.php'>email_helper.php</a></li>";
    echo "<li>📱 <strong>ICS:</strong> <a href='agenda_ics.php'>agenda_ics.php</a></li>";
    echo "</ul>";
    echo "</div>";
    
    echo "<div class='section warning-bg'>";
    echo "<h2>⚠️ Importante!</h2>";
    echo "<p>Execute os passos na ordem indicada para evitar problemas:</p>";
    echo "<ol>";
    echo "<li><strong>Passo 1:</strong> Executar SQL completo (obrigatório)</li>";
    echo "<li><strong>Passo 2:</strong> Verificar instalação (obrigatório)</li>";
    echo "<li><strong>Passo 3:</strong> Configurar permissões (obrigatório)</li>";
    echo "<li><strong>Passo 4:</strong> Testar funcionalidades (recomendado)</li>";
    echo "<li><strong>Passo 5:</strong> Configurar e-mail (recomendado)</li>";
    echo "<li><strong>Passo 6:</strong> Acessar sistema (final)</li>";
    echo "</ol>";
    echo "<p class='warning'>⚠️ Não pule os passos obrigatórios!</p>";
    echo "</div>";
}

// Executar configuração completa
setupAgendaCompleto();
?>
