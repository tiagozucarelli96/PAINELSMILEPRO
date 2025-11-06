<?php
/**
 * Script de teste para INSERT de usuário
 * Simula exatamente o que acontece no código
 */

require_once __DIR__ . '/../public/conexao.php';
require_once __DIR__ . '/../public/usuarios_save_robust.php';

echo "=== TESTE DE INSERT DE USUÁRIO ===\n\n";

// Dados de teste
$data = [
    'nome' => 'Teste Insert',
    'email' => 'teste@teste.com',
    'login' => 'teste',
    'senha' => '123456',
    'cargo' => 'Testador'
];

try {
    $manager = new UsuarioSaveManager($pdo);
    
    echo "Dados do teste:\n";
    print_r($data);
    echo "\n";
    
    $result = $manager->save($data, 0);
    
    echo "Resultado:\n";
    print_r($result);
    
    if ($result['success']) {
        echo "\n✅ SUCESSO! Usuário criado com ID: " . $result['id'] . "\n";
        
        // Limpar usuário de teste
        $pdo->exec("DELETE FROM usuarios WHERE id = " . $result['id']);
        echo "Usuário de teste removido.\n";
    } else {
        echo "\n❌ ERRO: " . $result['message'] . "\n";
    }
} catch (Exception $e) {
    echo "\n❌ EXCEÇÃO: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

