<?php
// test_lista.php - Testar uma lista específica
require_once 'conexao.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo "❌ ID da lista não fornecido.";
    exit;
}

echo "<h1>🧪 Testando Lista ID: $id</h1>";

try {
    // 1. Buscar dados da lista
    $stmt = $pdo->prepare("
        SELECT l.*, u.nome AS criado_por_nome
        FROM smilee12_painel_smile.lc_listas l
        LEFT JOIN smilee12_painel_smile.usuarios u ON u.id = l.criado_por
        WHERE l.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $lista = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lista) {
        echo "<p style='color: red;'>❌ Lista não encontrada!</p>";
        exit;
    }
    
    echo "<h2>📋 Dados da Lista</h2>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    foreach ($lista as $key => $value) {
        echo "<tr><td><strong>$key</strong></td><td>$value</td></tr>";
    }
    echo "</table>";
    
    // 2. Buscar eventos vinculados
    echo "<h2>📅 Eventos Vinculados</h2>";
    $stmt = $pdo->prepare("SELECT * FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id ORDER BY id");
    $stmt->execute([':id' => $id]);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($eventos)) {
        echo "<p style='color: orange;'>⚠️ Nenhum evento vinculado a esta lista.</p>";
    } else {
        echo "<p>Total de eventos: " . count($eventos) . "</p>";
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>ID</th><th>Grupo ID</th><th>Espaço</th><th>Convidados</th><th>Evento</th><th>Data</th></tr>";
        foreach ($eventos as $evento) {
            echo "<tr>";
            echo "<td>{$evento['id']}</td>";
            echo "<td>{$evento['grupo_id']}</td>";
            echo "<td>{$evento['espaco']}</td>";
            echo "<td>{$evento['convidados']}</td>";
            echo "<td>{$evento['evento']}</td>";
            echo "<td>{$evento['data']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // 3. Calcular total de convidados
    echo "<h2>👥 Total de Convidados</h2>";
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(convidados),0) FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id");
    $stmt->execute([':id' => $id]);
    $tot_convidados = $stmt->fetchColumn();
    echo "<p><strong>Total: $tot_convidados convidados</strong></p>";
    
    // 4. Testar botões
    echo "<h2>🔗 Testar Botões</h2>";
    echo "<p>";
    echo "<a href='lc_ver.php?id=$id&tipo=" . $lista['tipo'] . "' target='_blank' style='margin-right: 10px; padding: 5px 10px; background: #007bff; color: white; text-decoration: none; border-radius: 3px;'>👁️ Ver Lista</a>";
    echo "<a href='lc_pdf.php?id=$id&tipo=" . $lista['tipo'] . "' target='_blank' style='margin-right: 10px; padding: 5px 10px; background: #28a745; color: white; text-decoration: none; border-radius: 3px;'>📄 PDF</a>";
    echo "<a href='lc_excluir.php?id=$id' onclick='return confirm(\"Tem certeza que deseja excluir esta lista?\")' style='padding: 5px 10px; background: #dc3545; color: white; text-decoration: none; border-radius: 3px;'>🗑️ Excluir</a>";
    echo "</p>";
    
    echo "<h2>✅ Teste Concluído!</h2>";
    echo "<p>Se os botões funcionarem, o problema está resolvido!</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . $e->getMessage() . "</p>";
}
?>
