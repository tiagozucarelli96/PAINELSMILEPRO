<?php
// analise_permissoes.php
// Análise completa do sistema de permissões

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

echo "<h1>🔍 Análise Completa do Sistema de Permissões</h1>";
echo "<hr>";

// 1. Verificar estrutura atual de permissões
echo "<h2>📋 Sistema de Permissões Atual</h2>";

// Verificar colunas de permissão na tabela usuarios
try {
    $stmt = $pdo->query("
        SELECT column_name, data_type, is_nullable 
        FROM information_schema.columns 
        WHERE table_name = 'usuarios' 
        AND column_name LIKE 'perm_%' 
        ORDER BY ordinal_position
    ");
    $perm_cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>🔑 Colunas de Permissão na Tabela Usuários</h3>";
    if (empty($perm_cols)) {
        echo "<p style='color: red;'>❌ Nenhuma coluna de permissão encontrada!</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Nullable</th></tr>";
        foreach ($perm_cols as $col) {
            echo "<tr>";
            echo "<td>{$col['column_name']}</td>";
            echo "<td>{$col['data_type']}</td>";
            echo "<td>{$col['is_nullable']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro ao verificar colunas: " . $e->getMessage() . "</p>";
}

// 2. Verificar permissões em uso no sistema
echo "<h2>🎯 Permissões em Uso no Sistema</h2>";

$permissoes_sistema = [
    'perm_tarefas' => 'Tarefas',
    'perm_lista' => 'Lista de Compras', 
    'perm_demandas' => 'Solicitar Pagamento',
    'perm_pagamentos' => 'Pagamentos',
    'perm_usuarios' => 'Usuários',
    'perm_portao' => 'Portao',
    'perm_notas_fiscais' => 'Notas Fiscais',
    // 'perm_estoque_logistico' => 'Estoque Logístico', // REMOVIDO: Módulo desativado
    'perm_dados_contrato' => 'Dados do Contrato',
    'perm_uso_fiorino' => 'Uso Fiorino'
];

echo "<h3>📊 Mapeamento de Permissões</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Permissão</th><th>Descrição</th><th>Status</th></tr>";

foreach ($permissoes_sistema as $perm => $desc) {
    $existe = in_array($perm, array_column($perm_cols, 'column_name'));
    $status = $existe ? "✅ Existe" : "❌ Não existe";
    $cor = $existe ? "green" : "red";
    echo "<tr>";
    echo "<td><code>$perm</code></td>";
    echo "<td>$desc</td>";
    echo "<td style='color: $cor;'>$status</td>";
    echo "</tr>";
}
echo "</table>";

// 3. Verificar novos módulos (RH e Contabilidade)
echo "<h2>🆕 Novos Módulos - Controle de Acesso</h2>";

$novos_modulos = [
    'RH' => [
        'rh_dashboard.php' => 'ADM, FIN',
        'rh_colaboradores.php' => 'ADM, FIN', 
        'rh_colaborador_ver.php' => 'ADM, FIN, PRÓPRIO',
        'rh_holerite_upload.php' => 'ADM, FIN'
    ],
    'Contabilidade' => [
        'contab_dashboard.php' => 'ADM, FIN',
        'contab_documentos.php' => 'ADM, FIN, GERENTE, CONSULTA',
        'contab_doc_ver.php' => 'ADM, FIN, GERENTE, CONSULTA',
        'contab_link.php' => 'PÚBLICO (com token)'
    ]
];

echo "<h3>👥 Módulo RH</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Página</th><th>Permissões</th><th>Status</th></tr>";

foreach ($novos_modulos['RH'] as $pagina => $permissoes) {
    $arquivo_existe = file_exists(__DIR__ . "/$pagina");
    $status = $arquivo_existe ? "✅ Implementado" : "❌ Não encontrado";
    $cor = $arquivo_existe ? "green" : "red";
    
    echo "<tr>";
    echo "<td><code>$pagina</code></td>";
    echo "<td>$permissoes</td>";
    echo "<td style='color: $cor;'>$status</td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>📑 Módulo Contabilidade</h3>";
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Página</th><th>Permissões</th><th>Status</th></tr>";

foreach ($novos_modulos['Contabilidade'] as $pagina => $permissoes) {
    $arquivo_existe = file_exists(__DIR__ . "/$pagina");
    $status = $arquivo_existe ? "✅ Implementado" : "❌ Não encontrado";
    $cor = $arquivo_existe ? "green" : "red";
    
    echo "<tr>";
    echo "<td><code>$pagina</code></td>";
    echo "<td>$permissoes</td>";
    echo "<td style='color: $cor;'>$status</td>";
    echo "</tr>";
}
echo "</table>";

// 4. Verificar inconsistências
echo "<h2>⚠️ Inconsistências Encontradas</h2>";

$inconsistencias = [];

// Verificar se há dois sistemas de permissão diferentes
if (file_exists(__DIR__ . '/core/lc_permissions_stub.php') && file_exists(__DIR__ . '/permissoes_boot.php')) {
    $inconsistencias[] = "Dois sistemas de permissão diferentes: core/lc_permissions_stub.php (novo) e permissoes_boot.php (antigo)";
}

// Verificar se novos módulos usam sistema antigo
$paginas_novas = ['rh_dashboard.php', 'contab_dashboard.php'];
foreach ($paginas_novas as $pagina) {
    if (file_exists(__DIR__ . "/$pagina")) {
        $conteudo = file_get_contents(__DIR__ . "/$pagina");
        if (strpos($conteudo, 'core/lc_permissions_stub.php') !== false && strpos($conteudo, 'permissoes_boot.php') !== false) {
            $inconsistencias[] = "Página $pagina usa ambos os sistemas de permissão";
        }
    }
}

if (empty($inconsistencias)) {
    echo "<p style='color: green;'>✅ Nenhuma inconsistência encontrada!</p>";
} else {
    echo "<ul>";
    foreach ($inconsistencias as $inc) {
        echo "<li style='color: red;'>❌ $inc</li>";
    }
    echo "</ul>";
}

// 5. Recomendações
echo "<h2>💡 Recomendações</h2>";

echo "<h3>🔧 Padronização do Sistema de Permissões</h3>";
echo "<ol>";
echo "<li><strong>Unificar sistemas:</strong> Escolher entre core/lc_permissions_stub.php (novo) ou permissoes_boot.php (antigo)</li>";
echo "<li><strong>Adicionar colunas RH:</strong> Incluir campos RH na tabela usuarios (cpf, cargo, etc.)</li>";
echo "<li><strong>Implementar perfil único:</strong> Usar campo 'perfil' em vez de múltiplas colunas perm_*</li>";
echo "<li><strong>Atualizar sidebar:</strong> Incluir novos módulos na sidebar com controle de acesso</li>";
echo "<li><strong>Dashboard unificado:</strong> Integrar novos módulos no dashboard principal</li>";
echo "</ol>";

echo "<h3>🎨 Melhorias na UI</h3>";
echo "<ol>";
echo "<li><strong>Modal de usuário:</strong> Criar modal para cadastro/edição de usuários</li>";
echo "<li><strong>Integração RH:</strong> Conectar cadastro de usuário com dados RH</li>";
echo "<li><strong>Seleção de funções:</strong> Interface para escolha de permissões por usuário</li>";
echo "<li><strong>Visualização de perfil:</strong> Mostrar perfil atual do usuário logado</li>";
echo "</ol>";

// 6. Próximos passos
echo "<h2>🚀 Próximos Passos</h2>";
echo "<ol>";
echo "<li>Executar scripts SQL para adicionar campos RH</li>";
echo "<li>Padronizar sistema de permissões</li>";
echo "<li>Criar modal de cadastro de usuário</li>";
echo "<li>Atualizar sidebar com novos módulos</li>";
echo "<li>Testar controle de acesso em todos os módulos</li>";
echo "</ol>";

echo "<hr>";
echo "<p style='color: blue; font-weight: bold;'>📋 Análise concluída! Verifique as recomendações acima.</p>";
?>
