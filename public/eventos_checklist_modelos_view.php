<?php
/**
 * Visual do cadastro de modelos de checklist.
 * As operações e validações permanecem em eventos_checklist_modelos.php.
 */

$tarefasAtivasTotal = array_sum(array_map(
    static fn(array $modelo): int => (int)($modelo['tarefas_ativas'] ?? 0),
    $modelos
));
$ruleLabels = [
    'sem_data' => 'Sem data',
    'dia_evento' => 'No dia do evento',
    'antes_evento' => 'Antes do evento',
    'depois_evento' => 'Depois do evento',
    'depois_cadastro' => 'Após o cadastro',
    'depois_insercao' => 'Após a inserção',
];
$aplicacaoAtual = '';
if ($modeloAtual) {
    $aplicacaoAtual = $modeloAtual['origem'] === 'tipo'
        ? (string)($tipos[$modeloAtual['tipo_evento_key']] ?? $modeloAtual['tipo_evento_key'])
        : (string)($modeloAtual['pacote_nome'] ?? 'Pacote');
}
?>

<style>
.ecmv-page{max-width:1460px;margin:0 auto;padding:2.25rem 1.5rem 4rem;background:#f6f8fc;min-height:100vh;color:#172033}
.ecmv-hero{position:relative;overflow:hidden;background:linear-gradient(135deg,#173b86,#2458b7 62%,#3674db);border-radius:20px;padding:1.65rem 1.8rem;color:#fff;box-shadow:0 18px 40px rgba(30,64,175,.18);margin-bottom:1rem}
.ecmv-hero:after{content:"";position:absolute;width:260px;height:260px;border-radius:50%;right:-80px;top:-145px;background:rgba(255,255,255,.1)}
.ecmv-hero-main,.ecmv-title,.ecmv-actions,.ecmv-settings,.ecmv-settings form,.ecmv-detail-head,.ecmv-detail-actions,.ecmv-row-actions{display:flex;align-items:center}
.ecmv-hero-main{position:relative;z-index:1;justify-content:space-between;gap:1.5rem}.ecmv-title{gap:1rem}.ecmv-title-icon{width:52px;height:52px;border-radius:15px;display:grid;place-items:center;background:rgba(255,255,255,.16);font-size:1.55rem;border:1px solid rgba(255,255,255,.2)}
.ecmv-hero h1{font-size:1.75rem;margin:0 0 .3rem;letter-spacing:-.02em}.ecmv-hero p{margin:0;color:#dbeafe;font-size:.93rem}.ecmv-actions,.ecmv-detail-actions,.ecmv-row-actions{gap:.45rem;flex-wrap:wrap}
.ecmv-btn{border:0;border-radius:10px;padding:.66rem .92rem;font-size:.84rem;font-weight:750;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:.4rem;transition:.18s;white-space:nowrap}.ecmv-btn:hover{transform:translateY(-1px)}
.ecmv-btn.primary{background:#2563eb;color:#fff;box-shadow:0 7px 18px rgba(37,99,235,.2)}.ecmv-btn.hero{background:#fff;color:#1e3a8a}.ecmv-btn.ghost{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.26)}.ecmv-btn.secondary{background:#eef2f7;color:#334155}.ecmv-btn.danger{background:#fff1f2;color:#be123c}.ecmv-btn.success{background:#ecfdf5;color:#047857}.ecmv-btn.small{padding:.48rem .66rem;font-size:.76rem}
.ecmv-alert{padding:.85rem 1rem;border-radius:11px;margin-bottom:1rem;border:1px solid}.ecmv-alert.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}.ecmv-alert.error{background:#fff1f2;color:#9f1239;border-color:#fecdd3}
.ecmv-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.85rem;margin-bottom:1rem}.ecmv-stat{background:#fff;border:1px solid #e3e8f2;border-radius:14px;padding:1rem 1.1rem;display:flex;align-items:center;gap:.8rem;box-shadow:0 6px 18px rgba(15,23,42,.035)}.ecmv-stat-icon{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:#eff6ff;color:#1d4ed8;font-size:1.15rem}.ecmv-stat strong{display:block;font-size:1.25rem;color:#172554}.ecmv-stat span{display:block;color:#64748b;font-size:.78rem}
.ecmv-settings{background:#fff;border:1px solid #e3e8f2;border-radius:15px;padding:1rem 1.1rem;margin-bottom:1rem;justify-content:space-between;gap:1rem;box-shadow:0 6px 18px rgba(15,23,42,.035)}.ecmv-settings-copy strong{display:block;color:#172554}.ecmv-settings-copy span{display:block;color:#64748b;font-size:.76rem;margin-top:.18rem}.ecmv-settings form{justify-content:flex-end;gap:.85rem;flex-wrap:wrap}
.ecmv-switch-row{display:flex;align-items:center;gap:.52rem;color:#334155;font-size:.79rem;font-weight:650}.ecmv-switch{position:relative;width:40px;height:22px;flex:0 0 auto}.ecmv-switch input{opacity:0;width:0;height:0}.ecmv-switch-track{position:absolute;inset:0;border-radius:999px;background:#cbd5e1;cursor:pointer;transition:.2s}.ecmv-switch-track:before{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(15,23,42,.25);transition:.2s}.ecmv-switch input:checked+.ecmv-switch-track{background:#2563eb}.ecmv-switch input:checked+.ecmv-switch-track:before{transform:translateX(18px)}
.ecmv-card{background:#fff;border:1px solid #e3e8f2;border-radius:16px;box-shadow:0 8px 24px rgba(15,23,42,.045);overflow:hidden;margin-bottom:1rem}.ecmv-card-head{padding:1rem 1.15rem;border-bottom:1px solid #edf0f5;display:flex;justify-content:space-between;align-items:center;gap:1rem}.ecmv-card-head h2{font-size:1rem;margin:0;color:#172554}.ecmv-card-head p{font-size:.77rem;color:#64748b;margin:.2rem 0 0}
.ecmv-table-wrap{overflow-x:auto}.ecmv-table{width:100%;border-collapse:collapse;min-width:760px}.ecmv-table th{padding:.72rem 1rem;text-align:left;color:#64748b;background:#f8fafc;font-size:.68rem;text-transform:uppercase;letter-spacing:.055em;border-bottom:1px solid #e8edf4}.ecmv-table td{padding:.82rem 1rem;border-bottom:1px solid #edf0f5;vertical-align:middle;font-size:.83rem;color:#334155}.ecmv-table tr:last-child td{border-bottom:0}.ecmv-table tbody tr:hover{background:#f8fbff}.ecmv-table tbody tr.selected{background:#eff6ff}
.ecmv-name,.ecmv-task-title{font-weight:800;color:#172554}.ecmv-sub{font-size:.73rem;color:#64748b;margin-top:.15rem}.ecmv-row-actions{justify-content:flex-end}.ecmv-pill{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .52rem;border-radius:999px;font-size:.69rem;font-weight:750;background:#eef2ff;color:#4338ca}.ecmv-pill.green{background:#ecfdf5;color:#047857}.ecmv-pill.gray{background:#f1f5f9;color:#64748b}.ecmv-pill.orange{background:#fff7ed;color:#c2410c}.ecmv-dot{width:7px;height:7px;border-radius:50%;background:#22c55e}.ecmv-dot.off{background:#94a3b8}
.ecmv-empty{padding:3.2rem 1.5rem;text-align:center}.ecmv-empty-icon{width:68px;height:68px;margin:0 auto .9rem;border-radius:20px;background:#eff6ff;display:grid;place-items:center;font-size:1.9rem;color:#2563eb}.ecmv-empty h3{margin:0;color:#172554;font-size:1.1rem}.ecmv-empty p{max-width:500px;margin:.42rem auto 1rem;color:#64748b;font-size:.83rem}
.ecmv-detail-head{padding:1.15rem;justify-content:space-between;align-items:flex-start;gap:1rem;border-bottom:1px solid #edf0f5;background:linear-gradient(180deg,#fff,#fbfdff)}.ecmv-detail-head h2{margin:0;color:#172554;font-size:1.15rem}.ecmv-detail-meta{display:flex;gap:.38rem;flex-wrap:wrap;margin-top:.45rem}.ecmv-task-desc{max-width:500px;color:#64748b;font-size:.74rem;margin-top:.15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ecmv-inactive{opacity:.58}
.ecmv-modal{position:fixed;inset:0;background:rgba(15,23,42,.54);display:none;align-items:center;justify-content:center;padding:1.25rem;z-index:10050;backdrop-filter:blur(3px)}.ecmv-modal.open{display:flex}.ecmv-modal-card{width:min(680px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:18px;box-shadow:0 28px 80px rgba(15,23,42,.32)}.ecmv-modal-card.wide{width:min(820px,100%)}.ecmv-modal-head{padding:1.1rem 1.25rem;border-bottom:1px solid #e8edf4;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:2}.ecmv-modal-head h3{margin:0;color:#172554;font-size:1.05rem}.ecmv-close{width:34px;height:34px;border:0;border-radius:9px;background:#f1f5f9;color:#475569;font-size:1.25rem;cursor:pointer}.ecmv-modal-body{padding:1.2rem 1.25rem}.ecmv-modal-foot{padding:1rem 1.25rem;border-top:1px solid #e8edf4;display:flex;justify-content:flex-end;gap:.5rem;background:#fbfcfe}
.ecmv-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}.ecmv-field.full{grid-column:1/-1}.ecmv-field label{display:block;font-size:.76rem;font-weight:750;color:#334155;margin-bottom:.32rem}.ecmv-field input,.ecmv-field select,.ecmv-field textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:.68rem .72rem;background:#fff;color:#172033;outline:none}.ecmv-field input:focus,.ecmv-field select:focus,.ecmv-field textarea:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.11)}.ecmv-field textarea{min-height:92px;resize:vertical}.ecmv-help{font-size:.71rem;color:#64748b;margin-top:.3rem}.ecmv-check{display:flex!important;align-items:center;gap:.5rem}.ecmv-check input{width:auto}
@media(max-width:900px){.ecmv-hero-main,.ecmv-settings,.ecmv-detail-head{align-items:flex-start;flex-direction:column}.ecmv-settings form{justify-content:flex-start}.ecmv-stats{grid-template-columns:1fr}.ecmv-grid{grid-template-columns:1fr}.ecmv-field.full{grid-column:auto}.ecmv-actions{width:100%}.ecmv-actions .ecmv-btn{flex:1}}
</style>

<div class="ecmv-page">
    <section class="ecmv-hero">
        <div class="ecmv-hero-main">
            <div class="ecmv-title">
                <div class="ecmv-title-icon">✓</div>
                <div><h1>Modelos de checklist</h1><p>Defina as tarefas de planejamento por tipo de evento e por pacote.</p></div>
            </div>
            <div class="ecmv-actions">
                <a class="ecmv-btn ghost" href="index.php?page=configuracoes">← Configurações</a>
                <button class="ecmv-btn hero" type="button" data-ecmv-open="ecmv-new-model">＋ Novo modelo</button>
            </div>
        </div>
    </section>

    <?php if (!empty($_GET['success'])): ?><div class="ecmv-alert ok"><?= ecm_h($_GET['success']) ?></div><?php endif; ?>
    <?php if (!empty($_GET['error'])): ?><div class="ecmv-alert error"><?= ecm_h($_GET['error']) ?></div><?php endif; ?>

    <div class="ecmv-stats">
        <div class="ecmv-stat"><div class="ecmv-stat-icon">▦</div><div><strong><?= count($modelos) ?></strong><span>Modelos cadastrados</span></div></div>
        <div class="ecmv-stat"><div class="ecmv-stat-icon">✓</div><div><strong><?= $tarefasAtivasTotal ?></strong><span>Tarefas ativas nos modelos</span></div></div>
        <div class="ecmv-stat"><div class="ecmv-stat-icon">◉</div><div><strong><?= !empty($config['portal_cliente_ativo']) ? 'Ativo' : 'Desligado' ?></strong><span>Checklist no portal do cliente</span></div></div>
    </div>

    <section class="ecmv-settings">
        <div class="ecmv-settings-copy"><strong>Área do cliente</strong><span>Permanece desligada até a liberação oficial.</span></div>
        <form method="post">
            <input type="hidden" name="action" value="save_config">
            <label class="ecmv-switch-row"><span class="ecmv-switch"><input type="checkbox" name="portal_cliente_ativo" value="1" <?= !empty($config['portal_cliente_ativo']) ? 'checked' : '' ?>><span class="ecmv-switch-track"></span></span>Exibir no portal</label>
            <label class="ecmv-switch-row"><span class="ecmv-switch"><input type="checkbox" name="whatsapp_cliente_ativo" value="1" <?= !empty($config['whatsapp_cliente_ativo']) ? 'checked' : '' ?>><span class="ecmv-switch-track"></span></span>WhatsApp no vencimento</label>
            <button class="ecmv-btn secondary small" type="submit">Salvar</button>
        </form>
    </section>

    <section class="ecmv-card">
        <div class="ecmv-card-head">
            <div><h2>Modelos cadastrados</h2><p>O evento recebe todos os modelos compatíveis com seu tipo e pacote.</p></div>
            <button class="ecmv-btn primary small" type="button" data-ecmv-open="ecmv-new-model">＋ Novo modelo</button>
        </div>
        <?php if (!$modelos): ?>
            <div class="ecmv-empty">
                <div class="ecmv-empty-icon">✓</div>
                <h3>Comece criando o primeiro modelo</h3>
                <p>Escolha se ele pertence a um tipo de evento ou a um pacote. Depois adicione as tarefas e seus prazos.</p>
                <button class="ecmv-btn primary" type="button" data-ecmv-open="ecmv-new-model">Criar primeiro modelo</button>
            </div>
        <?php else: ?>
            <div class="ecmv-table-wrap">
                <table class="ecmv-table">
                    <thead><tr><th>Modelo</th><th>Aplicação</th><th>Tarefas</th><th>Versão</th><th>Status</th><th style="text-align:right">Ação</th></tr></thead>
                    <tbody>
                    <?php foreach ($modelos as $modelo): ?>
                        <?php $aplicacao = $modelo['origem'] === 'tipo' ? ($tipos[$modelo['tipo_evento_key']] ?? $modelo['tipo_evento_key']) : ($modelo['pacote_nome'] ?? 'Pacote'); ?>
                        <tr class="<?= (int)$modelo['id'] === $modeloId ? 'selected' : '' ?>">
                            <td><div class="ecmv-name"><?= ecm_h($modelo['nome']) ?></div><div class="ecmv-sub">ID <?= (int)$modelo['id'] ?></div></td>
                            <td><span class="ecmv-pill <?= $modelo['origem'] === 'pacote' ? 'orange' : '' ?>"><?= $modelo['origem'] === 'tipo' ? 'Tipo' : 'Pacote' ?></span> <?= ecm_h($aplicacao) ?></td>
                            <td><strong><?= (int)$modelo['tarefas_ativas'] ?></strong> ativas <span class="ecmv-sub">de <?= (int)$modelo['tarefas_total'] ?></span></td>
                            <td>v<?= (int)$modelo['versao'] ?></td>
                            <td><span class="ecmv-pill <?= !empty($modelo['ativo']) ? 'green' : 'gray' ?>"><span class="ecmv-dot <?= !empty($modelo['ativo']) ? '' : 'off' ?>"></span><?= !empty($modelo['ativo']) ? 'Ativo' : 'Inativo' ?></span></td>
                            <td><div class="ecmv-row-actions"><a class="ecmv-btn secondary small" href="index.php?page=eventos_checklist_modelos&modelo_id=<?= (int)$modelo['id'] ?>">Abrir</a></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($modeloAtual): ?>
    <section class="ecmv-card">
        <div class="ecmv-detail-head">
            <div>
                <h2><?= ecm_h($modeloAtual['nome']) ?></h2>
                <div class="ecmv-detail-meta">
                    <span class="ecmv-pill <?= $modeloAtual['origem'] === 'pacote' ? 'orange' : '' ?>"><?= $modeloAtual['origem'] === 'tipo' ? 'Tipo de evento' : 'Pacote' ?>: <?= ecm_h($aplicacaoAtual) ?></span>
                    <span class="ecmv-pill gray">Versão <?= (int)$modeloAtual['versao'] ?></span>
                    <span class="ecmv-pill <?= !empty($modeloAtual['ativo']) ? 'green' : 'gray' ?>"><?= !empty($modeloAtual['ativo']) ? 'Ativo' : 'Inativo' ?></span>
                </div>
            </div>
            <div class="ecmv-detail-actions">
                <button class="ecmv-btn secondary small" type="button" data-ecmv-open="ecmv-edit-model">Editar modelo</button>
                <button class="ecmv-btn primary small" type="button" data-ecmv-open="ecmv-task">＋ Nova tarefa</button>
                <form method="post">
                    <input type="hidden" name="action" value="toggle_model"><input type="hidden" name="modelo_id" value="<?= $modeloId ?>"><input type="hidden" name="ativo" value="<?= !empty($modeloAtual['ativo']) ? '0' : '1' ?>">
                    <button class="ecmv-btn <?= !empty($modeloAtual['ativo']) ? 'danger' : 'success' ?> small" type="submit"><?= !empty($modeloAtual['ativo']) ? 'Desativar' : 'Reativar' ?></button>
                </form>
            </div>
        </div>
        <?php if (!$tarefas): ?>
            <div class="ecmv-empty"><div class="ecmv-empty-icon">＋</div><h3>Este modelo ainda não possui tarefas</h3><p>Adicione a primeira tarefa e defina o responsável e a regra de vencimento.</p><button class="ecmv-btn primary" type="button" data-ecmv-open="ecmv-task">Adicionar primeira tarefa</button></div>
        <?php else: ?>
            <div class="ecmv-table-wrap">
                <table class="ecmv-table">
                    <thead><tr><th style="width:40%">Tarefa</th><th>Responsável</th><th>Vencimento</th><th>Status</th><th style="text-align:right">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($tarefas as $tarefa): ?>
                        <?php
                        $responsavelTarefa = $tarefa['responsabilidade'] === 'cliente'
                            ? 'Cliente'
                            : ($tarefa['responsabilidade'] === 'setor' ? 'Setor: ' . $tarefa['responsavel_setor'] : ($tarefa['responsavel_nome'] ?? 'Sem responsável'));
                        $regraLabel = $ruleLabels[$tarefa['regra_vencimento']] ?? $tarefa['regra_vencimento'];
                        if ((int)$tarefa['dias'] > 0) $regraLabel .= ' • ' . (int)$tarefa['dias'] . ' dias';
                        ?>
                        <tr class="<?= empty($tarefa['ativo']) ? 'ecmv-inactive' : '' ?>">
                            <td><div class="ecmv-task-title"><?= ecm_h($tarefa['titulo']) ?></div><?php if ($tarefa['descricao'] !== ''): ?><div class="ecmv-task-desc"><?= ecm_h($tarefa['descricao']) ?></div><?php endif; ?></td>
                            <td><span class="ecmv-pill"><?= ecm_h($responsavelTarefa) ?></span><?php if ($tarefa['responsabilidade'] === 'cliente' && !empty($tarefa['exige_validacao'])): ?><div class="ecmv-sub">Exige validação interna</div><?php endif; ?></td>
                            <td><?= ecm_h($regraLabel) ?></td>
                            <td><span class="ecmv-pill <?= !empty($tarefa['ativo']) ? 'green' : 'gray' ?>"><span class="ecmv-dot <?= !empty($tarefa['ativo']) ? '' : 'off' ?>"></span><?= !empty($tarefa['ativo']) ? 'Ativa' : 'Inativa' ?></span></td>
                            <td><div class="ecmv-row-actions">
                                <a class="ecmv-btn secondary small" href="index.php?page=eventos_checklist_modelos&modelo_id=<?= $modeloId ?>&tarefa_id=<?= (int)$tarefa['id'] ?>">Editar</a>
                                <form method="post"><input type="hidden" name="action" value="toggle_task"><input type="hidden" name="modelo_id" value="<?= $modeloId ?>"><input type="hidden" name="tarefa_id" value="<?= (int)$tarefa['id'] ?>"><input type="hidden" name="ativo" value="<?= !empty($tarefa['ativo']) ? '0' : '1' ?>"><button class="ecmv-btn <?= !empty($tarefa['ativo']) ? 'danger' : 'success' ?> small" type="submit"><?= !empty($tarefa['ativo']) ? 'Desativar' : 'Reativar' ?></button></form>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<div class="ecmv-modal" id="ecmv-new-model" aria-hidden="true">
    <div class="ecmv-modal-card">
        <div class="ecmv-modal-head"><h3>Novo modelo de checklist</h3><button class="ecmv-close" type="button" data-ecmv-close>×</button></div>
        <form method="post" data-ecmv-model-form>
            <input type="hidden" name="action" value="save_model">
            <div class="ecmv-modal-body"><div class="ecmv-grid">
                <div class="ecmv-field full"><label>Nome do modelo</label><input name="nome" required placeholder="Ex.: Casamento — planejamento padrão"></div>
                <div class="ecmv-field full"><label>Aplicar este modelo por</label><select name="origem" required><option value="tipo">Tipo de evento</option><option value="pacote">Pacote</option></select></div>
                <div class="ecmv-field full" data-ecmv-origin="tipo"><label>Tipo de evento</label><select name="tipo_evento_key"><option value="">Selecione...</option><?php foreach ($tipos as $key => $label): ?><option value="<?= ecm_h($key) ?>"><?= ecm_h($label) ?></option><?php endforeach; ?></select></div>
                <div class="ecmv-field full" data-ecmv-origin="pacote"><label>Pacote</label><select name="pacote_evento_id"><option value="">Selecione...</option><?php foreach ($pacotes as $pacote): ?><option value="<?= (int)$pacote['id'] ?>"><?= ecm_h($pacote['nome']) ?></option><?php endforeach; ?></select></div>
            </div></div>
            <div class="ecmv-modal-foot"><button class="ecmv-btn secondary" type="button" data-ecmv-close>Cancelar</button><button class="ecmv-btn primary" type="submit">Criar modelo</button></div>
        </form>
    </div>
</div>

<?php if ($modeloAtual): ?>
<div class="ecmv-modal" id="ecmv-edit-model" aria-hidden="true">
    <div class="ecmv-modal-card">
        <div class="ecmv-modal-head"><h3>Editar modelo</h3><button class="ecmv-close" type="button" data-ecmv-close>×</button></div>
        <form method="post" data-ecmv-model-form>
            <input type="hidden" name="action" value="save_model"><input type="hidden" name="modelo_id" value="<?= $modeloId ?>">
            <div class="ecmv-modal-body"><div class="ecmv-grid">
                <div class="ecmv-field full"><label>Nome do modelo</label><input name="nome" value="<?= ecm_h($modeloAtual['nome']) ?>" required></div>
                <div class="ecmv-field full"><label>Aplicar este modelo por</label><select name="origem"><option value="tipo" <?= $modeloAtual['origem'] === 'tipo' ? 'selected' : '' ?>>Tipo de evento</option><option value="pacote" <?= $modeloAtual['origem'] === 'pacote' ? 'selected' : '' ?>>Pacote</option></select></div>
                <div class="ecmv-field full" data-ecmv-origin="tipo"><label>Tipo de evento</label><select name="tipo_evento_key"><option value="">Selecione...</option><?php foreach ($tipos as $key => $label): ?><option value="<?= ecm_h($key) ?>" <?= $modeloAtual['tipo_evento_key'] === $key ? 'selected' : '' ?>><?= ecm_h($label) ?></option><?php endforeach; ?></select></div>
                <div class="ecmv-field full" data-ecmv-origin="pacote"><label>Pacote</label><select name="pacote_evento_id"><option value="">Selecione...</option><?php foreach ($pacotes as $pacote): ?><option value="<?= (int)$pacote['id'] ?>" <?= (int)$modeloAtual['pacote_evento_id'] === (int)$pacote['id'] ? 'selected' : '' ?>><?= ecm_h($pacote['nome']) ?></option><?php endforeach; ?></select></div>
            </div></div>
            <div class="ecmv-modal-foot"><button class="ecmv-btn secondary" type="button" data-ecmv-close>Cancelar</button><button class="ecmv-btn primary" type="submit">Salvar alterações</button></div>
        </form>
    </div>
</div>

<div class="ecmv-modal" id="ecmv-task" aria-hidden="true">
    <div class="ecmv-modal-card wide">
        <div class="ecmv-modal-head"><h3><?= $editTask ? 'Editar tarefa' : 'Nova tarefa' ?></h3><button class="ecmv-close" type="button" data-ecmv-close>×</button></div>
        <form method="post" data-ecmv-task-form>
            <input type="hidden" name="action" value="save_task"><input type="hidden" name="modelo_id" value="<?= $modeloId ?>"><input type="hidden" name="tarefa_id" value="<?= (int)($editTask['id'] ?? 0) ?>">
            <div class="ecmv-modal-body"><div class="ecmv-grid">
                <div class="ecmv-field full"><label>Título da tarefa</label><input name="titulo" required value="<?= ecm_h($editTask['titulo'] ?? '') ?>" placeholder="Ex.: Agendar reunião final"></div>
                <div class="ecmv-field full"><label>Descrição</label><textarea name="descricao" placeholder="Orientações para quem executar a tarefa"><?= ecm_h($editTask['descricao'] ?? '') ?></textarea></div>
                <div class="ecmv-field"><label>Responsabilidade</label><select name="responsabilidade" required><?php foreach (['usuario' => 'Usuário específico', 'setor' => 'Todos do setor', 'cliente' => 'Cliente'] as $key => $label): ?><option value="<?= $key ?>" <?= ($editTask['responsabilidade'] ?? 'usuario') === $key ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
                <div class="ecmv-field"><label>Ordem de exibição</label><input type="number" name="ordem" value="<?= (int)($editTask['ordem'] ?? (count($tarefas) + 1) * 10) ?>"></div>
                <div class="ecmv-field full" data-ecmv-responsibility="usuario"><label>Usuário responsável</label><select name="responsavel_usuario_id"><option value="">Selecione...</option><?php foreach ($usuarios as $usuario): ?><option value="<?= (int)$usuario['id'] ?>" <?= (int)($editTask['responsavel_usuario_id'] ?? 0) === (int)$usuario['id'] ? 'selected' : '' ?>><?= ecm_h($usuario['nome'] . ' — ' . $usuario['cargo']) ?></option><?php endforeach; ?></select></div>
                <div class="ecmv-field full" data-ecmv-responsibility="setor"><label>Setor responsável</label><select name="responsavel_setor"><option value="">Selecione...</option><?php foreach ($setores as $setor): ?><option value="<?= ecm_h($setor) ?>" <?= ($editTask['responsavel_setor'] ?? '') === $setor ? 'selected' : '' ?>><?= ecm_h($setor) ?></option><?php endforeach; ?></select><div class="ecmv-help">Todos os usuários deste setor poderão concluir a tarefa.</div></div>
                <div class="ecmv-field"><label>Regra de vencimento</label><select name="regra_vencimento"><?php foreach (['sem_data'=>'Data não definida','dia_evento'=>'No dia do evento','antes_evento'=>'Dias antes do evento','depois_evento'=>'Dias depois do evento','depois_cadastro'=>'Dias depois do cadastro','depois_insercao'=>'Dias depois da inserção'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($editTask['regra_vencimento'] ?? 'sem_data') === $key ? 'selected' : '' ?>><?= ecm_h($label) ?></option><?php endforeach; ?></select></div>
                <div class="ecmv-field" data-ecmv-days><label>Quantidade de dias</label><input type="number" min="0" name="dias" value="<?= (int)($editTask['dias'] ?? 0) ?>"></div>
                <div class="ecmv-field full" data-ecmv-responsibility="cliente"><label>Mensagem de WhatsApp</label><textarea name="whatsapp_mensagem" placeholder="Use #NOME#, #TAREFA# e #EVENTO#"><?= ecm_h($editTask['whatsapp_mensagem'] ?? '') ?></textarea><div class="ecmv-help">Uma única tentativa às 9h no dia do vencimento, somente após a liberação.</div></div>
                <div class="ecmv-field full" data-ecmv-responsibility="cliente"><label class="ecmv-check"><input type="checkbox" name="exige_validacao" value="1" <?= !empty($editTask['exige_validacao']) ? 'checked' : '' ?>> Exigir validação interna após o cliente concluir</label></div>
            </div></div>
            <div class="ecmv-modal-foot"><button class="ecmv-btn secondary" type="button" data-ecmv-close>Cancelar</button><button class="ecmv-btn primary" type="submit"><?= $editTask ? 'Salvar alterações' : 'Adicionar tarefa' ?></button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(() => {
    const open = (id) => {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        setTimeout(() => modal.querySelector('input:not([type="hidden"]),select,textarea')?.focus(), 40);
    };
    const close = (modal) => {
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.ecmv-modal.open')) document.body.style.overflow = '';
    };
    document.querySelectorAll('[data-ecmv-open]').forEach((button) => button.addEventListener('click', () => open(button.dataset.ecmvOpen)));
    document.querySelectorAll('[data-ecmv-close]').forEach((button) => button.addEventListener('click', () => close(button.closest('.ecmv-modal'))));
    document.querySelectorAll('.ecmv-modal').forEach((modal) => modal.addEventListener('mousedown', (event) => {
        if (event.target === modal) close(modal);
    }));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close(document.querySelector('.ecmv-modal.open'));
    });

    document.querySelectorAll('[data-ecmv-model-form]').forEach((form) => {
        const origin = form.querySelector('[name="origem"]');
        const sync = () => form.querySelectorAll('[data-ecmv-origin]').forEach((field) => {
            const active = field.dataset.ecmvOrigin === origin.value;
            field.hidden = !active;
            const select = field.querySelector('select');
            select.disabled = !active;
            select.required = active;
        });
        origin.addEventListener('change', sync);
        sync();
    });

    const taskForm = document.querySelector('[data-ecmv-task-form]');
    if (taskForm) {
        const responsibility = taskForm.querySelector('[name="responsabilidade"]');
        const rule = taskForm.querySelector('[name="regra_vencimento"]');
        const syncResponsibility = () => taskForm.querySelectorAll('[data-ecmv-responsibility]').forEach((field) => {
            const active = field.dataset.ecmvResponsibility === responsibility.value;
            field.hidden = !active;
            field.querySelectorAll('input,select,textarea').forEach((input) => {
                input.disabled = !active;
                if (input.tagName === 'SELECT') input.required = active;
            });
        });
        const syncRule = () => {
            const field = taskForm.querySelector('[data-ecmv-days]');
            const active = !['sem_data', 'dia_evento'].includes(rule.value);
            field.hidden = !active;
            field.querySelector('input').disabled = !active;
            field.querySelector('input').required = active;
        };
        responsibility.addEventListener('change', syncResponsibility);
        rule.addEventListener('change', syncRule);
        syncResponsibility();
        syncRule();
    }
    <?php if ($editTask): ?>open('ecmv-task');<?php endif; ?>
})();
</script>
