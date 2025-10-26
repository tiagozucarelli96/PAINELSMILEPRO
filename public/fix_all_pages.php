<?php
// fix_all_pages.php - Correção automática agressiva de todas as páginas
if (session_status() === PHP_SESSION_NONE) { session_start(); }

echo "<h1>🔧 CORREÇÃO AUTOMÁTICA AGRESSIVA DE TODAS AS PÁGINAS</h1>";

// Simular sessão de admin
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['nome'] = 'Teste';
$_SESSION['perfil'] = 'ADM';
$_SESSION['perm_usuarios'] = 1;

$php_files = glob('*.php');
$fixed_count = 0;
$error_count = 0;

foreach ($php_files as $file) {
    if (in_array($file, ['index.php', 'fix_all_pages.php', 'diagnostic_completo.php'])) continue;
    
    echo "<h2>🔍 Processando: $file</h2>";
    
    try {
        $content = file_get_contents($file);
        $original_content = $content;
        $changes = [];
        
        // 1. Remover HTML completo
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
            // Procurar onde inserir
            if (strpos($content, "require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';") !== false) {
                $content = str_replace(
                    "require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';",
                    "require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';\nrequire_once __DIR__ . '/sidebar_integration.php';",
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
        
        // Salvar apenas se houve mudanças
        if ($content !== $original_content) {
            file_put_contents($file, $content);
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
        $error_count++;
        echo "<div style='background:#ffebee;padding:10px;border-radius:4px;margin:10px 0;'>";
        echo "<strong>❌ Erro ao processar $file:</strong> " . $e->getMessage();
        echo "</div>";
    }
}

echo "<h2>📊 RESUMO DA CORREÇÃO</h2>";
echo "<div style='background:#f5f5f5;padding:20px;border-radius:8px;'>";
echo "<strong>Arquivos processados:</strong> " . count($php_files) . "<br>";
echo "<strong>Arquivos corrigidos:</strong> $fixed_count<br>";
echo "<strong>Erros encontrados:</strong> $error_count<br>";
echo "</div>";

if ($fixed_count > 0) {
    echo "<h2>🎯 PRÓXIMOS PASSOS</h2>";
    echo "<ol>";
    echo "<li>Teste o diagnóstico completo: <a href='index.php?page=diagnostic_completo'>diagnostic_completo</a></li>";
    echo "<li>Teste páginas específicas que estavam com problema</li>";
    echo "<li>Verifique se o dashboard está funcionando</li>";
    echo "<li>Teste a sidebar em diferentes páginas</li>";
    echo "</ol>";
}
?>
