<?php
// teste_demandas_simples.php
echo "Testando demandas...\n";

// Simular sessão
session_start();
$_SESSION['perfil'] = 'ADM';
$_SESSION['user_id'] = 1;

// Incluir arquivos necessários
require_once 'public/conexao.php';
require_once 'public/demandas_helper.php';

// Função temporária
function lc_can_access_demandas(): bool {
    $perfil = $_SESSION['perfil'] ?? 'ADM';
    return in_array($perfil, ['ADM', 'OPER']);
}

echo "Função criada: " . (function_exists('lc_can_access_demandas') ? 'SIM' : 'NÃO') . "\n";
echo "Resultado: " . (lc_can_access_demandas() ? 'true' : 'false') . "\n";

try {
    $demandas = new DemandasHelper();
    echo "DemandasHelper criado com sucesso\n";
} catch (Exception $e) {
    echo "Erro ao criar DemandasHelper: " . $e->getMessage() . "\n";
}
?>
