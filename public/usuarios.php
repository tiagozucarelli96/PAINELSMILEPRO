<?php
// usuarios.php
ini_set('display_errors', 1); error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 🔐 Acesso
if (empty($_SESSION['logado']) || empty($_SESSION['perm_usuarios']) || $_SESSION['perm_usuarios'] != 1) {
    http_response_code(403); echo "Acesso negado."; exit;
}

@include_once __DIR__ . '/conexao.php';
if (!isset($pdo)) { echo "Falha na conexão com o banco."; exit; }

$ok=''; $erro='';
$msg = $_GET['msg'] ?? '';
if ($msg === 'selecione_um_usuario_para_editar') { $erro = 'Selecione um usuário para editar.'; }
if ($msg === 'usuario_nao_encontrado')          { $erro = 'Usuário não encontrado.'; }
if ($msg === 'usuario_criado')                  { $ok   = 'Usuário criado com sucesso.'; }

// Exclusão (opcional)
if (isset($_GET['excluir'])) {
    $idExcluir = intval($_GET['excluir']);
    if ($idExcluir > 0) {
        if (!empty($_SESSION['id_usuario']) && intval($_SESSION['id_usuario']) === $idExcluir) {
            $erro = 'Você não pode excluir seu próprio usuário.';
        } else {
            try {
                $st = $pdo->prepare("DELETE FROM usuarios WHERE id = :id LIMIT 1");
                $st->bindValue(':id', $idExcluir, PDO::PARAM_INT);
                $st->execute();
                $ok = 'Usuário excluído com sucesso.';
            } catch (Exception $e) { $erro = 'Erro ao excluir: '.$e->getMessage(); }
        }
    }
}

// Busca
$busca = trim($_GET['q'] ?? '');
$params = [];
$sql = "SELECT id, nome, login, funcao, status,
               COALESCE(perm_usuarios,0)   AS perm_usuarios,
               COALESCE(perm_pagamentos,0) AS perm_pagamentos,
               COALESCE(perm_tarefas,0)    AS perm_tarefas,
               COALESCE(perm_demandas,0)   AS perm_demandas,
               COALESCE(perm_portao,0)     AS perm_portao
        FROM usuarios";
if ($busca !== '') { $sql .= " WHERE nome LIKE :q OR login LIKE :q"; $params[':q'] = "%{$busca}%"; }
$sql .= " ORDER BY nome ASC";
$st = $pdo->prepare($sql); foreach ($params as $k=>$v) $st->bindValue($k,$v); $st->execute();
$usuarios = $st->fetchAll(PDO::FETCH_ASSOC);

