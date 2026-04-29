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
require_once __DIR__ . '/administrativo_avisos_helper.php';
require_once __DIR__ . '/upload_magalu.php';

$pdo = $GLOBALS['pdo'];
adminAvisosEnsureSchema($pdo);

$erro = '';
$sucesso = '';
$usuariosAtivos = adminAvisosBuscarUsuariosAtivos($pdo);

$formData = [
    'assunto' => trim((string)($_POST['assunto'] ?? '')),
    'conteudo_html' => trim((string)($_POST['conteudo_html'] ?? '')),
    'modo_destino' => (string)($_POST['modo_destino'] ?? 'selecionados'),
    'destinatarios' => array_map('intval', (array)($_POST['destinatarios'] ?? [])),
    'expira_em' => trim((string)($_POST['expira_em'] ?? '')),
    'visualizacao_unica' => isset($_POST['visualizacao_unica']) ? adminAvisosBoolValue($_POST['visualizacao_unica']) : false,
];

if (!in_array($formData['modo_destino'], ['todos', 'selecionados'], true)) {
    $formData['modo_destino'] = 'selecionados';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'criar_aviso') {
        if ($formData['assunto'] === '') {
            $erro = 'Informe o assunto do aviso.';
        } elseif (trim(strip_tags($formData['conteudo_html'])) === '') {
            $erro = 'Informe o conteúdo do aviso.';
        } elseif ($formData['modo_destino'] === 'selecionados' && empty(adminAvisosNormalizarIds($formData['destinatarios']))) {
            $erro = 'Selecione pelo menos um destinatário.';
        } else {
            $expiraEm = null;
            if ($formData['expira_em'] !== '') {
                try {
                    $expiraEm = (new DateTimeImmutable($formData['expira_em']))->format('Y-m-d H:i:sP');
                } catch (Throwable $e) {
                    $erro = 'Data de expiração inválida.';
                }
            }

            if ($erro === '') {
                adminAvisosCriar($pdo, [
                    'assunto' => $formData['assunto'],
                    'conteudo_html' => $formData['conteudo_html'],
                    'modo_destino' => $formData['modo_destino'],
                    'destinatarios' => $formData['destinatarios'],
                    'expira_em' => $expiraEm,
                    'visualizacao_unica' => $formData['visualizacao_unica'],
                    'criado_por_usuario_id' => adminAvisosUsuarioLogadoId(),
                ]);

                $sucesso = 'Aviso enviado com sucesso.';
                $formData = [
                    'assunto' => '',
                    'conteudo_html' => '',
                    'modo_destino' => 'selecionados',
                    'destinatarios' => [],
                    'expira_em' => '',
                    'visualizacao_unica' => false,
                ];
            }
        }
    } elseif ($action === 'excluir_aviso') {
        $avisoId = (int)($_POST['aviso_id'] ?? 0);
        if ($avisoId > 0) {
            try {
                $aviso = adminAvisosBuscarPorId($pdo, $avisoId);
                if (!$aviso) {
                    throw new RuntimeException('Aviso não encontrado.');
                }

                $storageKeys = adminAvisosExtrairStorageKeys((string)($aviso['conteudo_html'] ?? ''));
                if (!empty($storageKeys)) {
                    $uploader = new MagaluUpload();
                    foreach ($storageKeys as $storageKey) {
                        if (!$uploader->delete($storageKey)) {
                            throw new RuntimeException('Não foi possível excluir uma das imagens do aviso no storage.');
                        }
                    }
                }

                adminAvisosExcluir($pdo, $avisoId);
                $sucesso = 'Aviso excluído com sucesso.';
            } catch (Throwable $e) {
                $erro = $e->getMessage();
            }
        }
    }
}

$historico = adminAvisosBuscarHistorico($pdo, 40);
$historicoIds = array_map(static function ($aviso) {
    return (int)($aviso['id'] ?? 0);
}, $historico);
$destinatariosPorAviso = adminAvisosBuscarDestinatariosPorAviso($pdo, $historicoIds);
$visualizacoesPorAviso = adminAvisosBuscarVisualizacoesPorAviso($pdo, $historicoIds);

ob_start();
?>

<style>
.avisos-admin-page {
    max-width: 1320px;
    margin: 0 auto;
    padding: 1.5rem;
}

.avisos-admin-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.avisos-admin-header h1 {
    margin: 0 0 0.45rem;
    color: #1e3a8a;
    font-size: 2rem;
}

.avisos-admin-header p {
    margin: 0;
    color: #64748b;
    max-width: 760px;
}

.avisos-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
}

