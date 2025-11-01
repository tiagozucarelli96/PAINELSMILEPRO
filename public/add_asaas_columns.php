<?php
// Script para adicionar colunas Asaas na tabela comercial_inscricoes
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_enhanced.php';

// Verificar permissões
if (!lc_can_manage_inscritos()) {
    die('Sem permissão');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Adicionar Colunas Asaas</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
        .success { color: green; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .error { color: red; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
        .info { color: blue; background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>🔧 Adicionar Colunas Asaas à Tabela comercial_inscricoes</h1>
    
<?php
try {
    // Verificar se asaas_payment_id existe
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_schema = 'public' 
                         AND table_name = 'comercial_inscricoes' 
                         AND column_name = 'asaas_payment_id'");
    $has_asaas_payment_id = $stmt->rowCount() > 0;
    
    if (!$has_asaas_payment_id) {
        echo "<div class='info'>📝 Adicionando coluna asaas_payment_id...</div>";
        $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN asaas_payment_id VARCHAR(255)");
        echo "<div class='success'>✅ Coluna asaas_payment_id adicionada com sucesso!</div>";
    } else {
        echo "<div class='info'>ℹ️ Coluna asaas_payment_id já existe.</div>";
    }
    
    // Verificar se valor_pago existe
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_schema = 'public' 
                         AND table_name = 'comercial_inscricoes' 
                         AND column_name = 'valor_pago'");
    $has_valor_pago = $stmt->rowCount() > 0;
    
    if (!$has_valor_pago) {
        echo "<div class='info'>📝 Adicionando coluna valor_pago...</div>";
        $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN valor_pago NUMERIC(10,2)");
        echo "<div class='success'>✅ Coluna valor_pago adicionada com sucesso!</div>";
    } else {
        echo "<div class='info'>ℹ️ Coluna valor_pago já existe.</div>";
    }
    
    // Verificar se compareceu existe
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                         WHERE table_schema = 'public' 
                         AND table_name = 'comercial_inscricoes' 
                         AND column_name = 'compareceu'");
    $has_compareceu = $stmt->rowCount() > 0;
    
    if (!$has_compareceu) {
        echo "<div class='info'>📝 Adicionando coluna compareceu...</div>";
        $pdo->exec("ALTER TABLE comercial_inscricoes ADD COLUMN compareceu BOOLEAN DEFAULT FALSE");
        echo "<div class='success'>✅ Coluna compareceu adicionada com sucesso!</div>";
    } else {
        echo "<div class='info'>ℹ️ Coluna compareceu já existe.</div>";
    }
    
    echo "<div class='success'><strong>✨ Todas as colunas estão atualizadas!</strong></div>";
    echo "<p><a href='index.php?page=comercial_degust_inscritos&event_id=1'>← Voltar para Inscrições</a></p>";
    
} catch (PDOException $e) {
    echo "<div class='error'>❌ Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
}
?>
</body>
</html>

