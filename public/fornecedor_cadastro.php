<?php
// fornecedor_cadastro.php
// Processar cadastro de fornecedores

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar se é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: lc_index.php');
    exit;
}

try {
    // Validar dados
    $nome = trim($_POST['nome'] ?? '');
    $cnpj = trim($_POST['cnpj'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contato_responsavel = trim($_POST['contato_responsavel'] ?? '');
    $endereco = trim($_POST['endereco'] ?? '');
    
    if (empty($nome)) {
        throw new Exception('Nome do fornecedor é obrigatório');
    }
    
    // Verificar se já existe fornecedor com mesmo nome
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM fornecedores WHERE nome = ? AND ativo = true");
    $stmt->execute([$nome]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('Já existe um fornecedor com este nome');
    }
    
    // Inserir fornecedor
    $stmt = $pdo->prepare("
        INSERT INTO fornecedores 
        (nome, cnpj, telefone, email, endereco, contato_responsavel, ativo)
        VALUES (?, ?, ?, ?, ?, ?, true)
    ");
    
    $stmt->execute([
        $nome,
        $cnpj ?: null,
        $telefone ?: null,
        $email ?: null,
        $endereco ?: null,
        $contato_responsavel ?: null
    ]);
    
    $fornecedor_id = $pdo->lastInsertId();
    
    // Sucesso - redirecionar com mensagem
    header('Location: lc_index.php?sucesso=fornecedor_cadastrado&id=' . $fornecedor_id);
    exit;
    
} catch (Exception $e) {
    // Erro - redirecionar com mensagem de erro
    header('Location: lc_index.php?erro=' . urlencode($e->getMessage()));
    exit;
}
?>
