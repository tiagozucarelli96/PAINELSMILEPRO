<?php
// fornecedores.php
// Lista e gerenciamento de fornecedores

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

$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Filtros
$status_filtro = $_GET['status'] ?? '';
$categoria_filtro = $_GET['categoria'] ?? '';
$busca = $_GET['busca'] ?? '';

// Construir query
$where_conditions = [];
$params = [];

// Filtro por status
if ($status_filtro) {
    $where_conditions[] = "f.ativo = ?";
    $params[] = $status_filtro === 'ativo';
}

// Filtro por categoria
if ($categoria_filtro) {
    $where_conditions[] = "f.categoria = ?";
    $params[] = $categoria_filtro;
}

// Filtro por busca
if ($busca) {
    $where_conditions[] = "(f.nome ILIKE ? OR f.cnpj ILIKE ? OR f.email ILIKE ?)";
    $search_term = '%' . $busca . '%';
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$where_sql = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar fornecedores
$sql = "
    SELECT 
        f.*,
        COUNT(DISTINCT s.id) as total_solicitacoes,
        COUNT(DISTINCT s.id) FILTER (WHERE s.status = 'pago') as solicitacoes_pagas,
        COALESCE(SUM(s.valor) FILTER (WHERE s.status = 'pago'), 0) as valor_total_pago
    FROM fornecedores f
    LEFT JOIN lc_solicitacoes_pagamento s ON s.fornecedor_id = f.id
    {$where_sql}
    GROUP BY f.id
    ORDER BY f.nome
    LIMIT 100
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $fornecedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao carregar fornecedores: " . $e->getMessage();
    $fornecedores = [];
}

// Buscar categorias para filtro
$categorias = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT categoria FROM fornecedores WHERE categoria IS NOT NULL ORDER BY categoria");
    $categorias = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Categorias podem não existir ainda
}

// Estatísticas
$stats = [
    'total' => 0,
    'ativos' => 0,
    'inativos' => 0,
    'com_token' => 0,
    'total_solicitacoes' => 0,
    'valor_total' => 0
];

