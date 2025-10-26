<?php
// fornecedor_ver.php
// Visualização detalhada do fornecedor

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN', 'GERENTE', 'CONSULTA'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$fornecedor_id = intval($_GET['id'] ?? 0);
if (!$fornecedor_id) {
    header('Location: fornecedores.php?erro=fornecedor_nao_encontrado');
    exit;
}

// Buscar dados do fornecedor
$sql = "
    SELECT 
        f.*,
        COUNT(DISTINCT s.id) as total_solicitacoes,
        COUNT(DISTINCT s.id) FILTER (WHERE s.status = 'aguardando') as aguardando,
        COUNT(DISTINCT s.id) FILTER (WHERE s.status = 'aprovado') as aprovado,
        COUNT(DISTINCT s.id) FILTER (WHERE s.status = 'suspenso') as suspenso,
        COUNT(DISTINCT s.id) FILTER (WHERE s.status = 'recusado') as recusado,
        COUNT(DISTINCT s.id) FILTER (WHERE s.status = 'pago') as pago,
        COALESCE(SUM(s.valor) FILTER (WHERE s.status = 'pago'), 0) as valor_total_pago,
        COALESCE(SUM(s.valor) FILTER (WHERE s.status = 'aguardando'), 0) as valor_aguardando,
        COALESCE(SUM(s.valor) FILTER (WHERE s.status = 'aprovado'), 0) as valor_aprovado
    FROM fornecedores f
    LEFT JOIN lc_solicitacoes_pagamento s ON s.fornecedor_id = f.id
    WHERE f.id = ?
    GROUP BY f.id
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fornecedor_id]);
    $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fornecedor) {
        header('Location: fornecedores.php?erro=fornecedor_nao_encontrado');
        exit;
    }
} catch (Exception $e) {
    $erro = "Erro ao carregar fornecedor: " . $e->getMessage();
    $fornecedor = null;
}

// Buscar últimas solicitações
$solicitacoes = [];
if ($fornecedor) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                s.id, s.valor, s.status, s.criado_em, s.observacoes,
                u.nome as criador_nome
            FROM lc_solicitacoes_pagamento s
            LEFT JOIN usuarios u ON u.id = s.criador_id
            WHERE s.fornecedor_id = ?
            ORDER BY s.criado_em DESC
            LIMIT 10
        ");
        $stmt->execute([$fornecedor_id]);
        $solicitacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Solicitações podem não existir ainda
    }
}

// Função para obter classe CSS do status
function getStatusClass($status) {
    switch ($status) {
        case 'aguardando': return 'smile-badge-warning';
        case 'aprovado': return 'smile-badge-info';
        case 'suspenso': return 'smile-badge-danger';
        case 'recusado': return 'smile-badge-danger';
        case 'pago': return 'smile-badge-success';
        default: return 'smile-badge-secondary';
    }
}

// Função para obter texto do status
function getStatusText($status) {
    switch ($status) {
        case 'aguardando': return 'Aguardando';
        case 'aprovado': return 'Aprovado';
        case 'suspenso': return 'Suspenso';
        case 'recusado': return 'Recusado';
        case 'pago': return 'Pago';
        default: return ucfirst($status);
    }
}

