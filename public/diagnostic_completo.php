<?php
// diagnostic_completo.php - Diagnóstico extremamente minucioso do sistema
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Simular sessão de admin para teste
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['nome'] = 'Teste';
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;
$_SESSION['perm_comercial_ver'] = 1;
$_SESSION['perm_pagamentos'] = 1;
// $_SESSION['perm_estoque_logistico'] = 1; // REMOVIDO: Módulo desativado

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Diagnóstico Completo</title>";
echo "<style>body{font-family:Arial,sans-serif;margin:20px;background:#f5f5f5;}";
echo ".section{background:white;margin:20px 0;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}";
echo ".error{color:#d32f2f;background:#ffebee;padding:10px;border-radius:4px;margin:5px 0;}";
echo ".warning{color:#f57c00;background:#fff3e0;padding:10px;border-radius:4px;margin:5px 0;}";
echo ".success{color:#388e3c;background:#e8f5e8;padding:10px;border-radius:4px;margin:5px 0;}";
echo ".info{color:#1976d2;background:#e3f2fd;padding:10px;border-radius:4px;margin:5px 0;}";
echo "table{border-collapse:collapse;width:100%;}th,td{border:1px solid #ddd;padding:8px;text-align:left;}th{background:#f2f2f2;}</style></head><body>";

echo "<h1>🔍 DIAGNÓSTICO EXTREMAMENTE MINUCIOSO DO SISTEMA</h1>";

// 1. VERIFICAR TODAS AS ROTAS DO INDEX.PHP
echo "<div class='section'>";
echo "<h2>1. 📋 VERIFICAÇÃO DE ROTAS (index.php)</h2>";

$index_content = file_get_contents(__DIR__ . '/index.php');
preg_match_all("/'([^']+)'\s*=>\s*'([^']+)'/", $index_content, $matches);
$routes = array_combine($matches[1], $matches[2]);

echo "<table><tr><th>Rota</th><th>Arquivo</th><th>Existe?</th><th>Status</th></tr>";

foreach ($routes as $route => $file) {
    $exists = file_exists($file);
    $status = $exists ? "✅ OK" : "❌ FALTANDO";
    $color = $exists ? "success" : "error";
    echo "<tr><td>$route</td><td>$file</td><td>" . ($exists ? "Sim" : "Não") . "</td><td class='$color'>$status</td></tr>";
}

echo "</table></div>";

// 2. VERIFICAR TODAS AS PÁGINAS PHP
echo "<div class='section'>";
echo "<h2>2. 📁 VERIFICAÇÃO DE TODAS AS PÁGINAS PHP</h2>";

$php_files = glob(__DIR__ . '/*.php');
$problematic_files = [];

echo "<table><tr><th>Arquivo</th><th>Sintaxe</th><th>HTML Completo</th><th>Sidebar Antiga</th><th>Sidebar Nova</th><th>Status</th></tr>";

foreach ($php_files as $file) {
    $filename = basename($file);
    if ($filename === 'index.php' || $filename === 'diagnostic_completo.php') continue;
    
    $content = file_get_contents($file);
    
    // Verificar sintaxe
    $syntax_check = shell_exec("php -l $file 2>&1");
    $syntax_ok = strpos($syntax_check, 'No syntax errors') !== false;
    
    // Verificar HTML completo
    $has_html = strpos($content, '<!DOCTYPE html>') !== false || strpos($content, '<html') !== false;
    
    // Verificar sidebar antiga
    $has_old_sidebar = strpos($content, "include __DIR__.'/sidebar.php'") !== false || 
                       strpos($content, "include 'sidebar.php'") !== false ||
                       strpos($content, 'sidebar.php') !== false;
    
    // Verificar sidebar nova
    $has_new_sidebar = strpos($content, 'sidebar_integration.php') !== false;
    
    $status = "✅ OK";
    $status_class = "success";
    
    if (!$syntax_ok) {
        $status = "❌ SINTAXE";
        $status_class = "error";
        $problematic_files[] = $filename;
    } elseif ($has_html && !$has_new_sidebar) {
        $status = "⚠️ HTML COMPLETO";
        $status_class = "warning";
        $problematic_files[] = $filename;
    } elseif ($has_old_sidebar) {
        $status = "⚠️ SIDEBAR ANTIGA";
        $status_class = "warning";
        $problematic_files[] = $filename;
    } elseif (!$has_new_sidebar && !$has_html) {
        $status = "⚠️ SEM SIDEBAR";
        $status_class = "warning";
        $problematic_files[] = $filename;
    }
    
    echo "<tr>";
    echo "<td>$filename</td>";
    echo "<td>" . ($syntax_ok ? "✅" : "❌") . "</td>";
    echo "<td>" . ($has_html ? "⚠️" : "✅") . "</td>";
    echo "<td>" . ($has_old_sidebar ? "❌" : "✅") . "</td>";
    echo "<td>" . ($has_new_sidebar ? "✅" : "❌") . "</td>";
    echo "<td class='$status_class'>$status</td>";
    echo "</tr>";
}

