<?php
// test_tokens_portal.php
// Teste completo do sistema de tokens e portal

session_start();
require_once __DIR__ . '/conexao.php';

echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><title>Teste Tokens e Portal</title>";
echo "<style>body{font-family: sans-serif; margin: 20px; background-color: #f4f7f6; color: #333;}";
echo "h1, h2 {color: #2c3e50;} .success {color: green;} .error {color: red;} .warning {color: orange;}";
echo "pre {background-color: #eee; padding: 10px; border-radius: 5px;}</style></head><body>";
echo "<h1>🧪 Teste do Sistema de Tokens e Portal</h1>";

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

echo "<h2>2. 🧪 Teste de Criação de Fornecedor</h2>";
try {
    // Criar fornecedor de teste
    $stmt = $pdo->prepare("
        INSERT INTO fornecedores 
        (nome, cnpj, telefone, email, contato_responsavel, categoria, pix_tipo, pix_chave, ativo)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, true)
        ON CONFLICT (nome) DO NOTHING
    ");
    
    $stmt->execute([
        'Fornecedor Teste Portal',
        '12345678000195',
        '(11) 99999-9999',
        'teste@fornecedor.com',
        'João Silva',
        'Alimentos',
        'cpf',
        '12345678901'
    ]);
    
    echo "<p class='success'>✅ Fornecedor de teste criado/verificado</p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao criar fornecedor: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>3. 🔑 Teste de Geração de Token</h2>";
try {
    // Buscar fornecedor criado
    $stmt = $pdo->query("SELECT id, nome FROM fornecedores WHERE nome = 'Fornecedor Teste Portal' LIMIT 1");
    $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fornecedor) {
        // Gerar token
        $stmt = $pdo->query("SELECT lc_gerar_token_publico()");
        $token = $stmt->fetchColumn();
        
        // Atualizar fornecedor com token
        $stmt = $pdo->prepare("UPDATE fornecedores SET token_publico = ? WHERE id = ?");
        $stmt->execute([$token, $fornecedor['id']]);
        
        echo "<p class='success'>✅ Token gerado com sucesso</p>";
        echo "<p><strong>Token:</strong> <code>" . htmlspecialchars($token) . "</code></p>";
        echo "<p><strong>Link do Portal:</strong> <code>fornecedor_link.php?t=" . htmlspecialchars($token) . "</code></p>";
        
        // Testar busca por token
        $stmt = $pdo->prepare("SELECT nome FROM fornecedores WHERE token_publico = ?");
        $stmt->execute([$token]);
        $nome_fornecedor = $stmt->fetchColumn();
        
        if ($nome_fornecedor) {
            echo "<p class='success'>✅ Token válido - Fornecedor: " . htmlspecialchars($nome_fornecedor) . "</p>";
        } else {
            echo "<p class='error'>❌ Token não encontrado</p>";
        }
        
    } else {
        echo "<p class='warning'>⚠️ Fornecedor de teste não encontrado</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro no teste de token: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>4. 🧪 Teste de Solicitação via Portal</h2>";
try {
    // Buscar fornecedor com token
    $stmt = $pdo->query("SELECT id, token_publico FROM fornecedores WHERE token_publico IS NOT NULL LIMIT 1");
    $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fornecedor) {
        // Simular criação de solicitação via portal
        $stmt = $pdo->prepare("
            INSERT INTO lc_solicitacoes_pagamento 
            (beneficiario_tipo, fornecedor_id, valor, observacoes, pix_tipo, pix_chave, 
             status, origem, ip_origem, user_agent)
            VALUES ('fornecedor', ?, 150.75, 'Teste via portal', 'cpf', '12345678901', 
                    'aguardando', 'fornecedor_link', '127.0.0.1', 'Test Browser')
        ");
        
        $stmt->execute([$fornecedor['id']]);
        $solicitacao_id = $pdo->lastInsertId();
        
        // Criar evento na timeline
        $stmt = $pdo->prepare("
            INSERT INTO lc_timeline_pagamentos (solicitacao_id, tipo_evento, mensagem)
            VALUES (?, 'criacao', 'Solicitação criada via portal do fornecedor')
        ");
        $stmt->execute([$solicitacao_id]);
        
        echo "<p class='success'>✅ Solicitação via portal criada (ID: {$solicitacao_id})</p>";
        
        // Verificar se foi criada corretamente
        $stmt = $pdo->prepare("
            SELECT s.id, s.valor, s.status, s.origem, f.nome as fornecedor_nome
            FROM lc_solicitacoes_pagamento s
            JOIN fornecedores f ON f.id = s.fornecedor_id
            WHERE s.id = ?
        ");
        $stmt->execute([$solicitacao_id]);
        $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($solicitacao) {
            echo "<p><strong>Detalhes da solicitação:</strong></p>";
            echo "<ul>";
            echo "<li>ID: " . $solicitacao['id'] . "</li>";
            echo "<li>Valor: R$ " . number_format($solicitacao['valor'], 2, ',', '.') . "</li>";
            echo "<li>Status: " . $solicitacao['status'] . "</li>";
            echo "<li>Origem: " . $solicitacao['origem'] . "</li>";
            echo "<li>Fornecedor: " . htmlspecialchars($solicitacao['fornecedor_nome']) . "</li>";
            echo "</ul>";
        }
        
    } else {
        echo "<p class='warning'>⚠️ Nenhum fornecedor com token encontrado</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro no teste de solicitação: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>5. 📊 Estatísticas</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true");
    $total_fornecedores = $stmt->fetchColumn();
    echo "<p>Total de fornecedores ativos: <strong>{$total_fornecedores}</strong></p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE token_publico IS NOT NULL");
    $fornecedores_com_token = $stmt->fetchColumn();
    echo "<p>Fornecedores com token: <strong>{$fornecedores_com_token}</strong></p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_solicitacoes_pagamento WHERE origem = 'fornecedor_link'");
    $solicitacoes_portal = $stmt->fetchColumn();
    echo "<p>Solicitações via portal: <strong>{$solicitacoes_portal}</strong></p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_solicitacoes_pagamento");
    $total_solicitacoes = $stmt->fetchColumn();
    echo "<p>Total de solicitações: <strong>{$total_solicitacoes}</strong></p>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao buscar estatísticas: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>6. 🔗 Links de Teste</h2>";
try {
    // Buscar fornecedor com token para gerar link
    $stmt = $pdo->query("SELECT id, nome, token_publico FROM fornecedores WHERE token_publico IS NOT NULL LIMIT 1");
    $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fornecedor) {
        $portal_url = "fornecedor_link.php?t=" . $fornecedor['token_publico'];
        echo "<p><a href='{$portal_url}' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>🌐 Portal do Fornecedor</a></p>";
        echo "<p><strong>Fornecedor:</strong> " . htmlspecialchars($fornecedor['nome']) . "</p>";
        echo "<p><strong>Token:</strong> <code>" . htmlspecialchars($fornecedor['token_publico']) . "</code></p>";
    } else {
        echo "<p class='warning'>⚠️ Nenhum fornecedor com token encontrado para gerar link</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao gerar link: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<p><a href='fornecedores.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>🏢 Gerenciar Fornecedores</a></p>";
echo "<p><a href='pagamentos_painel.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>📊 Painel Financeiro</a></p>";
echo "<p><a href='lc_index.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>🏠 Voltar para lc_index.php</a></p>";

echo "</body></html>";
?>
