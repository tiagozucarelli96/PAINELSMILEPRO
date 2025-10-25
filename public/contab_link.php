<?php
// contab_link.php
// Portal público da contabilidade

session_start();
require_once __DIR__ . '/conexao.php';

$token = $_GET['t'] ?? '';
$erro = '';
$sucesso = '';

// Verificar token
$token_valido = false;
$token_info = null;

if ($token) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM contab_tokens WHERE token = ? AND ativo = TRUE");
        $stmt->execute([$token]);
        $token_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($token_info) {
            $token_valido = true;
            // Atualizar último uso
            $stmt = $pdo->prepare("UPDATE contab_tokens SET ultimo_uso = NOW() WHERE token = ?");
            $stmt->execute([$token]);
        }
    } catch (Exception $e) {
        $erro = "Erro ao verificar token: " . $e->getMessage();
    }
}

// Processar envio de documento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token_valido) {
    try {
        // Verificar rate limit
        $ip_origem = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("SELECT contab_verificar_rate_limit(?, ?)");
        $stmt->execute([$ip_origem, $token]);
        $rate_limit_ok = $stmt->fetchColumn();
        
        if (!$rate_limit_ok) {
            throw new Exception("Limite de envios excedido. Tente novamente em 1 hora.");
        }
        
        $descricao = $_POST['descricao'] ?? '';
        $tipo = $_POST['tipo'] ?? '';
        $competencia = $_POST['competencia'] ?? '';
        $fornecedor = $_POST['fornecedor'] ?? '';
        $parcelado = $_POST['parcelado'] === 'sim';
        
        if (!$descricao || !$tipo || !$competencia) {
            throw new Exception("Descrição, tipo e competência são obrigatórios.");
        }
        
        // Criar documento
        $stmt = $pdo->prepare("
            INSERT INTO contab_documentos (tipo, descricao, competencia, origem, fornecedor_sugerido)
            VALUES (?, ?, ?, 'portal_contab', ?)
        ");
        $stmt->execute([$tipo, $descricao, $competencia, $fornecedor]);
        $documento_id = $pdo->lastInsertId();
        
        // Criar parcelas se parcelado
        if ($parcelado) {
            $parcelas = $_POST['parcelas'] ?? [];
            foreach ($parcelas as $i => $parcela) {
                if ($parcela['vencimento'] && $parcela['valor']) {
                    $stmt = $pdo->prepare("
                        INSERT INTO contab_parcelas (documento_id, numero_parcela, total_parcelas, vencimento, valor, linha_digitavel)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $documento_id,
                        $i + 1,
                        count($parcelas),
                        $parcela['vencimento'],
                        $parcela['valor'],
                        $parcela['linha_digitavel'] ?? null
                    ]);
                }
            }
        } else {
            // Parcela única
            $vencimento = $_POST['vencimento'] ?? '';
            $valor = $_POST['valor'] ?? 0;
            $linha_digitavel = $_POST['linha_digitavel'] ?? '';
            
            if ($vencimento && $valor) {
                $stmt = $pdo->prepare("
                    INSERT INTO contab_parcelas (documento_id, numero_parcela, total_parcelas, vencimento, valor, linha_digitavel)
                    VALUES (?, 1, 1, ?, ?, ?)
                ");
                $stmt->execute([$documento_id, $vencimento, $valor, $linha_digitavel]);
            }
        }
        
        // Processar anexos se houver
        if (isset($_FILES['anexos']) && $_FILES['anexos']['error'][0] !== UPLOAD_ERR_NO_FILE) {
            require_once __DIR__ . '/lc_anexos_helper.php';
            $anexos_manager = new LcAnexosManager($pdo);
            $anexos_result = $anexos_manager->processarUploadContab($_FILES['anexos'], $documento_id);
        }
        
        $sucesso = "Documento enviado com sucesso! ID: $documento_id";
        
    } catch (Exception $e) {
        $erro = "Erro ao enviar documento: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Contábil - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .portal-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .portal-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 16px;
        }
        
        .portal-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin: 0 0 10px 0;
        }
        
        .portal-subtitle {
            font-size: 1.2rem;
            opacity: 0.9;
            margin: 0;
        }
        
        .portal-form {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
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
        
        .parcelas-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .parcela-item {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 15px;
            align-items: end;
            margin-bottom: 15px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        
        .parcela-numero {
            font-weight: 500;
            color: #1e40af;
            margin-bottom: 5px;
        }
        
        .remove-parcela {
            background: #fee2e2;
            color: #991b1b;
            border: none;
            border-radius: 4px;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .remove-parcela:hover {
            background: #fecaca;
        }
        
        .file-upload {
            position: relative;
            display: inline-block;
            width: 100%;
        }
        
        .file-input {
            position: absolute;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .file-label {
            display: block;
            padding: 20px;
            background: #f8fafc;
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .file-label:hover {
            background: #f1f5f9;
            border-color: #1e40af;
        }
        
        .file-label.dragover {
            background: #eff6ff;
            border-color: #1e40af;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
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
        
        .error-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .error-title {
            font-weight: 600;
            color: #dc2626;
            margin-bottom: 5px;
        }
        
        .error-text {
            font-size: 14px;
            color: #374151;
        }
    </style>
</head>
<body>
    <div class="portal-container">
        <!-- Header -->
        <div class="portal-header">
            <h1 class="portal-title">📑 Portal Contábil</h1>
            <p class="portal-subtitle">Envie documentos e boletos para análise</p>
        </div>
        
        <?php if (!$token_valido): ?>
        <!-- Token inválido ou ausente -->
        <div class="error-box">
            <div class="error-title">❌ Portal Inválido</div>
            <div class="error-text">
                <?php if (empty($token)): ?>
                    <strong>Token não fornecido.</strong><br>
                    Acesse o portal através do link correto fornecido pelo administrador.<br><br>
                    <strong>Link de teste:</strong><br>
                    <a href="?t=teste123456789" style="color: #1e40af; text-decoration: underline;">
                        https://painelsmilepro-production.up.railway.app/contab_link.php?t=teste123456789
                    </a>
                <?php else: ?>
                    O link de acesso é inválido ou foi desativado. 
                    Entre em contato com o administrador do sistema.
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        
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
        <form method="POST" enctype="multipart/form-data" class="portal-form">
            <!-- Informações Básicas -->
            <div class="form-section">
                <h3 class="section-title">📋 Informações do Documento</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Descrição *</label>
                        <input type="text" name="descricao" class="form-input" 
                               placeholder="Ex: Imposto de Renda 2024" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Tipo *</label>
                        <select name="tipo" class="form-input" required>
                            <option value="">Selecione o tipo</option>
                            <option value="imposto">Imposto</option>
                            <option value="guia">Guia</option>
                            <option value="honorario">Honorário</option>
                            <option value="parcelamento">Parcelamento</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Competência (YYYY-MM) *</label>
                        <input type="text" name="competencia" class="form-input" 
                               placeholder="2024-01" pattern="\d{4}-\d{2}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Fornecedor/Entidade</label>
                        <input type="text" name="fornecedor" class="form-input" 
                               placeholder="Ex: Receita Federal, Prefeitura...">
                    </div>
                </div>
            </div>
            
            <!-- Parcelamento -->
            <div class="form-section">
                <h3 class="section-title">💰 Parcelamento</h3>
                <div class="form-group">
                    <label>
                        <input type="radio" name="parcelado" value="nao" checked onchange="toggleParcelas(false)">
                        Documento único
                    </label>
                    <label style="margin-left: 20px;">
                        <input type="radio" name="parcelado" value="sim" onchange="toggleParcelas(true)">
                        Documento parcelado
                    </label>
                </div>
                
                <!-- Parcela única -->
                <div id="parcela-unica">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Data de Vencimento</label>
                            <input type="date" name="vencimento" class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Valor (R$)</label>
                            <input type="number" name="valor" class="form-input" step="0.01" min="0">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Linha Digitável (opcional)</label>
                            <input type="text" name="linha_digitavel" class="form-input" 
                                   placeholder="Código de barras do boleto">
                        </div>
                    </div>
                </div>
                
                <!-- Parcelas múltiplas -->
                <div id="parcelas-multiplas" style="display: none;">
                    <div class="parcelas-section">
                        <div id="parcelas-container">
                            <!-- Parcelas serão adicionadas aqui -->
                        </div>
                        <button type="button" onclick="adicionarParcela()" class="smile-btn smile-btn-outline">
                            + Adicionar Parcela
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Anexos -->
            <div class="form-section">
                <h3 class="section-title">📎 Anexos</h3>
                <div class="file-upload">
                    <input type="file" name="anexos[]" class="file-input" 
                           accept=".pdf,.jpg,.jpeg,.png" multiple>
                    <label for="anexos" class="file-label">
                        📎 Clique aqui ou arraste os arquivos<br>
                        <small>PDF, JPG, PNG (máx. 10MB cada)</small>
                    </label>
                </div>
            </div>
            
            <!-- Informações -->
            <div class="info-box">
                <div class="info-box-title">ℹ️ Informações Importantes</div>
                <div class="info-box-text">
                    • Todos os campos marcados com * são obrigatórios<br>
                    • Arquivos devem ser PDF, JPG ou PNG (máx. 10MB cada)<br>
                    • Você pode enviar até 5 arquivos por documento<br>
                    • O sistema possui limite de 10 envios por hora
                </div>
            </div>
            
            <!-- Ações -->
            <div class="form-actions">
                <button type="submit" class="smile-btn smile-btn-primary">
                    📤 Enviar Documento
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
    
    <script>
        let parcelaCount = 0;
        
        function toggleParcelas(parcelado) {
            const parcelaUnica = document.getElementById('parcela-unica');
            const parcelasMultiplas = document.getElementById('parcelas-multiplas');
            
            if (parcelado) {
                parcelaUnica.style.display = 'none';
                parcelasMultiplas.style.display = 'block';
                if (parcelaCount === 0) {
                    adicionarParcela();
                }
            } else {
                parcelaUnica.style.display = 'block';
                parcelasMultiplas.style.display = 'none';
            }
        }
        
        function adicionarParcela() {
            parcelaCount++;
            const container = document.getElementById('parcelas-container');
            
            const parcelaDiv = document.createElement('div');
            parcelaDiv.className = 'parcela-item';
            parcelaDiv.innerHTML = `
                <div>
                    <div class="parcela-numero">Parcela ${parcelaCount}</div>
                    <input type="date" name="parcelas[${parcelaCount}][vencimento]" class="form-input" required>
                </div>
                <div>
                    <label class="form-label">Valor (R$)</label>
                    <input type="number" name="parcelas[${parcelaCount}][valor]" class="form-input" step="0.01" min="0" required>
                </div>
                <div>
                    <label class="form-label">Linha Digitável</label>
                    <input type="text" name="parcelas[${parcelaCount}][linha_digitavel]" class="form-input" placeholder="Opcional">
                </div>
                <button type="button" class="remove-parcela" onclick="removerParcela(this)">❌</button>
            `;
            
            container.appendChild(parcelaDiv);
        }
        
        function removerParcela(button) {
            button.parentElement.remove();
        }
        
        // Drag and drop para arquivos
        const fileLabel = document.querySelector('.file-label');
        const fileInput = document.querySelector('.file-input');
        
        fileLabel.addEventListener('dragover', (e) => {
            e.preventDefault();
            fileLabel.classList.add('dragover');
        });
        
        fileLabel.addEventListener('dragleave', () => {
            fileLabel.classList.remove('dragover');
        });
        
        fileLabel.addEventListener('drop', (e) => {
            e.preventDefault();
            fileLabel.classList.remove('dragover');
            fileInput.files = e.dataTransfer.files;
        });
        
        // Validação do formulário
        document.querySelector('form').addEventListener('submit', function(e) {
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
