<?php
/**
 * config_eventos_tipos.php
 * Administração dos tipos reais de evento usados em Eventos > Organização.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';

if (empty($_SESSION['perm_configuracoes']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

function config_eventos_tipos_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$messages = [];
$errors = [];

try {
    eventos_reuniao_tipos_evento_real_ensure_schema($pdo);
} catch (Throwable $e) {
    $errors[] = 'Não foi possível preparar a base de tipos de evento: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $defaults = eventos_reuniao_tipos_evento_real_defaults();
    $tiposAtuais = eventos_reuniao_tipos_evento_real_listar($pdo, true);
    $labels = $_POST['label'] ?? [];
    $ordens = $_POST['ordem'] ?? [];
    $ativos = $_POST['ativo'] ?? [];
    $novoTipoLabel = trim((string)($_POST['novo_tipo_label'] ?? ''));

    $ativosCount = 0;
    $payload = [];

    foreach ($tiposAtuais as $tipoAtual) {
        $tipoKey = (string)($tipoAtual['tipo_key'] ?? '');
        if ($tipoKey === '') {
            continue;
        }

        $label = trim((string)($labels[$tipoKey] ?? ''));
        $ordem = isset($ordens[$tipoKey]) ? (int)$ordens[$tipoKey] : (int)($defaults[$tipoKey]['ordem'] ?? 0);
        $ativo = isset($ativos[$tipoKey]) && (string)$ativos[$tipoKey] === '1';

        if ($label === '') {
            $errors[] = 'Informe o nome de exibição do tipo "' . $tipoKey . '".';
            continue;
        }

        if ($ativo) {
            $ativosCount++;
        }

        $payload[$tipoKey] = [
            'label' => mb_substr($label, 0, 80),
            'ordem' => $ordem,
            'ativo' => $ativo,
        ];
    }

    if ($novoTipoLabel !== '') {
        $novoTipoKey = eventos_reuniao_tipo_evento_real_slug($novoTipoLabel);
        if ($novoTipoKey === '') {
            $errors[] = 'Não foi possível gerar um código válido para o novo tipo.';
        } elseif (isset($payload[$novoTipoKey])) {
            $errors[] = 'Já existe um tipo com esse nome/código.';
        } else {
            $payload[$novoTipoKey] = [
                'label' => mb_substr($novoTipoLabel, 0, 80),
                'ordem' => empty($payload) ? 10 : (max(array_column($payload, 'ordem')) + 10),
                'ativo' => true,
            ];
            $ativosCount++;
        }
    }

    if (!$errors && $ativosCount <= 0) {
        $errors[] = 'Mantenha pelo menos um tipo de evento ativo.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("
            INSERT INTO eventos_tipos_reais_config (tipo_key, label, descricao, ativo, ordem, updated_at)
            VALUES (:tipo_key, :label, NULL, :ativo, :ordem, NOW())
            ON CONFLICT (tipo_key) DO UPDATE SET
                label = EXCLUDED.label,
                descricao = NULL,
                ativo = EXCLUDED.ativo,
                ordem = EXCLUDED.ordem,
                updated_at = NOW()
        ");

        foreach ($payload as $tipoKey => $item) {
            $stmt->execute([
                ':tipo_key' => $tipoKey,
                ':label' => $item['label'],
                ':ativo' => $item['ativo'],
                ':ordem' => $item['ordem'],
            ]);
        }

        $messages[] = 'Tipos de evento atualizados com sucesso.';
    }
}

$tipos = [];
if (empty($errors)) {
    $tipos = eventos_reuniao_tipos_evento_real_listar($pdo, true);
}

includeSidebar('Configurações - Tipos de Evento');
?>

<style>
.config-eventos-page {
    max-width: 1160px;
    margin: 0 auto;
    padding: 1.5rem;
}

.config-eventos-header {
    margin-bottom: 1.5rem;
}

.config-eventos-title {
    margin: 0;
    font-size: 1.85rem;
    font-weight: 700;
    color: #1e3a8a;
}

.config-eventos-subtitle {
    margin: 0.45rem 0 0;
    color: #64748b;
    font-size: 0.96rem;
}

.config-eventos-note,
.config-eventos-panel {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.05);
}

.config-eventos-note {
    padding: 1rem 1.1rem;
    margin-bottom: 1rem;
    color: #334155;
}

.config-eventos-panel {
    padding: 1.3rem;
}

.config-eventos-feedback {
    border-radius: 10px;
    padding: 0.85rem 1rem;
    margin-bottom: 1rem;
    font-size: 0.94rem;
}

.config-eventos-feedback.success {
    background: #ecfdf5;
    border: 1px solid #86efac;
    color: #166534;
}

.config-eventos-feedback.error {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    color: #991b1b;
}

.config-eventos-grid {
    display: grid;
    gap: 1rem;
}

.config-eventos-card {
    border: 1px solid #dbe3ef;
    border-radius: 12px;
    padding: 1rem;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.config-eventos-card-header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    margin-bottom: 0.9rem;
}

.config-eventos-badge {
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #1d4ed8;
    background: #dbeafe;
    padding: 0.35rem 0.6rem;
    border-radius: 999px;
}

.config-eventos-status {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    color: #334155;
    font-size: 0.92rem;
}

.config-eventos-fields {
    display: grid;
    grid-template-columns: minmax(220px, 1.6fr) minmax(180px, 2fr) 110px;
    gap: 0.9rem;
}

.config-eventos-field label {
    display: block;
    margin-bottom: 0.35rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: #334155;
}

.config-eventos-field input[type="text"],
.config-eventos-field input[type="number"] {
    width: 100%;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 0.72rem 0.8rem;
    font-size: 0.94rem;
    color: #0f172a;
    background: #fff;
}

.config-eventos-actions {
    margin-top: 1.2rem;
    display: flex;
    justify-content: flex-end;
}

.config-eventos-add {
    margin-bottom: 1.2rem;
    border: 1px dashed #93c5fd;
    border-radius: 12px;
    padding: 1rem;
    background: #f8fbff;
}

.config-eventos-add-title {
    margin: 0 0 0.8rem;
    font-size: 1rem;
    font-weight: 700;
    color: #1e3a8a;
}

.btn-save {
    border: none;
    border-radius: 10px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    font-weight: 700;
    padding: 0.78rem 1.2rem;
    cursor: pointer;
}

@media (max-width: 900px) {
    .config-eventos-fields {
        grid-template-columns: 1fr;
    }

    .config-eventos-card-header {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<div class="config-eventos-page">
    <div class="config-eventos-header">
        <h1 class="config-eventos-title">Tipos de evento</h1>
        <p class="config-eventos-subtitle">Administra os tipos usados em Eventos &gt; Organização. Os códigos internos são fixos para preservar as regras do sistema.</p>
    </div>

    <div class="config-eventos-note">
        Esses tipos aparecem na seleção de <strong>tipo real do evento</strong> em <code>eventos_organizacao</code>. Aqui você pode ajustar nome de exibição, ordem, ativação e adicionar novos tipos.
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="config-eventos-feedback success"><?= config_eventos_tipos_h($message) ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $error): ?>
        <div class="config-eventos-feedback error"><?= config_eventos_tipos_h($error) ?></div>
    <?php endforeach; ?>

    <div class="config-eventos-panel">
        <form method="post">
            <section class="config-eventos-add">
                <h2 class="config-eventos-add-title">Adicionar tipo</h2>
                <div class="config-eventos-fields">
                    <div class="config-eventos-field">
                        <label for="novo_tipo_label">Nome exibido</label>
                        <input
                            type="text"
                            id="novo_tipo_label"
                            name="novo_tipo_label"
                            maxlength="80"
                            placeholder="Ex.: Corporativo"
                        >
                    </div>

                    <div class="config-eventos-field">
                        <label>Código interno</label>
                        <input
                            type="text"
                            value="Gerado automaticamente pelo nome"
                            disabled
                        >
                    </div>

                    <div class="config-eventos-field">
                        <label>Status</label>
                        <input
                            type="text"
                            value="Novo tipo entra ativo"
                            disabled
                        >
                    </div>
                </div>
            </section>

            <div class="config-eventos-grid">
                <?php foreach ($tipos as $tipo): ?>
                    <?php
                    $tipoKey = (string)($tipo['tipo_key'] ?? '');
                    $checked = !empty($tipo['ativo']);
                    ?>
                    <section class="config-eventos-card">
                        <div class="config-eventos-card-header">
                            <div class="config-eventos-badge"><?= config_eventos_tipos_h($tipoKey) ?></div>
                            <label class="config-eventos-status">
                                <input type="checkbox" name="ativo[<?= config_eventos_tipos_h($tipoKey) ?>]" value="1" <?= $checked ? 'checked' : '' ?>>
                                Tipo ativo
                            </label>
                        </div>

                        <div class="config-eventos-fields">
                            <div class="config-eventos-field">
                                <label for="label_<?= config_eventos_tipos_h($tipoKey) ?>">Nome exibido</label>
                                <input
                                    type="text"
                                    id="label_<?= config_eventos_tipos_h($tipoKey) ?>"
                                    name="label[<?= config_eventos_tipos_h($tipoKey) ?>]"
                                    maxlength="80"
                                    value="<?= config_eventos_tipos_h((string)($tipo['label'] ?? '')) ?>"
                                    required
                                >
                            </div>

                            <div class="config-eventos-field">
                                <label for="ordem_<?= config_eventos_tipos_h($tipoKey) ?>">Ordem</label>
                                <input
                                    type="number"
                                    id="ordem_<?= config_eventos_tipos_h($tipoKey) ?>"
                                    name="ordem[<?= config_eventos_tipos_h($tipoKey) ?>]"
                                    value="<?= (int)($tipo['ordem'] ?? 0) ?>"
                                >
                            </div>

                            <div class="config-eventos-field">
                                <label>Código interno</label>
                                <input
                                    type="text"
                                    value="<?= config_eventos_tipos_h($tipoKey) ?>"
                                    disabled
                                >
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="config-eventos-actions">
                <button type="submit" class="btn-save">Salvar tipos de evento</button>
            </div>
        </form>
    </div>
</div>

<?php
endSidebar();