.avisos-btn {
    border: none;
    border-radius: 12px;
    padding: 0.9rem 1.15rem;
    font-weight: 700;
    cursor: pointer;
    font-size: 0.95rem;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.avisos-btn:hover {
    transform: translateY(-1px);
}

.avisos-btn-primary {
    background: linear-gradient(135deg, #1d4ed8, #2563eb);
    color: #fff;
    box-shadow: 0 14px 24px rgba(37, 99, 235, 0.18);
}

.avisos-btn-secondary {
    background: #fff;
    color: #0f172a;
    border: 1px solid #cbd5e1;
}

.avisos-btn-danger {
    background: #fee2e2;
    color: #991b1b;
}

.avisos-feedback {
    margin-bottom: 1rem;
    padding: 0.95rem 1rem;
    border-radius: 12px;
    font-weight: 500;
}

.avisos-feedback.error {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}

.avisos-feedback.success {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}

.avisos-main-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.07);
    overflow: hidden;
}

.avisos-main-top {
    padding: 1.4rem 1.5rem 1rem;
    border-bottom: 1px solid #eef2f7;
    background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
}

.avisos-main-top h2 {
    margin: 0 0 0.4rem;
    color: #0f172a;
    font-size: 1.18rem;
}

.avisos-main-top p {
    margin: 0;
    color: #64748b;
}

.avisos-form {
    padding: 1.5rem;
    display: grid;
    gap: 1.25rem;
}

.avisos-grid-two {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.85fr);
    gap: 1.25rem;
    align-items: start;
}

.avisos-form-group {
    display: grid;
    gap: 0.45rem;
}

.avisos-form-group label {
    font-weight: 700;
    color: #334155;
}

.avisos-form-group input[type="text"],
.avisos-form-group input[type="datetime-local"],
.avisos-form-group textarea {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    padding: 0.85rem 0.95rem;
    font-size: 0.96rem;
}

.avisos-form-help {
    color: #64748b;
    font-size: 0.82rem;
}

.avisos-inline-options {
    display: flex;
    flex-wrap: wrap;
    gap: 0.85rem;
}

.avisos-choice {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #334155;
    font-size: 0.94rem;
}

.avisos-side-panel {
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    background: #f8fafc;
    padding: 1rem;
    display: grid;
    gap: 1rem;
}

.avisos-side-panel h3 {
    margin: 0;
    font-size: 1rem;
    color: #0f172a;
}

.avisos-destinatarios {
    max-height: 340px;
    overflow: auto;
    border: 1px solid #dbe3ee;
    border-radius: 12px;
    padding: 0.85rem;
    display: grid;
    gap: 0.55rem;
    background: #fff;
}

.avisos-destinatario-item {
    display: flex;
    align-items: flex-start;
    gap: 0.65rem;
    font-size: 0.92rem;
}

.avisos-destinatario-item small {
    display: block;
    color: #64748b;
}

.avisos-empty {
    padding: 1.2rem;
    text-align: center;
    color: #64748b;
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
    border-radius: 12px;
}

.avisos-main-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding-top: 0.25rem;
}

.avisos-history-modal {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    z-index: 1600;
}

.avisos-history-modal.open {
    display: flex;
}

.avisos-history-modal-card {
    width: min(1180px, 100%);
    max-height: min(88vh, 920px);
    overflow: auto;
    background: #fff;
    border-radius: 20px;
    box-shadow: 0 24px 56px rgba(15, 23, 42, 0.24);
}

.avisos-history-modal-header {
    position: sticky;
    top: 0;
    background: #fff;
    z-index: 2;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    padding: 1.2rem 1.3rem 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.avisos-history-modal-header h2 {
    margin: 0 0 0.35rem;
    color: #0f172a;
    font-size: 1.15rem;
}

.avisos-history-modal-header p {
    margin: 0;
    color: #64748b;
}

.avisos-modal-close {
    border: none;
    background: transparent;
    font-size: 1.9rem;
    line-height: 1;
    color: #475569;
    cursor: pointer;
}

.avisos-history-modal-body {
    padding: 1.25rem;
}

.avisos-history-list {
    display: grid;
    gap: 1rem;
}

.avisos-history-item {
    border: 1px solid #e5e7eb;
    border-radius: 16px;
    padding: 1rem;
    background: #fff;
}

.avisos-history-top {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.avisos-history-top h3 {
    margin: 0 0 0.3rem;
    color: #0f172a;
    font-size: 1rem;
}

.avisos-history-top p {
    margin: 0;
    color: #64748b;
    font-size: 0.88rem;
}

.avisos-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-bottom: 0.75rem;
}

.avisos-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.32rem 0.6rem;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 700;
    background: #eff6ff;
    color: #1d4ed8;
}

