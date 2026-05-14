<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$flash = null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if ($action !== 'login') {
            wa_validate_csrf($_POST['csrf_token'] ?? null);
        }

        switch ($action) {
            case 'setup_module':
                wa_install_module(
                    (string)($_POST['display_name'] ?? ''),
                    (string)($_POST['email'] ?? ''),
                    (string)($_POST['password'] ?? '')
                );
                wa_flash('success', 'Módulo instalado. Faça login para continuar.');
                wa_redirect();

            case 'login':
                if (!wa_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''))) {
                    throw new RuntimeException('Credenciais inválidas para o Smile Chat.');
                }
                wa_flash('success', 'Login realizado com sucesso.');
                wa_redirect();

            case 'logout':
                wa_logout();
                wa_flash('info', 'Sessão encerrada.');
                wa_redirect();

            case 'save_department':
                wa_save_department($_POST);
                wa_flash('success', 'Departamento salvo.');
                wa_redirect('index.php?page=departments');

            case 'toggle_department':
                wa_toggle_department((int)($_POST['id'] ?? 0));
                wa_flash('success', 'Status do departamento atualizado.');
                wa_redirect('index.php?page=departments');

            case 'save_user':
                wa_save_user($_POST);
                wa_flash('success', 'Atendente salvo.');
                wa_redirect('index.php?page=users');

            case 'toggle_user':
                wa_toggle_user((int)($_POST['id'] ?? 0));
                wa_flash('success', 'Status do atendente atualizado.');
                wa_redirect('index.php?page=users');

            case 'save_inbox':
                wa_save_inbox($_POST);
                wa_flash('success', 'Inbox salvo.');
                wa_redirect('index.php?page=inboxes');

            case 'save_quick_reply':
                wa_save_quick_reply($_POST);
                wa_flash('success', 'Atalho salvo.');
                wa_redirect('index.php?page=quick_replies');
        }
    }
} catch (Throwable $e) {
    $flash = [
        'type' => 'error',
        'message' => $e->getMessage(),
    ];
}

if ($flash === null) {
    $flash = wa_pull_flash();
}

$tablesReady = wa_tables_ready();
$hasUsers = $tablesReady && wa_has_users();

