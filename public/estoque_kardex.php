<?php
// estoque_kardex.php
// Módulo de Kardex - Histórico cronológico de movimentos de estoque

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';
require_once __DIR__ . '/lc_units_helper.php';

// Verificar permissões
if (!lc_can_view_stock_value() && !lc_can_edit_contagem()) {
    if (!lc_can_create_contagem()) {
        die('Acesso negado. Você não tem permissão para visualizar o Kardex.');
    }
}

$perfil = lc_get_user_perfil();
$usuario_id = $_SESSION['usuario_id'] ?? 1;
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';

// Processar filtros
$insumo_id = $_GET['insumo_id'] ?? null;
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
$data_fim = $_GET['data_fim'] ?? date('Y-m-d');
$tipos_movimento = $_GET['tipos_movimento'] ?? ['entrada', 'consumo_evento', 'ajuste', 'perda', 'devolucao'];
$categoria_id = $_GET['categoria_id'] ?? null;
$fornecedor_id = $_GET['fornecedor_id'] ?? null;
$exibir_custos = $_GET['exibir_custos'] ?? ($perfil === 'ADM' ? 'true' : 'false');
$pagina = (int)($_GET['pagina'] ?? 1);
$limite = 50;
$offset = ($pagina - 1) * $limite;

// Processar ações
if ($_POST) {
    if (isset($_POST['acao'])) {
        switch ($_POST['acao']) {
            case 'adicionar_ajuste':
                if (lc_can_edit_contagem()) {
                    $insumo_ajuste = $_POST['insumo_id'] ?? null;
                    $tipo_ajuste = $_POST['tipo_ajuste'] ?? 'entrada';
                    $quantidade = (float)($_POST['quantidade'] ?? 0);
                    $unidade = $_POST['unidade'] ?? 'un';
                    $motivo = $_POST['motivo'] ?? 'Ajuste manual';
                    $observacao = $_POST['observacao'] ?? '';
                    
                    if ($insumo_ajuste && $quantidade > 0) {
                        try {
                            // Buscar dados do insumo
                            $stmt = $pdo->prepare("SELECT unidade_padrao, fator_correcao FROM lc_insumos WHERE id = ?");
                            $stmt->execute([$insumo_ajuste]);
                            $insumo = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($insumo) {
                                // Calcular fator de conversão
                                $fator = lc_convert_to_base(1, 1, $insumo['fator_correcao']); // Simplificado
                                $quantidade_base = $quantidade * $fator;
                                
                                // Inserir movimento
                                $stmt = $pdo->prepare("
                                    INSERT INTO lc_movimentos_estoque 
                                    (insumo_id, tipo, quantidade_base, unidade_digitada, quantidade_digitada, 
                                     fator_aplicado, referencia, observacao, usuario_id, usuario_nome)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ");
                                $stmt->execute([
                                    $insumo_ajuste,
                                    $tipo_ajuste,
                                    $quantidade_base,
                                    $unidade,
                                    $quantidade,
                                    $fator,
                                    'Ajuste manual',
                                    $observacao,
                                    $usuario_id,
                                    $usuario_nome
                                ]);
                                
                                $sucesso = "Ajuste registrado com sucesso!";
                            }
                        } catch (Exception $e) {
                            $erro = "Erro ao registrar ajuste: " . $e->getMessage();
                        }
                    }
                }
                break;
        }
    }
}

// Buscar insumos para filtro
$stmt = $pdo->query("
    SELECT id, nome, unidade_padrao, categoria_id 
    FROM lc_insumos 
    WHERE ativo = true 
    ORDER BY nome
");
$insumos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar categorias
$stmt = $pdo->query("SELECT id, nome FROM lc_categorias WHERE ativo = true ORDER BY nome");
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar fornecedores
$stmt = $pdo->query("SELECT id, nome FROM fornecedores WHERE ativo = true ORDER BY nome");
$fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar movimentos se insumo selecionado
$movimentos = [];
$resumo = null;

if ($insumo_id) {
    try {
        // Buscar saldo inicial
        $stmt = $pdo->prepare("
            SELECT lc_calcular_saldo_insumo(?, ?) as saldo_inicial
        ");
        $stmt->execute([$insumo_id, $data_inicio . ' 00:00:00']);
        $saldo_inicial = $stmt->fetchColumn();
        
        // Buscar movimentos no período
        $where_conditions = ["m.insumo_id = ?", "m.data_movimento BETWEEN ? AND ?", "m.ativo = true"];
        $params = [$insumo_id, $data_inicio . ' 00:00:00', $data_fim . ' 23:59:59'];
        
        if (!empty($tipos_movimento) && is_array($tipos_movimento)) {
            $placeholders = str_repeat('?,', count($tipos_movimento) - 1) . '?';
            $where_conditions[] = "m.tipo IN ($placeholders)";
            $params = array_merge($params, $tipos_movimento);
        }
        
        $where_sql = implode(' AND ', $where_conditions);
        
        // Contar total de registros
        $count_sql = "
            SELECT COUNT(*) 
            FROM lc_movimentos_estoque m
            JOIN lc_insumos i ON i.id = m.insumo_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            WHERE $where_sql
        ";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total_registros = $stmt->fetchColumn();
        
        // Buscar movimentos
        $sql = "
            SELECT 
                m.*,
                i.nome as insumo_nome,
                i.unidade_padrao as insumo_unidade,
                f.nome as fornecedor_nome,
                (
                    SELECT lc_calcular_saldo_insumo(m.insumo_id, m.data_movimento)
                ) as saldo_acumulado,
                CASE 
                    WHEN m.custo_unitario IS NOT NULL THEN m.quantidade_base * m.custo_unitario
                    ELSE NULL
                END as valor_movimento
            FROM lc_movimentos_estoque m
            JOIN lc_insumos i ON i.id = m.insumo_id
            LEFT JOIN fornecedores f ON f.id = m.fornecedor_id
            WHERE $where_sql
            ORDER BY m.data_movimento ASC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limite;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $movimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calcular resumo
        $stmt = $pdo->prepare("
            SELECT * FROM lc_calcular_saldo_insumo_data(?, ?, ?)
        ");
        $stmt->execute([$insumo_id, $data_inicio . ' 00:00:00', $data_fim . ' 23:59:59']);
        $resumo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Buscar custo atual do insumo
        $stmt = $pdo->prepare("
            SELECT preco, fator_correcao, (preco * fator_correcao) as custo_corrigido
            FROM lc_insumos WHERE id = ?
        ");
        $stmt->execute([$insumo_id]);
        $insumo_custo = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $erro = "Erro ao buscar movimentos: " . $e->getMessage();
    }
}

// Buscar insumo selecionado
$insumo_selecionado = null;
if ($insumo_id) {
    $stmt = $pdo->prepare("SELECT * FROM lc_insumos WHERE id = ?");
    $stmt->execute([$insumo_id]);
    $insumo_selecionado = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kardex - Histórico de Movimentos</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .kardex-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
            background: #f8f9fa;
            min-height: 100vh;
        }
        
        .kardex-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #d97706 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .filtros-section {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filtros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filtro-group {
            display: flex;
            flex-direction: column;
        }
        
        .filtro-group label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #333;
        }
        
        .filtro-group input,
        .filtro-group select {
            padding: 10px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .filtro-group input:focus,
        .filtro-group select:focus {
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        
        .tipos-movimento {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .tipo-checkbox {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .tipo-checkbox input[type="checkbox"] {
            accent-color: #1e3a8a;
        }
        
        .btn-filtrar {
            background: linear-gradient(135deg, #1e3a8a 0%, #d97706 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-filtrar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 58, 138, 0.3);
        }
        
        .kardex-grid {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .kardex-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .kardex-table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #e1e5e9;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .kardex-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e1e5e9;
        }
        
        .kardex-table tr:hover {
            background: #f8f9fa;
        }
        
        .tipo-entrada { color: #28a745; font-weight: 600; }
        .tipo-saida { color: #dc3545; font-weight: 600; }
        .tipo-ajuste { color: #ffc107; font-weight: 600; }
        .tipo-perda { color: #fd7e14; font-weight: 600; }
        .tipo-devolucao { color: #17a2b8; font-weight: 600; }
        
        .saldo-positivo { color: #28a745; font-weight: 600; }
        .saldo-negativo { color: #dc3545; font-weight: 600; }
        .saldo-zero { color: #6c757d; font-weight: 600; }
        
        .resumo-section {
            background: #e7f3ff;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .resumo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .resumo-item {
            text-align: center;
        }
        
        .resumo-item .label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .resumo-item .value {
            font-size: 24px;
            font-weight: 700;
            color: #1e3a8a;
            margin-top: 5px;
        }
        
        .paginacao {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
        }
        
        .paginacao button {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
        }
        
        .paginacao button:hover {
            background: #f8f9fa;
        }
        
        .paginacao button.active {
            background: #1e3a8a;
            color: white;
            border-color: #1e3a8a;
        }
        
        .btn-ajuste {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
        }
        
        .btn-ajuste:hover {
            background: #218838;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .close {
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e3a8a 0%, #d97706 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .tooltip {
            position: relative;
            cursor: help;
        }
        
        .tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
        }
    </style>
</head>
<body>
    <div class="kardex-container">
        <!-- Header -->
        <div class="kardex-header">
            <h1>📒 Kardex - Histórico de Movimentos</h1>
            <p>Histórico cronológico de movimentos de estoque com saldo acumulado</p>
        </div>
        
        <!-- Alertas -->
        <?php if (isset($sucesso)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($sucesso) ?></div>
        <?php endif; ?>
        
        <?php if (isset($erro)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filtros-section">
            <h2>🔍 Filtros</h2>
            <form method="GET" action="">
                <div class="filtros-grid">
                    <div class="filtro-group">
                        <label for="insumo_id">Insumo *</label>
                        <select name="insumo_id" id="insumo_id" required>
                            <option value="">Selecione um insumo</option>
                            <?php foreach ($insumos as $insumo): ?>
                                <option value="<?= $insumo['id'] ?>" <?= $insumo_id == $insumo['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($insumo['nome']) ?> (<?= $insumo['unidade_padrao'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filtro-group">
                        <label for="data_inicio">Data Inicial</label>
                        <input type="date" name="data_inicio" id="data_inicio" value="<?= htmlspecialchars($data_inicio) ?>">
                    </div>
                    
                    <div class="filtro-group">
                        <label for="data_fim">Data Final</label>
                        <input type="date" name="data_fim" id="data_fim" value="<?= htmlspecialchars($data_fim) ?>">
                    </div>
                    
                    <div class="filtro-group">
                        <label for="categoria_id">Categoria</label>
                        <select name="categoria_id" id="categoria_id">
                            <option value="">Todas as categorias</option>
                            <?php foreach ($categorias as $categoria): ?>
                                <option value="<?= $categoria['id'] ?>" <?= $categoria_id == $categoria['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($categoria['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filtro-group">
                        <label for="fornecedor_id">Fornecedor</label>
                        <select name="fornecedor_id" id="fornecedor_id">
                            <option value="">Todos os fornecedores</option>
                            <?php foreach ($fornecedores as $fornecedor): ?>
                                <option value="<?= $fornecedor['id'] ?>" <?= $fornecedor_id == $fornecedor['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($fornecedor['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="filtro-group">
                    <label>Tipo de Movimento</label>
                    <div class="tipos-movimento">
                        <div class="tipo-checkbox">
                            <input type="checkbox" name="tipos_movimento[]" value="entrada" id="tipo_entrada" 
                                   <?= in_array('entrada', $tipos_movimento) ? 'checked' : '' ?>>
                            <label for="tipo_entrada">Entrada</label>
                        </div>
                        <div class="tipo-checkbox">
                            <input type="checkbox" name="tipos_movimento[]" value="consumo_evento" id="tipo_consumo" 
                                   <?= in_array('consumo_evento', $tipos_movimento) ? 'checked' : '' ?>>
                            <label for="tipo_consumo">Consumo Evento</label>
                        </div>
                        <div class="tipo-checkbox">
                            <input type="checkbox" name="tipos_movimento[]" value="ajuste" id="tipo_ajuste" 
                                   <?= in_array('ajuste', $tipos_movimento) ? 'checked' : '' ?>>
                            <label for="tipo_ajuste">Ajuste</label>
                        </div>
                        <div class="tipo-checkbox">
                            <input type="checkbox" name="tipos_movimento[]" value="perda" id="tipo_perda" 
                                   <?= in_array('perda', $tipos_movimento) ? 'checked' : '' ?>>
                            <label for="tipo_perda">Perda</label>
                        </div>
                        <div class="tipo-checkbox">
                            <input type="checkbox" name="tipos_movimento[]" value="devolucao" id="tipo_devolucao" 
                                   <?= in_array('devolucao', $tipos_movimento) ? 'checked' : '' ?>>
                            <label for="tipo_devolucao">Devolução</label>
                        </div>
                    </div>
                </div>
                
                <?php if ($perfil === 'ADM'): ?>
                <div class="filtro-group">
                    <label>
                        <input type="checkbox" name="exibir_custos" value="true" <?= $exibir_custos === 'true' ? 'checked' : '' ?>>
                        Exibir custos e valores (R$)
                    </label>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="btn-filtrar">🔍 Filtrar Movimentos</button>
            </form>
        </div>
        
        <!-- Resumo -->
        <?php if ($resumo && $insumo_selecionado): ?>
        <div class="resumo-section">
            <h3>📊 Resumo do Período - <?= htmlspecialchars($insumo_selecionado['nome']) ?></h3>
            <div class="resumo-grid">
                <div class="resumo-item">
                    <div class="label">Saldo Inicial</div>
                    <div class="value saldo-<?= $resumo['saldo_inicial'] >= 0 ? 'positivo' : 'negativo' ?>">
                        <?= number_format($resumo['saldo_inicial'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                    </div>
                </div>
                <div class="resumo-item">
                    <div class="label">Entradas</div>
                    <div class="value saldo-positivo">
                        +<?= number_format($resumo['entradas'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                    </div>
                </div>
                <div class="resumo-item">
                    <div class="label">Saídas</div>
                    <div class="value saldo-negativo">
                        -<?= number_format($resumo['saidas'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                    </div>
                </div>
                <div class="resumo-item">
                    <div class="label">Saldo Final</div>
                    <div class="value saldo-<?= $resumo['saldo_final'] >= 0 ? 'positivo' : 'negativo' ?>">
                        <?= number_format($resumo['saldo_final'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                    </div>
                </div>
                <?php if ($exibir_custos === 'true' && $insumo_custo): ?>
                <div class="resumo-item">
                    <div class="label">Valor do Saldo</div>
                    <div class="value saldo-positivo">
                        R$ <?= number_format($resumo['saldo_final'] * $insumo_custo['custo_corrigido'], 2) ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Grid de Movimentos -->
        <?php if ($insumo_id && !empty($movimentos)): ?>
        <div class="kardex-grid">
            <div style="padding: 20px; border-bottom: 1px solid #e1e5e9; display: flex; justify-content: space-between; align-items: center;">
                <h3>📋 Movimentos do Período</h3>
                <div>
                    <?php if (lc_can_edit_contagem()): ?>
                    <button onclick="abrirModalAjuste()" class="btn-ajuste">+ Adicionar Ajuste</button>
                    <?php endif; ?>
                    <button onclick="exportarCSV()" class="btn-secondary">📊 Exportar CSV</button>
                </div>
            </div>
            
            <table class="kardex-table">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Tipo</th>
                        <th>Qtd (digitada)</th>
                        <th>Qtd (base)</th>
                        <th>Saldo Acumulado</th>
                        <th>Referência</th>
                        <th>Observação</th>
                        <?php if ($exibir_custos === 'true'): ?>
                        <th>Valor (R$)</th>
                        <?php endif; ?>
                        <th>Usuário</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimentos as $movimento): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($movimento['data_movimento'])) ?></td>
                        <td>
                            <span class="tipo-<?= $movimento['tipo'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $movimento['tipo'])) ?>
                            </span>
                        </td>
                        <td>
                            <span class="tooltip" data-tooltip="Quantidade digitada pelo usuário">
                                <?= number_format($movimento['quantidade_digitada'], 3) ?> <?= $movimento['unidade_digitada'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="tooltip" data-tooltip="Quantidade convertida para unidade base">
                                <?= number_format($movimento['quantidade_base'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="saldo-<?= $movimento['saldo_acumulado'] >= 0 ? 'positivo' : 'negativo' ?>">
                                <?= number_format($movimento['saldo_acumulado'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($movimento['referencia']) ?></td>
                        <td><?= htmlspecialchars($movimento['observacao']) ?></td>
                        <?php if ($exibir_custos === 'true'): ?>
                        <td>
                            <?php if ($movimento['valor_movimento']): ?>
                                R$ <?= number_format($movimento['valor_movimento'], 2) ?>
                            <?php else: ?>
                                <span style="color: #999;">—</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($movimento['usuario_nome']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_registros > $limite): ?>
        <div class="paginacao">
            <?php
            $total_paginas = ceil($total_registros / $limite);
            $inicio_pagina = max(1, $pagina - 2);
            $fim_pagina = min($total_paginas, $pagina + 2);
            ?>
            
            <?php if ($pagina > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>" class="btn-secondary">« Anterior</a>
            <?php endif; ?>
            
            <?php for ($i = $inicio_pagina; $i <= $fim_pagina; $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>" 
                   class="<?= $i == $pagina ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($pagina < $total_paginas): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>" class="btn-secondary">Próxima »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php elseif ($insumo_id && empty($movimentos)): ?>
        <div class="alert alert-danger">
            <h3>Nenhum movimento encontrado</h3>
            <p>Não foram encontrados movimentos para o insumo selecionado no período especificado.</p>
        </div>
        <?php endif; ?>
        
        <!-- Modal de Ajuste -->
        <div id="modalAjuste" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Adicionar Ajuste de Estoque</h3>
                    <span class="close" onclick="fecharModalAjuste()">&times;</span>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="acao" value="adicionar_ajuste">
                    <input type="hidden" name="insumo_id" value="<?= $insumo_id ?>">
                    
                    <div class="form-group">
                        <label for="tipo_ajuste">Tipo de Ajuste</label>
                        <select name="tipo_ajuste" id="tipo_ajuste" required>
                            <option value="entrada">Entrada</option>
                            <option value="saida">Saída</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="quantidade">Quantidade</label>
                        <input type="number" name="quantidade" id="quantidade" step="0.001" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="unidade">Unidade</label>
                        <select name="unidade" id="unidade" required>
                            <option value="un">Unidade</option>
                            <option value="kg">Quilograma</option>
                            <option value="g">Grama</option>
                            <option value="L">Litro</option>
                            <option value="ml">Mililitro</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="motivo">Motivo</label>
                        <input type="text" name="motivo" id="motivo" value="Ajuste manual" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="observacao">Observação</label>
                        <textarea name="observacao" id="observacao" rows="3"></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" class="btn-secondary" onclick="fecharModalAjuste()">Cancelar</button>
                        <button type="submit" class="btn-primary">Adicionar Ajuste</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function abrirModalAjuste() {
            document.getElementById('modalAjuste').style.display = 'block';
        }
        
        function fecharModalAjuste() {
            document.getElementById('modalAjuste').style.display = 'none';
        }
        
        function exportarCSV() {
            // Implementar exportação CSV
            alert('Funcionalidade de exportação CSV será implementada em breve!');
        }
        
        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById('modalAjuste');
            if (event.target == modal) {
                fecharModalAjuste();
            }
        }
        
        // Auto-focus no campo de quantidade
        document.addEventListener('DOMContentLoaded', function() {
            const quantidadeInput = document.getElementById('quantidade');
            if (quantidadeInput) {
                quantidadeInput.focus();
            }
        });
    </script>
</body>
</html>
