<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || (empty($_SESSION['perm_administrativo']) && empty($_SESSION['perm_superadmin']))) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/push_helper.php';
require_once __DIR__ . '/core/email_global_helper.php';

function adminNotifUsuarioLogadoId(): int
{
    return (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
}

function adminNotifBoolPost(string $campo, bool $default = false): bool
{
    if (!array_key_exists($campo, $_POST)) {
        return $default;
    }
    $valor = strtolower(trim((string)$_POST[$campo]));
    return in_array($valor, ['1', 'on', 'true', 'yes', 'sim'], true);
}

function adminNotifColunaExiste(PDO $pdo, string $tabela, string $coluna): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.columns
        WHERE table_name = :table_name
          AND column_name = :column_name
        LIMIT 1
    ");
    $stmt->execute([
        ':table_name' => $tabela,
        ':column_name' => $coluna
    ]);

    return (bool)$stmt->fetchColumn();
}

function adminNotifGarantirSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS administrativo_notificacoes_disparos (
            id BIGSERIAL PRIMARY KEY,
            titulo VARCHAR(180) NOT NULL,
            mensagem TEXT NOT NULL,
            url_destino TEXT,
            canais JSONB NOT NULL DEFAULT '[]'::jsonb,
            modo_destino VARCHAR(20) NOT NULL DEFAULT 'selecionados',
            total_destinatarios INT NOT NULL DEFAULT 0,
            enviados_interno INT NOT NULL DEFAULT 0,
            enviados_push INT NOT NULL DEFAULT 0,
            enviados_email INT NOT NULL DEFAULT 0,
            falhas_interno INT NOT NULL DEFAULT 0,
            falhas_push INT NOT NULL DEFAULT 0,
            falhas_email INT NOT NULL DEFAULT 0,
            emails_sem_endereco INT NOT NULL DEFAULT 0,
            criado_por_usuario_id BIGINT REFERENCES usuarios(id) ON DELETE SET NULL,
            criado_em TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_admin_notif_disparos_criado_em ON administrativo_notificacoes_disparos(criado_em DESC)");

    // Garantir estrutura mínima de notificações internas.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS demandas_notificacoes (
            id BIGSERIAL PRIMARY KEY,
            usuario_id BIGINT REFERENCES usuarios(id) ON DELETE CASCADE,
            tipo VARCHAR(50) NOT NULL,
            referencia_id INT,
            titulo VARCHAR(180),
            mensagem TEXT NOT NULL,
            url_destino TEXT,
            lida BOOLEAN DEFAULT FALSE,
            criada_em TIMESTAMPTZ DEFAULT NOW()
        )
    ");

    $pdo->exec("ALTER TABLE demandas_notificacoes ADD COLUMN IF NOT EXISTS titulo VARCHAR(180)");
    $pdo->exec("ALTER TABLE demandas_notificacoes ADD COLUMN IF NOT EXISTS url_destino TEXT");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_demandas_notificacoes_usuario ON demandas_notificacoes(usuario_id, lida)");
}

