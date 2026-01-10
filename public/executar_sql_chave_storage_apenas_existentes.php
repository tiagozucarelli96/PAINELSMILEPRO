<?php
// Executar SQL apenas nas tabelas que existem
require_once __DIR__ . '/conexao.php';

echo "🔧 Adicionando coluna chave_storage nas tabelas existentes...\n\n";

$tabelas_contabilidade = [
    'contabilidade_guias',
    'contabilidade_holerites',
    'contabilidade_honorarios',
    'contabilidade_conversas_mensagens',
    'contabilidade_colaboradores_documentos'
];

$sucesso = 0;
$erros = 0;

foreach ($tabelas_contabilidade as $tabela) {
    try {
        // Verificar se tabela existe
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$tabela'");
        $existe = $stmt->fetchColumn() > 0;
        
        if (!$existe) {
            echo "⚠️  $tabela - Tabela não existe, pulando...\n";
            continue;
        }
        
        // Verificar se coluna já existe
        $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '$tabela' AND column_name = 'chave_storage'");
        $tem_coluna = $stmt->fetchColumn() !== false;
        
        if ($tem_coluna) {
            echo "✅ $tabela - Coluna chave_storage já existe\n";
            continue;
        }
        
        // Adicionar coluna
        $sql = "ALTER TABLE $tabela ADD COLUMN chave_storage VARCHAR(500)";
        $pdo->exec($sql);
        echo "✅ $tabela - Coluna chave_storage adicionada com sucesso\n";
        $sucesso++;
        
        // Criar índice
        try {
            $index_name = "idx_{$tabela}_chave_storage";
            $sql_index = "CREATE INDEX IF NOT EXISTS $index_name ON $tabela(chave_storage)";
            $pdo->exec($sql_index);
            echo "   → Índice criado: $index_name\n";
        } catch (Exception $e) {
            // Índice pode já existir, ignorar
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "   ⚠️  Erro ao criar índice: " . $e->getMessage() . "\n";
            }
        }
        
    } catch (PDOException $e) {
        // Ignorar erro se coluna já existe
        if (strpos($e->getMessage(), 'already exists') !== false || 
            strpos($e->getMessage(), 'duplicate') !== false) {
            echo "✅ $tabela - Coluna chave_storage já existe (ignorado)\n";
        } else {
            echo "❌ $tabela - Erro: " . $e->getMessage() . "\n";
            $erros++;
        }
    }
    echo "\n";
}

echo "\n📊 Resumo:\n";
echo "   ✅ Sucesso: $sucesso\n";
echo "   ❌ Erros: $erros\n";
echo "\n✅ Processo concluído!\n";
