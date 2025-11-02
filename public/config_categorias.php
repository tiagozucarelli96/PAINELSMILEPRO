<?php
// config_categorias.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); error_reporting(E_ALL);

// Permissão: apenas admin/gestão de usuários (ajuste se necessário)
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1 || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';



// Ações
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg    = '';
$err    = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_action = $_POST['post_action'] ?? '';
        if ($post_action === 'create' || $post_action === 'update') {
            $nome   = trim($_POST['nome'] ?? '');
            $ordem  = (int)($_POST['ordem'] ?? 0);
            $ativo  = isset($_POST['ativo']) ? 1 : 0;
            $mostrar= isset($_POST['mostrar_no_gerar']) ? 1 : 0;

            if ($nome === '') { throw new Exception('Informe o nome.'); }

            if ($post_action === 'create') {
                $sql = "INSERT INTO lc_categorias (nome, ordem, ativo, mostrar_no_gerar) VALUES (:nome, :ordem, :ativo, :mostrar)";
                $st = $pdo->prepare($sql);
                $st->execute([':nome'=>$nome, ':ordem'=>$ordem, ':ativo'=>$ativo, ':mostrar'=>$mostrar]);
                $msg = 'Categoria criada.';
            } else {
                $idUpd = (int)($_POST['id'] ?? 0);
                if ($idUpd <= 0) throw new Exception('ID inválido.');
                $sql = "UPDATE lc_categorias SET nome=:nome, ordem=:ordem, ativo=:ativo, mostrar_no_gerar=:mostrar WHERE id=:id";
                $st = $pdo->prepare($sql);
                $st->execute([':nome'=>$nome, ':ordem'=>$ordem, ':ativo'=>$ativo, ':mostrar'=>$mostrar, ':id'=>$idUpd]);
                $msg = 'Categoria atualizada.';
            }
            header("Location: config_categorias.php?msg=".urlencode($msg));
            exit;
        }
    }

    if ($action === 'toggle' && $id > 0) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT ativo FROM lc_categorias WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Registro não encontrado.');
        $novo = $row['ativo'] ? 0 : 1;
        $st = $pdo->prepare("UPDATE lc_categorias SET ativo=? WHERE id=?");
        $st->execute([$novo, $id]);
        $pdo->commit();
        $msg = 'Categoria '.($novo? 'ativada' : 'desativada').'.';
        header("Location: config_categorias.php?msg=".urlencode($msg));
        exit;
    }

    if ($action === 'toggle_mostrar' && $id > 0) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT mostrar_no_gerar FROM lc_categorias WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Registro não encontrado.');
        $novo = $row['mostrar_no_gerar'] ? 0 : 1;
        $st = $pdo->prepare("UPDATE lc_categorias SET mostrar_no_gerar=? WHERE id=?");
        $st->execute([$novo, $id]);
        $pdo->commit();
        $msg = 'Campo "Mostrar no Gerar" atualizado.';
        header("Location: config_categorias.php?msg=".urlencode($msg));
        exit;
    }

    if ($action === 'delete' && $id > 0) {
        $st = $pdo->prepare("DELETE FROM lc_categorias WHERE id=?");
        $st->execute([$id]);
        $msg = 'Categoria excluída.';
        header("Location: config_categorias.php?msg=".urlencode($msg));
        exit;
    }

} catch (Throwable $e) {
    $err = $e->getMessage();
}

// Busca para listagem
$busca = trim($_GET['q'] ?? '');
$params = [];
$sqlList = "SELECT id, nome, ordem, ativo, mostrar_no_gerar, created_at, updated_at
            FROM lc_categorias";
if ($busca !== '') {
    $sqlList .= " WHERE nome LIKE :q";
    $params[':q'] = "%{$busca}%";
}
$sqlList .= " ORDER BY ordem ASC, nome ASC";
$st = $pdo->prepare($sqlList);
$st->execute($params);
$categorias = $st->fetchAll(PDO::FETCH_ASSOC);