function iconePerm($v){ return $v ? '✅' : '—'; }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Usuários & Permissões</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="estilo.css">
<script>
// Evita restaurar rolagem quando volta do editar
if ('scrollRestoration' in history) { history.scrollRestoration = 'manual'; }
window.addEventListener('load', () => { window.scrollTo(0, 0); });
</script>
<style>
.content-narrow{ max-width: 1100px; margin: 0 auto; }
.topbar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.topbar .grow{flex:1}
.input-sm{padding:9px;border:1px solid #ccc;border-radius:8px;font-size:14px;width:100%;max-width:340px}
.btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:600;cursor:pointer}
.btn-link{text-decoration:none;background:#e9efff;border:1px solid #b9cdfa;padding:8px 12px;border-radius:8px;font-weight:600;color:#004aad}
.msg-ok{background:#e7f6e7;border:1px solid #86d686;color:#1a7f1a;padding:10px 12px;border-radius:8px;margin-bottom:12px}
.msg-erro{background:#fdeeee;border:1px solid #f5a7a7;color:#a33;padding:10px 12px;border-radius:8px;margin-bottom:12px}
.table-card{background:#fff;border:1px solid #ddd;border-radius:12px;overflow:hidden}
.table{width:100%;border-collapse:collapse;table-layout:auto}
.table th,.table td{padding:7px 8px;border-bottom:1px solid #eee;text-align:center;font-size:14px;vertical-align:middle;white-space:nowrap}
.table th{text-align:center;background:#f7f9ff;color:#004aad;font-weight:700}
.table tr:hover td{background:#fafcff}
.nowrap{white-space:nowrap}
.col-nome{text-align:left}.col-func{max-width:180px}.col-perm{width:88px}.col-acoes{width:170px}
.badge-status{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px;font-weight:700}
.badge-ativo{background:#e7f6e7;color:#1a7f1a;border:1px solid #86d686}
.badge-inativo{background:#fdeeee;color:#a33;border:1px solid #f5a7a7}
.pill{border:1px solid #cfe0ff;background:#f2f6ff;color:#004aad;padding:3px 8px;border-radius:999px;font-size:12px}
.btn-mini{display:inline-block;text-decoration:none;background:#e9efff;border:1px solid #b9cdfa;padding:6px 10px;border-radius:8px;font-weight:600;color:#004aad;margin-right:6px}
@media (max-width: 1024px){ .table th:nth-child(4), .table td:nth-child(4){display:none} }
</style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/sidebar.php')) { include __DIR__ . '/sidebar.php'; } ?>

<div class="main-content content-narrow">
    <h1>Usuários & Permissões</h1>

    <?php if ($ok): ?><div class="msg-ok"><?= htmlspecialchars($ok,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>
    <?php if ($erro): ?><div class="msg-erro"><?= htmlspecialchars($erro,ENT_QUOTES,'UTF-8'); ?></div><?php endif; ?>

    <div class="topbar">
        <form method="get" class="grow" style="display:flex;gap:8px;align-items:center">
            <input class="input-sm" type="text" name="q" value="<?= htmlspecialchars($busca,ENT_QUOTES,'UTF-8'); ?>" placeholder="Buscar por nome ou login">
            <button class="btn" type="submit">Buscar</button>
            <?php if ($busca!==''): ?><a class="btn-link" href="usuarios.php">Limpar</a><?php endif; ?>
        </form>
        <a class="btn-link" href="usuario_novo.php">+ Novo Usuário</a>
    </div>

    <div class="table-card">
        <table class="table">
            <thead>
            <tr>
                <th class="nowrap col-nome" style="text-align:left">Nome</th>
                <th class="nowrap">Login</th>
                <th class="nowrap">Status</th>
                <th class="nowrap col-func">Função</th>
                <th class="nowrap col-perm">👤 Usuários</th>
                <th class="nowrap col-perm">💰 Pagamentos</th>
                <th class="nowrap col-perm">📋 Tarefas</th>
                <th class="nowrap col-perm">🗂️ Demandas</th>
                <th class="nowrap col-perm">🚪 Portão</th>
                <th class="nowrap col-acoes">Ações</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$usuarios): ?>
                <tr><td colspan="10" style="text-align:center;padding:20px">Nenhum usuário encontrado.</td></tr>
            <?php else: foreach ($usuarios as $u): ?>
                <tr>
                    <td class="nowrap col-nome" style="text-align:left"><?= htmlspecialchars($u['nome'],ENT_QUOTES,'UTF-8'); ?></td>
                    <td class="nowrap"><?= htmlspecialchars($u['login'],ENT_QUOTES,'UTF-8'); ?></td>
                    <td class="nowrap">
                        <?php if (($u['status']??'')==='ativo'): ?>
                            <span class="badge-status badge-ativo">ativo</span>
                        <?php else: ?>
                            <span class="badge-status badge-inativo">inativo</span>
                        <?php endif; ?>
                    </td>
                    <td><?= !empty($u['funcao']) ? "<span class='pill'>".htmlspecialchars($u['funcao'],ENT_QUOTES,'UTF-8')."</span>" : "<span style='color:#888'>—</span>" ?></td>
                    <td class="nowrap"><?= iconePerm($u['perm_usuarios']); ?></td>
                    <td class="nowrap"><?= iconePerm($u['perm_pagamentos']); ?></td>
                    <td class="nowrap"><?= iconePerm($u['perm_tarefas']); ?></td>
                    <td class="nowrap"><?= iconePerm($u['perm_demandas']); ?></td>
                    <td class="nowrap"><?= iconePerm($u['perm_portao']); ?></td>
                    <td class="nowrap">
                        <a class="btn-mini" href="usuario_editar.php?id=<?= intval($u['id']); ?>">Editar</a>
                        <a class="btn-mini" href="usuarios.php?excluir=<?= intval($u['id']); ?>" onclick="return confirm('Excluir este usuário?');">Excluir</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
