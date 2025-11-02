<?php
// config_fornecedores.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
ini_set('display_errors', 1); error_reporting(E_ALL);

// Permissão: apenas admin (ajuste se tiver outra flag específica)
if (!isset($_SESSION['logado']) || $_SESSION['logado'] != 1 || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão."; exit; }
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg    = '';
$err    = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $post_action = $_POST['post_action'] ?? '';
        if ($post_action === 'create' || $post_action === 'update') {
            $nome         = trim($_POST['nome'] ?? '');
            $whatsapp     = trim($_POST['whatsapp'] ?? '');
            $email        = trim($_POST['email'] ?? '');
            $categorias   = trim($_POST['categorias'] ?? ''); // texto livre para filtro
            $modo_padrao  = ($_POST['modo_padrao'] ?? 'consolidado') === 'separado_evento' ? 'separado_evento' : 'consolidado';
            $observacoes  = trim($_POST['observacoes'] ?? '');
            $ativo        = isset($_POST['ativo']) ? 1 : 0;

            if ($nome === '') { throw new Exception('Informe o nome do fornecedor.'); }

            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('E-mail inválido.');
            }

            if ($post_action === 'create') {
                $sql = "INSERT INTO lc_fornecedores (nome, whatsapp, email, categorias, modo_padrao, observacoes, ativo)
                        VALUES (:nome, :whatsapp, :email, :categorias, :modo_padrao, :observacoes, :ativo)";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':nome'=>$nome, ':whatsapp'=>$whatsapp, ':email'=>$email,
                    ':categorias'=>$categorias, ':modo_padrao'=>$modo_padrao,
                    ':observacoes'=>$observacoes, ':ativo'=>$ativo
                ]);
                $msg = 'Fornecedor criado.';
            } else {
                $idUpd = (int)($_POST['id'] ?? 0);
                if ($idUpd <= 0) throw new Exception('ID inválido.');
                $sql = "UPDATE lc_fornecedores
                           SET nome=:nome, whatsapp=:whatsapp, email=:email, categorias=:categorias,
                               modo_padrao=:modo_padrao, observacoes=:observacoes, ativo=:ativo
                         WHERE id=:id";
                $st = $pdo->prepare($sql);
                $st->execute([
                    ':nome'=>$nome, ':whatsapp'=>$whatsapp, ':email'=>$email,
                    ':categorias'=>$categorias, ':modo_padrao'=>$modo_padrao,
                    ':observacoes'=>$observacoes, ':ativo'=>$ativo, ':id'=>$idUpd
                ]);
                $msg = 'Fornecedor atualizado.';
            }
            header("Location: config_fornecedores.php?msg=".urlencode($msg));
            exit;
        }
    }

    if ($action === 'toggle' && $id > 0) {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT ativo FROM lc_fornecedores WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Registro não encontrado.');
        $novo = $row['ativo'] ? 0 : 1;
        $st = $pdo->prepare("UPDATE lc_fornecedores SET ativo=? WHERE id=?");
        $st->execute([$novo, $id]);
        $pdo->commit();
        $msg = 'Fornecedor '.($novo? 'ativado' : 'desativado').'.';
        header("Location: config_fornecedores.php?msg=".urlencode($msg));
        exit;
    }

    if ($action === 'delete' && $id > 0) {
        // Verifica se está vinculado a itens do cardápio (opcional endurecer regra)
        $stc = $pdo->prepare("SELECT COUNT(*) FROM lc_itens WHERE fornecedor_id=?");
        $stc->execute([$id]);
        $usos = (int)$stc->fetchColumn();
        if ($usos > 0) {
            throw new Exception('Não é possível excluir: fornecedor vinculado a itens do cardápio.');
        }
        $st = $pdo->prepare("DELETE FROM lc_fornecedores WHERE id=?");
        $st->execute([$id]);
        $msg = 'Fornecedor excluído.';
        header("Location: config_fornecedores.php?msg=".urlencode($msg));
        exit;
    }

} catch (Throwable $e) {
    $err = $e->getMessage();
}

// Busca/listagem
$busca = trim($_GET['q'] ?? '');
$params = [];
$sqlList = "SELECT id, nome, whatsapp, email, categorias, modo_padrao, observacoes, ativo, created_at, updated_at
            FROM lc_fornecedores";
