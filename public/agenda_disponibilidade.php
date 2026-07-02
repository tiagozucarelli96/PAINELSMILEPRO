<?php
// agenda_disponibilidade.php — Disponibilidade de responsáveis da Agenda
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/agenda_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

$agenda = new AgendaHelper();
$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
$is_superadmin = !empty($_SESSION['perm_superadmin']);

if (!$is_superadmin) {
    header('Location: index.php?page=agenda');
    exit;
}

$_GET['page'] = 'agenda_disponibilidade';

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $resultado = $agenda->salvarDisponibilidade([
            'usuario_id' => $_POST['usuario_id'] ?? null,
            'tipo' => $_POST['tipo'] ?? 'disponivel',
            'recorrencia' => $_POST['recorrencia'] ?? 'semanal',
            'dia_semana' => $_POST['dia_semana'] ?? null,
            'data_especifica' => $_POST['data_especifica'] ?? null,
            'hora_inicio' => $_POST['hora_inicio'] ?? null,
            'hora_fim' => $_POST['hora_fim'] ?? null,
            'valido_de' => $_POST['valido_de'] ?? date('Y-m-d'),
            'valido_ate' => $_POST['valido_ate'] ?? null,
            'observacao' => $_POST['observacao'] ?? '',
            'ativo' => isset($_POST['ativo']),
            'criado_por_usuario_id' => $usuario_id,
        ]);

        if (!empty($resultado['success'])) {
            $success = 'Regra salva com sucesso.';
        } else {
            $error = $resultado['error'] ?? 'Não foi possível salvar a regra.';
        }
    }

    if ($acao === 'excluir') {
        $resultado = $agenda->excluirDisponibilidade((int)($_POST['id'] ?? 0));
        if (!empty($resultado['success'])) {
            $success = 'Regra removida com sucesso.';
        } else {
            $error = $resultado['error'] ?? 'Não foi possível remover a regra.';
        }
    }
}

$usuarios = $agenda->obterUsuariosComCores();
$regras = $agenda->obterDisponibilidades();
$dias_semana = [
    0 => 'Domingo',
    1 => 'Segunda',
    2 => 'Terça',
    3 => 'Quarta',
    4 => 'Quinta',
    5 => 'Sexta',
    6 => 'Sábado',
];

includeSidebar('Agenda');
?>

<style>
    .availability-page {
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        padding: 24px;
    }

    .availability-container {
        max-width: 1280px;
        margin: 0 auto;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 16px 34px rgba(15, 23, 42, 0.05);
        padding: 24px;
    }

    .availability-header {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        border-bottom: 1px solid #e5e7eb;
        padding-bottom: 18px;
        margin-bottom: 22px;
    }

    h1 {
        color: #1e3a8a;
        font-size: 2rem;
        margin: 0 0 8px;
    }

    .header-note {
        color: #64748b;
        margin: 0;
        max-width: 720px;
    }

    .btn {
        border: none;
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        gap: 8px;
        padding: 10px 16px;
        text-decoration: none;
    }

    .btn-primary {
        background: #1e3a8a;
        color: #fff;
    }

    .btn-outline {
        background: #fff;
        border: 1px solid #cbd5e1;
        color: #1e3a8a;
    }

    .btn-danger {
        background: #dc2626;
        color: #fff;
    }

    .alert {
        border-radius: 10px;
        font-weight: 700;
        margin-bottom: 18px;
        padding: 12px 14px;
    }

    .alert-success {
        background: #dcfce7;
        color: #166534;
    }

    .alert-error {
        background: #fee2e2;
        color: #991b1b;
    }

    .section {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        margin-bottom: 22px;
        padding: 20px;
    }

    .section h2 {
        color: #334155;
        font-size: 1.2rem;
        margin: 0 0 16px;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 14px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 7px;
    }

    label {
        color: #475569;
        font-weight: 700;
    }

    select,
    input,
    textarea {
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        color: #0f172a;
        font-size: 1rem;
        padding: 10px 12px;
        width: 100%;
    }

    textarea {
        min-height: 80px;
        resize: vertical;
    }

    .checkbox-row {
        align-items: center;
        display: flex;
        gap: 8px;
        margin-top: 28px;
    }

    .checkbox-row input {
        width: auto;
    }

    .form-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 18px;
    }

    .rules-table {
        border-collapse: collapse;
        width: 100%;
    }

    .rules-table th,
    .rules-table td {
        border-bottom: 1px solid #e2e8f0;
        padding: 12px;
        text-align: left;
        vertical-align: top;
    }

    .rules-table th {
        color: #475569;
        font-size: .85rem;
        text-transform: uppercase;
    }

    .badge {
        border-radius: 999px;
        display: inline-flex;
        font-size: .85rem;
        font-weight: 800;
        padding: 5px 10px;
    }

    .badge-ok {
        background: #dcfce7;
        color: #166534;
    }

    .badge-block {
        background: #fee2e2;
        color: #991b1b;
    }

    .muted {
        color: #64748b;
        font-size: .9rem;
    }

    @media (max-width: 760px) {
        .availability-header,
        .form-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .rules-table {
            display: block;
            overflow-x: auto;
        }
    }
</style>

