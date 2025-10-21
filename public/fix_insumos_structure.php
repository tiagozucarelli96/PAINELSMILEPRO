<?php
// fix_insumos_structure.php - Corrigir estrutura da tabela lc_insumos

require_once 'conexao.php';

$msg = '';
$err = '';

try {
    echo "<h2>Corrigindo Estrutura da Tabela lc_insumos...</h2>";
    
    // Verificar se a tabela existe
    $tableExists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'smilee12_painel_smile' AND table_name = 'lc_insumos'
        )
    ")->fetchColumn();
    
    if (!$tableExists) {
        throw new Exception('Tabela lc_insumos não existe!');
    }
    
    $msg .= '✅ Tabela lc_insumos existe.<br>';
    
    // Verificar se a coluna ativo existe
    $columnExists = $pdo->query("
        SELECT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'smilee12_painel_smile'
            AND table_name = 'lc_insumos'
            AND column_name = 'ativo'
        )
    ")->fetchColumn();
    
    if (!$columnExists) {
        // Adicionar coluna ativo
        $pdo->exec("
            ALTER TABLE smilee12_painel_smile.lc_insumos
            ADD COLUMN ativo BOOLEAN DEFAULT true
        ");
        
        // Atualizar registros existentes
        $pdo->exec("
            UPDATE smilee12_painel_smile.lc_insumos
            SET ativo = true
            WHERE ativo IS NULL
        ");
        
        $msg .= '✅ Coluna ativo adicionada à tabela lc_insumos.<br>';
    } else {
        $msg .= '✅ Coluna ativo já existe na tabela lc_insumos.<br>';
    }
    
    // Verificar quantos insumos existem
    $count = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos")->fetchColumn();
    $msg .= "✅ Total de insumos: $count<br>";
    
    // Testar consulta de insumos
    $insumos = $pdo->query("
        SELECT i.id, i.nome, i.ativo, c.nome AS categoria_nome
        FROM lc_insumos i
        LEFT JOIN lc_categorias c ON c.id = i.categoria_id
        ORDER BY c.nome, i.nome
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $msg .= '<br><strong>Amostra dos insumos:</strong><br>';
    foreach ($insumos as $insumo) {
        $msg .= "- {$insumo['nome']} (Categoria: " . ($insumo['categoria_nome'] ?? 'Sem categoria') . ", Ativo: " . ($insumo['ativo'] ? 'Sim' : 'Não') . ")<br>";
    }
    
} catch (Exception $e) {
    $err = '❌ Erro: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corrigir Estrutura | Painel Smile PRO</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body class="main-layout">
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Corrigir Estrutura da Tabela lc_insumos</h1>
            <p class="page-subtitle">Adicionando coluna ativo e verificando estrutura</p>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert alert-success">
                <?= $msg ?>
            </div>
        <?php endif; ?>
        
        <?php if ($err): ?>
            <div class="alert alert-error">
                <?= $err ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-body">
                <p>Este script:</p>
                <ul>
                    <li>Verifica se a tabela <code>lc_insumos</code> existe</li>
                    <li>Adiciona a coluna <code>ativo</code> se ela não existir</li>
                    <li>Define todos os insumos existentes como ativos</li>
                    <li>Testa a consulta de insumos</li>
                </ul>
                
                <div class="flex gap-2 mt-4">
                    <a href="configuracoes.php?tab=receitas" class="btn btn-primary">
                        <span>👨‍🍳</span> Testar Ficha Técnica
                    </a>
                    <a href="configuracoes.php?tab=insumos" class="btn btn-outline">
                        <span>🥘</span> Ver Insumos
                    </a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
