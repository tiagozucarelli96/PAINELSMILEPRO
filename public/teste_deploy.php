<?php
// teste_deploy.php
echo "Teste de deploy - " . date('Y-m-d H:i:s') . "\n";
echo "Versão: " . phpversion() . "\n";
echo "Arquivo atualizado em: " . filemtime(__FILE__) . "\n";
?>
