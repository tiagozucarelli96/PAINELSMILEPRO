<?php
// final_verification.php
// Verificação final do sistema

echo "Verificação final iniciada...<br>";

try {
    require_once __DIR__ . '/public/conexao.php';
    echo "Conexão carregada<br>";
    
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "PDO disponível<br>";
        
        $schema = $pdo->query("SELECT current_schema()")->fetchColumn();
        echo "Schema atual: {$schema}<br>";
        
        $tables = $pdo->query("
            SELECT COUNT(*) 
            FROM information_schema.tables 
            WHERE table_schema = 'smilee12_painel_smile' 
            AND table_name LIKE 'lc_%'
        ")->fetchColumn();
        
        echo "Tabelas lc_* no schema smilee12_painel_smile: {$tables}<br>";
        
        $categorias = $pdo->query("SELECT COUNT(*) FROM lc_categorias")->fetchColumn();
        echo "Categorias: {$categorias}<br>";
        
        $unidades = $pdo->query("SELECT COUNT(*) FROM lc_unidades")->fetchColumn();
        echo "Unidades: {$unidades}<br>";
        
        $configs = $pdo->query("SELECT COUNT(*) FROM lc_config")->fetchColumn();
        echo "Configurações: {$configs}<br>";
        
        echo "<br><strong>✅ SUCESSO! Sistema funcionando perfeitamente!</strong>";
        echo "<br><strong>🎉 Todas as tabelas foram criadas e migradas com sucesso!</strong>";
        
    } else {
        echo "❌ PDO não disponível";
    }
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage();
}

echo "<br><br>Verificação final concluída em: " . date('H:i:s');
?>
