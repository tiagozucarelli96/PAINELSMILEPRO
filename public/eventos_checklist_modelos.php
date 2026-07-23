<?php
/**
 * Configuração dos modelos do checklist de planejamento.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/eventos_checklist_planejamento_helper.php';

if (empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=configuracoes');
    exit;
}

eventos_checklist_planejamento_ensure_schema($pdo);

function ecm_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ecm_redirect(string $message, bool $error = false, int $modelId = 0): void
{
    $url = 'index.php?page=eventos_checklist_modelos&' . ($error ? 'error=' : 'success=') . urlencode($message);
    if ($modelId > 0) {
        $url .= '&modelo_id=' . $modelId;
    }
    header('Location: ' . $url);
    exit;
}

$userId = eventos_checklist_planejamento_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'save_model') {
            $modelId = (int)($_POST['modelo_id'] ?? 0);
            $nome = trim((string)($_POST['nome'] ?? ''));
            $origem = (string)($_POST['origem'] ?? '');
            $tipo = trim((string)($_POST['tipo_evento_key'] ?? ''));
            $pacoteId = (int)($_POST['pacote_evento_id'] ?? 0);
            if ($nome === '' || !in_array($origem, ['tipo', 'pacote'], true)) {
                ecm_redirect('Informe o nome e a origem do modelo.', true, $modelId);
            }
            if (($origem === 'tipo' && $tipo === '') || ($origem === 'pacote' && $pacoteId <= 0)) {
                ecm_redirect('Selecione o tipo de evento ou pacote aplicável.', true, $modelId);
            }

            if ($modelId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE eventos_checklist_modelos
                    SET nome = :nome,
                        origem = :origem,
                        tipo_evento_key = :tipo,
                        pacote_evento_id = :pacote,
                        versao = versao + 1,
                        updated_at = NOW()
                    WHERE id = :id AND deleted_at IS NULL
                ");
                $stmt->execute([
                    ':id' => $modelId,
                    ':nome' => $nome,
                    ':origem' => $origem,
                    ':tipo' => $origem === 'tipo' ? $tipo : null,
                    ':pacote' => $origem === 'pacote' ? $pacoteId : null,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO eventos_checklist_modelos
                        (nome, origem, tipo_evento_key, pacote_evento_id, created_by)
                    VALUES
                        (:nome, :origem, :tipo, :pacote, :created_by)
                    RETURNING id
                ");
                $stmt->execute([
                    ':nome' => $nome,
                    ':origem' => $origem,
                    ':tipo' => $origem === 'tipo' ? $tipo : null,
                    ':pacote' => $origem === 'pacote' ? $pacoteId : null,
                    ':created_by' => $userId > 0 ? $userId : null,
                ]);
                $modelId = (int)$stmt->fetchColumn();
            }
            ecm_redirect('Modelo salvo com sucesso.', false, $modelId);
        }

        if ($action === 'toggle_model') {
            $modelId = (int)($_POST['modelo_id'] ?? 0);
            $ativo = (string)($_POST['ativo'] ?? '0') === '1';
            $stmt = $pdo->prepare("
                UPDATE eventos_checklist_modelos
                SET ativo = :ativo, versao = versao + 1, updated_at = NOW()
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmt->execute([':ativo' => $ativo ? 't' : 'f', ':id' => $modelId]);
            ecm_redirect($ativo ? 'Modelo ativado.' : 'Modelo desativado.', false, $modelId);
        }

        if ($action === 'save_task') {
            $modelId = (int)($_POST['modelo_id'] ?? 0);
            $taskId = (int)($_POST['tarefa_id'] ?? 0);
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $descricao = trim((string)($_POST['descricao'] ?? ''));
            $ordem = (int)($_POST['ordem'] ?? 0);
            $responsabilidade = (string)($_POST['responsabilidade'] ?? '');
            $responsavelUsuarioId = (int)($_POST['responsavel_usuario_id'] ?? 0);
            $responsavelSetor = trim((string)($_POST['responsavel_setor'] ?? ''));
            $regra = (string)($_POST['regra_vencimento'] ?? 'sem_data');
            $dias = max(0, (int)($_POST['dias'] ?? 0));
            $exigeValidacao = $responsabilidade === 'cliente' && !empty($_POST['exige_validacao']);
            $whatsapp = $responsabilidade === 'cliente'
                ? trim((string)($_POST['whatsapp_mensagem'] ?? ''))
                : '';

            $regras = ['sem_data', 'dia_evento', 'antes_evento', 'depois_evento', 'depois_cadastro', 'depois_insercao'];
            if ($modelId <= 0 || $titulo === '' || !in_array($responsabilidade, ['usuario', 'setor', 'cliente'], true) || !in_array($regra, $regras, true)) {
                ecm_redirect('Preencha os campos obrigatórios da tarefa.', true, $modelId);
            }
            if ($responsabilidade === 'usuario' && $responsavelUsuarioId <= 0) {
                ecm_redirect('Selecione o usuário responsável.', true, $modelId);
            }
            if ($responsabilidade === 'setor' && $responsavelSetor === '') {
                ecm_redirect('Selecione o setor responsável.', true, $modelId);
            }
            if ($responsabilidade === 'cliente' && $whatsapp === '') {
                $whatsapp = 'Olá, #NOME#. Você tem a tarefa "#TAREFA#" para realizar até hoje no evento "#EVENTO#".';
            }

            $params = [
                ':modelo_id' => $modelId,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':ordem' => $ordem,
                ':responsabilidade' => $responsabilidade,
                ':responsavel_usuario_id' => $responsabilidade === 'usuario' ? $responsavelUsuarioId : null,
                ':responsavel_setor' => $responsabilidade === 'setor' ? $responsavelSetor : null,
                ':visivel_cliente' => $responsabilidade === 'cliente' ? 't' : 'f',
                ':exige_validacao' => $exigeValidacao ? 't' : 'f',
                ':regra_vencimento' => $regra,
                ':dias' => in_array($regra, ['sem_data', 'dia_evento'], true) ? 0 : $dias,
                ':whatsapp_mensagem' => $whatsapp,
            ];

            if ($taskId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE eventos_checklist_modelo_tarefas
                    SET titulo = :titulo,
                        descricao = :descricao,
                        ordem = :ordem,
                        responsabilidade = :responsabilidade,
                        responsavel_usuario_id = :responsavel_usuario_id,
                        responsavel_setor = :responsavel_setor,
                        visivel_cliente = :visivel_cliente,
                        exige_validacao = :exige_validacao,
                        regra_vencimento = :regra_vencimento,
                        dias = :dias,
                        whatsapp_mensagem = :whatsapp_mensagem,
                        updated_at = NOW()
                    WHERE id = :id AND modelo_id = :modelo_id
                ");
                $params[':id'] = $taskId;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO eventos_checklist_modelo_tarefas (
                        modelo_id, titulo, descricao, ordem, responsabilidade,
                        responsavel_usuario_id, responsavel_setor, visivel_cliente,
                        exige_validacao, regra_vencimento, dias, whatsapp_mensagem
                    ) VALUES (
                        :modelo_id, :titulo, :descricao, :ordem, :responsabilidade,
                        :responsavel_usuario_id, :responsavel_setor, :visivel_cliente,
                        :exige_validacao, :regra_vencimento, :dias, :whatsapp_mensagem
                    )
                ");
            }
            $stmt->execute($params);
            $pdo->prepare("UPDATE eventos_checklist_modelos SET versao = versao + 1, updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $modelId]);
            ecm_redirect('Tarefa do modelo salva.', false, $modelId);
        }

        if ($action === 'toggle_task') {
            $modelId = (int)($_POST['modelo_id'] ?? 0);
            $taskId = (int)($_POST['tarefa_id'] ?? 0);
            $ativo = (string)($_POST['ativo'] ?? '0') === '1';
            $stmt = $pdo->prepare("
                UPDATE eventos_checklist_modelo_tarefas
                SET ativo = :ativo, updated_at = NOW()
                WHERE id = :id AND modelo_id = :modelo_id
            ");
            $stmt->execute([':ativo' => $ativo ? 't' : 'f', ':id' => $taskId, ':modelo_id' => $modelId]);
            $pdo->prepare("UPDATE eventos_checklist_modelos SET versao = versao + 1, updated_at = NOW() WHERE id = :id")
                ->execute([':id' => $modelId]);
            ecm_redirect($ativo ? 'Tarefa reativada no modelo.' : 'Tarefa desativada no modelo.', false, $modelId);
        }

        if ($action === 'save_config') {
            $portalAtivo = !empty($_POST['portal_cliente_ativo']);
            $whatsappAtivo = $portalAtivo && !empty($_POST['whatsapp_cliente_ativo']);
            $stmt = $pdo->prepare("
                UPDATE eventos_checklist_config
                SET portal_cliente_ativo = :portal,
                    whatsapp_cliente_ativo = :whatsapp,
                    whatsapp_hora = '09:00',
                    updated_by = :usuario,
                    updated_at = NOW()
                WHERE id = 1
            ");
            $stmt->execute([
                ':portal' => $portalAtivo ? 't' : 'f',
                ':whatsapp' => $whatsappAtivo ? 't' : 'f',
                ':usuario' => $userId > 0 ? $userId : null,
            ]);
            ecm_redirect('Configuração geral atualizada.');
        }
    } catch (Throwable $e) {
        error_log('eventos_checklist_modelos: ' . $e->getMessage());
        ecm_redirect('Não foi possível concluir a operação.', true, (int)($_POST['modelo_id'] ?? 0));
    }
}

$tipos = eventos_reuniao_tipos_evento_real_options($pdo, true);
$pacotes = [];
try {
    $pacotes = $pdo->query("
        SELECT id, nome
        FROM logistica_pacotes_evento
        WHERE deleted_at IS NULL
        ORDER BY LOWER(nome), id
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $pacotes = [];
}

$usuarios = $pdo->query("
    SELECT id, nome, COALESCE(NULLIF(TRIM(cargo), ''), 'Sem setor') AS cargo
    FROM usuarios
    WHERE COALESCE(ativo, TRUE) = TRUE
    ORDER BY LOWER(nome)
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$setores = [];
foreach ($usuarios as $usuario) {
    $cargo = trim((string)$usuario['cargo']);
    if ($cargo !== '' && $cargo !== 'Sem setor') {
        $setores[mb_strtolower($cargo, 'UTF-8')] = $cargo;
    }
}
natcasesort($setores);

$modelos = $pdo->query("
    SELECT m.*, p.nome AS pacote_nome,
           COUNT(t.id)::int AS tarefas_total,
           COUNT(t.id) FILTER (WHERE t.ativo = TRUE)::int AS tarefas_ativas
    FROM eventos_checklist_modelos m
    LEFT JOIN logistica_pacotes_evento p ON p.id = m.pacote_evento_id
    LEFT JOIN eventos_checklist_modelo_tarefas t ON t.modelo_id = m.id
    WHERE m.deleted_at IS NULL
    GROUP BY m.id, p.nome
    ORDER BY m.ativo DESC, LOWER(m.nome), m.id
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$modeloId = (int)($_GET['modelo_id'] ?? 0);
if ($modeloId <= 0 && $modelos) {
    $modeloId = (int)$modelos[0]['id'];
}
$modeloAtual = null;
foreach ($modelos as $modelo) {
    if ((int)$modelo['id'] === $modeloId) {
        $modeloAtual = $modelo;
        break;
    }
}

$tarefas = [];
if ($modeloAtual) {
    $stmt = $pdo->prepare("
        SELECT t.*, u.nome AS responsavel_nome
        FROM eventos_checklist_modelo_tarefas t
        LEFT JOIN usuarios u ON u.id = t.responsavel_usuario_id
        WHERE t.modelo_id = :modelo_id
        ORDER BY t.ativo DESC, t.ordem, t.id
    ");
    $stmt->execute([':modelo_id' => $modeloId]);
    $tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$editTaskId = (int)($_GET['tarefa_id'] ?? 0);
$editTask = null;
foreach ($tarefas as $tarefa) {
    if ((int)$tarefa['id'] === $editTaskId) {
        $editTask = $tarefa;
        break;
    }
}
$config = eventos_checklist_planejamento_config($pdo);

includeSidebar('Modelos de Checklist');
?>

<style>
.ecm-page{max-width:1480px;margin:0 auto;padding:1.5rem;background:#f8fafc}
.ecm-head{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;margin-bottom:1.25rem}
.ecm-head h1{margin:0;color:#1e3a8a;font-size:1.9rem}.ecm-head p{margin:.35rem 0;color:#64748b}
.ecm-layout{display:grid;grid-template-columns:330px minmax(0,1fr);gap:1.2rem;align-items:start}
.ecm-panel{background:#fff;border:1px solid #dbe3ef;border-radius:14px;padding:1rem;box-shadow:0 8px 24px rgba(15,23,42,.06)}
.ecm-panel h2,.ecm-panel h3{margin:0 0 .9rem;color:#1e3a8a}
.ecm-list{display:grid;gap:.65rem}.ecm-model{display:block;border:1px solid #e2e8f0;border-radius:11px;padding:.8rem;text-decoration:none;color:#334155}
.ecm-model.active{border-color:#2563eb;background:#eff6ff}.ecm-model strong{display:block;color:#0f172a}.ecm-muted{color:#64748b;font-size:.84rem}
.ecm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.85rem}.ecm-field.full{grid-column:1/-1}
.ecm-field label{display:block;font-size:.84rem;font-weight:700;color:#334155;margin-bottom:.3rem}
.ecm-field input,.ecm-field select,.ecm-field textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:9px;padding:.68rem;background:#fff;color:#0f172a}
.ecm-field textarea{min-height:88px;resize:vertical}.ecm-actions{display:flex;gap:.55rem;flex-wrap:wrap;margin-top:.9rem}
.ecm-btn{border:0;border-radius:9px;padding:.65rem .9rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
.ecm-btn.primary{background:#2563eb;color:#fff}.ecm-btn.secondary{background:#e2e8f0;color:#334155}.ecm-btn.danger{background:#fee2e2;color:#991b1b}
.ecm-alert{padding:.8rem 1rem;border-radius:10px;margin-bottom:1rem}.ecm-alert.ok{background:#ecfdf5;color:#166534}.ecm-alert.error{background:#fef2f2;color:#991b1b}
.ecm-task{border:1px solid #e2e8f0;border-radius:11px;padding:.85rem;margin-bottom:.65rem;display:flex;justify-content:space-between;gap:1rem;align-items:center}
.ecm-task.inactive{opacity:.55}.ecm-task-title{font-weight:800;color:#0f172a}.ecm-badge{display:inline-block;padding:.2rem .5rem;border-radius:999px;background:#e0e7ff;color:#3730a3;font-size:.75rem;font-weight:700;margin:.25rem .25rem 0 0}
.ecm-config{margin-bottom:1.2rem}.ecm-check{display:flex;gap:.5rem;align-items:center;margin:.45rem 0;color:#334155}.ecm-check input{width:auto}
@media(max-width:900px){.ecm-layout{grid-template-columns:1fr}.ecm-grid{grid-template-columns:1fr}.ecm-field.full{grid-column:auto}}
</style>

<div class="ecm-page">
    <div class="ecm-head">
        <div>
            <h1>✅ Modelos de Checklist</h1>
            <p>Planejamento por tipo de evento e pacote. Este módulo não altera os checklists operacionais.</p>
        </div>
        <a class="ecm-btn secondary" href="index.php?page=configuracoes">← Configurações</a>
    </div>

    <?php if (!empty($_GET['success'])): ?><div class="ecm-alert ok"><?= ecm_h($_GET['success']) ?></div><?php endif; ?>
    <?php if (!empty($_GET['error'])): ?><div class="ecm-alert error"><?= ecm_h($_GET['error']) ?></div><?php endif; ?>

    <div class="ecm-panel ecm-config">
        <h3>Liberação para clientes</h3>
        <form method="post">
            <input type="hidden" name="action" value="save_config">
            <label class="ecm-check"><input type="checkbox" name="portal_cliente_ativo" value="1" <?= !empty($config['portal_cliente_ativo']) ? 'checked' : '' ?>> Exibir a área de tarefas no portal do cliente</label>
            <label class="ecm-check"><input type="checkbox" name="whatsapp_cliente_ativo" value="1" <?= !empty($config['whatsapp_cliente_ativo']) ? 'checked' : '' ?>> Enviar um lembrete por WhatsApp às 9h no dia do vencimento</label>
            <div class="ecm-muted">O WhatsApp somente pode ser ativado junto com a área do cliente. Cada tarefa recebe uma única tentativa.</div>
            <div class="ecm-actions"><button class="ecm-btn primary" type="submit">Salvar liberação</button></div>
        </form>
    </div>

    <div class="ecm-layout">
        <aside>
            <div class="ecm-panel">
                <h2>Modelos</h2>
                <div class="ecm-list">
                    <?php foreach ($modelos as $modelo): ?>
                        <?php
                        $aplicacao = $modelo['origem'] === 'tipo'
                            ? ($tipos[$modelo['tipo_evento_key']] ?? $modelo['tipo_evento_key'])
                            : ($modelo['pacote_nome'] ?? 'Pacote');
                        ?>
                        <a class="ecm-model <?= (int)$modelo['id'] === $modeloId ? 'active' : '' ?>" href="index.php?page=eventos_checklist_modelos&modelo_id=<?= (int)$modelo['id'] ?>">
                            <strong><?= ecm_h($modelo['nome']) ?></strong>
                            <span class="ecm-muted"><?= ecm_h(ucfirst($modelo['origem'])) ?>: <?= ecm_h($aplicacao) ?> • <?= (int)$modelo['tarefas_ativas'] ?> tarefa(s) • v<?= (int)$modelo['versao'] ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ecm-panel" style="margin-top:1rem">
                <h3>Novo modelo</h3>
                <form method="post">
                    <input type="hidden" name="action" value="save_model">
                    <div class="ecm-field"><label>Nome</label><input name="nome" required placeholder="Ex.: Casamento padrão"></div>
                    <div class="ecm-field" style="margin-top:.65rem"><label>Origem</label>
                        <select name="origem" required>
                            <option value="tipo">Tipo de evento</option>
                            <option value="pacote">Pacote</option>
                        </select>
                    </div>
                    <div class="ecm-field" style="margin-top:.65rem"><label>Tipo de evento</label>
                        <select name="tipo_evento_key"><option value="">Selecione...</option><?php foreach ($tipos as $key => $label): ?><option value="<?= ecm_h($key) ?>"><?= ecm_h($label) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="ecm-field" style="margin-top:.65rem"><label>Pacote</label>
                        <select name="pacote_evento_id"><option value="">Selecione...</option><?php foreach ($pacotes as $pacote): ?><option value="<?= (int)$pacote['id'] ?>"><?= ecm_h($pacote['nome']) ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="ecm-actions"><button class="ecm-btn primary" type="submit">Criar modelo</button></div>
                </form>
            </div>
        </aside>

        <main>
            <?php if (!$modeloAtual): ?>
                <div class="ecm-panel"><h2>Crie o primeiro modelo</h2><p class="ecm-muted">Depois adicione as tarefas que serão copiadas para os eventos.</p></div>
            <?php else: ?>
                <div class="ecm-panel">
                    <h2>Modelo: <?= ecm_h($modeloAtual['nome']) ?></h2>
                    <form method="post">
                        <input type="hidden" name="action" value="save_model">
                        <input type="hidden" name="modelo_id" value="<?= $modeloId ?>">
                        <div class="ecm-grid">
                            <div class="ecm-field full"><label>Nome</label><input name="nome" value="<?= ecm_h($modeloAtual['nome']) ?>" required></div>
                            <div class="ecm-field"><label>Origem</label>
                                <select name="origem">
                                    <option value="tipo" <?= $modeloAtual['origem'] === 'tipo' ? 'selected' : '' ?>>Tipo de evento</option>
                                    <option value="pacote" <?= $modeloAtual['origem'] === 'pacote' ? 'selected' : '' ?>>Pacote</option>
                                </select>
                            </div>
                            <div class="ecm-field"><label>Tipo</label>
                                <select name="tipo_evento_key"><option value="">Selecione...</option><?php foreach ($tipos as $key => $label): ?><option value="<?= ecm_h($key) ?>" <?= $modeloAtual['tipo_evento_key'] === $key ? 'selected' : '' ?>><?= ecm_h($label) ?></option><?php endforeach; ?></select>
                            </div>
                            <div class="ecm-field"><label>Pacote</label>
                                <select name="pacote_evento_id"><option value="">Selecione...</option><?php foreach ($pacotes as $pacote): ?><option value="<?= (int)$pacote['id'] ?>" <?= (int)$modeloAtual['pacote_evento_id'] === (int)$pacote['id'] ? 'selected' : '' ?>><?= ecm_h($pacote['nome']) ?></option><?php endforeach; ?></select>
                            </div>
                        </div>
                        <div class="ecm-actions">
                            <button class="ecm-btn primary" type="submit">Salvar modelo</button>
                        </div>
                    </form>
                    <form method="post" class="ecm-actions">
                        <input type="hidden" name="action" value="toggle_model"><input type="hidden" name="modelo_id" value="<?= $modeloId ?>">
                        <input type="hidden" name="ativo" value="<?= !empty($modeloAtual['ativo']) ? '0' : '1' ?>">
                        <button class="ecm-btn <?= !empty($modeloAtual['ativo']) ? 'danger' : 'secondary' ?>" type="submit"><?= !empty($modeloAtual['ativo']) ? 'Desativar modelo' : 'Reativar modelo' ?></button>
                    </form>
                </div>

                <div class="ecm-panel" style="margin-top:1rem">
                    <h2><?= $editTask ? 'Editar tarefa' : 'Nova tarefa' ?></h2>
                    <form method="post">
                        <input type="hidden" name="action" value="save_task"><input type="hidden" name="modelo_id" value="<?= $modeloId ?>">
                        <input type="hidden" name="tarefa_id" value="<?= (int)($editTask['id'] ?? 0) ?>">
                        <div class="ecm-grid">
                            <div class="ecm-field full"><label>Título</label><input name="titulo" required value="<?= ecm_h($editTask['titulo'] ?? '') ?>"></div>
                            <div class="ecm-field full"><label>Descrição</label><textarea name="descricao"><?= ecm_h($editTask['descricao'] ?? '') ?></textarea></div>
                            <div class="ecm-field"><label>Ordem</label><input type="number" name="ordem" value="<?= (int)($editTask['ordem'] ?? (count($tarefas) + 1) * 10) ?>"></div>
                            <div class="ecm-field"><label>Responsabilidade</label>
                                <select name="responsabilidade" required>
                                    <?php foreach (['usuario' => 'Usuário', 'setor' => 'Setor', 'cliente' => 'Cliente'] as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= ($editTask['responsabilidade'] ?? 'usuario') === $key ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ecm-field"><label>Usuário responsável</label>
                                <select name="responsavel_usuario_id"><option value="">Selecione...</option><?php foreach ($usuarios as $usuario): ?><option value="<?= (int)$usuario['id'] ?>" <?= (int)($editTask['responsavel_usuario_id'] ?? 0) === (int)$usuario['id'] ? 'selected' : '' ?>><?= ecm_h($usuario['nome'] . ' — ' . $usuario['cargo']) ?></option><?php endforeach; ?></select>
                            </div>
                            <div class="ecm-field"><label>Setor responsável</label>
                                <select name="responsavel_setor"><option value="">Selecione...</option><?php foreach ($setores as $setor): ?><option value="<?= ecm_h($setor) ?>" <?= ($editTask['responsavel_setor'] ?? '') === $setor ? 'selected' : '' ?>><?= ecm_h($setor) ?></option><?php endforeach; ?></select>
                            </div>
                            <div class="ecm-field"><label>Regra de vencimento</label>
                                <select name="regra_vencimento">
                                    <?php foreach ([
                                        'sem_data' => 'Data não definida', 'dia_evento' => 'No dia do evento',
                                        'antes_evento' => 'Dias antes do evento', 'depois_evento' => 'Dias depois do evento',
                                        'depois_cadastro' => 'Dias depois do cadastro', 'depois_insercao' => 'Dias depois da inserção'
                                    ] as $key => $label): ?><option value="<?= $key ?>" <?= ($editTask['regra_vencimento'] ?? 'sem_data') === $key ? 'selected' : '' ?>><?= ecm_h($label) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ecm-field"><label>Quantidade de dias</label><input type="number" min="0" name="dias" value="<?= (int)($editTask['dias'] ?? 0) ?>"></div>
                            <div class="ecm-field full"><label>Mensagem WhatsApp (somente cliente)</label><textarea name="whatsapp_mensagem" placeholder="Use #NOME#, #TAREFA# e #EVENTO#"><?= ecm_h($editTask['whatsapp_mensagem'] ?? '') ?></textarea></div>
                            <div class="ecm-field full"><label class="ecm-check"><input type="checkbox" name="exige_validacao" value="1" <?= !empty($editTask['exige_validacao']) ? 'checked' : '' ?>> Exigir validação interna quando o cliente marcar como concluída</label></div>
                        </div>
                        <div class="ecm-actions">
                            <button class="ecm-btn primary" type="submit"><?= $editTask ? 'Salvar alterações' : 'Adicionar tarefa' ?></button>
                            <?php if ($editTask): ?><a class="ecm-btn secondary" href="index.php?page=eventos_checklist_modelos&modelo_id=<?= $modeloId ?>">Cancelar edição</a><?php endif; ?>
                        </div>
                    </form>
                </div>

                <div class="ecm-panel" style="margin-top:1rem">
                    <h2>Tarefas do modelo</h2>
                    <?php if (!$tarefas): ?><p class="ecm-muted">Nenhuma tarefa cadastrada.</p><?php endif; ?>
                    <?php foreach ($tarefas as $tarefa): ?>
                        <div class="ecm-task <?= empty($tarefa['ativo']) ? 'inactive' : '' ?>">
                            <div>
                                <div class="ecm-task-title"><?= ecm_h($tarefa['titulo']) ?></div>
                                <span class="ecm-badge"><?= ecm_h(ucfirst($tarefa['responsabilidade'])) ?></span>
                                <span class="ecm-badge"><?= ecm_h($tarefa['regra_vencimento']) ?><?= (int)$tarefa['dias'] > 0 ? ' • ' . (int)$tarefa['dias'] . ' dias' : '' ?></span>
                                <?php if ($tarefa['responsabilidade'] === 'usuario'): ?><div class="ecm-muted"><?= ecm_h($tarefa['responsavel_nome'] ?? 'Sem responsável') ?></div><?php endif; ?>
                                <?php if ($tarefa['responsabilidade'] === 'setor'): ?><div class="ecm-muted"><?= ecm_h($tarefa['responsavel_setor']) ?></div><?php endif; ?>
                            </div>
                            <div class="ecm-actions">
                                <a class="ecm-btn secondary" href="index.php?page=eventos_checklist_modelos&modelo_id=<?= $modeloId ?>&tarefa_id=<?= (int)$tarefa['id'] ?>">Editar</a>
                                <form method="post">
                                    <input type="hidden" name="action" value="toggle_task"><input type="hidden" name="modelo_id" value="<?= $modeloId ?>"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>">
                                    <input type="hidden" name="ativo" value="<?= !empty($tarefa['ativo']) ? '0' : '1' ?>">
                                    <button class="ecm-btn <?= !empty($tarefa['ativo']) ? 'danger' : 'secondary' ?>" type="submit"><?= !empty($tarefa['ativo']) ? 'Desativar' : 'Reativar' ?></button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<?php endSidebar(); ?>
