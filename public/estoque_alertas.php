<?php
// estoque_alertas.php
// Página de alertas de risco de ruptura

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_calc.php';
require_once __DIR__ . '/lc_units_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';
require_once __DIR__ . '/me_api_helper.php';
require_once __DIR__ . '/lc_substitutes_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();

$msg = '';
$err = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    try {
        if ($acao === 'calcular_sugestao') {
            // Processar cálculo de sugestão
            $parametros = [
                'horizonte_dias' => (int)($_POST['horizonte_dias'] ?? 7),
                'lead_time_dias' => (int)($_POST['lead_time_dias'] ?? 2),
                'estoque_seguranca_percent' => (float)($_POST['estoque_seguranca_percent'] ?? 10),
                'agrupamento' => $_POST['agrupamento'] ?? 'fornecedor_consolidado',
                'arredondar_embalagem' => isset($_POST['arredondar_embalagem']),
                'permitir_substitutos' => isset($_POST['permitir_substitutos'])
            ];
            
            $insumos_selecionados = $_POST['insumos_selecionados'] ?? [];
            
            if (empty($insumos_selecionados)) {
                throw new Exception('Selecione pelo menos um insumo.');
            }
            
            // Calcular sugestões
            $dados_sugestao = calcularSugestoesME($pdo, $insumos_selecionados, $parametros);
            
        } elseif ($acao === 'aplicar_substituto') {
            // Aplicar substituto
            $insumo_principal_id = (int)($_POST['insumo_principal_id'] ?? 0);
            $insumo_substituto_id = (int)($_POST['insumo_substituto_id'] ?? 0);
            $percentual_cobertura = (float)($_POST['percentual_cobertura'] ?? 100.0);
            
            if ($insumo_principal_id <= 0 || $insumo_substituto_id <= 0) {
                throw new Exception('IDs de insumos inválidos.');
            }
            
            if ($percentual_cobertura <= 0 || $percentual_cobertura > 100) {
                throw new Exception('Percentual de cobertura deve estar entre 0% e 100%.');
            }
            
            // Buscar dados do substituto
            $substituto = lc_buscar_substitutos($pdo, $insumo_principal_id);
            $substituto_escolhido = null;
            
            foreach ($substituto as $sub) {
                if ($sub['insumo_substituto_id'] == $insumo_substituto_id) {
                    $substituto_escolhido = $sub;
                    break;
                }
            }
            
            if (!$substituto_escolhido) {
                throw new Exception('Substituto não encontrado ou não aprovado.');
            }
            
            // Retornar dados do substituto para o JavaScript
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'substituto' => $substituto_escolhido,
                'percentual_cobertura' => $percentual_cobertura
            ]);
            exit;
            
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

/**
 * Calcular sugestões com integração ME Eventos
 */
function calcularSugestoesME(PDO $pdo, array $insumos_ids, array $parametros): array {
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
        
        // Calcular demanda usando ME Eventos
        $demanda = me_calcular_demanda_eventos($pdo, $insumo_id, $horizonte_total);
        
        // Se não houver demanda de eventos, usar média móvel
        if ($demanda <= 0) {
            $demanda = calcularDemandaMedia($pdo, $insumo_id, $horizonte_total);
        }
        
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
 * Calcular demanda por média móvel
 */
function calcularDemandaMedia(PDO $pdo, int $insumo_id, int $dias): float {
    // Buscar consumo médio dos últimos 30 dias
    $stmt = $pdo->prepare("
        SELECT AVG(quantidade) as consumo_medio
        FROM lc_compras_consolidadas 
        WHERE insumo_id = :insumo_id 
        AND data_compra >= CURRENT_DATE - INTERVAL '30 days'
    ");
    $stmt->execute([':insumo_id' => $insumo_id]);
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $consumo_medio = (float)($resultado['consumo_medio'] ?? 0);
    return $consumo_medio * $dias;
}

/**
 * Criar lista de sugestão
 */
function criarListaSugestao(PDO $pdo, array $sugestoes, array $parametros): int {
    // Criar cabeçalho da lista
    $resumo = "Sugestão automática ME (Horizonte: {$parametros['horizonte_dias']}d | Lead time: {$parametros['lead_time_dias']}d | Segurança: {$parametros['estoque_seguranca_percent']}%)";
    
    $stmt = $pdo->prepare("
        INSERT INTO lc_listas (tipo_lista, data_gerada, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome, status, resumo_eventos)
        VALUES ('compras', NOW(), 'Sugestão Automática ME', :resumo, :criado_por, :criado_por_nome, 'rascunho', :resumo)
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
            ':observacao' => "Sugestão ME - Demanda: {$sugestao['demanda']} | Segurança: {$sugestao['seguranca']}"
        ]);
    }
    
    return $lista_id;
}

