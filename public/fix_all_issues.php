<?php
// fix_all_issues.php - Corrigir todos os problemas reportados

require_once 'conexao.php';

$msg = '';
$err = '';

try {
    echo "<h2>Corrigindo Todos os Problemas...</h2>";
    
    // 1. Verificar e criar enum insumo_aquisicao
    $enumExists = $pdo->query("
        SELECT EXISTS (
            SELECT 1 FROM pg_type 
            WHERE typname = 'insumo_aquisicao'
        )
    ")->fetchColumn();
    
    if (!$enumExists) {
        $pdo->exec("CREATE TYPE insumo_aquisicao AS ENUM ('mercado', 'preparo', 'fixo')");
        $msg .= '✅ Enum insumo_aquisicao criado.<br>';
    } else {
        $msg .= '✅ Enum insumo_aquisicao já existe.<br>';
    }
    
    // 2. Verificar se a coluna ativo existe na tabela lc_insumos
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
        $pdo->exec("
            ALTER TABLE smilee12_painel_smile.lc_insumos
            ADD COLUMN ativo BOOLEAN DEFAULT true
        ");
        
        $pdo->exec("
            UPDATE smilee12_painel_smile.lc_insumos
            SET ativo = true
            WHERE ativo IS NULL
        ");
        
        $msg .= '✅ Coluna ativo adicionada à tabela lc_insumos.<br>';
    } else {
        $msg .= '✅ Coluna ativo já existe na tabela lc_insumos.<br>';
    }
    
    // 3. Verificar se as tabelas de receitas existem
    $receitasExists = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_schema = 'smilee12_painel_smile' AND table_name = 'lc_receitas'
        )
    ")->fetchColumn();
    
    if (!$receitasExists) {
        $sql = file_get_contents('../create_recipes_tables.sql');
        $pdo->exec($sql);
        $msg .= '✅ Tabelas de receitas criadas.<br>';
    } else {
        $msg .= '✅ Tabelas de receitas já existem.<br>';
    }
    
    // 4. Testar consulta de insumos
    $insumos = $pdo->query("
        SELECT i.id, i.nome, i.ativo, c.nome AS categoria_nome
        FROM lc_insumos i
        LEFT JOIN lc_categorias c ON c.id = i.categoria_id
        ORDER BY c.nome, i.nome
        LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $msg .= '<br><strong>Teste de consulta de insumos:</strong><br>';
    foreach ($insumos as $insumo) {
        $msg .= "- {$insumo['nome']} (Categoria: " . ($insumo['categoria_nome'] ?? 'Sem categoria') . ", Ativo: " . ($insumo['ativo'] ? 'Sim' : 'Não') . ")<br>";
    }
    
    // 5. Verificar se há dados de teste
    $countInsumos = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_insumos")->fetchColumn();
    $countCategorias = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_categorias")->fetchColumn();
    $countUnidades = $pdo->query("SELECT COUNT(*) FROM smilee12_painel_smile.lc_unidades")->fetchColumn();
    
    $msg .= "<br><strong>Contadores:</strong><br>";
    $msg .= "- Insumos: $countInsumos<br>";
    $msg .= "- Categorias: $countCategorias<br>";
    $msg .= "- Unidades: $countUnidades<br>";
    
} catch (Exception $e) {
    $err = '❌ Erro: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corrigir Problemas | Painel Smile PRO</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body class="main-layout">
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Corrigir Todos os Problemas</h1>
            <p class="page-subtitle">Resolvendo erros de validação, enum e modal</p>
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
                <h3>Problemas Corrigidos:</h3>
                <ul>
                    <li>✅ <strong>Exclusão de categorias/unidades:</strong> Formulários separados para evitar conflitos</li>
                    <li>✅ <strong>Enum insumo_aquisicao:</strong> Criado com valores 'mercado', 'preparo', 'fixo'</li>
                    <li>✅ <strong>Coluna ativo:</strong> Adicionada na tabela lc_insumos</li>
                    <li>✅ <strong>Modal ficha técnica:</strong> JavaScript corrigido para atualizar conteúdo</li>
                    <li>✅ <strong>Tabelas de receitas:</strong> Criadas se não existirem</li>
                </ul>
                
                <div class="flex gap-2 mt-4">
                    <a href="configuracoes.php?tab=categorias" class="btn btn-primary">
                        <span>📂</span> Testar Categorias
                    </a>
                    <a href="configuracoes.php?tab=unidades" class="btn btn-primary">
                        <span>📏</span> Testar Unidades
                    </a>
                    <a href="configuracoes.php?tab=insumos" class="btn btn-primary">
                        <span>🥘</span> Testar Insumos
                    </a>
                    <a href="configuracoes.php?tab=receitas" class="btn btn-primary">
                        <span>👨‍🍳</span> Testar Receitas
                    </a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
