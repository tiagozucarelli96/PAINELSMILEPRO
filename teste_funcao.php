<?php
// teste_funcao.php
require_once 'public/lc_permissions_helper.php';

echo "Testando função lc_can_access_demandas...\n";

if (function_exists('lc_can_access_demandas')) {
    echo "✅ Função existe\n";
    $result = lc_can_access_demandas();
    echo "Resultado: " . ($result ? 'true' : 'false') . "\n";
} else {
    echo "❌ Função não existe\n";
}

echo "Funções disponíveis:\n";
$functions = get_defined_functions()['user'];
foreach ($functions as $func) {
    if (strpos($func, 'lc_') === 0) {
        echo "- $func\n";
    }
}
?>
