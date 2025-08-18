<?php
session_start();

// Acesso
if (!isset($_SESSION['logado']) || intval($_SESSION['perm_usuarios'] ?? 0) !== 1) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { http_response_code(500); echo 'Falha na conexão.'; exit; }

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: index.php?page=usuarios'); exit; }

// Salvar (PRG) -> volta para a rota do dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome   = trim($_POST['nome'] ?? '');
    $login  = trim($_POST['login'] ?? '');
    $senha  = $_POST['senha'] ?? '';
    $status = trim($_POST['status'] ?? '');
    $funcao = trim($_POST['funcao'] ?? '');

    $perm_usuarios   = isset($_POST['perm_usuarios']) ? 1 : 0;
    $perm_pagamentos = isset($_POST['perm_pagamentos']) ? 1 : 0;
    $perm_tarefas    = isset($_POST['perm_tarefas']) ? 1 : 0;
    $perm_demandas   = isset($_POST['perm_demandas']) ? 1 : 0;
    $perm_portao     = isset($_POST['perm_portao']) ? 1 : 0;

    if ($senha !== '') {
        $sql = "UPDATE usuarios
                   SET nome=:nome, login=:login, senha=:senha,
                       status=:status, funcao=:funcao,
                       perm_usuarios=:perm_usuarios, perm_pagamentos=:perm_pagamentos,
                       perm_tarefas=:perm_tarefas, perm_demandas=:perm_demandas, perm_portao=:perm_portao
                 WHERE id=:id LIMIT 1";
        $params = [
          ':nome'=>$nome, ':login'=>$login, ':senha'=>$senha, // legado: texto puro
          ':status'=>$status, ':funcao'=>$funcao,
          ':perm_usuarios'=>$perm_usuarios, ':perm_pagamentos'=>$perm_pagamentos,
          ':perm_tarefas'=>$perm_tarefas, ':perm_demandas'=>$perm_demandas, ':perm_portao'=>$perm_portao,
          ':id'=>$id
        ];
    } else {
        $sql = "UPDATE usuarios
                   SET nome=:nome, login=:login,
                       status=:status, funcao=:funcao,
                       perm_usuarios=:perm_usuarios, perm_pagamentos=:perm_pagamentos,
                       perm_tarefas=:perm_tarefas, perm_demandas=:perm_demandas, perm_portao=:perm_portao
                 WHERE id=:id LIMIT 1";
        $params = [
          ':nome'=>$nome, ':login'=>$login,
          ':status'=>$status, ':funcao'=>$funcao,
          ':perm_usuarios'=>$perm_usuarios, ':perm_pagamentos'=>$perm_pagamentos,
          ':perm_tarefas'=>$perm_tarefas, ':perm_demandas'=>$perm_demandas, ':perm_portao'=>$perm_portao,
          ':id'=>$id
        ];
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    header('Location: index.php?page=usuarios&ok=1');
    exit;
}

// Carregar
$st = $pdo->prepare("SELECT * FROM usuarios WHERE id=:id LIMIT 1");
$st->execute([':id' => $id]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u) { header('Location: index.php?page=usuarios'); exit; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Editar Usuário</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
/* Somente alinhamento visual desta página */

/* containers */
.content-narrow{max-width:900px;margin:0 auto}
.group{background:#fff;border:1px solid #e6ecff;border-radius:12px;padding:16px;margin:12px 0}

/* campos topo */
.form-row{display:flex;gap:16px;flex-wrap:wrap}
.form-row .col{flex:1;min-width:260px}
.input{padding:10px;border:1px solid #cfd8ea;border-radius:10px;font-size:14px;width:100%;height:44px;box-sizing:border-box}
select.input{height:44px}
.label{font-size:13px;color:#1d3c8f;margin-bottom:6px;display:block}

/* permissões: grid 2 colunas, itens centralizados e alinhados */
.checks{
  display:grid;
  grid-template-columns: repeat(2, minmax(280px, 1fr));
  column-gap: 40px;
  row-gap: 12px;
  align-items:center;
}
.checks label{
  display:flex;
  align-items:center;
  gap:10px;
  font-size:16px;
  line-height:1.2;
  color:#4c3b2a; /* mantém paleta existente */
  padding:6px 0;
}
.checks input[type="checkbox"]{
  width:18px;height:18px;vertical-align:middle;flex:0 0 auto;
}

/* rodapé do formulário */
.form-actions{display:flex;gap:10px;margin-top:12px;align-items:center}
.btn{background:#004aad;color:#fff;border:none;border-radius:10px;padding:11px 16px;font-weight:700;cursor:pointer}
.btn-outline{background:#e9efff;color:#004aad}
</style>
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
  <div class="content-narrow">
    <h1>Editar Usuário</h1>

    <form method="post" action="">
      <div class="group">
        <div class="form-row">
          <div class="col">
            <label class="label">Nome</label>
            <input class="input" type="text" name="nome" value="<?php echo htmlspecialchars($u['nome'] ?? '', ENT_QUOTES); ?>" required>
          </div>
          <div class="col">
            <label class="label">Login</label>
            <input class="input" type="text" name="login" value="<?php echo htmlspecialchars($u['login'] ?? '', ENT_QUOTES); ?>" required>
          </div>
        </div>
        <div class="form-row">
          <div class="col">
            <label class="label">Senha (em branco para manter)</label>
            <input class="input" type="text" name="senha" value="">
          </div>
          <div class="col">
            <label class="label">Status</label>
            <?php $stx = strtolower($u['status'] ?? ''); ?>
            <select class="input" name="status">
              <option value="ativo"   <?php echo $stx==='ativo'?'selected':''; ?>>ativo</option>
              <option value="inativo" <?php echo $stx==='inativo'?'selected':''; ?>>inativo</option>
            </select>
          </div>
          <div class="col">
            <label class="label">Função</label>
            <input class="input" type="text" name="funcao" value="<?php echo htmlspecialchars($u['funcao'] ?? '', ENT_QUOTES); ?>" placeholder="ex.: admin">
          </div>
        </div>
      </div>

      <div class="group">
        <div class="label" style="margin-bottom:8px">Permissões</div>
        <div class="checks">
          <label><input type="checkbox" name="perm_usuarios"   <?php echo intval($u['perm_usuarios'])===1?'checked':''; ?>> Usuários</label>
          <label><input type="checkbox" name="perm_pagamentos" <?php echo intval($u['perm_pagamentos'])===1?'checked':''; ?>> Pagamentos</label>
          <label><input type="checkbox" name="perm_tarefas"    <?php echo intval($u['perm_tarefas'])===1?'checked':''; ?>> Tarefas</label>
          <label><input type="checkbox" name="perm_demandas"   <?php echo intval($u['perm_demandas'])===1?'checked':''; ?>> Demandas</label>
          <label><input type="checkbox" name="perm_portao"     <?php echo intval($u['perm_portao'])===1?'checked':''; ?>> Portão</label>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn" type="submit">Salvar</button>
        <a class="btn btn-outline" href="index.php?page=usuarios">Voltar</a>
      </div>
    </form>
  </div>
</main>
</body>
</html>
