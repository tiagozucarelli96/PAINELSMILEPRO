<?php
/**
 * cliente_notificacoes.php
 * Central de modelos e regras de notificação para clientes.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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

try {
    cliente_notificacoes_ensure_schema($pdo);
} catch (Throwable $e) {
    $erro = 'Não foi possível preparar a estrutura de notificações: ' . $e->getMessage();
}

if (empty($erro) && isset($_GET['preview'])) {
    $modeloIdPreview = (int)($_GET['modelo'] ?? 0);
    if ($modeloIdPreview <= 0) {
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($erro)) {
    try {
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
if (empty($erro)) {
    $modelos = $pdo->query("SELECT * FROM cliente_notificacao_modelos ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $modeloId = (int)($_GET['modelo'] ?? 0);
    foreach ($modelos as $modelo) {
        if ($modeloAtual === null || ($modeloId > 0 && (int)$modelo['id'] === $modeloId)) {
            $modeloAtual = $modelo;
        }
    }
    $enviosRecentes = $pdo->query("
        SELECT *
        FROM cliente_notificacao_envios
        ORDER BY created_at DESC, id DESC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
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
.notif-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 1rem; }
.notif-code-list { padding: .9rem 1.1rem 1.1rem; display: grid; gap: .55rem; }
.notif-code { display: grid; grid-template-columns: 155px 1fr; gap: .6rem; align-items: start; font-size: .88rem; }
.notif-code code { color: #0f766e; font-weight: 800; background: #ecfdf5; border: 1px solid #bbf7d0; border-radius: 6px; padding: .22rem .35rem; white-space: nowrap; }
.notif-code span { color: #475569; }
.notif-history { padding: .85rem 1.1rem 1.1rem; overflow-x: auto; }
.notif-history table { width: 100%; border-collapse: collapse; font-size: .86rem; }
.notif-history th, .notif-history td { text-align: left; border-bottom: 1px solid #e2e8f0; padding: .55rem .45rem; vertical-align: top; }
.notif-history th { color: #64748b; font-size: .76rem; text-transform: uppercase; letter-spacing: .04em; }
@media (max-width: 960px) {
    .notif-layout, .notif-meta, .notif-grid { grid-template-columns: 1fr; }
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
        <aside class="notif-panel">
            <div class="notif-panel-head">
                <h2>Modelos</h2>
                <span>Novas regras futuras entram nesta lista.</span>
            </div>
            <div class="notif-list">
                <?php foreach ($modelos as $modelo): ?>
                    <a class="notif-list-item <?= (int)$modelo['id'] === (int)$modeloAtual['id'] ? 'active' : '' ?>" href="index.php?page=cliente_notificacoes&modelo=<?= (int)$modelo['id'] ?>">
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

        <main>
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
                            href="index.php?page=cliente_notificacoes&modelo=<?= (int)$modeloAtual['id'] ?>&preview=1"
                            target="_blank"
                            rel="noopener"
                        >Ver prévia</a>
                        <button type="submit" class="notif-btn">Salvar notificação</button>
                    </div>
                </form>
            </section>

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
        </main>
    </div>
    <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Notificações para Clientes');
echo $conteudo;
endSidebar();
?>
