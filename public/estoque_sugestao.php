<?php
// estoque_sugestao.php
// Modal de sugestão de compra para insumos críticos

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_calc.php';
require_once __DIR__ . '/lc_units_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'OPER'])) {
    header('Location: estoque_alertas.php?error=permission_denied');
    exit;
}

$msg = '';
$err = '';
$dados_sugestao = null;

// Parâmetros padrão
$parametros = [
    'horizonte_dias' => 7,
    'lead_time_dias' => 2,
    'estoque_seguranca_percent' => 10,
    'agrupamento' => 'fornecedor_consolidado',
    'arredondar_embalagem' => true,
    'permitir_substitutos' => false,
    'periodo_demanda' => 'eventos_futuros'
];

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    try {
        if ($acao === 'calcular') {
            // Capturar parâmetros
            $parametros['horizonte_dias'] = (int)($_POST['horizonte_dias'] ?? 7);
            $parametros['lead_time_dias'] = (int)($_POST['lead_time_dias'] ?? 2);
            $parametros['estoque_seguranca_percent'] = (float)($_POST['estoque_seguranca_percent'] ?? 10);
            $parametros['agrupamento'] = $_POST['agrupamento'] ?? 'fornecedor_consolidado';
            $parametros['arredondar_embalagem'] = isset($_POST['arredondar_embalagem']);
            $parametros['permitir_substitutos'] = isset($_POST['permitir_substitutos']);
            $parametros['periodo_demanda'] = $_POST['periodo_demanda'] ?? 'eventos_futuros';
            
            // Insumos selecionados
            $insumos_selecionados = $_POST['insumos_selecionados'] ?? [];
            
            if (empty($insumos_selecionados)) {
                throw new Exception('Selecione pelo menos um insumo.');
            }
            
            // Calcular sugestões
            $dados_sugestao = calcularSugestoes($pdo, $insumos_selecionados, $parametros);
            
        } elseif ($acao === 'salvar_rascunho') {
            // Salvar como rascunho
            $sugestoes = json_decode($_POST['sugestoes_json'], true);
            $parametros_json = json_decode($_POST['parametros_json'], true);
            
            if (empty($sugestoes)) {
                throw new Exception('Nenhuma sugestão para salvar.');
            }
            
            // Criar lista de sugestão
            $lista_id = criarListaSugestao($pdo, $sugestoes, $parametros_json);
            
            $msg = 'Sugestão salva como rascunho!';
            header("Location: lc_ver.php?id=$lista_id&tipo=compras");
            exit;
        }
    } catch (Exception $e) {
        $err = 'Erro: ' . $e->getMessage();
    }
}

