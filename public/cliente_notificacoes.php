<?php
/**
 * cliente_notificacoes.php
 * Central de modelos e regras de notificação para clientes.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_GET['page'])) {
    $_GET['page'] = 'cliente_notificacoes';
}

if (empty($_SESSION['logado']) || (empty($_SESSION['perm_configuracoes']) && empty($_SESSION['perm_administrativo']) && empty($_SESSION['perm_superadmin']))) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/cliente_notificacoes_helper.php';

$mensagem = '';
$erro = '';
$campanhaResultado = null;

if (!cliente_notificacoes_schema_pronto($pdo)) {
    $erro = 'Estrutura de notificações ainda não instalada. Execute a migração sql/073_cliente_notificacoes.sql.';
}

if (empty($erro) && isset($_GET['preview'])) {
    $modeloIdPreview = (int)($_GET['modelo'] ?? 0);
    $modeloChavePreview = trim((string)($_GET['modelo_chave'] ?? $_GET['chave'] ?? ''));
    if ($modeloChavePreview !== '') {
        $modeloPreview = cliente_notificacoes_get_modelo($pdo, $modeloChavePreview);
    } elseif ($modeloIdPreview <= 0) {
        $modeloPreview = cliente_notificacoes_get_modelo($pdo, 'contrato_aprovado');
    } else {
        $stmtPreview = $pdo->prepare("SELECT * FROM cliente_notificacao_modelos WHERE id = :id LIMIT 1");
        $stmtPreview->execute([':id' => $modeloIdPreview]);
        $modeloPreview = $stmtPreview->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$modeloPreview) {
        http_response_code(404);
        echo 'Modelo de notificação não encontrado.';
        exit;
    }

    $variaveisPreview = [
        '{{nome_cliente}}' => 'Tiago Zucarelli',
        '{{nome_evento}}' => 'Casamento',
        '{{data_evento}}' => '29/09/2026',
        '{{link_painel}}' => 'https://painelpro.smileeventos.com.br/index.php?page=eventos_cliente_portal&token=exemplo',
        '{{prazo_formularios}}' => '14/09/2026',
    ];

    echo cliente_notificacoes_render_email($modeloPreview, $variaveisPreview);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($erro) && ($_POST['acao'] ?? '') === 'enviar_campanha_portal') {
    try {
        cliente_notificacoes_ensure_schema($pdo);
        $usuarioId = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
        $campanhaResultado = cliente_notificacoes_enviar_campanha_portal_lancamento($pdo, $usuarioId, 500);
        if (empty($campanhaResultado['ok'])) {
            throw new RuntimeException((string)($campanhaResultado['error'] ?? 'Não foi possível enviar a campanha.'));
        }
        $mensagem = 'Campanha processada. Enviados: ' . (int)$campanhaResultado['enviados']
            . ' | Erros: ' . (int)$campanhaResultado['erros']
            . ' | Ignorados: ' . (int)$campanhaResultado['ignorados'] . '.';
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($erro) && ($_POST['acao'] ?? '') !== 'enviar_campanha_portal') {
    try {
        cliente_notificacoes_ensure_schema($pdo);
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $gatilho = trim((string)($_POST['gatilho'] ?? ''));
        $assunto = trim((string)($_POST['assunto'] ?? ''));
        $mensagemTexto = trim((string)($_POST['mensagem_texto'] ?? ''));
        $botaoTexto = trim((string)($_POST['botao_texto'] ?? 'Acessar painel'));
        $ativo = !empty($_POST['ativo']);
        $envioAutomatico = !empty($_POST['envio_automatico']);
        $canalEmail = !empty($_POST['canal_email']);

        if ($id <= 0) {
            throw new RuntimeException('Modelo inválido.');
        }
        if ($nome === '' || $assunto === '' || $mensagemTexto === '') {
            throw new RuntimeException('Nome, assunto e mensagem são obrigatórios.');
        }
        if ($botaoTexto === '') {
            $botaoTexto = 'Acessar painel';
        }

        $stmt = $pdo->prepare("
            UPDATE cliente_notificacao_modelos
            SET nome = :nome,
                descricao = :descricao,
                gatilho = :gatilho,
                ativo = :ativo,
                envio_automatico = :envio_automatico,
                canal_email = :canal_email,
                assunto = :assunto,
                mensagem_texto = :mensagem_texto,
                botao_texto = :botao_texto,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->bindValue(':nome', $nome);
        $stmt->bindValue(':descricao', $descricao !== '' ? $descricao : null);
        $stmt->bindValue(':gatilho', $gatilho !== '' ? $gatilho : null);
        $stmt->bindValue(':ativo', $ativo, PDO::PARAM_BOOL);
        $stmt->bindValue(':envio_automatico', $envioAutomatico, PDO::PARAM_BOOL);
        $stmt->bindValue(':canal_email', $canalEmail, PDO::PARAM_BOOL);
        $stmt->bindValue(':assunto', $assunto);
        $stmt->bindValue(':mensagem_texto', $mensagemTexto);
        $stmt->bindValue(':botao_texto', $botaoTexto);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $mensagem = 'Notificação salva com sucesso.';
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$modelos = [];
$modeloAtual = null;
$enviosRecentes = [];
$publicoCampanha = [];
$publicoCampanhaConsultado = false;
if (empty($erro)) {
    $modeloId = (int)($_GET['modelo'] ?? 0);
    $modeloChave = trim((string)($_GET['modelo_chave'] ?? $_GET['chave'] ?? ''));
    $modeloChaveNormalizada = strtolower($modeloChave);

    $stmtModelos = $pdo->prepare("
        SELECT id, chave, nome, descricao, gatilho, ativo, envio_automatico, canal_email, assunto, mensagem_texto, botao_texto
        FROM cliente_notificacao_modelos
        ORDER BY
            CASE
                WHEN :modelo_chave_filtro <> '' AND chave = :modelo_chave_match THEN 0
                WHEN :modelo_id_filtro > 0 AND id = :modelo_id_match THEN 0
                ELSE 1
            END,
            id ASC
    ");
    $stmtModelos->bindValue(':modelo_chave_filtro', $modeloChave);
    $stmtModelos->bindValue(':modelo_chave_match', $modeloChave);
    $stmtModelos->bindValue(':modelo_id_filtro', $modeloId, PDO::PARAM_INT);
    $stmtModelos->bindValue(':modelo_id_match', $modeloId, PDO::PARAM_INT);
    $stmtModelos->execute();
    $modelos = $stmtModelos->fetchAll(PDO::FETCH_ASSOC);
    $modeloAtual = null;
    foreach ($modelos as $modelo) {
        $chaveModelo = strtolower(trim((string)($modelo['chave'] ?? '')));
        if ($modeloChaveNormalizada !== '' && $chaveModelo === $modeloChaveNormalizada) {
            $modeloAtual = $modelo;
            break;
        }
    }
    if ($modeloAtual === null && $modeloId > 0) {
        foreach ($modelos as $modelo) {
            if ((int)$modelo['id'] === $modeloId) {
                $modeloAtual = $modelo;
                break;
            }
        }
    }
    if ($modeloAtual === null) {
        $modeloAtual = $modelos[0] ?? null;
    }
    if (($modeloAtual['chave'] ?? '') !== 'portal_cliente_lancamento') {
        $enviosRecentes = $pdo->query("
            SELECT id, chave_modelo, cliente_nome, cliente_email, status, created_at
            FROM cliente_notificacao_envios
            ORDER BY id DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if (($modeloAtual['chave'] ?? '') === 'portal_cliente_lancamento') {
        $publicoCampanhaConsultado = isset($_GET['consultar_publico']) || ($_POST['acao'] ?? '') === 'enviar_campanha_portal';
        if ($publicoCampanhaConsultado) {
            try {
                $publicoCampanha = cliente_notificacoes_buscar_publico_portal_lancamento($pdo, 500);
            } catch (Throwable $e) {
                $erro = 'Não foi possível consultar a ME Eventos para montar o público da campanha: ' . $e->getMessage();
                $publicoCampanha = [];
            }
        }
    }
}

$codigos = cliente_notificacoes_codigos();

ob_start();
?>

<style>
.notif-page { max-width: 1320px; margin: 0 auto; padding: 1.5rem; color: #0f172a; }
.notif-header { margin-bottom: 1.5rem; }
.notif-header h1 { margin: 0 0 .35rem; font-size: 1.9rem; color: #1e3a8a; }
.notif-header p { margin: 0; color: #64748b; font-size: 1rem; }
.notif-alert { border-radius: 8px; padding: .85rem 1rem; margin-bottom: 1rem; border: 1px solid; }
.notif-alert.ok { background: #ecfdf5; color: #065f46; border-color: #bbf7d0; }
.notif-alert.err { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.notif-layout { display: grid; grid-template-columns: minmax(260px, 330px) 1fr; gap: 1rem; align-items: start; }
.notif-modelos { min-width: 0; }
.notif-main { min-width: 0; }
.notif-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 5px rgba(15,23,42,.06); }
.notif-panel-head { padding: 1rem 1.1rem; border-bottom: 1px solid #e2e8f0; }
.notif-panel-head h2 { margin: 0; font-size: 1rem; }
.notif-panel-head span { display: block; margin-top: .25rem; color: #64748b; font-size: .86rem; }
.notif-list { padding: .65rem; display: grid; gap: .5rem; }
.notif-list-item { display: block; text-decoration: none; color: inherit; border: 1px solid #e2e8f0; border-radius: 8px; padding: .8rem; background: #f8fafc; }
.notif-list-item.active { border-color: #0f766e; background: #ecfdf5; }
.notif-list-item strong { display: block; font-size: .96rem; margin-bottom: .25rem; }
.notif-list-item small { color: #64748b; line-height: 1.35; }
.notif-badges { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .6rem; }
.notif-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: .2rem .5rem; font-size: .74rem; font-weight: 700; background: #e2e8f0; color: #334155; }
.notif-badge.green { background: #dcfce7; color: #166534; }
.notif-badge.blue { background: #dbeafe; color: #1d4ed8; }
.notif-form { padding: 1.1rem; }
.notif-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .9rem; }
.notif-field { margin-bottom: .9rem; }
.notif-field label { display: block; margin-bottom: .35rem; color: #334155; font-size: .85rem; font-weight: 700; }
.notif-field input[type="text"], .notif-field textarea { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: .75rem; font-size: .94rem; color: #0f172a; background: #fff; box-sizing: border-box; }
.notif-field textarea { min-height: 300px; resize: vertical; line-height: 1.45; }
.notif-toggles { display: flex; flex-wrap: wrap; gap: .8rem; margin: .4rem 0 1rem; }
.notif-toggle { display: inline-flex; align-items: center; gap: .45rem; color: #334155; font-weight: 700; font-size: .9rem; }
.notif-actions { display: flex; justify-content: flex-end; gap: .7rem; border-top: 1px solid #e2e8f0; padding-top: 1rem; }
.notif-btn { border: 0; border-radius: 8px; padding: .75rem 1rem; font-weight: 800; cursor: pointer; background: #0f766e; color: #fff; }
.notif-btn.secondary { background: #e2e8f0; color: #0f172a; text-decoration: none; display: inline-flex; align-items: center; }
.notif-btn.primary-blue { background: #1e3a8a; }
.notif-btn.danger { background: #b91c1c; }
.notif-btn[disabled] { opacity: .55; cursor: not-allowed; }
.notif-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem; }
.notif-campaign { margin-top: 1rem; }
.notif-campaign-summary { display: grid; grid-template-columns: repeat(4, minmax(120px, 1fr)); gap: .75rem; padding: 1.1rem; }
.notif-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: .85rem; }
.notif-stat strong { display: block; font-size: 1.35rem; color: #1e3a8a; }
.notif-stat span { color: #64748b; font-size: .82rem; font-weight: 700; }
.notif-campaign-actions { display: flex; justify-content: space-between; align-items: center; gap: .75rem; padding: 0 1.1rem 1.1rem; border-bottom: 1px solid #e2e8f0; }
.notif-warning { color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: .75rem .85rem; font-size: .88rem; line-height: 1.45; }
.notif-audience { padding: .85rem 1.1rem 1.1rem; overflow-x: auto; }
.notif-audience table { width: 100%; border-collapse: collapse; font-size: .86rem; }
.notif-audience th, .notif-audience td { text-align: left; border-bottom: 1px solid #e2e8f0; padding: .55rem .45rem; vertical-align: top; }
.notif-audience th { color: #64748b; font-size: .76rem; text-transform: uppercase; letter-spacing: .04em; }
.notif-code-list { padding: .9rem 1.1rem 1.1rem; display: grid; gap: .55rem; }
.notif-code { display: grid; grid-template-columns: 155px 1fr; gap: .6rem; align-items: start; font-size: .88rem; }
.notif-code code { color: #0f766e; font-weight: 800; background: #ecfdf5; border: 1px solid #bbf7d0; border-radius: 6px; padding: .22rem .35rem; white-space: nowrap; }
.notif-code span { color: #475569; }
.notif-history { padding: .85rem 1.1rem 1.1rem; overflow-x: auto; }
.notif-history table { width: 100%; border-collapse: collapse; font-size: .86rem; }
.notif-history th, .notif-history td { text-align: left; border-bottom: 1px solid #e2e8f0; padding: .55rem .45rem; vertical-align: top; }
.notif-history th { color: #64748b; font-size: .76rem; text-transform: uppercase; letter-spacing: .04em; }
@media (max-width: 960px) {
    .notif-layout, .notif-meta, .notif-grid, .notif-campaign-summary { grid-template-columns: 1fr; }
    .notif-main { order: 1; }
    .notif-modelos { order: 2; }
    .notif-campaign-actions { align-items: stretch; flex-direction: column; }
    .notif-code { grid-template-columns: 1fr; }
}
</style>

<div class="notif-page">
    <div class="notif-header">
        <h1>Notificações para Clientes</h1>
        <p>Gerencie mensagens automáticas, modelos e regras de envio para clientes.</p>
    </div>

    <?php if ($mensagem): ?><div class="notif-alert ok"><?= h($mensagem) ?></div><?php endif; ?>
    <?php if ($erro): ?><div class="notif-alert err"><?= h($erro) ?></div><?php endif; ?>

    <?php if ($modeloAtual): ?>
    <div class="notif-layout">
        <aside class="notif-panel notif-modelos">
            <div class="notif-panel-head">
                <h2>Modelos</h2>
                <span>Novas regras futuras entram nesta lista.</span>
            </div>
            <div class="notif-list">
                <?php foreach ($modelos as $modelo): ?>
                    <a class="notif-list-item <?= (int)$modelo['id'] === (int)$modeloAtual['id'] ? 'active' : '' ?>" href="cliente_notificacoes.php?modelo_chave=<?= urlencode((string)$modelo['chave']) ?>">
                        <strong><?= h($modelo['nome']) ?></strong>
                        <small><?= h($modelo['gatilho'] ?: $modelo['descricao']) ?></small>
                        <span class="notif-badges">
                            <span class="notif-badge <?= !empty($modelo['ativo']) ? 'green' : '' ?>"><?= !empty($modelo['ativo']) ? 'Ativa' : 'Inativa' ?></span>
                            <span class="notif-badge <?= !empty($modelo['envio_automatico']) ? 'blue' : '' ?>"><?= !empty($modelo['envio_automatico']) ? 'Automática' : 'Manual' ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <div class="notif-main">
            <section class="notif-panel">
                <div class="notif-panel-head">
                    <h2><?= h($modeloAtual['nome']) ?></h2>
                    <span><?= h($modeloAtual['descricao']) ?></span>
                </div>

                <form method="post" class="notif-form">
                    <input type="hidden" name="id" value="<?= (int)$modeloAtual['id'] ?>">

                    <div class="notif-grid">
                        <div class="notif-field">
                            <label for="nome">Nome da notificação</label>
                            <input type="text" id="nome" name="nome" value="<?= h($modeloAtual['nome']) ?>" required>
                        </div>
                        <div class="notif-field">
                            <label for="gatilho">Quando dispara</label>
                            <input type="text" id="gatilho" name="gatilho" value="<?= h($modeloAtual['gatilho']) ?>">
                        </div>
                    </div>

                    <div class="notif-field">
                        <label for="descricao">Descrição interna</label>
                        <input type="text" id="descricao" name="descricao" value="<?= h($modeloAtual['descricao']) ?>">
                    </div>

                    <div class="notif-toggles">
                        <label class="notif-toggle"><input type="checkbox" name="ativo" value="1" <?= !empty($modeloAtual['ativo']) ? 'checked' : '' ?>> Ativa</label>
                        <label class="notif-toggle"><input type="checkbox" name="envio_automatico" value="1" <?= !empty($modeloAtual['envio_automatico']) ? 'checked' : '' ?>> Enviar automaticamente</label>
                        <label class="notif-toggle"><input type="checkbox" name="canal_email" value="1" <?= !empty($modeloAtual['canal_email']) ? 'checked' : '' ?>> E-mail</label>
                    </div>

                    <div class="notif-grid">
                        <div class="notif-field">
                            <label for="assunto">Assunto do e-mail</label>
                            <input type="text" id="assunto" name="assunto" value="<?= h($modeloAtual['assunto']) ?>" required>
                        </div>
                        <div class="notif-field">
                            <label for="botao_texto">Texto do botão</label>
                            <input type="text" id="botao_texto" name="botao_texto" value="<?= h($modeloAtual['botao_texto']) ?>" required>
                        </div>
                    </div>

                    <div class="notif-field">
                        <label for="mensagem_texto">Mensagem</label>
                        <textarea id="mensagem_texto" name="mensagem_texto" required><?= h($modeloAtual['mensagem_texto']) ?></textarea>
                    </div>

                    <div class="notif-actions">
                        <a
                            class="notif-btn secondary"
                            href="cliente_notificacoes.php?modelo_chave=<?= urlencode((string)$modeloAtual['chave']) ?>&preview=1"
                            target="_blank"
                            rel="noopener"
                        >Ver prévia</a>
                        <button type="submit" class="notif-btn">Salvar notificação</button>
                    </div>
                </form>
            </section>

            <?php if (($modeloAtual['chave'] ?? '') === 'portal_cliente_lancamento'): ?>
                <?php
                    $totalPublico = count($publicoCampanha);
                    $comPortal = count(array_filter($publicoCampanha, static fn($item) => !empty($item['portal_url'])));
                    $semPortal = max(0, $totalPublico - $comPortal);
                    $emailsInvalidos = count(array_filter($publicoCampanha, static fn($item) => empty($item['email_valido'])));
                ?>
                <section class="notif-panel notif-campaign">
                    <div class="notif-panel-head">
                        <h2>Disparo em massa</h2>
                        <span>Consulta direta na ME Eventos: eventos futuros a partir de 30/05/2026, apenas casamento e 15 anos, sem envio anterior desta campanha.</span>
                    </div>
                    <div class="notif-campaign-summary">
                        <div class="notif-stat"><strong><?= (int)$totalPublico ?></strong><span>clientes elegíveis</span></div>
                        <div class="notif-stat"><strong><?= (int)$comPortal ?></strong><span>já com portal</span></div>
                        <div class="notif-stat"><strong><?= (int)$semPortal ?></strong><span>portais a criar</span></div>
                        <div class="notif-stat"><strong><?= (int)$emailsInvalidos ?></strong><span>e-mails inválidos</span></div>
                    </div>
                    <div class="notif-campaign-actions">
                        <div class="notif-warning">
                            A tela abre sem consultar a ME Eventos para não travar. Primeiro clique em consultar público; depois confira a lista e envie. No envio, o sistema cria automaticamente o portal para quem ainda não tiver e dispara o e-mail com o link individual.
                        </div>
                        <div style="display:flex;gap:.6rem;flex-wrap:wrap;justify-content:flex-end;">
                            <a class="notif-btn secondary" href="cliente_notificacoes.php?modelo_chave=<?= urlencode((string)$modeloAtual['chave']) ?>&consultar_publico=1">Consultar público na ME</a>
                            <form method="post" onsubmit="return confirm('Enviar esta campanha para os clientes elegíveis agora?');">
                                <input type="hidden" name="acao" value="enviar_campanha_portal">
                                <button type="submit" class="notif-btn primary-blue" <?= (!$publicoCampanhaConsultado || $totalPublico <= 0) ? 'disabled' : '' ?>>Enviar campanha</button>
                            </form>
                        </div>
                    </div>
                    <div class="notif-audience">
                        <table>
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Evento</th>
                                    <th>Data</th>
                                    <th>Portal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$publicoCampanhaConsultado): ?>
                                    <tr><td colspan="4">Clique em “Consultar público na ME” para carregar os clientes elegíveis.</td></tr>
                                <?php elseif (empty($publicoCampanha)): ?>
                                    <tr><td colspan="4">Nenhum cliente elegível pendente para esta campanha.</td></tr>
                                <?php endif; ?>
                                <?php foreach (array_slice($publicoCampanha, 0, 80) as $item): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($item['nome_completo'] ?: '-') ?></strong><br>
                                            <span style="color:#64748b;"><?= h($item['email'] ?: '') ?></span>
                                        </td>
                                        <td><?= h(cliente_notificacoes_nome_evento($item)) ?></td>
                                        <td><?= h(cliente_notificacoes_data_br((string)($item['data_evento'] ?? ''))) ?></td>
                                        <td>
                                            <?php if (!empty($item['portal_url'])): ?>
                                                <span class="notif-badge green">Criado</span>
                                            <?php else: ?>
                                                <span class="notif-badge">Será criado</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>

            <div class="notif-meta">
                <section class="notif-panel">
                    <div class="notif-panel-head">
                        <h2>Legenda dos códigos</h2>
                        <span>Use estes códigos no assunto ou na mensagem.</span>
                    </div>
                    <div class="notif-code-list">
                        <?php foreach ($codigos as $codigo => $descricao): ?>
                            <div class="notif-code">
                                <code><?= h($codigo) ?></code>
                                <span><?= h($descricao) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="notif-panel">
                    <div class="notif-panel-head">
                        <h2>Últimos envios</h2>
                        <span>Histórico técnico dos disparos recentes.</span>
                    </div>
                    <div class="notif-history">
                        <table>
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($enviosRecentes)): ?>
                                    <tr><td colspan="3">Nenhum envio registrado.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($enviosRecentes as $envio): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($envio['cliente_nome'] ?: '-') ?></strong><br>
                                            <span style="color:#64748b;"><?= h($envio['cliente_email'] ?: '') ?></span>
                                        </td>
                                        <td><?= h($envio['status']) ?></td>
                                        <td><?= h(!empty($envio['created_at']) ? date('d/m/Y H:i', strtotime((string)$envio['created_at'])) : '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Notificações para Clientes');
echo $conteudo;
endSidebar();
?>
