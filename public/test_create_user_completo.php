<?php
/**
 * Teste completo de criação de usuário
 * Simula EXATAMENTE o que acontece no servidor
 */

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/usuarios_save_robust.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== TESTE COMPLETO DE CRIAÇÃO DE USUÁRIO ===\n\n";

// 1. Verificar todas as colunas NOT NULL
echo "1. COLUNAS NOT NULL:\n";
try {
    $stmt = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_name = 'usuarios' 
        AND is_nullable = 'NO'
        ORDER BY column_name
    ");
    $notNull = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Total: " . count($notNull) . "\n";
    foreach ($notNull as $col) {
        echo "  - {$col['column_name']} ({$col['data_type']}) default: " . ($col['column_default'] ?? 'NULL') . "\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// 2. Verificar coluna funcao
echo "\n2. COLUNA FUNCAO:\n";
try {
    $stmt = $pdo->query("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns 
        WHERE table_name = 'usuarios' 
        AND column_name = 'funcao'
    ");
    $funcao = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($funcao) {
        echo "  EXISTE: SIM\n";
        echo "  Tipo: {$funcao['data_type']}\n";
        echo "  Nullable: {$funcao['is_nullable']}\n";
        echo "  Default: " . ($funcao['column_default'] ?? 'NULL') . "\n";
    } else {
        echo "  EXISTE: NÃO\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// 3. Testar INSERT manual mínimo
echo "\n3. TESTE INSERT MANUAL MÍNIMO:\n";
try {
    $stmt = $pdo->prepare("INSERT INTO usuarios (nome, email, login, senha) VALUES (:nome, :email, :login, :senha) RETURNING id");
    $stmt->execute([
        ':nome' => 'Teste Manual',
        ':email' => 'teste@teste.com',
        ':login' => 'teste_manual',
        ':senha' => password_hash('123456', PASSWORD_DEFAULT)
    ]);
    $id = $stmt->fetch(PDO::FETCH_COLUMN);
    echo "  ✅ SUCESSO! ID: $id\n";
    $pdo->exec("DELETE FROM usuarios WHERE id = $id");
    echo "  Limpo\n";
} catch (Exception $e) {
    echo "  ❌ ERRO: " . $e->getMessage() . "\n";
    echo "  Código: " . $e->getCode() . "\n";
}

// 4. Testar UsuarioSaveManager
echo "\n4. TESTE UsuarioSaveManager:\n";
try {
    $data = [
        'nome' => 'Teste Manager',
        'email' => 'manager@teste.com',
        'login' => 'teste_manager',
        'senha' => '123456'
    ];
    
    $manager = new UsuarioSaveManager($pdo);
    $result = $manager->save($data, 0);
    
    if ($result['success']) {
        echo "  ✅ SUCESSO! ID: {$result['id']}\n";
        $pdo->exec("DELETE FROM usuarios WHERE id = {$result['id']}");
        echo "  Limpo\n";
    } else {
        echo "  ❌ FALHOU: {$result['message']}\n";
    }
} catch (Exception $e) {
    echo "  ❌ EXCEÇÃO: " . $e->getMessage() . "\n";
    echo "  Código: " . $e->getCode() . "\n";
    echo "  Stack:\n";
    $trace = $e->getTraceAsString();
    echo "  " . str_replace("\n", "\n  ", $trace) . "\n";
}

// 5. Construir SQL manualmente para ver o que está sendo gerado
echo "\n5. CONSTRUÇÃO MANUAL DO SQL:\n";
try {
    $sqlCols = ['nome', 'email', 'login', 'senha'];
    $sqlVals = [':nome', ':email', ':login', ':senha'];
    $params = [
        ':nome' => 'Teste SQL',
        ':email' => 'sql@teste.com',
        ':login' => 'teste_sql',
        ':senha' => password_hash('123456', PASSWORD_DEFAULT)
    ];
    
    // Verificar se funcao existe e é NOT NULL
    $stmt = $pdo->query("
        SELECT is_nullable 
        FROM information_schema.columns 
        WHERE table_name = 'usuarios' 
        AND column_name = 'funcao'
    ");
    $funcaoCheck = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($funcaoCheck && $funcaoCheck['is_nullable'] === 'NO') {
        echo "  funcao é NOT NULL, adicionando...\n";
        $sqlCols[] = 'funcao';
        $sqlVals[] = ':funcao';
        $params[':funcao'] = 'OPER';
    }
    
    $sql = "INSERT INTO usuarios (" . implode(', ', $sqlCols) . ") VALUES (" . implode(', ', $sqlVals) . ") RETURNING id";
    echo "  SQL: $sql\n";
    echo "  Params: " . implode(', ', array_keys($params)) . "\n";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $id = $stmt->fetch(PDO::FETCH_COLUMN);
    echo "  ✅ SUCESSO! ID: $id\n";
    $pdo->exec("DELETE FROM usuarios WHERE id = $id");
    echo "  Limpo\n";
} catch (Exception $e) {
    echo "  ❌ ERRO: " . $e->getMessage() . "\n";
    echo "  Código: " . $e->getCode() . "\n";
}

echo "\n=== FIM DO TESTE ===\n";