// Buscar insumos com risco de ruptura
$stmt = $pdo->query("
    SELECT 
        i.id, i.nome, i.unidade_padrao, i.preco, i.fator_correcao,
        i.estoque_minimo, i.estoque_atual, i.fornecedor_id,
        f.nome as fornecedor_nome,
        c.nome as categoria_nome,
        u.simbolo as unidade_simbolo
    FROM lc_insumos i
    LEFT JOIN fornecedores f ON f.id = i.fornecedor_id
    LEFT JOIN lc_categorias c ON c.id = i.categoria_id
    LEFT JOIN lc_unidades u ON u.simbolo = i.unidade_padrao
    WHERE i.ativo = true 
    AND i.estoque_atual <= i.estoque_minimo
    ORDER BY (i.estoque_atual / NULLIF(i.estoque_minimo, 0)) ASC, i.nome
");
$insumos_criticos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas
$total_criticos = count($insumos_criticos);
$total_sem_fornecedor = 0;
$total_sem_preco = 0;

foreach ($insumos_criticos as $insumo) {
    if (!$insumo['fornecedor_id']) $total_sem_fornecedor++;
    if (!$insumo['preco'] || $insumo['preco'] <= 0) $total_sem_preco++;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function calcularDiasEstoque($atual, $minimo, $consumo_medio = null) {
    if ($consumo_medio && $consumo_medio > 0) {
        return floor($atual / $consumo_medio);
    }
    return $minimo > 0 ? floor($atual / $minimo) : 0;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alertas de Estoque - Painel Smile PRO</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .header {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border-left: 4px solid #dc3545;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #dc3545;
        }
        .stat-label {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        .alert-card {
            background: white;
            border: 1px solid #dc3545;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.1);
        }
        .alert-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .alert-title {
            font-weight: bold;
            color: #dc3545;
            font-size: 16px;
        }
        .alert-level {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .level-critical {
            background: #dc3545;
            color: white;
        }
        .level-warning {
            background: #ffc107;
            color: #000;
        }
        .alert-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 15px;
        }
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #eee;
        }
        .detail-label {
            font-weight: 500;
            color: #666;
        }
        .detail-value {
            font-weight: bold;
        }
        .alert-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            font-weight: 500;
        }
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        .btn-warning {
            background: #ffc107;
            color: #000;
        }
        .btn-primary {
            background: #1e3a8a;
            color: white;
        }
        .btn:hover {
            opacity: 0.8;
        }
        .no-alerts {
            text-align: center;
            padding: 40px;
            color: #666;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .no-alerts-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        .bulk-actions {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚨 Alertas de Risco de Ruptura</h1>
            <p>Insumos com estoque abaixo do mínimo estabelecido</p>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?= h($msg) ?></div>
        <?php endif; ?>

        <?php if ($err): ?>
            <div class="alert alert-error"><?= h($err) ?></div>
        <?php endif; ?>

        <?php if ($total_criticos > 0): ?>
            <!-- Estatísticas -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $total_criticos ?></div>
                    <div class="stat-label">Itens Críticos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $total_sem_fornecedor ?></div>
                    <div class="stat-label">Sem Fornecedor</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $total_sem_preco ?></div>
                    <div class="stat-label">Sem Preço</div>
                </div>
            </div>

            <!-- Ações em massa -->
            <?php if (in_array($perfil, ['ADM', 'OPER'])): ?>
                <div class="bulk-actions">
                    <h3 style="margin-top: 0;">Ações em Massa</h3>
                    <button type="button" class="btn btn-primary" onclick="abrirModalSugestao([<?= implode(',', array_column($insumos_criticos, 'id')) ?>])">
                        📋 Sugerir Compra (Todos os Críticos)
                    </button>
                </div>
            <?php endif; ?>

            <!-- Lista de alertas -->
            <?php foreach ($insumos_criticos as $insumo): ?>
                <?php
                $percentual_estoque = $insumo['estoque_minimo'] > 0 ? 
                    ($insumo['estoque_atual'] / $insumo['estoque_minimo']) * 100 : 0;
                $nivel_alerta = $percentual_estoque <= 50 ? 'critical' : 'warning';
                $dias_estoque = calcularDiasEstoque($insumo['estoque_atual'], $insumo['estoque_minimo']);
                ?>
                
                <div class="alert-card">
                    <div class="alert-header">
                        <div class="alert-title">
                            <?= h($insumo['nome']) ?>
                            <?php if ($insumo['categoria_nome']): ?>
                                <small style="color: #666;">(<?= h($insumo['categoria_nome']) ?>)</small>
                            <?php endif; ?>
                        </div>
                        <span class="alert-level level-<?= $nivel_alerta ?>">
                            <?= $nivel_alerta === 'critical' ? 'CRÍTICO' : 'ATENÇÃO' ?>
                        </span>
                    </div>
                    
                    <div class="alert-details">
                        <div class="detail-item">
                            <span class="detail-label">Estoque Atual:</span>
                            <span class="detail-value" style="color: #dc3545;">
                                <?= number_format($insumo['estoque_atual'], 2, ',', '.') ?> <?= h($insumo['unidade_simbolo']) ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Estoque Mínimo:</span>
                            <span class="detail-value">
                                <?= number_format($insumo['estoque_minimo'], 2, ',', '.') ?> <?= h($insumo['unidade_simbolo']) ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Percentual:</span>
                            <span class="detail-value" style="color: #dc3545;">
                                <?= number_format($percentual_estoque, 1, ',', '.') ?>%
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Dias de Estoque:</span>
                            <span class="detail-value">
                                ~<?= $dias_estoque ?> dias
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Fornecedor:</span>
                            <span class="detail-value">
                                <?= $insumo['fornecedor_nome'] ? h($insumo['fornecedor_nome']) : '<em style="color: #dc3545;">Não definido</em>' ?>
                            </span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Custo Unit.:</span>
                            <span class="detail-value">
                                <?php if ($insumo['preco'] && $insumo['preco'] > 0): ?>
                                    R$ <?= number_format($insumo['preco'] * ($insumo['fator_correcao'] ?? 1), 2, ',', '.') ?>
                                <?php else: ?>
                                    <em style="color: #dc3545;">Não cadastrado</em>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="alert-actions">
                        <?php if (in_array($perfil, ['ADM', 'OPER'])): ?>
                            <button type="button" class="btn btn-primary" onclick="abrirModalSugestao([<?= $insumo['id'] ?>])">
                                📋 Gerar Sugestão de Compra
                            </button>
                        <?php endif; ?>
                        
                        <a href="config_insumos.php?id=<?= $insumo['id'] ?>" class="btn btn-warning">
                            ⚙️ Editar Insumo
                        </a>
                        
                        <a href="estoque_contar.php" class="btn btn-secondary">
                            📦 Fazer Contagem
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
            
        <?php else: ?>
            <div class="no-alerts">
                <div class="no-alerts-icon">✅</div>
                <h3>Nenhum Alerta de Ruptura</h3>
                <p>Todos os insumos estão com estoque acima do mínimo estabelecido.</p>
                <a href="estoque_contagens.php" class="btn btn-primary">Ver Contagens</a>
            </div>
        <?php endif; ?>

        <div style="margin-top: 30px; text-align: center;">
            <a href="estoque_contagens.php" class="btn btn-secondary">← Voltar para Contagens</a>
            <a href="config_insumos.php" class="btn btn-secondary">⚙️ Configurar Insumos</a>
        </div>
    </div>

    <!-- Modal de Sugestão -->
    <div id="modalSugestao" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📋 Sugestão de Compra</h2>
                <span class="close" onclick="fecharModalSugestao()">&times;</span>
            </div>
            
            <form id="formSugestao" method="POST">
                <input type="hidden" name="acao" value="calcular_sugestao">
                <input type="hidden" id="insumosSelecionados" name="insumos_selecionados[]">
                
                <div class="modal-body">
                    <!-- Parâmetros -->
                    <div class="parameters-section">
                        <h3>Parâmetros de Cálculo</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Horizonte de cobertura (dias):</label>
                                <input type="number" name="horizonte_dias" value="7" min="1" max="30" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Lead time de reposição (dias):</label>
                                <input type="number" name="lead_time_dias" value="2" min="0" max="30" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Estoque de segurança (%):</label>
                                <input type="number" name="estoque_seguranca_percent" value="10" min="0" max="100" step="0.1" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Modo de agrupamento:</label>
                                <select name="agrupamento">
                                    <option value="fornecedor_consolidado">Fornecedor consolidado</option>
                                    <option value="fornecedor_por_evento">Fornecedor por evento</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-options">
                            <label>
                                <input type="checkbox" name="arredondar_embalagem" checked>
                                Arredondar por embalagem
                            </label>
                            <label>
                                <input type="checkbox" name="permitir_substitutos" disabled>
                                Permitir substitutos (futuro)
                            </label>
                        </div>
                    </div>
                    
                    <!-- Seleção de insumos -->
                    <div class="insumos-section">
                        <h3>Insumos Selecionados</h3>
                        <div id="listaInsumos" class="insumos-list">
                            <!-- Será preenchido via JavaScript -->
                        </div>
                    </div>
                    
                    <!-- Prévia (aparece após calcular) -->
                    <div id="previaSection" style="display: none;">
                        <h3>Prévia das Sugestões</h3>
                        <div id="previaContent">
                            <!-- Será preenchido após cálculo -->
                        </div>
                    </div>
                </div>
                
                <!-- Submodal de Substitutos -->
                <div id="submodalSubstitutos" class="submodal" style="display: none;">
                    <div class="submodal-content">
                        <div class="submodal-header">
                            <h3>🔄 Sugerir Substituto</h3>
                            <span class="close" onclick="fecharSubmodalSubstitutos()">&times;</span>
                        </div>
                        
                        <div class="submodal-body">
                            <div id="substitutoInfo">
                                <!-- Informações do insumo principal -->
                            </div>
                            
                            <div class="substitutos-list">
                                <h4>Substitutos Aprovados</h4>
                                <div id="listaSubstitutos">
                                    <!-- Lista de substitutos disponíveis -->
                                </div>
                            </div>
                            
                            <div class="cobertura-section">
                                <h4>% de Cobertura</h4>
                                <div class="slider-container">
                                    <input type="range" id="percentualCobertura" min="1" max="100" value="100" 
                                           oninput="atualizarPercentual(this.value)">
                                    <span id="percentualDisplay">100%</span>
                                </div>
                                <small>Defina quanto do insumo principal será coberto por este substituto</small>
                            </div>
                            
                            <div id="previaSubstituto" style="display: none;">
                                <h4>Prévia do Substituto</h4>
                                <div id="dadosPreviaSubstituto">
                                    <!-- Dados da prévia -->
                                </div>
                            </div>
                        </div>
                        
                        <div class="submodal-footer">
                            <button type="button" class="btn btn-secondary" onclick="fecharSubmodalSubstitutos()">Cancelar</button>
                            <button type="button" class="btn btn-primary" id="btnAplicarSubstituto" onclick="aplicarSubstituto()" disabled>Aplicar Substituto</button>
                        </div>
                    </div>
                </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalSugestao()">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="calcularSugestao()">🧮 Calcular</button>
                    <button type="button" class="btn btn-success" id="btnSalvar" onclick="salvarRascunho()" style="display: none;">💾 Salvar Rascunho</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border: none;
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            background: linear-gradient(135deg, #1e3a8a 0%, #d97706 100%);
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            margin: 0;
        }
        
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            opacity: 0.7;
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #eee;
            text-align: right;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
        }
        
        .parameters-section, .insumos-section {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
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
        
        .form-options {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .form-options label {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .insumos-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
        }
        
        .insumo-item {
            display: flex;
            align-items: center;
            padding: 8px;
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
        
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .preview-table th, .preview-table td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #eee;
            font-size: 12px;
        }
        
        .preview-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .preview-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .total-row {
            background: #e7f3ff;
            font-weight: bold;
        }
        
        .badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
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
        
        /* Estilos para submodal de substitutos */
        .submodal {
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
        }
        
        .submodal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 0;
            border: none;
            width: 80%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }
        
        .submodal-header {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 15px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .submodal-header h3 {
            margin: 0;
            font-size: 18px;
        }
        
        .submodal-body {
            padding: 20px;
        }
        
        .submodal-footer {
            padding: 15px;
            border-top: 1px solid #eee;
            text-align: right;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
        }
        
        .substitutos-list {
            margin-bottom: 20px;
        }
        
        .substituto-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .substituto-item:hover {
            background: #f8f9fa;
            border-color: #28a745;
        }
        
        .substituto-item.selected {
            background: #e7f3ff;
            border-color: #1e3a8a;
        }
        
        .substituto-item input[type="radio"] {
            margin-right: 10px;
        }
        
        .substituto-info {
            flex: 1;
        }
        
        .substituto-name {
            font-weight: 600;
            color: #333;
        }
        
        .substituto-details {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
        
        .cobertura-section {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .slider-container {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
        }
        
        .slider-container input[type="range"] {
            flex: 1;
            height: 6px;
            border-radius: 3px;
            background: #ddd;
            outline: none;
        }
        
        .slider-container input[type="range"]::-webkit-slider-thumb {
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #28a745;
            cursor: pointer;
        }
        
        .slider-container span {
            font-weight: bold;
            color: #28a745;
            min-width: 40px;
        }
        
        .previa-substituto {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .previa-substituto h5 {
            margin: 0 0 10px 0;
            color: #1e3a8a;
        }
        
        .previa-row {
            display: flex;
            justify-content: space-between;
            margin: 5px 0;
        }
        
        .previa-label {
            font-weight: 500;
            color: #666;
        }
        
        .previa-value {
            font-weight: bold;
            color: #333;
        }
        
        .btn-substituto {
            background: #28a745;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            margin-left: 10px;
        }
        
        .btn-substituto:hover {
            background: #218838;
        }
        
        .btn-substituto:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }
    </style>

    <script>
        let insumosDisponiveis = <?= json_encode($insumos_criticos) ?>;
        let sugestoesCalculadas = null;
        let substitutosDisponiveis = {};
        let insumoPrincipalAtual = null;
        let substitutoSelecionado = null;
        
        function abrirModalSugestao(insumosIds) {
            document.getElementById('modalSugestao').style.display = 'block';
            document.getElementById('insumosSelecionados').value = insumosIds.join(',');
            
            // Preencher lista de insumos
            const listaInsumos = document.getElementById('listaInsumos');
            listaInsumos.innerHTML = '';
            
            insumosDisponiveis.forEach(insumo => {
                const isSelected = insumosIds.includes(insumo.id);
                const item = document.createElement('div');
                item.className = 'insumo-item';
                item.innerHTML = `
                    <input type="checkbox" name="insumos_selecionados[]" value="${insumo.id}" ${isSelected ? 'checked' : ''}>
                    <div class="insumo-info">
                        <div class="insumo-name">${insumo.nome}</div>
                        <div class="insumo-details">
                            Estoque: ${parseFloat(insumo.estoque_atual).toFixed(2)} ${insumo.unidade_simbolo} | 
                            Mínimo: ${parseFloat(insumo.estoque_minimo).toFixed(2)} ${insumo.unidade_simbolo} | 
                            Fornecedor: ${insumo.fornecedor_nome || 'Não definido'}
                            ${(!insumo.preco || insumo.preco <= 0) ? '<span class="badge badge-danger">Sem preço</span>' : ''}
                        </div>
                    </div>
                `;
                listaInsumos.appendChild(item);
            });
            
            // Resetar prévia
            document.getElementById('previaSection').style.display = 'none';
            document.getElementById('btnSalvar').style.display = 'none';
        }
        
        function fecharModalSugestao() {
            document.getElementById('modalSugestao').style.display = 'none';
        }
        
        function calcularSugestao() {
            const form = document.getElementById('formSugestao');
            const formData = new FormData(form);
            
            // Coletar insumos selecionados
            const insumosSelecionados = [];
            document.querySelectorAll('input[name="insumos_selecionados[]"]:checked').forEach(cb => {
                insumosSelecionados.push(cb.value);
            });
            
            if (insumosSelecionados.length === 0) {
                alert('Selecione pelo menos um insumo.');
                return;
            }
            
            // Adicionar insumos selecionados ao form
            formData.delete('insumos_selecionados[]');
            insumosSelecionados.forEach(id => {
                formData.append('insumos_selecionados[]', id);
            });
            
            // Enviar requisição
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // Extrair dados da resposta (simulação)
                // Em produção, retornar JSON
                mostrarPrevia(insumosSelecionados);
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao calcular sugestões.');
            });
        }
        
        function mostrarPrevia(insumosIds) {
            // Simular cálculo (em produção, viria do servidor)
            const sugestoes = [];
            let totalCusto = 0;
            
            insumosIds.forEach(id => {
                const insumo = insumosDisponiveis.find(i => i.id == id);
                if (insumo) {
                    const demanda = Math.random() * 10; // Simular
                    const seguranca = demanda * 0.1;
                    const necessidade = demanda + seguranca - parseFloat(insumo.estoque_atual);
                    
                    if (necessidade > 0) {
                        const sugerido = Math.ceil(necessidade);
                        const custo = sugerido * (parseFloat(insumo.preco) || 0);
                        totalCusto += custo;
                        
                        sugestoes.push({
                            insumo_id: id,
                            insumo_nome: insumo.nome,
                            unidade: insumo.unidade_simbolo,
                            saldo_atual: parseFloat(insumo.estoque_atual),
                            demanda: demanda,
                            seguranca: seguranca,
                            necessidade: necessidade,
                            sugerido: sugerido,
                            fornecedor: insumo.fornecedor_nome || '(Definir)',
                            custo: custo,
                            tem_preco: insumo.preco && insumo.preco > 0,
                            is_substituto: false
                        });
                    }
                }
            });
            
            sugestoesCalculadas = sugestoes;
            
            // Mostrar prévia
            atualizarPreviaComSubstitutos();
            
            document.getElementById('previaSection').style.display = 'block';
            document.getElementById('btnSalvar').style.display = 'inline-block';
        }
        
        function salvarRascunho() {
            if (!sugestoesCalculadas || sugestoesCalculadas.length === 0) {
                alert('Nenhuma sugestão para salvar.');
                return;
            }
            
            const form = document.getElementById('formSugestao');
            const formData = new FormData(form);
            formData.set('acao', 'salvar_rascunho');
            formData.set('sugestoes_json', JSON.stringify(sugestoesCalculadas));
            formData.set('parametros_json', JSON.stringify({
                horizonte_dias: formData.get('horizonte_dias'),
                lead_time_dias: formData.get('lead_time_dias'),
                estoque_seguranca_percent: formData.get('estoque_seguranca_percent'),
                agrupamento: formData.get('agrupamento'),
                arredondar_embalagem: formData.has('arredondar_embalagem')
            }));
            
            // Enviar requisição
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (response.ok) {
                    window.location.href = response.url;
                } else {
                    alert('Erro ao salvar rascunho.');
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao salvar rascunho.');
            });
        }
        
        // Funções para substitutos
        function abrirSubmodalSubstitutos(insumoId) {
            insumoPrincipalAtual = insumoId;
            document.getElementById('submodalSubstitutos').style.display = 'block';
            
            // Buscar substitutos para este insumo
            buscarSubstitutos(insumoId);
        }
        
        function fecharSubmodalSubstitutos() {
            document.getElementById('submodalSubstitutos').style.display = 'none';
            insumoPrincipalAtual = null;
            substitutoSelecionado = null;
        }
        
        function buscarSubstitutos(insumoId) {
            // Simular busca de substitutos (em produção, seria via AJAX)
            const substitutos = [
                {
                    id: 1,
                    nome: 'Leite Integral 1L',
                    unidade: 'L',
                    preco: 4.50,
                    fator_correcao: 1.0,
                    embalagem_multiplo: 12,
                    fornecedor: 'Fornecedor A',
                    equivalencia: 1.0,
                    prioridade: 1,
                    observacao: 'Substituto direto'
                },
                {
                    id: 2,
                    nome: 'Leite Desnatado 1L',
                    unidade: 'L',
                    preco: 4.20,
                    fator_correcao: 1.0,
                    embalagem_multiplo: 12,
                    fornecedor: 'Fornecedor B',
                    equivalencia: 1.0,
                    prioridade: 2,
                    observacao: 'Substituto alternativo'
                }
            ];
            
            substitutosDisponiveis[insumoId] = substitutos;
            renderizarSubstitutos(substitutos);
        }
        
        function renderizarSubstitutos(substitutos) {
            const lista = document.getElementById('listaSubstitutos');
            lista.innerHTML = '';
            
            if (substitutos.length === 0) {
                lista.innerHTML = '<p style="text-align: center; color: #666;">Nenhum substituto aprovado para este insumo.</p>';
                return;
            }
            
            substitutos.forEach(substituto => {
                const item = document.createElement('div');
                item.className = 'substituto-item';
                item.onclick = () => selecionarSubstituto(substituto);
                item.innerHTML = `
                    <input type="radio" name="substituto" value="${substituto.id}">
                    <div class="substituto-info">
                        <div class="substituto-name">${substituto.nome}</div>
                        <div class="substituto-details">
                            Unidade: ${substituto.unidade} | 
                            Preço: R$ ${substituto.preco.toFixed(2)} | 
                            Equivalência: ${substituto.equivalencia} | 
                            Fornecedor: ${substituto.fornecedor}
                            ${substituto.observacao ? '<br>Obs: ' + substituto.observacao : ''}
                        </div>
                    </div>
                `;
                lista.appendChild(item);
            });
        }
        
        function selecionarSubstituto(substituto) {
            substitutoSelecionado = substituto;
            
            // Atualizar seleção visual
            document.querySelectorAll('.substituto-item').forEach(item => {
                item.classList.remove('selected');
            });
            event.currentTarget.classList.add('selected');
            
            // Mostrar prévia
            mostrarPreviaSubstituto(substituto);
            
            // Habilitar botão de aplicar
            document.getElementById('btnAplicarSubstituto').disabled = false;
        }
        
        function mostrarPreviaSubstituto(substituto) {
            const percentual = document.getElementById('percentualCobertura').value;
            const insumoPrincipal = insumosDisponiveis.find(i => i.id == insumoPrincipalAtual);
            
            if (!insumoPrincipal) return;
            
            // Calcular necessidades
            const necessidadePrincipal = parseFloat(insumoPrincipal.estoque_atual) || 0;
            const necessidadeParcial = necessidadePrincipal * (percentual / 100);
            const necessidadeSubstituto = necessidadeParcial * substituto.equivalencia;
            
            // Aplicar arredondamento por embalagem
            let sugerido = necessidadeSubstituto;
            if (substituto.embalagem_multiplo > 0) {
                sugerido = Math.ceil(necessidadeSubstituto / substituto.embalagem_multiplo) * substituto.embalagem_multiplo;
            } else {
                sugerido = Math.ceil(necessidadeSubstituto * 100) / 100;
            }
            
            // Calcular custo
            const custoUnitario = substituto.preco * substituto.fator_correcao;
            const custoTotal = sugerido * custoUnitario;
            
            // Mostrar prévia
            document.getElementById('previaSubstituto').style.display = 'block';
            document.getElementById('dadosPreviaSubstituto').innerHTML = `
                <div class="previa-substituto">
                    <h5>Prévia do Substituto</h5>
                    <div class="previa-row">
                        <span class="previa-label">Insumo Principal:</span>
                        <span class="previa-value">${insumoPrincipal.nome}</span>
                    </div>
                    <div class="previa-row">
                        <span class="previa-label">Necessidade Principal:</span>
                        <span class="previa-value">${necessidadePrincipal.toFixed(2)} ${insumoPrincipal.unidade_simbolo}</span>
                    </div>
                    <div class="previa-row">
                        <span class="previa-label">% Cobertura:</span>
                        <span class="previa-value">${percentual}%</span>
                    </div>
                    <div class="previa-row">
                        <span class="previa-label">Necessidade Parcial:</span>
                        <span class="previa-value">${necessidadeParcial.toFixed(2)} ${insumoPrincipal.unidade_simbolo}</span>
                    </div>
                    <div class="previa-row">
                        <span class="previa-label">Equivalência:</span>
                        <span class="previa-value">${substituto.equivalencia}</span>
                    </div>
                    <div class="previa-row">
                        <span class="previa-label">Necessidade Substituto:</span>
                        <span class="previa-value">${necessidadeSubstituto.toFixed(2)} ${substituto.unidade}</span>
                    </div>
                    <div class="previa-row">
                        <span class="previa-label">Sugerido (com pack):</span>
                        <span class="previa-value"><strong>${sugerido.toFixed(2)} ${substituto.unidade}</strong></span>
                    </div>
                    <div class="previa-row">
                        <span class="previa-label">Custo Unitário:</span>
                        <span class="previa-value">R$ ${custoUnitario.toFixed(2)}</span>
                    </div>
                    <div class="previa-row">
                        <span class="previa-label">Custo Total:</span>
                        <span class="previa-value"><strong>R$ ${custoTotal.toFixed(2)}</strong></span>
                    </div>
                </div>
            `;
        }
        
        function atualizarPercentual(valor) {
            document.getElementById('percentualDisplay').textContent = valor + '%';
            
            if (substitutoSelecionado) {
                mostrarPreviaSubstituto(substitutoSelecionado);
            }
        }
        
        function aplicarSubstituto() {
            if (!substitutoSelecionado || !insumoPrincipalAtual) {
                alert('Selecione um substituto primeiro.');
                return;
            }
            
            const percentual = document.getElementById('percentualCobertura').value;
            
            // Simular aplicação do substituto
            const insumoPrincipal = insumosDisponiveis.find(i => i.id == insumoPrincipalAtual);
            const substituto = substitutoSelecionado;
            
            // Calcular necessidades
            const necessidadePrincipal = parseFloat(insumoPrincipal.estoque_atual) || 0;
            const necessidadeParcial = necessidadePrincipal * (percentual / 100);
            const necessidadeSubstituto = necessidadeParcial * substituto.equivalencia;
            
            // Aplicar arredondamento por embalagem
            let sugerido = necessidadeSubstituto;
            if (substituto.embalagem_multiplo > 0) {
                sugerido = Math.ceil(necessidadeSubstituto / substituto.embalagem_multiplo) * substituto.embalagem_multiplo;
            } else {
                sugerido = Math.ceil(necessidadeSubstituto * 100) / 100;
            }
            
            // Calcular custo
            const custoUnitario = substituto.preco * substituto.fator_correcao;
            const custoTotal = sugerido * custoUnitario;
            
            // Adicionar à prévia
            const novaSugestao = {
                insumo_id: substituto.id,
                insumo_nome: substituto.nome,
                unidade: substituto.unidade,
                fornecedor_id: null,
                fornecedor_nome: substituto.fornecedor,
                saldo_atual: 0,
                demanda: necessidadeSubstituto,
                seguranca: 0,
                necessidade: necessidadeSubstituto,
                sugerido: sugerido,
                custo_unitario: custoUnitario,
                custo_total: custoTotal,
                tem_preco: custoUnitario > 0,
                is_substituto: true,
                substituto_de: insumoPrincipal.nome,
                percentual_cobertura: percentual,
                equivalencia: substituto.equivalencia
            };
            
            if (!sugestoesCalculadas) {
                sugestoesCalculadas = [];
            }
            
            // Remover sugestão anterior do mesmo substituto se existir
            sugestoesCalculadas = sugestoesCalculadas.filter(s => !(s.is_substituto && s.insumo_id === substituto.id));
            
            // Adicionar nova sugestão
            sugestoesCalculadas.push(novaSugestao);
            
            // Atualizar prévia
            atualizarPreviaComSubstitutos();
            
            // Fechar submodal
            fecharSubmodalSubstitutos();
            
            // Mostrar mensagem de sucesso
            alert('Substituto aplicado com sucesso!');
        }
        
        function atualizarPreviaComSubstitutos() {
            if (!sugestoesCalculadas || sugestoesCalculadas.length === 0) return;
            
            const previaContent = document.getElementById('previaContent');
            let totalCusto = 0;
            
            sugestoesCalculadas.forEach(s => totalCusto += s.custo_total);
            
            previaContent.innerHTML = `
                <table class="preview-table">
                    <thead>
                        <tr>
                            <th>Insumo</th>
                            <th>Saldo</th>
                            <th>Demanda</th>
                            <th>Segurança</th>
                            <th>Necessário</th>
                            <th>Sugerido</th>
                            <th>Unidade</th>
                            <th>Fornecedor</th>
                            ${<?= lc_can_view_stock_value() ? 'true' : 'false' ?> ? '<th>Custo Est.</th>' : ''}
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${sugestoesCalculadas.map(s => `
                            <tr ${s.is_substituto ? 'style="background: #e7f3ff;"' : ''}>
                                <td>
                                    <strong>${s.insumo_nome}</strong>
                                    ${s.is_substituto ? '<br><small style="color: #28a745;">🔄 Substituto de: ' + s.substituto_de + '</small>' : ''}
                                </td>
                                <td>${s.saldo_atual.toFixed(2)}</td>
                                <td>${s.demanda.toFixed(2)}</td>
                                <td>${s.seguranca.toFixed(2)}</td>
                                <td>${s.necessidade.toFixed(2)}</td>
                                <td><strong>${s.sugerido.toFixed(2)}</strong></td>
                                <td>${s.unidade}</td>
                                <td>${s.fornecedor_nome} ${s.fornecedor_nome === '(Definir)' ? '<span class="badge badge-warning">Atenção</span>' : ''}</td>
                                ${<?= lc_can_view_stock_value() ? 'true' : 'false' ?> ? `
                                    <td>${s.tem_preco ? '<strong>R$ ' + s.custo_total.toFixed(2) + '</strong>' : '<span class="badge badge-danger">Sem preço</span>'}</td>
                                ` : ''}
                                <td>
                                    ${!s.is_substituto ? `<button class="btn-substituto" onclick="abrirSubmodalSubstitutos(${s.insumo_id})">🔄 Substituto</button>` : ''}
                                </td>
                            </tr>
                        `).join('')}
                        ${<?= lc_can_view_stock_value() ? 'true' : 'false' ?> ? `
                            <tr class="total-row">
                                <td colspan="8"><strong>Total Estimado</strong></td>
                                <td><strong>R$ ${totalCusto.toFixed(2)}</strong></td>
                                <td></td>
                            </tr>
                        ` : ''}
                    </tbody>
                </table>
            `;
        }
        
        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById('modalSugestao');
            const submodal = document.getElementById('submodalSubstitutos');
            
            if (event.target === modal) {
                fecharModalSugestao();
            } else if (event.target === submodal) {
                fecharSubmodalSubstitutos();
            }
        }
    </script>
</body>
</html>
