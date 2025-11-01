<?php
// fix_token_publico_degustacoes.php
// Script para adicionar coluna token_publico na tabela comercial_degustacoes

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar permissões (apenas admins)
if (!isset($_SESSION['logado']) || !isset($_SESSION['id'])) {
    die('Acesso negado. Faça login primeiro.');
}

// Verificar se é admin (ajuste conforme seu sistema de permissões)
$stmt = $pdo->prepare("SELECT perm_admin FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !$user['perm_admin']) {
    die('Acesso negado. Apenas administradores podem executar este script.');
}

echo "<h1>Correção: Adicionar coluna token_publico</h1>";
echo "<pre>";

try {
    // Verificar se coluna já existe
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_schema = 'public' 
        AND table_name = 'comercial_degustacoes' 
        AND column_name = 'token_publico'
    ");
    $exists = $stmt->fetch();
    
    if ($exists) {
        echo "✅ Coluna token_publico já existe!\n";
    } else {
        echo "📝 Adicionando coluna token_publico...\n";
        
        // Adicionar coluna
        $pdo->exec("
            ALTER TABLE comercial_degustacoes 
            ADD COLUMN token_publico VARCHAR(64) UNIQUE
        ");
        
        echo "✅ Coluna adicionada com sucesso!\n";
        
        // Gerar tokens para degustações existentes
        echo "📝 Gerando tokens para degustações existentes...\n";
        $stmt = $pdo->query("SELECT id FROM comercial_degustacoes WHERE token_publico IS NULL OR token_publico = ''");
        $degustacoes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $count = 0;
        foreach ($degustacoes as $id) {
            $token = bin2hex(random_bytes(32)); // 64 caracteres
            $stmt_update = $pdo->prepare("UPDATE comercial_degustacoes SET token_publico = ? WHERE id = ?");
            $stmt_update->execute([$token, $id]);
            $count++;
        }
        
        echo "✅ Tokens gerados para $count degustações!\n";
    }
    
    // Verificar/criar função lc_gerar_token_publico
    echo "📝 Verificando função lc_gerar_token_publico...\n";
    $pdo->exec("
        CREATE OR REPLACE FUNCTION lc_gerar_token_publico()
        RETURNS VARCHAR(64) AS \$\$
        BEGIN
            RETURN REPLACE(gen_random_uuid()::text, '-', '');
        END;
        \$\$ LANGUAGE plpgsql;
    ");
    
    echo "✅ Função lc_gerar_token_publico criada/atualizada!\n";
    
    echo "\n✅ Correção concluída com sucesso!\n";
    echo "\n<a href='index.php?page=comercial_degustacoes'>← Voltar para Degustações</a>\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
?>

