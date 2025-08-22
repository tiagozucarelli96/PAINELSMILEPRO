<?php
declare(strict_types=1);
// public/configurar.php — Configurações do módulo Lista de Compras (PostgreSQL/Railway)

// ========= Sessão / Auth =========
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == '443');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
}
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) { $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true); }
if (!$uid || !is_numeric($uid) || !$estaLogado) { http_response_code(403); echo "Acesso negado. Faça login para continuar."; exit; }

// ========= Conexão =========
require_once __DIR__ . '/conexao.php';
if (!isset($pdo) || !$pdo instanceof PDO) { echo "Falha na conexão com o banco de dados."; exit; }

// ========= Helpers =========
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function qs(array $extra=[]): string { $base = $_GET; foreach ($extra as $k=>$v){ if($v===null) unset($base[$k]); else $base[$k]=$v; } return http_build_query($base); }
function post($k,$d=''){ return $_POST[$k] ?? $d; }

$tab = (string)($_GET['tab'] ?? 'categorias');
if (!in_array($tab, ['categorias','fornecedores','insumos','fichas','itens'], true)) $tab='categorias';

$msg = '';
$err = '';

// ========= AÇÕES: CATEGORIAS =========
if ($_SERVER['REQUEST_METHOD']==='POST' && $tab==='categorias') {
    try {
        if (post('action')==='create') {
            $nome = trim((string)post('nome',''));
            $ordem = (int)post('ordem',0);
            if ($nome==='') throw new Exception('Informe o nome da categoria.');
            $st = $pdo->prepare("INSERT INTO lc_categorias (nome, ordem, ativo) VALUES (:n,:o, TRUE)");
            $st->execute([':n'=>$nome, ':o'=>$ordem]);
            $msg = 'Categoria criada.';
        } elseif (post('action')==='update') {
            $id = (int)post('id',0);
            $nome = trim((string)post('nome',''));
            $ordem = (int)post('ordem',0);
            $ativo = (string)post('ativo','1') === '1';
            if ($id<=0) throw new Exception('ID inválido.');
            if ($nome==='') throw new Exception('Informe o nome.');
            $st = $pdo->prepare("UPDATE lc_categorias SET nome=:n, ordem=:o, ativo=:a WHERE id=:id");
            $st->execute([':n'=>$nome, ':o'=>$ordem, ':a'=>$ativo?1:0, ':id'=>$id]);
            $msg = 'Categoria atualizada.';
        } elseif (post('action')==='toggle') {
            $id = (int)post('id',0);
            $ativo = (string)post('ativo','1') === '1';
            if ($id<=0) throw new Exception('ID inválido.');
            $st = $pdo->prepare("UPDATE lc_categorias SET ativo=:a WHERE id=:id");
            $st->execute([':a'=>$ativo?1:0, ':id'=>$id]);
            $msg = $ativo?'Categoria ativada.':'Categoria desativada.';
        }
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

// ========= AÇÕES: FORNECEDORES =========
if ($_SERVER['REQUEST_METHOD']==='POST' && $tab==='fornecedores') {
    try {
        if (post('action')==='create') {
            $nome = trim((string)post('nome',''));
            $modo = (string)post('modo_padrao','consolidado');
            $ativo = (string)post('ativo','1')==='1';
            if ($nome==='') throw new Exception('Informe o nome do fornecedor.');
            if (!in_array($modo,['consolidado','separado'],true)) $modo='consolidado';
            $st = $pdo->prepare("INSERT INTO fornecedores (nome, modo_padrao, ativo) VALUES (:n, :m, :a)");
            $st->execute([':n'=>$nome, ':m'=>$modo, ':a'=>$ativo?1:0]);
            $msg = 'Fornecedor criado.';
        } elseif (post('action')==='update') {
            $id = (int)post('id',0);
            $nome = trim((string)post('nome',''));
            $modo = (string)post('modo_padrao','consolidado');
            $ativo = (string)post('ativo','1')==='1';
            if ($id<=0) throw new Exception('ID inválido.');
            if ($nome==='') throw new Exception('Informe o nome.');
            if (!in_array($modo,['consolidado','separado'],true)) $modo='consolidado';
            $st = $pdo->prepare("UPDATE fornecedores SET nome=:n, modo_padrao=:m, ativo=:a WHERE id=:id");
            $st->execute([':n'=>$nome, ':m'=>$modo, ':a'=>$ativo?1:0, ':id'=>$id]);
            $msg = 'Fornecedor atualizado.';
        } elseif (post('action')==='toggle') {
            $id = (int)post('id',0);
            $ativo = (string)post('ativo','1')==='1';
            if ($id<=0) throw new Exception('ID inválido.');
            $st = $pdo->prepare("UPDATE fornecedores SET ativo=:a WHERE id=:id");
            $st->execute([':a'=>$ativo?1:0, ':id'=>$id]);
            $msg = $ativo?'Fornecedor ativado.':'Fornecedor desativado.';
        }
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

// ========= AÇÕES: INSUMOS (com busca/paginação e delete seguro) =========
if ($_SERVER['REQUEST_METHOD']==='POST' && $tab==='insumos') {
    try {
        if (post('action')==='create') {
            $nome = trim((string)post('nome',''));
            $uni  = trim((string)post('unidade_padrao',''));
            if ($nome==='') throw new Exception('Informe o nome do insumo.');
            $st = $pdo->prepare("INSERT INTO lc_insumos (nome, unidade_padrao) VALUES (:n, :u)");
            $st->execute([':n'=>$nome, ':u'=>$uni!==''?$uni:null]);
            $msg = 'Insumo criado.';
        } elseif (post('action')==='update') {
            $id = (int)post('id',0);
            $nome = trim((string)post('nome',''));
            $uni  = trim((string)post('unidade_padrao',''));
            if ($id<=0) throw new Exception('ID inválido.');
            if ($nome==='') throw new Exception('Informe o nome do insumo.');
            $st = $pdo->prepare("UPDATE lc_insumos SET nome=:n, unidade_padrao=:u WHERE id=:id");
            $st->execute([':n'=>$nome, ':u'=>$uni!==''?$uni:null, ':id'=>$id]);
            $msg = 'Insumo atualizado.';
        } elseif (post('action')==='delete') {
            $id = (int)post('id',0);
            if ($id<=0) throw new Exception('ID inválido.');
            // Verifica referências (ficha_componentes)
            $ref = $pdo->prepare("SELECT 1 FROM lc_ficha_componentes WHERE insumo_id=:id LIMIT 1");
            $ref->execute([':id'=>$id]);
            if ($ref->fetch()) throw new Exception('Não é possível excluir: insumo em uso em alguma ficha.');
            $st = $pdo->prepare("DELETE FROM lc_insumos WHERE id=:id");
            $st->execute([':id'=>$id]);
            $msg = 'Insumo excluído.';
        }
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

// ========= Leitura para telas =========
$categorias = [];
$fornecedores = [];
$insumos = [];
$ins_total = 0; $ins_q = trim((string)($_GET['q'] ?? '')); $ins_page = max(1,(int)($_GET['page'] ?? 1)); $ins_limit = 15; $ins_off = ($ins_page-1)*$ins_limit;

if ($tab==='categorias') {
    $categorias = $pdo->query("SELECT id, nome, ordem, ativo FROM lc_categorias ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);
}
if ($tab==='fornecedores') {
    $fornecedores = $pdo->query("SELECT id, nome, modo_padrao::text AS modo_padrao, ativo FROM fornecedores ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
}
if ($tab==='insumos') {
    $where = ""; $bind = [];
    if ($ins_q!=='') { $where = "WHERE (nome ILIKE :pat OR COALESCE(unidade_padrao,'') ILIKE :pat)"; $bind[':pat'] = '%'.$ins_q.'%'; }

    // total
    $sqlC = "SELECT count(*)::int FROM lc_insumos $where";
    $stC = $pdo->prepare($sqlC);
    foreach ($bind as $k=>$v) $stC->bindValue($k,$v,PDO::PARAM_STR);
    $stC->execute();
    $ins_total = (int)$stC->fetchColumn();

    // page
    $sqlL = "SELECT id, nome, COALESCE(unidade_padrao,'') AS unidade_padrao FROM lc_insumos $where ORDER BY nome LIMIT :lim OFFSET :off";
    $stL = $pdo->prepare($sqlL);
    foreach ($bind as $k=>$v) $stL->bindValue($k,$v,PDO::PARAM_STR);
    $stL->bindValue(':lim',$ins_limit,PDO::PARAM_INT);
    $stL->bindValue(':off',$ins_off,PDO::PARAM_INT);
    $stL->execute();
    $insumos = $stL->fetchAll(PDO::FETCH_ASSOC);
    $ins_pages = max(1, (int)ceil($ins_total / $ins_limit));
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Configurar — Lista de Compras</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="estilo.css">
    <style>
        .wrap{padding:16px}
        .card{background:#fff;border:1px solid #dfe7f4;border-radius:12px;padding:16px}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
        h1{margin:0}
        .tabs{display:flex;gap:8px;margin:8px 0 12px 0;flex-wrap:wrap}
        .tab{padding:8px 12px;border:1px solid #e1ebff;border-radius:8px;text-decoration:none}
        .tab.active{background:#004aad;color:#fff;border-color:#004aad}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .grid .full{grid-column:1/-1}
        .input{width:100%;padding:10px;border:1px solid #cfe0ff;border-radius:8px}
        .btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
        .btn.gray{background:#e9efff;color:#004aad}
        .muted{color:#667b9f;font-size:12px}
        table{width:100%;border-collapse:separate;border-spacing:0}
        th,td{padding:10px;border-bottom:1px solid #eef3ff;vertical-align:top}
        th{text-align:left;font-size:13px;color:#37517e}
        .alert{padding:10px;border-radius:8px;margin-bottom:10px}
        .alert.err{background:#ffeded;border:1px solid #ffb3b3;color:#8a0c0c}
        .alert.success{background:#edffed;border:1px solid #b3ffb3;color:#0c8a0c}
        .row-actions form{display:inline}
        .section{border:1px dashed #dbe6ff;border-radius:10px;margin:10px 0}
        .section h3{margin:0;padding:10px 12px;border-bottom:1px dashed #e6eeff;background:#f9fbff;border-radius:10px 10px 0 0}
        .section .inner{padding:8px 12px}
        .toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
        .pagination{display:flex;gap:6px;justify-content:flex-end;margin-top:12px}
        .pagination a,.pagination span{padding:8px 12px;border:1px solid #e1ebff;border-radius:8px;text-decoration:none}
        .pagination .active{background:#004aad;color:#fff;border-color:#004aad}
    </style>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
<div class="wrap">

<div class="header">
    <h1>Configurar — Lista de Compras</h1>
    <div>
        <a class="btn gray" href="lc_index.php">← Painel</a>
        <a class="btn gray" href="historico.php">← Histórico</a>
        <a class="btn" href="lista_compras.php">+ Gerar nova lista</a>
    </div>
</div>

    <div class="tabs">
        <a class="tab <?= $tab==='categorias'?'active':'' ?>" href="configurar.php?<?= h(qs(['tab'=>'categorias','page'=>null,'q'=>null])) ?>">Categorias</a>
        <a class="tab <?= $tab==='fornecedores'?'active':'' ?>" href="configurar.php?<?= h(qs(['tab'=>'fornecedores','page'=>null,'q'=>null])) ?>">Fornecedores</a>
        <a class="tab <?= $tab==='insumos'?'active':'' ?>" href="configurar.php?<?= h(qs(['tab'=>'insumos'])) ?>">Insumos</a>
        <a class="tab <?= $tab==='fichas'?'active':'' ?>" href="configurar.php?<?= h(qs(['tab'=>'fichas'])) ?>">Fichas</a>
        <a class="tab <?= $tab==='itens'?'active':'' ?>" href="configurar.php?<?= h(qs(['tab'=>'itens'])) ?>">Itens</a>
    </div>

    <?php if ($err): ?><div class="alert err"><?=h($err)?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert success"><?=h($msg)?></div><?php endif; ?>

    <?php if ($tab==='categorias'): ?>
        <div class="card section">
            <h3>Nova categoria</h3>
            <div class="inner">
                <form method="post">
                    <input type="hidden" name="action" value="create">
                    <div class="grid">
                        <div>
                            <label class="muted">Nome</label>
                            <input class="input" name="nome" required>
                        </div>
                        <div>
                            <label class="muted">Ordem</label>
                            <input class="input" type="number" name="ordem" value="0">
                        </div>
                    </div>
                    <div style="margin-top:10px"><button class="btn" type="submit">Salvar</button></div>
                </form>
            </div>
        </div>

        <div class="card section">
            <h3>Categorias existentes</h3>
            <div class="inner" style="overflow:auto">
                <table>
                    <thead><tr><th style="width:80px">ID</th><th>Nome</th><th style="width:120px">Ordem</th><th style="width:120px">Ativo</th><th style="width:260px">Ações</th></tr></thead>
                    <tbody>
                    <?php if (!$categorias): ?>
                        <tr><td colspan="5" class="muted">Nenhuma categoria.</td></tr>
                    <?php else: foreach ($categorias as $c): ?>
                        <tr>
                            <td>#<?= (int)$c['id'] ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <input class="input" name="nome" value="<?= h((string)$c['nome']) ?>" required>
                            </td>
                            <td><input class="input" type="number" name="ordem" value="<?= (int)$c['ordem'] ?>"></td>
                            <td>
                                <select class="input" name="ativo">
                                    <option value="1" <?= $c['ativo']?'selected':'' ?>>Ativo</option>
                                    <option value="0" <?= !$c['ativo']?'selected':'' ?>>Inativo</option>
                                </select>
                            </td>
                            <td class="row-actions">
                                    <button class="btn" type="submit">Atualizar</button>
                                </form>
                                <form method="post" style="margin-left:6px;display:inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <input type="hidden" name="ativo" value="<?= $c['ativo']? '0':'1' ?>">
                                    <button class="btn gray" type="submit"><?= $c['ativo']? 'Desativar':'Ativar' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($tab==='fornecedores'): ?>
        <div class="card section">
            <h3>Novo fornecedor</h3>
            <div class="inner">
                <form method="post">
                    <input type="hidden" name="action" value="create">
                    <div class="grid">
                        <div class="full">
                            <label class="muted">Nome</label>
                            <input class="input" name="nome" required>
                        </div>
                        <div>
                            <label class="muted">Modo padrão</label>
                            <select class="input" name="modo_padrao">
                                <option value="consolidado">Consolidado</option>
                                <option value="separado">Separado por evento</option>
                            </select>
                        </div>
                        <div>
                            <label class="muted">Ativo</label>
                            <select class="input" name="ativo">
                                <option value="1" selected>Ativo</option>
                                <option value="0">Inativo</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top:10px"><button class="btn" type="submit">Salvar</button></div>
                </form>
            </div>
        </div>

        <div class="card section">
            <h3>Fornecedores</h3>
            <div class="inner" style="overflow:auto">
                <table>
                    <thead><tr><th style="width:80px">ID</th><th>Nome</th><th style="width:200px">Modo padrão</th><th style="width:120px">Ativo</th><th style="width:260px">Ações</th></tr></thead>
                    <tbody>
                    <?php if (!$fornecedores): ?>
                        <tr><td colspan="5" class="muted">Nenhum fornecedor.</td></tr>
                    <?php else: foreach ($fornecedores as $f): ?>
                        <tr>
                            <td>#<?= (int)$f['id'] ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                    <input class="input" name="nome" value="<?= h((string)$f['nome']) ?>" required>
                            </td>
                            <td>
                                <select class="input" name="modo_padrao">
                                    <option value="consolidado" <?= ($f['modo_padrao']==='consolidado')?'selected':'' ?>>Consolidado</option>
                                    <option value="separado"    <?= ($f['modo_padrao']==='separado')?'selected':'' ?>>Separado por evento</option>
                                </select>
                            </td>
                            <td>
                                <select class="input" name="ativo">
                                    <option value="1" <?= $f['ativo']?'selected':'' ?>>Ativo</option>
                                    <option value="0" <?= !$f['ativo']?'selected':'' ?>>Inativo</option>
                                </select>
                            </td>
                            <td class="row-actions">
                                    <button class="btn" type="submit">Atualizar</button>
                                </form>
                                <form method="post" style="margin-left:6px;display:inline">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                    <input type="hidden" name="ativo" value="<?= $f['ativo']? '0':'1' ?>">
                                    <button class="btn gray" type="submit"><?= $f['ativo']? 'Desativar':'Ativar' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    <?php elseif ($tab==='insumos'): ?>
        <div class="card section">
            <h3>Novo insumo</h3>
            <div class="inner">
                <form method="post">
                    <input type="hidden" name="action" value="create">
                    <div class="grid">
                        <div class="full">
                            <label class="muted">Nome</label>
                            <input class="input" name="nome" required>
                        </div>
                        <div>
                            <label class="muted">Unidade padrão (opcional)</label>
                            <input class="input" name="unidade_padrao" placeholder="kg, un, maço, litro...">
                        </div>
                    </div>
                    <div style="margin-top:10px"><button class="btn" type="submit">Salvar</button></div>
                </form>
            </div>
        </div>

        <div class="card section">
            <h3>Insumos</h3>
            <div class="inner">
                <form method="get" class="toolbar">
                    <input type="hidden" name="tab" value="insumos">
                    <input class="input" type="text" name="q" value="<?= h($ins_q) ?>" placeholder="Buscar por nome/unidade...">
                    <button class="btn" type="submit">Buscar</button>
                    <?php if ($ins_q!==''): ?><a class="btn gray" href="configurar.php?<?= h(qs(['tab'=>'insumos','q'=>null,'page'=>null])) ?>">Limpar</a><?php endif; ?>
                    <span class="muted" style="margin-left:auto"><?= $ins_total ?> registro(s)</span>
                </form>

                <div style="overflow:auto">
                <table>
                    <thead><tr><th style="width:80px">ID</th><th>Nome</th><th style="width:220px">Unidade padrão</th><th style="width:260px">Ações</th></tr></thead>
                    <tbody>
                    <?php if (!$insumos): ?>
                        <tr><td colspan="4" class="muted">Nenhum insumo encontrado.</td></tr>
                    <?php else: foreach ($insumos as $i): ?>
                        <tr>
                            <td>#<?= (int)$i['id'] ?></td>
                            <td>
                                <form method="post">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                                    <input class="input" name="nome" value="<?= h((string)$i['nome']) ?>" required>
                            </td>
                            <td><input class="input" name="unidade_padrao" value="<?= h((string)$i['unidade_padrao']) ?>" placeholder="kg, un, maço, litro..."></td>
                            <td class="row-actions">
                                    <button class="btn" type="submit">Atualizar</button>
                                </form>
                                <form method="post" style="margin-left:6px;display:inline" onsubmit="return confirm('Excluir este insumo? Esta ação não pode ser desfeita.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                                    <button class="btn gray" type="submit">Excluir</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>

                <?php if (($ins_pages ?? 1) > 1): ?>
                <div class="pagination">
                    <?php
                    $pages = $ins_pages;
                    $page  = $ins_page;
                    $start = max(1, $page-2);
                    $end   = min($pages, $page+2);
                    if ($page>1) echo '<a href="configurar.php?'.h(qs(['tab'=>'insumos','page'=>$page-1])).'">« Anterior</a>';
                    for($p=$start;$p<=$end;$p++){
                        if($p===$page) echo '<span class="active">'.(int)$p.'</span>';
                        else echo '<a href="configurar.php?'.h(qs(['tab'=>'insumos','page'=>$p])).'">'.(int)$p.'</a>';
                    }
                    if ($page<$pages) echo '<a href="configurar.php?'.h(qs(['tab'=>'insumos','page'=>$page+1])).'">Próxima »</a>';
                    ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($tab==='fichas'): ?>
        <div class="card"><h3>Fichas</h3><p class="muted">Em breve: CRUD de fichas e componentes (insumo, quantidade, unidade).</p></div>

    <?php elseif ($tab==='itens'): ?>
        <div class="card"><h3>Itens</h3><p class="muted">Em breve: CRUD de itens (categoria, tipo, nome, unidade, fornecedor/ficha, ativo).</p></div>

    <?php endif; ?>

</div>
</div>
</body>
</html>
