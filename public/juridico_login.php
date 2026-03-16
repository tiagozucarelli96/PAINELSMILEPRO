<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/setup_administrativo_juridico.php';

setupAdministrativoJuridico($pdo);

$redirectRaw = trim((string)($_GET['redirect'] ?? ''));
$redirectTarget = '';
$startsOk = (substr($redirectRaw, 0, 9) === '/index.php' || substr($redirectRaw, 0, 8) === 'index.php');
if ($redirectRaw !== '' && $startsOk && strpos($redirectRaw, 'page=juridico_portal') !== false) {
    $redirectTarget = $redirectRaw;
}

if (!empty($_SESSION['juridico_logado']) && !empty($_SESSION['juridico_usuario_id'])) {
    header('Location: ' . ($redirectTarget !== '' ? $redirectTarget : 'index.php?page=juridico_portal'));
    exit;
}

$erro = '';
$usuarios = [];

try {
    $stmt = $pdo->query(
        'SELECT id, nome
         FROM administrativo_juridico_usuarios
         WHERE ativo = TRUE
         ORDER BY nome ASC'
    );
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $erro = 'Não foi possível carregar os usuários de acesso.';
    error_log('Juridico login - listar usuarios: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuarioId = (int)($_POST['usuario_id'] ?? 0);
    $senha = (string)($_POST['senha'] ?? '');

    if ($usuarioId <= 0) {
        $erro = 'Selecione o usuário.';
    } elseif ($senha === '') {
        $erro = 'Informe a senha.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, nome, senha_hash
                 FROM administrativo_juridico_usuarios
                 WHERE id = :id AND ativo = TRUE
                 LIMIT 1'
            );
            $stmt->execute([':id' => $usuarioId]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$usuario || empty($usuario['senha_hash']) || !password_verify($senha, (string)$usuario['senha_hash'])) {
                $erro = 'Usuário ou senha inválidos.';
            } else {
                $_SESSION['juridico_logado'] = true;
                $_SESSION['juridico_usuario_id'] = (int)$usuario['id'];
                $_SESSION['juridico_usuario_nome'] = (string)$usuario['nome'];
                $_SESSION['juridico_login_at'] = date('c');

                header('Location: ' . ($redirectTarget !== '' ? $redirectTarget : 'index.php?page=juridico_portal'));
                exit;
            }
        } catch (Exception $e) {
            $erro = 'Erro ao autenticar. Tente novamente.';
            error_log('Juridico login - erro autenticar: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Jurídico</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 100%);
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 45px rgba(15, 23, 42, .25);
            padding: 2rem;
        }
        .header {
            text-align: center;
            margin-bottom: 1.4rem;
        }
        .brand-logo {
            width: 190px;
            max-width: 100%;
            height: auto;
            margin: 0 auto .75rem;
            display: block;
        }
        .header h1 {
            color: #1e3a8a;
            margin-bottom: .4rem;
            font-size: 1.45rem;
        }
        .header p { color: #64748b; font-size: .92rem; }
        .header .company {
            margin-bottom: .3rem;
            color: #1e3a8a;
            font-weight: 700;
            font-size: .92rem;
        }
        .field { margin-bottom: 1rem; }
        .field label {
            display: block;
            margin-bottom: .35rem;
            color: #334155;
            font-size: .86rem;
            font-weight: 600;
        }
        .field select,
        .field input {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: .72rem .76rem;
            font-size: .95rem;
        }
        .field select:focus,
        .field input:focus {
            outline: none;
            border-color: #1d4ed8;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
        }
        .alert {
            border-radius: 10px;
            padding: .75rem .85rem;
            margin-bottom: .9rem;
            font-weight: 600;
            font-size: .88rem;
            background: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .btn {
            width: 100%;
            border: 0;
            border-radius: 9px;
            padding: .75rem;
            font-weight: 700;
            cursor: pointer;
            color: #fff;
            background: #1d4ed8;
            transition: .2s;
        }
        .btn:hover { background: #1e40af; }
        .btn:disabled { background: #94a3b8; cursor: not-allowed; }
        .foot {
            margin-top: 1rem;
            color: #64748b;
            font-size: .8rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <img src="/logo-smile.png" alt="Grupo Smile Eventos" class="brand-logo">
            <h1>Portal Jurídico</h1>
            <p class="company">Grupo Smile Eventos</p>
            <p>Selecione o usuário e informe a senha para acessar os arquivos.</p>
        </div>

        <?php if ($erro !== ''): ?>
            <div class="alert"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="field">
                <label>Usuário</label>
                <select name="usuario_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= (int)($usuario['id'] ?? 0) ?>"><?= htmlspecialchars((string)($usuario['nome'] ?? '')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Senha</label>
                <input type="password" name="senha" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn" <?= empty($usuarios) ? 'disabled' : '' ?>>Entrar</button>
        </form>

        <?php if (empty($usuarios)): ?>
            <div class="foot">Nenhum usuário jurídico ativo foi cadastrado ainda.</div>
        <?php else: ?>
            <div class="foot">Acesso exclusivo para pastas e arquivos do Jurídico.</div>
        <?php endif; ?>
    </div>
</body>
</html>
