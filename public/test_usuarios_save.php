<?php
/**
 * Script de teste para verificar salvamento de usuários
 * Execute: php -f test_usuarios_save.php
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/usuarios_save_robust.php';

echo "=== TESTE DE SALVAMENTO DE USUÁRIOS ===\n\n";

try {
    // Verificar conexão
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception("Erro: PDO não disponível");
    }
    echo "✅ Conexão com banco OK\n";
    
    // Verificar estrutura da tabela
    $stmt = $pdo->query("
        SELECT column_name, data_type, is_nullable
        FROM information_schema.columns 
        WHERE table_schema = 'public' AND table_name = 'usuarios'
        ORDER BY ordinal_position
    ");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "\n📋 Colunas encontradas na tabela usuarios:\n";
    foreach ($columns as $col) {
        echo "  - {$col['column_name']} ({$col['data_type']})\n";
    }
    
    // Testar salvamento
    $manager = new UsuarioSaveManager($pdo);
    
    echo "\n🧪 Testando criação de usuário...\n";
    $testData = [
        'nome' => 'Teste Usuario',
        'email' => 'teste' . time() . '@teste.com',
        'senha' => 'senha123',
        'login' => 'teste' . time(),
        'cargo' => 'Desenvolvedor',
        'cpf' => '123.456.789-00',
        'admissao_data' => '2024-01-01',
        'salario_base' => 5000.00,
        'pix_tipo' => 'CPF',
        'pix_chave' => '12345678900',
        'status_empregado' => 'ativo',
        'perm_agenda' => true,
        'perm_configuracoes' => true
    ];
    
    $result = $manager->save($testData, 0);
    
    if ($result['success']) {
        echo "✅ Usuário criado com sucesso! ID: {$result['id']}\n";
        
        // Testar atualização
        echo "\n🧪 Testando atualização de usuário...\n";
        $testData['nome'] = 'Teste Usuario Atualizado';
        $testData['cargo'] = 'Desenvolvedor Senior';
        $result2 = $manager->save($testData, $result['id']);
        
        if ($result2['success']) {
            echo "✅ Usuário atualizado com sucesso!\n";
        } else {
            echo "❌ Erro ao atualizar: {$result2['message']}\n";
        }
        
        // Limpar teste
        $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$result['id']]);
        echo "\n🧹 Usuário de teste removido\n";
    } else {
        echo "❌ Erro ao criar: {$result['message']}\n";
    }
    
    echo "\n✅ Todos os testes passaram!\n";
    
} catch (Exception $e) {
    echo "\n❌ ERRO: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