echo "</table></div>";

// 3. TESTAR CARREGAMENTO DE PÁGINAS CRÍTICAS
echo "<div class='section'>";
echo "<h2>3. 🧪 TESTE DE CARREGAMENTO DE PÁGINAS CRÍTICAS</h2>";

$critical_pages = [
    'dashboard' => 'sidebar_simples.php',
    'comercial' => 'comercial.php',
    'usuarios' => 'usuarios.php',
    'pagamentos' => 'pagamentos.php',
    'lista_compras' => 'lista_compras.php',
    'ver' => 'ver.php',
    'estoque_logistico' => 'estoque_logistico.php',
    'config_insumos' => 'config_insumos.php',
    'comercial_degustacoes' => 'comercial_degustacoes.php',
    'comercial_clientes' => 'comercial_clientes.php'
];

echo "<table><tr><th>Página</th><th>Arquivo</th><th>Carregamento</th><th>Conteúdo</th><th>Sidebar</th><th>Status</th></tr>";

foreach ($critical_pages as $page => $file) {
    $file_path = __DIR__ . '/' . $file;
    if (!file_exists($file_path)) {
        echo "<tr><td>$page</td><td>$file</td><td>❌</td><td>❌</td><td>❌</td><td class='error'>ARQUIVO FALTANDO</td></tr>";
        continue;
    }
    
    try {
        ob_start();
        include $file_path;
        $content = ob_get_clean();
        
        $loads = !empty($content);
        $has_content = strlen($content) > 100;
        $has_sidebar = strpos($content, 'sidebar') !== false || strpos($content, 'main-content') !== false;
        
        $status = "✅ OK";
        $status_class = "success";
        
        if (!$loads) {
            $status = "❌ VAZIO";
            $status_class = "error";
        } elseif (!$has_content) {
            $status = "⚠️ POUCO CONTEÚDO";
            $status_class = "warning";
        } elseif (!$has_sidebar) {
            $status = "⚠️ SEM SIDEBAR";
            $status_class = "warning";
        }
        
        echo "<tr>";
        echo "<td>$page</td>";
        echo "<td>$file</td>";
        echo "<td>" . ($loads ? "✅" : "❌") . "</td>";
        echo "<td>" . ($has_content ? "✅" : "❌") . "</td>";
        echo "<td>" . ($has_sidebar ? "✅" : "❌") . "</td>";
        echo "<td class='$status_class'>$status</td>";
        echo "</tr>";
        
    } catch (Exception $e) {
        echo "<tr><td>$page</td><td>$file</td><td>❌</td><td>❌</td><td>❌</td><td class='error'>ERRO: " . $e->getMessage() . "</td></tr>";
    } catch (Error $e) {
        echo "<tr><td>$page</td><td>$file</td><td>❌</td><td>❌</td><td>❌</td><td class='error'>ERRO FATAL: " . $e->getMessage() . "</td></tr>";
    }
}

echo "</table></div>";

// 4. VERIFICAR ARQUIVOS DE SIDEBAR
echo "<div class='section'>";
echo "<h2>4. 🎨 VERIFICAÇÃO DE ARQUIVOS DE SIDEBAR</h2>";

