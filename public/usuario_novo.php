<?php
// usuario_novo.php
ini_set('display_errors', 1); error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 🔐 Acesso: somente quem tem permissão de usuários
if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios']) || $_SESSION['perm_usuarios'] != 1) {
    http_response_code(403);
    echo "Acesso negado.";
    exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão com o banco."; exit; }

function val($arr,$key,$default=''){ return isset($arr[$key])?trim($arr[$key]):$default; }

$erro = '';

// POST: criar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome   = val($_POST,'nome');
    $login  = val($_POST,'login');
    $senha  = val($_POST,'senha');
    $funcao = val($_POST,'funcao');
    $status = val($_POST,'status','ativo');

    $perm_usuarios   = isset($_POST['perm_usuarios'])   ? 1 : 0;
    $perm_pagamentos = isset($_POST['perm_pagamentos']) ? 1 : 0;
    $perm_tarefas    = isset($_POST['perm_tarefas'])    ? 1 : 0;
    $perm_demandas   = isset($_POST['perm_demandas'])   ? 1 : 0;
    $perm_portao     = isset($_POST['perm_portao'])     ? 1 : 0;

    if ($nome==='' || $login==='' || $senha==='') {
        $erro = 'Preencha Nome, Login e Senha.';
    } else {
        try {
            $chk = $pdo->prepare("SELECT id FROM usuarios WHERE login = :login LIMIT 1");
            $chk->bindValue(':login',$login);
            $chk->execute();
            if ($chk->fetch(PDO::FETCH_ASSOC)) {
                $erro = 'Já existe um usuário com este login.';
            } else {
                $sql = "INSERT INTO usuarios
                        (nome, login, senha, funcao, status,
                         perm_usuarios, perm_pagamentos, perm_tarefas, perm_demandas, perm_portao)
                        VALUES
                        (:nome, :login, :senha, :funcao, :status,
                         :perm_usuarios, :perm_pagamentos, :perm_tarefas, :perm_demandas, :perm_portao)";
                $st = $pdo->prepare($sql);
                $st->bindValue(':nome',$nome);
                $st->bindValue(':login',$login);
                $st->bindValue(':senha',$senha); // legado: texto puro
                $st->bindValue(':funcao',$funcao);
                $st->bindValue(':status',$status);
                $st->bindValue(':perm_usuarios',$perm_usuarios,PDO::PARAM_INT);
                $st->bindValue(':perm_pagamentos',$perm_pagamentos,PDO::PARAM_INT);
                $st->bindValue(':perm_tarefas',$perm_tarefas,PDO::PARAM_INT);
                $st->bindValue(':perm_demandas',$perm_demandas,PDO::PARAM_INT);
                $st->bindValue(':perm_portao',$perm_portao,PDO::PARAM_INT);
                $st->execute();

                header('Location: usuarios.php?msg=usuario_criado');
                exit;
            }
        } catch (Exception $e) { $erro = 'Erro ao salvar: '.$e->getMessage(); }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Novo Usuário</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<style>
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.form-grid .full{grid-column:1/-1}
.form-section{background:#fff;border:1px solid #ddd;border-radius:12px;padding:18px}
.form-section h3{margin:0 0 10px 0;color:#004aad}
.badge-erro{background:#fdeeee;border:1px solid #f5a7a7;color:#a33;padding:8px 12px;border-radius:8px;display:inline-block}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.actions a.button-link{display:inline-block;text-decoration:none;background:#e9efff;border:1px solid #b9cdfa;padding:11px 14px;border-radius:8px;font-weight:600;color:#004aad}
.help{font-size:12px;color:#666;margin-top:4px}

/* ✔ grade das permissões corrigida */
.checkbox-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(220px,1fr));
  gap:10px 16px;
  align-items:start;
}
.chk{
  display:flex;align-items:center;gap:10px;
  padding:10px 12px;border:1px solid #cfe0ff;background:#f7faff;border-radius:10px;
  line-height:1.2; user-select:none; cursor:pointer;
}
.chk input[type="checkbox"]{width:18px;height:18px;flex:0 0 18px;cursor:pointer}
.chk span{font-weight:600;color:#004aad}
@media (max-width:800px){ .checkbox-grid{grid-template-columns:1fr} }
</style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/sidebar.php')) { include __DIR__ . '/sidebar.php'; } ?>

<div class="main-content">
    <h1>Novo Usuário</h1>

    <?php if ($erro): ?><div class="badge-erro"><?php echo htmlspecialchars($erro,ENT_QUOTES,'UTF-8'); ?></div><br><br><?php endif; ?>

    <form method="post" class="form-section">
        <div class="form-grid">
            <div>
                <label>Nome</label>
                <input type="text" name="nome" value="<?php echo htmlspecialchars($_POST['nome']??'',ENT_QUOTES,'UTF-8'); ?>" required>
            </div>
            <div>
                <label>Função/Cargo</label>
                <input type="text" name="funcao" value="<?php echo htmlspecialchars($_POST['funcao']??'',ENT_QUOTES,'UTF-8'); ?>">
            </div>

            <div>
                <label>Login</label>
                <input type="text" name="login" value="<?php echo htmlspecialchars($_POST['login']??'',ENT_QUOTES,'UTF-8'); ?>" required>
                <div class="help">Usa a coluna <b>login</b> do banco.</div>
            </div>
            <div>
                <label>Senha</label>
                <input type="password" name="senha" required>
                <div class="help">No legado atual, a senha fica em texto puro.</div>
            </div>

            <div>
                <label>Status</label>
                <select name="status">
                    <option value="ativo"   <?php echo ((($_POST['status']??'ativo')==='ativo')?'selected':''); ?>>ativo</option>
                    <option value="inativo" <?php echo ((($_POST['status']??'ativo')==='inativo')?'selected':''); ?>>inativo</option>
                </select>
            </div>
            <div></div>

            <div class="full">
                <div class="form-section" style="padding:12px;">
                    <h3>Permissões</h3>
                    <div class="checkbox-grid">
                        <label class="chk">
                            <input type="checkbox" name="perm_usuarios"   <?php echo isset($_POST['perm_usuarios'])?'checked':''; ?>>
                            <span>Gerenciar Usuários</span>
                        </label>
                        <label class="chk">
                            <input type="checkbox" name="perm_pagamentos" <?php echo isset($_POST['perm_pagamentos'])?'checked':''; ?>>
                            <span>Pagamentos</span>
                        </label>
                        <label class="chk">
                            <input type="checkbox" name="perm_tarefas"    <?php echo isset($_POST['perm_tarefas'])?'checked':''; ?>>
                            <span>Tarefas</span>
                        </label>
                        <label class="chk">
                            <input type="checkbox" name="perm_demandas"   <?php echo isset($_POST['perm_demandas'])?'checked':''; ?>>
                            <span>Demandas</span>
                        </label>
                        <label class="chk">
                            <input type="checkbox" name="perm_portao"     <?php echo isset($_POST['perm_portao'])?'checked':''; ?>>
                            <span>Portão</span>
                        </label>
                    </div>
                    <div class="help" style="margin-top:8px;">O item <b>Portão</b> aparece na sidebar apenas quando <b>perm_portao = 1</b>.</div>
                </div>
            </div>

            <div class="full actions">
                <button type="submit">Cadastrar</button>
                <a class="button-link" href="usuarios.php">
