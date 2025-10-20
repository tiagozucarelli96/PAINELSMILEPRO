<?php
// lc_excluir.php - Excluir lista de compras/encomendas
require_once 'conexao.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: lc_index.php?erro=ID inválido');
    exit;
}

try {
    // Verificar se a lista existe
    $stmt = $pdo->prepare("SELECT id, tipo FROM smilee12_painel_smile.lc_listas WHERE id = ?");
    $stmt->execute([$id]);
    $lista = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lista) {
        header('Location: lc_index.php?erro=Lista não encontrada');
        exit;
    }
    
    // Excluir eventos vinculados primeiro
    $pdo->prepare("DELETE FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = ?")->execute([$id]);
    
    // Excluir itens de compras consolidadas
    $pdo->prepare("DELETE FROM smilee12_painel_smile.lc_compras_consolidadas WHERE lista_id = ?")->execute([$id]);
    
    // Excluir itens de encomendas
    $pdo->prepare("DELETE FROM smilee12_painel_smile.lc_encomendas_itens WHERE lista_id = ?")->execute([$id]);
    
    // Excluir a lista principal
    $pdo->prepare("DELETE FROM smilee12_painel_smile.lc_listas WHERE id = ?")->execute([$id]);
    
    // Redirecionar com sucesso
    header('Location: lc_index.php?sucesso=Lista excluída com sucesso');
    exit;
    
} catch (Exception $e) {
    // Em caso de erro, redirecionar com mensagem de erro
    header('Location: lc_index.php?erro=' . urlencode('Erro ao excluir: ' . $e->getMessage()));
    exit;
}
?>
