<?php
// estoque_desvios.php
// Relatório de Desvios Previsto × Real

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_calc.php';
require_once __DIR__ . '/lc_units_helper.php';
require_once __DIR__ . '/lc_config_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();

$msg = '';
$err = '';
$dados_relatorio = null;

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contagem_anterior_id = (int)($_POST['contagem_anterior_id'] ?? 0);
    $contagem_atual_id = (int)($_POST['contagem_atual_id'] ?? 0);
    
    try {
        if ($contagem_anterior_id <= 0 || $contagem_atual_id <= 0) {
            throw new Exception('Selecione ambas as contagens.');
        }
        
        if ($contagem_anterior_id === $contagem_atual_id) {
            throw new Exception('As contagens devem ser diferentes.');
        }
        
        // Validar que as contagens estão fechadas
        $stmt = $pdo->prepare("
            SELECT id, data_ref, status 
            FROM estoque_contagens 
            WHERE id IN (:id1, :id2) AND status = 'fechada'
        ");
        $stmt->execute([':id1' => $contagem_anterior_id, ':id2' => $contagem_atual_id]);
        $contagens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($contagens) !== 2) {
            throw new Exception('Ambas as contagens devem estar fechadas.');
        }
        
        // Identificar qual é anterior e qual é atual
        $contagem_anterior = null;
        $contagem_atual = null;
        
        foreach ($contagens as $contagem) {
            if ($contagem['id'] == $contagem_anterior_id) {
                $contagem_anterior = $contagem;
            } else {
                $contagem_atual = $contagem;
            }
        }
        
        // Validar ordem cronológica
        if (strtotime($contagem_atual['data_ref']) <= strtotime($contagem_anterior['data_ref'])) {
            throw new Exception('A contagem atual deve ser posterior à anterior.');
        }
        
        // Calcular desvios
        $dados_relatorio = calcular_desvios($pdo, $contagem_anterior_id, $contagem_atual_id);
        
    } catch (Exception $e) {
        $err = 'Erro: ' . $e->getMessage();
    }
}