$sidebar_files = [
    '../sidebar.php' => 'Sidebar Antiga',
    '../sidebar_moderna.php' => 'Sidebar Moderna',
    '../sidebar_macro.php' => 'Sidebar Macro',
    '../sidebar_simples.php' => 'Sidebar Simples',
    'sidebar_integration.php' => 'Sidebar Integration',
    '../sidebar_funcional.php' => 'Sidebar Funcional'
];

echo "<table><tr><th>Arquivo</th><th>Descrição</th><th>Existe?</th><th>Sintaxe</th><th>Status</th></tr>";

foreach ($sidebar_files as $file => $desc) {
    $exists = file_exists($file);
    $syntax_ok = "N/A";
    
    if ($exists) {
        $syntax_check = shell_exec("php -l $file 2>&1");
        $syntax_ok = strpos($syntax_check, 'No syntax errors') !== false ? "✅" : "❌";
    }
    
    $status = $exists ? ($syntax_ok === "✅" ? "✅ OK" : "❌ SINTAXE") : "❌ FALTANDO";
    $status_class = $exists ? ($syntax_ok === "✅" ? "success" : "error") : "error";
    
    echo "<tr>";
    echo "<td>$file</td>";
    echo "<td>$desc</td>";
    echo "<td>" . ($exists ? "Sim" : "Não") . "</td>";
    echo "<td>$syntax_ok</td>";
    echo "<td class='$status_class'>$status</td>";
    echo "</tr>";
}

echo "</table></div>";

// 5. VERIFICAR CONEXÃO COM BANCO
echo "<div class='section'>";
echo "<h2>5. 🗄️ VERIFICAÇÃO DE BANCO DE DADOS</h2>";

try {
    require_once 'conexao.php';
    
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "<div class='success'>✅ Conexão com banco estabelecida</div>";
        
        // Testar algumas consultas básicas
        $tables_to_check = ['usuarios', 'comercial_degustacoes', 'lc_insumos', 'fornecedores'];
        
        echo "<table><tr><th>Tabela</th><th>Existe?</th><th>Registros</th><th>Status</th></tr>";
        
        foreach ($tables_to_check as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
                $count = $stmt->fetchColumn();
                echo "<tr><td>$table</td><td>✅</td><td>$count</td><td class='success'>✅ OK</td></tr>";
            } catch (Exception $e) {
                echo "<tr><td>$table</td><td>❌</td><td>N/A</td><td class='error'>❌ ERRO: " . $e->getMessage() . "</td></tr>";
            }
        }
        
        echo "</table>";
        
    } else {
        echo "<div class='error'>❌ Falha na conexão com banco</div>";
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ Erro ao conectar com banco: " . $e->getMessage() . "</div>";
}

echo "</div>";

// 6. RESUMO E RECOMENDAÇÕES
echo "<div class='section'>";
echo "<h2>6. 📊 RESUMO E RECOMENDAÇÕES</h2>";

echo "<h3>Problemas Identificados:</h3>";
echo "<ul>";

if (!empty($problematic_files)) {
    echo "<li class='error'><strong>Arquivos Problemáticos:</strong> " . implode(', ', $problematic_files) . "</li>";
}

echo "<li class='info'><strong>Total de Rotas:</strong> " . count($routes) . "</li>";
echo "<li class='info'><strong>Total de Arquivos PHP:</strong> " . count($php_files) . "</li>";
echo "<li class='info'><strong>Páginas Críticas Testadas:</strong> " . count($critical_pages) . "</li>";

echo "</ul>";

echo "<h3>Ações Recomendadas:</h3>";
echo "<ol>";
echo "<li>Executar script de correção automática de sidebars</li>";
echo "<li>Corrigir arquivos com sintaxe incorreta</li>";
echo "<li>Padronizar todas as páginas para usar sidebar_integration.php</li>";
echo "<li>Testar cada página individualmente após correções</li>";
echo "</ol>";

echo "</div>";

echo "</body></html>";
?>
