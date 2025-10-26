<?php
// fix_critical_issues.php - Correção dos problemas críticos identificados
if (session_status() === PHP_SESSION_NONE) { session_start(); }

echo "<h1>🔧 CORREÇÃO DOS PROBLEMAS CRÍTICOS IDENTIFICADOS</h1>";

// Simular sessão de admin
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['nome'] = 'Teste';
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;

$critical_fixes = [
    '../pagamentos.php' => 'Corrigir sidebar antiga e tela branca',
    '../lista_compras.php' => 'Corrigir sidebar antiga',
    '../ver.php' => 'Corrigir sidebar antiga',
    '../estoque_logistico.php' => 'Corrigir sidebar antiga',
    '../config_insumos.php' => 'Corrigir sidebar antiga',
    '../comercial_degustacoes.php' => 'Corrigir sidebar antiga',
    '../comercial_clientes.php' => 'Corrigir sidebar antiga',
    '../usuarios.php' => 'Corrigir sidebar antiga',
    '../dashboard_simples.php' => 'Corrigir dashboard quebrado'
];

$fixed_count = 0;

foreach ($critical_fixes as $file => $description) {
    $file_path = realpath(__DIR__ . '/' . $file);
    $file_name = basename($file);
    
    echo "<h2>🔍 Corrigindo: $file_name</h2>";
    echo "<p><strong>Problema:</strong> $description</p>";
    echo "<p><strong>Caminho:</strong> $file_path</p>";
    
    if (!$file_path || !file_exists($file_path)) {
        echo "<div style='background:#ffebee;padding:10px;border-radius:4px;margin:10px 0;'>";
        echo "<strong>❌ Arquivo não encontrado: $file_path</strong>";
        echo "</div>";
        continue;
    }
    
    try {
        $content = file_get_contents($file_path);
        $original_content = $content;
        $changes = [];
        
        // 1. Remover HTML completo se existir
        if (strpos($content, '<!DOCTYPE html>') !== false) {
            $content = preg_replace('/<!DOCTYPE html>.*?<html[^>]*>/s', '', $content);
            $changes[] = "Removido DOCTYPE html";
        }
        
        if (strpos($content, '<head>') !== false) {
            $content = preg_replace('/<head>.*?<\/head>/s', '', $content);
            $changes[] = "Removido head";
        }
        
        if (strpos($content, '<body>') !== false) {
            $content = preg_replace('/<body[^>]*>/', '', $content);
            $changes[] = "Removido body";
        }
        
        if (strpos($content, '</body>') !== false) {
            $content = preg_replace('/<\/body>.*?<\/html>/s', '', $content);
            $changes[] = "Removido fechamento body/html";
        }
        
        // 2. Remover sidebar antiga
        if (strpos($content, "include __DIR__.'/sidebar.php'") !== false) {
            $content = preg_replace('/<\?php if \(is_file\(__DIR__\.\'\/sidebar\.php\'\)\) \{ include __DIR__\.\'\/sidebar\.php\'; \} \?>/', '', $content);
            $changes[] = "Removido include sidebar.php";
        }
        
        if (strpos($content, "include 'sidebar.php'") !== false) {
            $content = preg_replace('/<\?php include.*?sidebar\.php.*?\?>/', '', $content);
            $changes[] = "Removido include sidebar.php simples";
        }
        
        // 3. Adicionar sidebar_integration se não existir
        if (strpos($content, 'sidebar_integration.php') === false) {
            if (strpos($content, "require_once __DIR__ . '/conexao.php';") !== false) {
                $content = str_replace(
                    "require_once __DIR__ . '/conexao.php';",
                    "require_once __DIR__ . '/conexao.php';\nrequire_once __DIR__ . '/sidebar_integration.php';",
                    $content
                );
                $changes[] = "Adicionado sidebar_integration.php";
            }
        }
        
        // 4. Adicionar includeSidebar() se não existir
        if (strpos($content, 'includeSidebar()') === false && strpos($content, 'sidebar_integration.php') !== false) {
            $page_name = ucfirst(str_replace('.php', '', $file));
            $content = str_replace(
                "require_once __DIR__ . '/sidebar_integration.php';",
                "require_once __DIR__ . '/sidebar_integration.php';\n\n// Iniciar sidebar\nincludeSidebar();\nsetPageTitle('$page_name');",
                $content
            );
            $changes[] = "Adicionado includeSidebar() e setPageTitle()";
        }
        
        // 5. Adicionar endSidebar() no final se não existir
        if (strpos($content, 'endSidebar()') === false && strpos($content, 'includeSidebar()') !== false) {
            $content .= "\n\n<?php endSidebar(); ?>";
            $changes[] = "Adicionado endSidebar()";
        }
        
        // 6. Corrigir session_start() duplicado
        if (strpos($content, 'session_start()') !== false && strpos($content, 'session_status()') === false) {
            $content = str_replace(
                'session_start();',
                'if (session_status() === PHP_SESSION_NONE) { session_start(); }',
                $content
            );
            $changes[] = "Corrigido session_start() duplicado";
        }
        
        // 7. Remover includes de arquivos CSS externos que podem não existir
        if (strpos($content, 'estilo.css') !== false) {
            $content = preg_replace('/<link[^>]*href=["\']estilo\.css["\'][^>]*>/', '', $content);
            $changes[] = "Removido link para estilo.css";
        }
        
        // 8. Corrigir problemas específicos do dashboard
        if ($file === 'dashboard_simples.php') {
            // Garantir que o dashboard tenha conteúdo básico
            if (strpos($content, 'dashboard-container') === false) {
                $content = str_replace(
                    '<?php endSidebar(); ?>',
                    '<div class="dashboard-container">
    <h1>📊 Dashboard</h1>
    <div class="dashboard-cards">
        <div class="card">
            <h3>Usuários Ativos</h3>
            <p>0</p>
        </div>
        <div class="card">
            <h3>Eventos Cadastrados</h3>
            <p>0</p>
        </div>
        <div class="card">
            <h3>Fornecedores Ativos</h3>
            <p>0</p>
        </div>
        <div class="card">
            <h3>Insumos Cadastrados</h3>
            <p>0</p>
        </div>
    </div>
</div>

<?php endSidebar(); ?>',
                    $content
                );
                $changes[] = "Adicionado conteúdo básico do dashboard";
            }
        }
        
        // Salvar apenas se houve mudanças
        if ($content !== $original_content) {
            file_put_contents($file_path, $content);
            $fixed_count++;
            
            echo "<div style='background:#e8f5e8;padding:10px;border-radius:4px;margin:10px 0;'>";
            echo "<strong>✅ Arquivo corrigido!</strong><br>";
            echo "<strong>Mudanças aplicadas:</strong><br>";
            foreach ($changes as $change) {
                echo "• $change<br>";
            }
            echo "</div>";
        } else {
            echo "<div style='background:#e3f2fd;padding:10px;border-radius:4px;margin:10px 0;'>";
            echo "<strong>ℹ️ Arquivo já estava correto</strong>";
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div style='background:#ffebee;padding:10px;border-radius:4px;margin:10px 0;'>";
        echo "<strong>❌ Erro ao processar $file:</strong> " . $e->getMessage();
        echo "</div>";
    }
}

echo "<h2>📊 RESUMO DA CORREÇÃO CRÍTICA</h2>";
echo "<div style='background:#f5f5f5;padding:20px;border-radius:8px;'>";
echo "<strong>Arquivos processados:</strong> " . count($critical_fixes) . "<br>";
echo "<strong>Arquivos corrigidos:</strong> $fixed_count<br>";
echo "</div>";

if ($fixed_count > 0) {
    echo "<h2>🎯 PRÓXIMOS PASSOS</h2>";
    echo "<ol>";
    echo "<li>Teste o diagnóstico completo novamente: <a href='index.php?page=diagnostic_completo'>diagnostic_completo</a></li>";
    echo "<li>Teste as páginas que estavam com problema:</li>";
    echo "<ul>";
    echo "<li><a href='index.php?page=pagamentos'>Pagamentos</a></li>";
    echo "<li><a href='index.php?page=lista_compras'>Lista de Compras</a></li>";
    echo "<li><a href='index.php?page=ver'>Ver</a></li>";
    echo "<li><a href='index.php?page=estoque_logistico'>Estoque Logístico</a></li>";
    echo "<li><a href='index.php?page=config_insumos'>Config Insumos</a></li>";
    echo "<li><a href='index.php?page=comercial_degustacoes'>Comercial Degustações</a></li>";
    echo "<li><a href='index.php?page=comercial_clientes'>Comercial Clientes</a></li>";
    echo "<li><a href='index.php?page=usuarios'>Usuários</a></li>";
    echo "<li><a href='index.php?page=dashboard'>Dashboard</a></li>";
    echo "</ul>";
    echo "<li>Verifique se as sidebars estão funcionando corretamente</li>";
    echo "<li>Verifique se não há mais telas brancas</li>";
    echo "</ol>";
}
?>
