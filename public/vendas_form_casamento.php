<?php
/**
 * vendas_form_casamento.php
 * Formulário público para cadastro de contrato - Casamento
 * Campos conforme especificação do Ajuste 8
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/vendas_helper.php';

// Permite reaproveitar este template para formulários com mesmo layout/campos
// (ex.: 15 anos), sobrescrevendo as variáveis antes do require.
$tipo_evento = $tipo_evento ?? 'casamento';
$titulo = $titulo ?? 'Cadastro do Contrato - Casamento';
$label_nome_noivos = $label_nome_noivos ?? 'Nome dos Noivos';
$placeholder_nome_noivos = $placeholder_nome_noivos ?? 'Ex: João e Maria';
$erro_nome_noivos = $erro_nome_noivos ?? 'Nome dos noivos é obrigatório';
$log_prefix = $log_prefix ?? $tipo_evento;

// Buscar locais mapeados para dropdown
$locais_mapeados = vendas_buscar_locais_mapeados();

// Proteção anti-spam: rate limit por IP
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$limite_por_hora = 3;

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
$success_title = 'Enviado com sucesso!';
$success_message = 'Recebemos seus dados. Nossa equipe entrará em contato em breve para dar andamento ao contrato.';
$memoria_pre_contrato_dias = vendas_memoria_pre_contrato_dias();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$rate_limit_excedido) {
    try {
        // Cliente (público)
        $nome_completo = trim($_POST['nome_completo'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefone = preg_replace('/\D/', '', $_POST['telefone'] ?? '');
        $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
        $rg = trim($_POST['rg'] ?? '');
        $cep = preg_replace('/\D/', '', $_POST['cep'] ?? '');
        $endereco_completo = trim($_POST['endereco_completo'] ?? '');
        $numero = trim($_POST['numero'] ?? '');
        $complemento = trim($_POST['complemento'] ?? '');
        $bairro = trim($_POST['bairro'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $estado = strtoupper(trim($_POST['estado'] ?? ''));
        $pais = trim($_POST['pais'] ?? 'Brasil');
        $instagram = trim($_POST['instagram'] ?? '');
        
        // Evento (público)
        $data_evento = $_POST['data_evento'] ?? '';
        $horario_inicio = $_POST['horario_inicio'] ?? '';
        $horario_termino = $_POST['horario_termino'] ?? '';
        $unidade = $_POST['unidade'] ?? '';
        $nome_noivos = trim($_POST['nome_noivos'] ?? '');
        $num_convidados = (int)($_POST['num_convidados'] ?? 0);
        $como_conheceu = trim($_POST['como_conheceu'] ?? '');
        $como_conheceu_outro = trim($_POST['como_conheceu_outro'] ?? '');
        
        // Texto livre (público)
        $pacote_plano = trim($_POST['pacote_plano'] ?? '');
        $registro_existente_id = (int)($_POST['registro_existente_id'] ?? 0);
        $alterar_registro_existente = ($_POST['alterar_registro_existente'] ?? '') === '1';
        
        // Validações obrigatórias
        if (empty($nome_completo) || strlen($nome_completo) < 3) {
            throw new Exception('Nome completo é obrigatório (mínimo 3 caracteres)');
        }
        
        if (empty($cpf) || strlen($cpf) !== 11) {
            throw new Exception('CPF é obrigatório e deve ter 11 dígitos');
        }
        
        // Validar CPF (dígitos verificadores básicos)
        if (!vendas_validar_cpf($cpf)) {
            throw new Exception('CPF inválido');
        }
        
        if (empty($telefone) || strlen($telefone) < 10) {
            throw new Exception('Telefone/WhatsApp é obrigatório');
        }
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('E-mail válido é obrigatório');
        }
        
        if (empty($rg)) {
            throw new Exception('RG é obrigatório');
        }
        
        if (empty($cep) || strlen($cep) !== 8) {
            throw new Exception('CEP é obrigatório e deve ter 8 dígitos');
        }
        
        if (empty($endereco_completo)) {
            throw new Exception('Endereço completo é obrigatório');
        }
        
        if (empty($numero)) {
            throw new Exception('Número do endereço é obrigatório');
        }
        
        if (empty($bairro)) {
            throw new Exception('Bairro é obrigatório');
        }
        
        if (empty($cidade)) {
            throw new Exception('Cidade é obrigatória');
        }
        
        if (empty($estado) || strlen($estado) !== 2) {
            throw new Exception('Estado é obrigatório (2 letras)');
        }
        
        if (empty($data_evento)) {
            throw new Exception('Data do evento é obrigatória');
        }
        
        // Validar que data não é no passado
        $data_evento_obj = new DateTime($data_evento);
        $hoje = new DateTime();
        $hoje->setTime(0, 0, 0);
        if ($data_evento_obj < $hoje) {
            throw new Exception('Data do evento não pode ser no passado');
        }
        
        if (empty($horario_inicio) || empty($horario_termino)) {
            throw new Exception('Horário de início e término são obrigatórios');
        }
        
        // Validar horários (evento pode passar da meia-noite: ex. 20:00 às 01:00 = válido)
        $inicio_ts = strtotime($horario_inicio);
        $termino_ts = strtotime($horario_termino);
        if ($inicio_ts === false || $termino_ts === false) {
            throw new Exception('Horário de início ou término inválido');
        }
        // Só rejeitar se forem iguais; se término < início = evento após meia-noite (aceitar)
        if ($termino_ts === $inicio_ts) {
            throw new Exception('Horário de término deve ser diferente do horário de início');
        }
        
        // Validar que unidade está mapeada
        $me_local_id = vendas_validar_local_mapeado($unidade);
        if (!$me_local_id) {
            // Mensagem amigável para cliente (o detalhe interno fica nos logs)
            error_log('[VENDAS] Form público ' . $log_prefix . ': local não mapeado/unidade inválida: ' . $unidade);
            throw new Exception('Local indisponível no momento. Por favor, tente novamente mais tarde.');
        }
        
        if (empty($nome_noivos)) {
            throw new Exception((string)$erro_nome_noivos);
        }
        
        if (empty($num_convidados) || $num_convidados <= 0) {
            throw new Exception('Número de convidados é obrigatório e deve ser maior que zero');
        }
        
        if (empty($como_conheceu)) {
            throw new Exception('Como conheceu é obrigatório');
        }
        
        if ($como_conheceu === 'outro' && empty($como_conheceu_outro)) {
            throw new Exception('Informe como conheceu quando selecionar "Outro"');
        }
        
        if (empty($pacote_plano)) {
            throw new Exception('Pacote/Plano escolhido é obrigatório');
        }

        $registro_existente = null;
        if ($registro_existente_id > 0 && $alterar_registro_existente) {
            $registro_existente = vendas_buscar_pre_contrato_publico_recente($cpf, $tipo_evento, $registro_existente_id, $memoria_pre_contrato_dias);
        }
        if (!$registro_existente) {
            $registro_existente = vendas_buscar_pre_contrato_publico_recente($cpf, $tipo_evento, null, $memoria_pre_contrato_dias);
        }

        if ($registro_existente) {
            $pre_contrato_id = (int)$registro_existente['id'];
            $stmt = $pdo->prepare("
                UPDATE vendas_pre_contratos
                SET nome_completo = ?,
                    cpf = ?,
                    rg = ?,
                    telefone = ?,
                    email = ?,
                    cep = ?,
                    endereco_completo = ?,
                    numero = ?,
                    complemento = ?,
                    bairro = ?,
                    cidade = ?,
                    estado = ?,
                    pais = ?,
                    instagram = ?,
                    data_evento = ?,
                    unidade = ?,
                    horario_inicio = ?,
                    horario_termino = ?,
                    nome_noivos = ?,
                    num_convidados = ?,
                    como_conheceu = ?,
                    como_conheceu_outro = ?,
                    pacote_contratado = ?,
                    atualizado_em = NOW()
                WHERE id = ?
            ");

            $stmt->execute([
                $nome_completo,
                $cpf,
                $rg,
                $telefone,
                $email,
                $cep,
                $endereco_completo,
                $numero,
                $complemento,
                $bairro,
                $cidade,
                $estado,
                $pais,
                $instagram,
                $data_evento,
                $unidade,
                $horario_inicio,
                $horario_termino,
                $nome_noivos,
                $num_convidados,
                $como_conheceu,
                $como_conheceu === 'outro' ? $como_conheceu_outro : null,
                $pacote_plano,
                $pre_contrato_id
            ]);

            $stmt_log = $pdo->prepare("
                INSERT INTO vendas_logs (pre_contrato_id, acao, detalhes)
                VALUES (?, 'dados_publicos_atualizados', ?)
            ");
            $stmt_log->execute([$pre_contrato_id, json_encode([
                'ip' => $ip,
                'tipo' => $tipo_evento,
                'origem' => 'publico',
                'memoria_dias' => $memoria_pre_contrato_dias,
            ])]);

            $success_title = 'Dados atualizados com sucesso!';
            $success_message = 'Recebemos sua atualização. Nossa equipe entrará em contato em breve para dar andamento ao contrato.';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO vendas_pre_contratos
                (tipo_evento, origem, nome_completo, cpf, rg, telefone, email,
                 cep, endereco_completo, numero, complemento, bairro, cidade, estado, pais, instagram,
                 data_evento, unidade, horario_inicio, horario_termino,
                 nome_noivos, num_convidados, como_conheceu, como_conheceu_outro,
                 pacote_contratado, status, criado_por_ip)
                VALUES (?, 'publico', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aguardando_conferencia', ?)
            ");

            $stmt->execute([
                $tipo_evento,
                $nome_completo,
                $cpf,
                $rg,
                $telefone,
                $email,
                $cep,
                $endereco_completo,
                $numero,
                $complemento,
                $bairro,
                $cidade,
                $estado,
                $pais,
                $instagram,
                $data_evento,
                $unidade,
                $horario_inicio,
                $horario_termino,
                $nome_noivos,
                $num_convidados,
                $como_conheceu,
                $como_conheceu === 'outro' ? $como_conheceu_outro : null,
                $pacote_plano,
                $ip
            ]);

            $pre_contrato_id = $pdo->lastInsertId();
            $stmt_log = $pdo->prepare("
                INSERT INTO vendas_logs (pre_contrato_id, acao, detalhes)
                VALUES (?, 'criado', ?)
            ");
            $stmt_log->execute([$pre_contrato_id, json_encode(['ip' => $ip, 'tipo' => $tipo_evento, 'origem' => 'publico'])]);
        }
        
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
        :root {
            --primary-color: #1e3a8a;
            --primary-light: #3b82f6;
            --primary-dark: #1e40af;
            --accent-color: #2563eb;
            --success-color: #16a34a;
            --error-color: #dc2626;
            --warning-color: #f59e0b;
            --text-dark: #1e293b;
            --text-medium: #475569;
            --text-light: #64748b;
            --bg-gradient-start: #0b1b3a;
            --bg-gradient-mid: #081126;
            --bg-gradient-end: #060c1c;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: "Manrope", -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background:
              radial-gradient(700px 420px at 15% 20%, rgba(39,79,170,0.25), transparent 60%),
              radial-gradient(700px 420px at 85% 80%, rgba(24,119,242,0.18), transparent 60%),
              linear-gradient(135deg, var(--bg-gradient-start), var(--bg-gradient-mid) 55%, var(--bg-gradient-end));
            min-height: 100vh;
            padding: 2rem 1rem;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
            background: rgba(255,255,255,0.98);
            border-radius: 20px;
            box-shadow: 0 25px 80px rgba(4, 9, 20, 0.6);
            padding: 0;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        /* Header da marca */
        .brand-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            padding: 2rem 2.5rem;
            text-align: center;
            position: relative;
        }
        
        .brand-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-light), var(--accent-color), var(--primary-light));
        }

        .brand-row {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        
        .brand-row img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            transition: transform 0.3s ease;
        }
        
        .brand-row img:hover {
            transform: scale(1.05);
        }
        
        .brand-header h1 {
            color: #ffffff;
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .brand-header .subtitle {
            color: rgba(255,255,255,0.85);
            margin-bottom: 0;
            font-size: 0.95rem;
        }
        
        /* Conteúdo do formulário */
        .form-content {
            padding: 2rem 2.5rem 2.5rem;
        }
        
        @media (max-width: 600px) {
            .brand-header {
                padding: 1.5rem 1.25rem;
            }
            .form-content {
                padding: 1.5rem 1.25rem 2rem;
            }
        }
        
        h1 {
            color: var(--primary-color);
            margin-bottom: 0.5rem;
            font-size: 1.75rem;
        }
        
        .subtitle {
            color: var(--text-light);
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

        .alert strong {
            display: block;
            margin-bottom: 0.35rem;
            letter-spacing: 0.02em;
        }

        .alert-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1rem;
        }

        .lookup-status {
            display: block;
            margin-top: 0.5rem;
            color: #64748b;
        }
        
        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .form-section:last-child {
            border-bottom: none;
        }
        
        .form-section-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1e3a8a;
            margin-bottom: 1rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
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
        input[type="number"],
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
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, var(--accent-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-color) 100%);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
            transform: translateY(-2px);
        }
        
        .btn:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 120px;
            padding: 0.8rem 1rem;
            background: #fff;
            color: var(--primary-color);
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            font-size: 0.96rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }
        
        .success-message {
            text-align: center;
            padding: 3rem 2rem;
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: white;
            box-shadow: 0 8px 25px rgba(22, 163, 74, 0.3);
        }
        
        .success-message h2 {
            color: var(--success-color);
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .success-message p {
            color: var(--text-light);
            font-size: 1.05rem;
            line-height: 1.6;
        }
        
        /* Seções do formulário */
        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .form-section:last-of-type {
            border-bottom: none;
            margin-bottom: 1.5rem;
        }
        
        .form-section-title {
            font-size: 1.125rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .form-section-title::before {
            content: '';
            width: 4px;
            height: 20px;
            background: linear-gradient(180deg, var(--primary-light), var(--accent-color));
            border-radius: 2px;
        }
        
        /* Rodapé */
        .form-footer {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e5e7eb;
            text-align: center;
        }
        
        .form-footer p {
            color: var(--text-light);
            font-size: 0.85rem;
        }
        
        .form-footer a {
            color: var(--accent-color);
            text-decoration: none;
        }
        
        .form-footer a:hover {
            text-decoration: underline;
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="brand-header">
            <div class="brand-row">
                <img src="logo.png" alt="Logo do Grupo Smile">
            </div>
            <h1><?php echo htmlspecialchars($titulo); ?></h1>
            <p class="subtitle">Preencha o formulário abaixo para darmos andamento ao seu contrato</p>
        </div>
        
        <div class="form-content">
        <?php if ($success): ?>
            <div class="success-message">
                <div class="success-icon">✓</div>
                <h2><?php echo htmlspecialchars($success_title); ?></h2>
                <p><?php echo htmlspecialchars($success_message); ?></p>
            </div>
        <?php else: ?>
            
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

            <div class="success-message" id="registro_existente_agradecimento" style="display:none;">
                <div class="success-icon">✓</div>
                <h2>Obrigado!</h2>
                <p>Recebemos sua confirmação. Se precisar atualizar esses dados depois, é só abrir o link novamente.</p>
            </div>
            
            <form id="form_pre_contrato_publico" method="POST" action="" <?php echo $rate_limit_excedido ? 'onsubmit="return false;"' : ''; ?>>
                <input type="hidden" id="registro_existente_id" name="registro_existente_id" value="<?php echo (int)($_POST['registro_existente_id'] ?? 0); ?>">
                <input type="hidden" id="alterar_registro_existente" name="alterar_registro_existente" value="<?php echo (($_POST['alterar_registro_existente'] ?? '') === '1') ? '1' : '0'; ?>">
                <!-- Seção: Cliente -->
                <div class="form-section">
                    <div class="form-section-title">Dados do Cliente</div>
                    
                    <div class="form-group">
                        <label for="nome_completo">Nome Completo <span class="required">*</span></label>
                        <input type="text" id="nome_completo" name="nome_completo" required 
                               value="<?php echo htmlspecialchars($_POST['nome_completo'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="cpf">CPF <span class="required">*</span></label>
                            <input type="text" id="cpf" name="cpf" required 
                                   placeholder="000.000.000-00"
                                   maxlength="14"
                                   value="<?php echo htmlspecialchars($_POST['cpf'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="rg">RG <span class="required">*</span></label>
                            <input type="text" id="rg" name="rg" required 
                                   value="<?php echo htmlspecialchars($_POST['rg'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="alert alert-warning" id="registro_existente_alerta" style="display:none;">
                        <strong>VOCÊ JÁ TEM DADOS ENVIADOS, DESEJA ALTERAR?</strong>
                        <span id="registro_existente_resumo"></span>
                        <div class="alert-actions">
                            <button type="button" class="btn-secondary" id="registro_existente_sim">Sim, alterar</button>
                            <button type="button" class="btn-secondary" id="registro_existente_nao">Não</button>
                        </div>
                    </div>
                    <small class="lookup-status" id="registro_existente_status"></small>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="telefone">Telefone/WhatsApp <span class="required">*</span></label>
                            <input type="text" id="telefone" name="telefone" required 
                                   placeholder="(00) 00000-0000"
                                   value="<?php echo htmlspecialchars($_POST['telefone'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="email">E-mail <span class="required">*</span></label>
                            <input type="email" id="email" name="email" required 
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="instagram">Instagram / Rede Social</label>
                        <input type="text" id="instagram" name="instagram" 
                               placeholder="@usuario"
                               value="<?php echo htmlspecialchars($_POST['instagram'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="cep">CEP <span class="required">*</span></label>
                        <input type="text" id="cep" name="cep" required 
                               placeholder="00000-000"
                               maxlength="9"
                               value="<?php echo htmlspecialchars($_POST['cep'] ?? ''); ?>">
                        <small id="cep_status" style="color:#64748b;display:block;margin-top:.35rem;"></small>
                    </div>
                    
                    <div class="form-group">
                        <label for="endereco_completo">Endereço Completo <span class="required">*</span></label>
                        <input type="text" id="endereco_completo" name="endereco_completo" required 
                               placeholder="Rua, Avenida, etc"
                               value="<?php echo htmlspecialchars($_POST['endereco_completo'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="numero">Número <span class="required">*</span></label>
                            <input type="text" id="numero" name="numero" required 
                                   value="<?php echo htmlspecialchars($_POST['numero'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="complemento">Complemento</label>
                            <input type="text" id="complemento" name="complemento" 
                                   placeholder="Apto, Bloco, etc"
                                   value="<?php echo htmlspecialchars($_POST['complemento'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="bairro">Bairro <span class="required">*</span></label>
                            <input type="text" id="bairro" name="bairro" required 
                                   value="<?php echo htmlspecialchars($_POST['bairro'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="cidade">Cidade <span class="required">*</span></label>
                            <input type="text" id="cidade" name="cidade" required 
                                   value="<?php echo htmlspecialchars($_POST['cidade'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="estado">Estado (UF) <span class="required">*</span></label>
                            <input type="text" id="estado" name="estado" required 
                                   placeholder="SP"
                                   maxlength="2"
                                   value="<?php echo htmlspecialchars($_POST['estado'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="pais">País</label>
                            <input type="text" id="pais" name="pais" 
                                   value="<?php echo htmlspecialchars($_POST['pais'] ?? 'Brasil'); ?>">
                        </div>
                    </div>
                </div>
                
                <!-- Seção: Evento -->
                <div class="form-section">
                    <div class="form-section-title">Dados do Evento</div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="data_evento">Data do Evento <span class="required">*</span></label>
                            <input type="date" id="data_evento" name="data_evento" required 
                                   min="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo htmlspecialchars($_POST['data_evento'] ?? ''); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="unidade">Local do Evento (Unidade) <span class="required">*</span></label>
                            <select id="unidade" name="unidade" required>
                                <option value="">Selecione...</option>
                                <?php if (empty($locais_mapeados)): ?>
                                    <option value="" disabled>Nenhum local disponível no momento.</option>
                                <?php else: ?>
                                    <?php foreach ($locais_mapeados as $local): ?>
                                        <option value="<?php echo htmlspecialchars($local['me_local_nome']); ?>" 
                                                <?php echo (($_POST['unidade'] ?? '') === $local['me_local_nome']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($local['me_local_nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($locais_mapeados)): ?>
                                <small style="color: #ef4444; display: block; margin-top: 0.5rem;">
                                    ⚠️ Nenhum local disponível no momento. Por favor, tente novamente mais tarde.
                                </small>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-row">
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
                    </div>
                    
                    <div class="form-group">
                        <label for="nome_noivos"><?php echo htmlspecialchars((string)$label_nome_noivos); ?> <span class="required">*</span></label>
                        <input type="text" id="nome_noivos" name="nome_noivos" required 
                               placeholder="<?php echo htmlspecialchars((string)$placeholder_nome_noivos); ?>"
                               value="<?php echo htmlspecialchars($_POST['nome_noivos'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="num_convidados">Nº de Convidados <span class="required">*</span></label>
                        <input type="number" id="num_convidados" name="num_convidados" required 
                               min="1"
                               value="<?php echo htmlspecialchars($_POST['num_convidados'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="como_conheceu">Como Conheceu <span class="required">*</span></label>
                        <select id="como_conheceu" name="como_conheceu" required>
                            <option value="">Selecione...</option>
                            <option value="instagram" <?php echo (($_POST['como_conheceu'] ?? '') === 'instagram') ? 'selected' : ''; ?>>Instagram</option>
                            <option value="facebook" <?php echo (($_POST['como_conheceu'] ?? '') === 'facebook') ? 'selected' : ''; ?>>Facebook</option>
                            <option value="google" <?php echo (($_POST['como_conheceu'] ?? '') === 'google') ? 'selected' : ''; ?>>Google</option>
                            <option value="indicacao" <?php echo (($_POST['como_conheceu'] ?? '') === 'indicacao') ? 'selected' : ''; ?>>Indicação</option>
                            <option value="outro" <?php echo (($_POST['como_conheceu'] ?? '') === 'outro') ? 'selected' : ''; ?>>Outro</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="div_como_conheceu_outro" style="display: none;">
                        <label for="como_conheceu_outro">Especifique como conheceu <span class="required">*</span></label>
                        <input type="text" id="como_conheceu_outro" name="como_conheceu_outro" 
                               value="<?php echo htmlspecialchars($_POST['como_conheceu_outro'] ?? ''); ?>">
                    </div>
                </div>
                
                <!-- Seção: Pacote -->
                <div class="form-section">
                    <div class="form-section-title">Pacote/Plano</div>
                    
                    <div class="form-group">
                        <label for="pacote_plano">Pacote/Plano Escolhido <span class="required">*</span></label>
                        <textarea id="pacote_plano" name="pacote_plano" required 
                                  placeholder="Descreva o pacote ou plano escolhido..."
                                  rows="3"><?php echo htmlspecialchars($_POST['pacote_plano'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <button type="submit" class="btn" <?php echo $rate_limit_excedido ? 'disabled' : ''; ?>>
                    Enviar
                </button>
            </form>
        <?php endif; ?>
        </div>
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
        
        // Máscara CEP
        document.getElementById('cep')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 8) {
                value = value.replace(/(\d{5})(\d)/, '$1-$2');
                e.target.value = value;
            }
        });

        // Busca automática de CEP (ViaCEP via endpoint interno)
        let cepTimeout = null;
        let lastCepBuscado = '';

        async function buscarCepPreencher(cepDigits) {
            const status = document.getElementById('cep_status');
            const endereco = document.getElementById('endereco_completo');
            const bairro = document.getElementById('bairro');
            const cidade = document.getElementById('cidade');
            const estado = document.getElementById('estado');
            const complemento = document.getElementById('complemento');
            const numero = document.getElementById('numero');

            if (!status) return;
            status.textContent = 'Buscando CEP...';

            try {
                const resp = await fetch(`buscar_cep_endpoint.php?cep=${encodeURIComponent(cepDigits)}`);
                const data = await resp.json();
                if (!data?.success || !data?.data) {
                    status.textContent = data?.message ? String(data.message) : 'CEP não encontrado.';
                    return;
                }

                const d = data.data;
                // Preenche somente se estiver vazio (não sobrescrever o usuário)
                if (endereco && !endereco.value) endereco.value = d.logradouro || '';
                if (bairro && !bairro.value) bairro.value = d.bairro || '';
                if (cidade && !cidade.value) cidade.value = d.cidade || '';
                if (estado && !estado.value) estado.value = (d.estado || '').toUpperCase();
                if (complemento && !complemento.value) complemento.value = d.complemento || '';

                status.textContent = '';
                if (numero) numero.focus();
            } catch (err) {
                status.textContent = 'Erro ao buscar CEP. Tente novamente.';
            }
        }

        function handleCepAuto() {
            const cepEl = document.getElementById('cep');
            if (!cepEl) return;
            const digits = (cepEl.value || '').replace(/\D/g, '');
            if (digits.length !== 8) return;
            if (digits === lastCepBuscado) return;
            lastCepBuscado = digits;
            buscarCepPreencher(digits);
        }

        document.getElementById('cep')?.addEventListener('blur', handleCepAuto);
        document.getElementById('cep')?.addEventListener('input', function() {
            clearTimeout(cepTimeout);
            cepTimeout = setTimeout(handleCepAuto, 350);
        });
        
        // Mostrar/ocultar campo "como conheceu outro"
        document.getElementById('como_conheceu')?.addEventListener('change', function() {
            const divOutro = document.getElementById('div_como_conheceu_outro');
            const inputOutro = document.getElementById('como_conheceu_outro');
            if (this.value === 'outro') {
                divOutro.style.display = 'block';
                inputOutro.required = true;
            } else {
                divOutro.style.display = 'none';
                inputOutro.required = false;
                inputOutro.value = '';
            }
        });
        
        // Inicializar estado do campo "como conheceu outro"
        if (document.getElementById('como_conheceu')?.value === 'outro') {
            document.getElementById('div_como_conheceu_outro').style.display = 'block';
            document.getElementById('como_conheceu_outro').required = true;
        }

        const tipoEventoConsulta = <?php echo json_encode($tipo_evento); ?>;
        const memoriaPreContratoDias = <?php echo (int)$memoria_pre_contrato_dias; ?>;
        const formPreContratoEl = document.getElementById('form_pre_contrato_publico');
        const cpfConsultaEl = document.getElementById('cpf');
        const alertaRegistroExistenteEl = document.getElementById('registro_existente_alerta');
        const resumoRegistroExistenteEl = document.getElementById('registro_existente_resumo');
        const statusRegistroExistenteEl = document.getElementById('registro_existente_status');
        const btnRegistroExistenteSimEl = document.getElementById('registro_existente_sim');
        const btnRegistroExistenteNaoEl = document.getElementById('registro_existente_nao');
        const agradecimentoRegistroExistenteEl = document.getElementById('registro_existente_agradecimento');
        const registroExistenteIdEl = document.getElementById('registro_existente_id');
        const alterarRegistroExistenteEl = document.getElementById('alterar_registro_existente');

        let consultaRegistroTimeout = null;
        let consultaRegistroController = null;
        let ultimoCpfConsultado = '';
        let registroEncontrado = null;

        function somenteDigitos(valor) {
            return String(valor || '').replace(/\D/g, '');
        }

        function formatarCpf(valor) {
            let digits = somenteDigitos(valor).slice(0, 11);
            digits = digits.replace(/(\d{3})(\d)/, '$1.$2');
            digits = digits.replace(/(\d{3})(\d)/, '$1.$2');
            digits = digits.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            return digits;
        }

        function formatarTelefone(valor) {
            let digits = somenteDigitos(valor).slice(0, 11);
            if (digits.length <= 10) {
                digits = digits.replace(/(\d{2})(\d)/, '($1) $2');
                digits = digits.replace(/(\d{4})(\d)/, '$1-$2');
                return digits;
            }
            digits = digits.replace(/(\d{2})(\d)/, '($1) $2');
            digits = digits.replace(/(\d{5})(\d)/, '$1-$2');
            return digits;
        }

        function formatarCep(valor) {
            return somenteDigitos(valor).slice(0, 8).replace(/(\d{5})(\d)/, '$1-$2');
        }

        function atualizarStatusRegistroExistente(mensagem, isError = false) {
            if (!statusRegistroExistenteEl) return;
            statusRegistroExistenteEl.textContent = mensagem || '';
            statusRegistroExistenteEl.style.color = isError ? '#b45309' : '#64748b';
        }

        function esconderAlertaRegistroExistente() {
            if (alertaRegistroExistenteEl) {
                alertaRegistroExistenteEl.style.display = 'none';
            }
            if (resumoRegistroExistenteEl) {
                resumoRegistroExistenteEl.textContent = '';
            }
        }

        function limparRegistroExistente() {
            registroEncontrado = null;
            if (registroExistenteIdEl) {
                registroExistenteIdEl.value = '0';
            }
            if (alterarRegistroExistenteEl) {
                alterarRegistroExistenteEl.value = '0';
            }
            esconderAlertaRegistroExistente();
        }

        function reabrirFormulario() {
            if (formPreContratoEl) {
                formPreContratoEl.style.display = '';
            }
            if (agradecimentoRegistroExistenteEl) {
                agradecimentoRegistroExistenteEl.style.display = 'none';
            }
        }

        function preencherCampo(id, valor) {
            const el = document.getElementById(id);
            if (!el || valor == null) return;
            el.value = String(valor);
        }

        function preencherFormularioRegistroExistente(registro) {
            if (!registro) return;

            reabrirFormulario();

            preencherCampo('nome_completo', registro.nome_completo || '');
            preencherCampo('cpf', formatarCpf(registro.cpf || ''));
            preencherCampo('rg', registro.rg || '');
            preencherCampo('telefone', formatarTelefone(registro.telefone || ''));
            preencherCampo('email', registro.email || '');
            preencherCampo('instagram', registro.instagram || '');
            preencherCampo('cep', formatarCep(registro.cep || ''));
            preencherCampo('endereco_completo', registro.endereco_completo || '');
            preencherCampo('numero', registro.numero || '');
            preencherCampo('complemento', registro.complemento || '');
            preencherCampo('bairro', registro.bairro || '');
            preencherCampo('cidade', registro.cidade || '');
            preencherCampo('estado', registro.estado || '');
            preencherCampo('pais', registro.pais || 'Brasil');
            preencherCampo('data_evento', registro.data_evento || '');
            preencherCampo('unidade', registro.unidade || '');
            preencherCampo('horario_inicio', registro.horario_inicio || '');
            preencherCampo('horario_termino', registro.horario_termino || '');
            preencherCampo('nome_noivos', registro.nome_noivos || '');
            preencherCampo('num_convidados', registro.num_convidados || '');
            preencherCampo('pacote_plano', registro.pacote_contratado || '');

            const comoConheceuEl = document.getElementById('como_conheceu');
            if (comoConheceuEl) {
                comoConheceuEl.value = registro.como_conheceu || '';
                comoConheceuEl.dispatchEvent(new Event('change'));
            }
            preencherCampo('como_conheceu_outro', registro.como_conheceu_outro || '');

            if (registroExistenteIdEl) {
                registroExistenteIdEl.value = String(registro.id || 0);
            }
            if (alterarRegistroExistenteEl) {
                alterarRegistroExistenteEl.value = '1';
            }

            esconderAlertaRegistroExistente();
            atualizarStatusRegistroExistente('Dados carregados para alteração.');
        }

        async function consultarRegistroExistente(force = false) {
            if (!cpfConsultaEl) return;

            const cpfDigits = somenteDigitos(cpfConsultaEl.value);
            if (cpfDigits.length !== 11) {
                ultimoCpfConsultado = '';
                limparRegistroExistente();
                atualizarStatusRegistroExistente('');
                return;
            }

            if (!force && cpfDigits === ultimoCpfConsultado) {
                return;
            }

            ultimoCpfConsultado = cpfDigits;
            reabrirFormulario();

            if (consultaRegistroController) {
                consultaRegistroController.abort();
            }
            consultaRegistroController = new AbortController();

            atualizarStatusRegistroExistente('Consultando dados já enviados...');

            try {
                const resp = await fetch(`vendas_pre_contrato_consulta.php?cpf=${encodeURIComponent(cpfDigits)}&tipo_evento=${encodeURIComponent(tipoEventoConsulta)}`, {
                    signal: consultaRegistroController.signal,
                    headers: { 'Accept': 'application/json' }
                });
                const data = await resp.json();

                if (!resp.ok || !data?.success) {
                    throw new Error(data?.message || 'Não foi possível consultar seus dados agora.');
                }

                if (!data.found || !data.registro) {
                    limparRegistroExistente();
                    atualizarStatusRegistroExistente('');
                    return;
                }

                registroEncontrado = data.registro;
                if (registroExistenteIdEl) {
                    registroExistenteIdEl.value = String(data.registro.id || 0);
                }
                if (alterarRegistroExistenteEl) {
                    alterarRegistroExistenteEl.value = '0';
                }
                if (resumoRegistroExistenteEl) {
                    resumoRegistroExistenteEl.textContent = data?.resumo?.criado_em
                        ? `Encontramos um cadastro enviado em ${data.resumo.criado_em}.`
                        : `Encontramos um cadastro enviado nos últimos ${data.memoria_dias || memoriaPreContratoDias} dias.`;
                }
                if (alertaRegistroExistenteEl) {
                    alertaRegistroExistenteEl.style.display = 'block';
                }
                atualizarStatusRegistroExistente(`Cadastro localizado nos últimos ${data.memoria_dias || memoriaPreContratoDias} dias.`);
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return;
                }
                limparRegistroExistente();
                atualizarStatusRegistroExistente(error?.message || 'Não foi possível consultar seus dados agora.', true);
            }
        }

        cpfConsultaEl?.addEventListener('blur', function() {
            consultarRegistroExistente();
        });

        cpfConsultaEl?.addEventListener('input', function() {
            clearTimeout(consultaRegistroTimeout);

            const cpfDigits = somenteDigitos(this.value);
            if (registroEncontrado && cpfDigits !== somenteDigitos(registroEncontrado.cpf || '')) {
                limparRegistroExistente();
            }
            if (cpfDigits.length !== 11) {
                ultimoCpfConsultado = '';
                atualizarStatusRegistroExistente('');
                return;
            }

            consultaRegistroTimeout = setTimeout(function() {
                consultarRegistroExistente();
            }, 350);
        });

        btnRegistroExistenteSimEl?.addEventListener('click', function() {
            preencherFormularioRegistroExistente(registroEncontrado);
        });

        btnRegistroExistenteNaoEl?.addEventListener('click', function() {
            if (alertaRegistroExistenteEl) {
                alertaRegistroExistenteEl.style.display = 'none';
            }
            if (formPreContratoEl) {
                formPreContratoEl.style.display = 'none';
            }
            if (agradecimentoRegistroExistenteEl) {
                agradecimentoRegistroExistenteEl.style.display = 'block';
            }
            atualizarStatusRegistroExistente('');
        });

        formPreContratoEl?.addEventListener('submit', function(e) {
            if (registroEncontrado && alterarRegistroExistenteEl?.value !== '1') {
                e.preventDefault();
                if (alertaRegistroExistenteEl) {
                    alertaRegistroExistenteEl.style.display = 'block';
                }
                atualizarStatusRegistroExistente('Escolha se deseja alterar o cadastro encontrado antes de continuar.', true);
            }
        });
    </script>
</body>
</html>
