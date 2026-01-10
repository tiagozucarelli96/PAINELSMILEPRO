<?php
// rh_holerite_upload.php
// Lançar holerites em lote (ADM/RH)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/lc_permissions_stub.php';
require_once __DIR__ . '/lc_anexos_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$sucesso = '';
$erro = '';

// Processar upload em lote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'upload_lote') {
    try {
        $competencia = $_POST['competencia'] ?? '';
        $colaboradores_selecionados = $_POST['colaboradores'] ?? [];
        
        if (!$competencia || empty($colaboradores_selecionados)) {
            throw new Exception('Competência e colaboradores são obrigatórios');
        }
        
        $anexos_manager = new LcAnexosManager($pdo);
        $holerites_criados = 0;
        
        foreach ($colaboradores_selecionados as $colaborador_id) {
            // Verificar se já existe holerite para este colaborador e competência
            $stmt = $pdo->prepare("SELECT id FROM rh_holerites WHERE usuario_id = ? AND mes_competencia = ?");
            $stmt->execute([$colaborador_id, $competencia]);
            if ($stmt->fetch()) {
                continue; // Pular se já existe
            }
            
            // Criar holerite
            $stmt = $pdo->prepare("
                INSERT INTO rh_holerites (usuario_id, mes_competencia, valor_liquido, observacao, criado_por)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $colaborador_id,
                $competencia,
                $_POST['valor_' . $colaborador_id] ?? null,
                $_POST['obs_' . $colaborador_id] ?? null,
                $_SESSION['user_id']
            ]);
            
            $holerite_id = $pdo->lastInsertId();
            
            // Processar anexos se houver
            if (isset($_FILES['anexos_' . $colaborador_id]) && $_FILES['anexos_' . $colaborador_id]['error'][0] !== UPLOAD_ERR_NO_FILE) {
                $anexos_result = $anexos_manager->processarUploadRH(
                    $_FILES['anexos_' . $colaborador_id],
                    $holerite_id,
                    $colaborador_id,
                    'holerite'
                );
            }
            
            $holerites_criados++;
        }
        
        $sucesso = "Holerites lançados com sucesso! $holerites_criados holerites criados para a competência $competencia.";
        
    } catch (Exception $e) {
        $erro = "Erro ao lançar holerites: " . $e->getMessage();
    }
}

