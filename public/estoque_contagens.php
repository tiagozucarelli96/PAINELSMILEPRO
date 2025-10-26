<?php
// estoque_contagens.php
// Listagem de contagens de estoque

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_units_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();

$msg = '';
$err = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $contagem_id = (int)($_POST['contagem_id'] ?? 0);
    
    try {
        if ($acao === 'fechar') {
            if (lc_can_close_contagem()) {
                $stmt = $pdo->prepare("UPDATE estoque_contagens SET status = 'fechada' WHERE id = :id");
                $stmt->execute([':id' => $contagem_id]);
                $msg = 'Contagem fechada com sucesso!';
            } else {
                $err = lc_get_permission_message('close');
            }
        }
    } catch (Exception $e) {
        $err = 'Erro: ' . $e->getMessage();
    }
}

// Filtros
$filtro_data = $_GET['data'] ?? '';
$pagina = (int)($_GET['pagina'] ?? 1);
$limite = 10;
$offset = ($pagina - 1) * $limite;

// Construir query com filtros
$where = "1=1";
$params = [];

if ($filtro_data) {
    $where .= " AND data_ref = :data";
    $params[':data'] = $filtro_data;
}

// Buscar contagens
$sql = "
    SELECT 
        c.*,
        u.nome as criado_por_nome,
        COUNT(ci.id) as total_itens
    FROM estoque_contagens c
    LEFT JOIN usuarios u ON u.id = c.criada_por
    LEFT JOIN estoque_contagem_itens ci ON ci.contagem_id = c.id
    WHERE $where
    GROUP BY c.id, u.nome
    ORDER BY c.data_ref DESC, c.criado_em DESC
    LIMIT $limite OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contagens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar total para paginação
$sql_count = "SELECT COUNT(*) FROM estoque_contagens WHERE $where";
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total_registros = (int)$stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $limite);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt($s, $fmt='d/m/Y H:i') { return $s ? date($fmt, strtotime($s)) : ''; }
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contagens de Estoque - Painel Smile PRO</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .filters {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filters form {
            display: flex;
            gap: 15px;
            align-items: end;
        }
        .table-container {
            overflow-x: auto;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
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
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-rascunho {
            background: #fff3cd;
            color: #856404;
        }
        .status-fechada {
            background: #d4edda;
            color: #155724;
        }
        .actions {
            white-space: nowrap;
        }
        .btn {
            padding: 6px 12px;
            margin: 2px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 12px;
        }
        .btn-primary {
            background: #007bff;
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
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin-top: 20px;
        }
        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-decoration: none;
            color: #007bff;
        }
        .pagination .current {
            background: #007bff;
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-actions">
            <h1>Contagens de Estoque</h1>
            <div>
                <a href="estoque_alertas.php" class="btn btn-danger" style="margin-right: 10px;">🚨 Alertas de Ruptura</a>
                <a href="estoque_desvios.php" class="btn btn-secondary" style="margin-right: 10px;">📊 Relatório de Desvios</a>
                <a href="config_insumos.php" class="btn btn-outline" style="margin-right: 10px;">📦 Configurar Insumos</a>
                <a href="lc_index.php" class="btn btn-outline" style="margin-right: 10px;">🏠 Voltar</a>
                <?php if (lc_can_create_contagem()): ?>
                    <a href="estoque_contar.php" class="btn btn-primary">Nova Contagem</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($msg): ?>
            <div class="alert success"><?= h($msg) ?></div>
        <?php endif; ?>

        <?php if ($err): ?>
            <div class="alert err"><?= h($err) ?></div>
        <?php endif; ?>

        <div class="filters">
            <form method="GET">
                <div>
                    <label>Filtrar por data:</label>
                    <input type="date" name="data" value="<?= h($filtro_data) ?>" class="input">
                </div>
                <button type="submit" class="btn btn-secondary">Filtrar</button>
                <?php if ($filtro_data): ?>
                    <a href="estoque_contagens.php" class="btn btn-secondary">Limpar</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>Nº</th>
                        <th>Data Ref.</th>
                        <th>Status</th>
                        <th>Criada por</th>
                        <th>Criado em</th>
                        <th>Itens</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($contagens)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px; color: #666;">
                                Nenhuma contagem encontrada.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($contagens as $contagem): ?>
                            <tr>
                                <td>#<?= $contagem['id'] ?></td>
                                <td><?= h($contagem['data_ref']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $contagem['status'] ?>">
                                        <?= ucfirst($contagem['status']) ?>
                                    </span>
                                </td>
                                <td><?= h($contagem['criado_por_nome'] ?: 'Sistema') ?></td>
                                <td><?= dt($contagem['criado_em']) ?></td>
                                <td><?= (int)$contagem['total_itens'] ?></td>
                                <td class="actions">
                                    <a href="estoque_contar.php?id=<?= $contagem['id'] ?>" class="btn btn-primary">
                                        <?= $contagem['status'] === 'rascunho' ? 'Editar' : 'Ver' ?>
                                    </a>
                                    
                                    <?php if ($contagem['status'] === 'rascunho' && lc_can_close_contagem()): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="acao" value="fechar">
                                            <input type="hidden" name="contagem_id" value="<?= $contagem['id'] ?>">
                                            <button type="submit" class="btn btn-success" 
                                                    onclick="return confirm('Fechar esta contagem?')">
                                                Fechar
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_paginas > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                    <?php if ($i == $pagina): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?pagina=<?= $i ?>&data=<?= h($filtro_data) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top: 30px; text-align: center;">
            <a href="lc_index.php" class="btn btn-secondary">← Voltar</a>
        </div>
    </div>
</body>
</html>
