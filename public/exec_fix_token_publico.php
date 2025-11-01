<?php
// exec_fix_token_publico.php
// Script para executar a correção do token_publico diretamente

require_once __DIR__ . '/conexao.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Correção: Adicionar coluna token_publico ===\n\n";

try {
    // Verificar se coluna já existe
    echo "1. Verificando se coluna token_publico existe...\n";
    $stmt = $pdo->query("
        SELECT column_name 
        FROM information_schema.columns 
        WHERE table_schema IN ('smilee12_painel_smile', 'public')
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
            ADD COLUMN IF NOT EXISTS token_publico VARCHAR(64) UNIQUE
        ");
        
        echo "✅ Coluna adicionada com sucesso!\n";
    }
    
    // Gerar tokens para degustações existentes
    echo "\n2. Verificando degustações sem token...\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM comercial_degustacoes WHERE token_publico IS NULL OR token_publico = ''");
    $count_sem_token = (int)$stmt->fetchColumn();
    
    if ($count_sem_token > 0) {
        echo "📝 Gerando tokens para $count_sem_token degustações...\n";
        
        // Buscar todas as degustações sem token
        $stmt = $pdo->query("SELECT id FROM comercial_degustacoes WHERE token_publico IS NULL OR token_publico = ''");
        $degustacoes = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $count_atualizado = 0;
        foreach ($degustacoes as $id) {
            // Gerar token único (64 caracteres hex)
            $token = bin2hex(random_bytes(32));
            
            // Verificar se token já existe (muito improvável, mas seguro)
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM comercial_degustacoes WHERE token_publico = ?");
            $stmt_check->execute([$token]);
            while ($stmt_check->fetchColumn() > 0) {
                $token = bin2hex(random_bytes(32)); // Regenerar se colidir
                $stmt_check->execute([$token]);
            }
            
            $stmt_update = $pdo->prepare("UPDATE comercial_degustacoes SET token_publico = ? WHERE id = ?");
            $stmt_update->execute([$token, $id]);
            $count_atualizado++;
        }
        
        echo "✅ Tokens gerados para $count_atualizado degustações!\n";
    } else {
        echo "✅ Todas as degustações já têm token!\n";
    }
    
    // Criar/atualizar função lc_gerar_token_publico
    echo "\n3. Criando/atualizando função lc_gerar_token_publico...\n";
    $pdo->exec("
        CREATE OR REPLACE FUNCTION lc_gerar_token_publico()
        RETURNS VARCHAR(64) AS \$\$
        BEGIN
            RETURN REPLACE(gen_random_uuid()::text, '-', '');
        END;
        \$\$ LANGUAGE plpgsql;
    ");
    
    echo "✅ Função lc_gerar_token_publico criada/atualizada!\n";
    
    // Verificar resultado final
    echo "\n4. Verificando resultado final...\n";
    $stmt = $pdo->query("SELECT COUNT(*) as total, COUNT(token_publico) as com_token FROM comercial_degustacoes");
    $result = $stmt->fetch();
    
    echo "   Total de degustações: {$result['total']}\n";
    echo "   Com token: {$result['com_token']}\n";
    echo "   Sem token: " . ($result['total'] - $result['com_token']) . "\n";
    
    echo "\n✅ Correção concluída com sucesso!\n";
    echo "\nAgora você pode:\n";
    echo "- Editar degustações\n";
    echo "- Publicar degustações (token será gerado automaticamente)\n";
    echo "- Ver links públicos após publicação\n";
    
} catch (Exception $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    http_response_code(500);
}
?>

