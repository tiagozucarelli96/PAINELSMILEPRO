<?php
/**
 * vendas_form_casamento.php
 * Formulário público para solicitação de orçamento - Casamento
 * Campos conforme especificação do Ajuste 8
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/vendas_helper.php';

$tipo_evento = 'casamento';
$titulo = 'Solicite seu Orçamento - Casamento';

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
        
        // Validar que término é depois do início
        $inicio_ts = strtotime($horario_inicio);
        $termino_ts = strtotime($horario_termino);
        if ($termino_ts <= $inicio_ts) {
            throw new Exception('Horário de término deve ser após o horário de início');
        }
        
        // Validar que unidade está mapeada
        $me_local_id = vendas_validar_local_mapeado($unidade);
        if (!$me_local_id) {
            throw new Exception('Local não mapeado. Ajuste em Logística > Conexão.');
        }
        
        if (empty($nome_noivos)) {
            throw new Exception('Nome dos noivos é obrigatório');
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
        
        // Montar endereço completo
        $endereco_formatado = trim($endereco_completo . ', ' . $numero . 
            (!empty($complemento) ? ' - ' . $complemento : '') . 
            ' - ' . $bairro . ' - ' . $cidade . '/' . $estado . ' - CEP: ' . $cep);
        
        // Inserir pré-contrato
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
            $endereco_formatado,
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
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem 1rem;
        }
        
        .container {
            max-width: 700px;
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
                                    <option value="" disabled>Nenhum local mapeado. Ajuste em Logística > Conexão.</option>
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
                                    ⚠️ Nenhum local mapeado. Ajuste em Logística > Conexão.
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
                        <label for="nome_noivos">Nome dos Noivos <span class="required">*</span></label>
                        <input type="text" id="nome_noivos" name="nome_noivos" required 
                               placeholder="Ex: João e Maria"
                               value="<?php echo htmlspecialchars($_POST['nome_noivos'] ?? ''); ?>">
                        <small style="color: #64748b; display: block; margin-top: 0.25rem;">
                            Este nome será usado como nome do evento na ME
                        </small>
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
                        <small style="color: #64748b; display: block; margin-top: 0.25rem;">
                            ⚠️ Este campo é interno e não será enviado para a ME
                        </small>
                    </div>
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
    </script>
</body>
</html>
