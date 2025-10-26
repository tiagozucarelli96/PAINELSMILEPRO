<?php
// freelancer_cadastro.php
// Cadastro rápido de freelancers

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN', 'GERENTE'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nome_completo = trim($_POST['nome_completo'] ?? '');
        $cpf = trim($_POST['cpf'] ?? '');
        $pix_tipo = $_POST['pix_tipo'] ?? '';
        $pix_chave = trim($_POST['pix_chave'] ?? '');
        $observacao = trim($_POST['observacao'] ?? '');
        
        // Validações
        if (empty($nome_completo)) {
            throw new Exception('Nome completo é obrigatório');
        }
        
        if (empty($cpf)) {
            throw new Exception('CPF é obrigatório');
        }
        
        // Limpar CPF (remover pontos e traços)
        $cpf_limpo = preg_replace('/[^0-9]/', '', $cpf);
        
        if (strlen($cpf_limpo) !== 11) {
            throw new Exception('CPF deve ter 11 dígitos');
        }
        
        if (empty($pix_tipo) || !in_array($pix_tipo, ['cpf', 'cnpj', 'email', 'celular', 'aleatoria'])) {
            throw new Exception('Tipo de PIX inválido');
        }
        
        if (empty($pix_chave)) {
            throw new Exception('Chave PIX é obrigatória');
        }
        
        // Validar PIX baseado no tipo
        switch ($pix_tipo) {
            case 'cpf':
                if (strlen($pix_chave) !== 11 || !ctype_digit($pix_chave)) {
                    throw new Exception('PIX CPF deve ter 11 dígitos');
                }
                break;
            case 'cnpj':
                if (strlen($pix_chave) !== 14 || !ctype_digit($pix_chave)) {
                    throw new Exception('PIX CNPJ deve ter 14 dígitos');
                }
                break;
            case 'email':
                if (!filter_var($pix_chave, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('PIX e-mail inválido');
                }
                break;
            case 'celular':
                $celular_limpo = preg_replace('/[^0-9]/', '', $pix_chave);
                if (strlen($celular_limpo) < 10 || strlen($celular_limpo) > 11) {
                    throw new Exception('PIX celular deve ter 10 ou 11 dígitos');
                }
                break;
            case 'aleatoria':
                if (strlen($pix_chave) < 32 || strlen($pix_chave) > 36) {
                    throw new Exception('PIX aleatória deve ter entre 32 e 36 caracteres');
                }
                break;
        }
        
        // Verificar se CPF já existe
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM lc_freelancers WHERE cpf = ?");
        $stmt->execute([$cpf_limpo]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Já existe um freelancer com este CPF');
        }
        
        // Inserir freelancer
        $stmt = $pdo->prepare("
            INSERT INTO lc_freelancers 
            (nome_completo, cpf, pix_tipo, pix_chave, observacao, criado_por)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $nome_completo,
            $cpf_limpo,
            $pix_tipo,
            $pix_chave,
            $observacao ?: null,
            $_SESSION['usuario_id'] ?? null
        ]);
        
        $freelancer_id = $pdo->lastInsertId();
        
        header('Location: pagamentos_solicitar.php?sucesso=freelancer_cadastrado&id=' . $freelancer_id);
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novo Freelancer - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .form-container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .pix-tipo-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .pix-tipo-info strong {
            color: #1e40af;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="smile-card">
            <div class="smile-card-header">
                <h1>👨‍💼 Novo Freelancer</h1>
                <p>Cadastro rápido de freelancer para pagamentos</p>
            </div>
            
            <div class="smile-card-body">
                <!-- Mensagens -->
                <?php if ($sucesso): ?>
                    <div class="smile-alert smile-alert-success">
                        ✅ <?= htmlspecialchars($sucesso) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($erro): ?>
                    <div class="smile-alert smile-alert-danger">
                        ❌ <?= htmlspecialchars($erro) ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="smile-form-group">
                        <label for="nome_completo">Nome Completo *</label>
                        <input type="text" name="nome_completo" id="nome_completo" 
                               class="smile-form-control" required
                               value="<?= htmlspecialchars($_POST['nome_completo'] ?? '') ?>">
                    </div>
                    
                    <div class="smile-form-group">
                        <label for="cpf">CPF *</label>
                        <input type="text" name="cpf" id="cpf" 
                               class="smile-form-control" required
                               placeholder="000.000.000-00"
                               value="<?= htmlspecialchars($_POST['cpf'] ?? '') ?>">
                    </div>
                    
                    <div class="smile-form-group">
                        <label for="pix_tipo">Tipo de PIX *</label>
                        <select name="pix_tipo" id="pix_tipo" class="smile-form-control" required>
                            <option value="">Selecione o tipo</option>
                            <option value="cpf" <?= ($_POST['pix_tipo'] ?? '') === 'cpf' ? 'selected' : '' ?>>CPF</option>
                            <option value="cnpj" <?= ($_POST['pix_tipo'] ?? '') === 'cnpj' ? 'selected' : '' ?>>CNPJ</option>
                            <option value="email" <?= ($_POST['pix_tipo'] ?? '') === 'email' ? 'selected' : '' ?>>E-mail</option>
                            <option value="celular" <?= ($_POST['pix_tipo'] ?? '') === 'celular' ? 'selected' : '' ?>>Celular</option>
                            <option value="aleatoria" <?= ($_POST['pix_tipo'] ?? '') === 'aleatoria' ? 'selected' : '' ?>>Chave Aleatória</option>
                        </select>
                        <div class="pix-tipo-info" id="pix-tipo-info" style="display: none;">
                            <strong>Formato esperado:</strong> <span id="pix-format"></span>
                        </div>
                    </div>
                    
                    <div class="smile-form-group">
                        <label for="pix_chave">Chave PIX *</label>
                        <input type="text" name="pix_chave" id="pix_chave" 
                               class="smile-form-control" required
                               value="<?= htmlspecialchars($_POST['pix_chave'] ?? '') ?>">
                    </div>
                    
                    <div class="smile-form-group">
                        <label for="observacao">Observação (opcional)</label>
                        <textarea name="observacao" id="observacao" rows="3" 
                                  class="smile-form-control"
                                  placeholder="Informações adicionais sobre o freelancer"><?= htmlspecialchars($_POST['observacao'] ?? '') ?></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                        <a href="pagamentos_solicitar.php" class="smile-btn smile-btn-secondary">
                            Cancelar
                        </a>
                        <button type="submit" class="smile-btn smile-btn-primary">
                            💾 Cadastrar Freelancer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Máscara para CPF
        document.getElementById('cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 11) {
                value = value.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
                e.target.value = value;
            }
        });
        
        // Informações sobre tipos de PIX
        const pixInfo = {
            'cpf': '11 dígitos (ex: 12345678901)',
            'cnpj': '14 dígitos (ex: 12345678000195)',
            'email': 'E-mail válido (ex: joao@email.com)',
            'celular': '10 ou 11 dígitos (ex: 11987654321)',
            'aleatoria': '32-36 caracteres alfanuméricos'
        };
        
        document.getElementById('pix_tipo').addEventListener('change', function() {
            const tipo = this.value;
            const infoDiv = document.getElementById('pix-tipo-info');
            const formatSpan = document.getElementById('pix-format');
            
            if (tipo && pixInfo[tipo]) {
                formatSpan.textContent = pixInfo[tipo];
                infoDiv.style.display = 'block';
            } else {
                infoDiv.style.display = 'none';
            }
        });
        
        // Validação de PIX baseada no tipo
        document.getElementById('pix_chave').addEventListener('input', function() {
            const tipo = document.getElementById('pix_tipo').value;
            const chave = this.value;
            
            if (!tipo) return;
            
            let isValid = true;
            let message = '';
            
            switch (tipo) {
                case 'cpf':
                    const cpfDigits = chave.replace(/\D/g, '');
                    if (cpfDigits.length > 0 && cpfDigits.length !== 11) {
                        isValid = false;
                        message = 'CPF deve ter 11 dígitos';
                    }
                    break;
                case 'cnpj':
                    const cnpjDigits = chave.replace(/\D/g, '');
                    if (cnpjDigits.length > 0 && cnpjDigits.length !== 14) {
                        isValid = false;
                        message = 'CNPJ deve ter 14 dígitos';
                    }
                    break;
                case 'email':
                    if (chave && !chave.includes('@')) {
                        isValid = false;
                        message = 'E-mail deve conter @';
                    }
                    break;
                case 'celular':
                    const celularDigits = chave.replace(/\D/g, '');
                    if (celularDigits.length > 0 && (celularDigits.length < 10 || celularDigits.length > 11)) {
                        isValid = false;
                        message = 'Celular deve ter 10 ou 11 dígitos';
                    }
                    break;
                case 'aleatoria':
                    if (chave.length > 0 && (chave.length < 32 || chave.length > 36)) {
                        isValid = false;
                        message = 'Chave aleatória deve ter entre 32 e 36 caracteres';
                    }
                    break;
            }
            
            // Mostrar feedback visual
            if (chave && !isValid) {
                this.style.borderColor = '#dc2626';
                this.style.backgroundColor = '#fef2f2';
            } else {
                this.style.borderColor = '';
                this.style.backgroundColor = '';
            }
        });
    </script>
</body>
</html>
