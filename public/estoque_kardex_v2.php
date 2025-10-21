<?php
// estoque_kardex_v2.php
// Módulo de Kardex com novo padrão visual Smile UI

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
    <link rel="stylesheet" href="css/smile-ui.css">
</head>
<body>
    <div class="smile-container">
        <!-- Header -->
        <div class="smile-card">
            <div class="smile-card-header">
                <h1>📒 Kardex - Histórico de Movimentos</h1>
                <p>Histórico cronológico de movimentos de estoque com saldo acumulado</p>
            </div>
        </div>
        
        <!-- Alertas -->
        <?php if (isset($sucesso)): ?>
            <div class="smile-alert smile-alert-success"><?= htmlspecialchars($sucesso) ?></div>
        <?php endif; ?>
        
        <?php if (isset($erro)): ?>
            <div class="smile-alert smile-alert-danger"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="smile-card">
            <div class="smile-card-body">
                <h2>🔍 Filtros</h2>
                <form method="GET" action="">
                    <div class="smile-form-grid">
                        <div class="smile-form-group">
                            <label for="insumo_id">Insumo *</label>
                            <select name="insumo_id" id="insumo_id" class="smile-form-control" required>
                                <option value="">Selecione um insumo</option>
                                <?php foreach ($insumos as $insumo): ?>
                                    <option value="<?= $insumo['id'] ?>" <?= $insumo_id == $insumo['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($insumo['nome']) ?> (<?= $insumo['unidade_padrao'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="data_inicio">Data Inicial</label>
                            <input type="date" name="data_inicio" id="data_inicio" class="smile-form-control" value="<?= htmlspecialchars($data_inicio) ?>">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="data_fim">Data Final</label>
                            <input type="date" name="data_fim" id="data_fim" class="smile-form-control" value="<?= htmlspecialchars($data_fim) ?>">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="categoria_id">Categoria</label>
                            <select name="categoria_id" id="categoria_id" class="smile-form-control">
                                <option value="">Todas as categorias</option>
                                <?php foreach ($categorias as $categoria): ?>
                                    <option value="<?= $categoria['id'] ?>" <?= $categoria_id == $categoria['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($categoria['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="fornecedor_id">Fornecedor</label>
                            <select name="fornecedor_id" id="fornecedor_id" class="smile-form-control">
                                <option value="">Todos os fornecedores</option>
                                <?php foreach ($fornecedores as $fornecedor): ?>
                                    <option value="<?= $fornecedor['id'] ?>" <?= $fornecedor_id == $fornecedor['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($fornecedor['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="smile-form-group">
                        <label>Tipo de Movimento</label>
                        <div class="smile-flex smile-gap-2" style="flex-wrap: wrap;">
                            <label class="smile-flex" style="align-items: center; gap: 8px;">
                                <input type="checkbox" name="tipos_movimento[]" value="entrada" <?= in_array('entrada', $tipos_movimento) ? 'checked' : '' ?>>
                                Entrada
                            </label>
                            <label class="smile-flex" style="align-items: center; gap: 8px;">
                                <input type="checkbox" name="tipos_movimento[]" value="consumo_evento" <?= in_array('consumo_evento', $tipos_movimento) ? 'checked' : '' ?>>
                                Consumo Evento
                            </label>
                            <label class="smile-flex" style="align-items: center; gap: 8px;">
                                <input type="checkbox" name="tipos_movimento[]" value="ajuste" <?= in_array('ajuste', $tipos_movimento) ? 'checked' : '' ?>>
                                Ajuste
                            </label>
                            <label class="smile-flex" style="align-items: center; gap: 8px;">
                                <input type="checkbox" name="tipos_movimento[]" value="perda" <?= in_array('perda', $tipos_movimento) ? 'checked' : '' ?>>
                                Perda
                            </label>
                            <label class="smile-flex" style="align-items: center; gap: 8px;">
                                <input type="checkbox" name="tipos_movimento[]" value="devolucao" <?= in_array('devolucao', $tipos_movimento) ? 'checked' : '' ?>>
                                Devolução
                            </label>
                        </div>
                    </div>
                    
                    <?php if ($perfil === 'ADM'): ?>
                    <div class="smile-form-group">
                        <label class="smile-flex" style="align-items: center; gap: 8px;">
                            <input type="checkbox" name="exibir_custos" value="true" <?= $exibir_custos === 'true' ? 'checked' : '' ?>>
                            Exibir custos e valores (R$)
                        </label>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="smile-btn smile-btn-primary">🔍 Filtrar Movimentos</button>
                </form>
            </div>
        </div>
        
        <!-- Resumo -->
        <?php if ($resumo && $insumo_selecionado): ?>
        <div class="smile-card">
            <div class="smile-card-body">
                <h3>📊 Resumo do Período - <?= htmlspecialchars($insumo_selecionado['nome']) ?></h3>
                <div class="smile-grid smile-grid-4">
                    <div class="smile-summary-card">
                        <div class="label">Saldo Inicial</div>
                        <div class="value" style="color: <?= $resumo['saldo_inicial'] >= 0 ? '#059669' : '#dc2626' ?>">
                            <?= number_format($resumo['saldo_inicial'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                        </div>
                    </div>
                    <div class="smile-summary-card">
                        <div class="label">Entradas</div>
                        <div class="value" style="color: #059669">
                            +<?= number_format($resumo['entradas'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                        </div>
                    </div>
                    <div class="smile-summary-card">
                        <div class="label">Saídas</div>
                        <div class="value" style="color: #dc2626">
                            -<?= number_format($resumo['saidas'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                        </div>
                    </div>
                    <div class="smile-summary-card">
                        <div class="label">Saldo Final</div>
                        <div class="value" style="color: <?= $resumo['saldo_final'] >= 0 ? '#059669' : '#dc2626' ?>">
                            <?= number_format($resumo['saldo_final'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
                        </div>
                    </div>
                    <?php if ($exibir_custos === 'true' && $insumo_custo): ?>
                    <div class="smile-summary-card">
                        <div class="label">Valor do Saldo</div>
                        <div class="value" style="color: #1e40af">
                            R$ <?= number_format($resumo['saldo_final'] * $insumo_custo['custo_corrigido'], 2) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Grid de Movimentos -->
        <?php if ($insumo_id && !empty($movimentos)): ?>
        <div class="smile-card">
            <div class="smile-card-body">
                <div class="smile-flex-between">
                    <h3>📋 Movimentos do Período</h3>
                    <div class="smile-flex smile-gap-1">
                        <?php if (lc_can_edit_contagem()): ?>
                        <button onclick="abrirModalAjuste()" class="smile-btn smile-btn-success">+ Adicionar Ajuste</button>
                        <?php endif; ?>
                        <button onclick="exportarCSV()" class="smile-btn smile-btn-secondary">📊 Exportar CSV</button>
                    </div>
                </div>
                
                <table class="smile-table">
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
                                <span class="smile-badge smile-badge-<?= $movimento['tipo'] === 'entrada' ? 'success' : ($movimento['tipo'] === 'consumo_evento' ? 'danger' : 'warning') ?>">
                                    <?= ucfirst(str_replace('_', ' ', $movimento['tipo'])) ?>
                                </span>
                            </td>
                            <td><?= number_format($movimento['quantidade_digitada'], 3) ?> <?= $movimento['unidade_digitada'] ?></td>
                            <td><?= number_format($movimento['quantidade_base'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?></td>
                            <td style="color: <?= $movimento['saldo_acumulado'] >= 0 ? '#059669' : '#dc2626' ?>; font-weight: 600;">
                                <?= number_format($movimento['saldo_acumulado'], 3) ?> <?= $insumo_selecionado['unidade_padrao'] ?>
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
        </div>
        
        <!-- Paginação -->
        <?php if ($total_registros > $limite): ?>
        <div class="smile-pagination">
            <?php
            $total_paginas = ceil($total_registros / $limite);
            $inicio_pagina = max(1, $pagina - 2);
            $fim_pagina = min($total_paginas, $pagina + 2);
            ?>
            
            <?php if ($pagina > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>" class="smile-btn smile-btn-secondary">« Anterior</a>
            <?php endif; ?>
            
            <?php for ($i = $inicio_pagina; $i <= $fim_pagina; $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>" 
                   class="<?= $i == $pagina ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($pagina < $total_paginas): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>" class="smile-btn smile-btn-secondary">Próxima »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php elseif ($insumo_id && empty($movimentos)): ?>
        <div class="smile-alert smile-alert-warning">
            <h3>Nenhum movimento encontrado</h3>
            <p>Não foram encontrados movimentos para o insumo selecionado no período especificado.</p>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        function abrirModalAjuste() {
            alert('Funcionalidade de ajuste será implementada em breve!');
        }
        
        function exportarCSV() {
            alert('Funcionalidade de exportação CSV será implementada em breve!');
        }
    </script>
</body>
</html>