<div class="availability-page">
    <div class="availability-container">
        <div class="availability-header">
            <div>
                <h1>🕒 Disponibilidade da Agenda</h1>
                <p class="header-note">
                    Cadastre os dias e horários em que cada responsável pode receber visitas. Use bloqueios para almoço, folgas, exceções ou horários que não devem ser sugeridos.
                </p>
            </div>
            <a href="index.php?page=agenda" class="btn btn-outline">← Voltar para Agenda</a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" class="section" id="availabilityForm">
            <input type="hidden" name="acao" value="salvar">
            <h2>Nova regra</h2>

            <div class="form-grid">
                <div class="form-group">
                    <label for="usuario_id">Responsável</label>
                    <select id="usuario_id" name="usuario_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($usuarios as $usuario): ?>
                            <option value="<?= (int)$usuario['id'] ?>">
                                <?= htmlspecialchars((string)($usuario['login'] ?: $usuario['nome'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="tipo">Tipo</label>
                    <select id="tipo" name="tipo" required>
                        <option value="disponivel">Disponível para visitas</option>
                        <option value="bloqueio">Bloqueio</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="recorrencia">Regra</label>
                    <select id="recorrencia" name="recorrencia" required onchange="toggleRecurrenceFields()">
                        <option value="semanal">Semanal</option>
                        <option value="data">Data específica</option>
                    </select>
                </div>

                <div class="form-group" id="weekdayGroup">
                    <label for="dia_semana">Dia da semana</label>
                    <select id="dia_semana" name="dia_semana">
                        <?php foreach ($dias_semana as $dia => $label): ?>
                            <option value="<?= $dia ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="specificDateGroup" style="display: none;">
                    <label for="data_especifica">Data específica</label>
                    <input type="date" id="data_especifica" name="data_especifica">
                </div>

                <div class="form-group">
                    <label for="hora_inicio">Início</label>
                    <input type="time" id="hora_inicio" name="hora_inicio" required>
                </div>

                <div class="form-group">
                    <label for="hora_fim">Fim</label>
                    <input type="time" id="hora_fim" name="hora_fim" required>
                </div>

                <div class="form-group">
                    <label for="valido_de">Válida a partir de</label>
                    <input type="date" id="valido_de" name="valido_de" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label for="valido_ate">Válida até</label>
                    <input type="date" id="valido_ate" name="valido_ate">
                    <span class="muted">Deixe vazio para regra sem data final.</span>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="ativo" value="1" checked>
                    Regra ativa
                </label>
            </div>

            <div class="form-group" style="margin-top: 14px;">
                <label for="observacao">Observação</label>
                <textarea id="observacao" name="observacao" placeholder="Ex.: horário de almoço, agenda especial da semana, folga, etc."></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Salvar regra</button>
            </div>
        </form>

        <div class="section">
            <h2>Regras cadastradas</h2>
            <?php if (!$regras): ?>
                <p class="muted">Nenhuma regra cadastrada ainda. Enquanto um responsável não tiver disponibilidade cadastrada, a sugestão usa apenas os eventos e bloqueios da Agenda.</p>
            <?php else: ?>
                <table class="rules-table">
                    <thead>
                        <tr>
                            <th>Responsável</th>
                            <th>Tipo</th>
                            <th>Quando</th>
                            <th>Horário</th>
                            <th>Validade</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regras as $regra): ?>
                            <?php
                                $tipo = (string)$regra['tipo'];
                                $recorrencia = (string)$regra['recorrencia'];
                                $quando = $recorrencia === 'data'
                                    ? date('d/m/Y', strtotime((string)$regra['data_especifica']))
                                    : ($dias_semana[(int)$regra['dia_semana']] ?? '-');
                                $validade = date('d/m/Y', strtotime((string)$regra['valido_de']));
                                if (!empty($regra['valido_ate'])) {
                                    $validade .= ' até ' . date('d/m/Y', strtotime((string)$regra['valido_ate']));
                                } else {
                                    $validade .= ' em diante';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars((string)($regra['usuario_login'] ?: $regra['usuario_nome'])) ?></strong>
                                    <?php if (!empty($regra['observacao'])): ?>
                                        <div class="muted"><?= htmlspecialchars((string)$regra['observacao']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?= $tipo === 'bloqueio' ? 'badge-block' : 'badge-ok' ?>">
                                        <?= $tipo === 'bloqueio' ? 'Bloqueio' : 'Disponível' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($quando) ?></td>
                                <td>
                                    <?= htmlspecialchars(substr((string)$regra['hora_inicio'], 0, 5)) ?>
                                    -
                                    <?= htmlspecialchars(substr((string)$regra['hora_fim'], 0, 5)) ?>
                                </td>
                                <td><?= htmlspecialchars($validade) ?></td>
                                <td><?= !empty($regra['ativo']) ? 'Ativa' : 'Inativa' ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Remover esta regra?');">
                                        <input type="hidden" name="acao" value="excluir">
                                        <input type="hidden" name="id" value="<?= (int)$regra['id'] ?>">
                                        <button type="submit" class="btn btn-danger">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function toggleRecurrenceFields() {
        const recurrence = document.getElementById('recorrencia').value;
        const weekdayGroup = document.getElementById('weekdayGroup');
        const specificDateGroup = document.getElementById('specificDateGroup');
        const weekday = document.getElementById('dia_semana');
        const specificDate = document.getElementById('data_especifica');

        if (recurrence === 'data') {
            weekdayGroup.style.display = 'none';
            specificDateGroup.style.display = '';
            weekday.required = false;
            specificDate.required = true;
        } else {
            weekdayGroup.style.display = '';
            specificDateGroup.style.display = 'none';
            weekday.required = true;
            specificDate.required = false;
        }
    }

    toggleRecurrenceFields();
</script>
