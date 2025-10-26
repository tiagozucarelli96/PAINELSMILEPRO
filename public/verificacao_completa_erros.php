<?php
// verificacao_completa_erros.php — Script para identificar todos os erros do sistema
declare(strict_types=1);

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>🔍 Verificação Completa de Erros</h1>";
echo "<style>body{font-family:monospace;margin:20px;} .ok{color:green;} .erro{color:red;} .warning{color:orange;}</style>";

// Conectar ao banco
try {
    $pdo = new PDO(
        "pgsql:host=switchback.proxy.rlwy.net;port=10898;dbname=railway;sslmode=require",
        "postgres",
        "qgEAbEeoqBipYcBGKMezSWwcnOomAVJa"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET search_path TO smilee12_painel_smile, public');
    echo "<p class='ok'>✅ Conexão com banco estabelecida</p>";
} catch (Exception $e) {
    echo "<p class='erro'>❌ Erro de conexão: " . $e->getMessage() . "</p>";
    exit;
}

// Verificar tabelas principais
$tabelas_principais = [
    'usuarios', 'eventos', 'comercial_degustacoes', 'comercial_inscricoes',
    'lc_insumos', 'lc_solicitacoes_pagamento', 'rh_holerites', 'fornecedores',
    'lc_categorias', 'lc_encomendas_itens', 'contab_tokens', 'contab_documentos',
    'lc_freelancers'
];

echo "<h2>📊 Verificação de Tabelas</h2>";
foreach ($tabelas_principais as $tabela) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $tabela");
        $count = $stmt->fetchColumn();
        echo "<p class='ok'>✅ Tabela $tabela: $count registros</p>";
    } catch (Exception $e) {
        echo "<p class='erro'>❌ Erro na tabela $tabela: " . $e->getMessage() . "</p>";
    }
}

// Verificar colunas específicas que podem estar faltando
echo "<h2>🔍 Verificação de Colunas</h2>";
$colunas_verificar = [
    'comercial_degustacoes' => ['status', 'nome', 'data', 'criado_por'],
    'usuarios' => ['cor_agenda', 'perm_forcar_conflito', 'admissao_data', 'salario_base', 'status_empregado'],
    'lc_insumos' => ['unidade_compra'],
    'lc_solicitacoes_pagamento' => ['fornecedor_id', 'criador_id'],
    'rh_holerites' => ['valor_liquido'],
    'fornecedores' => ['modificado_em'],
    'lc_categorias' => ['mostrar_no_gerar'],
    'lc_encomendas_itens' => ['insumo_id']
];

foreach ($colunas_verificar as $tabela => $colunas) {
    foreach ($colunas as $coluna) {
        try {
            $stmt = $pdo->query("SELECT $coluna FROM $tabela LIMIT 1");
            echo "<p class='ok'>✅ Coluna $tabela.$coluna: OK</p>";
        } catch (Exception $e) {
            echo "<p class='erro'>❌ Coluna $tabela.$coluna: " . $e->getMessage() . "</p>";
        }
    }
}

// Verificar funções
echo "<h2>⚙️ Verificação de Funções</h2>";
$funcoes_verificar = [
    'obter_proximos_eventos',
    'obter_eventos_hoje', 
    'obter_eventos_semana',
    'verificar_conflito_agenda',
    'lc_gerar_token_publico',
    'contab_verificar_rate_limit'
];

foreach ($funcoes_verificar as $funcao) {
    try {
        $stmt = $pdo->query("SELECT $funcao(1, 1)");
        echo "<p class='ok'>✅ Função $funcao: OK</p>";
    } catch (Exception $e) {
        echo "<p class='erro'>❌ Função $funcao: " . $e->getMessage() . "</p>";
    }
}

// Verificar ENUMs
echo "<h2>📋 Verificação de ENUMs</h2>";
$enums_verificar = [
    'solicitacoes_pagfor_status' => ['Rascunho', 'Pendente', 'Aguardando pagamento', 'Pago'],
    'eventos_status' => ['ativo', 'inativo', 'cancelado'],
    'aquisicao' => ['compra', 'producao', 'terceirizado']
];

foreach ($enums_verificar as $enum => $valores) {
    foreach ($valores as $valor) {
        try {
            $stmt = $pdo->query("SELECT '$valor'::text");
            echo "<p class='ok'>✅ ENUM $enum valor '$valor': OK</p>";
        } catch (Exception $e) {
            echo "<p class='erro'>❌ ENUM $enum valor '$valor': " . $e->getMessage() . "</p>";
        }
    }
}

// Testar queries específicas que podem estar falhando
echo "<h2>🔬 Teste de Queries Específicas</h2>";

// Query do dashboard
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM lc_categorias WHERE ativo = true");
    echo "<p class='ok'>✅ Query dashboard categorias: OK</p>";
} catch (Exception $e) {
    echo "<p class='erro'>❌ Query dashboard categorias: " . $e->getMessage() . "</p>";
}

// Query da agenda
try {
    $stmt = $pdo->query("SELECT id, nome, cor_agenda FROM usuarios LIMIT 1");
    echo "<p class='ok'>✅ Query agenda usuarios: OK</p>";
} catch (Exception $e) {
    echo "<p class='erro'>❌ Query agenda usuarios: " . $e->getMessage() . "</p>";
}

// Query comercial
try {
    $stmt = $pdo->query("SELECT d.*, u.nome as criado_por_nome FROM comercial_degustacoes d LEFT JOIN usuarios u ON u.id = d.criado_por LIMIT 1");
    echo "<p class='ok'>✅ Query comercial degustacoes: OK</p>";
} catch (Exception $e) {
    echo "<p class='erro'>❌ Query comercial degustacoes: " . $e->getMessage() . "</p>";
}

// Query pagamentos
try {
    $stmt = $pdo->query("SELECT s.*, u.nome as criador_nome FROM lc_solicitacoes_pagamento s LEFT JOIN usuarios u ON u.id = s.criador_id LIMIT 1");
    echo "<p class='ok'>✅ Query pagamentos: OK</p>";
} catch (Exception $e) {
    echo "<p class='erro'>❌ Query pagamentos: " . $e->getMessage() . "</p>";
}

echo "<h2>✅ Verificação Completa Finalizada</h2>";
echo "<p>Verifique os erros acima e corrija conforme necessário.</p>";
?>