if ($busca !== '') {
    $sqlList .= " WHERE nome LIKE :q OR categorias LIKE :q";
    $params[':q'] = "%{$busca}%";
}
$sqlList .= " ORDER BY nome ASC";
$st = $pdo->prepare($sqlList);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Edição
$editRow = null;
if ($action === 'edit' && $id > 0) {
    $st = $pdo->prepare("SELECT * FROM lc_fornecedores WHERE id=?");
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
        
        .badge.mode {
            background: #dbeafe;
            color: #1e40af;
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
        
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        @media (max-width: 900px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
        
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
            font-size: 0.875rem;
        }
        
        input[type="text"],
        input[type="email"],
        select,
        textarea {
            width: 100%;
            padding: 0.625rem 0.875rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: border-color 0.2s;
        }
        
        input[type="text"]:focus,
        input[type="email"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #1e3a8a;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
</style>

<div class="content-narrow">
        <div class="page-header">
            <h1>🤝 Fornecedores</h1>
            <p>Gerencie os fornecedores e suas informações de contato</p>
        </div>

    <div class="topbar">
        <form class="inline" method="get" action="config_fornecedores.php">
            <input class="input-sm" type="text" name="q" placeholder="Buscar por nome ou categorias..." value="<?=h($busca)?>">
            <button class="btn" type="submit">Buscar</button>
            <a class="btn link" href="config_fornecedores.php">Limpar</a>
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
            <legend><?= $editRow ? 'Editar fornecedor' : 'Novo fornecedor' ?></legend>
            <form method="post" action="config_fornecedores.php<?= $editRow ? '?action=edit&id='.$editRow['id'] : '' ?>">
                <?php if ($editRow): ?>
                    <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
                <?php endif; ?>
                <input type="hidden" name="post_action" value="<?= $editRow ? 'update' : 'create' ?>">

                <div class="grid">
                    <div>
                        <label>Nome *</label>
                        <input type="text" name="nome" value="<?= h($editRow['nome'] ?? '') ?>" required>
                    </div>
                    <div>
                        <label>WhatsApp</label>
                        <input type="text" name="whatsapp" placeholder="+55 12 9 9999-9999" value="<?= h($editRow['whatsapp'] ?? '') ?>">
                    </div>
                    <div>
                        <label>E-mail</label>
                        <input type="email" name="email" value="<?= h($editRow['email'] ?? '') ?>">
                    </div>
                    <div>
                        <label>Categorias atendidas</label>
                        <input type="text" name="categorias" placeholder="doces, salgados, bebidas..." value="<?= h($editRow['categorias'] ?? '') ?>">
                    </div>
                    <div>
                        <label>Modo padrão de envio</label>
                        <select name="modo_padrao">
                            <?php
                              $modo = $editRow['modo_padrao'] ?? 'consolidado';
                              $opts = ['consolidado'=>'Consolidado','separado_evento'=>'Separado por evento'];
                              foreach ($opts as $val=>$lab) {
                                  $sel = ($modo === $val) ? 'selected' : '';
                                  echo "<option value=\"".h($val)."\" $sel>".h($lab)."</option>";
                              }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label>Ativo</label><br>
                        <input type="checkbox" name="ativo" <?= !isset($editRow['ativo']) || (int)($editRow['ativo'])===1 ? 'checked' : '' ?>> habilitar
                    </div>
                </div>

                <div style="margin-top:10px">
                    <label>Observações de envio</label>
                    <textarea name="observacoes" rows="3" style="width:100%;"><?= h($editRow['observacoes'] ?? '') ?></textarea>
                </div>

                <div style="margin-top:12px;display:flex;gap:8px">
                    <button class="btn" type="submit"><?= $editRow ? 'Salvar alterações' : 'Adicionar' ?></button>
                    <?php if ($editRow): ?>
                        <a class="btn link" href="config_fornecedores.php">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
            <p class="note" style="margin-top:10px">O “Modo padrão de envio” define se as encomendas serão consolidadas ou divididas por evento ao gerar a lista. Você poderá mudar por lista.</p>
        </fieldset>
    </div>

    <div class="card">
        <h3 style="margin-top:0">Fornecedores</h3>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>Nome</th>
                    <th style="width:160px">WhatsApp</th>
                    <th style="width:220px">E-mail</th>
                    <th>Categorias</th>
                    <th style="width:160px">Modo</th>
                    <th style="width:120px">Ativo</th>
                    <th style="width:220px">Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8">Nenhum fornecedor cadastrado.</td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= h($r['nome']) ?></td>
                    <td><?= h($r['whatsapp']) ?></td>
                    <td><?= h($r['email']) ?></td>
                    <td><?= h($r['categorias']) ?></td>
                    <td>
                        <span class="badge mode"><?= $r['modo_padrao']==='separado_evento' ? 'Separado por evento' : 'Consolidado' ?></span>
                    </td>
                    <td>
                        <?php if ($r['ativo']): ?>
                            <span class="badge on">Ativo</span>
                        <?php else: ?>
                            <span class="badge off">Inativo</span>
                        <?php endif; ?>
                        <a class="actions" href="config_fornecedores.php?action=toggle&id=<?= (int)$r['id'] ?>">alternar</a>
                    </td>
                    <td class="actions">
                        <a href="config_fornecedores.php?action=edit&id=<?= (int)$r['id'] ?>">Editar</a>
                        <a href="config_fornecedores.php?action=delete&id=<?= (int)$r['id'] ?>" onclick="return confirm('Excluir este fornecedor?')">Excluir</a>
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

includeSidebar('Configurações - Fornecedores');
echo $conteudo;
endSidebar();
?>
