<?php
/**
 * vendas_form_base.php
 * Página base para formulários públicos de pré-contrato
 * Reutilizada para Casamento, Infantil e PJ
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';

// Tipo de evento (casamento, infantil, pj)
$tipo_evento = $_GET['tipo'] ?? 'casamento';
if (!in_array($tipo_evento, ['casamento', 'infantil', 'pj'])) {
    $tipo_evento = 'casamento';
}

// Títulos por tipo
$titulos = [
    'casamento' => 'Solicite seu Orçamento - Casamento',
    'infantil' => 'Solicite seu Orçamento - Festa Infantil',
    'pj' => 'Solicite seu Orçamento - Evento Corporativo'
];

$titulo = $titulos[$tipo_evento] ?? 'Solicite seu Orçamento';

// Proteção anti-spam: rate limit por IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$limite_por_hora = 3; // Máximo 3 envios por hora por IP

$pdo = $GLOBALS['pdo'];

// Verificar rate limit
$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM vendas_pre_contratos 
    WHERE criado_por_ip = ? 
    AND criado_em > NOW() - INTERVAL '1 hour'
");
$stmt->execute([$ip]);
$envios_ultima_hora = (int)$stmt->fetchColumn();

$rate_limit_excedido = $envios_ultima_hora >= $limite_por_hora;

// Processar formulário
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rate_limit_excedido) {
    try {
        $nome_completo = trim($_POST['nome_completo'] ?? '');
        $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
        $telefone = preg_replace('/\D/', '', $_POST['telefone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $data_evento = $_POST['data_evento'] ?? '';
        $unidade = $_POST['unidade'] ?? '';
        $horario_inicio = $_POST['horario_inicio'] ?? '';
        $horario_termino = $_POST['horario_termino'] ?? '';
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        // Validações
        if (empty($nome_completo) || strlen($nome_completo) < 3) {
            throw new Exception('Nome completo é obrigatório (mínimo 3 caracteres)');
        }
        
        if (empty($cpf) || strlen($cpf) !== 11) {
            throw new Exception('CPF é obrigatório e deve ter 11 dígitos');
        }
        
        if (empty($telefone) || strlen($telefone) < 10) {
            throw new Exception('Telefone é obrigatório');
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail válido é obrigatório');
        }
        
        if (empty($data_evento)) {
            throw new Exception('Data do evento é obrigatória');
        }
        
        // Validar que data não é no passado
        $data_evento_obj = new DateTime($data_evento);
        $hoje = new DateTime();
        if ($data_evento_obj < $hoje) {
            throw new Exception('Data do evento não pode ser no passado');
        }
        
        if (empty($unidade) || !in_array($unidade, ['Lisbon', 'Diverkids', 'Garden', 'Cristal'])) {
            throw new Exception('Unidade inválida');
        }
        
        if (empty($horario_inicio) || empty($horario_termino)) {
            throw new Exception('Horário de início e término são obrigatórios');
        }
        
        // Validar que término é depois do início
        $inicio_ts = strtotime($horario_inicio);
        $termino_ts = strtotime($horario_termino);
        if ($termino_ts <= $inicio_ts) {
            throw new Exception('Horário de término deve ser após o horário de início');
        }
        
        // Inserir pré-contrato
        $stmt = $pdo->prepare("
            INSERT INTO vendas_pre_contratos 
            (tipo_evento, nome_completo, cpf, telefone, email, data_evento, unidade, 
             horario_inicio, horario_termino, observacoes, status, criado_por_ip)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aguardando_conferencia', ?)
        ");
        
        $stmt->execute([
            $tipo_evento,
            $nome_completo,
            $cpf,
            $telefone,
            $email,
            $data_evento,
            $unidade,
            $horario_inicio,
            $horario_termino,
            $observacoes,
            $ip
        ]);
        
        // Log
        $pre_contrato_id = $pdo->lastInsertId();
        $stmt_log = $pdo->prepare("
            INSERT INTO vendas_logs (pre_contrato_id, acao, detalhes)
            VALUES (?, 'criado', ?)
        ");
        $stmt_log->execute([$pre_contrato_id, json_encode(['ip' => $ip, 'tipo' => $tipo_evento])]);
        
        $success = true;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($titulo); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 1rem;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 2.5rem;
        }
        
        h1 {
            color: #1e3a8a;
            margin-bottom: 0.5rem;
            font-size: 1.75rem;
        }
        
        .subtitle {
            color: #64748b;
            margin-bottom: 2rem;
            font-size: 1rem;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #374151;
            font-weight: 500;
        }
        
        .required {
            color: #ef4444;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="date"],
        input[type="time"],
        select,
        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .btn {
            width: 100%;
            padding: 1rem;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn:hover:not(:disabled) {
            background: #1d4ed8;
        }
        
        .btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
        }
        
        .success-message {
            text-align: center;
            padding: 2rem;
        }
        
        .success-message h2 {
            color: #16a34a;
            margin-bottom: 1rem;
        }
        
        .success-message p {
            color: #64748b;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($success): ?>
            <div class="success-message">
                <h2>✓ Solicitação enviada com sucesso!</h2>
                <p>Recebemos sua solicitação de orçamento. Nossa equipe entrará em contato em breve.</p>
            </div>
        <?php else: ?>
            <h1><?php echo htmlspecialchars($titulo); ?></h1>
            <p class="subtitle">Preencha o formulário abaixo e nossa equipe entrará em contato</p>
            
            <?php if ($rate_limit_excedido): ?>
                <div class="alert alert-warning">
                    Você já enviou muitas solicitações recentemente. Por favor, aguarde algumas horas antes de tentar novamente.
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" <?php echo $rate_limit_excedido ? 'onsubmit="return false;"' : ''; ?>>
                <div class="form-group">
                    <label for="nome_completo">Nome Completo <span class="required">*</span></label>
                    <input type="text" id="nome_completo" name="nome_completo" required 
                           value="<?php echo htmlspecialchars($_POST['nome_completo'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="cpf">CPF <span class="required">*</span></label>
                    <input type="text" id="cpf" name="cpf" required 
                           placeholder="000.000.000-00"
                           maxlength="14"
                           value="<?php echo htmlspecialchars($_POST['cpf'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="telefone">Telefone <span class="required">*</span></label>
                    <input type="text" id="telefone" name="telefone" required 
                           placeholder="(00) 00000-0000"
                           value="<?php echo htmlspecialchars($_POST['telefone'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="email">E-mail <span class="required">*</span></label>
                    <input type="email" id="email" name="email" required 
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="data_evento">Data do Evento <span class="required">*</span></label>
                    <input type="date" id="data_evento" name="data_evento" required 
                           min="<?php echo date('Y-m-d'); ?>"
                           value="<?php echo htmlspecialchars($_POST['data_evento'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="unidade">Unidade/Local <span class="required">*</span></label>
                    <select id="unidade" name="unidade" required>
                        <option value="">Selecione...</option>
                        <option value="Lisbon" <?php echo (($_POST['unidade'] ?? '') === 'Lisbon') ? 'selected' : ''; ?>>Lisbon</option>
                        <option value="Diverkids" <?php echo (($_POST['unidade'] ?? '') === 'Diverkids') ? 'selected' : ''; ?>>Diverkids</option>
                        <option value="Garden" <?php echo (($_POST['unidade'] ?? '') === 'Garden') ? 'selected' : ''; ?>>Garden</option>
                        <option value="Cristal" <?php echo (($_POST['unidade'] ?? '') === 'Cristal') ? 'selected' : ''; ?>>Cristal</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="horario_inicio">Horário de Início <span class="required">*</span></label>
                    <input type="time" id="horario_inicio" name="horario_inicio" required 
                           value="<?php echo htmlspecialchars($_POST['horario_inicio'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="horario_termino">Horário de Término <span class="required">*</span></label>
                    <input type="time" id="horario_termino" name="horario_termino" required 
                           value="<?php echo htmlspecialchars($_POST['horario_termino'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="observacoes">Observações</label>
                    <textarea id="observacoes" name="observacoes" 
                              placeholder="Informações adicionais sobre o evento..."><?php echo htmlspecialchars($_POST['observacoes'] ?? ''); ?></textarea>
                </div>
                
                <button type="submit" class="btn" <?php echo $rate_limit_excedido ? 'disabled' : ''; ?>>
                    Enviar Solicitação
                </button>
            </form>
        <?php endif; ?>
    </div>
    
    <script>
        // Máscara CPF
        document.getElementById('cpf')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 11) {
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                e.target.value = value;
            }
        });
        
        // Máscara Telefone
        document.getElementById('telefone')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 11) {
                if (value.length <= 10) {
                    value = value.replace(/(\d{2})(\d)/, '($1) $2');
                    value = value.replace(/(\d{4})(\d)/, '$1-$2');
                } else {
                    value = value.replace(/(\d{2})(\d)/, '($1) $2');
                    value = value.replace(/(\d{5})(\d)/, '$1-$2');
                }
                e.target.value = value;
            }
        });
    </script>
</body>
</html>
