<?php
// ficha_tecnica_simple.php - Ficha técnica simplificada
require_once 'conexao.php';

$receita_id = (int)($_GET['id'] ?? 0);
if (!$receita_id) {
    echo '<div class="alert alert-error">ID da receita não fornecido.</div>';
    exit;
}

$msg = '';
$err = '';

try {
    // Carregar receita
    $receita = $pdo->prepare("
        SELECT r.*, c.nome AS categoria_nome
        FROM smilee12_painel_smile.lc_receitas r
        LEFT JOIN smilee12_painel_smile.lc_categorias c ON c.id = r.categoria_id
        WHERE r.id = ?
    ");
    $receita->execute([$receita_id]);
    $receita = $receita->fetch(PDO::FETCH_ASSOC);
    
    if (!$receita) {
        throw new Exception('Receita não encontrada.');
    }
    
    // Processar ações
    if ($_POST) {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_componente') {
            $insumo_id = (int)($_POST['insumo_id'] ?? 0);
            $quantidade = (float)str_replace(',', '.', $_POST['quantidade'] ?? '0');
            $unidade_id = (int)($_POST['unidade_id'] ?? 0);
            $observacoes = $_POST['observacoes'] ?? '';
            
            if (!$insumo_id) throw new Exception('Insumo é obrigatório.');
            if ($quantidade <= 0) throw new Exception('Quantidade deve ser maior que zero.');
            
            // Validar se a unidade existe (se foi selecionada)
            if ($unidade_id > 0) {
                $unidade_check = $pdo->prepare("SELECT id FROM smilee12_painel_smile.lc_unidades WHERE id = ?");
                $unidade_check->execute([$unidade_id]);
                if (!$unidade_check->fetchColumn()) {
                    throw new Exception('Unidade selecionada não existe.');
                }
            } else {
                // Se não foi selecionada unidade, usar NULL
                $unidade_id = null;
            }
            
            // Buscar custo unitário do insumo
            $insumo = $pdo->prepare("SELECT custo_unit FROM smilee12_painel_smile.lc_insumos WHERE id = ?");
            $insumo->execute([$insumo_id]);
            $custo_unitario = $insumo->fetchColumn() ?: 0;
            
            $custo_total = $quantidade * $custo_unitario;
            
            $stmt = $pdo->prepare("
                INSERT INTO smilee12_painel_smile.lc_receita_componentes 
                (receita_id, insumo_id, quantidade, unidade_id, custo_unitario, custo_total, observacoes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$receita_id, $insumo_id, $quantidade, $unidade_id, $custo_unitario, $custo_total, $observacoes]);
            
            $msg = 'Componente adicionado à receita.';
        }
        
        if ($action === 'delete_componente') {
            $componente_id = (int)($_POST['componente_id'] ?? 0);
            
            if (!$componente_id) throw new Exception('ID do componente é obrigatório.');
            
            $stmt = $pdo->prepare("DELETE FROM smilee12_painel_smile.lc_receita_componentes WHERE id = ? AND receita_id = ?");
            $stmt->execute([$componente_id, $receita_id]);
            
            $msg = 'Componente removido da receita.';
        }
    }
    
    // Carregar componentes da receita
    $componentes = $pdo->prepare("
        SELECT rc.*, i.nome AS insumo_nome, i.custo_unit, u.simbolo AS unidade_simbolo
        FROM smilee12_painel_smile.lc_receita_componentes rc
        LEFT JOIN smilee12_painel_smile.lc_insumos i ON i.id = rc.insumo_id
        LEFT JOIN smilee12_painel_smile.lc_unidades u ON u.id = rc.unidade_id
        WHERE rc.receita_id = ?
        ORDER BY rc.ordem, i.nome
    ");
    $componentes->execute([$receita_id]);
    $componentes = $componentes->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular custo total
    $custo_total = array_sum(array_column($componentes, 'custo_total'));
    
    // Atualizar custo total na receita
    $pdo->prepare("UPDATE smilee12_painel_smile.lc_receitas SET custo_total = ? WHERE id = ?")
        ->execute([$custo_total, $receita_id]);
    
    // Carregar insumos disponíveis
    $insumos = $pdo->query("
        SELECT i.*, c.nome AS categoria_nome
        FROM smilee12_painel_smile.lc_insumos i
        LEFT JOIN smilee12_painel_smile.lc_categorias c ON c.id = i.categoria_id
        ORDER BY c.nome, i.nome
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Carregar unidades
    $unidades = $pdo->query("
        SELECT * FROM smilee12_painel_smile.lc_unidades WHERE ativo = true ORDER BY nome
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $err = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ficha Técnica - <?= htmlspecialchars($receita['nome']) ?></title>
    <link rel="stylesheet" href="estilo.css">
</head>
<body class="main-layout">
    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">🍳 Ficha Técnica</h1>
            <p class="page-subtitle"><?= htmlspecialchars($receita['nome']) ?></p>
        </div>
        
        <?php if ($msg): ?>
            <div class="alert alert-success">
                <span>✅</span> <?= htmlspecialchars($msg) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($err): ?>
            <div class="alert alert-error">
                <span>❌</span> <?= htmlspecialchars($err) ?>
            </div>
        <?php endif; ?>
        
        <!-- Informações da Receita -->
        <div class="card mb-6">
            <div class="card-header">
                <h2 class="card-title">📋 Informações da Receita</h2>
            </div>
            <div class="card-body">
                <div class="grid grid-cols-4 gap-4">
                    <div>
                        <label class="form-label">Categoria</label>
                        <div class="text-lg"><?= htmlspecialchars($receita['categoria_nome'] ?? 'Sem categoria') ?></div>
                    </div>
                    <div>
                        <label class="form-label">Rendimento</label>
                        <div class="text-lg"><?= (int)$receita['rendimento'] ?> porções</div>
                    </div>
                    <div>
                        <label class="form-label">Quantidade por Pessoa</label>
                        <div class="text-lg"><?= number_format((float)$receita['quantia_por_pessoa'], 2, ',', '.') ?></div>
                    </div>
                    <div>
                        <label class="form-label">Custo Total</label>
                        <div class="text-lg font-bold text-green-600">
                            R$ <?= number_format($custo_total, 4, ',', '.') ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Adicionar Componente -->
        <div class="card mb-6">
            <div class="card-header">
                <h2 class="card-title">➕ Adicionar Componente</h2>
            </div>
            <div class="card-body">
                <form method="post" class="grid grid-cols-4 gap-4">
                    <input type="hidden" name="action" value="add_componente">
                    
                    <div class="form-group">
                        <label class="form-label">Insumo *</label>
                        <select name="insumo_id" class="form-select" required>
                            <option value="">Selecione um insumo...</option>
                            <?php foreach ($insumos as $i): ?>
                                <option value="<?= $i['id'] ?>">
                                    <?= htmlspecialchars($i['categoria_nome'] ?? 'Sem categoria') ?> - <?= htmlspecialchars($i['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Quantidade *</label>
                        <input type="number" step="0.0001" name="quantidade" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Unidade</label>
                        <select name="unidade_id" class="form-select">
                            <option value="">Selecione...</option>
                            <?php foreach ($unidades as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['simbolo']) ?> (<?= htmlspecialchars($u['nome']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Observações</label>
                        <input type="text" name="observacoes" class="form-input" placeholder="Opcional">
                    </div>
                    
                    <div class="col-span-4">
                        <button type="submit" class="btn btn-primary">
                            <span>➕</span> Adicionar Componente
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Componentes -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">📝 Componentes da Receita</h2>
            </div>
            <div class="card-body">
                <?php if (empty($componentes)): ?>
                    <p class="text-gray-600">Nenhum componente adicionado ainda.</p>
                <?php else: ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Insumo</th>
                                    <th>Quantidade</th>
                                    <th>Unidade</th>
                                    <th>Custo Unit.</th>
                                    <th>Custo Total</th>
                                    <th>Observações</th>
                                    <th class="text-center">Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($componentes as $comp): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($comp['insumo_nome']) ?></td>
                                        <td class="text-center"><?= number_format($comp['quantidade'], 4, ',', '.') ?></td>
                                        <td class="text-center"><?= htmlspecialchars($comp['unidade_simbolo']) ?></td>
                                        <td class="text-right">R$ <?= number_format($comp['custo_unitario'], 4, ',', '.') ?></td>
                                        <td class="text-right font-bold">R$ <?= number_format($comp['custo_total'], 4, ',', '.') ?></td>
                                        <td><?= htmlspecialchars($comp['observacoes']) ?></td>
                                        <td class="text-center">
                                            <form method="post" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_componente">
                                                <input type="hidden" name="componente_id" value="<?= $comp['id'] ?>">
                                                <button type="submit" class="btn btn-outline btn-sm" 
                                                        onclick="return confirm('Tem certeza que deseja remover este componente?')">
                                                    <span>🗑️</span> Remover
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</body>
</html>
