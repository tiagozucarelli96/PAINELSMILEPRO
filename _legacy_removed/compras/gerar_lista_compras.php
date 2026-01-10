<?php
// gerar_lista_compras.php - Módulo de geração de listas de compras
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';



// Processar formulário
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $evento_id = (int)input('evento_id');
        $itens_selecionados = $_POST['itens'] ?? [];
        
        if ($evento_id <= 0) {
            throw new Exception('Evento é obrigatório.');
        }
        
        if (empty($itens_selecionados)) {
            throw new Exception('Selecione pelo menos um item.');
        }
        
        // Aqui você pode salvar a lista gerada
        // Por enquanto, vamos apenas mostrar o resultado
        
        $msg = 'Lista de compras gerada com sucesso!';
        
    } catch (Exception $e) {
        $err = $e->getMessage();
    }
}

// Carregar dados
try {
    // Carregar eventos
    $eventos = $pdo->query("
        SELECT e.*, COUNT(li.id) as total_itens
        FROM smilee12_painel_smile.lc_listas_eventos e
        LEFT JOIN smilee12_painel_smile.lc_encomendas_itens li ON li.evento_id = e.id
        GROUP BY e.id
        ORDER BY e.data DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Carregar insumos visíveis por categoria
    $insumos_por_categoria = [];
    $insumos = $pdo->query("
        SELECT i.*, c.nome as categoria_nome, c.ordem as categoria_ordem,
               u.simbolo as unidade_simbolo, u.nome as unidade_nome
        FROM smilee12_painel_smile.lc_insumos i
        LEFT JOIN smilee12_painel_smile.lc_categorias c ON c.id = i.categoria_id
        LEFT JOIN smilee12_painel_smile.lc_unidades u ON u.simbolo = i.unidade_padrao
        WHERE i.ativo = true AND COALESCE(i.visivel, true) = true
        ORDER BY c.ordem ASC, c.nome ASC, i.nome ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar insumos por categoria
    foreach ($insumos as $insumo) {
        $categoria = $insumo['categoria_nome'] ?: 'Sem Categoria';
        $insumos_por_categoria[$categoria][] = $insumo;
    }
    
    // Carregar receitas visíveis por categoria
    $receitas_por_categoria = [];
    $receitas = $pdo->query("
        SELECT r.*, c.nome as categoria_nome, c.ordem as categoria_ordem
        FROM smilee12_painel_smile.lc_receitas r
        LEFT JOIN smilee12_painel_smile.lc_categorias c ON c.id = r.categoria_id
        WHERE r.ativo = true AND COALESCE(r.visivel, true) = true
        ORDER BY c.ordem ASC, c.nome ASC, r.nome ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Agrupar receitas por categoria
    foreach ($receitas as $receita) {
        $categoria = $receita['categoria_nome'] ?: 'Sem Categoria';
        $receitas_por_categoria[$categoria][] = $receita;
    }
    
} catch (Exception $e) {
    $err = 'Erro ao carregar dados: ' . $e->getMessage();
    $eventos = [];
    $insumos_por_categoria = [];
    $receitas_por_categoria = [];
}

function input($key, $default = '') {
    return isset($_POST[$key]) ? trim($_POST[$key]) : $default;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerar Lista de Compras - Painel Smile PRO</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .categoria-section {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .categoria-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #3b82f6;
        }
        
        .categoria-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e40af;
            margin: 0;
        }
        
        .categoria-count {
            background: #3b82f6;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .itens-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1rem;
        }
        
        .item-card {
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .item-card:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
            transform: translateY(-2px);
        }
        
        .item-card.selected {
            border-color: #10b981;
            background: #f0fdf4;
        }
        
        .item-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        
        .item-name {
            font-weight: 600;
            color: #1f2937;
            margin: 0;
            flex: 1;
        }
        
        .item-checkbox {
            width: 20px;
            height: 20px;
            accent-color: #10b981;
        }
        
        .item-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .item-detail {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .item-detail-icon {
            width: 16px;
            height: 16px;
        }
        
        .quantity-input {
            width: 80px;
            padding: 0.25rem 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            text-align: center;
            font-size: 0.875rem;
        }
        
        .quantity-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .evento-selector {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .summary-card {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 2rem;
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
        }
        
        .summary-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
        }
        
        .summary-stat {
            text-align: center;
        }
        
        .summary-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .summary-stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }
    </style>
</head>
<body class="panel has-sidebar">
    <?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container">
            <div class="page-header">
                <h1 class="page-title">🛒 Gerar Lista de Compras</h1>
                <p class="page-subtitle">Selecione os itens visíveis por categoria para gerar sua lista</p>
            </div>

            <?php if ($msg): ?>
                <div class="alert alert-success">
                    <span>✅</span> <?= h($msg) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($err): ?>
                <div class="alert alert-error">
                    <span>❌</span> <?= h($err) ?>
                </div>
            <?php endif; ?>

            <form method="post" id="listaForm">
                <!-- Seletor de Evento -->
                <div class="evento-selector">
                    <h2 class="text-xl font-bold mb-4">📅 Selecionar Evento</h2>
                    <select name="evento_id" id="eventoSelect" class="form-select" required>
                        <option value="">Escolha um evento...</option>
                        <?php foreach ($eventos as $evento): ?>
                            <option value="<?= $evento['id'] ?>">
                                <?= h($evento['evento']) ?> - <?= date('d/m/Y', strtotime($evento['data'])) ?>
                                (<?= $evento['total_itens'] ?> itens)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Insumos por Categoria -->
                <div class="mb-8">
                    <h2 class="text-2xl font-bold mb-6">📦 Insumos</h2>
                    
                    <?php if (empty($insumos_por_categoria)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <p>Nenhum insumo visível encontrado.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($insumos_por_categoria as $categoria => $itens): ?>
                            <div class="categoria-section">
                                <div class="categoria-header">
                                    <h3 class="categoria-title"><?= h($categoria) ?></h3>
                                    <span class="categoria-count"><?= count($itens) ?> itens</span>
                                </div>
                                
                                <div class="itens-grid">
                                    <?php foreach ($itens as $item): ?>
                                        <div class="item-card" onclick="toggleItem(this)">
                                            <div class="item-header">
                                                <h4 class="item-name"><?= h($item['nome']) ?></h4>
                                                <input type="checkbox" 
                                                       name="itens[insumo_<?= $item['id'] ?>]" 
                                                       value="<?= $item['id'] ?>" 
                                                       class="item-checkbox"
                                                       data-type="insumo"
                                                       data-id="<?= $item['id'] ?>"
                                                       data-nome="<?= h($item['nome']) ?>"
                                                       data-unidade="<?= h($item['unidade_simbolo'] ?: $item['unidade_nome']) ?>"
                                                       data-custo="<?= $item['custo_unit'] ?? 0 ?>">
                                            </div>
                                            
                                            <div class="item-details">
                                                <div class="item-detail">
                                                    <span class="item-detail-icon">📏</span>
                                                    <span><?= h($item['unidade_simbolo'] ?: $item['unidade_nome']) ?></span>
                                                </div>
                                                
                                                <?php if ($item['custo_unit']): ?>
                                                    <div class="item-detail">
                                                        <span class="item-detail-icon">💰</span>
                                                        <span>R$ <?= number_format($item['custo_unit'], 2, ',', '.') ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="item-detail">
                                                    <span class="item-detail-icon">🏷️</span>
                                                    <span><?= ucfirst($item['aquisicao'] ?? 'Mercado') ?></span>
                                                </div>
                                                
                                                <div class="item-detail">
                                                    <span class="item-detail-icon">🔢</span>
                                                    <input type="number" 
                                                           class="quantity-input" 
                                                           placeholder="Qtd" 
                                                           min="0" 
                                                           step="0.001"
                                                           data-item-id="<?= $item['id'] ?>"
                                                           data-item-type="insumo">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Receitas por Categoria -->
                <div class="mb-8">
                    <h2 class="text-2xl font-bold mb-6">🍳 Receitas</h2>
                    
                    <?php if (empty($receitas_por_categoria)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <p>Nenhuma receita visível encontrada.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($receitas_por_categoria as $categoria => $itens): ?>
                            <div class="categoria-section">
                                <div class="categoria-header">
                                    <h3 class="categoria-title"><?= h($categoria) ?></h3>
                                    <span class="categoria-count"><?= count($itens) ?> receitas</span>
                                </div>
                                
                                <div class="itens-grid">
                                    <?php foreach ($itens as $item): ?>
                                        <div class="item-card" onclick="toggleItem(this)">
                                            <div class="item-header">
                                                <h4 class="item-name"><?= h($item['nome']) ?></h4>
                                                <input type="checkbox" 
                                                       name="itens[receita_<?= $item['id'] ?>]" 
                                                       value="<?= $item['id'] ?>" 
                                                       class="item-checkbox"
                                                       data-type="receita"
                                                       data-id="<?= $item['id'] ?>"
                                                       data-nome="<?= h($item['nome']) ?>"
                                                       data-rendimento="<?= $item['rendimento'] ?>"
                                                       data-por-pessoa="<?= $item['quantia_por_pessoa'] ?>">
                                            </div>
                                            
                                            <div class="item-details">
                                                <div class="item-detail">
                                                    <span class="item-detail-icon">👥</span>
                                                    <span><?= $item['rendimento'] ?> porções</span>
                                                </div>
                                                
                                                <div class="item-detail">
                                                    <span class="item-detail-icon">🍽️</span>
                                                    <span><?= number_format($item['quantia_por_pessoa'], 3, ',', '.') ?> por pessoa</span>
                                                </div>
                                                
                                                <?php if ($item['custo_total']): ?>
                                                    <div class="item-detail">
                                                        <span class="item-detail-icon">💰</span>
                                                        <span>R$ <?= number_format($item['custo_total'], 2, ',', '.') ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="item-detail">
                                                    <span class="item-detail-icon">🔢</span>
                                                    <input type="number" 
                                                           class="quantity-input" 
                                                           placeholder="Qtd" 
                                                           min="0" 
                                                           step="0.001"
                                                           data-item-id="<?= $item['id'] ?>"
                                                           data-item-type="receita">
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Resumo da Seleção -->
                <div class="summary-card" id="summaryCard" style="display: none;">
                    <h3 class="summary-title">📊 Resumo da Seleção</h3>
                    <div class="summary-stats">
                        <div class="summary-stat">
                            <div class="summary-stat-value" id="totalItens">0</div>
                            <div class="summary-stat-label">Itens Selecionados</div>
                        </div>
                        <div class="summary-stat">
                            <div class="summary-stat-value" id="totalInsumos">0</div>
                            <div class="summary-stat-label">Insumos</div>
                        </div>
                        <div class="summary-stat">
                            <div class="summary-stat-value" id="totalReceitas">0</div>
                            <div class="summary-stat-label">Receitas</div>
                        </div>
                        <div class="summary-stat">
                            <div class="summary-stat-value" id="custoEstimado">R$ 0,00</div>
                            <div class="summary-stat-label">Custo Estimado</div>
                        </div>
                    </div>
                </div>

                <!-- Botões de Ação -->
                <div class="flex gap-4 justify-center mt-8">
                    <button type="button" onclick="calcularLista()" class="btn btn-info">
                        <span>🧮</span> Calcular Lista
                    </button>
                    <button type="submit" class="btn btn-success" id="gerarBtn" disabled>
                        <span>📋</span> Gerar Lista de Compras
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleItem(card) {
            const checkbox = card.querySelector('.item-checkbox');
            checkbox.checked = !checkbox.checked;
            
            if (checkbox.checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
            
            updateSummary();
        }
        
        function updateSummary() {
            const checkboxes = document.querySelectorAll('.item-checkbox:checked');
            const insumos = document.querySelectorAll('.item-checkbox[data-type="insumo"]:checked');
            const receitas = document.querySelectorAll('.item-checkbox[data-type="receita"]:checked');
            
            let custoTotal = 0;
            
            // Calcular custo dos insumos
            insumos.forEach(checkbox => {
                const custo = parseFloat(checkbox.dataset.custo) || 0;
                const quantidade = parseFloat(checkbox.closest('.item-card').querySelector('.quantity-input').value) || 1;
                custoTotal += custo * quantidade;
            });
            
            // Calcular custo das receitas
            receitas.forEach(checkbox => {
                const custo = parseFloat(checkbox.dataset.custo) || 0;
                const quantidade = parseFloat(checkbox.closest('.item-card').querySelector('.quantity-input').value) || 1;
                custoTotal += custo * quantidade;
            });
            
            document.getElementById('totalItens').textContent = checkboxes.length;
            document.getElementById('totalInsumos').textContent = insumos.length;
            document.getElementById('totalReceitas').textContent = receitas.length;
            document.getElementById('custoEstimado').textContent = 'R$ ' + custoTotal.toLocaleString('pt-BR', {minimumFractionDigits: 2});
            
            const summaryCard = document.getElementById('summaryCard');
            const gerarBtn = document.getElementById('gerarBtn');
            
            if (checkboxes.length > 0) {
                summaryCard.style.display = 'block';
                gerarBtn.disabled = false;
            } else {
                summaryCard.style.display = 'none';
                gerarBtn.disabled = true;
            }
        }
        
        function calcularLista() {
            const checkboxes = document.querySelectorAll('.item-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Selecione pelo menos um item para calcular.');
                return;
            }
            
            // Aqui você pode implementar a lógica de cálculo automático
            // Por exemplo, calcular quantidades baseadas no número de pessoas do evento
            alert('Funcionalidade de cálculo automático será implementada em breve!');
        }
        
        // Atualizar resumo quando quantidade mudar
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('quantity-input')) {
                updateSummary();
            }
        });
        
        // Atualizar resumo quando checkbox mudar
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('item-checkbox')) {
                const card = e.target.closest('.item-card');
                if (e.target.checked) {
                    card.classList.add('selected');
                } else {
                    card.classList.remove('selected');
                }
                updateSummary();
            }
        });
    </script>
</body>
</html>