// Se for edição
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM lc_categorias WHERE id=?");
    $st->execute([$id]);
    $editRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$editRow) { $err = 'Registro não encontrado.'; $action = ''; }
}

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
        
        .content-narrow {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1.5rem;
        }
        
        .topbar {
            display: flex;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
        }
        
        .topbar .grow {
            flex: 1;
        }
        
        .input-sm {
            padding: 0.625rem 0.875rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            width: 100%;
            max-width: 340px;
            transition: border-color 0.2s;
        }
        
        .input-sm:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        
        .btn {
            background: #1e3a8a;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 0.625rem 1.25rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            font-size: 0.875rem;
        }
        
        .btn:hover {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(30, 58, 138, 0.2);
        }
        
        .btn.link {
            background: transparent;
            color: #1e3a8a;
            border: 1px solid #1e3a8a;
        }
        
        .btn.link:hover {
            background: #1e3a8a;
            color: white;
        }
        
        .btn.danger {
            background: #dc2626;
        }
        
        .btn.danger:hover {
            background: #b91c1c;
        }
        
        .badge {
            font-size: 0.75rem;
            padding: 0.375rem 0.75rem;
            border-radius: 999px;
            font-weight: 600;
            display: inline-block;
        }
        
        .badge.on {
            background: #d1fae5;
            color: #065f46;
        }
        
        .badge.off {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th, .table td {
            border-bottom: 1px solid #e5e7eb;
            padding: 0.875rem 1rem;
            text-align: left;
        }
        
        .table th {
            background: #1e3a8a;
            color: white;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .card {
            margin-bottom: 1.5rem;
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
        }
        
        h1 {
            margin-top: 0;
            color: #1e3a8a;
        }
        
        .actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .actions a {
            color: #1e3a8a;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        .actions a:hover {
            background: #f1f5f9;
        }
        
        .note {
            font-size: 0.813rem;
            color: #64748b;
            margin-top: 0.75rem;
        }
        
        form.inline {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        fieldset {
            border: 1px solid #e5e7eb;
            padding: 1.5rem;
            border-radius: 10px;
            background: white;
        }
        
        legend {
            padding: 0 0.75rem;
            color: #1e3a8a;
            font-weight: 700;
            font-size: 1.125rem;
        }
        
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
            font-size: 0.875rem;
        }
        
        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: border-color 0.2s;
        }
        
        input[type="text"]:focus,
        input[type="number"]:focus,
        select:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
    </style>

<div class="content-narrow">
        <div class="page-header">
            <h1>⚙️ Categorias</h1>
            <p>Gerencie as categorias de insumos e itens</p>
        </div>

    <div class="topbar">
        <form class="inline" method="get" action="config_categorias.php">
            <input class="input-sm" type="text" name="q" placeholder="Buscar categoria..." value="<?=h($busca)?>">
            <button class="btn" type="submit">Buscar</button>
            <a class="btn link" href="config_categorias.php">Limpar</a>
        </form>
        <div class="grow"></div>
        <a class="btn link" href="index.php?page=cadastros">← Voltar</a>
    </div>

        <?php if ($err): ?>
            <div class="card" style="border-left: 4px solid #dc2626; background: #fee2e2;">
                <p style="color: #991b1b; margin: 0;"><?= h($err) ?></p>
            </div>
        <?php elseif (isset($_GET['msg'])): ?>
            <div class="card" style="border-left: 4px solid #059669; background: #d1fae5;">
                <p style="color: #065f46; margin: 0;">✅ <?= h($_GET['msg']) ?></p>
            </div>
        <?php endif; ?>

    <div class="card">
        <fieldset>
            <legend><?= $editRow ? 'Editar categoria' : 'Nova categoria' ?></legend>
            <form method="post" action="config_categorias.php<?= $editRow ? '?action=edit&id='.$editRow['id'] : '' ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="post_action" value="<?= $editRow ? 'update' : 'create' ?>">
                <div style="display:grid;grid-template-columns:1fr 140px 160px 200px;gap:10px;align-items:end">
                    <div>
                        <label>Nome</label>
                        <input type="text" name="nome" value="<?= h($editRow['nome'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>Ordem</label>
                        <input type="number" name="ordem" value="<?= h($editRow['ordem'] ?? 0) ?>" min="0">
                    </div>
                    <div>
                        <label>Ativo</label><br>
                        <input type="checkbox" name="ativo" <?= !isset($editRow['ativo']) || (int)($editRow['ativo'])===1 ? 'checked' : '' ?>> mostrar
                    </div>
                    <div>
                        <label>Mostrar no Gerar Lista</label><br>
                        <input type="checkbox" name="mostrar_no_gerar" <?= !isset($editRow['mostrar_no_gerar']) || (int)($editRow['mostrar_no_gerar'])===1 ? 'checked' : '' ?>> habilitar
                    </div>
                </div>
                <div style="margin-top:12px;display:flex;gap:8px">
                    <button class="btn" type="submit"><?= $editRow ? 'Salvar alterações' : 'Adicionar' ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn link" href="config_categorias.php">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
            <p class="note" style="margin-top:10px">“Ativo” controla se a categoria aparece no sistema. “Mostrar no Gerar Lista” controla se o bloco aparece para seleção na tela de geração.</p>
        </fieldset>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Categorias</h3>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>Nome</th>
                    <th style="width:90px">Ordem</th>
                    <th style="width:120px">Ativo</th>
                    <th style="width:170px">Mostrar no Gerar</th>
                    <th style="width:220px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$categorias): ?>
                <tr><td colspan="6">Nenhuma categoria cadastrada.</td></tr>
            <?php else: foreach ($categorias as $c): ?>
                <tr>
                    <td><?= (int)$c['id'] ?></td>
                    <td><?= h($c['nome']) ?></td>
                    <td><?= (int)$c['ordem'] ?></td>
                    <td>
                        <?php if ($c['ativo']): ?>
                            <span class="badge on">Ativo</span>
                        <?php else: ?>
                            <span class="badge off">Inativo</span>
                        <?php endif; ?>
                        <a class="actions" href="config_categorias.php?action=toggle&id=<?= (int)$c['id'] ?>">alternar</a>
                    </td>
                    <td>
                        <?php if ($c['mostrar_no_gerar']): ?>
                            <span class="badge on">Habilitado</span>
                        <?php else: ?>
                            <span class="badge off">Desabilitado</span>
                        <?php endif; ?>
                        <a class="actions" href="config_categorias.php?action=toggle_mostrar&id=<?= (int)$c['id'] ?>">alternar</a>
                    </td>
                    <td class="actions">
                        <a href="config_categorias.php?action=edit&id=<?= (int)$c['id'] ?>">Editar</a>
                        <a href="config_categorias.php?action=delete&id=<?= (int)$c['id'] ?>" onclick="return confirm('Excluir esta categoria?')">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    </div>

<?php
// Restaurar error_reporting antes de incluir sidebar
error_reporting(E_ALL);
@ini_set('display_errors', 0);

$conteudo = ob_get_clean();

includeSidebar('Configurações - Categorias');
echo $conteudo;
endSidebar();
?>
