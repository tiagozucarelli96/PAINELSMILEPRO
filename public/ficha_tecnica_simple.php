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
    
    // Atualizar custo total na receita (usar função do banco para evitar conflito com trigger)
    try {
        $pdo->prepare("UPDATE smilee12_painel_smile.lc_receitas SET custo_total = ? WHERE id = ?")
            ->execute([$custo_total, $receita_id]);
    } catch (Exception $e) {
        // Se der erro, tentar usar a função do banco
        try {
            $pdo->exec("SELECT smilee12_painel_smile.atualizar_custo_receita($receita_id)");
        } catch (Exception $e2) {
            // Ignorar erro do trigger se a função não existir
        }
    }
    
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Ficha Técnica Moderna */
        .ficha-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
        }

        .ficha-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 2rem;
            border-radius: 16px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .ficha-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 0.5rem 0;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .ficha-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin: 0;
        }

        .ficha-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin: 0 0 0.5rem 0;
        }

        .stat-label {
            font-size: 0.875rem;
            opacity: 0.8;
            margin: 0;
        }

        .ficha-content {
            display: grid;
            gap: 2rem;
        }

        .content-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }

        .card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-body {
            padding: 1.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
        }

        .form-input, .form-select {
            padding: 0.75rem;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.875rem;
            transition: all 0.2s;
            background: white;
        }

        .form-input:focus, .form-select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(59, 130, 246, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.4);
        }

        .btn-outline {
            background: white;
            color: #374151;
            border: 2px solid #e5e7eb;
        }

        .btn-outline:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .table th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
            font-size: 0.875rem;
        }

        .table td {
            padding: 1rem;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.875rem;
        }

        .table tr:hover {
            background: #f9fafb;
        }

        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .font-bold { font-weight: 700; }
        .text-green-600 { color: #059669; }
        .text-gray-600 { color: #6b7280; }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .ficha-container {
                padding: 1rem;
            }
            
            .ficha-title {
                font-size: 2rem;
            }
            
            .ficha-stats {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="main-layout">
    <div class="ficha-container">
        <!-- Header da Ficha -->
        <div class="ficha-header">
            <h1 class="ficha-title">🍳 Ficha Técnica</h1>
            <p class="ficha-subtitle"><?= htmlspecialchars($receita['nome']) ?></p>
            
            <!-- Estatísticas da Receita -->
            <div class="ficha-stats">
                <div class="stat-card">
                    <div class="stat-value"><?= htmlspecialchars($receita['categoria_nome'] ?? 'N/A') ?></div>
                    <div class="stat-label">Categoria</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= (int)$receita['rendimento'] ?></div>
                    <div class="stat-label">Porções</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?= number_format((float)$receita['quantia_por_pessoa'], 2, ',', '.') ?></div>
                    <div class="stat-label">Por Pessoa</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">R$ <?= number_format($custo_total, 2, ',', '.') ?></div>
                    <div class="stat-label">Custo Total</div>
                </div>
            </div>
        </div>
        
        <!-- Mensagens -->
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
        
        <div class="ficha-content">
        
            <!-- Adicionar Componente -->
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">➕ Adicionar Componente</h2>
                </div>
                <div class="card-body">
                    <form method="post" class="form-grid">
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
                            <input type="number" step="0.0001" name="quantidade" class="form-input" required placeholder="Ex: 1.5">
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
                        
                        <div class="form-group" style="grid-column: 1 / -1; display: flex; justify-content: flex-end;">
                            <button type="submit" class="btn btn-primary">
                                <span>➕</span> Adicionar Componente
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        
            <!-- Lista de Componentes -->
            <div class="content-card">
                <div class="card-header">
                    <h2 class="card-title">📝 Componentes da Receita</h2>
                </div>
                <div class="card-body">
                    <?php if (empty($componentes)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">📝</div>
                            <p>Nenhum componente adicionado ainda.</p>
                            <p class="text-gray-600">Use o formulário acima para adicionar ingredientes à receita.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Insumo</th>
                                        <th class="text-center">Quantidade</th>
                                        <th class="text-center">Unidade</th>
                                        <th class="text-right">Custo Unit.</th>
                                        <th class="text-right">Custo Total</th>
                                        <th>Observações</th>
                                        <th class="text-center">Ação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($componentes as $comp): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight: 600; color: #1e293b;">
                                                    <?= htmlspecialchars($comp['insumo_nome']) ?>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span style="font-weight: 600;">
                                                    <?= number_format($comp['quantidade'], 4, ',', '.') ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <span style="background: #f1f5f9; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                                    <?= htmlspecialchars($comp['unidade_simbolo']) ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span style="color: #6b7280;">
                                                    R$ <?= number_format($comp['custo_unitario'], 4, ',', '.') ?>
                                                </span>
                                            </td>
                                            <td class="text-right">
                                                <span style="font-weight: 700; color: #059669;">
                                                    R$ <?= number_format($comp['custo_total'], 4, ',', '.') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="color: #6b7280; font-size: 0.875rem;">
                                                    <?= htmlspecialchars($comp['observacoes']) ?>
                                                </span>
                                            </td>
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
        </div>
    </div>
</body>
</html>