// Carregar contagens fechadas para o select
$stmt = $pdo->query("
    SELECT id, data_ref, criado_em, 
           (SELECT COUNT(*) FROM estoque_contagem_itens WHERE contagem_id = estoque_contagens.id) as total_itens
    FROM estoque_contagens 
    WHERE status = 'fechada' 
    ORDER BY data_ref DESC, criado_em DESC
");
$contagens_fechadas = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Calcular desvios entre duas contagens
 */
function calcular_desvios(PDO $pdo, int $contagem_anterior_id, int $contagem_atual_id): array {
    // 1. Buscar eventos no período
    $stmt = $pdo->prepare("
        SELECT data_ref FROM estoque_contagens WHERE id = :id_anterior
    ");
    $stmt->execute([':id_anterior' => $contagem_anterior_id]);
    $data_anterior = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT data_ref FROM estoque_contagens WHERE id = :id_atual
    ");
    $stmt->execute([':id_atual' => $contagem_atual_id]);
    $data_atual = $stmt->fetchColumn();
    
    // Buscar eventos no período
    $stmt = $pdo->prepare("
        SELECT id, data_evento, convidados 
        FROM lc_listas_eventos 
        WHERE data_evento > :data_anterior AND data_evento <= :data_atual
        ORDER BY data_evento
    ");
    $stmt->execute([':data_anterior' => $data_anterior, ':data_atual' => $data_atual]);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Calcular consumo previsto
    $previsto = [];
    foreach ($eventos as $evento) {
        // Buscar cardápio do evento
        $stmt = $pdo->prepare("
            SELECT ficha_id, consumo_pessoa_override 
            FROM lc_evento_cardapio 
            WHERE evento_id = :evento_id AND ativo = true
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
                    $insumo_id = $compra['insumo_id'] ?? null;
                    if (!$insumo_id) continue;
                    
                    if (!isset($previsto[$insumo_id])) {
                        $previsto[$insumo_id] = 0;
                    }
                    $previsto[$insumo_id] += (float)$compra['qtd'];
                }
            }
        }
    }
    
    // 3. Calcular consumo real (diferença entre contagens)
    $real = [];
    
    // Contagem anterior
    $stmt = $pdo->prepare("
        SELECT insumo_id, SUM(qtd_contada_base) as total
        FROM estoque_contagem_itens 
        WHERE contagem_id = :contagem_id
        GROUP BY insumo_id
    ");
    $stmt->execute([':contagem_id' => $contagem_anterior_id]);
    $estoque_anterior = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $estoque_anterior[$row['insumo_id']] = (float)$row['total'];
    }
    
    // Contagem atual
    $stmt = $pdo->prepare("
        SELECT insumo_id, SUM(qtd_contada_base) as total
        FROM estoque_contagem_itens 
        WHERE contagem_id = :contagem_id
        GROUP BY insumo_id
    ");
    $stmt->execute([':contagem_id' => $contagem_atual_id]);
    $estoque_atual = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $estoque_atual[$row['insumo_id']] = (float)$row['total'];
    }
    
    // Calcular diferença (consumo real)
    $todos_insumos = array_unique(array_merge(
        array_keys($estoque_anterior),
        array_keys($estoque_atual)
    ));
    
    foreach ($todos_insumos as $insumo_id) {
        $anterior = $estoque_anterior[$insumo_id] ?? 0;
        $atual = $estoque_atual[$insumo_id] ?? 0;
        $real[$insumo_id] = $anterior - $atual; // Positivo = consumiu
    }
    
    // 4. Calcular desvios e impacto financeiro
    $resultado = [];
    $todos_insumos = array_unique(array_merge(
        array_keys($previsto),
        array_keys($real)
    ));
    
    foreach ($todos_insumos as $insumo_id) {
        $previsto_qtd = $previsto[$insumo_id] ?? 0;
        $real_qtd = $real[$insumo_id] ?? 0;
        $desvio = $real_qtd - $previsto_qtd;
        $percentual = ($previsto_qtd > 0) ? ($desvio / $previsto_qtd) * 100 : null;
        
        // Buscar dados do insumo
        $stmt = $pdo->prepare("
            SELECT nome, preco, fator_correcao, unidade_padrao
            FROM lc_insumos 
            WHERE id = :insumo_id
        ");
        $stmt->execute([':insumo_id' => $insumo_id]);
        $insumo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$insumo) continue;
        
        $custo_unitario = (float)$insumo['preco'] * (float)($insumo['fator_correcao'] ?? 1.0);
        $impacto_rs = $desvio * $custo_unitario;
        
        $resultado[] = [
            'insumo_id' => $insumo_id,
            'insumo_nome' => $insumo['nome'],
            'unidade' => $insumo['unidade_padrao'],
            'previsto' => $previsto_qtd,
            'real' => $real_qtd,
            'desvio' => $desvio,
            'percentual' => $percentual,
            'custo_unitario' => $custo_unitario,
            'impacto_rs' => $impacto_rs
        ];
    }
    
    // Ordenar por impacto (maior primeiro)
    usort($resultado, function($a, $b) {
        return abs($b['impacto_rs']) <=> abs($a['impacto_rs']);
    });
    
    return $resultado;
}


?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Desvios - Painel Smile PRO</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #d97706 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filters form {
            display: flex;
            gap: 20px;
            align-items: end;
        }
        .filter-group {
            flex: 1;
        }
        .table-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .total-row {
            background: #e7f3ff;
            font-weight: bold;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        .btn-primary {
            background: #1e3a8a;
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
            color: #666;
            padding: 40px;
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Relatório de Desvios - Previsto × Real</h1>
            <p>Compare o consumo previsto pelos eventos com o consumo real das contagens</p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?= h($msg) ?></div>
        <?php endif; ?>

        <?php if ($err): ?>
            <div class="alert alert-error"><?= h($err) ?></div>
        <?php endif; ?>

        <div class="filters">
            <form method="POST">
                <div class="filter-group">
                    <label>Contagem Anterior:</label>
                    <select name="contagem_anterior_id" class="input" required>
                        <option value="">Selecione a contagem anterior</option>
                        <?php foreach ($contagens_fechadas as $contagem): ?>
                            <option value="<?= $contagem['id'] ?>" 
                                    <?= (isset($_POST['contagem_anterior_id']) && $_POST['contagem_anterior_id'] == $contagem['id']) ? 'selected' : '' ?>>
                                #<?= $contagem['id'] ?> - <?= h($contagem['data_ref']) ?> (<?= $contagem['total_itens'] ?> itens)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Contagem Atual:</label>
                    <select name="contagem_atual_id" class="input" required>
                        <option value="">Selecione a contagem atual</option>
                        <?php foreach ($contagens_fechadas as $contagem): ?>
                            <option value="<?= $contagem['id'] ?>" 
                                    <?= (isset($_POST['contagem_atual_id']) && $_POST['contagem_atual_id'] == $contagem['id']) ? 'selected' : '' ?>>
                                #<?= $contagem['id'] ?> - <?= h($contagem['data_ref']) ?> (<?= $contagem['total_itens'] ?> itens)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <button type="submit" class="btn btn-primary">Gerar Relatório</button>
                </div>
            </form>
        </div>

        <?php if ($dados_relatorio): ?>
            <div class="table-container">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Insumo</th>
                            <th>Unidade</th>
                            <th>Previsto</th>
                            <th>Real</th>
                            <th>Desvio</th>
                            <th>%</th>
                            <?php if (lc_can_view_stock_value()): ?>
                                <th>Impacto R$</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_impacto = 0;
                        foreach ($dados_relatorio as $item): 
                            $total_impacto += $item['impacto_rs'];
                        ?>
                            <tr>
                                <td><strong><?= h($item['insumo_nome']) ?></strong></td>
                                <td><?= h($item['unidade']) ?></td>
                                <td><?= number_format($item['previsto'], 3, ',', '.') ?></td>
                                <td><?= number_format($item['real'], 3, ',', '.') ?></td>
                                <td>
                                    <?= number_format($item['desvio'], 3, ',', '.') ?>
                                    <?php if ($item['desvio'] > 0): ?>
                                        <span style="color: #dc3545;">↑</span>
                                    <?php elseif ($item['desvio'] < 0): ?>
                                        <span style="color: #28a745;">↓</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['percentual'] !== null): ?>
                                        <?= number_format($item['percentual'], 1, ',', '.') ?>%
                                        <?php if (abs($item['percentual']) >= 10): ?>
                                            <span class="badge badge-<?= abs($item['percentual']) >= 20 ? 'danger' : 'warning' ?>">
                                                Fora do programado
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <?php if (lc_can_view_stock_value()): ?>
                                    <td>
                                        <strong style="color: <?= $item['impacto_rs'] >= 0 ? '#dc3545' : '#28a745' ?>">
                                            R$ <?= number_format($item['impacto_rs'], 2, ',', '.') ?>
                                        </strong>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($dados_relatorio)): ?>
                            <tr>
                                <td colspan="<?= lc_can_view_stock_value() ? '7' : '6' ?>" class="no-data">
                                    Nenhum desvio encontrado no período selecionado.
                                </td>
                            </tr>
                        <?php else: ?>
                            <tr class="total-row">
                                <td colspan="<?= lc_can_view_stock_value() ? '6' : '5' ?>"><strong>Total</strong></td>
                                <?php if (lc_can_view_stock_value()): ?>
                                    <td>
                                        <strong style="color: <?= $total_impacto >= 0 ? '#dc3545' : '#28a745' ?>">
                                            R$ <?= number_format($total_impacto, 2, ',', '.') ?>
                                        </strong>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($dados_relatorio)): ?>
                <div style="text-align: center; margin-top: 20px;">
                    <button onclick="exportarCSV()" class="btn btn-secondary">📄 Exportar CSV</button>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div style="margin-top: 30px; text-align: center;">
            <a href="estoque_contagens.php" class="btn btn-secondary">← Voltar para Contagens</a>
        </div>
    </div>

    <script>
        function exportarCSV() {
            const dados = <?= json_encode($dados_relatorio ?? []) ?>;
            const perfil = '<?= $perfil ?>';
            const podeVerImpacto = perfil === 'ADM';
            
            let csv = 'Insumo,Unidade,Previsto,Real,Desvio,Percentual';
            if (podeVerImpacto) {
                csv += ',Impacto R$';
            }
            csv += '\n';
            
            dados.forEach(item => {
                csv += `"${item.insumo_nome}","${item.unidade}",${item.previsto},${item.real},${item.desvio}`;
                csv += item.percentual !== null ? `,${item.percentual}%` : ',-';
                if (podeVerImpacto) {
                    csv += `,${item.impacto_rs}`;
                }
                csv += '\n';
            });
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'desvios_estoque_' + new Date().toISOString().split('T')[0] + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>
</html>