.avisos-badge.warn {
    background: #fff7ed;
    color: #c2410c;
}

.avisos-badge.neutral {
    background: #f1f5f9;
    color: #475569;
}

.avisos-history-resumo {
    color: #334155;
    line-height: 1.5;
    margin-bottom: 0.75rem;
}

.avisos-history-columns {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.avisos-mini-panel {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 0.85rem;
}

.avisos-mini-panel h4 {
    margin: 0 0 0.65rem;
    font-size: 0.9rem;
    color: #0f172a;
}

.avisos-mini-list {
    display: grid;
    gap: 0.5rem;
    max-height: 220px;
    overflow: auto;
}

.avisos-mini-item {
    font-size: 0.86rem;
    color: #334155;
    padding-bottom: 0.5rem;
    border-bottom: 1px dashed #dbe3ee;
}

.avisos-mini-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

@media (max-width: 1040px) {
    .avisos-admin-header,
    .avisos-history-top {
        flex-direction: column;
    }

    .avisos-grid-two,
    .avisos-history-columns {
        grid-template-columns: 1fr;
    }

    .avisos-main-actions {
        justify-content: stretch;
    }

    .avisos-main-actions .avisos-btn,
    .avisos-header-actions .avisos-btn {
        width: 100%;
    }
}
</style>

<div class="avisos-admin-page">
    <div class="avisos-admin-header">
        <div>
            <h1>📣 Enviar Avisos</h1>
            <p>Cadastre avisos da dashboard com assunto curto, conteúdo completo em modal, escolha de público, prazo de expiração e leitura única.</p>
        </div>
        <div class="avisos-header-actions">
            <button type="button" class="avisos-btn avisos-btn-secondary" onclick="abrirHistoricoAvisos()">Histórico e visualizações</button>
        </div>
    </div>

    <?php if ($erro !== ''): ?>
        <div class="avisos-feedback error"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    <?php if ($sucesso !== ''): ?>
        <div class="avisos-feedback success"><?= htmlspecialchars($sucesso) ?></div>
    <?php endif; ?>

    <section class="avisos-main-card">
        <div class="avisos-main-top">
            <h2>Novo aviso</h2>
            <p>O assunto aparece como faixa curta na dashboard. O conteúdo abaixo abre completo no modal do usuário.</p>
        </div>

        <form method="post" class="avisos-form" id="avisos-form">
            <input type="hidden" name="action" value="criar_aviso">

            <div class="avisos-form-group">
                <label for="aviso-assunto">Assunto da faixa</label>
                <input type="text" id="aviso-assunto" name="assunto" maxlength="180" value="<?= htmlspecialchars($formData['assunto']) ?>" placeholder="Ex.: Atualização importante do setor administrativo" required>
                <div class="avisos-form-help">Use um texto direto. Esse é o único conteúdo mostrado na faixa da dashboard.</div>
            </div>

            <div class="avisos-grid-two">
                <div class="avisos-form-group">
                    <label for="aviso-conteudo">Conteúdo completo do aviso</label>
                    <textarea id="aviso-conteudo" name="conteudo_html"><?= htmlspecialchars($formData['conteudo_html']) ?></textarea>
                    <div class="avisos-form-help">Este editor suporta links, tabelas e envio de imagens, no mesmo padrão rico usado em outras áreas do sistema.</div>
                </div>

                <aside class="avisos-side-panel">
                    <div class="avisos-form-group">
                        <label>Quem vai ver</label>
                        <div class="avisos-inline-options">
                            <label class="avisos-choice">
                                <input type="radio" name="modo_destino" value="selecionados" <?= $formData['modo_destino'] === 'selecionados' ? 'checked' : '' ?>>
                                <span>Usuários selecionados</span>
                            </label>
                            <label class="avisos-choice">
                                <input type="radio" name="modo_destino" value="todos" <?= $formData['modo_destino'] === 'todos' ? 'checked' : '' ?>>
                                <span>Todos os usuários ativos</span>
                            </label>
                        </div>
                    </div>

                    <div class="avisos-form-group" id="aviso-destinatarios-wrapper">
                        <label>Destinatários</label>
                        <div class="avisos-destinatarios">
                            <?php foreach ($usuariosAtivos as $usuario): ?>
                                <?php $checked = in_array((int)$usuario['id'], $formData['destinatarios'], true); ?>
                                <label class="avisos-destinatario-item">
                                    <input type="checkbox" name="destinatarios[]" value="<?= (int)$usuario['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                                    <span>
                                        <?= htmlspecialchars($usuario['nome']) ?>
                                        <small><?= htmlspecialchars((string)($usuario['email'] ?? 'Sem e-mail')) ?></small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="avisos-form-group">
                        <label for="aviso-expira-em">Prazo de expiração</label>
                        <input type="datetime-local" id="aviso-expira-em" name="expira_em" value="<?= htmlspecialchars($formData['expira_em']) ?>">
                        <div class="avisos-form-help">Se deixar em branco, o aviso só sai quando for desativado manualmente ou quando a leitura única for consumida.</div>
                    </div>

                    <div class="avisos-form-group">
                        <label class="avisos-choice">
                            <input type="checkbox" name="visualizacao_unica" value="1" <?= $formData['visualizacao_unica'] ? 'checked' : '' ?>>
                            <span>Visualização única por usuário</span>
                        </label>
                        <div class="avisos-form-help">Ao abrir o modal, o usuário consome o aviso e ele deixa de aparecer na dashboard dele.</div>
                    </div>
                </aside>
            </div>

            <div class="avisos-main-actions">
                <button type="submit" class="avisos-btn avisos-btn-primary">Publicar aviso</button>
            </div>
        </form>
    </section>
</div>

<div id="avisosHistoricoModal" class="avisos-history-modal" aria-hidden="true">
    <div class="avisos-history-modal-card">
        <div class="avisos-history-modal-header">
            <div>
                <h2>Histórico e visualizações</h2>
                <p>Veja cada aviso publicado, seus destinatários e o registro de quem visualizou com dia e hora.</p>
            </div>
            <button type="button" class="avisos-modal-close" onclick="fecharHistoricoAvisos()" aria-label="Fechar">&times;</button>
        </div>
        <div class="avisos-history-modal-body">
            <?php if (empty($historico)): ?>
                <div class="avisos-empty">Nenhum aviso cadastrado até agora.</div>
            <?php else: ?>
                <div class="avisos-history-list">
                    <?php foreach ($historico as $aviso): ?>
                        <?php
                        $avisoId = (int)$aviso['id'];
                        $destinatarios = $destinatariosPorAviso[$avisoId] ?? [];
                        $visualizacoes = $visualizacoesPorAviso[$avisoId] ?? [];
                        $totalDestinatarios = (int)($aviso['total_destinatarios'] ?? 0);
                        $totalVisualizados = (int)($aviso['total_visualizados'] ?? 0);
                        $expirado = !empty($aviso['expira_em']) && strtotime((string)$aviso['expira_em']) <= time();
                        ?>
                        <article class="avisos-history-item">
                            <div class="avisos-history-top">
                                <div>
                                    <h3><?= htmlspecialchars($aviso['assunto']) ?></h3>
                                    <p>
                                        Criado em <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$aviso['criado_em']))) ?>
                                        <?php if (!empty($aviso['criador_nome'])): ?>
                                            por <?= htmlspecialchars((string)$aviso['criador_nome']) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <?php if (adminAvisosBoolValue($aviso['ativo'] ?? false) && !$expirado): ?>
                                    <form method="post" onsubmit="return confirm('Excluir este aviso? Esta ação também remove as imagens dele do storage.');">
                                        <input type="hidden" name="action" value="excluir_aviso">
                                        <input type="hidden" name="aviso_id" value="<?= $avisoId ?>">
                                        <button type="submit" class="avisos-btn avisos-btn-danger">Excluir</button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <div class="avisos-badges">
                                <span class="avisos-badge"><?= $aviso['modo_destino'] === 'todos' ? 'Todos os usuários' : 'Usuários selecionados' ?></span>
                                <span class="avisos-badge neutral"><?= $totalVisualizados ?>/<?= $totalDestinatarios ?> visualizaram</span>
                                <?php if (adminAvisosBoolValue($aviso['visualizacao_unica'] ?? false)): ?>
                                    <span class="avisos-badge warn">Visualização única</span>
                                <?php endif; ?>
                                <?php if (!empty($aviso['expira_em'])): ?>
                                    <span class="avisos-badge <?= $expirado ? 'warn' : 'neutral' ?>">
                                        <?= $expirado ? 'Expirado em ' : 'Expira em ' ?><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$aviso['expira_em']))) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!adminAvisosBoolValue($aviso['ativo'] ?? false)): ?>
                                    <span class="avisos-badge warn">Desativado</span>
                                <?php endif; ?>
                            </div>

                            <div class="avisos-history-resumo"><?= htmlspecialchars(adminAvisosResumoHtml((string)$aviso['conteudo_html'])) ?></div>

                            <div class="avisos-history-columns">
                                <div class="avisos-mini-panel">
                                    <h4>Destinatários</h4>
                                    <?php if ($aviso['modo_destino'] === 'todos'): ?>
                                        <div class="avisos-empty">Este aviso foi liberado para todos os usuários ativos.</div>
                                    <?php elseif (empty($destinatarios)): ?>
                                        <div class="avisos-empty">Nenhum destinatário associado.</div>
                                    <?php else: ?>
                                        <div class="avisos-mini-list">
                                            <?php foreach ($destinatarios as $destinatario): ?>
                                                <div class="avisos-mini-item">
                                                    <strong><?= htmlspecialchars($destinatario['nome']) ?></strong><br>
                                                    <span><?= htmlspecialchars((string)$destinatario['email']) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="avisos-mini-panel">
                                    <h4>Quem visualizou</h4>
                                    <?php if (empty($visualizacoes)): ?>
                                        <div class="avisos-empty">Ainda não há visualizações registradas.</div>
                                    <?php else: ?>
                                        <div class="avisos-mini-list">
                                            <?php foreach ($visualizacoes as $visualizacao): ?>
                                                <div class="avisos-mini-item">
                                                    <strong><?= htmlspecialchars($visualizacao['nome']) ?></strong><br>
                                                    <span>Primeiro acesso: <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$visualizacao['visualizado_em']))) ?></span><br>
                                                    <?php if ((int)($visualizacao['total_visualizacoes'] ?? 1) > 1): ?>
                                                        <span>Último acesso: <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$visualizacao['ultima_visualizacao_em']))) ?> · <?= (int)$visualizacao['total_visualizacoes'] ?>x</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
