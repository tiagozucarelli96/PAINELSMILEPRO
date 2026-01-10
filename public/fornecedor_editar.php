<?php
// fornecedor_editar.php
// Edição/cadastro de fornecedores

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/lc_permissions_stub.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

$fornecedor_id = intval($_GET['id'] ?? 0);
$is_edit = $fornecedor_id > 0;

$sucesso = $_GET['sucesso'] ?? null;
$erro = $_GET['erro'] ?? null;

// Buscar dados do fornecedor se editando
$fornecedor = null;
if ($is_edit) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM fornecedores WHERE id = ?");
        $stmt->execute([$fornecedor_id]);
        $fornecedor = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$fornecedor) {
            header('Location: fornecedores.php?erro=fornecedor_nao_encontrado');
            exit;
        }
    } catch (Exception $e) {
        $erro = "Erro ao carregar fornecedor: " . $e->getMessage();
    }
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nome = trim($_POST['nome'] ?? '');
        $cnpj = trim($_POST['cnpj'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $contato_responsavel = trim($_POST['contato_responsavel'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        $pix_tipo = $_POST['pix_tipo'] ?? '';
        $pix_chave = trim($_POST['pix_chave'] ?? '');
        $ativo = isset($_POST['ativo']);
        
        // Validações
        if (empty($nome)) {
            throw new Exception('Nome é obrigatório');
        }
        
        // Limpar CNPJ
        if ($cnpj) {
            $cnpj_limpo = preg_replace('/[^0-9]/', '', $cnpj);
            if (strlen($cnpj_limpo) !== 14) {
                throw new Exception('CNPJ deve ter 14 dígitos');
            }
        }
        
        // Validar PIX se fornecido
        if ($pix_tipo && $pix_chave) {
            switch ($pix_tipo) {
                case 'cpf':
                    $pix_limpo = preg_replace('/[^0-9]/', '', $pix_chave);
                    if (strlen($pix_limpo) !== 11) {
                        throw new Exception('PIX CPF deve ter 11 dígitos');
                    }
                    $pix_chave = $pix_limpo;
                    break;
                case 'cnpj':
                    $pix_limpo = preg_replace('/[^0-9]/', '', $pix_chave);
                    if (strlen($pix_limpo) !== 14) {
                        throw new Exception('PIX CNPJ deve ter 14 dígitos');
                    }
                    $pix_chave = $pix_limpo;
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
                    $pix_chave = $celular_limpo;
                    break;
                case 'aleatoria':
                    if (strlen($pix_chave) < 32 || strlen($pix_chave) > 36) {
                        throw new Exception('PIX aleatória deve ter entre 32 e 36 caracteres');
                    }
                    break;
            }
        }
        
        if ($is_edit) {
            // Atualizar fornecedor
            $stmt = $pdo->prepare("
                UPDATE fornecedores 
                SET nome = ?, cnpj = ?, telefone = ?, email = ?, endereco = ?, 
                    contato_responsavel = ?, categoria = ?, observacoes = ?, 
                    pix_tipo = ?, pix_chave = ?, ativo = ?, modificado_em = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $nome,
                $cnpj ?: null,
                $telefone ?: null,
                $email ?: null,
                $endereco ?: null,
                $contato_responsavel ?: null,
                $categoria ?: null,
                $observacoes ?: null,
                $pix_tipo ?: null,
                $pix_chave ?: null,
                $ativo,
                $fornecedor_id
            ]);
            
            $sucesso = 'Fornecedor atualizado com sucesso';
        } else {
            // Criar fornecedor
            $stmt = $pdo->prepare("
                INSERT INTO fornecedores 
                (nome, cnpj, telefone, email, endereco, contato_responsavel, 
                 categoria, observacoes, pix_tipo, pix_chave, ativo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $nome,
                $cnpj ?: null,
                $telefone ?: null,
                $email ?: null,
                $endereco ?: null,
                $contato_responsavel ?: null,
                $categoria ?: null,
                $observacoes ?: null,
                $pix_tipo ?: null,
                $pix_chave ?: null,
                $ativo
            ]);
            
            $fornecedor_id = $pdo->lastInsertId();
            $sucesso = 'Fornecedor criado com sucesso';
        }
        
        header('Location: fornecedores.php?sucesso=' . urlencode($sucesso));
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
    <title><?= $is_edit ? 'Editar' : 'Novo' ?> Fornecedor - Sistema Smile</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .pix-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-top: 10px;
            font-size: 14px;
        }
        
        .pix-info strong {
            color: #1e40af;
        }
        
        .token-section {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }
        
        .token-url {
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 15px;
            font-family: monospace;
            font-size: 14px;
            word-break: break-all;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="smile-container">
        <div class="smile-card">
            <div class="smile-card-header">
                <h1><?= $is_edit ? '✏️ Editar' : '➕ Novo' ?> Fornecedor</h1>
                <p><?= $is_edit ? 'Atualize os dados do fornecedor' : 'Cadastre um novo fornecedor' ?></p>
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
                    <div class="form-section">
                        <h3>📋 Informações Básicas</h3>
                        
                        <div class="smile-form-group">
                            <label for="nome">Nome/Razão Social *</label>
                            <input type="text" name="nome" id="nome" 
                                   class="smile-form-control" required
                                   value="<?= htmlspecialchars($fornecedor['nome'] ?? $_POST['nome'] ?? '') ?>">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="cnpj">CNPJ</label>
                            <input type="text" name="cnpj" id="cnpj" 
                                   class="smile-form-control" placeholder="00.000.000/0000-00"
                                   value="<?= htmlspecialchars($fornecedor['cnpj'] ?? $_POST['cnpj'] ?? '') ?>">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="telefone">Telefone/WhatsApp</label>
                            <input type="text" name="telefone" id="telefone" 
                                   class="smile-form-control" placeholder="(11) 99999-9999"
                                   value="<?= htmlspecialchars($fornecedor['telefone'] ?? $_POST['telefone'] ?? '') ?>">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="email">E-mail</label>
                            <input type="email" name="email" id="email" 
                                   class="smile-form-control"
                                   value="<?= htmlspecialchars($fornecedor['email'] ?? $_POST['email'] ?? '') ?>">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="endereco">Endereço</label>
                            <textarea name="endereco" id="endereco" rows="3" 
                                      class="smile-form-control"><?= htmlspecialchars($fornecedor['endereco'] ?? $_POST['endereco'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="contato_responsavel">Contato Responsável</label>
                            <input type="text" name="contato_responsavel" id="contato_responsavel" 
                                   class="smile-form-control"
                                   value="<?= htmlspecialchars($fornecedor['contato_responsavel'] ?? $_POST['contato_responsavel'] ?? '') ?>">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="categoria">Categoria</label>
                            <input type="text" name="categoria" id="categoria" 
                                   class="smile-form-control" placeholder="Ex: Alimentos, Bebidas, Limpeza..."
                                   value="<?= htmlspecialchars($fornecedor['categoria'] ?? $_POST['categoria'] ?? '') ?>">
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="observacoes">Observações</label>
                            <textarea name="observacoes" id="observacoes" rows="3" 
                                      class="smile-form-control"><?= htmlspecialchars($fornecedor['observacoes'] ?? $_POST['observacoes'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="smile-form-group">
                            <label>
                                <input type="checkbox" name="ativo" value="1" 
                                       <?= ($fornecedor['ativo'] ?? true) ? 'checked' : '' ?>>
                                Fornecedor ativo
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>💰 Dados PIX</h3>
                        
                        <div class="smile-form-group">
                            <label for="pix_tipo">Tipo de PIX</label>
                            <select name="pix_tipo" id="pix_tipo" class="smile-form-control">
                                <option value="">Selecione o tipo</option>
                                <option value="cpf" <?= ($fornecedor['pix_tipo'] ?? $_POST['pix_tipo'] ?? '') === 'cpf' ? 'selected' : '' ?>>CPF</option>
                                <option value="cnpj" <?= ($fornecedor['pix_tipo'] ?? $_POST['pix_tipo'] ?? '') === 'cnpj' ? 'selected' : '' ?>>CNPJ</option>
                                <option value="email" <?= ($fornecedor['pix_tipo'] ?? $_POST['pix_tipo'] ?? '') === 'email' ? 'selected' : '' ?>>E-mail</option>
                                <option value="celular" <?= ($fornecedor['pix_tipo'] ?? $_POST['pix_tipo'] ?? '') === 'celular' ? 'selected' : '' ?>>Celular</option>
                                <option value="aleatoria" <?= ($fornecedor['pix_tipo'] ?? $_POST['pix_tipo'] ?? '') === 'aleatoria' ? 'selected' : '' ?>>Chave Aleatória</option>
                            </select>
                            <div class="pix-info" id="pix-info" style="display: none;">
                                <strong>Formato esperado:</strong> <span id="pix-format"></span>
                            </div>
                        </div>
                        
                        <div class="smile-form-group">
                            <label for="pix_chave">Chave PIX</label>
                            <input type="text" name="pix_chave" id="pix_chave" 
                                   class="smile-form-control"
                                   value="<?= htmlspecialchars($fornecedor['pix_chave'] ?? $_POST['pix_chave'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <?php if ($is_edit && $fornecedor['token_publico']): ?>
                        <div class="form-section">
                            <h3>🔗 Token Público</h3>
                            <div class="token-section">
                                <p><strong>Link público do fornecedor:</strong></p>
                                <div class="token-url" id="token-url">
                                    <?= $_SERVER['HTTP_HOST'] ?>/fornecedor_link.php?t=<?= htmlspecialchars($fornecedor['token_publico']) ?>
                                </div>
                                <button type="button" class="smile-btn smile-btn-secondary" onclick="copyToken()">
                                    📋 Copiar Link
                                </button>
                                <a href="fornecedor_regenerar_token.php?id=<?= $fornecedor_id ?>" 
                                   class="smile-btn smile-btn-warning"
                                   onclick="return confirm('Regenerar token? O link atual será invalidado.')">
                                    🔄 Regenerar Token
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                        <a href="fornecedores.php" class="smile-btn smile-btn-secondary">
                            Cancelar
                        </a>
                        <button type="submit" class="smile-btn smile-btn-primary">
                            <?= $is_edit ? '💾 Atualizar' : '➕ Criar' ?> Fornecedor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Máscara para CNPJ
        document.getElementById('cnpj').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 14) {
                value = value.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
                e.target.value = value;
            }
        });
        
        // Máscara para telefone
        document.getElementById('telefone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 11) {
                value = value.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
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
            const infoDiv = document.getElementById('pix-info');
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
            
            switch (tipo) {
                case 'cpf':
                    const cpfDigits = chave.replace(/\D/g, '');
                    if (cpfDigits.length > 0 && cpfDigits.length !== 11) {
                        isValid = false;
                    }
                    break;
                case 'cnpj':
                    const cnpjDigits = chave.replace(/\D/g, '');
                    if (cnpjDigits.length > 0 && cnpjDigits.length !== 14) {
                        isValid = false;
                    }
                    break;
                case 'email':
                    if (chave && !chave.includes('@')) {
                        isValid = false;
                    }
                    break;
                case 'celular':
                    const celularDigits = chave.replace(/\D/g, '');
                    if (celularDigits.length > 0 && (celularDigits.length < 10 || celularDigits.length > 11)) {
                        isValid = false;
                    }
                    break;
                case 'aleatoria':
                    if (chave.length > 0 && (chave.length < 32 || chave.length > 36)) {
                        isValid = false;
                    }
                    break;
            }
            
            // Feedback visual
            if (chave && !isValid) {
                this.style.borderColor = '#dc2626';
                this.style.backgroundColor = '#fef2f2';
            } else {
                this.style.borderColor = '';
                this.style.backgroundColor = '';
            }
        });
        
        function copyToken() {
            const tokenUrl = document.getElementById('token-url').textContent;
            
            navigator.clipboard.writeText(tokenUrl).then(function() {
                alert('Link copiado para a área de transferência!');
            }).catch(function(err) {
                alert('Erro ao copiar: ' + err);
            });
        }
    </script>
</body>
</html>
