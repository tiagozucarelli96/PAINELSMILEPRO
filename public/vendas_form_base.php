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
require_once __DIR__ . '/vendas_helper.php';

// Tipo de evento (casamento, infantil, pj)
$tipo_evento = $_GET['tipo'] ?? 'casamento';
if (!in_array($tipo_evento, ['casamento', 'infantil', 'pj'])) {
    $tipo_evento = 'casamento';
}

// Buscar locais mapeados para dropdown
$locais_mapeados = vendas_buscar_locais_mapeados();

// Títulos por tipo
$titulos = [
    'casamento' => 'Cadastro do Contrato - Casamento',
    'infantil' => 'Cadastro do Contrato - Infantil / 15 anos',
    'pj' => 'Cadastro do Contrato - Pessoa Jurídica (PJ)'
];

$titulo = $titulos[$tipo_evento] ?? 'Cadastro do Contrato';

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
        $unidade = $_POST['unidade'] ?? '';
        $horario_inicio = $_POST['horario_inicio'] ?? '';
        $horario_termino = $_POST['horario_termino'] ?? '';
        $nome_evento = trim($_POST['nome_evento'] ?? '');
        $num_convidados = (int)($_POST['num_convidados'] ?? 0);
        $como_conheceu = trim($_POST['como_conheceu'] ?? '');
        $como_conheceu_outro = trim($_POST['como_conheceu_outro'] ?? '');

        // Texto livre (público)
        $pacote_plano = trim($_POST['pacote_plano'] ?? '');

        // Observações (público)
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        // Validações
        if (empty($nome_completo) || strlen($nome_completo) < 3) {
            throw new Exception('Nome completo é obrigatório (mínimo 3 caracteres)');
        }
        
        if (empty($cpf) || strlen($cpf) !== 11) {
            throw new Exception('CPF é obrigatório e deve ter 11 dígitos');
        }

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
            throw new Exception('Endereço é obrigatório');
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
        
        // Validar que unidade está mapeada
        $me_local_id = vendas_validar_local_mapeado($unidade);
        if (!$me_local_id) {
            // Mensagem amigável para cliente (o detalhe interno fica nos logs)
            error_log('[VENDAS] Form público: local não mapeado/unidade inválida: ' . $unidade);
            throw new Exception('Local indisponível no momento. Por favor, tente novamente mais tarde.');
        }
        
        if (empty($horario_inicio) || empty($horario_termino)) {
            throw new Exception('Horário de início e término são obrigatórios');
        }
        
        // Validar horários (evento pode passar da meia-noite: 20:00 às 01:00 = válido)
        $inicio_ts = strtotime($horario_inicio);
        $termino_ts = strtotime($horario_termino);
        if ($inicio_ts === false || $termino_ts === false) {
            throw new Exception('Horário de início ou término inválido');
        }
        // Só rejeitar se forem exatamente iguais; se término < início = evento após meia-noite (aceitar)
        if ($termino_ts === $inicio_ts) {
            throw new Exception('Horário de término deve ser diferente do horário de início');
        }

        if (empty($nome_evento)) {
            if ($tipo_evento === 'infantil') {
                throw new Exception('Nome do aniversariante é obrigatório');
            }
            if ($tipo_evento === 'pj') {
                throw new Exception('Nome do evento é obrigatório');
            }
            // fallback
            throw new Exception('Nome do evento é obrigatório');
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
        
        // Inserir pré-contrato
        $stmt = $pdo->prepare("
            INSERT INTO vendas_pre_contratos 
            (tipo_evento, origem, nome_completo, cpf, rg, telefone, email,
             cep, endereco_completo, numero, complemento, bairro, cidade, estado, pais, instagram,
             data_evento, unidade, horario_inicio, horario_termino,
             nome_noivos, num_convidados, como_conheceu, como_conheceu_outro,
             pacote_contratado, observacoes, status, criado_por_ip)
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
            $nome_evento,
            $num_convidados,
            $como_conheceu,
            $como_conheceu === 'outro' ? $como_conheceu_outro : null,
            $pacote_plano,
            $observacoes,
            $ip
        ]);
        
        // Log
        $pre_contrato_id = $pdo->lastInsertId();
        $stmt_log = $pdo->prepare("
            INSERT INTO vendas_logs (pre_contrato_id, acao, detalhes)
            VALUES (?, 'criado', ?)
        ");
        $stmt_log->execute([$pre_contrato_id, json_encode(['ip' => $ip, 'tipo' => $tipo_evento, 'origem' => 'publico'])]);
        
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
            font-family: "Manrope", -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background:
              radial-gradient(700px 420px at 15% 20%, rgba(39,79,170,0.25), transparent 60%),
              radial-gradient(700px 420px at 85% 80%, rgba(24,119,242,0.18), transparent 60%),
              linear-gradient(135deg, #0b1b3a, #081126 55%, #060c1c);
            min-height: 100vh;
            padding: 2rem 1rem;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
            background: rgba(255,255,255,0.96);
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(4, 9, 20, 0.55);
            padding: 2.5rem;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .brand-row{
            display:flex;
            align-items:center;
            justify-content:center;
            margin-bottom: 1.25rem;
        }
        .brand-row img{
            width: 96px;
            height: 96px;
            object-fit: contain;
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
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 1rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
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
        
        textarea { resize: vertical; min-height: 100px; }
        
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="brand-row">
            <img src="logo.png" alt="Logo do Grupo Smile">
        </div>
        <?php if ($success): ?>
            <div class="success-message">
                <h2>✓ Enviado com sucesso!</h2>
                <p>Recebemos seus dados. Nossa equipe entrará em contato em breve para dar andamento ao contrato.</p>
            </div>
        <?php else: ?>
            <h1><?php echo htmlspecialchars($titulo); ?></h1>
            <p class="subtitle">Preencha o formulário abaixo para darmos andamento ao seu contrato</p>
            
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
                <?php
                    $label_nome_evento = 'Nome do evento';
                    $ph_nome_evento = 'Ex: Aniversário da Maria';
                    if ($tipo_evento === 'infantil') {
                        $label_nome_evento = 'Nome do aniversariante';
                        $ph_nome_evento = 'Ex: Maria (15 anos)';
                    } elseif ($tipo_evento === 'pj') {
                        $label_nome_evento = 'Nome do evento';
                        $ph_nome_evento = 'Ex: Confraternização Empresa X';
                    }
                ?>

                <!-- Seção: Cliente -->
                <div class="form-section">
                    <div class="form-section-title">Dados do Cliente</div>

                    <div class="form-group">
                        <label for="nome_completo">Nome Completo <span class="required">*</span></label>
                        <input type="text" id="nome_completo" name="nome_completo" required value="<?php echo htmlspecialchars($_POST['nome_completo'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="cpf">CPF <span class="required">*</span></label>
                            <input type="text" id="cpf" name="cpf" required placeholder="000.000.000-00" maxlength="14" value="<?php echo htmlspecialchars($_POST['cpf'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="rg">RG <span class="required">*</span></label>
                            <input type="text" id="rg" name="rg" required value="<?php echo htmlspecialchars($_POST['rg'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="telefone">Telefone/WhatsApp <span class="required">*</span></label>
                            <input type="text" id="telefone" name="telefone" required placeholder="(00) 00000-0000" value="<?php echo htmlspecialchars($_POST['telefone'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="email">E-mail <span class="required">*</span></label>
                            <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="instagram">Instagram / Rede Social</label>
                        <input type="text" id="instagram" name="instagram" placeholder="@usuario" value="<?php echo htmlspecialchars($_POST['instagram'] ?? ''); ?>">
                    </div>

                    <div class="form-group">
                        <label for="cep">CEP <span class="required">*</span></label>
                        <input type="text" id="cep" name="cep" required placeholder="00000-000" maxlength="9" value="<?php echo htmlspecialchars($_POST['cep'] ?? ''); ?>">
                        <small id="cep_status" style="color:#64748b;display:block;margin-top:.35rem;"></small>
                    </div>

                    <div class="form-group">
                        <label for="endereco_completo">Endereço <span class="required">*</span></label>
                        <input type="text" id="endereco_completo" name="endereco_completo" required placeholder="Rua, Avenida, etc" value="<?php echo htmlspecialchars($_POST['endereco_completo'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="numero">Número <span class="required">*</span></label>
                            <input type="text" id="numero" name="numero" required value="<?php echo htmlspecialchars($_POST['numero'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="complemento">Complemento</label>
                            <input type="text" id="complemento" name="complemento" placeholder="Apto, Bloco, etc" value="<?php echo htmlspecialchars($_POST['complemento'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="bairro">Bairro <span class="required">*</span></label>
                            <input type="text" id="bairro" name="bairro" required value="<?php echo htmlspecialchars($_POST['bairro'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="cidade">Cidade <span class="required">*</span></label>
                            <input type="text" id="cidade" name="cidade" required value="<?php echo htmlspecialchars($_POST['cidade'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="estado">Estado (UF) <span class="required">*</span></label>
                            <input type="text" id="estado" name="estado" required placeholder="SP" maxlength="2" value="<?php echo htmlspecialchars($_POST['estado'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="pais">País</label>
                            <input type="text" id="pais" name="pais" value="<?php echo htmlspecialchars($_POST['pais'] ?? 'Brasil'); ?>">
                        </div>
                    </div>
                </div>

                <!-- Seção: Evento -->
                <div class="form-section">
                    <div class="form-section-title">Dados do Evento</div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="data_evento">Data do Evento <span class="required">*</span></label>
                            <input type="date" id="data_evento" name="data_evento" required min="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($_POST['data_evento'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="unidade">Local do Evento (Unidade) <span class="required">*</span></label>
                            <select id="unidade" name="unidade" required>
                                <option value="">Selecione...</option>
                                <?php if (empty($locais_mapeados)): ?>
                                    <option value="" disabled>Nenhum local disponível no momento.</option>
                                <?php else: ?>
                                    <?php foreach ($locais_mapeados as $local): ?>
                                        <option value="<?php echo htmlspecialchars($local['me_local_nome']); ?>" <?php echo (($_POST['unidade'] ?? '') === $local['me_local_nome']) ? 'selected' : ''; ?>>
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
                            <input type="time" id="horario_inicio" name="horario_inicio" required value="<?php echo htmlspecialchars($_POST['horario_inicio'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="horario_termino">Horário de Término <span class="required">*</span></label>
                            <input type="time" id="horario_termino" name="horario_termino" required value="<?php echo htmlspecialchars($_POST['horario_termino'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="nome_evento"><?php echo htmlspecialchars($label_nome_evento); ?> <span class="required">*</span></label>
                        <input type="text" id="nome_evento" name="nome_evento" required placeholder="<?php echo htmlspecialchars($ph_nome_evento); ?>" value="<?php echo htmlspecialchars($_POST['nome_evento'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="num_convidados">Nº de Convidados <span class="required">*</span></label>
                            <input type="number" id="num_convidados" name="num_convidados" required min="1" value="<?php echo htmlspecialchars($_POST['num_convidados'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label for="como_conheceu">Como conheceu <span class="required">*</span></label>
                            <select id="como_conheceu" name="como_conheceu" required>
                                <option value="">Selecione...</option>
                                <option value="instagram" <?php echo (($_POST['como_conheceu'] ?? '') === 'instagram') ? 'selected' : ''; ?>>Instagram</option>
                                <option value="facebook" <?php echo (($_POST['como_conheceu'] ?? '') === 'facebook') ? 'selected' : ''; ?>>Facebook</option>
                                <option value="google" <?php echo (($_POST['como_conheceu'] ?? '') === 'google') ? 'selected' : ''; ?>>Google</option>
                                <option value="indicacao" <?php echo (($_POST['como_conheceu'] ?? '') === 'indicacao') ? 'selected' : ''; ?>>Indicação</option>
                                <option value="outro" <?php echo (($_POST['como_conheceu'] ?? '') === 'outro') ? 'selected' : ''; ?>>Outro</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group" id="div_como_conheceu_outro" style="display: none;">
                        <label for="como_conheceu_outro">Especifique como conheceu <span class="required">*</span></label>
                        <input type="text" id="como_conheceu_outro" name="como_conheceu_outro" value="<?php echo htmlspecialchars($_POST['como_conheceu_outro'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Seção: Pacote -->
                <div class="form-section">
                    <div class="form-section-title">Pacote/Plano</div>
                    <div class="form-group">
                        <label for="pacote_plano">Pacote/Plano escolhido <span class="required">*</span></label>
                        <textarea id="pacote_plano" name="pacote_plano" required placeholder="Descreva o pacote ou plano escolhido..." rows="3"><?php echo htmlspecialchars($_POST['pacote_plano'] ?? ''); ?></textarea>
                    </div>
                </div>

                <!-- Observações -->
                <div class="form-section">
                    <div class="form-section-title">Observações</div>
                    <div class="form-group">
                        <label for="observacoes">Observações (opcional)</label>
                        <textarea id="observacoes" name="observacoes" placeholder="Informações adicionais..."><?php echo htmlspecialchars($_POST['observacoes'] ?? ''); ?></textarea>
                    </div>
                </div>
                
                <button type="submit" class="btn" <?php echo $rate_limit_excedido ? 'disabled' : ''; ?>>
                    Enviar
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

        // Máscara CEP
        document.getElementById('cep')?.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\\D/g, '');
            if (value.length <= 8) {
                value = value.replace(/(\\d{5})(\\d)/, '$1-$2');
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
            const digits = (cepEl.value || '').replace(/\\D/g, '');
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
            if (!divOutro || !inputOutro) return;
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
            const divOutro = document.getElementById('div_como_conheceu_outro');
            const inputOutro = document.getElementById('como_conheceu_outro');
            if (divOutro && inputOutro) {
                divOutro.style.display = 'block';
                inputOutro.required = true;
            }
        }
    </script>
</body>
</html>