// Carregar insumos críticos
$insumos_criticos = [];
if (isset($_GET['insumo_id'])) {
    // Insumo específico
    $insumo_id = (int)$_GET['insumo_id'];
    $stmt = $pdo->prepare("
        SELECT i.*, f.nome as fornecedor_nome, c.nome as categoria_nome, u.simbolo as unidade_simbolo
        FROM lc_insumos i
        LEFT JOIN fornecedores f ON f.id = i.fornecedor_id
        LEFT JOIN lc_categorias c ON c.id = i.categoria_id
        LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
        WHERE i.id = :id AND i.ativo = true
    ");
    $stmt->execute([':id' => $insumo_id]);
    $insumo = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($insumo) {
        $insumos_criticos[] = $insumo;
    }
} else {
    // Todos os críticos
    $stmt = $pdo->query("
        SELECT i.*, f.nome as fornecedor_nome, c.nome as categoria_nome, u.simbolo as unidade_simbolo
        FROM lc_insumos i
        LEFT JOIN fornecedores f ON f.id = i.fornecedor_id
        LEFT JOIN lc_categorias c ON c.id = i.categoria_id
        LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
        WHERE i.ativo = true AND i.estoque_atual <= i.estoque_minimo
        ORDER BY i.nome
    ");
    $insumos_criticos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Calcular sugestões de compra
 */
function calcularSugestoes(PDO $pdo, array $insumos_ids, array $parametros): array {
    $sugestoes = [];
    $horizonte_total = $parametros['horizonte_dias'] + $parametros['lead_time_dias'];
    
    foreach ($insumos_ids as $insumo_id) {
        // Buscar dados do insumo
        $stmt = $pdo->prepare("
            SELECT i.*, f.nome as fornecedor_nome, u.simbolo as unidade_simbolo
            FROM lc_insumos i
            LEFT JOIN fornecedores f ON f.id = i.fornecedor_id
            LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
            WHERE i.id = :id
        ");
        $stmt->execute([':id' => $insumo_id]);
        $insumo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$insumo) continue;
        
        // Saldo atual (última contagem fechada)
        $saldo_atual = (float)$insumo['estoque_atual'];
        
        // Calcular demanda projetada
        $demanda = calcularDemanda($pdo, $insumo_id, $horizonte_total, $parametros['periodo_demanda']);
        
        // Estoque de segurança
        $seguranca = $demanda * ($parametros['estoque_seguranca_percent'] / 100);
        
        // Necessidade bruta
        $necessidade = $demanda + $seguranca - $saldo_atual;
        
        if ($necessidade <= 0) continue; // Já coberto
        
        // Arredondamento por embalagem
        $sugerido = $necessidade;
        if ($parametros['arredondar_embalagem'] && $insumo['embalagem_multiplo'] > 0) {
            $sugerido = ceil($necessidade / $insumo['embalagem_multiplo']) * $insumo['embalagem_multiplo'];
        } else {
            $sugerido = ceil($necessidade * 100) / 100; // Arredondar para cima
        }
        
        // Custo estimado
        $custo_unitario = (float)$insumo['preco'] * (float)($insumo['fator_correcao'] ?? 1.0);
        $custo_total = $sugerido * $custo_unitario;
        
        $sugestoes[] = [
            'insumo_id' => $insumo_id,
            'insumo_nome' => $insumo['nome'],
            'unidade' => $insumo['unidade_simbolo'],
            'fornecedor_id' => $insumo['fornecedor_id'],
            'fornecedor_nome' => $insumo['fornecedor_nome'] ?: '(Definir)',
            'saldo_atual' => $saldo_atual,
            'demanda' => $demanda,
            'seguranca' => $seguranca,
            'necessidade' => $necessidade,
            'sugerido' => $sugerido,
            'custo_unitario' => $custo_unitario,
            'custo_total' => $custo_total,
            'tem_preco' => $custo_unitario > 0
        ];
    }
    
    return $sugestoes;
}

/**
 * Calcular demanda projetada
 */
function calcularDemanda(PDO $pdo, int $insumo_id, int $dias, string $periodo): float {
    if ($periodo === 'eventos_futuros') {
        // Buscar eventos futuros no período
        $data_fim = date('Y-m-d', strtotime("+$dias days"));
        
        $stmt = $pdo->prepare("
            SELECT le.id, le.data_evento, le.convidados
            FROM lc_listas_eventos le
            WHERE le.data_evento BETWEEN :hoje AND :data_fim
            ORDER BY le.data_evento
        ");
        $stmt->execute([':hoje' => date('Y-m-d'), ':data_fim' => $data_fim]);
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $demanda_total = 0;
        foreach ($eventos as $evento) {
            // Buscar cardápio do evento
            $stmt = $pdo->prepare("
                SELECT ec.ficha_id, ec.consumo_pessoa_override
                FROM lc_evento_cardapio ec
                WHERE ec.evento_id = :evento_id AND ec.ativo = true
            ");
            $stmt->execute([':evento_id' => $evento['id']]);
            $cardapio = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($cardapio as $item_cardapio) {
                // Carregar ficha
                $pack = lc_fetch_ficha($pdo, $item_cardapio['ficha_id']);
                if (!$pack) continue;
                
                // Aplicar override se existir
                if ($item_cardapio['consumo_pessoa_override']) {
                    $pack['ficha']['consumo_pessoa'] = (float)$item_cardapio['consumo_pessoa_override'];
                }
                
                // Explodir ficha para o evento
                $res = lc_explode_ficha_para_evento($pack, (int)$evento['convidados']);
                
                // Somar compras (não encomendas)
                if (isset($res['compras'])) {
                    foreach ($res['compras'] as $compra) {
                        if ($compra['insumo_id'] == $insumo_id) {
                            $demanda_total += (float)$compra['qtd'];
                        }
                    }
                }
            }
        }
        
        return $demanda_total;
    } else {
        // Média móvel (implementação futura)
        return 0;
    }
}

/**
 * Criar lista de sugestão
 */
function criarListaSugestao(PDO $pdo, array $sugestoes, array $parametros): int {
    // Criar cabeçalho da lista
    $resumo = "Sugestão automática (Horizonte: {$parametros['horizonte_dias']}d | Lead time: {$parametros['lead_time_dias']}d | Segurança: {$parametros['estoque_seguranca_percent']}%)";
    
    $stmt = $pdo->prepare("
        INSERT INTO lc_listas (tipo_lista, data_gerada, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome, status, resumo_eventos)
        VALUES ('compras', NOW(), 'Sugestão Automática', :resumo, :criado_por, :criado_por_nome, 'rascunho', :resumo)
    ");
    $stmt->execute([
        ':resumo' => $resumo,
        ':criado_por' => $_SESSION['usuario_id'] ?? 1,
        ':criado_por_nome' => $_SESSION['usuario_nome'] ?? 'Sistema'
    ]);
    
    $lista_id = $pdo->lastInsertId();
    
    // Inserir itens de compra
    foreach ($sugestoes as $sugestao) {
        $stmt = $pdo->prepare("
            INSERT INTO lc_compras_consolidadas 
            (lista_id, insumo_id, insumo_nome, unidade, quantidade, preco_unitario, custo, observacao)
            VALUES (:lista_id, :insumo_id, :insumo_nome, :unidade, :quantidade, :preco_unitario, :custo, :observacao)
        ");
        $stmt->execute([
            ':lista_id' => $lista_id,
            ':insumo_id' => $sugestao['insumo_id'],
            ':insumo_nome' => $sugestao['insumo_nome'],
            ':unidade' => $sugestao['unidade'],
            ':quantidade' => $sugestao['sugerido'],
            ':preco_unitario' => $sugestao['custo_unitario'],
            ':custo' => $sugestao['custo_total'],
            ':observacao' => "Sugestão automática - Demanda: {$sugestao['demanda']} | Segurança: {$sugestao['seguranca']}"
        ]);
    }
    
    return $lista_id;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sugestão de Compra - Painel Smile PRO</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #d97706 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .parameters-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }
        .form-group input, .form-group select {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        .insumos-selection {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .insumo-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .insumo-item:last-child {
            border-bottom: none;
        }
        .insumo-item input[type="checkbox"] {
            margin-right: 10px;
        }
        .insumo-info {
            flex: 1;
        }
        .insumo-name {
            font-weight: 600;
            color: #333;
        }
        .insumo-details {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
        .preview-grid {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
        .preview-table {
            width: 100%;
            border-collapse: collapse;
        }
        .preview-table th, .preview-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .preview-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .preview-table tbody tr:hover {
            background: #f8f9fa;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            font-weight: 500;
        }
        .btn-primary {
            background: #1e3a8a;
            color: white;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn:hover {
            opacity: 0.8;
        }
        .alert {
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        .total-row {
            background: #e7f3ff;
            font-weight: bold;
        }
        .badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Sugestão de Compra</h1>
            <p>Configure os parâmetros e calcule as quantidades necessárias</p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?= h($msg) ?></div>
        <?php endif; ?>

        <?php if ($err): ?>
            <div class="alert alert-error"><?= h($err) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="acao" value="calcular">
            
            <!-- Parâmetros -->
            <div class="parameters-form">
                <h3 style="margin-top: 0;">Parâmetros de Cálculo</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Horizonte de cobertura (dias):</label>
                        <input type="number" name="horizonte_dias" value="<?= $parametros['horizonte_dias'] ?>" min="1" max="30" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Lead time de reposição (dias):</label>
                        <input type="number" name="lead_time_dias" value="<?= $parametros['lead_time_dias'] ?>" min="0" max="30" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Estoque de segurança (%):</label>
                        <input type="number" name="estoque_seguranca_percent" value="<?= $parametros['estoque_seguranca_percent'] ?>" min="0" max="100" step="0.1" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Modo de agrupamento:</label>
                        <select name="agrupamento">
                            <option value="fornecedor_consolidado" <?= $parametros['agrupamento'] === 'fornecedor_consolidado' ? 'selected' : '' ?>>
                                Fornecedor consolidado
                            </option>
                            <option value="fornecedor_por_evento" <?= $parametros['agrupamento'] === 'fornecedor_por_evento' ? 'selected' : '' ?>>
                                Fornecedor por evento
                            </option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Período de demanda base:</label>
                        <select name="periodo_demanda">
                            <option value="eventos_futuros" <?= $parametros['periodo_demanda'] === 'eventos_futuros' ? 'selected' : '' ?>>
                                Próximos eventos
                            </option>
                            <option value="media_movel" <?= $parametros['periodo_demanda'] === 'media_movel' ? 'selected' : '' ?> disabled>
                                Média móvel (futuro)
                            </option>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                    <label style="display: flex; align-items: center; gap: 5px;">
                        <input type="checkbox" name="arredondar_embalagem" <?= $parametros['arredondar_embalagem'] ? 'checked' : '' ?>>
                        Arredondar por embalagem
                    </label>
                    
                    <label style="display: flex; align-items: center; gap: 5px;">
                        <input type="checkbox" name="permitir_substitutos" <?= $parametros['permitir_substitutos'] ? 'checked' : '' ?> disabled>
                        Permitir substitutos (futuro)
                    </label>
                </div>
            </div>
            
            <!-- Seleção de insumos -->
            <div class="insumos-selection">
                <h3 style="margin-top: 0;">Insumos para Cálculo</h3>
                
                <?php if (empty($insumos_criticos)): ?>
                    <div class="no-data">
                        <p>Nenhum insumo crítico encontrado.</p>
                        <a href="estoque_alertas.php" class="btn btn-secondary">← Voltar para Alertas</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($insumos_criticos as $insumo): ?>
                        <div class="insumo-item">
                            <input type="checkbox" name="insumos_selecionados[]" value="<?= $insumo['id'] ?>" checked>
                            <div class="insumo-info">
                                <div class="insumo-name"><?= h($insumo['nome']) ?></div>
                                <div class="insumo-details">
                                    Estoque: <?= number_format($insumo['estoque_atual'], 2, ',', '.') ?> <?= h($insumo['unidade_simbolo']) ?> | 
                                    Mínimo: <?= number_format($insumo['estoque_minimo'], 2, ',', '.') ?> <?= h($insumo['unidade_simbolo']) ?> | 
                                    Fornecedor: <?= h($insumo['fornecedor_nome'] ?: 'Não definido') ?> |
                                    <?php if (!$insumo['preco'] || $insumo['preco'] <= 0): ?>
                                        <span class="badge badge-danger">Sem preço</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div style="margin-top: 15px; text-align: center;">
                        <button type="submit" class="btn btn-primary">🧮 Calcular Sugestões</button>
                    </div>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($dados_sugestao): ?>
            <!-- Prévia das sugestões -->
            <div class="preview-grid">
                <h3 style="margin: 0; padding: 15px; background: #f8f9fa; border-bottom: 1px solid #ddd;">
                    Prévia das Sugestões
                </h3>
                
                <table class="preview-table">
                    <thead>
                        <tr>
                            <th>Insumo</th>
                            <th>Saldo Atual</th>
                            <th>Demanda</th>
                            <th>Segurança</th>
                            <th>Necessário</th>
                            <th>Sugerido</th>
                            <th>Unidade</th>
                            <th>Fornecedor</th>
                            <?php if (lc_can_view_stock_value()): ?>
                                <th>Custo Est.</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_custo = 0;
                        foreach ($dados_sugestao as $sugestao): 
                            $total_custo += $sugestao['custo_total'];
                        ?>
                            <tr>
                                <td><strong><?= h($sugestao['insumo_nome']) ?></strong></td>
                                <td><?= number_format($sugestao['saldo_atual'], 2, ',', '.') ?></td>
                                <td><?= number_format($sugestao['demanda'], 2, ',', '.') ?></td>
                                <td><?= number_format($sugestao['seguranca'], 2, ',', '.') ?></td>
                                <td><?= number_format($sugestao['necessidade'], 2, ',', '.') ?></td>
                                <td><strong><?= number_format($sugestao['sugerido'], 2, ',', '.') ?></strong></td>
                                <td><?= h($sugestao['unidade']) ?></td>
                                <td>
                                    <?= h($sugestao['fornecedor_nome']) ?>
                                    <?php if ($sugestao['fornecedor_nome'] === '(Definir)'): ?>
                                        <span class="badge badge-warning">Atenção</span>
                                    <?php endif; ?>
                                </td>
                                <?php if (lc_can_view_stock_value()): ?>
                                    <td>
                                        <?php if ($sugestao['tem_preco']): ?>
                                            <strong>R$ <?= number_format($sugestao['custo_total'], 2, ',', '.') ?></strong>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Sem preço</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (lc_can_view_stock_value()): ?>
                            <tr class="total-row">
                                <td colspan="8"><strong>Total Estimado</strong></td>
                                <td><strong>R$ <?= number_format($total_custo, 2, ',', '.') ?></strong></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Ações -->
            <div style="margin-top: 20px; text-align: center;">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="acao" value="salvar_rascunho">
                    <input type="hidden" name="sugestoes_json" value="<?= h(json_encode($dados_sugestao)) ?>">
                    <input type="hidden" name="parametros_json" value="<?= h(json_encode($parametros)) ?>">
                    <button type="submit" class="btn btn-success">
                        💾 Salvar como Rascunho
                    </button>
                </form>
                
                <a href="estoque_alertas.php" class="btn btn-secondary">← Voltar para Alertas</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