function adminNotifBuscarUsuariosAtivos(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, nome, email
        FROM usuarios
        WHERE ativo IS DISTINCT FROM FALSE
        ORDER BY nome ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function adminNotifBuscarDestinatarios(PDO $pdo, string $modoDestino, array $idsSelecionados): array
{
    if ($modoDestino === 'todos') {
        return adminNotifBuscarUsuariosAtivos($pdo);
    }

    $ids = [];
    foreach ($idsSelecionados as $id) {
        $idInt = (int)$id;
        if ($idInt > 0) {
            $ids[$idInt] = $idInt;
        }
    }
    $ids = array_values($ids);

    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT id, nome, email
        FROM usuarios
        WHERE ativo IS DISTINCT FROM FALSE
          AND id IN ({$placeholders})
        ORDER BY nome ASC
    ");
    $stmt->execute($ids);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function adminNotifInserirInterna(PDO $pdo, int $usuarioId, string $titulo, string $mensagem, string $urlDestino = ''): array
{
    try {
        if ($usuarioId <= 0) {
            return ['success' => false, 'error' => 'Usuário inválido'];
        }

        $hasReferenciaId = adminNotifColunaExiste($pdo, 'demandas_notificacoes', 'referencia_id');
        $hasTitulo = adminNotifColunaExiste($pdo, 'demandas_notificacoes', 'titulo');
        $hasUrlDestino = adminNotifColunaExiste($pdo, 'demandas_notificacoes', 'url_destino');

        $campos = ['usuario_id', 'tipo', 'mensagem', 'lida'];
        $valores = [':usuario_id', ':tipo', ':mensagem', ':lida'];
        $params = [
            ':usuario_id' => $usuarioId,
            ':tipo' => 'administrativo_manual',
            ':mensagem' => $mensagem,
            ':lida' => false
        ];

        if ($hasReferenciaId) {
            $campos[] = 'referencia_id';
            $valores[] = ':referencia_id';
            $params[':referencia_id'] = null;
        }

        if ($hasTitulo) {
            $campos[] = 'titulo';
            $valores[] = ':titulo';
            $params[':titulo'] = $titulo;
        }

        if ($hasUrlDestino) {
            $campos[] = 'url_destino';
            $valores[] = ':url_destino';
            $params[':url_destino'] = $urlDestino !== '' ? $urlDestino : null;
        }

        $sql = "
            INSERT INTO demandas_notificacoes (" . implode(', ', $campos) . ")
            VALUES (" . implode(', ', $valores) . ")
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return ['success' => true];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function adminNotifCorpoEmail(string $titulo, string $mensagem, string $urlDestino = ''): string
{
    $tituloHtml = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $mensagemHtml = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));
    $urlHtml = htmlspecialchars($urlDestino, ENT_QUOTES, 'UTF-8');

    $botao = '';
    if ($urlDestino !== '') {
        $botao = "
            <p style='margin-top: 18px;'>
                <a href='{$urlHtml}' style='display:inline-block;background:#1e3a8a;color:#ffffff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;'>
                    Abrir no Painel
                </a>
            </p>
        ";
    }

    return "
        <div style='font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;max-width:700px;margin:0 auto;'>
            <div style='background:#1e3a8a;color:#ffffff;padding:16px 18px;border-radius:10px 10px 0 0;'>
                <h2 style='margin:0;font-size:20px;'>{$tituloHtml}</h2>
            </div>
            <div style='border:1px solid #dbe3ef;border-top:none;padding:16px 18px;border-radius:0 0 10px 10px;background:#ffffff;'>
                <div style='font-size:15px;color:#334155;'>{$mensagemHtml}</div>
                {$botao}
                <p style='margin-top:22px;color:#64748b;font-size:13px;'>Mensagem enviada pelo módulo Administrativo.</p>
            </div>
        </div>
    ";
}

function adminNotifRegistrarDisparo(PDO $pdo, array $dados): void
{
    $stmt = $pdo->prepare("
        INSERT INTO administrativo_notificacoes_disparos (
            titulo, mensagem, url_destino, canais, modo_destino,
            total_destinatarios, enviados_interno, enviados_push, enviados_email,
            falhas_interno, falhas_push, falhas_email, emails_sem_endereco,
            criado_por_usuario_id
        ) VALUES (
            :titulo, :mensagem, :url_destino, CAST(:canais AS jsonb), :modo_destino,
            :total_destinatarios, :enviados_interno, :enviados_push, :enviados_email,
            :falhas_interno, :falhas_push, :falhas_email, :emails_sem_endereco,
            :criado_por_usuario_id
        )
    ");

    $stmt->execute([
        ':titulo' => $dados['titulo'],
        ':mensagem' => $dados['mensagem'],
        ':url_destino' => $dados['url_destino'] !== '' ? $dados['url_destino'] : null,
        ':canais' => json_encode($dados['canais'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':modo_destino' => $dados['modo_destino'],
        ':total_destinatarios' => (int)$dados['total_destinatarios'],
        ':enviados_interno' => (int)$dados['enviados_interno'],
        ':enviados_push' => (int)$dados['enviados_push'],
        ':enviados_email' => (int)$dados['enviados_email'],
        ':falhas_interno' => (int)$dados['falhas_interno'],
        ':falhas_push' => (int)$dados['falhas_push'],
        ':falhas_email' => (int)$dados['falhas_email'],
        ':emails_sem_endereco' => (int)$dados['emails_sem_endereco'],
        ':criado_por_usuario_id' => (int)$dados['criado_por_usuario_id'] > 0 ? (int)$dados['criado_por_usuario_id'] : null,
    ]);
}

function adminNotifResumoTexto(string $texto, int $limite = 80): string
{
    $texto = trim($texto);
    if ($texto === '') {
        return '';
    }

    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($texto, 0, $limite, '...');
    }

    if (strlen($texto) <= $limite) {
        return $texto;
    }

    return substr($texto, 0, max(0, $limite - 3)) . '...';
}

adminNotifGarantirSchema($pdo);

$erro = '';
$sucesso = '';
$resumoEnvio = null;

$formData = [
    'titulo' => trim((string)($_POST['titulo'] ?? '')),
    'mensagem' => trim((string)($_POST['mensagem'] ?? '')),
    'url_destino' => trim((string)($_POST['url_destino'] ?? '')),
    'modo_destino' => (string)($_POST['modo_destino'] ?? 'selecionados'),
    'destinatarios' => array_map('intval', (array)($_POST['destinatarios'] ?? [])),
    'canal_interno' => $_SERVER['REQUEST_METHOD'] === 'POST' ? adminNotifBoolPost('canal_interno') : true,
    'canal_push' => $_SERVER['REQUEST_METHOD'] === 'POST' ? adminNotifBoolPost('canal_push') : true,
    'canal_email' => $_SERVER['REQUEST_METHOD'] === 'POST' ? adminNotifBoolPost('canal_email') : false,
];

if (!in_array($formData['modo_destino'], ['todos', 'selecionados'], true)) {
    $formData['modo_destino'] = 'selecionados';
}

$usuariosAtivos = adminNotifBuscarUsuariosAtivos($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'enviar_notificacao') {
    $titulo = $formData['titulo'];
    $mensagem = $formData['mensagem'];
    $urlDestino = $formData['url_destino'];
    $modoDestino = $formData['modo_destino'];

    $canalInterno = $formData['canal_interno'];
    $canalPush = $formData['canal_push'];
    $canalEmail = $formData['canal_email'];

    if ($titulo === '') {
        $erro = 'Informe o título da notificação.';
    } elseif ($mensagem === '') {
        $erro = 'Informe a mensagem da notificação.';
    } elseif (!$canalInterno && !$canalPush && !$canalEmail) {
        $erro = 'Selecione pelo menos um canal de envio.';
    } else {
        $destinatarios = adminNotifBuscarDestinatarios($pdo, $modoDestino, $formData['destinatarios']);

        if (empty($destinatarios)) {
            $erro = $modoDestino === 'selecionados'
                ? 'Selecione pelo menos um destinatário ativo.'
                : 'Nenhum destinatário ativo encontrado.';
        } else {
            $pushHelper = null;
            $emailHelper = null;

            if ($canalPush) {
                try {
                    $pushHelper = new PushHelper();
                } catch (Throwable $e) {
                    $erro = 'Falha ao iniciar envio Push: ' . $e->getMessage();
                }
            }

            if ($erro === '' && $canalEmail) {
                try {
                    $emailHelper = new EmailGlobalHelper();
                } catch (Throwable $e) {
                    $erro = 'Falha ao iniciar envio de e-mail: ' . $e->getMessage();
                }
            }

            if ($erro === '') {
                $contadores = [
                    'total_destinatarios' => count($destinatarios),
                    'enviados_interno' => 0,
                    'enviados_push' => 0,
                    'enviados_email' => 0,
                    'falhas_interno' => 0,
                    'falhas_push' => 0,
                    'falhas_email' => 0,
                    'emails_sem_endereco' => 0,
                ];

                foreach ($destinatarios as $usuario) {
                    $usuarioId = (int)($usuario['id'] ?? 0);
                    $emailUsuario = trim((string)($usuario['email'] ?? ''));

                    if ($usuarioId <= 0) {
                        continue;
                    }

                    if ($canalInterno) {
                        $retornoInterno = adminNotifInserirInterna($pdo, $usuarioId, $titulo, $mensagem, $urlDestino);
                        if (!empty($retornoInterno['success'])) {
                            $contadores['enviados_interno']++;
                        } else {
                            $contadores['falhas_interno']++;
                        }
                    }

                    if ($canalPush && $pushHelper) {
                        $retornoPush = $pushHelper->enviarPush(
                            $usuarioId,
                            $titulo,
                            $mensagem,
                            [
                                'url' => $urlDestino !== '' ? $urlDestino : 'index.php?page=dashboard',
                                'tipo' => 'administrativo_manual'
                            ]
                        );

                        if (!empty($retornoPush['success'])) {
                            $contadores['enviados_push']++;
                        } else {
                            $contadores['falhas_push']++;
                        }
                    }

                    if ($canalEmail && $emailHelper) {
                        if ($emailUsuario === '' || !filter_var($emailUsuario, FILTER_VALIDATE_EMAIL)) {
                            $contadores['emails_sem_endereco']++;
                            $contadores['falhas_email']++;
                        } else {
                            $okEmail = $emailHelper->enviarEmail(
                                $emailUsuario,
                                $titulo,
                                adminNotifCorpoEmail($titulo, $mensagem, $urlDestino),
                                true
                            );

                            if ($okEmail) {
                                $contadores['enviados_email']++;
                            } else {
                                $contadores['falhas_email']++;
                            }
                        }
                    }
                }

                $canais = [];
                if ($canalInterno) {
                    $canais[] = 'interna';
                }
                if ($canalPush) {
                    $canais[] = 'push';
                }
                if ($canalEmail) {
                    $canais[] = 'email';
                }

                adminNotifRegistrarDisparo($pdo, [
                    'titulo' => $titulo,
                    'mensagem' => $mensagem,
                    'url_destino' => $urlDestino,
                    'canais' => $canais,
                    'modo_destino' => $modoDestino,
                    'total_destinatarios' => $contadores['total_destinatarios'],
                    'enviados_interno' => $contadores['enviados_interno'],
                    'enviados_push' => $contadores['enviados_push'],
                    'enviados_email' => $contadores['enviados_email'],
                    'falhas_interno' => $contadores['falhas_interno'],
                    'falhas_push' => $contadores['falhas_push'],
                    'falhas_email' => $contadores['falhas_email'],
                    'emails_sem_endereco' => $contadores['emails_sem_endereco'],
                    'criado_por_usuario_id' => adminNotifUsuarioLogadoId(),
                ]);

                $resumoEnvio = $contadores;
                $sucesso = 'Disparo concluído.';
            }
        }
    }
}

$stmtHistorico = $pdo->query("
    SELECT d.*, u.nome AS criador_nome
    FROM administrativo_notificacoes_disparos d
    LEFT JOIN usuarios u ON u.id = d.criado_por_usuario_id
    ORDER BY d.criado_em DESC
    LIMIT 30
");
$historico = $stmtHistorico->fetchAll(PDO::FETCH_ASSOC) ?: [];

ob_start();
?>

<style>
    .admin-notif-page {
        width: 100%;
        max-width: 1200px;
        margin: 0 auto;
        padding: 1.5rem;
    }

    .admin-notif-header {
        margin-bottom: 1.25rem;
    }

    .admin-notif-header h1 {
        margin: 0;
        font-size: 1.7rem;
        color: #1e3a8a;
    }

    .admin-notif-header p {
        margin: 0.4rem 0 0 0;
        color: #64748b;
    }

    .admin-notif-grid {
        display: grid;
        grid-template-columns: 1.15fr 0.85fr;
        gap: 1rem;
        align-items: start;
    }

    .admin-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }

    .admin-card h2 {
        margin: 0 0 0.8rem 0;
        font-size: 1.1rem;
        color: #0f172a;
    }

    .admin-alert {
        border-radius: 10px;
        padding: 0.75rem 0.9rem;
        margin-bottom: 0.9rem;
        font-size: 0.92rem;
    }

    .admin-alert.error {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
    }

    .admin-alert.success {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #065f46;
    }

    .admin-form-group {
        margin-bottom: 0.8rem;
    }

    .admin-form-group label {
        display: block;
        margin-bottom: 0.35rem;
        font-weight: 600;
        color: #334155;
        font-size: 0.88rem;
    }

    .admin-form-group input[type="text"],
    .admin-form-group textarea,
    .admin-form-group select {
        width: 100%;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        padding: 0.65rem 0.75rem;
        font-size: 0.9rem;
    }

    .admin-form-group textarea {
        min-height: 120px;
        resize: vertical;
    }

    .admin-hint {
        margin-top: 0.35rem;
        color: #64748b;
        font-size: 0.78rem;
    }

    .admin-checks {
        display: flex;
        flex-wrap: wrap;
        gap: 0.8rem 1rem;
    }

    .admin-checks label {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        font-size: 0.88rem;
        color: #334155;
        margin: 0;
    }

    .admin-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.6rem;
        margin-top: 0.8rem;
    }

    .btn {
        border: 1px solid transparent;
        border-radius: 10px;
        padding: 0.62rem 0.95rem;
        font-size: 0.88rem;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
    }

    .btn-primary {
        background: #1e3a8a;
        border-color: #1e3a8a;
        color: #fff;
    }

    .btn-outline {
        background: #fff;
        border-color: #cbd5e1;
        color: #334155;
    }

    .admin-summary {
        margin-top: 0.8rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 0.75rem;
        font-size: 0.84rem;
        color: #334155;
        line-height: 1.5;
    }

    .admin-history-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.82rem;
    }

    .admin-history-table th,
    .admin-history-table td {
        border-bottom: 1px solid #e2e8f0;
        padding: 0.55rem 0.4rem;
        text-align: left;
        vertical-align: top;
    }

    .admin-history-table th {
        color: #334155;
        font-weight: 700;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .admin-badge {
        display: inline-block;
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 0.72rem;
        font-weight: 700;
        margin-right: 0.25rem;
        margin-bottom: 0.25rem;
        background: #eff6ff;
        color: #1e40af;
    }

    .destinatarios-wrapper {
        display: none;
    }

    .destinatarios-wrapper.show {
        display: block;
    }

    @media (max-width: 980px) {
        .admin-notif-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="admin-notif-page">
    <div class="admin-notif-header">
        <h1>🔔 Central de Notificações</h1>
        <p>Dispare avisos manuais para usuários internos por notificação no painel, Push e e-mail.</p>
    </div>

    <?php if ($erro !== ''): ?>
        <div class="admin-alert error"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <?php if ($sucesso !== ''): ?>
        <div class="admin-alert success"><?= htmlspecialchars($sucesso) ?></div>
    <?php endif; ?>

    <div class="admin-notif-grid">
        <div class="admin-card">
            <h2>Novo Disparo</h2>

            <form method="POST">
                <input type="hidden" name="action" value="enviar_notificacao">

                <div class="admin-form-group">
                    <label for="titulo">Título *</label>
                    <input type="text" id="titulo" name="titulo" maxlength="180" required value="<?= htmlspecialchars($formData['titulo']) ?>">
                </div>

                <div class="admin-form-group">
                    <label for="mensagem">Mensagem *</label>
                    <textarea id="mensagem" name="mensagem" required><?= htmlspecialchars($formData['mensagem']) ?></textarea>
                </div>

                <div class="admin-form-group">
                    <label for="url_destino">URL de destino (opcional)</label>
                    <input type="text" id="url_destino" name="url_destino" value="<?= htmlspecialchars($formData['url_destino']) ?>" placeholder="Ex.: index.php?page=agenda">
                    <div class="admin-hint">Se informado, esta URL será usada quando disponível no aviso interno e no payload de push.</div>
                </div>

                <div class="admin-form-group">
                    <label>Canais</label>
                    <div class="admin-checks">
                        <label><input type="checkbox" name="canal_interno" value="1" <?= $formData['canal_interno'] ? 'checked' : '' ?>> Notificação interna</label>
                        <label><input type="checkbox" name="canal_push" value="1" <?= $formData['canal_push'] ? 'checked' : '' ?>> Push no navegador</label>
                        <label><input type="checkbox" name="canal_email" value="1" <?= $formData['canal_email'] ? 'checked' : '' ?>> E-mail</label>
                    </div>
                </div>

                <div class="admin-form-group">
                    <label for="modo_destino">Destinatários</label>
                    <select id="modo_destino" name="modo_destino" onchange="toggleDestinatariosAdminNotif()">
                        <option value="selecionados" <?= $formData['modo_destino'] === 'selecionados' ? 'selected' : '' ?>>Usuários selecionados</option>
                        <option value="todos" <?= $formData['modo_destino'] === 'todos' ? 'selected' : '' ?>>Todos os usuários ativos</option>
                    </select>
                </div>

                <div id="destinatarios-wrapper" class="admin-form-group destinatarios-wrapper <?= $formData['modo_destino'] === 'selecionados' ? 'show' : '' ?>">
                    <label for="destinatarios">Selecionar usuários</label>
                    <select id="destinatarios" name="destinatarios[]" multiple style="min-height: 180px;">
                        <?php foreach ($usuariosAtivos as $usuario): ?>
                            <?php $uid = (int)($usuario['id'] ?? 0); ?>
                            <option value="<?= $uid ?>" <?= in_array($uid, $formData['destinatarios'], true) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($usuario['nome'] ?? 'Usuário')) ?>
                                <?php if (!empty($usuario['email'])): ?>
                                    (<?= htmlspecialchars((string)$usuario['email']) ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="admin-hint">Use Ctrl/Cmd para seleção múltipla.</div>
                </div>

                <div class="admin-actions">
                    <a class="btn btn-outline" href="index.php?page=administrativo">Voltar</a>
                    <button type="submit" class="btn btn-primary">Enviar Aviso</button>
                </div>
            </form>

            <?php if (is_array($resumoEnvio)): ?>
                <div class="admin-summary">
                    <strong>Resumo do envio:</strong><br>
                    Destinatários: <?= (int)$resumoEnvio['total_destinatarios'] ?><br>
                    Interno: <?= (int)$resumoEnvio['enviados_interno'] ?> sucesso / <?= (int)$resumoEnvio['falhas_interno'] ?> falha<br>
                    Push: <?= (int)$resumoEnvio['enviados_push'] ?> sucesso / <?= (int)$resumoEnvio['falhas_push'] ?> falha<br>
                    E-mail: <?= (int)$resumoEnvio['enviados_email'] ?> sucesso / <?= (int)$resumoEnvio['falhas_email'] ?> falha
                    <?php if ((int)$resumoEnvio['emails_sem_endereco'] > 0): ?>
                        (<?= (int)$resumoEnvio['emails_sem_endereco'] ?> sem e-mail válido)
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="admin-card">
            <h2>Histórico Recente</h2>
            <?php if (empty($historico)): ?>
                <div class="admin-hint">Nenhum disparo registrado ainda.</div>
            <?php else: ?>
                <div style="overflow:auto;">
                    <table class="admin-history-table">
                        <thead>
                            <tr>
                                <th>Quando</th>
                                <th>Título</th>
                                <th>Canais</th>
                                <th>Totais</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($historico as $item): ?>
                                <?php
                                $canais = json_decode((string)($item['canais'] ?? '[]'), true);
                                if (!is_array($canais)) {
                                    $canais = [];
                                }
                                $criadoEm = !empty($item['criado_em']) ? strtotime((string)$item['criado_em']) : false;
                                ?>
                                <tr>
                                    <td>
                                        <?= $criadoEm ? date('d/m H:i', $criadoEm) : '-' ?><br>
                                        <span style="color:#64748b;"><?= htmlspecialchars((string)($item['criador_nome'] ?? 'Sistema')) ?></span>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars((string)($item['titulo'] ?? '')) ?></strong>
                                        <div style="color:#64748b; margin-top:2px;"><?= htmlspecialchars(adminNotifResumoTexto((string)($item['mensagem'] ?? ''), 80)) ?></div>
                                    </td>
                                    <td>
                                        <?php if (empty($canais)): ?>
                                            <span style="color:#64748b;">-</span>
                                        <?php else: ?>
                                            <?php foreach ($canais as $canal): ?>
                                                <span class="admin-badge"><?= htmlspecialchars((string)$canal) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        Dest: <?= (int)($item['total_destinatarios'] ?? 0) ?><br>
                                        Int: <?= (int)($item['enviados_interno'] ?? 0) ?>/<?= (int)($item['falhas_interno'] ?? 0) ?><br>
                                        Push: <?= (int)($item['enviados_push'] ?? 0) ?>/<?= (int)($item['falhas_push'] ?? 0) ?><br>
                                        Email: <?= (int)($item['enviados_email'] ?? 0) ?>/<?= (int)($item['falhas_email'] ?? 0) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function toggleDestinatariosAdminNotif() {
        const modo = document.getElementById('modo_destino');
        const wrapper = document.getElementById('destinatarios-wrapper');
        if (!modo || !wrapper) return;

        if (modo.value === 'selecionados') {
            wrapper.classList.add('show');
        } else {
            wrapper.classList.remove('show');
        }
    }
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Administrativo - Notificações');
echo $conteudo;
endSidebar();
