<?php
// public/usuarios.php — listagem com layout alinhado e compatível com schema
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios'])) {
    http_response_code(403); echo "Acesso negado."; exit;
}

require_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { http_response_code(500); echo "Falha na conexão."; exit; }

function cols(PDO $pdo, string $table): array {
  $st = $pdo->prepare("select column_name from information_schema.columns where table_schema=current_schema() and table_name=:t");
  $st->execute([":t"=>$table]);
  return $st->fetchAll(PDO::FETCH_COLUMN);
}
function hascol(array $cols, string $c): bool { return in_array($c, $cols, true); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ok($v){ return (int)$v===1 ? '✅' : '—'; }

$T='usuarios'; $C=cols($pdo,$T);

$colNome   = hascol($C,'nome') ? 'nome' : (hascol($C,'nome_completo') ? 'nome_completo' : (hascol($C,'name') ? 'name' : null));
$loginCands= array_values(array_filter(['loguin','login','usuario','username','user','email'], fn($c)=>hascol($C,$c)));
$colLogin  = $loginCands[0] ?? null;
$colAtivo  = hascol($C,'ativo') ? 'ativo' : (hascol($C,'status') ? 'status' : null);
$colFuncao = hascol($C,'funcao') ? 'funcao' : (hascol($C,'cargo') ? 'cargo' : null);

$permCols = array_values(array_filter([
  hascol($C,'perm_usuarios') ? 'perm_usuarios' : null,
  hascol($C,'perm_pagamentos') ? 'perm_pagamentos' : null,
  hascol($C,'perm_tarefas') ? 'perm_tarefas' : null,
  hascol($C,'perm_demandas') ? 'perm_demandas' : null,
  hascol($C,'perm_portao') ? 'perm_portao' : null,
]));

// excluir
if (isset($_GET['del'], $_GET['conf']) && (int)$_GET['conf']===1) {
  $did = (int)$_GET['del'];
  if ($did>0) {
    $st = $pdo->prepare("delete from {$T} where id=:id");
    $st->execute([':id'=>$did]);
    header("Location: index.php?page=usuarios&msg=".urlencode("Usuário excluído."));
    exit;
  }
}

$q = trim($_GET['q'] ?? '');
$where = [];
$bind = [];
if ($q !== '') {
  if ($colNome)  { $where[] = "{$colNome} ILIKE :q"; }
  if ($colLogin) { $where[] = "{$colLogin} ILIKE :q"; }
  $bind[':q'] = "%{$q}%";
}
$sql = "select * from {$T}".($where ? " where ".implode(" OR ", $where) : "")." order by id asc limit 500";
$rows = $pdo->prepare($sql);
$rows->execute($bind);
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Usuários & Permissões</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
body{margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; background:#f3f7ff; color:#0b1430;}
.main-content{ padding:24px; }
.page-title{ margin:0 0 16px; font-weight:800; color:#0c3a91; letter-spacing:.2px; }

.toolbar{ display:flex; gap:10px; align-items:center; margin-bottom:12px;
  list-style:none; padding:0; }
.toolbar input[type="text"]{ flex:1; min-width:240px; padding:10px 12px; border-radius:10px; border:1px solid #cfe0ff; background:#fff; }
.toolbar .btn{ flex:0 0 auto; width:auto; white-space:nowrap; }
.btn{background:#004aad;color:#fff;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}
.btn.gray{ background:#e9efff; color:#004aad; }

.table{ width:100%; border-collapse:collapse; background:#fff; border:1px solid #dfe7f4; border-radius:12px; overflow:hidden; }
.table th,.table td{ padding:10px 12px; border-bottom:1px solid #eef4ff; text-align:left; white-space:nowrap; }
.table th{ background:#f6faff; color:#0c3a91; font-weight:800; }

.badge{ display:inline-block; padding:4px 8px; border-radius:999px; font-size:12px; }
.badge.ok{ background:#e9f9ef; color:#1b7f3a; border:1px solid #bfe9cc; }
.badge.role{ background:#eef2ff; color:#004aad; border:1px solid #cfe0ff; }
.actions a{ margin-right:8px; text-decoration:none; }
a.btn-link{ padding:6px 10px; border-radius:8px; background:#eef2ff; border:1px solid #cfe0ff; color:#004aad; }
a.btn-link.danger{ background:#ffefef; border-color:#ffd6d6; color:#8a0c0c; }
</style>
<script>
function delUser(id){
  if(confirm('Excluir usuário #' + id + '?')) {
    location.href = 'index.php?page=usuarios&del='+id+'&conf=1';
  }
}
</script>
</head>
<body>
<?php if (is_file(__DIR__.'/sidebar.php')) { include __DIR__.'/sidebar.php'; } ?>
<div class="main-content">
  <h1 class="page-title">Usuários & Permissões</h1>

  <form class="toolbar" method="get" action="index.php">
    <input type="hidden" name="page" value="usuarios">
    <input type="text" name="q" placeholder="Nome ou login" value="<?php echo h($q); ?>">
    <button class="btn" type="submit">Buscar</button>
    <a class="btn gray" href="usuario_novo.php">+ Novo Usuário</a>
  </form>

  <table class="table">
    <thead>
      <tr>
        <th>Login</th>
        <th>Status</th>
        <th>Função</th>
        <?php if (in_array('perm_usuarios',$permCols,true)): ?><th>👥 Usuários</th><?php endif; ?>
        <?php if (in_array('perm_pagamentos',$permCols,true)): ?><th>💰 Pagamentos</th><?php endif; ?>
        <?php if (in_array('perm_tarefas',$permCols,true)): ?><th>🧾 Tarefas</th><?php endif; ?>
        <?php if (in_array('perm_demandas',$permCols,true)): ?><th>📋 Demandas</th><?php endif; ?>
        <?php if (in_array('perm_portao',$permCols,true)): ?><th>🚪 Portão</th><?php endif; ?>
        <th>Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo h($r[$colLogin] ?? ($r['login'] ?? '—')); ?></td>
          <td><?php echo $colAtivo ? ('<span class="badge ok">'.( (int)$r[$colAtivo]===1 ? 'ativo' : 'inativo' ).'</span>') : '—'; ?></td>
          <td><?php echo $colFuncao ? ('<span class="badge role">'.h($r[$colFuncao]).'</span>') : '—'; ?></td>

          <?php if (in_array('perm_usuarios',$permCols,true)): ?><td><?php echo ok($r['perm_usuarios'] ?? 0); ?></td><?php endif; ?>
          <?php if (in_array('perm_pagamentos',$permCols,true)): ?><td><?php echo ok($r['perm_pagamentos'] ?? 0); ?></td><?php endif; ?>
          <?php if (in_array('perm_tarefas',$permCols,true)): ?><td><?php echo ok($r['perm_tarefas'] ?? 0); ?></td><?php endif; ?>
          <?php if (in_array('perm_demandas',$permCols,true)): ?><td><?php echo ok($r['perm_demandas'] ?? 0); ?></td><?php endif; ?>
          <?php if (in_array('perm_portao',$permCols,true)): ?><td><?php echo ok($r['perm_portao'] ?? 0); ?></td><?php endif; ?>

          <td class="actions">
            <a class="btn-link" href="usuario_editar.php?id=<?php echo (int)$r['id']; ?>">Editar</a>
            <a class="btn-link danger" href="javascript:void(0)" onclick="delUser(<?php echo (int)$r['id']; ?>)">Excluir</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="12">Nenhum usuário encontrado.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>
