<?php
/**
 * setup_trello_inicial.php
 * Cria um quadro inicial com listas padrão para facilitar o início
 */

require_once __DIR__ . '/conexao.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1) {
    header('Location: login.php');
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Setup Inicial - Trello</title>
    <style>
        body { font-family: system-ui; max-width: 800px; margin: 2rem auto; padding: 2rem; }
        .success { background: #d1fae5; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        .error { background: #fee2e2; padding: 1rem; border-radius: 8px; margin: 1rem 0; }
        .btn { background: #3b82f6; color: white; padding: 0.75rem 1.5rem; border-radius: 6px; border: none; cursor: pointer; }
    </style>
</head>
<body>
    <h1>🔧 Setup Inicial - Sistema Trello</h1>
    
<?php
try {
    $pdo = $GLOBALS['pdo'];
    $usuario_id = (int)$_SESSION['user_id'];
    
    // Verificar se já existe quadro
    $stmt = $pdo->prepare("SELECT id FROM demandas_boards WHERE criado_por = :user_id LIMIT 1");
    $stmt->execute([':user_id' => $usuario_id]);
    $board_existente = $stmt->fetch();
    
    if ($board_existente) {
        echo '<div class="success">✅ Você já possui um quadro. <a href="index.php?page=trello">Acessar</a></div>';
    } else {
        $pdo->beginTransaction();
        
        // Criar quadro
        $stmt = $pdo->prepare("
            INSERT INTO demandas_boards (nome, descricao, criado_por, cor)
            VALUES (:nome, :desc, :user_id, '#3b82f6')
            RETURNING id
        ");
        $stmt->execute([
            ':nome' => 'Meu Quadro',
            ':desc' => 'Quadro inicial do sistema de demandas',
            ':user_id' => $usuario_id
        ]);
        $board_id = (int)$stmt->fetch(PDO::FETCH_ASSOC)['id'];
        
        // Criar listas padrão
        $listas = [
            ['nome' => '📋 Para Fazer', 'posicao' => 0],
            ['nome' => '🔄 Em Andamento', 'posicao' => 1],
            ['nome' => '✅ Feito', 'posicao' => 2]
        ];
        
        foreach ($listas as $lista) {
            $stmt = $pdo->prepare("
                INSERT INTO demandas_listas (board_id, nome, posicao)
                VALUES (:board_id, :nome, :posicao)
            ");
            $stmt->execute([
                ':board_id' => $board_id,
                ':nome' => $lista['nome'],
                ':posicao' => $lista['posicao']
            ]);
        }
        
        $pdo->commit();
        
        echo '<div class="success">✅ Quadro inicial criado com sucesso!</div>';
        echo '<p><a href="index.php?page=trello&board_id=' . $board_id . '" class="btn">Acessar Quadro</a></p>';
    }
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo '<div class="error">❌ Erro: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>

</body>
</html>