foreach ($fornecedores as $fornecedor) {
    $stats['total']++;
    if ($fornecedor['ativo']) {
        $stats['ativos']++;
    } else {
        $stats['inativos']++;
    }
    if ($fornecedor['token_publico']) {
        $stats['com_token']++;
    }
    $stats['total_solicitacoes'] += $fornecedor['total_solicitacoes'];
    $stats['valor_total'] += $fornecedor['valor_total_pago'];
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
    <title>Fornecedores - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border-left: 4px solid #1e40af;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 16px;
            color: #64748b;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 18px;
            font-weight: 600;
            color: #10b981;
        }
        
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .token-section {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
        }
        
        .token-url {
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 10px;
            font-family: monospace;
            font-size: 14px;
            word-break: break-all;
        }
        
        .copy-btn {
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 12px;
            margin-left: 10px;
        }
        
        .copy-btn:hover {
            background: #1e3a8a;
        }
        
        .table-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 4px 8px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
        }
        
        .action-btn.view {
            background: #3b82f6;
            color: white;
        }
        
        .action-btn.edit {
            background: #f59e0b;
            color: white;
        }
        
        .action-btn.token {
            background: #10b981;
            color: white;
        }
        
        .action-btn.regenerate {
            background: #8b5cf6;
            color: white;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="smile-card">
            <div class="smile-card-header">
                <h1>🏢 Fornecedores</h1>
                <p>Gerencie fornecedores e seus tokens públicos</p>
            </div>
            
            <div class="smile-card-body">
                <!-- Mensagens -->
                <?php if ($sucesso): ?>
                    <div class="smile-alert smile-alert-success">
                        ✅ <?= htmlspecialchars($sucesso) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($erro): ?>
                    <div class="smile-alert smile-alert-danger">
                        ❌ <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>
                
                <!-- Estatísticas -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['total'] ?></div>
                        <div class="stat-label">Total</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['ativos'] ?></div>
                        <div class="stat-label">Ativos</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['com_token'] ?></div>
                        <div class="stat-label">Com Token</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?= $stats['total_solicitacoes'] ?></div>
                        <div class="stat-label">Solicitações</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">R$ <?= number_format($stats['valor_total'], 2, ',', '.') ?></div>
                        <div class="stat-label">Valor Total Pago</div>
                    </div>
                </div>
                
                <!-- Filtros -->
                <div class="filters-section">
                    <h3>🔍 Filtros</h3>
                    
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="smile-form-group">
                                <label for="status">Status</label>
                                <select name="status" id="status" class="smile-form-control">
                                    <option value="">Todos</option>
                                    <option value="ativo" <?= $status_filtro === 'ativo' ? 'selected' : '' ?>>Ativos</option>
                                    <option value="inativo" <?= $status_filtro === 'inativo' ? 'selected' : '' ?>>Inativos</option>
                                </select>
                            </div>
                            
                            <div class="smile-form-group">
                                <label for="categoria">Categoria</label>
                                <select name="categoria" id="categoria" class="smile-form-control">
                                    <option value="">Todas</option>
                                    <?php foreach ($categorias as $categoria): ?>
                                        <option value="<?= htmlspecialchars($categoria) ?>" 
                                                <?= $categoria_filtro === $categoria ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($categoria) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="smile-form-group">
                                <label for="busca">Buscar</label>
                                <input type="text" name="busca" id="busca" 
                                       class="smile-form-control" placeholder="Nome, CNPJ, e-mail..."
                                       value="<?= htmlspecialchars($busca) ?>">
                            </div>
                            
                            <div class="smile-form-group">
                                <label>&nbsp;</label>
                                <button type="submit" class="smile-btn smile-btn-primary">
                                    🔍 Filtrar
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Tabela de Fornecedores -->
                <div style="background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <table class="smile-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>CNPJ</th>
                                <th>PIX</th>
                                <th>Status</th>
                                <th>Token</th>
                                <th>Solicitações</th>
                                <th>Valor Pago</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fornecedores)): ?>
                                <tr>
                                    <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">
                                        Nenhum fornecedor encontrado
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($fornecedores as $fornecedor): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($fornecedor['nome']) ?></strong>
                                            <?php if ($fornecedor['categoria']): ?>
                                                <br><small style="color: #64748b;"><?= htmlspecialchars($fornecedor['categoria']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($fornecedor['cnpj'] ?: '-') ?>
                                        </td>
                                        <td>
                                            <?php if ($fornecedor['pix_tipo'] && $fornecedor['pix_chave']): ?>
                                                <strong><?= strtoupper($fornecedor['pix_tipo']) ?>:</strong><br>
                                                <small><?= maskPix($fornecedor['pix_chave'], $fornecedor['pix_tipo']) ?></small>
                                            <?php else: ?>
                                                <span style="color: #dc2626;">Não cadastrado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="smile-badge <?= $fornecedor['ativo'] ? 'smile-badge-success' : 'smile-badge-danger' ?>">
                                                <?= $fornecedor['ativo'] ? 'Ativo' : 'Inativo' ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($fornecedor['token_publico']): ?>
                                                <div class="token-section">
                                                    <div class="token-url" id="token-<?= $fornecedor['id'] ?>">
                                                        <?= $_SERVER['HTTP_HOST'] ?>/fornecedor_link.php?t=<?= htmlspecialchars($fornecedor['token_publico']) ?>
                                                    </div>
                                                    <button class="copy-btn" onclick="copyToken(<?= $fornecedor['id'] ?>)">
                                                        📋 Copiar
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: #64748b;">Sem token</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= $fornecedor['total_solicitacoes'] ?></strong>
                                            <?php if ($fornecedor['solicitacoes_pagas'] > 0): ?>
                                                <br><small style="color: #10b981;"><?= $fornecedor['solicitacoes_pagas'] ?> pagas</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong>R$ <?= number_format($fornecedor['valor_total_pago'], 2, ',', '.') ?></strong>
                                        </td>
                                        <td>
                                            <div class="table-actions">
                                                <a href="fornecedor_ver.php?id=<?= $fornecedor['id'] ?>" 
                                                   class="action-btn view">👁️ Ver</a>
                                                
                                                <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                                                    <a href="fornecedor_editar.php?id=<?= $fornecedor['id'] ?>" 
                                                       class="action-btn edit">✏️ Editar</a>
                                                    
                                                    <?php if ($fornecedor['token_publico']): ?>
                                                        <a href="fornecedor_regenerar_token.php?id=<?= $fornecedor['id'] ?>" 
                                                           class="action-btn regenerate"
                                                           onclick="return confirm('Regenerar token? O link atual será invalidado.')">
                                                            🔄 Regenerar
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="fornecedor_gerar_token.php?id=<?= $fornecedor['id'] ?>" 
                                                           class="action-btn token">
                                                            🔑 Gerar Token
                                                        </a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Ações -->
                <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                    <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                        <a href="fornecedor_editar.php" class="smile-btn smile-btn-primary">
                            ➕ Novo Fornecedor
                        </a>
                    <?php endif; ?>
                    <a href="lc_index.php" class="smile-btn smile-btn-secondary">
                        🏠 Voltar
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function copyToken(fornecedorId) {
            const tokenElement = document.getElementById('token-' + fornecedorId);
            const tokenUrl = tokenElement.textContent;
            
            navigator.clipboard.writeText(tokenUrl).then(function() {
                // Feedback visual
                const btn = tokenElement.nextElementSibling;
                const originalText = btn.textContent;
                btn.textContent = '✅ Copiado!';
                btn.style.background = '#10b981';
                
                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = '#1e40af';
                }, 2000);
            }).catch(function(err) {
                alert('Erro ao copiar: ' + err);
            });
        }
    </script>
</body>
</html>
