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
                wa_install_module();
                wa_flash('success', 'Módulo instalado. Agora libere os usuários do Smile Chat no cadastro principal de usuários.');
                wa_redirect();

            case 'login':
                if (!wa_login((string)($_POST['login'] ?? ''), (string)($_POST['password'] ?? ''))) {
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
                wa_redirect('index.php?page=settings&tab=departments');

            case 'toggle_department':
                wa_toggle_department((int)($_POST['id'] ?? 0));
                wa_flash('success', 'Status do departamento atualizado.');
                wa_redirect('index.php?page=settings&tab=departments');

            case 'save_user':
                wa_save_user($_POST);
                wa_flash('success', 'Atendente salvo.');
                wa_redirect('index.php?page=settings&tab=users');

            case 'toggle_user':
                wa_toggle_user((int)($_POST['id'] ?? 0));
                wa_flash('success', 'Status do atendente atualizado.');
                wa_redirect('index.php?page=settings&tab=users');

            case 'save_inbox':
                wa_save_inbox($_POST);
                wa_flash('success', 'Inbox salvo.');
                wa_redirect('index.php?page=settings&tab=inboxes');

            case 'connect_inbox':
                wa_connect_inbox_session((string)($_POST['session_key'] ?? ''));
                wa_flash('success', 'Solicitação de conexão enviada para a inbox.');
                wa_redirect('index.php?page=settings&tab=inboxes');

            case 'disconnect_inbox':
                wa_disconnect_inbox_session((string)($_POST['session_key'] ?? ''));
                wa_flash('success', 'Solicitação de desconexão enviada para a inbox.');
                wa_redirect('index.php?page=settings&tab=inboxes');

            case 'save_quick_reply':
                wa_save_quick_reply($_POST);
                wa_flash('success', 'Atalho salvo.');
                wa_redirect('index.php?page=settings&tab=quick_replies');

            case 'accept_conversation':
                wa_accept_conversation((int)($_POST['conversation_id'] ?? 0), wa_auth_user());
                wa_flash('success', 'Conversa aceita e movida para Ativos.');
                wa_redirect('index.php?page=conversations&conversation_id=' . (int)($_POST['conversation_id'] ?? 0) . '&bucket=active');

            case 'transfer_conversation':
                wa_transfer_conversation(
                    (int)($_POST['conversation_id'] ?? 0),
                    (int)($_POST['target_user_id'] ?? 0),
                    (int)($_POST['target_department_id'] ?? 0)
                );
                wa_flash('success', 'Conversa transferida.');
                wa_redirect('index.php?page=conversations&conversation_id=' . (int)($_POST['conversation_id'] ?? 0) . '&bucket=active');

            case 'finalize_conversation':
                wa_finalize_conversation((int)($_POST['conversation_id'] ?? 0));
                wa_flash('success', 'Conversa finalizada.');
                wa_redirect('index.php?page=conversations');

            case 'end_conversation':
                wa_end_conversation((int)($_POST['conversation_id'] ?? 0));
                wa_flash('success', 'Atendimento encerrado.');
                wa_redirect('index.php?page=conversations');

            case 'reopen_conversation':
                wa_reopen_conversation((int)($_POST['conversation_id'] ?? 0), wa_auth_user());
                wa_flash('success', 'Conversa reaberta.');
                wa_redirect('index.php?page=conversations&conversation_id=' . (int)($_POST['conversation_id'] ?? 0) . '&bucket=active');

            case 'send_conversation_reply':
                wa_send_conversation_reply(
                    (int)($_POST['conversation_id'] ?? 0),
                    (string)($_POST['body'] ?? ''),
                    wa_auth_user()
                );
                wa_flash('success', 'Mensagem enviada.');
                wa_redirect('index.php?page=conversations&conversation_id=' . (int)($_POST['conversation_id'] ?? 0) . '&bucket=active');
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

if (!$tablesReady) {
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
                    <button class="button" type="submit">Instalar módulo</button>
                </form>
                <p class="muted-note">
                    Schema base: <code><?= wa_e($schemaPath) ?></code><br>
                    O acesso será feito pelos mesmos usuários e senhas do Painel Smile, via permissões no cadastro principal.
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
                        <label class="label" for="login">Login ou email</label>
                        <input class="field" id="login" name="login" required placeholder="seu email ou login do painel">
                    </div>
                    <div>
                        <label class="label" for="password">Senha</label>
                        <input class="field" id="password" name="password" type="password" required placeholder="Sua senha">
                    </div>
                    <button class="button" type="submit">Entrar</button>
                </form>
                <p class="muted-note">
                    Caminho atual do app: <code><?= wa_e(wa_base_path()) ?></code><br>
                    Liberação de acesso: <code>perm_smile_chat</code> e <code>perm_smile_chat_admin</code>.
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
$settingsTab = wa_settings_tab();
$canManageSettings = wa_user_can_manage_settings($user);

$navItems = [
    'dashboard' => 'Dashboard',
    'conversations' => 'Conversas',
];

$counts = wa_fetch_dashboard_counts();
$departments = wa_fetch_all_departments();
$users = wa_fetch_users();
$inboxes = wa_fetch_inboxes();
$quickReplies = wa_fetch_quick_replies();
$conversations = wa_fetch_conversations();
$selectedConversationId = (int)($_GET['conversation_id'] ?? 0);
$selectedBucket = (string)($_GET['bucket'] ?? 'all');
$selectedConversation = $selectedConversationId > 0 ? wa_fetch_conversation_detail($selectedConversationId) : null;
$conversationMessages = $selectedConversation ? wa_fetch_conversation_messages((int)$selectedConversation['id']) : [];
$transferTargets = wa_transfer_targets();
$gatewayHealth = wa_gateway_health();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= wa_e(WA_APP_TITLE) ?> • <?= wa_e($page === 'settings' ? 'Configurações' : ($navItems[$page] ?? 'Dashboard')) ?></title>
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
                <h2 class="page-title"><?= wa_e($page === 'settings' ? 'Configurações' : ($navItems[$page] ?? 'Dashboard')) ?></h2>
                <p class="page-copy">
                    <?= match ($page) {
                        'dashboard' => 'Visão geral do novo ambiente de atendimento.',
                        'conversations' => 'Base da caixa de entrada compartilhada e atribuição por departamento.',
                        'settings' => 'Área restrita para configurar inboxes, departamentos, acessos e atalhos do Smile Chat.',
                        default => 'Operação do Smile Chat.',
                    } ?>
                </p>
            </div>
            <div class="topbar-actions">
                <?php if ($canManageSettings): ?>
                    <a class="icon-button <?= $page === 'settings' ? 'active' : '' ?>" href="<?= wa_url('index.php?page=settings&tab=' . $settingsTab) ?>" title="Configurações do Smile Chat" aria-label="Configurações do Smile Chat">&#9881;</a>
                <?php endif; ?>
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
                <article class="card"><div class="card-body"><p class="stat-label">Inboxes conectadas</p><p class="stat-value"><?= (int)$counts['inboxes_connected'] ?></p></div></article>
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
                        <h3 class="section-title">Gateway de sessão</h3>
                    </div>
                    <div class="card-body stack">
                        <?php if (is_array($gatewayHealth) && !empty($gatewayHealth['ok'])): ?>
                            <div class="topbar-card">
                                <strong>Gateway online</strong>
                                <p class="small">Serviço respondendo em <code><?= wa_e(wa_gateway_base_url()) ?></code>.</p>
                            </div>
                            <div class="topbar-card">
                                <strong>Conectadas agora</strong>
                                <p class="small"><?= (int)($gatewayHealth['overview']['connected_inboxes'] ?? 0) ?> inboxes conectadas no gateway.</p>
                            </div>
                            <div class="topbar-card">
                                <strong>Mensagens 24h</strong>
                                <p class="small"><?= (int)($gatewayHealth['overview']['messages_last_day'] ?? 0) ?> eventos entregues pelo gateway nas últimas 24 horas.</p>
                            </div>
                        <?php else: ?>
                            <div class="topbar-card"><strong>Gateway indisponível</strong><p class="small"><?= wa_e((string)($gatewayHealth['error'] ?? 'Serviço não respondeu.')) ?></p></div>
                            <div class="topbar-card"><strong>URL esperada</strong><p class="small"><code><?= wa_e(wa_gateway_base_url()) ?></code></p></div>
                            <div class="topbar-card"><strong>Próximo incremento</strong><p class="small">Quando o gateway estiver online, as inboxes passam a exibir QR, status e ações de conectar/desconectar.</p></div>
                        <?php endif; ?>
                    </div>
                </article>
            </section>
        <?php elseif ($page === 'settings' && $canManageSettings): ?>
            <section class="settings-tabs">
                <a class="settings-tab <?= $settingsTab === 'inboxes' ? 'active' : '' ?>" href="<?= wa_url('index.php?page=settings&tab=inboxes') ?>">Inboxes</a>
                <a class="settings-tab <?= $settingsTab === 'departments' ? 'active' : '' ?>" href="<?= wa_url('index.php?page=settings&tab=departments') ?>">Departamentos</a>
                <a class="settings-tab <?= $settingsTab === 'users' ? 'active' : '' ?>" href="<?= wa_url('index.php?page=settings&tab=users') ?>">Acessos</a>
                <a class="settings-tab <?= $settingsTab === 'quick_replies' ? 'active' : '' ?>" href="<?= wa_url('index.php?page=settings&tab=quick_replies') ?>">Atalhos</a>
            </section>

            <?php if ($settingsTab === 'departments'): ?>
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
            <?php elseif ($settingsTab === 'users'): ?>
                <section class="grid-2">
                    <article class="card">
                        <div class="section-header"><h3 class="section-title">Controle de acesso</h3></div>
                        <div class="card-body stack">
                            <div class="topbar-card">
                                <strong>Mesmo login e senha do painel</strong>
                                <p class="small">O Smile Chat autentica diretamente na tabela principal <code>usuarios</code>. Nenhuma senha é cadastrada aqui.</p>
                            </div>
                            <div class="topbar-card">
                                <strong>Permissões que liberam acesso</strong>
                                <p class="small"><code>perm_smile_chat</code> libera o login no chat. <code>perm_smile_chat_admin</code> ou <code>perm_superadmin</code> liberam a engrenagem de configurações.</p>
                            </div>
                            <div class="topbar-card">
                                <strong>Onde ajustar</strong>
                                <p class="small">Marque essas permissões na tela principal de usuários do Painel Smile. Este módulo apenas reflete quem já foi liberado.</p>
                            </div>
                        </div>
                    </article>
                    <article class="card">
                        <div class="section-header"><h3 class="section-title">Usuários liberados</h3></div>
                        <?php if ($users === []): ?>
                            <div class="empty">Nenhum usuário do painel foi liberado para o Smile Chat ainda.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead><tr><th>Usuário</th><th>Login</th><th>Perfil no chat</th><th>Status</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($users as $listedUser): ?>
                                        <tr>
                                            <td><strong><?= wa_e($listedUser['display_name']) ?></strong><br><span class="small"><?= wa_e($listedUser['email']) ?></span></td>
                                            <td><?= wa_e($listedUser['login'] ?: 'Sem login explícito') ?></td>
                                            <td>
                                                <span class="badge"><?= wa_e($listedUser['departments']) ?></span>
                                                <?php if (!empty($listedUser['is_super_admin'])): ?>
                                                    <span class="badge">Superadmin</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="badge <?= !empty($listedUser['is_active']) ? 'badge-success' : 'badge-danger' ?>"><?= !empty($listedUser['is_active']) ? 'Ativo' : 'Inativo' ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </article>
                </section>
            <?php elseif ($settingsTab === 'inboxes'): ?>
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
                                        <option value="mock">Mock / Homologação</option>
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
                        <div class="card-body">
                            <?php if (is_array($gatewayHealth) && !empty($gatewayHealth['ok'])): ?>
                                <div class="flash flash-info">
                                    Gateway online em <code><?= wa_e(wa_gateway_base_url()) ?></code>.
                                    Conectadas agora: <strong><?= (int)($gatewayHealth['overview']['connected_inboxes'] ?? 0) ?></strong>.
                                </div>
                            <?php else: ?>
                                <div class="flash flash-error">
                                    Gateway indisponível em <code><?= wa_e(wa_gateway_base_url()) ?></code>.
                                    <?= wa_e((string)($gatewayHealth['error'] ?? 'Sem resposta do serviço.')) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($inboxes === []): ?>
                            <div class="empty">Nenhuma inbox cadastrada ainda.</div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead><tr><th>Inbox</th><th>Departamento</th><th>Engine</th><th>Status</th><th>Operação</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($inboxes as $inbox): ?>
                                        <?php
                                        $runtimeMeta = is_array($inbox['runtime_meta'] ?? null) ? $inbox['runtime_meta'] : [];
                                        $uiStatus = (string)($inbox['gateway_status'] ?: $inbox['status']);
                                        $statusClass = $uiStatus === 'connected'
                                            ? 'badge-success'
                                            : ($uiStatus === 'error' ? 'badge-danger' : 'badge-warning');
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= wa_e($inbox['name']) ?></strong><br>
                                                <span class="small"><?= wa_e($inbox['session_key']) ?><?= $inbox['phone_number'] ? ' • ' . wa_e($inbox['phone_number']) : '' ?></span>
                                                <?php if (!empty($runtimeMeta['pairingCode'])): ?>
                                                    <div class="code-chip">Pairing code: <?= wa_e((string)$runtimeMeta['pairingCode']) ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($runtimeMeta['qrImage'])): ?>
                                                    <div class="qr-preview">
                                                        <img src="<?= wa_e((string)$runtimeMeta['qrImage']) ?>" alt="QR da sessão <?= wa_e($inbox['session_key']) ?>">
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= wa_e($inbox['department_name'] ?: 'Sem vínculo') ?></td>
                                            <td><?= wa_e($inbox['provider']) ?><br><span class="small"><?= wa_e($inbox['connection_mode']) ?></span></td>
                                            <td>
                                                <span class="badge <?= $statusClass ?>"><?= wa_e(wa_status_label($uiStatus)) ?></span>
                                                <?php if (!empty($inbox['credential_updated_at'])): ?>
                                                    <div class="small">Runtime: <?= wa_e((string)$inbox['credential_updated_at']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="stack">
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="connect_inbox">
                                                        <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                                        <input type="hidden" name="session_key" value="<?= wa_e($inbox['session_key']) ?>">
                                                        <button class="button-secondary" type="submit">Conectar</button>
                                                    </form>
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="disconnect_inbox">
                                                        <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                                        <input type="hidden" name="session_key" value="<?= wa_e($inbox['session_key']) ?>">
                                                        <button class="button-danger" type="submit">Desconectar</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </article>
                </section>
            <?php else: ?>
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
            <?php endif; ?>
        <?php elseif ($page === 'conversations'): ?>
            <?php
            $waitingConversations = array_values(array_filter($conversations, static fn (array $conversation): bool => ($conversation['status'] ?? '') === 'waiting'));
            $activeConversations = array_values(array_filter($conversations, static fn (array $conversation): bool => in_array(($conversation['status'] ?? ''), ['open', 'pending'], true)));
            $visibleConversations = match ($selectedBucket) {
                'waiting' => $waitingConversations,
                'active' => $activeConversations,
                default => $conversations,
            };
            ?>
            <section class="conversation-shell">
                <article class="card conversation-list-card">
                    <div class="conversation-search">
                        <input class="field" placeholder="Procure sua conversa..." disabled>
                    </div>
                    <div class="conversation-buckets">
                        <a class="bucket-pill <?= $selectedBucket === 'active' ? 'active' : '' ?>" href="<?= wa_url('index.php?page=conversations&bucket=active') ?>">Ativos <span class="badge"><?= count($activeConversations) ?></span></a>
                        <a class="bucket-pill <?= $selectedBucket === 'waiting' ? 'active' : '' ?>" href="<?= wa_url('index.php?page=conversations&bucket=waiting') ?>">Aguardando <span class="badge"><?= count($waitingConversations) ?></span></a>
                        <a class="bucket-pill <?= $selectedBucket === 'all' ? 'active' : '' ?>" href="<?= wa_url('index.php?page=conversations&bucket=all') ?>">Todas <span class="badge"><?= count($conversations) ?></span></a>
                    </div>

                    <?php if ($visibleConversations === []): ?>
                        <div class="empty">Nenhuma conversa nesta fila.</div>
                    <?php else: ?>
                        <div class="conversation-items">
                            <?php foreach ($visibleConversations as $conversation): ?>
                                <?php
                                $isSelected = $selectedConversation && (int)$selectedConversation['id'] === (int)$conversation['id'];
                                $conversationBucket = ($conversation['status'] ?? '') === 'waiting' ? 'waiting' : 'active';
                                ?>
                                <a class="conversation-item <?= $isSelected ? 'active' : '' ?>" href="<?= wa_url('index.php?page=conversations&bucket=' . $conversationBucket . '&conversation_id=' . (int)$conversation['id']) ?>">
                                    <div class="conversation-avatar"><?= wa_e(mb_substr((string)$conversation['contact_name'], 0, 1)) ?></div>
                                    <div class="conversation-meta">
                                        <div class="conversation-row">
                                            <strong><?= wa_e($conversation['contact_name']) ?></strong>
                                            <span class="small"><?= wa_e(date('H:i', strtotime((string)($conversation['last_message_at'] ?? 'now')))) ?></span>
                                        </div>
                                        <div class="small"><?= wa_e($conversation['department_name'] ?: 'Sem departamento') ?></div>
                                        <div class="conversation-preview"><?= wa_e($conversation['last_message_preview'] ?: 'Sem mensagens ainda.') ?></div>
                                    </div>
                                    <?php if ((int)$conversation['unread_count'] > 0): ?>
                                        <span class="badge badge-warning"><?= (int)$conversation['unread_count'] ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="card conversation-detail-card">
                    <?php if (!$selectedConversation): ?>
                        <div class="conversation-empty-state">
                            <h3 class="section-title">Selecione uma conversa</h3>
                            <p class="page-copy">As novas conversas entram em <strong>Aguardando</strong>. Ao aceitar, passam para <strong>Ativos</strong> com transferência, finalização e encerramento no cabeçalho.</p>
                        </div>
                    <?php else: ?>
                        <header class="conversation-header">
                            <div>
                                <h3 class="section-title"><?= wa_e($selectedConversation['contact_name']) ?></h3>
                                <p class="small">
                                    <?= wa_e($selectedConversation['phone_e164']) ?> •
                                    <?= wa_e($selectedConversation['department_name'] ?: 'Sem departamento') ?> •
                                    <?= wa_e(wa_status_label((string)$selectedConversation['status'])) ?>
                                </p>
                            </div>
                            <div class="conversation-actions">
                                <?php if (($selectedConversation['status'] ?? '') === 'waiting'): ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="accept_conversation">
                                        <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                        <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                        <button class="button" type="submit">Aceitar conversa</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (($selectedConversation['status'] ?? '') !== 'closed'): ?>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="action" value="transfer_conversation">
                                        <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                        <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                        <select class="select compact-select" name="target_user_id" required>
                                            <option value="">Transferir para</option>
                                            <?php foreach ($transferTargets as $targetUser): ?>
                                                <option value="<?= (int)$targetUser['id'] ?>"><?= wa_e($targetUser['display_name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <select class="select compact-select" name="target_department_id">
                                            <option value="0">Mesmo departamento</option>
                                            <?php foreach ($departments as $department): ?>
                                                <option value="<?= (int)$department['id'] ?>"><?= wa_e($department['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="button-secondary" type="submit">Transferir</button>
                                    </form>

                                    <form method="post">
                                        <input type="hidden" name="action" value="finalize_conversation">
                                        <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                        <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                        <button class="button-secondary" type="submit">Finalizar conversa</button>
                                    </form>

                                    <form method="post">
                                        <input type="hidden" name="action" value="end_conversation">
                                        <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                        <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                        <button class="button-danger" type="submit">Encerrar atendimento</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <input type="hidden" name="action" value="reopen_conversation">
                                        <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                        <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                        <button class="button" type="submit">Reabrir conversa</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </header>

                        <div class="message-thread">
                            <?php foreach ($conversationMessages as $message): ?>
                                <?php $outbound = in_array((string)$message['direction'], ['outbound', 'internal'], true); ?>
                                <div class="message-bubble <?= $outbound ? 'outbound' : 'inbound' ?>">
                                    <div class="message-body"><?= nl2br(wa_e((string)($message['body'] ?? '[mensagem sem texto]'))) ?></div>
                                    <div class="message-meta">
                                        <?= wa_e($message['author_name'] ?: ($outbound ? 'Equipe Smile' : $selectedConversation['contact_name'])) ?> •
                                        <?= wa_e(date('d/m H:i', strtotime((string)$message['created_at']))) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (($selectedConversation['status'] ?? '') !== 'closed'): ?>
                            <form method="post" class="composer">
                                <input type="hidden" name="action" value="send_conversation_reply">
                                <input type="hidden" name="csrf_token" value="<?= wa_e(wa_csrf_token()) ?>">
                                <input type="hidden" name="conversation_id" value="<?= (int)$selectedConversation['id'] ?>">
                                <textarea class="textarea composer-input" name="body" placeholder="Digite sua resposta..." required></textarea>
                                <div class="composer-actions">
                                    <button class="button" type="submit">Enviar mensagem</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            </section>
        <?php else: ?>
            <section class="grid-2">
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Acesso restrito</h3></div>
                    <div class="empty">
                        As configurações do Smile Chat ficam na engrenagem do canto superior direito e são liberadas apenas para usuários com
                        <code>perm_smile_chat_admin</code> ou <code>perm_superadmin</code>.
                    </div>
                </article>
                <article class="card">
                    <div class="section-header"><h3 class="section-title">Próximo passo</h3></div>
                    <div class="card-body stack">
                        <div class="topbar-card"><strong>Login unificado</strong><p class="small">O acesso do chat agora depende das permissões do cadastro principal de usuários.</p></div>
                        <div class="topbar-card"><strong>Configuração isolada</strong><p class="small">Inboxes, departamentos e atalhos saíram da navegação operacional e foram concentrados na engrenagem.</p></div>
                    </div>
                </article>
            </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
