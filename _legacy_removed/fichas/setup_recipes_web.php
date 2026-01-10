<?php
// setup_recipes_web.php - Executar script de criação das tabelas de receitas via web

require_once 'conexao.php';

$msg = '';
$err = '';

try {
    echo "<h2>Configurando Tabelas de Receitas...</h2>";
    
    // Ler o arquivo SQL
    $sql = file_get_contents('../create_recipes_tables.sql');
    
    if (!$sql) {
        throw new Exception('Não foi possível ler o arquivo create_recipes_tables.sql');
    }
    
    // Executar o SQL
    $pdo->exec($sql);
    
    $msg = '✅ Tabelas de receitas criadas com sucesso!';
    
    // Verificar se as tabelas foram criadas
    $tables = $pdo->query("
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'smilee12_painel_smile' 
        AND table_name IN ('lc_receitas', 'lc_receita_componentes')
        ORDER BY table_name
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    $msg .= '<br>Tabelas criadas: ' . implode(', ', $tables);
    
} catch (Exception $e) {
    $err = '❌ Erro: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Receitas | Painel Smile PRO</title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body class="main-layout">
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">Setup das Tabelas de Receitas</h1>
            <p class="page-subtitle">Configurando banco de dados para receitas e fichas técnicas</p>
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
                <p>Este script criou as seguintes tabelas:</p>
                <ul>
                    <li><strong>lc_receitas</strong> - Dados principais das receitas</li>
                    <li><strong>lc_receita_componentes</strong> - Insumos e quantidades das receitas</li>
                </ul>
                
                <p>E também criou:</p>
                <ul>
                    <li>Triggers para atualização automática de custos</li>
                    <li>Funções para cálculo de custo total</li>
                    <li>Índices para melhor performance</li>
                </ul>
                
                <div class="flex gap-2 mt-4">
                    <a href="configuracoes.php?tab=receitas" class="btn btn-primary">
                        <span>👨‍🍳</span> Ir para Receitas
                    </a>
                    <a href="configuracoes.php" class="btn btn-outline">
                        <span>⚙️</span> Voltar para Configurações
                    </a>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
