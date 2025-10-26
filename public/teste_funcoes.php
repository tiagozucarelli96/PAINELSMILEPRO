<?php
// teste_funcoes.php — Teste direto das funções PostgreSQL
declare(strict_types=1);

ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>🧪 Teste Direto das Funções PostgreSQL</h1>";
echo "<style>body{font-family:monospace;margin:20px;} .ok{color:green;} .erro{color:red;}</style>";

// Conectar ao banco
try {
    $pdo = new PDO(
        "pgsql:host=switchback.proxy.rlwy.net;port=10898;dbname=railway;sslmode=require",
        "postgres",
        "qgEAbEeoqBipYcBGKMezSWwcnOomAVJa"
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET search_path TO smilee12_painel_smile, public');
    echo "<p class='ok'>✅ Conexão estabelecida</p>";
} catch (Exception $e) {
    echo "<p class='erro'>❌ Erro de conexão: " . $e->getMessage() . "</p>";
    exit;
}

// Testar funções com parâmetros corretos
$testes = [
    'obter_proximos_eventos' => 'SELECT COUNT(*) FROM obter_proximos_eventos(1, 24)',
    'obter_eventos_hoje' => 'SELECT COUNT(*) FROM obter_eventos_hoje(1, 24)', 
    'obter_eventos_semana' => 'SELECT COUNT(*) FROM obter_eventos_semana(1, 7)',
    'verificar_conflito_agenda' => 'SELECT verificar_conflito_agenda(1, NOW(), NOW() + INTERVAL \'1 hour\', NULL)',
    'lc_gerar_token_publico' => 'SELECT lc_gerar_token_publico()',
    'contab_verificar_rate_limit' => 'SELECT contab_verificar_rate_limit(\'teste123456789\', 50)'
];

echo "<h2>🔬 Testando Funções</h2>";
foreach ($testes as $funcao => $query) {
    try {
        $stmt = $pdo->query($query);
        $resultado = $stmt->fetchColumn();
        echo "<p class='ok'>✅ $funcao: $resultado</p>";
    } catch (Exception $e) {
        echo "<p class='erro'>❌ $funcao: " . $e->getMessage() . "</p>";
    }
}

echo "<h2>✅ Teste Finalizado</h2>";
?>
