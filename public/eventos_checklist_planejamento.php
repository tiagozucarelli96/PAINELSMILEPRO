<?php
/**
 * Linha do tempo do checklist de planejamento de um evento.
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_checklist_planejamento_helper.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

eventos_checklist_planejamento_ensure_schema($pdo);

$eventoId = (int)($_GET['evento_id'] ?? $_POST['evento_id'] ?? 0);
$userId = eventos_checklist_planejamento_user_id();
$isSuperadmin = eventos_checklist_planejamento_is_superadmin();
$evento = eventos_checklist_planejamento_evento($pdo, $eventoId);

if (!$evento || $eventoId <= 0) {
    header('Location: index.php?page=agenda_eventos');
    exit;
}

function ecp_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ecp_redirect(int $eventoId, string $message, bool $error = false): void
{
    header('Location: index.php?page=eventos_checklist_planejamento&evento_id=' . $eventoId . '&' . ($error ? 'error=' : 'success=') . urlencode($message));
    exit;
}

function ecp_get_task(PDO $pdo, int $taskId, int $eventoId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM eventos_checklist_tarefas WHERE id = :id AND evento_id = :evento_id LIMIT 1");
    $stmt->execute([':id' => $taskId, ':evento_id' => $eventoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    try {
        if ($action === 'sync_models') {
            if (!$isSuperadmin) {
                ecp_redirect($eventoId, 'Somente o superadministrador pode atualizar os modelos do evento.', true);
            }
            $result = eventos_checklist_planejamento_gerar_para_evento($pdo, $eventoId, $userId);
            if (empty($result['ok'])) {
                ecp_redirect($eventoId, (string)($result['error'] ?? 'Não foi possível gerar as tarefas.'), true);
            }
            ecp_redirect($eventoId, (int)$result['adicionadas'] . ' nova(s) tarefa(s) adicionada(s), sem alterar as existentes.');
        }

        if ($action === 'change_typology') {
            if (!$isSuperadmin) {
                ecp_redirect($eventoId, 'Somente o superadministrador pode alterar tipo e pacote.', true);
            }
            $tipo = eventos_reuniao_normalizar_tipo_evento_real((string)($_POST['tipo_evento_real'] ?? ''), $pdo);
            $pacoteId = (int)($_POST['pacote_evento_id'] ?? 0);
            if ($tipo === '' || $pacoteId <= 0) {
                ecp_redirect($eventoId, 'Tipo e pacote são obrigatórios.', true);
            }
            $stmtPacote = $pdo->prepare("
                SELECT COALESCE(NULLIF(TRIM(tipo_evento_real), ''), '') AS tipo_evento_real
                FROM logistica_pacotes_evento
                WHERE id = :id
                  AND deleted_at IS NULL
                  AND COALESCE(oculto, FALSE) = FALSE
                LIMIT 1
            ");
            $stmtPacote->execute([':id' => $pacoteId]);
            $tipoPacote = $stmtPacote->fetchColumn();
            if ($tipoPacote === false) {
                ecp_redirect($eventoId, 'Pacote inválido ou indisponível.', true);
            }
            if (
                trim((string)$tipoPacote) !== ''
                && eventos_reuniao_normalizar_tipo_evento_real((string)$tipoPacote, $pdo) !== $tipo
            ) {
                ecp_redirect($eventoId, 'O pacote selecionado não pertence ao tipo de evento informado.', true);
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                UPDATE comercial_eventos_painel
                SET tipo_evento_real = :tipo, pacote_evento_id = :pacote, updated_at = NOW()
                WHERE id = (
                    SELECT id
                    FROM comercial_eventos_painel
                    WHERE espelho_evento_id = :evento_id
                    ORDER BY updated_at DESC NULLS LAST, id DESC
                    LIMIT 1
                )
            ");
            $stmt->execute([':tipo' => $tipo, ':pacote' => $pacoteId, ':evento_id' => $eventoId]);
            if ($stmt->rowCount() <= 0) {
                ecp_redirect($eventoId, 'Este evento não possui cadastro direto no Painel para alterar.', true);
            }

            $desativadas = 0;
            if (!empty($_POST['desativar_antigas'])) {
                $stmtOld = $pdo->prepare("
                    SELECT t.*
                    FROM eventos_checklist_tarefas t
                    JOIN eventos_checklist_modelos m ON m.id = t.modelo_id
                    WHERE t.evento_id = :evento_id
                      AND t.status NOT IN ('concluida', 'desativada')
                      AND NOT (
                          (m.origem = 'tipo' AND m.tipo_evento_key = :tipo)
                          OR
                          (m.origem = 'pacote' AND m.pacote_evento_id = :pacote)
                      )
                ");
                $stmtOld->execute([':evento_id' => $eventoId, ':tipo' => $tipo, ':pacote' => $pacoteId]);
                $oldTasks = $stmtOld->fetchAll(PDO::FETCH_ASSOC) ?: [];
                $disable = $pdo->prepare("
                    UPDATE eventos_checklist_tarefas
                    SET status = 'desativada', desativada_em = NOW(), desativada_por = :usuario,
                        motivo_desativacao = 'Tipo ou pacote do evento alterado', updated_at = NOW()
                    WHERE id = :id
                ");
                foreach ($oldTasks as $oldTask) {
                    $disable->execute([':usuario' => $userId, ':id' => (int)$oldTask['id']]);
                    eventos_checklist_planejamento_historico(
                        $pdo,
                        (int)$oldTask['id'],
                        $eventoId,
                        'desativada_por_tipologia',
                        'Tarefa desativada após alteração do tipo ou pacote.',
                        ['status' => $oldTask['status']],
                        ['status' => 'desativada'],
                        $userId
                    );
                    $desativadas++;
                }
            }

            $generated = eventos_checklist_planejamento_gerar_para_evento($pdo, $eventoId, $userId);
            if (empty($generated['ok'])) {
                throw new RuntimeException((string)($generated['error'] ?? 'Não foi possível gerar as tarefas da nova tipologia.'));
            }
            $pdo->commit();
            ecp_redirect(
                $eventoId,
                'Tipo e pacote atualizados. ' . (int)($generated['adicionadas'] ?? 0)
                . ' tarefa(s) adicionada(s) e ' . $desativadas . ' tarefa(s) antiga(s) desativada(s).'
            );
        }

        if ($action === 'set_event_cancelled') {
            if (!$isSuperadmin) {
                ecp_redirect($eventoId, 'Somente o superadministrador pode alterar o cancelamento do evento.', true);
            }
            $cancelado = (string)($_POST['cancelado'] ?? '0') === '1';
            $alteradas = eventos_checklist_planejamento_definir_cancelamento(
                $pdo,
                $eventoId,
                $cancelado,
                $userId
            );
            ecp_redirect(
                $eventoId,
                $cancelado
                    ? 'Evento marcado como cancelado; ' . $alteradas . ' tarefa(s) aberta(s) foram desativadas.'
                    : 'Evento reativado; ' . $alteradas . ' tarefa(s) cancelada(s) foram reabertas.'
            );
        }

        $taskId = (int)($_POST['tarefa_id'] ?? 0);
        $task = ecp_get_task($pdo, $taskId, $eventoId);
        if (!$task) {
            ecp_redirect($eventoId, 'Tarefa não encontrada.', true);
        }
        $canEdit = eventos_checklist_planejamento_pode_editar($pdo, $task, $userId, $isSuperadmin);
        if (!$canEdit) {
            ecp_redirect($eventoId, 'Você não é responsável por esta tarefa.', true);
        }

        if ($action === 'set_status') {
            $next = (string)($_POST['status'] ?? '');
            $allowed = ['pendente', 'em_andamento', 'concluida', 'desativada'];
            if (!in_array($next, $allowed, true)) {
                ecp_redirect($eventoId, 'Status inválido.', true);
            }
            if ($next === 'desativada' && !$isSuperadmin) {
                ecp_redirect($eventoId, 'Somente o superadministrador pode desativar tarefas.', true);
            }
            $motivo = trim((string)($_POST['motivo'] ?? ''));
            if ($next === 'desativada' && $motivo === '') {
                ecp_redirect($eventoId, 'Informe o motivo da desativação.', true);
            }
            $stmt = $pdo->prepare("
                UPDATE eventos_checklist_tarefas
                SET status = :status,
                    concluida_em = CASE WHEN :status = 'concluida' THEN NOW() ELSE NULL END,
                    concluida_por = CASE WHEN :status = 'concluida' THEN :usuario ELSE NULL END,
                    concluida_pelo_cliente = FALSE,
                    desativada_em = CASE WHEN :status = 'desativada' THEN NOW() ELSE NULL END,
                    desativada_por = CASE WHEN :status = 'desativada' THEN :usuario ELSE NULL END,
                    motivo_desativacao = CASE WHEN :status = 'desativada' THEN :motivo ELSE '' END,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':status' => $next,
                ':usuario' => $userId > 0 ? $userId : null,
                ':motivo' => $motivo,
                ':id' => $taskId,
            ]);
            eventos_checklist_planejamento_historico(
                $pdo,
                $taskId,
                $eventoId,
                'status_alterado',
                'Status alterado para ' . $next . '.',
                ['status' => $task['status']],
                ['status' => $next, 'motivo' => $motivo],
                $userId
            );
            ecp_redirect($eventoId, 'Status atualizado.');
        }

        if ($action === 'reactivate') {
            if (!$isSuperadmin) {
                ecp_redirect($eventoId, 'Somente o superadministrador pode reativar tarefas.', true);
            }
            $pdo->prepare("
                UPDATE eventos_checklist_tarefas
                SET status = 'pendente', desativada_em = NULL, desativada_por = NULL,
                    motivo_desativacao = '', updated_at = NOW()
                WHERE id = :id
            ")->execute([':id' => $taskId]);
            eventos_checklist_planejamento_historico(
                $pdo, $taskId, $eventoId, 'reativada', 'Tarefa reativada.',
                ['status' => $task['status']], ['status' => 'pendente'], $userId
            );
            ecp_redirect($eventoId, 'Tarefa reativada.');
        }

        if ($action === 'validate_client') {
            if ((string)$task['status'] !== 'aguardando_validacao') {
                ecp_redirect($eventoId, 'Esta tarefa não está aguardando validação.', true);
            }
            $pdo->prepare("
                UPDATE eventos_checklist_tarefas
                SET status = 'concluida', concluida_em = NOW(), concluida_por = :usuario, updated_at = NOW()
                WHERE id = :id
            ")->execute([':usuario' => $userId > 0 ? $userId : null, ':id' => $taskId]);
            eventos_checklist_planejamento_historico(
                $pdo, $taskId, $eventoId, 'conclusao_cliente_validada',
                'Conclusão informada pelo cliente validada internamente.',
                ['status' => 'aguardando_validacao'], ['status' => 'concluida'], $userId
            );
            ecp_redirect($eventoId, 'Conclusão do cliente validada.');
        }

        if ($action === 'set_due_date') {
            $vencimento = trim((string)($_POST['vencimento'] ?? ''));
            if ($vencimento !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencimento) !== 1) {
                ecp_redirect($eventoId, 'Informe uma data válida.', true);
            }
            $pdo->prepare("
                UPDATE eventos_checklist_tarefas
                SET vencimento = CAST(NULLIF(:vencimento, '') AS DATE),
                    vencimento_manual = TRUE,
                    updated_at = NOW()
                WHERE id = :id
            ")->execute([':vencimento' => $vencimento, ':id' => $taskId]);
            eventos_checklist_planejamento_historico(
                $pdo, $taskId, $eventoId, 'vencimento_manual',
                'Vencimento ajustado manualmente e protegido contra recálculo.',
                ['vencimento' => $task['vencimento'], 'manual' => $task['vencimento_manual']],
                ['vencimento' => $vencimento ?: null, 'manual' => true], $userId
            );
            ecp_redirect($eventoId, 'Vencimento ajustado manualmente.');
        }

        if ($action === 'reassign') {
            if (!$isSuperadmin) {
                ecp_redirect($eventoId, 'Somente o superadministrador pode trocar responsáveis.', true);
            }
            $tipo = (string)($_POST['responsabilidade'] ?? '');
            $assignedUser = (int)($_POST['responsavel_usuario_id'] ?? 0);
            $setor = trim((string)($_POST['responsavel_setor'] ?? ''));
            if (!in_array($tipo, ['usuario', 'setor'], true)
                || ($tipo === 'usuario' && $assignedUser <= 0)
                || ($tipo === 'setor' && $setor === '')) {
                ecp_redirect($eventoId, 'Selecione o novo usuário ou setor.', true);
            }
            $pdo->prepare("
                UPDATE eventos_checklist_tarefas
                SET responsabilidade = :tipo,
                    responsavel_usuario_id = :usuario,
                    responsavel_setor = :setor,
                    updated_at = NOW()
                WHERE id = :id
            ")->execute([
                ':tipo' => $tipo,
                ':usuario' => $tipo === 'usuario' ? $assignedUser : null,
                ':setor' => $tipo === 'setor' ? $setor : null,
                ':id' => $taskId,
            ]);
            eventos_checklist_planejamento_historico(
                $pdo, $taskId, $eventoId, 'responsavel_alterado',
                'Responsabilidade da tarefa alterada.',
                ['responsabilidade' => $task['responsabilidade'], 'usuario' => $task['responsavel_usuario_id'], 'setor' => $task['responsavel_setor']],
                ['responsabilidade' => $tipo, 'usuario' => $assignedUser ?: null, 'setor' => $setor ?: null], $userId
            );
            ecp_redirect($eventoId, 'Responsável atualizado.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('eventos_checklist_planejamento: ' . $e->getMessage());
        ecp_redirect($eventoId, 'Não foi possível concluir a operação.', true);
    }
}

$stmt = $pdo->prepare("
    SELECT t.*, u.nome AS responsavel_nome,
           m.nome AS modelo_nome
    FROM eventos_checklist_tarefas t
    LEFT JOIN usuarios u ON u.id = t.responsavel_usuario_id
    LEFT JOIN eventos_checklist_modelos m ON m.id = t.modelo_id
    WHERE t.evento_id = :evento_id
    ORDER BY t.vencimento ASC NULLS LAST, t.ordem, t.id
");
$stmt->execute([':evento_id' => $eventoId]);
$tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$resumo = eventos_checklist_planejamento_resumo_evento($pdo, $eventoId);
$tiposEvento = $isSuperadmin ? eventos_reuniao_tipos_evento_real_options($pdo, false) : [];
$pacotesEvento = [];
if ($isSuperadmin) {
    $pacotesEvento = $pdo->query("
        SELECT id, nome, COALESCE(NULLIF(TRIM(tipo_evento_real), ''), '') AS tipo_evento_real
        FROM logistica_pacotes_evento
        WHERE deleted_at IS NULL
          AND COALESCE(oculto, FALSE) = FALSE
        ORDER BY LOWER(nome), id
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($pacotesEvento as &$pacoteEvento) {
        $rawType = trim((string)($pacoteEvento['tipo_evento_real'] ?? ''));
        $pacoteEvento['tipo_evento_real'] = $rawType === ''
            ? ''
            : eventos_reuniao_normalizar_tipo_evento_real($rawType, $pdo);
    }
    unset($pacoteEvento);
}

$usuarios = [];
$setores = [];
if ($isSuperadmin) {
    $usuarios = $pdo->query("
        SELECT id, nome, COALESCE(NULLIF(TRIM(cargo), ''), 'Sem setor') AS cargo
        FROM usuarios WHERE COALESCE(ativo, TRUE) = TRUE ORDER BY LOWER(nome)
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($usuarios as $usuario) {
        $cargo = trim((string)$usuario['cargo']);
        if ($cargo !== '' && $cargo !== 'Sem setor') {
            $setores[mb_strtolower($cargo, 'UTF-8')] = $cargo;
        }
    }
    natcasesort($setores);
}

$mes = substr((string)$evento['data_evento'], 0, 7);
includeSidebar('Checklist do Evento');
?>

<style>
.ecp-page{max-width:1320px;margin:0 auto;padding:1.5rem;background:#f8fafc}
.ecp-head{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1rem}
.ecp-title{margin:0;color:#1e3a8a;font-size:1.9rem}.ecp-sub{color:#64748b;margin:.35rem 0}
.ecp-actions{display:flex;gap:.55rem;flex-wrap:wrap}.ecp-btn{border:0;border-radius:9px;padding:.62rem .85rem;font-weight:750;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center}
.ecp-btn.primary{background:#2563eb;color:#fff}.ecp-btn.secondary{background:#e2e8f0;color:#334155}.ecp-btn.success{background:#dcfce7;color:#166534}.ecp-btn.warning{background:#fef3c7;color:#92400e}.ecp-btn.danger{background:#fee2e2;color:#991b1b}
.ecp-summary{display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:.8rem;margin:1rem 0}
.ecp-stat{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1rem}.ecp-stat strong{display:block;font-size:1.55rem;color:#1e3a8a}.ecp-stat span{color:#64748b;font-size:.84rem}
.ecp-progress{height:10px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:.7rem}.ecp-progress span{display:block;height:100%;background:#22c55e}
.ecp-task{background:#fff;border:1px solid #dbe3ef;border-left:5px solid #94a3b8;border-radius:13px;padding:1rem;margin-bottom:.8rem;box-shadow:0 6px 18px rgba(15,23,42,.04)}
.ecp-task.overdue{border-left-color:#7c3aed}.ecp-task.done{border-left-color:#22c55e}.ecp-task.disabled{opacity:.62;border-left-color:#f59e0b}.ecp-task.waiting{border-left-color:#0ea5e9}
.ecp-task-top{display:flex;justify-content:space-between;gap:1rem;align-items:flex-start}.ecp-task h3{margin:0;color:#0f172a;font-size:1.05rem}.ecp-meta{display:flex;gap:.4rem;flex-wrap:wrap;margin:.5rem 0}.ecp-badge{padding:.22rem .52rem;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.76rem;font-weight:700}
.ecp-desc{color:#475569;font-size:.9rem;white-space:pre-line}.ecp-forms{display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-top:.8rem;padding-top:.8rem;border-top:1px solid #e2e8f0}
.ecp-forms form{display:flex;gap:.4rem;align-items:end;flex-wrap:wrap}.ecp-input{border:1px solid #cbd5e1;border-radius:8px;padding:.55rem}.ecp-alert{padding:.8rem 1rem;border-radius:10px;margin-bottom:1rem}.ecp-alert.ok{background:#ecfdf5;color:#166534}.ecp-alert.error{background:#fef2f2;color:#991b1b}.ecp-empty{text-align:center;background:#fff;border:1px dashed #cbd5e1;border-radius:13px;padding:2.2rem;color:#64748b}
.ecp-config{background:#fff;border:1px solid #dbe3ef;border-radius:13px;padding:1rem;margin-bottom:1rem}.ecp-config h2{margin:0 0 .65rem;color:#1e3a8a;font-size:1.05rem}.ecp-config form{display:flex;gap:.5rem;align-items:end;flex-wrap:wrap}.ecp-config label{font-size:.8rem;font-weight:700;color:#475569}.ecp-config label span{display:block;margin-bottom:.25rem}
@media(max-width:900px){.ecp-head{flex-direction:column}.ecp-summary{grid-template-columns:repeat(2,1fr)}.ecp-task-top{flex-direction:column}}
</style>

<div class="ecp-page">
    <div class="ecp-head">
        <div>
            <h1 class="ecp-title">✅ Checklist de planejamento</h1>
            <p class="ecp-sub"><?= ecp_h($evento['nome_evento']) ?> • <?= ecp_h(date('d/m/Y', strtotime((string)$evento['data_evento']))) ?> • <?= ecp_h($evento['unidade']) ?></p>
            <p class="ecp-sub">Tipo: <?= ecp_h($evento['tipo_evento_real'] ?: 'não definido') ?> • Pacote: <?= ecp_h($evento['pacote_nome'] ?: 'não definido') ?></p>
        </div>
        <div class="ecp-actions">
            <a class="ecp-btn secondary" href="index.php?page=agenda_eventos&evento_id=<?= $eventoId ?>&mes=<?= ecp_h($mes) ?>">← Evento</a>
            <?php if ($isSuperadmin): ?>
            <form method="post">
                <input type="hidden" name="action" value="sync_models"><input type="hidden" name="evento_id" value="<?= $eventoId ?>">
                <button class="ecp-btn primary" type="submit">Adicionar tarefas dos modelos atuais</button>
            </form>
            <form method="post" onsubmit="return confirm('<?= $evento['painel_status'] === 'cancelado' ? 'Reativar o evento e suas tarefas canceladas?' : 'Marcar o evento como cancelado e desativar todas as tarefas abertas?' ?>')">
                <input type="hidden" name="action" value="set_event_cancelled"><input type="hidden" name="evento_id" value="<?= $eventoId ?>">
                <input type="hidden" name="cancelado" value="<?= $evento['painel_status'] === 'cancelado' ? '0' : '1' ?>">
                <button class="ecp-btn <?= $evento['painel_status'] === 'cancelado' ? 'warning' : 'danger' ?>" type="submit"><?= $evento['painel_status'] === 'cancelado' ? 'Reativar evento' : 'Cancelar evento' ?></button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_GET['success'])): ?><div class="ecp-alert ok"><?= ecp_h($_GET['success']) ?></div><?php endif; ?>
    <?php if (!empty($_GET['error'])): ?><div class="ecp-alert error"><?= ecp_h($_GET['error']) ?></div><?php endif; ?>

    <div class="ecp-summary">
        <div class="ecp-stat"><strong><?= (int)$resumo['percentual'] ?>%</strong><span>Progresso</span><div class="ecp-progress"><span style="width:<?= (int)$resumo['percentual'] ?>%"></span></div></div>
        <div class="ecp-stat"><strong><?= (int)$resumo['total'] ?></strong><span>Total</span></div>
        <div class="ecp-stat"><strong><?= (int)$resumo['concluidas'] ?></strong><span>Concluídas</span></div>
        <div class="ecp-stat"><strong><?= (int)$resumo['abertas'] ?></strong><span>Abertas</span></div>
        <div class="ecp-stat"><strong><?= (int)$resumo['atrasadas'] ?></strong><span>Atrasadas</span></div>
    </div>

    <?php if ($isSuperadmin): ?>
    <div class="ecp-config">
        <h2>Alterar tipo ou pacote</h2>
        <form method="post" onsubmit="return confirm('Confirmar a alteração? As tarefas concluídas nunca serão removidas.')">
            <input type="hidden" name="action" value="change_typology"><input type="hidden" name="evento_id" value="<?= $eventoId ?>">
            <label><span>Tipo de evento</span><select id="ecp-tipo-evento" class="ecp-input" name="tipo_evento_real" required><?php foreach ($tiposEvento as $key => $label): ?><option value="<?= ecp_h($key) ?>" <?= $evento['tipo_evento_real'] === $key ? 'selected' : '' ?>><?= ecp_h($label) ?></option><?php endforeach; ?></select></label>
            <label><span>Pacote</span><select id="ecp-pacote-evento" class="ecp-input" name="pacote_evento_id" required><?php foreach ($pacotesEvento as $pacote): ?><option value="<?= (int)$pacote['id'] ?>" data-tipo="<?= ecp_h($pacote['tipo_evento_real']) ?>" <?= (int)$evento['pacote_evento_id'] === (int)$pacote['id'] ? 'selected' : '' ?>><?= ecp_h($pacote['nome']) ?></option><?php endforeach; ?></select></label>
            <label style="display:flex;align-items:center;gap:.4rem;padding-bottom:.55rem"><input type="checkbox" name="desativar_antigas" value="1"> Desativar tarefas abertas dos modelos anteriores</label>
            <button class="ecp-btn warning" type="submit">Aplicar alteração</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!$tarefas): ?>
        <div class="ecp-empty">
            <strong>Nenhuma tarefa gerada.</strong>
            <div>Confirme o tipo, o pacote e os modelos ativos. Depois use “Adicionar tarefas dos modelos atuais”.</div>
        </div>
    <?php endif; ?>

    <?php foreach ($tarefas as $tarefa): ?>
        <?php
        $overdue = !empty($tarefa['vencimento'])
            && strtotime((string)$tarefa['vencimento']) < strtotime(date('Y-m-d'))
            && in_array($tarefa['status'], ['pendente', 'em_andamento', 'aguardando_validacao'], true);
        $class = $tarefa['status'] === 'concluida' ? 'done' : ($tarefa['status'] === 'desativada' ? 'disabled' : ($tarefa['status'] === 'aguardando_validacao' ? 'waiting' : ($overdue ? 'overdue' : '')));
        $canEdit = eventos_checklist_planejamento_pode_editar($pdo, $tarefa, $userId, $isSuperadmin);
        $responsavel = $tarefa['responsabilidade'] === 'cliente'
            ? 'Cliente'
            : ($tarefa['responsabilidade'] === 'setor' ? 'Setor: ' . $tarefa['responsavel_setor'] : 'Usuário: ' . ($tarefa['responsavel_nome'] ?: 'não definido'));
        ?>
        <article class="ecp-task <?= ecp_h($class) ?>">
            <div class="ecp-task-top">
                <div>
                    <h3><?= ecp_h($tarefa['titulo']) ?></h3>
                    <div class="ecp-meta">
                        <span class="ecp-badge"><?= ecp_h($responsavel) ?></span>
                        <span class="ecp-badge">Status: <?= ecp_h(str_replace('_', ' ', $tarefa['status'])) ?></span>
                        <span class="ecp-badge">Vence: <?= $tarefa['vencimento'] ? ecp_h(date('d/m/Y', strtotime((string)$tarefa['vencimento']))) : 'sem data' ?><?= !empty($tarefa['vencimento_manual']) ? ' • manual' : '' ?></span>
                        <span class="ecp-badge"><?= ecp_h($tarefa['modelo_nome'] ?: 'Manual') ?></span>
                    </div>
                    <?php if ($tarefa['descricao'] !== ''): ?><div class="ecp-desc"><?= ecp_h($tarefa['descricao']) ?></div><?php endif; ?>
                    <?php if ($tarefa['status'] === 'desativada' && $tarefa['motivo_desativacao'] !== ''): ?><div class="ecp-desc"><strong>Motivo:</strong> <?= ecp_h($tarefa['motivo_desativacao']) ?></div><?php endif; ?>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div class="ecp-forms">
                <?php if ($tarefa['status'] === 'desativada'): ?>
                    <?php if ($isSuperadmin): ?><form method="post"><input type="hidden" name="evento_id" value="<?= $eventoId ?>"><input type="hidden" name="action" value="reactivate"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>"><button class="ecp-btn warning" type="submit">Reativar</button></form><?php endif; ?>
                <?php else: ?>
                    <?php if ($tarefa['status'] !== 'em_andamento'): ?><form method="post"><input type="hidden" name="evento_id" value="<?= $eventoId ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>"><input type="hidden" name="status" value="em_andamento"><button class="ecp-btn primary" type="submit">Em andamento</button></form><?php endif; ?>
                    <?php if ($tarefa['status'] === 'aguardando_validacao'): ?>
                        <form method="post"><input type="hidden" name="evento_id" value="<?= $eventoId ?>"><input type="hidden" name="action" value="validate_client"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>"><button class="ecp-btn success" type="submit">Validar conclusão do cliente</button></form>
                    <?php elseif ($tarefa['status'] !== 'concluida'): ?>
                        <form method="post"><input type="hidden" name="evento_id" value="<?= $eventoId ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>"><input type="hidden" name="status" value="concluida"><button class="ecp-btn success" type="submit">Concluir</button></form>
                    <?php endif; ?>
                    <?php if ($tarefa['status'] === 'concluida'): ?><form method="post"><input type="hidden" name="evento_id" value="<?= $eventoId ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>"><input type="hidden" name="status" value="pendente"><button class="ecp-btn warning" type="submit">Reabrir</button></form><?php endif; ?>
                    <form method="post"><input type="hidden" name="evento_id" value="<?= $eventoId ?>"><input type="hidden" name="action" value="set_due_date"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>"><input class="ecp-input" type="date" name="vencimento" value="<?= ecp_h($tarefa['vencimento']) ?>"><button class="ecp-btn secondary" type="submit">Ajustar vencimento</button></form>
                    <?php if ($isSuperadmin): ?>
                        <form method="post"><input type="hidden" name="evento_id" value="<?= $eventoId ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>"><input type="hidden" name="status" value="desativada"><input class="ecp-input" name="motivo" required placeholder="Motivo"><button class="ecp-btn danger" type="submit">Não terá/cancelar</button></form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($isSuperadmin && $tarefa['responsabilidade'] !== 'cliente'): ?>
            <div class="ecp-forms">
                <form method="post">
                    <input type="hidden" name="evento_id" value="<?= $eventoId ?>"><input type="hidden" name="action" value="reassign"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>">
                    <select class="ecp-input" name="responsabilidade"><option value="usuario">Usuário</option><option value="setor" <?= $tarefa['responsabilidade'] === 'setor' ? 'selected' : '' ?>>Setor</option></select>
                    <select class="ecp-input" name="responsavel_usuario_id"><option value="">Usuário...</option><?php foreach ($usuarios as $usuario): ?><option value="<?= (int)$usuario['id'] ?>" <?= (int)$tarefa['responsavel_usuario_id'] === (int)$usuario['id'] ? 'selected' : '' ?>><?= ecp_h($usuario['nome']) ?></option><?php endforeach; ?></select>
                    <select class="ecp-input" name="responsavel_setor"><option value="">Setor...</option><?php foreach ($setores as $setor): ?><option value="<?= ecp_h($setor) ?>" <?= $tarefa['responsavel_setor'] === $setor ? 'selected' : '' ?>><?= ecp_h($setor) ?></option><?php endforeach; ?></select>
                    <button class="ecp-btn secondary" type="submit">Trocar responsável</button>
                </form>
            </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>

<script>
(() => {
    const typeSelect = document.getElementById('ecp-tipo-evento');
    const packageSelect = document.getElementById('ecp-pacote-evento');
    if (!typeSelect || !packageSelect) return;
    const filterPackages = () => {
        const type = String(typeSelect.value || '');
        Array.from(packageSelect.options).forEach((option) => {
            const packageType = String(option.dataset.tipo || '');
            option.hidden = packageType !== '' && packageType !== type;
            option.disabled = option.hidden;
        });
        if (packageSelect.selectedOptions[0]?.disabled) packageSelect.value = '';
    };
    typeSelect.addEventListener('change', filterPackages);
    filterPackages();
})();
</script>

<?php endSidebar(); ?>
