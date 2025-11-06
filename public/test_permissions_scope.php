<?php
/**
 * Teste para verificar se $existing_perms está disponível no escopo do modal
 */

require_once __DIR__ . '/conexao.php';

// Simular o que acontece no usuarios_new.php
$existing_perms = [];

try {
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_schema = 'public' AND table_name = 'usuarios' 
                         AND column_name LIKE 'perm_%' 
                         ORDER BY column_name");
    $perms_array = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Permissões encontradas: " . count($perms_array) . "\n";
    
    if (!empty($perms_array)) {
        $existing_perms = array_flip($perms_array);
        echo "Permissões flipped: " . count($existing_perms) . "\n";
    } else {
        echo "ERRO: Nenhuma permissão encontrada!\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

// Simular ob_start
ob_start();
echo "Teste de escopo\n";
echo "existing_perms disponível: " . (isset($existing_perms) ? "SIM" : "NÃO") . "\n";
echo "Count: " . (isset($existing_perms) ? count($existing_perms) : 0) . "\n";

$content = ob_get_clean();

echo "Conteúdo capturado:\n";
echo $content;

// Testar dentro de função
function testScope() {
    global $existing_perms;
    echo "\nDentro da função:\n";
    echo "existing_perms disponível: " . (isset($existing_perms) ? "SIM" : "NÃO") . "\n";
    echo "Count: " . (isset($existing_perms) ? count($existing_perms) : 0) . "\n";
}

testScope();

