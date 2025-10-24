<?php
/**
 * test_all_pages.php — Testar todas as páginas principais
 * Execute: php test_all_pages.php
 */

echo "🧪 Testando Todas as Páginas Principais\n";
echo "=====================================\n\n";

$baseUrl = 'http://localhost:8000';
$pages = [
    'login.php' => 'Página de Login',
    'dashboard.php' => 'Dashboard',
    'index.php?page=dashboard' => 'Dashboard via Index',
    'usuarios.php' => 'Usuários',
    'configuracoes.php' => 'Configurações',
    'lc_index.php' => 'Lista de Compras',
    'estoque_contagens.php' => 'Contagens de Estoque',
    'pagamentos_solicitar.php' => 'Solicitar Pagamento',
    'comercial_degustacoes.php' => 'Degustações Comerciais'
];

$results = [];

foreach ($pages as $page => $description) {
    echo "🔍 Testando $description ($page)...\n";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl . '/' . $page);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo "❌ Erro de conexão: $error\n";
        $results[$page] = ['status' => 'error', 'code' => 0, 'message' => $error];
    } else {
        echo "📊 HTTP $httpCode\n";
        
        if ($httpCode == 200) {
            echo "✅ Página carregando corretamente\n";
            $results[$page] = ['status' => 'ok', 'code' => $httpCode, 'message' => 'OK'];
        } elseif ($httpCode == 302) {
            echo "🔄 Redirecionamento (normal para páginas protegidas)\n";
            $results[$page] = ['status' => 'redirect', 'code' => $httpCode, 'message' => 'Redirecionamento'];
        } elseif ($httpCode == 404) {
            echo "❌ Página não encontrada\n";
            $results[$page] = ['status' => 'not_found', 'code' => $httpCode, 'message' => 'Não encontrada'];
        } elseif ($httpCode >= 500) {
            echo "❌ Erro interno do servidor\n";
            $results[$page] = ['status' => 'server_error', 'code' => $httpCode, 'message' => 'Erro do servidor'];
        } else {
            echo "⚠️ Status inesperado\n";
            $results[$page] = ['status' => 'unexpected', 'code' => $httpCode, 'message' => 'Status inesperado'];
        }
    }
    
    echo "\n";
}

// Resumo dos resultados
echo "📊 Resumo dos Testes\n";
echo "===================\n\n";

$statusCounts = [];
foreach ($results as $page => $result) {
    $status = $result['status'];
    if (!isset($statusCounts[$status])) {
        $statusCounts[$status] = 0;
    }
    $statusCounts[$status]++;
}

foreach ($statusCounts as $status => $count) {
    $icon = match($status) {
        'ok' => '✅',
        'redirect' => '🔄',
        'not_found' => '❌',
        'server_error' => '❌',
        'error' => '❌',
        'unexpected' => '⚠️',
        default => '❓'
    };
    echo "$icon $status: $count páginas\n";
}

echo "\n📋 Detalhes por Página:\n";
foreach ($results as $page => $result) {
    $icon = match($result['status']) {
        'ok' => '✅',
        'redirect' => '🔄',
        'not_found' => '❌',
        'server_error' => '❌',
        'error' => '❌',
        'unexpected' => '⚠️',
        default => '❓'
    };
    echo "$icon $page: HTTP {$result['code']} - {$result['message']}\n";
}

echo "\n🎉 Teste de páginas concluído!\n";

// Verificar se há problemas críticos
$criticalIssues = array_filter($results, function($result) {
    return in_array($result['status'], ['server_error', 'error']);
});

if (empty($criticalIssues)) {
    echo "\n✅ Nenhum problema crítico encontrado!\n";
    echo "O sistema está funcionando corretamente.\n";
} else {
    echo "\n⚠️ Problemas críticos encontrados:\n";
    foreach ($criticalIssues as $page => $result) {
        echo "  - $page: {$result['message']}\n";
    }
}
?>
