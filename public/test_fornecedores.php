<?php
// test_fornecedores.php
// Teste de integração de fornecedores

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

echo "<!DOCTYPE html><html lang='pt-BR'><head><meta charset='UTF-8'><title>Teste de Fornecedores</title>";
echo "<style>body{font-family: sans-serif; margin: 20px; background-color: #f4f7f6; color: #333;}";
echo "h1, h2 {color: #2c3e50;} .success {color: green;} .error {color: red;} .warning {color: orange;}";
echo "pre {background-color: #eee; padding: 10px; border-radius: 5px;}</style></head><body>";
echo "<h1>🧪 Teste de Integração de Fornecedores</h1>";

if (!$pdo) {
    echo "<p class='error'>❌ Erro de conexão com o banco de dados: " . htmlspecialchars($db_error) . "</p>";
    exit;
}

echo "<h2>1. 🔄 Aplicando SQL de Fornecedores</h2>";
try {
    $sql_script_path = __DIR__ . '/../sql/010_fornecedores_integracao.sql';
    if (file_exists($sql_script_path)) {
        $sql_commands = file_get_contents($sql_script_path);
        $pdo->exec($sql_commands);
        echo "<p class='success'>✅ Script de fornecedores executado com sucesso.</p>";
    } else {
        echo "<p class='error'>❌ Arquivo SQL não encontrado: <code>{$sql_script_path}</code></p>";
    }
} catch (PDOException $e) {
    echo "<p class='error'>❌ Erro ao executar script SQL: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}

echo "<h2>2. 📋 Verificando Tabelas de Fornecedores</h2>";
$tabelas_fornecedores = [
    'fornecedores' => 'Tabela principal de fornecedores',
    'lc_encomendas_fornecedor' => 'Encomendas por fornecedor'
];

foreach ($tabelas_fornecedores as $tabela => $descricao) {
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

echo "<h2>3. 🔗 Verificando Colunas de Integração</h2>";
$colunas_integracao = [
    'lc_insumos.fornecedor_id' => 'Fornecedor do insumo',
    'lc_listas.fornecedor_id' => 'Fornecedor da lista',
    'lc_movimentos_estoque.fornecedor_id' => 'Fornecedor do movimento'
];

foreach ($colunas_integracao as $coluna => $descricao) {
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
    $stmt = $pdo->query("SELECT * FROM lc_buscar_fornecedores_ativos() LIMIT 3");
    $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<p class='success'>✅ Função lc_buscar_fornecedores_ativos() funcionando</p>";
    if (!empty($fornecedores)) {
        echo "<p>Fornecedores encontrados: " . count($fornecedores) . "</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro na função lc_buscar_fornecedores_ativos(): " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>5. 🧪 Teste de Cadastro</h2>";
try {
    // Testar inserção de fornecedor
    $stmt = $pdo->prepare("
        INSERT INTO fornecedores (nome, telefone, email, contato_responsavel, ativo)
        VALUES (?, ?, ?, ?, true)
        ON CONFLICT (nome) DO NOTHING
    ");
    
    $stmt->execute([
        'Fornecedor Teste ' . date('Y-m-d H:i:s'),
        '(11) 99999-9999',
        'teste@fornecedor.com',
        'João Teste'
    ]);
    
    echo "<p class='success'>✅ Teste de cadastro de fornecedor bem-sucedido</p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro no teste de cadastro: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>6. 📈 Estatísticas</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM fornecedores WHERE ativo = true");
    $total_fornecedores = $stmt->fetchColumn();
    echo "<p>Total de fornecedores ativos: <strong>{$total_fornecedores}</strong></p>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_insumos WHERE fornecedor_id IS NOT NULL");
    $insumos_com_fornecedor = $stmt->fetchColumn();
    echo "<p>Insumos com fornecedor: <strong>{$insumos_com_fornecedor}</strong></p>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Erro ao buscar estatísticas: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>7. 🔗 Links de Teste</h2>";
echo "<p><a href='lc_index.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>🏠 Voltar para lc_index.php</a></p>";
echo "<p><a href='fornecedor_cadastro.php' style='color: #1e40af; text-decoration: none; padding: 10px 20px; background: #e0f2fe; border-radius: 5px;'>➕ Testar Cadastro de Fornecedor</a></p>";

echo "</body></html>";
?>
