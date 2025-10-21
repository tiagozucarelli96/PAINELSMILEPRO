<?php
// test_pagamentos.php
// Teste do sistema de pagamentos

session_start();
require_once __DIR__ . '/conexao.php';

echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><title>Teste Sistema de Pagamentos</title>";
echo "<style>body{font-family: sans-serif; margin: 20px; background-color: #f4f7f6; color: #333;}";
echo "h1, h2 {color: #2c3e50;} .success {color: green;} .error {color: red;} .warning {color: orange;}";
echo "pre {background-color: #eee; padding: 10px; border-radius: 5px;}</style></head><body>";
echo "<h1>🧪 Teste do Sistema de Pagamentos</h1>";

if (!$pdo) {
    echo "<p class='error'>❌ Erro de conexão com o banco de dados: " . htmlspecialchars($db_error) . "</p>";
    exit;
}

echo "<h2>1. 🔄 Aplicando SQL de Pagamentos</h2>";
try {
    $sql_script_path = __DIR__ . '/../sql/011_sistema_pagamentos.sql';
    if (file_exists($sql_script_path)) {
        $sql_commands = file_get_contents($sql_script_path);
        $pdo->exec($sql_commands);
        echo "<p class='success'>✅ Script de pagamentos executado com sucesso.</p>";
    } else {
        echo "<p class='error'>❌ Arquivo SQL não encontrado: <code>{$sql_script_path}</code></p>";
    }
} catch (PDOException $e) {
    echo "<p class='error'>❌ Erro ao executar script SQL: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<h2>2. 📋 Verificando Tabelas de Pagamentos</h2>";
$tabelas_pagamentos = [
    'lc_freelancers' => 'Freelancers cadastrados',
    'lc_solicitacoes_pagamento' => 'Solicitações de pagamento',
    'lc_timeline_pagamentos' => 'Timeline de eventos'
];

foreach ($tabelas_pagamentos as $tabela => $descricao) {
    try {
        $stmt = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_name = '{$tabela}'");
        if ($stmt->fetchColumn()) {
            echo "<p class='success'>✅ {$tabela} - {$descricao}</p>";
        } else {
            echo "<p class='error'>❌ {$tabela} - {$descricao} (FALTANDO)</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ {$tabela} - {$descricao} (ERRO: {$e->getMessage()})</p>";
    }
}

echo "<h2>3. 🔗 Verificando Colunas de Fornecedores</h2>";
$colunas_fornecedores = [
    'fornecedores.pix_tipo' => 'Tipo PIX do fornecedor',
    'fornecedores.pix_chave' => 'Chave PIX do fornecedor',
    'fornecedores.token_publico' => 'Token público do fornecedor',
    'fornecedores.categoria' => 'Categoria do fornecedor'
];

foreach ($colunas_fornecedores as $coluna => $descricao) {
    try {
        list($tabela, $campo) = explode('.', $coluna);
        $stmt = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = '{$tabela}' AND column_name = '{$campo}'");
        if ($stmt->fetchColumn()) {
            echo "<p class='success'>✅ {$coluna} - {$descricao}</p>";
        } else {
            echo "<p class='warning'>⚠️ {$coluna} - {$descricao} (NÃO ENCONTRADA)</p>";
        }
    } catch (Exception $e) {
        echo "<p class='error'>❌ {$coluna} - {$descricao} (ERRO: {$e->getMessage()})</p>";
    }
}

echo "<h2>4. 📊 Testando Funções</h2>";
try {
    $stmt = $pdo->query("SELECT * FROM lc_buscar_freelancers_ativos() LIMIT 3");
    $freelancers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p class='success'>✅ Função lc_buscar_freelancers_ativos() funcionando</p>";
    if (!empty($freelancers)) {
        echo "<p>Freelancers encontrados: " . count($freelancers) . "</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro na função lc_buscar_freelancers_ativos(): " . htmlspecialchars($e->getMessage()) . "</p>";
}

try {
    $stmt = $pdo->query("SELECT * FROM lc_gerar_token_publico()");
    $token = $stmt->fetchColumn();
    echo "<p class='success'>✅ Função lc_gerar_token_publico() funcionando</p>";
    echo "<p>Token gerado: <code>" . htmlspecialchars($token) . "</code></p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro na função lc_gerar_token_publico(): " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>5. 🧪 Teste de Cadastro de Freelancer</h2>";
try {
    // Testar inserção de freelancer
    $stmt = $pdo->prepare("
        INSERT INTO lc_freelancers (nome_completo, cpf, pix_tipo, pix_chave, ativo)
        VALUES (?, ?, ?, ?, true)
        ON CONFLICT (cpf) DO NOTHING
    ");
    
    $stmt->execute([
        'João Silva Teste',
        '12345678901',
        'cpf',
        '12345678901'
    ]);
    
    echo "<p class='success'>✅ Teste de cadastro de freelancer bem-sucedido</p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro no teste de cadastro: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>6. 🧪 Teste de Solicitação de Pagamento</h2>";
try {
    // Buscar freelancer criado
    $stmt = $pdo->query("SELECT id FROM lc_freelancers WHERE cpf = '12345678901' LIMIT 1");
    $freelancer_id = $stmt->fetchColumn();
    
    if ($freelancer_id) {
        // Testar criação de solicitação
        $stmt = $pdo->prepare("
            INSERT INTO lc_solicitacoes_pagamento 
            (criador_id, beneficiario_tipo, freelancer_id, valor, observacoes, pix_tipo, pix_chave)
            VALUES (?, 'freelancer', ?, 100.50, 'Teste de solicitação', 'cpf', '12345678901')
        ");
        
        $stmt->execute([1, $freelancer_id]); // Assumindo usuário ID 1
        
        $solicitacao_id = $pdo->lastInsertId();
        
        // Criar evento na timeline
        $stmt = $pdo->prepare("
            INSERT INTO lc_timeline_pagamentos (solicitacao_id, autor_id, tipo_evento, mensagem)
            VALUES (?, 1, 'criacao', 'Solicitação criada via teste')
        ");
        $stmt->execute([$solicitacao_id]);
        
        echo "<p class='success'>✅ Teste de solicitação de pagamento bem-sucedido (ID: {$solicitacao_id})</p>";
    } else {
        echo "<p class='warning'>⚠️ Freelancer não encontrado para teste</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro no teste de solicitação: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>7. 📈 Estatísticas</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_freelancers WHERE ativo = true");
    $total_freelancers = $stmt->fetchColumn();
    echo "<p>Total de freelancers ativos: <strong>{$total_freelancers}</strong></p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_solicitacoes_pagamento");
    $total_solicitacoes = $stmt->fetchColumn();
    echo "<p>Total de solicitações: <strong>{$total_solicitacoes}</strong></p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true");
    $total_fornecedores = $stmt->fetchColumn();
    echo "<p>Total de fornecedores ativos: <strong>{$total_fornecedores}</strong></p>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao buscar estatísticas: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>8. 🔗 Links de Teste</h2>";
echo "<p><a href='pagamentos_solicitar.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>💰 Solicitar Pagamento</a></p>";
echo "<p><a href='pagamentos_minhas.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>📋 Minhas Solicitações</a></p>";
echo "<p><a href='pagamentos_painel.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>📊 Painel Financeiro</a></p>";
echo "<p><a href='freelancer_cadastro.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>👨‍💼 Cadastrar Freelancer</a></p>";
echo "<p><a href='lc_index.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>🏠 Voltar para lc_index.php</a></p>";

echo "</body></html>";
?>
