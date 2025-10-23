<?php
// check_env.php — Verificar variáveis de ambiente
require_once __DIR__ . '/config_env.php';

function checkEnv() {
    $required_vars = [
        'DATABASE_URL' => 'Banco de Dados PostgreSQL',
        'APP_URL' => 'URL da Aplicação',
        'ASAAS_API_KEY' => 'Chave da API ASAAS',
        'WEBHOOK_URL' => 'URL do Webhook ASAAS'
    ];
    
    $optional_vars = [
        'SMTP_HOST' => 'Host SMTP',
        'SMTP_USERNAME' => 'Usuário SMTP',
        'SMTP_PASSWORD' => 'Senha SMTP',
        'ME_BASE_URL' => 'URL ME Eventos',
        'ME_API_KEY' => 'Chave ME Eventos'
    ];
    
    echo "<h1>🔧 Verificação de Variáveis de Ambiente</h1>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: #10b981; }
        .warning { color: #f59e0b; }
        .error { color: #ef4444; }
        .section { margin: 20px 0; padding: 15px; border-radius: 8px; }
        .required { background: #fef2f2; border: 1px solid #fecaca; }
        .optional { background: #fffbeb; border: 1px solid #fed7aa; }
    </style>";
    
    echo "<div class='section required'>";
    echo "<h2>🔴 Variáveis Obrigatórias</h2>";
    
    $all_required_ok = true;
    foreach ($required_vars as $var => $description) {
        $value = getenv($var);
        if ($value) {
            echo "<p class='success'>✅ $description ($var): <strong>Configurado</strong></p>";
        } else {
            echo "<p class='error'>❌ $description ($var): <strong>NÃO CONFIGURADO</strong></p>";
            $all_required_ok = false;
        }
    }
    
    if ($all_required_ok) {
        echo "<p class='success'><strong>🎉 Todas as variáveis obrigatórias estão configuradas!</strong></p>";
    } else {
        echo "<p class='error'><strong>⚠️ Configure as variáveis obrigatórias na Railway</strong></p>";
    }
    
    echo "</div>";
    
    echo "<div class='section optional'>";
    echo "<h2>🟡 Variáveis Opcionais</h2>";
    
    foreach ($optional_vars as $var => $description) {
        $value = getenv($var);
        if ($value) {
            echo "<p class='success'>✅ $description ($var): <strong>Configurado</strong></p>";
        } else {
            echo "<p class='warning'>⚠️ $description ($var): <strong>Não configurado</strong></p>";
        }
    }
    
    echo "</div>";
    
    // Teste de conexão com banco
    echo "<div class='section'>";
    echo "<h2>🗄️ Teste de Conexão com Banco</h2>";
    
    try {
        $dbUrl = getenv('DATABASE_URL');
        if ($dbUrl) {
            $pdo = new PDO($dbUrl);
            echo "<p class='success'>✅ Conexão com banco: <strong>OK</strong></p>";
            
            // Testar tabelas
            $tables = ['usuarios', 'comercial_degustacoes', 'comercial_inscricoes'];
            foreach ($tables as $table) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                $count = $stmt->fetchColumn();
                echo "<p class='success'>✅ Tabela $table: <strong>$count registros</strong></p>";
            }
        } else {
            echo "<p class='error'>❌ DATABASE_URL não configurado</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro na conexão: " . $e->getMessage() . "</p>";
    }
    
    echo "</div>";
    
    // Teste ASAAS
    echo "<div class='section'>";
    echo "<h2>💳 Teste ASAAS</h2>";
    
    try {
        if (getenv('ASAAS_API_KEY')) {
            require_once __DIR__ . '/asaas_helper.php';
            $asaas = new AsaasHelper();
            echo "<p class='success'>✅ Helper ASAAS: <strong>Carregado</strong></p>";
            echo "<p class='success'>✅ API Key: <strong>Configurada</strong></p>";
            echo "<p class='success'>✅ Webhook URL: <strong>" . getenv('WEBHOOK_URL') . "</strong></p>";
        } else {
            echo "<p class='error'>❌ ASAAS_API_KEY não configurado</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ Erro ASAAS: " . $e->getMessage() . "</p>";
    }
    
    echo "</div>";
    
    // Teste SMTP
    echo "<div class='section'>";
    echo "<h2>📧 Teste SMTP</h2>";
    
    if (getenv('SMTP_HOST') && getenv('SMTP_USERNAME') && getenv('SMTP_PASSWORD')) {
        echo "<p class='success'>✅ Configurações SMTP: <strong>Configuradas</strong></p>";
        echo "<p class='success'>✅ Host: <strong>" . getenv('SMTP_HOST') . "</strong></p>";
        echo "<p class='success'>✅ Porta: <strong>" . getenv('SMTP_PORT') . "</strong></p>";
        echo "<p class='success'>✅ From: <strong>" . getenv('SMTP_FROM_EMAIL') . "</strong></p>";
    } else {
        echo "<p class='warning'>⚠️ Configurações SMTP não completas</p>";
    }
    
    echo "</div>";
    
    // Resumo
    echo "<div class='section'>";
    echo "<h2>📊 Resumo</h2>";
    
    $total_required = count($required_vars);
    $configured_required = 0;
    
    foreach ($required_vars as $var => $description) {
        if (getenv($var)) {
            $configured_required++;
        }
    }
    
    $percentage = ($configured_required / $total_required) * 100;
    
    echo "<p><strong>Variáveis obrigatórias:</strong> $configured_required/$total_required ($percentage%)</p>";
    
    if ($percentage == 100) {
        echo "<p class='success'><strong>🎉 Sistema pronto para produção!</strong></p>";
    } elseif ($percentage >= 75) {
        echo "<p class='warning'><strong>⚠️ Sistema quase pronto, configure as variáveis restantes</strong></p>";
    } else {
        echo "<p class='error'><strong>❌ Configure mais variáveis para o sistema funcionar</strong></p>";
    }
    
    echo "</div>";
}

// Executar verificação
checkEnv();
?>