let avisoTinyLoaded = false;

function toggleAvisoDestinatarios() {
    const wrapper = document.getElementById('aviso-destinatarios-wrapper');
    const modoSelecionados = document.querySelector('input[name="modo_destino"][value="selecionados"]');
    if (!wrapper || !modoSelecionados) return;
    wrapper.style.display = modoSelecionados.checked ? 'grid' : 'none';
}

function abrirHistoricoAvisos() {
    const modal = document.getElementById('avisosHistoricoModal');
    if (!modal) return;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
}

function fecharHistoricoAvisos() {
    const modal = document.getElementById('avisosHistoricoModal');
    if (!modal) return;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
}

function loadAvisoTiny() {
    if (typeof tinymce !== 'undefined') {
        initAvisoTiny();
        return;
    }
    if (avisoTinyLoaded) return;
    avisoTinyLoaded = true;
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js';
    script.async = true;
    script.onload = initAvisoTiny;
    document.head.appendChild(script);
}

function initAvisoTiny() {
    const textarea = document.getElementById('aviso-conteudo');
    if (!textarea || typeof tinymce === 'undefined') return;
    if (tinymce.get('aviso-conteudo')) return;

    tinymce.init({
        selector: '#aviso-conteudo',
        base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
        suffix: '.min',
        plugins: 'lists link image table code',
        toolbar: 'undo redo | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright justify | bullist numlist outdent indent | link image table | removeformat',
        menubar: false,
        height: 420,
        content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; }',
        paste_data_images: true,
        automatic_uploads: true,
        images_upload_handler: function(blobInfo) {
            return new Promise(function(resolve, reject) {
                const xhr = new XMLHttpRequest();
                const formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());
                xhr.open('POST', 'index.php?page=administrativo_avisos_upload');
                xhr.onload = function() {
                    if (xhr.status < 200 || xhr.status >= 300) {
                        reject('Upload falhou: ' + xhr.status);
                        return;
                    }
                    try {
                        const payload = JSON.parse(xhr.responseText);
                        if (payload.location) {
                            resolve(payload.location);
                        } else {
                            reject(payload.error || 'Resposta inválida');
                        }
                    } catch (error) {
                        reject('Resposta inválida');
                    }
                };
                xhr.onerror = function() {
                    reject('Erro de rede');
                };
                xhr.send(formData);
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="modo_destino"]').forEach(function(input) {
        input.addEventListener('change', toggleAvisoDestinatarios);
    });
    toggleAvisoDestinatarios();
    loadAvisoTiny();

    document.addEventListener('click', function(event) {
        const modal = document.getElementById('avisosHistoricoModal');
        if (modal && event.target === modal) {
            fecharHistoricoAvisos();
        }
    });
});
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Administrativo');
echo $conteudo;
endSidebar();