if (!$tablesReady || !$hasUsers) {
    $schemaPath = wa_schema_file();
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= wa_e(WA_APP_TITLE) ?> • Setup</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="<?= wa_url('assets/app.css') ?>">
    </head>
    <body class="auth-shell">
        <main class="auth-card">
            <section class="auth-panel">
                <span class="kicker">Setup inicial</span>
                <h1 class="auth-title">Instalar o app separado de atendimento</h1>
                <p class="auth-copy">
                    Esta área cria a base do novo sistema com tabelas próprias para atendentes, departamentos,
                    inboxes, conversas e atalhos.
                </p>
                <?php if ($flash): ?>
                    <div class="flash flash-<?= wa_e($flash['type']) ?>"><?= wa_e($flash['message']) ?></div>
                <?php endif; ?>
                <form method="post" class="field-grid">
                    <input type="hidden" name="action" value="setup_module">
                    <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                    <div>
                        <label class="label" for="display_name">Nome do administrador inicial</label>
                        <input class="field" id="display_name" name="display_name" required placeholder="Tiago Zucarelli">
                    </div>
                    <div>
                        <label class="label" for="email">Email de acesso</label>
                        <input class="field" id="email" name="email" type="email" required placeholder="tiagozucarelli@hotmail.com">
                    </div>
                    <div>
                        <label class="label" for="password">Senha inicial</label>
                        <input class="field" id="password" name="password" type="password" required placeholder="Defina a senha">
                    </div>
                    <button class="button" type="submit">Instalar módulo</button>
                </form>
                <p class="muted-note">
                    Schema base: <code><?= wa_e($schemaPath) ?></code>
                </p>
            </section>
            <aside class="auth-hero">
                <div>
                    <span class="kicker">Fase 1</span>
                    <h2 class="auth-title">Estrutura pronta para um acesso separado</h2>
                    <p class="auth-copy">
                        O módulo já nasce isolado do painel principal e preparado para evoluir para serviço próprio,
                        inclusive com camada futura de sessão WhatsApp não-oficial.
                    </p>
                </div>
                <div class="stack">
                    <div class="topbar-card">
                        <strong>Inclui agora</strong>
                        <p class="small">Login próprio, departamentos, atendentes, inboxes, base de conversas e atalhos.</p>
                    </div>
                    <div class="topbar-card">
                        <strong>Próxima etapa</strong>
                        <p class="small">Motor de conexão com sessão QR, recebimento de mensagens, distribuição e realtime.</p>
                    </div>
                </div>
            </aside>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if (!wa_is_logged_in()) {
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= wa_e(WA_APP_TITLE) ?> • Login</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="<?= wa_url('assets/app.css') ?>">
    </head>
    <body class="auth-shell">
        <main class="auth-card">
            <section class="auth-panel">
                <span class="kicker">Acesso separado</span>
                <h1 class="auth-title">Entrar no Smile Chat</h1>
                <p class="auth-copy">
                    Este é o novo ambiente de atendimento, isolado do painel principal e preparado para operação com
                    atendentes, departamentos e filas.
                </p>
                <?php if ($flash): ?>
                    <div class="flash flash-<?= wa_e($flash['type']) ?>"><?= wa_e($flash['message']) ?></div>
                <?php endif; ?>
                <form method="post" class="field-grid">
                    <input type="hidden" name="action" value="login">
                    <div>
                        <label class="label" for="email">Email</label>
                        <input class="field" id="email" name="email" type="email" required placeholder="tiagozucarelli@hotmail.com">
                    </div>
                    <div>
                        <label class="label" for="password">Senha</label>
                        <input class="field" id="password" name="password" type="password" required placeholder="Sua senha">
                    </div>
                    <button class="button" type="submit">Entrar</button>
                </form>
                <p class="muted-note">
                    Caminho atual do app: <code><?= wa_e(WA_BASE_PATH) ?></code>
                </p>
            </section>
            <aside class="auth-hero">
                <div>
                    <span class="kicker">Base operacional</span>
                    <h2 class="auth-title">Fluxo já separado para virar serviço próprio</h2>
                    <p class="auth-copy">
                        A aplicação foi iniciada para funcionar com login, banco e navegação próprios. Isso permite
                        colocar em outro subdomínio depois sem acoplar ao painel legado.
                    </p>
                </div>
                <div class="stack">
                    <div class="topbar-card">
                        <strong>Modelo</strong>
                        <p class="small">Departamentos, atendentes, inboxes, conversas e atalhos rápidos.</p>
                    </div>
                    <div class="topbar-card">
                        <strong>Objetivo</strong>
                        <p class="small">Evoluir esta base para o motor não-oficial de WhatsApp com tempo real e distribuição.</p>
                    </div>
                </div>
            </aside>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$user = wa_auth_user();
$page = wa_page();

$navItems = [
    'dashboard' => 'Dashboard',
    'conversations' => 'Conversas',
    'inboxes' => 'Inboxes',
    'departments' => 'Departamentos',
    'users' => 'Atendentes',
    'quick_replies' => 'Atalhos',
    'roadmap' => 'Roadmap',
];

$counts = wa_fetch_dashboard_counts();
$departments = wa_fetch_all_departments();
$users = wa_fetch_users();
$inboxes = wa_fetch_inboxes();
$quickReplies = wa_fetch_quick_replies();
$conversations = wa_fetch_conversations();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= wa_e(WA_APP_TITLE) ?> • <?= wa_e($navItems[$page] ?? 'Dashboard') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= wa_url('assets/app.css') ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand-block">
            <h1 class="brand-title">Smile Chat</h1>
            <p class="brand-subtitle">Atendimento separado do painel principal, pronto para sessões, filas e operação em tempo real.</p>
        </div>

        <ul class="nav-list">
            <?php foreach ($navItems as $navKey => $navLabel): ?>
                <li>
                    <a class="nav-link <?= $page === $navKey ? 'active' : '' ?>" href="<?= wa_url('index.php?page=' . $navKey) ?>">
                        <span><?= wa_e($navLabel) ?></span>
                        <?php if ($navKey === 'conversations'): ?>
                            <span class="badge"><?= (int)$counts['conversations_open'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="sidebar-footer">
            <strong>Conectores futuros</strong><br>
            Baileys/WPP camada de sessão, webhooks internos, fila de eventos e socket realtime.
        </div>
    </aside>

    <main class="content">
        <header class="topbar">
            <div>
                <h2 class="page-title"><?= wa_e($navItems[$page] ?? 'Dashboard') ?></h2>
                <p class="page-copy">
                    <?= match ($page) {
                        'dashboard' => 'Visão geral do novo ambiente de atendimento.',
                        'conversations' => 'Base da caixa de entrada compartilhada e atribuição por departamento.',
                        'inboxes' => 'Cadastro das instâncias/sessões que serão conectadas ao WhatsApp.',
                        'departments' => 'Departamentos e filas responsáveis pelo atendimento.',
                        'users' => 'Atendentes, supervisores e vínculo com departamentos.',
                        'quick_replies' => 'Atalhos operacionais para padronizar respostas.',
                        default => 'Planejamento da próxima fase de desenvolvimento.',
                    } ?>
                </p>
            </div>
            <div class="topbar-card">
                <div class="status-pill"><?= wa_e(wa_status_label((string)$user['status'])) ?></div>
                <p><strong><?= wa_e((string)$user['display_name']) ?></strong></p>
                <p class="small"><?= wa_e((string)$user['email']) ?></p>
                <form method="post">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                    <button type="submit" class="button-secondary">Sair</button>
                </form>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="flash flash-<?= wa_e($flash['type']) ?>"><?= wa_e($flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($page === 'dashboard'): ?>
            <section class="grid-3">
                <article class="card"><div class="card-body"><p class="stat-label">Departamentos ativos</p><p class="stat-value"><?= (int)$counts['departments'] ?></p></div></article>
                <article class="card"><div class="card-body"><p class="stat-label">Atendentes ativos</p><p class="stat-value"><?= (int)$counts['users'] ?></p></div></article>
                <article class="card"><div class="card-body"><p class="stat-label">Inboxes cadastrados</p><p class="stat-value"><?= (int)$counts['inboxes'] ?></p></div></article>
                <article class="card"><div class="card-body"><p class="stat-label">Conversas abertas</p><p class="stat-value"><?= (int)$counts['conversations_open'] ?></p></div></article>
                <article class="card"><div class="card-body"><p class="stat-label">Conversas aguardando</p><p class="stat-value"><?= (int)$counts['conversations_waiting'] ?></p></div></article>
                <article class="card"><div class="card-body"><p class="stat-label">Atalhos ativos</p><p class="stat-value"><?= (int)$counts['quick_replies'] ?></p></div></article>
            </section>

            <section class="grid-2" style="margin-top: 18px;">
                <article class="card">
                    <div class="section-header">
                        <h3 class="section-title">Estrutura já aberta</h3>
                    </div>
                    <div class="card-body stack">
                        <div class="topbar-card"><strong>Fila operacional</strong><p class="small">Departamentos, supervisão por fila, status do atendente e atribuição futura por inbox.</p></div>
                        <div class="topbar-card"><strong>Camada de conexão</strong><p class="small">Tabela de inboxes pronta para receber QR, session key, status e eventos de conexão.</p></div>
                        <div class="topbar-card"><strong>Inbox compartilhada</strong><p class="small">Base de contatos, conversas e mensagens criada para o motor realtime da próxima etapa.</p></div>
                    </div>
                </article>
                <article class="card">
                    <div class="section-header">
                        <h3 class="section-title">Próximo incremento</h3>
                    </div>
                    <div class="card-body stack">
                        <div class="topbar-card"><strong>1.</strong><p class="small">Serviço Node separado para sessão WhatsApp não-oficial.</p></div>
                        <div class="topbar-card"><strong>2.</strong><p class="small">Persistência dos eventos recebidos e abertura automática de conversa.</p></div>
                        <div class="topbar-card"><strong>3.</strong><p class="small">Tela realtime com transferência, notas internas e mensagens rápidas.</p></div>
                    </div>
                </article>
            </section>
        <?php elseif ($page === 'departments'): ?>
            <section class="grid-2">
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Novo departamento</h3></div>
                    <div class="card-body">
                        <form method="post" class="form-grid">
                            <input type="hidden" name="action" value="save_department">
                            <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                            <div>
                                <label class="label">Nome</label>
                                <input class="field" name="name" placeholder="COMERCIAL" required>
                            </div>
                            <div>
                                <label class="label">Cor</label>
                                <input class="field" name="color" value="#1d4ed8">
                            </div>
                            <div>
                                <label class="label">Ordem</label>
                                <input class="field" name="sort_order" type="number" value="0">
                            </div>
                            <div class="full">
                                <label class="label">Descrição</label>
                                <textarea class="textarea" name="description" placeholder="Responsabilidade da fila"></textarea>
                            </div>
                            <div class="full">
                                <button class="button" type="submit">Salvar departamento</button>
                            </div>
                        </form>
                    </div>
                </article>
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Departamentos atuais</h3></div>
                    <?php if ($departments === []): ?>
                        <div class="empty">Ainda não há departamentos cadastrados.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th>Departamento</th><th>Descrição</th><th>Status</th><th>Ação</th></tr></thead>
                                <tbody>
                                <?php foreach ($departments as $department): ?>
                                    <tr>
                                        <td><strong><?= wa_e($department['name']) ?></strong><br><span class="small"><?= wa_e($department['color']) ?></span></td>
                                        <td><?= wa_e($department['description'] ?: 'Sem descrição') ?></td>
                                        <td><span class="badge <?= !empty($department['is_active']) ? 'badge-success' : 'badge-danger' ?>"><?= !empty($department['is_active']) ? 'Ativo' : 'Inativo' ?></span></td>
                                        <td>
                                            <form method="post">
                                                <input type="hidden" name="action" value="toggle_department">
                                                <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                                <input type="hidden" name="id" value="<?= (int)$department['id'] ?>">
                                                <button class="button-secondary" type="submit"><?= !empty($department['is_active']) ? 'Desativar' : 'Ativar' ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        <?php elseif ($page === 'users'): ?>
            <section class="grid-2">
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Novo atendente</h3></div>
                    <div class="card-body">
                        <form method="post" class="form-grid">
                            <input type="hidden" name="action" value="save_user">
                            <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                            <div>
                                <label class="label">Nome</label>
                                <input class="field" name="display_name" required placeholder="Nome do atendente">
                            </div>
                            <div>
                                <label class="label">Email</label>
                                <input class="field" name="email" type="email" required placeholder="email@empresa.com">
                            </div>
                            <div>
                                <label class="label">Senha</label>
                                <input class="field" name="password" type="password" required placeholder="Senha inicial">
                            </div>
                            <div>
                                <label class="label">Status inicial</label>
                                <select class="select" name="status">
                                    <option value="available">Disponível</option>
                                    <option value="busy">Ocupado</option>
                                    <option value="away">Ausente</option>
                                    <option value="offline">Offline</option>
                                </select>
                            </div>
                            <div class="full check-grid">
                                <label class="label">Departamentos</label>
                                <?php foreach ($departments as $department): ?>
                                    <div class="check-item">
                                        <label>
                                            <input type="checkbox" name="department_ids[]" value="<?= (int)$department['id'] ?>">
                                            <?= wa_e($department['name']) ?>
                                        </label>
                                        <select class="select" style="max-width: 180px;" name="department_roles[<?= (int)$department['id'] ?>]">
                                            <option value="agent">Atendente</option>
                                            <option value="supervisor">Supervisor</option>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="full">
                                <label><input type="checkbox" name="is_super_admin" value="1"> Super admin do módulo</label>
                            </div>
                            <div class="full">
                                <button class="button" type="submit">Salvar atendente</button>
                            </div>
                        </form>
                    </div>
                </article>
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Equipe cadastrada</h3></div>
                    <?php if ($users === []): ?>
                        <div class="empty">Nenhum atendente cadastrado.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th>Atendente</th><th>Departamentos</th><th>Status</th><th>Ação</th></tr></thead>
                                <tbody>
                                <?php foreach ($users as $listedUser): ?>
                                    <tr>
                                        <td><strong><?= wa_e($listedUser['display_name']) ?></strong><br><span class="small"><?= wa_e($listedUser['email']) ?></span></td>
                                        <td><?= wa_e($listedUser['departments'] ?: 'Sem vínculo') ?></td>
                                        <td>
                                            <span class="badge <?= !empty($listedUser['is_active']) ? 'badge-success' : 'badge-danger' ?>">
                                                <?= wa_e(wa_status_label((string)$listedUser['status'])) ?>
                                            </span>
                                            <?php if (!empty($listedUser['is_super_admin'])): ?>
                                                <span class="badge">Admin</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post">
                                                <input type="hidden" name="action" value="toggle_user">
                                                <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                                <input type="hidden" name="id" value="<?= (int)$listedUser['id'] ?>">
                                                <button class="button-secondary" type="submit"><?= !empty($listedUser['is_active']) ? 'Desativar' : 'Ativar' ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        <?php elseif ($page === 'inboxes'): ?>
            <section class="grid-2">
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Nova inbox / sessão</h3></div>
                    <div class="card-body">
                        <form method="post" class="form-grid">
                            <input type="hidden" name="action" value="save_inbox">
                            <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                            <div>
                                <label class="label">Nome da inbox</label>
                                <input class="field" name="name" required placeholder="WhatsApp Comercial">
                            </div>
                            <div>
                                <label class="label">Session key</label>
                                <input class="field" name="session_key" required placeholder="smile-comercial-01">
                            </div>
                            <div>
                                <label class="label">Telefone</label>
                                <input class="field" name="phone_number" placeholder="+55 11 99999-9999">
                            </div>
                            <div>
                                <label class="label">Provider</label>
                                <select class="select" name="provider">
                                    <option value="baileys">Baileys</option>
                                    <option value="whatsapp-web.js">whatsapp-web.js</option>
                                    <option value="wppconnect">WPPConnect</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Modo de conexão</label>
                                <select class="select" name="connection_mode">
                                    <option value="qr">QR Code</option>
                                    <option value="pairing_code">Pairing Code</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Departamento padrão</label>
                                <select class="select" name="department_id">
                                    <option value="0">Sem vínculo inicial</option>
                                    <?php foreach ($departments as $department): ?>
                                        <option value="<?= (int)$department['id'] ?>"><?= wa_e($department['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="full">
                                <label class="label">Observações</label>
                                <textarea class="textarea" name="notes" placeholder="Observações da sessão"></textarea>
                            </div>
                            <div class="full">
                                <button class="button" type="submit">Salvar inbox</button>
                            </div>
                        </form>
                    </div>
                </article>
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Inboxes cadastradas</h3></div>
                    <?php if ($inboxes === []): ?>
                        <div class="empty">Nenhuma inbox cadastrada ainda.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th>Inbox</th><th>Departamento</th><th>Engine</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($inboxes as $inbox): ?>
                                    <tr>
                                        <td><strong><?= wa_e($inbox['name']) ?></strong><br><span class="small"><?= wa_e($inbox['session_key']) ?><?= $inbox['phone_number'] ? ' • ' . wa_e($inbox['phone_number']) : '' ?></span></td>
                                        <td><?= wa_e($inbox['department_name'] ?: 'Sem vínculo') ?></td>
                                        <td><?= wa_e($inbox['provider']) ?><br><span class="small"><?= wa_e($inbox['connection_mode']) ?></span></td>
                                        <td><span class="badge <?= $inbox['status'] === 'connected' ? 'badge-success' : ($inbox['status'] === 'error' ? 'badge-danger' : 'badge-warning') ?>"><?= wa_e(wa_status_label((string)$inbox['status'])) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        <?php elseif ($page === 'quick_replies'): ?>
            <section class="grid-2">
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Novo atalho</h3></div>
                    <div class="card-body">
                        <form method="post" class="form-grid">
                            <input type="hidden" name="action" value="save_quick_reply">
                            <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                            <div>
                                <label class="label">Título</label>
                                <input class="field" name="title" required placeholder="Saudação comercial">
                            </div>
                            <div>
                                <label class="label">Atalho</label>
                                <input class="field" name="shortcut" required placeholder="/orcamento">
                            </div>
                            <div class="full">
                                <label class="label">Departamento</label>
                                <select class="select" name="department_id">
                                    <option value="0">Global</option>
                                    <?php foreach ($departments as $department): ?>
                                        <option value="<?= (int)$department['id'] ?>"><?= wa_e($department['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="full">
                                <label class="label">Mensagem</label>
                                <textarea class="textarea" name="body" required placeholder="Texto padrão do atalho"></textarea>
                            </div>
                            <div class="full">
                                <button class="button" type="submit">Salvar atalho</button>
                            </div>
                        </form>
                    </div>
                </article>
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Atalhos cadastrados</h3></div>
                    <?php if ($quickReplies === []): ?>
                        <div class="empty">Nenhum atalho cadastrado ainda.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th>Atalho</th><th>Departamento</th><th>Mensagem</th></tr></thead>
                                <tbody>
                                <?php foreach ($quickReplies as $reply): ?>
                                    <tr>
                                        <td><strong><?= wa_e($reply['shortcut']) ?></strong><br><span class="small"><?= wa_e($reply['title']) ?></span></td>
                                        <td><?= wa_e($reply['department_name'] ?: 'Global') ?></td>
                                        <td><?= nl2br(wa_e($reply['body'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
            </section>
        <?php elseif ($page === 'conversations'): ?>
            <section class="grid-2">
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Inbox compartilhada</h3></div>
                    <?php if ($conversations === []): ?>
                        <div class="empty">
                            A base de conversas está pronta, mas ainda não há eventos chegando das sessões WhatsApp.
                            A próxima etapa vai popular esta lista automaticamente a partir do motor de conexão.
                        </div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="table">
                                <thead><tr><th>Contato</th><th>Departamento</th><th>Responsável</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($conversations as $conversation): ?>
                                    <tr>
                                        <td><strong><?= wa_e($conversation['contact_name']) ?></strong><br><span class="small"><?= wa_e($conversation['subject'] ?: $conversation['inbox_name']) ?></span></td>
                                        <td><?= wa_e($conversation['department_name'] ?: 'Sem departamento') ?></td>
                                        <td><?= wa_e($conversation['assigned_name'] ?: 'Fila aberta') ?></td>
                                        <td>
                                            <span class="badge"><?= wa_e(wa_status_label((string)$conversation['status'])) ?></span>
                                            <?php if ((int)$conversation['unread_count'] > 0): ?>
                                                <span class="badge badge-warning"><?= (int)$conversation['unread_count'] ?> não lidas</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </article>
                <article class="card">
                    <div class="section-header"><h3 class="section-title">O que falta nesta tela</h3></div>
                    <div class="card-body stack">
                        <div class="topbar-card"><strong>Realtime</strong><p class="small">Socket para atualização instantânea de novas mensagens e movimentação entre filas.</p></div>
                        <div class="topbar-card"><strong>Operação</strong><p class="small">Assumir conversa, transferir, concluir, nota interna e atalhos no composer.</p></div>
                        <div class="topbar-card"><strong>Conectividade</strong><p class="small">Integração da sessão WhatsApp com persistência de mídia, QR e presença.</p></div>
                    </div>
                </article>
            </section>
        <?php else: ?>
            <section class="grid-2">
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Roadmap imediato</h3></div>
                    <div class="card-body stack">
                        <div class="topbar-card"><strong>Fase 2</strong><p class="small">Serviço Node dedicado para sessão WhatsApp não-oficial, QR e consumo de eventos.</p></div>
                        <div class="topbar-card"><strong>Fase 3</strong><p class="small">Worker de ingestão para abrir conversa, persistir mensagem e distribuir por departamento.</p></div>
                        <div class="topbar-card"><strong>Fase 4</strong><p class="small">Frontend realtime com cola de atendimento, transferência, copiloto e métricas.</p></div>
                    </div>
                </article>
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Decisões já tomadas</h3></div>
                    <div class="card-body stack">
                        <div class="topbar-card"><strong>App isolado</strong><p class="small">Login e sessão próprios para não colidir com o painel atual.</p></div>
                        <div class="topbar-card"><strong>Schema próprio</strong><p class="small">Tabelas `wa_*` para evitar acoplamento com entidades legadas do projeto.</p></div>
                        <div class="topbar-card"><strong>Inboxes abstratas</strong><p class="small">A camada PHP não depende do provider final; Baileys, whatsapp-web.js ou WPP ficam na borda.</p></div>
                    </div>
                </article>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