// Buscar colaboradores ativos
$colaboradores = [];
try {
    $stmt = $pdo->query("
        SELECT id, nome, cargo, status_empregado
        FROM usuarios 
        WHERE ativo = true AND status_empregado = 'ativo'
        ORDER BY nome
    ");
    $colaboradores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar colaboradores: " . $e->getMessage();
}

// Competência atual (mês anterior)
$competencia_atual = date('Y-m', strtotime('-1 month'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lançar Holerites - RH</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .rh-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .rh-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 16px;
        }
        
        .rh-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 10px 0;
        }
        
        .rh-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin: 0;
        }
        
        .upload-form {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .form-input {
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        
        .colaboradores-grid {
            display: grid;
            gap: 15px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
        }
        
        .colaborador-item {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 15px;
            align-items: center;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .colaborador-checkbox {
            width: 18px;
            height: 18px;
        }
        
        .colaborador-info {
            flex: 1;
        }
        
        .colaborador-nome {
            font-weight: 500;
            color: #374151;
            margin-bottom: 2px;
        }
        
        .colaborador-cargo {
            font-size: 12px;
            color: #64748b;
        }
        
        .colaborador-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .field-group {
            display: flex;
            flex-direction: column;
        }
        
        .field-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 2px;
        }
        
        .field-input {
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .file-upload {
            position: relative;
            display: inline-block;
        }
        
        .file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-label {
            display: inline-block;
            padding: 8px 12px;
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            color: #374151;
        }
        
        .file-label:hover {
            background: #e5e7eb;
        }
        
        .actions-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .select-all-section {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
        }
        
        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .info-box-title {
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 5px;
        }
        
        .info-box-text {
            font-size: 14px;
            color: #374151;
        }
    </style>
</head>
<body>
    <div class="rh-container">
        <!-- Header -->
        <div class="rh-header">
            <h1 class="rh-title">💰 Lançar Holerites</h1>
            <p class="rh-subtitle">Upload em lote de holerites para colaboradores</p>
        </div>
        
        <!-- Mensagens -->
        <?php if ($sucesso): ?>
        <div class="smile-alert smile-alert-success">
            <strong>✅ Sucesso!</strong> <?= htmlspecialchars($sucesso) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
        <div class="smile-alert smile-alert-danger">
            <strong>❌ Erro!</strong> <?= htmlspecialchars($erro) ?>
        </div>
        <?php endif; ?>
        
        <!-- Formulário -->
        <form method="POST" enctype="multipart/form-data" class="upload-form">
            <input type="hidden" name="acao" value="upload_lote">
            
            <!-- Informações Gerais -->
            <div class="form-section">
                <h3 class="section-title">📋 Informações Gerais</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Competência (YYYY-MM)</label>
                        <input type="text" name="competencia" value="<?= $competencia_atual ?>" 
                               class="form-input" placeholder="2024-01" required>
                    </div>
                </div>
            </div>
            
            <!-- Seleção de Colaboradores -->
            <div class="form-section">
                <h3 class="section-title">👥 Colaboradores</h3>
                
                <div class="info-box">
                    <div class="info-box-title">ℹ️ Como funciona</div>
                    <div class="info-box-text">
                        Selecione os colaboradores que receberão holerites. Para cada colaborador selecionado, 
                        você pode informar o valor líquido, observações e anexar o arquivo do holerite.
                    </div>
                </div>
                
                <div class="colaboradores-grid">
                    <?php foreach ($colaboradores as $colaborador): ?>
                    <div class="colaborador-item">
                        <input type="checkbox" name="colaboradores[]" value="<?= $colaborador['id'] ?>" 
                               class="colaborador-checkbox" id="colab_<?= $colaborador['id'] ?>">
                        
                        <div class="colaborador-info">
                            <div class="colaborador-nome"><?= htmlspecialchars($colaborador['nome']) ?></div>
                            <div class="colaborador-cargo"><?= htmlspecialchars($colaborador['cargo'] ?? 'Cargo não informado') ?></div>
                        </div>
                        
                        <div class="colaborador-fields">
                            <div class="field-group">
                                <label class="field-label">Valor Líquido (R$)</label>
                                <input type="number" name="valor_<?= $colaborador['id'] ?>" 
                                       class="field-input" step="0.01" placeholder="0,00">
                            </div>
                            <div class="field-group">
                                <label class="field-label">Observação</label>
                                <input type="text" name="obs_<?= $colaborador['id'] ?>" 
                                       class="field-input" placeholder="Opcional">
                            </div>
                            <div class="field-group">
                                <label class="field-label">Anexar Holerite</label>
                                <div class="file-upload">
                                    <input type="file" name="anexos_<?= $colaborador['id'] ?>[]" 
                                           class="file-input" id="file_<?= $colaborador['id'] ?>" 
                                           accept=".pdf,.jpg,.jpeg,.png" multiple>
                                    <label for="file_<?= $colaborador['id'] ?>" class="file-label">
                                        📎 Escolher arquivos
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Ações -->
            <div class="actions-section">
                <div class="select-all-section">
                    <input type="checkbox" id="select_all" class="colaborador-checkbox">
                    <label for="select_all">Selecionar todos</label>
                </div>
                
                <div class="form-actions">
                    <a href="rh_dashboard.php" class="smile-btn smile-btn-outline">← Cancelar</a>
                    <button type="submit" class="smile-btn smile-btn-primary">
                        📤 Lançar Holerites
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <script>
        // Selecionar todos
        document.getElementById('select_all').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="colaboradores[]"]');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        
        // Atualizar checkbox "Selecionar todos"
        document.querySelectorAll('input[name="colaboradores[]"]').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const allCheckboxes = document.querySelectorAll('input[name="colaboradores[]"]');
                const checkedCheckboxes = document.querySelectorAll('input[name="colaboradores[]"]:checked');
                
                document.getElementById('select_all').checked = 
                    allCheckboxes.length === checkedCheckboxes.length;
            });
        });
        
        // Validação do formulário
        document.querySelector('form').addEventListener('submit', function(e) {
            const colaboradoresSelecionados = document.querySelectorAll('input[name="colaboradores[]"]:checked');
            
            if (colaboradoresSelecionados.length === 0) {
                e.preventDefault();
                alert('Selecione pelo menos um colaborador para lançar holerites.');
                return;
            }
            
            const competencia = document.querySelector('input[name="competencia"]').value;
            if (!competencia.match(/^\d{4}-\d{2}$/)) {
                e.preventDefault();
                alert('A competência deve estar no formato YYYY-MM (ex: 2024-01).');
                return;
            }
        });
    </script>
</body>
</html>