// Função para mascarar PIX
function maskPix($pix_chave, $pix_tipo) {
    if (!$pix_chave) return '-';
    
    switch ($pix_tipo) {
        case 'cpf':
        case 'cnpj':
            return substr($pix_chave, 0, 3) . '***' . substr($pix_chave, -2);
        case 'email':
            $parts = explode('@', $pix_chave);
            return substr($parts[0], 0, 2) . '***@' . $parts[1];
        case 'celular':
            return substr($pix_chave, 0, 4) . '****' . substr($pix_chave, -2);
        default:
            return substr($pix_chave, 0, 8) . '***' . substr($pix_chave, -4);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($fornecedor['nome']) ?> - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .detail-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .detail-section.full-width {
            grid-column: 1 / -1;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 600;
            color: #374151;
        }
        
        .detail-value {
            color: #64748b;
            text-align: right;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #1e40af;
        }
        
        .stat-label {
            font-size: 14px;
            color: #64748b;
        }
        
        .token-section {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .token-url {
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 15px;
            font-family: monospace;
            font-size: 14px;
            word-break: break-all;
            margin: 10px 0;
        }
        
        .copy-btn {
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 12px;
            margin-right: 10px;
        }
        
        .copy-btn:hover {
            background: #1e3a8a;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="smile-card">
            <div class="smile-card-header">
                <h1>🏢 <?= htmlspecialchars($fornecedor['nome']) ?></h1>
                <p>Detalhes do fornecedor e histórico de solicitações</p>
            </div>
            
            <div class="smile-card-body">
                <?php if ($fornecedor): ?>
                    <!-- Estatísticas -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?= $fornecedor['total_solicitacoes'] ?></div>
                            <div class="stat-label">Total Solicitações</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?= $fornecedor['pago'] ?></div>
                            <div class="stat-label">Pagas</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?= $fornecedor['aguardando'] ?></div>
                            <div class="stat-label">Aguardando</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number">R$ <?= number_format($fornecedor['valor_total_pago'], 2, ',', '.') ?></div>
                            <div class="stat-label">Valor Pago</div>
                        </div>
                    </div>
                    
                    <!-- Detalhes do Fornecedor -->
                    <div class="detail-grid">
                        <div class="detail-section">
                            <h3>📋 Informações Básicas</h3>
                            
                            <div class="detail-row">
                                <span class="detail-label">Status</span>
                                <span class="detail-value">
                                    <span class="smile-badge <?= $fornecedor['ativo'] ? 'smile-badge-success' : 'smile-badge-danger' ?>">
                                        <?= $fornecedor['ativo'] ? 'Ativo' : 'Inativo' ?>
                                    </span>
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">CNPJ</span>
                                <span class="detail-value">
                                    <?= htmlspecialchars($fornecedor['cnpj'] ?: '-') ?>
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">Telefone</span>
                                <span class="detail-value">
                                    <?= htmlspecialchars($fornecedor['telefone'] ?: '-') ?>
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">E-mail</span>
                                <span class="detail-value">
                                    <?= htmlspecialchars($fornecedor['email'] ?: '-') ?>
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">Contato</span>
                                <span class="detail-value">
                                    <?= htmlspecialchars($fornecedor['contato_responsavel'] ?: '-') ?>
                                </span>
                            </div>
                            
                            <?php if ($fornecedor['categoria']): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Categoria</span>
                                    <span class="detail-value">
                                        <?= htmlspecialchars($fornecedor['categoria']) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="detail-section">
                            <h3>💰 Dados PIX</h3>
                            
                            <div class="detail-row">
                                <span class="detail-label">Tipo</span>
                                <span class="detail-value">
                                    <?= $fornecedor['pix_tipo'] ? strtoupper($fornecedor['pix_tipo']) : '-' ?>
                                </span>
                            </div>
                            
                            <div class="detail-row">
                                <span class="detail-label">Chave</span>
                                <span class="detail-value">
                                    <?php if ($fornecedor['pix_chave']): ?>
                                        <strong><?= maskPix($fornecedor['pix_chave'], $fornecedor['pix_tipo']) ?></strong>
                                    <?php else: ?>
                                        <span style="color: #dc2626;">Não cadastrado</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($fornecedor['endereco']): ?>
                            <div class="detail-section full-width">
                                <h3>📍 Endereço</h3>
                                <p style="color: #64748b; line-height: 1.6;"><?= nl2br(htmlspecialchars($fornecedor['endereco'])) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($fornecedor['observacoes']): ?>
                            <div class="detail-section full-width">
                                <h3>📝 Observações</h3>
                                <p style="color: #64748b; line-height: 1.6;"><?= nl2br(htmlspecialchars($fornecedor['observacoes'])) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($fornecedor['token_publico']): ?>
                            <div class="detail-section full-width">
                                <h3>🔗 Token Público</h3>
                                <div class="token-section">
                                    <p><strong>Link público do fornecedor:</strong></p>
                                    <div class="token-url" id="token-url">
                                        <?= $_SERVER['HTTP_HOST'] ?>/fornecedor_link.php?t=<?= htmlspecialchars($fornecedor['token_publico']) ?>
                                    </div>
                                    <button class="copy-btn" onclick="copyToken()">
                                        📋 Copiar Link
                                    </button>
                                    <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                                        <a href="fornecedor_regenerar_token.php?id=<?= $fornecedor_id ?>" 
                                           class="smile-btn smile-btn-warning"
                                           onclick="return confirm('Regenerar token? O link atual será invalidado.')">
                                            🔄 Regenerar Token
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Histórico de Solicitações -->
                    <div class="detail-section full-width">
                        <h3>📋 Últimas Solicitações</h3>
                        
                        <?php if (empty($solicitacoes)): ?>
                            <p style="color: #64748b; text-align: center; padding: 20px;">
                                Nenhuma solicitação encontrada
                            </p>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="smile-table">
                                    <thead>
                                        <tr>
                                            <th>Nº</th>
                                            <th>Valor</th>
                                            <th>Status</th>
                                            <th>Criado em</th>
                                            <th>Criado por</th>
                                            <th>Observações</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($solicitacoes as $solicitacao): ?>
                                            <tr>
                                                <td><?= $solicitacao['id'] ?></td>
                                                <td>
                                                    <strong>R$ <?= number_format($solicitacao['valor'], 2, ',', '.') ?></strong>
                                                </td>
                                                <td>
                                                    <span class="smile-badge <?= getStatusClass($solicitacao['status']) ?>">
                                                        <?= getStatusText($solicitacao['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= date('d/m/Y H:i', strtotime($solicitacao['criado_em'])) ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($solicitacao['criador_nome'] ?: 'Sistema') ?>
                                                </td>
                                                <td>
                                                    <?= htmlspecialchars($solicitacao['observacoes'] ?: '-') ?>
                                                </td>
                                                <td>
                                                    <a href="pagamentos_ver.php?id=<?= $solicitacao['id'] ?>" 
                                                       class="smile-btn smile-btn-sm smile-btn-primary">
                                                        👁️ Ver
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <div class="smile-alert smile-alert-danger">
                        ❌ Fornecedor não encontrado
                    </div>
                <?php endif; ?>
                
                <!-- Navegação -->
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                    <a href="fornecedores.php" class="smile-btn smile-btn-secondary">
                        ← Voltar para Fornecedores
                    </a>
                    <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                        <a href="fornecedor_editar.php?id=<?= $fornecedor_id ?>" class="smile-btn smile-btn-primary">
                            ✏️ Editar Fornecedor
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function copyToken() {
            const tokenUrl = document.getElementById('token-url').textContent;
            
            navigator.clipboard.writeText(tokenUrl).then(function() {
                alert('Link copiado para a área de transferência!');
            }).catch(function(err) {
                alert('Erro ao copiar: ' + err);
            });
        }
    </script>
</body>
</html>
