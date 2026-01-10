<?php
// Verificar quais tabelas da contabilidade existem
require_once __DIR__ . '/conexao.php';

echo "Verificando tabelas da contabilidade...\n\n";

$tabelas_contabilidade = [
    'contabilidade_guias',
    'contabilidade_holerites',
    'contabilidade_honorarios',
    'contabilidade_conversas_mensagens',
    'contabilidade_colaboradores_documentos'
];

foreach ($tabelas_contabilidade as $tabela) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_name = '$tabela'");
        $existe = $stmt->fetchColumn() > 0;
        
        if ($existe) {
            echo "✅ $tabela - EXISTE\n";
            
            // Verificar se coluna chave_storage já existe
            try {
                $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = '$tabela' AND column_name = 'chave_storage'");
                $tem_coluna = $stmt->fetchColumn() !== false;
                
                if ($tem_coluna) {
                    echo "   → Coluna chave_storage já existe\n";
                } else {
                    echo "   → Coluna chave_storage NÃO existe (será adicionada)\n";
                }
            } catch (Exception $e) {
                echo "   → Erro ao verificar coluna: " . $e->getMessage() . "\n";
            }
        } else {
            echo "❌ $tabela - NÃO EXISTE\n";
        }
    } catch (Exception $e) {
        echo "❌ Erro ao verificar $tabela: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
