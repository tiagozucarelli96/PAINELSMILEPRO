<?php
/**
 * TESTE ULTRA-SIMPLES - Bypass total do sistema
 * Acesse diretamente: test_relatorio_simples.php?degustacao_id=17
 */
session_start();
require_once __DIR__ . '/conexao.php';

$degustacao_id = (int)($_GET['degustacao_id'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>TESTE - Relatório Degustação</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f0f0f0; }
        .box { background: white; padding: 20px; margin: 10px; border-radius: 8px; }
        .success { background: #d4edda; border: 2px solid #28a745; }
        .error { background: #f8d7da; border: 2px solid #dc3545; }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>
    <h1>🧪 TESTE ULTRA-SIMPLES - Relatório Degustação</h1>
    
    <div class="box">
        <h2>📋 Informações da Requisição</h2>
        <p><strong>REQUEST_URI:</strong> <code><?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'N/A') ?></code></p>
        <p><strong>QUERY_STRING:</strong> <code><?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? 'N/A') ?></code></p>
        <p><strong>$_GET:</strong> <code><?= htmlspecialchars(json_encode($_GET, JSON_UNESCAPED_UNICODE)) ?></code></p>
        <p><strong>degustacao_id recebido:</strong> <code><?= $degustacao_id ?></code></p>
    </div>
    
    <?php if ($degustacao_id > 0): ?>
        <div class="box success">
            <h2>✅ degustacao_id Encontrado!</h2>
            <p>Valor: <strong><?= $degustacao_id ?></strong></p>
            <?php
            try {
                $pdo = $GLOBALS['pdo'];
                $stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE id = :id");
                $stmt->execute([':id' => $degustacao_id]);
                $deg = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($deg) {
                    echo "<h3>Degustação Encontrada:</h3>";
                    echo "<p><strong>Nome:</strong> " . htmlspecialchars($deg['nome']) . "</p>";
                    echo "<p><strong>Data:</strong> " . htmlspecialchars($deg['data'] ?? 'N/A') . "</p>";
                    
                    // Buscar inscritos
                    $col_check = $pdo->query("
                        SELECT column_name FROM information_schema.columns
                        WHERE table_name = 'comercial_inscricoes' 
                        AND column_name IN ('degustacao_id', 'event_id') LIMIT 1
                    ")->fetch(PDO::FETCH_ASSOC);
                    
                    $col = $col_check ? $col_check['column_name'] : 'degustacao_id';
                    
                    $stmt = $pdo->prepare("
                        SELECT id, nome, qtd_pessoas FROM comercial_inscricoes 
                        WHERE {$col} = :id AND status = 'confirmado'
                    ");
                    $stmt->execute([':id' => $degustacao_id]);
                    $inscritos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo "<p><strong>Inscritos confirmados:</strong> " . count($inscritos) . "</p>";
                    echo "<p><strong>Total de pessoas:</strong> " . array_sum(array_column($inscritos, 'qtd_pessoas')) . "</p>";
                } else {
                    echo "<p class='error'>Degustação não encontrada no banco!</p>";
                }
            } catch (Exception $e) {
                echo "<p class='error'>Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            ?>
        </div>
    <?php else: ?>
        <div class="box error">
            <h2>❌ degustacao_id NÃO encontrado</h2>
            <p>Teste acessando: <code>test_relatorio_simples.php?degustacao_id=17</code></p>
        </div>
    <?php endif; ?>
    
    <div class="box">
        <h2>🔧 Teste Manual</h2>
        <form method="GET" action="test_relatorio_simples.php">
            <label>degustacao_id: <input type="number" name="degustacao_id" value="<?= $degustacao_id ?>" required></label>
            <button type="submit">Testar</button>
        </form>
    </div>
    
    <div class="box">
        <h2>🔗 Links para Teste</h2>
        <ul>
            <li><a href="test_relatorio_simples.php?degustacao_id=17">test_relatorio_simples.php?degustacao_id=17</a></li>
            <li><a href="comercial_realizar_degustacao_direto.php?degustacao_id=17">comercial_realizar_degustacao_direto.php?degustacao_id=17</a></li>
            <li><a href="index.php?page=comercial_realizar_degustacao_direto&degustacao_id=17">Via router: index.php?page=comercial_realizar_degustacao_direto&degustacao_id=17</a></li>
        </ul>
    </div>
</body>
</html>

