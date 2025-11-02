<?php
// estoque_contagens.php
// Listagem de contagens de estoque

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_units_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

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


function dt($s, $fmt='d/m/Y H:i') { return $s ? date($fmt, strtotime($s)) : ''; }

// Suprimir warnings durante renderização
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@ini_set('display_errors', 0);

// Criar conteúdo da página usando output buffering
ob_start();
?>

<style>
        .page-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0 0 0.5rem 0;
        }
        
        .page-header p {
            font-size: 1.125rem;
            color: #64748b;
            margin: 0;
        }
        
        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .header-actions h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .header-actions > div {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
    </style>

<div class="container" style="max-width: 1400px; margin: 0 auto; padding: 1.5rem;">
        <div class="page-header">
            <h1>📊 Contagens de Estoque</h1>
            <p>Gerencie e visualize as contagens de estoque realizadas</p>
        </div>
        
        <div class="header-actions">
            <h1 style="display: none;">Contagens de Estoque</h1>
            <div>
                <a href="index.php?page=estoque_alertas" class="btn btn-danger">🚨 Alertas</a>
                <a href="index.php?page=config_insumos" class="btn btn-outline">📦 Insumos</a>
                <a href="index.php?page=logistico" class="btn btn-outline">🏠 Voltar</a>
                <?php if (lc_can_create_contagem()): ?>
                    <a href="estoque_contar.php" class="btn btn-primary">+ Nova Contagem</a>
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
                    <label for="data">Filtrar por data:</label>
                    <input type="date" name="data" id="data" value="<?= h($filtro_data) ?>" class="input">
                </div>
                <button type="submit" class="btn btn-primary">🔍 Filtrar</button>
                <?php if ($filtro_data): ?>
                    <a href="index.php?page=estoque_contagens" class="btn btn-secondary">Limpar</a>
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

    </div>

<?php
// Restaurar error_reporting antes de incluir sidebar
error_reporting(E_ALL);
@ini_set('display_errors', 0);

$conteudo = ob_get_clean();

// Verificar se houve algum erro no buffer
if (ob_get_level() > 0) {
    ob_end_clean();
}

includeSidebar('Estoque - Contagens');
echo $conteudo;
endSidebar();
?>
