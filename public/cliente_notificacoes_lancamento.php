<?php
/**
 * Página independente para gerenciar a campanha "Lançamento do Portal do Cliente".
 */
if (isset($_GET['codex_probe']) && $_GET['codex_probe'] === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'cliente-notificacoes-lancamento-v2';
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_GET['page'] = 'cliente_notificacoes_lancamento';

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
$publicoCampanha = [];
$publicoCampanhaConsultado = isset($_GET['consultar_publico']);

if (!cliente_notificacoes_schema_pronto($pdo)) {
    $erro = 'Estrutura de notificações ainda não instalada. Execute a migração sql/073_cliente_notificacoes.sql.';
}

if (empty($erro) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_modelo') {
    try {
        cliente_notificacoes_ensure_schema($pdo);
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
            WHERE chave = 'portal_cliente_lancamento'
        ");
        $stmt->execute([
            ':nome' => trim((string)($_POST['nome'] ?? 'Lançamento do Portal do Cliente')),
            ':descricao' => trim((string)($_POST['descricao'] ?? '')),
            ':gatilho' => trim((string)($_POST['gatilho'] ?? '')),
            ':ativo' => !empty($_POST['ativo']),
            ':envio_automatico' => !empty($_POST['envio_automatico']),
            ':canal_email' => !empty($_POST['canal_email']),
            ':assunto' => trim((string)($_POST['assunto'] ?? '')),
            ':mensagem_texto' => trim((string)($_POST['mensagem_texto'] ?? '')),
            ':botao_texto' => trim((string)($_POST['botao_texto'] ?? 'Acessar meu portal')),
        ]);
        $mensagem = 'Notificação salva com sucesso.';
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

if (empty($erro) && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'enviar_campanha_portal') {
    try {
        cliente_notificacoes_ensure_schema($pdo);
        $usuarioId = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
        $resultado = cliente_notificacoes_enviar_campanha_portal_lancamento($pdo, $usuarioId, 500);
        if (empty($resultado['ok'])) {
            throw new RuntimeException((string)($resultado['error'] ?? 'Não foi possível enviar a campanha.'));
        }
        $mensagem = 'Campanha processada. Enviados: ' . (int)$resultado['enviados']
            . ' | Erros: ' . (int)$resultado['erros']
            . ' | Ignorados: ' . (int)$resultado['ignorados'] . '.';
        $publicoCampanhaConsultado = true;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

$modeloAtual = empty($erro) ? cliente_notificacoes_get_modelo($pdo, 'portal_cliente_lancamento') : null;
if (!$modeloAtual && empty($erro)) {
    $erro = 'Modelo Lançamento do Portal do Cliente não encontrado.';
}

if (empty($erro) && $publicoCampanhaConsultado) {
    try {
        $publicoCampanha = cliente_notificacoes_buscar_publico_portal_lancamento($pdo, 500);
    } catch (Throwable $e) {
        $erro = 'Não foi possível consultar a ME Eventos para montar o público da campanha: ' . $e->getMessage();
    }
}

$codigos = cliente_notificacoes_codigos();
$totalPublico = count($publicoCampanha);
$comPortal = count(array_filter($publicoCampanha, static fn($item) => !empty($item['portal_url'])));
$semPortal = max(0, $totalPublico - $comPortal);
$emailsInvalidos = count(array_filter($publicoCampanha, static fn($item) => empty($item['email_valido'])));

ob_start();
?>
<style>
.launch-page{max-width:1320px;margin:0 auto;padding:1.5rem;color:#0f172a}.launch-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1.2rem}.launch-head h1{margin:0 0 .35rem;font-size:1.9rem;color:#1e3a8a}.launch-head p{margin:0;color:#64748b}.launch-link{background:#e2e8f0;color:#0f172a;text-decoration:none;border-radius:8px;padding:.72rem 1rem;font-weight:800}.launch-alert{border-radius:8px;padding:.85rem 1rem;margin-bottom:1rem;border:1px solid}.launch-alert.ok{background:#ecfdf5;color:#065f46;border-color:#bbf7d0}.launch-alert.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}.launch-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:1rem}.launch-panel{background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 1px 5px rgba(15,23,42,.06)}.launch-panel-head{padding:1rem 1.1rem;border-bottom:1px solid #e2e8f0}.launch-panel-head h2{margin:0;font-size:1rem}.launch-panel-head span{display:block;margin-top:.25rem;color:#64748b;font-size:.86rem}.launch-form{padding:1.1rem}.launch-row{display:grid;grid-template-columns:1fr 1fr;gap:.9rem}.launch-field{margin-bottom:.9rem}.launch-field label{display:block;margin-bottom:.35rem;color:#334155;font-size:.85rem;font-weight:700}.launch-field input[type=text],.launch-field textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.75rem;font-size:.94rem;color:#0f172a;background:#fff;box-sizing:border-box}.launch-field textarea{min-height:300px;resize:vertical;line-height:1.45}.launch-toggles{display:flex;flex-wrap:wrap;gap:.8rem;margin:.4rem 0 1rem}.launch-toggle{display:inline-flex;align-items:center;gap:.45rem;color:#334155;font-weight:700;font-size:.9rem}.launch-actions{display:flex;justify-content:flex-end;gap:.7rem;border-top:1px solid #e2e8f0;padding-top:1rem}.launch-btn{border:0;border-radius:8px;padding:.75rem 1rem;font-weight:800;cursor:pointer;background:#0f766e;color:#fff;text-decoration:none;display:inline-flex;align-items:center}.launch-btn.secondary{background:#e2e8f0;color:#0f172a}.launch-btn.blue{background:#1e3a8a}.launch-btn[disabled]{opacity:.55;cursor:not-allowed}.launch-stats{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:.75rem;padding:1.1rem}.launch-stat{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.85rem}.launch-stat strong{display:block;font-size:1.35rem;color:#1e3a8a}.launch-stat span{color:#64748b;font-size:.82rem;font-weight:700}.launch-campaign-actions{display:flex;justify-content:space-between;align-items:center;gap:.75rem;padding:0 1.1rem 1.1rem;border-bottom:1px solid #e2e8f0}.launch-warning{color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.75rem .85rem;font-size:.88rem;line-height:1.45}.launch-table-wrap,.launch-codes{padding:.9rem 1.1rem 1.1rem;overflow-x:auto}.launch-table{width:100%;border-collapse:collapse;font-size:.86rem}.launch-table th,.launch-table td{text-align:left;border-bottom:1px solid #e2e8f0;padding:.55rem .45rem;vertical-align:top}.launch-table th{color:#64748b;font-size:.76rem;text-transform:uppercase;letter-spacing:.04em}.launch-badge{display:inline-flex;border-radius:999px;padding:.2rem .5rem;font-size:.74rem;font-weight:800;background:#e2e8f0;color:#334155}.launch-badge.green{background:#dcfce7;color:#166534}.launch-codes{display:grid;gap:.55rem}.launch-code{display:grid;grid-template-columns:155px 1fr;gap:.6rem;align-items:start;font-size:.88rem}.launch-code code{color:#0f766e;font-weight:800;background:#ecfdf5;border:1px solid #bbf7d0;border-radius:6px;padding:.22rem .35rem;white-space:nowrap}@media(max-width:960px){.launch-head,.launch-campaign-actions{flex-direction:column}.launch-row,.launch-stats{grid-template-columns:1fr}.launch-actions{justify-content:flex-start;flex-wrap:wrap}}
</style>
<script>
window.sidebarUnifiedConfig = Object.assign({}, window.sidebarUnifiedConfig || {}, { currentPage: 'vendas_pre_contratos' });
</script>

<div class="launch-page" data-page="cliente-notificacoes-lancamento-v1">
    <div class="launch-head">
        <div>
            <h1>Lançamento do Portal do Cliente</h1>
            <p>Disparo em massa para eventos futuros de casamento e 15 anos, com criação automática de portal quando necessário.</p>
        </div>
        <a class="launch-link" href="cliente_notificacoes.php">Voltar aos modelos</a>
    </div>

    <?php if ($mensagem): ?><div class="launch-alert ok"><?= h($mensagem) ?></div><?php endif; ?>
    <?php if ($erro): ?><div class="launch-alert err"><?= h($erro) ?></div><?php endif; ?>

    <?php if ($modeloAtual): ?>
    <div class="launch-grid">
        <section class="launch-panel">
            <div class="launch-panel-head">
                <h2>Modelo da notificação</h2>
                <span>Esta é a mensagem usada no envio em massa do lançamento do portal.</span>
            </div>
            <form method="post" class="launch-form">
                <input type="hidden" name="acao" value="salvar_modelo">
                <div class="launch-row">
                    <div class="launch-field"><label for="nome">Nome da notificação</label><input type="text" id="nome" name="nome" value="<?= h($modeloAtual['nome']) ?>" required></div>
                    <div class="launch-field"><label for="gatilho">Quando dispara</label><input type="text" id="gatilho" name="gatilho" value="<?= h($modeloAtual['gatilho']) ?>"></div>
                </div>
                <div class="launch-field"><label for="descricao">Descrição interna</label><input type="text" id="descricao" name="descricao" value="<?= h($modeloAtual['descricao']) ?>"></div>
                <div class="launch-toggles">
                    <label class="launch-toggle"><input type="checkbox" name="ativo" value="1" <?= !empty($modeloAtual['ativo']) ? 'checked' : '' ?>> Ativa</label>
                    <label class="launch-toggle"><input type="checkbox" name="envio_automatico" value="1" <?= !empty($modeloAtual['envio_automatico']) ? 'checked' : '' ?>> Enviar automaticamente</label>
                    <label class="launch-toggle"><input type="checkbox" name="canal_email" value="1" <?= !empty($modeloAtual['canal_email']) ? 'checked' : '' ?>> E-mail</label>
                </div>
                <div class="launch-row">
                    <div class="launch-field"><label for="assunto">Assunto do e-mail</label><input type="text" id="assunto" name="assunto" value="<?= h($modeloAtual['assunto']) ?>" required></div>
                    <div class="launch-field"><label for="botao_texto">Texto do botão</label><input type="text" id="botao_texto" name="botao_texto" value="<?= h($modeloAtual['botao_texto']) ?>" required></div>
                </div>
                <div class="launch-field"><label for="mensagem_texto">Mensagem</label><textarea id="mensagem_texto" name="mensagem_texto" required><?= h($modeloAtual['mensagem_texto']) ?></textarea></div>
                <div class="launch-actions">
                    <a class="launch-btn secondary" href="cliente_notificacoes.php?modelo_chave=portal_cliente_lancamento&preview=1" target="_blank" rel="noopener">Ver prévia</a>
                    <button type="submit" class="launch-btn">Salvar notificação</button>
                </div>
            </form>
        </section>

        <section class="launch-panel">
            <div class="launch-panel-head">
                <h2>Disparo em massa</h2>
                <span>Consulta direta na ME Eventos: eventos futuros a partir de 30/05/2026, apenas casamento e 15 anos, sem envio anterior desta campanha.</span>
            </div>
            <div class="launch-stats">
                <div class="launch-stat"><strong><?= (int)$totalPublico ?></strong><span>clientes elegíveis</span></div>
                <div class="launch-stat"><strong><?= (int)$comPortal ?></strong><span>já com portal</span></div>
                <div class="launch-stat"><strong><?= (int)$semPortal ?></strong><span>portais a criar</span></div>
                <div class="launch-stat"><strong><?= (int)$emailsInvalidos ?></strong><span>e-mails inválidos</span></div>
            </div>
            <div class="launch-campaign-actions">
                <div class="launch-warning">A tela abre sem consultar a ME Eventos para não travar. Primeiro clique em consultar público; depois confira a lista e envie.</div>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;justify-content:flex-end;">
                    <a class="launch-btn secondary" href="cliente_notificacoes_lancamento.php?consultar_publico=1">Consultar público na ME</a>
                    <form method="post" onsubmit="return confirm('Enviar esta campanha para os clientes elegíveis agora?');">
                        <input type="hidden" name="acao" value="enviar_campanha_portal">
                        <button type="submit" class="launch-btn blue" <?= (!$publicoCampanhaConsultado || $totalPublico <= 0) ? 'disabled' : '' ?>>Enviar campanha</button>
                    </form>
                </div>
            </div>
            <div class="launch-table-wrap">
                <table class="launch-table">
                    <thead><tr><th>Cliente</th><th>Evento</th><th>Data</th><th>Portal</th></tr></thead>
                    <tbody>
                        <?php if (!$publicoCampanhaConsultado): ?>
                            <tr><td colspan="4">Clique em “Consultar público na ME” para carregar os clientes elegíveis.</td></tr>
                        <?php elseif (empty($publicoCampanha)): ?>
                            <tr><td colspan="4">Nenhum cliente elegível pendente para esta campanha.</td></tr>
                        <?php endif; ?>
                        <?php foreach (array_slice($publicoCampanha, 0, 80) as $item): ?>
                            <tr>
                                <td><strong><?= h($item['nome_completo'] ?: '-') ?></strong><br><span style="color:#64748b;"><?= h($item['email'] ?: '') ?></span></td>
                                <td><?= h(cliente_notificacoes_nome_evento($item)) ?></td>
                                <td><?= h(cliente_notificacoes_data_br((string)($item['data_evento'] ?? ''))) ?></td>
                                <td><span class="launch-badge <?= !empty($item['portal_url']) ? 'green' : '' ?>"><?= !empty($item['portal_url']) ? 'Criado' : 'Será criado' ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="launch-panel">
            <div class="launch-panel-head"><h2>Legenda dos códigos</h2><span>Use estes códigos no assunto ou na mensagem.</span></div>
            <div class="launch-codes">
                <?php foreach ($codigos as $codigo => $descricao): ?>
                    <div class="launch-code"><code><?= h($codigo) ?></code><span><?= h($descricao) ?></span></div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
    <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Lançamento do Portal do Cliente');
echo $conteudo;
endSidebar();
?>
