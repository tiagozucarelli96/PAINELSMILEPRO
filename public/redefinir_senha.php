<?php
declare(strict_types=1);

require_once __DIR__ . '/session_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/password_reset_helper.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$token = strtolower(trim((string)($_POST['token'] ?? $_GET['token'] ?? '')));
$error = '';
$reset = null;
$requestSent = false;
$action = (string)($_POST['action'] ?? '');

try {
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Serviço temporariamente indisponível.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'request') {
        $requestSent = true;
        $identifier = (string)($_POST['identifier'] ?? '');
        $requestIp = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
        if (str_contains($requestIp, ',')) {
            $requestIp = trim(explode(',', $requestIp)[0]);
        }
        $user = painel_password_reset_find_user($pdo, $identifier);
        if ($user
            && !empty($user['ativo'])
            && filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)
            && painel_password_reset_can_issue($pdo, (int)$user['id'], $requestIp)
        ) {
            require_once __DIR__ . '/core/email_global_helper.php';
            $issued = painel_password_reset_issue($pdo, (int)$user['id'], 30, $requestIp);
            $baseUrl = 'https://painelpro.smileeventos.com.br';
            $link = $baseUrl . '/redefinir_senha.php?token=' . rawurlencode((string)$issued['token']);
            $safeName = htmlspecialchars((string)$user['nome'], ENT_QUOTES, 'UTF-8');
            $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
            $body = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#172033">'
                . '<h2>Redefinição de senha</h2>'
                . '<p>Olá, ' . $safeName . '.</p>'
                . '<p>Recebemos uma solicitação para redefinir sua senha do Painel Smile.</p>'
                . '<p style="margin:28px 0"><a href="' . $safeLink . '" style="background:#1769e0;color:#fff;text-decoration:none;padding:13px 20px;border-radius:8px;font-weight:bold">Criar nova senha</a></p>'
                . '<p>Este link é de uso único e expira em 30 minutos.</p>'
                . '<p>Se você não solicitou esta redefinição, ignore esta mensagem.</p>'
                . '</div>';
            $mailer = new EmailGlobalHelper();
            if (!$mailer->enviarEmail((string)$user['email'], 'Redefinição de senha — Painel Smile', $body, true)) {
                painel_password_reset_revoke($pdo, (string)$issued['token_hash']);
                error_log('Falha ao enviar e-mail de redefinição para o usuário ' . (int)$user['id']);
            }
        }
    } elseif ($token !== '') {
        $reset = painel_password_reset_lookup($pdo, $token);
        if (!$reset || empty($reset['ativo'])) {
            $error = 'Este link é inválido, expirou ou já foi utilizado.';
        }
    }
} catch (Throwable $e) {
    error_log('Falha no fluxo de redefinição de senha: ' . $e->getMessage());
    if (!$requestSent) {
        $error = 'Não foi possível validar este link agora. Tente novamente em alguns minutos.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reset' && $error === '' && $reset) {
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');

    if (strlen($password) < 12) {
        $error = 'A nova senha precisa ter pelo menos 12 caracteres.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $error = 'Use pelo menos uma letra e um número na nova senha.';
    } elseif (!hash_equals($password, $passwordConfirmation)) {
        $error = 'As senhas informadas não são iguais.';
    } else {
        try {
            $reset = painel_password_reset_consume($pdo, $token, $password);
            session_regenerate_id(true);
            $_SESSION['logado'] = 1;
            $_SESSION['id'] = (int)$reset['usuario_id'];
            $_SESSION['nome'] = (string)($reset['nome'] ?? 'Usuário');
            header('Location: index.php?page=dashboard&senha_redefinida=1');
            exit;
        } catch (Throwable $e) {
            error_log('Falha ao redefinir senha: ' . $e->getMessage());
            $error = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Não foi possível redefinir a senha. Solicite um novo link.';
        }
    }
}

function reset_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redefinir senha — Painel Smile</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: linear-gradient(135deg, #0b1b3a, #060c1c); color: #14233b; }
        .card { width: min(100%, 440px); padding: 32px; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px rgba(0,0,0,.35); }
        .logo { display: block; max-width: 180px; max-height: 72px; margin: 0 auto 24px; }
        h1 { margin: 0 0 8px; font-size: 26px; }
        p { color: #5b677a; line-height: 1.5; }
        label { display: block; margin: 18px 0 7px; font-weight: 700; }
        input { width: 100%; padding: 13px 14px; border: 1px solid #cbd5e1; border-radius: 10px; font: inherit; }
        input:focus { outline: 3px solid rgba(37,99,235,.16); border-color: #2563eb; }
        button { width: 100%; margin-top: 22px; padding: 14px; border: 0; border-radius: 10px; background: #1769e0; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        .alert { margin: 18px 0; padding: 12px 14px; border-radius: 10px; background: #fee2e2; color: #991b1b; line-height: 1.45; }
        .back { display: block; margin-top: 20px; text-align: center; color: #2563eb; text-decoration: none; }
        .hint { margin-top: 7px; font-size: 13px; color: #64748b; }
    </style>
</head>
<body>
    <main class="card">
        <img class="logo" src="/logo.png" alt="Grupo Smile">
        <h1>Redefinir senha</h1>

        <?php if ($error !== ''): ?>
            <div class="alert"><?= reset_e($error) ?></div>
        <?php endif; ?>

        <?php if ($requestSent): ?>
            <p>Se os dados informados correspondem a um usuário ativo, enviamos um link de redefinição ao e-mail cadastrado. Verifique também a caixa de spam.</p>
        <?php elseif ($reset && $error !== 'Este link é inválido, expirou ou já foi utilizado.'): ?>
            <p>Olá, <?= reset_e((string)($reset['nome'] ?? '')) ?>. Escolha uma nova senha para acessar o painel.</p>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="reset">
                <input type="hidden" name="token" value="<?= reset_e($token) ?>">
                <label for="password">Nova senha</label>
                <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required autofocus>
                <div class="hint">Use pelo menos 12 caracteres, incluindo uma letra e um número.</div>

                <label for="password_confirmation">Confirmar nova senha</label>
                <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
                <button type="submit">Salvar nova senha</button>
            </form>
        <?php elseif ($token === '' && $error === ''): ?>
            <p>Informe o usuário ou e-mail cadastrado. Você receberá um link de uso único para criar uma nova senha.</p>
            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="request">
                <label for="identifier">Usuário ou e-mail</label>
                <input id="identifier" name="identifier" type="text" autocomplete="username" required autofocus>
                <button type="submit">Enviar link de redefinição</button>
            </form>
        <?php endif; ?>

        <a class="back" href="/login.php">Voltar ao login</a>
    </main>
</body>
</html>
