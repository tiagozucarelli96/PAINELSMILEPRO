<?php
// comercial_degust_public.php — Página pública de inscrição
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/asaas_helper.php';

$token = $_GET['t'] ?? '';
if (!$token) {
    die('Token de acesso inválido');
}

// Buscar degustação pelo token
$stmt = $pdo->prepare("SELECT * FROM comercial_degustacoes WHERE token_publico = :token");
$stmt->execute([':token' => $token]);
$degustacao = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$degustacao) {
    die('Degustação não encontrada');
}

// Verificar se está publicada
if ($degustacao['status'] !== 'publicado') {
    die('Degustação não está disponível para inscrições');
}

// Verificar data limite - bloquear ao final do dia (23:59:59)
$hoje = date('Y-m-d');
$agora = new DateTime();
$data_limite = new DateTime($degustacao['data_limite'] . ' 23:59:59');

if ($agora > $data_limite) {
    $inscricoes_encerradas = true;
} else {
    $inscricoes_encerradas = false;
}

// Verificar capacidade
$stmt = $pdo->prepare("SELECT COUNT(*) FROM comercial_inscricoes WHERE degustacao_id = :id AND status = 'confirmado'");
$stmt->execute([':id' => $degustacao['id']]);
$inscritos_count = $stmt->fetchColumn();

$lotado = $inscritos_count >= $degustacao['capacidade'];
$aceita_lista_espera = $degustacao['lista_espera'] && $lotado;

// Processar inscrição
$success_message = '';
$error_message = '';

if ($_POST && !$inscricoes_encerradas) {
    try {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $celular = trim($_POST['celular'] ?? '');
        $tipo_festa = $_POST['tipo_festa'] ?? '';
        $qtd_pessoas = (int)($_POST['qtd_pessoas'] ?? 1);
        $fechou_contrato = $_POST['fechou_contrato'] ?? 'nao';
        $nome_titular_contrato = trim($_POST['nome_titular_contrato'] ?? '');
        $cpf_3_digitos = trim($_POST['cpf_3_digitos'] ?? '');
        
        // Respostas do formulário
        $dados_json = [];
        $campos = json_decode($degustacao['campos_json'], true) ?: [];
        foreach ($campos as $campo) {
            $value = $_POST[$campo['name']] ?? '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $dados_json[$campo['name']] = $value;
        }
        
        // Validar campos obrigatórios
        if (!$nome || !$email || !$tipo_festa || !$qtd_pessoas) {
            throw new Exception("Preencha todos os campos obrigatórios");
        }
        
        // Usar valores calculados pelo JavaScript ou calcular no servidor
        $valor_total = (float)($_POST['valor_total'] ?? 0);
        $extras = (int)($_POST['extras'] ?? 0);
        
        if ($valor_total <= 0) {
            // Fallback: calcular no servidor se não veio do JavaScript
            $incluidos = $tipo_festa === 'casamento' ? $degustacao['incluidos_casamento'] : $degustacao['incluidos_15anos'];
            $extras = max(0, $qtd_pessoas - $incluidos);
            $preco_base = $tipo_festa === 'casamento' ? $degustacao['preco_casamento'] : $degustacao['preco_15anos'];
            $valor_total = $preco_base + ($extras * $degustacao['preco_extra']);
        }
        
        // Determinar status
        $status = 'confirmado';
        if ($lotado && !$aceita_lista_espera) {
            throw new Exception("Degustação lotada e não aceita lista de espera");
        } elseif ($lotado && $aceita_lista_espera) {
            $status = 'lista_espera';
        }
        
        // Verificar se já existe inscrição com este e-mail
        $stmt = $pdo->prepare("SELECT id FROM comercial_inscricoes WHERE degustacao_id = :degustacao_id AND email = :email");
        $stmt->execute([':degustacao_id' => $degustacao['id'], ':email' => $email]);
        if ($stmt->fetch()) {
            throw new Exception("Já existe uma inscrição com este e-mail para esta degustação");
        }
        
        // Verificar se colunas existem antes de inserir
        try {
            $check_stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                                       WHERE table_name = 'comercial_inscricoes' 
                                       AND column_name IN ('nome_titular_contrato', 'cpf_3_digitos', 'me_event_id', 'me_cliente_cpf')");
            $check_columns = $check_stmt->fetchAll(PDO::FETCH_COLUMN);
            $has_nome_titular = in_array('nome_titular_contrato', $check_columns);
            $has_cpf_3 = in_array('cpf_3_digitos', $check_columns);
            $has_me_event_id = in_array('me_event_id', $check_columns);
            $has_me_cpf = in_array('me_cliente_cpf', $check_columns);
        } catch (PDOException $e) {
            $has_nome_titular = false;
            $has_cpf_3 = false;
            $has_me_event_id = false;
            $has_me_cpf = false;
        }
        
        $cpf_3_digitos = substr(preg_replace('/\D/', '', $_POST['me_cliente_cpf'] ?? ''), 0, 3);
        $me_cliente_cpf = preg_replace('/\D/', '', $_POST['me_cliente_cpf'] ?? '');
        $me_event_id = (int)($_POST['me_event_id'] ?? 0);
        
        // Montar SQL dinamicamente baseado nas colunas existentes
        // Verificar quais colunas existem na tabela
        try {
            $check_all_cols = $pdo->query("SELECT column_name FROM information_schema.columns 
                                           WHERE table_name = 'comercial_inscricoes'");
            $existing_columns = $check_all_cols->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $existing_columns = [];
        }
        
        // Verificar se coluna é telefone ou celular
        $telefone_col = in_array('telefone', $existing_columns) ? 'telefone' : 
                       (in_array('celular', $existing_columns) ? 'celular' : 'telefone');
        
        $telefone = $_POST['telefone'] ?? $_POST['celular'] ?? '';
        
        // Campos obrigatórios (sempre existem)
        $campos = ['degustacao_id', 'status', 'fechou_contrato', 'nome', 'email', 'dados_json'];
        $valores = [':degustacao_id', ':status', ':fechou_contrato', ':nome', ':email', ':dados_json'];
        $params = [
            ':degustacao_id' => $degustacao['id'],
            ':status' => $status,
            ':fechou_contrato' => $fechou_contrato,
            ':nome' => $nome,
            ':email' => $email,
            ':dados_json' => json_encode($dados_json)
        ];
        
        // Campos opcionais - adicionar apenas se existirem na tabela
        if (in_array($telefone_col, $existing_columns)) {
            $campos[] = $telefone_col;
            $valores[] = ':telefone';
            $params[':telefone'] = $telefone;
        }
        
        if (in_array('qtd_pessoas', $existing_columns)) {
            $campos[] = 'qtd_pessoas';
            $valores[] = ':qtd_pessoas';
            $params[':qtd_pessoas'] = $qtd_pessoas;
        }
        
        if (in_array('tipo_festa', $existing_columns)) {
            $campos[] = 'tipo_festa';
            $valores[] = ':tipo_festa';
            $params[':tipo_festa'] = $tipo_festa;
        }
        
        if (in_array('extras', $existing_columns)) {
            $campos[] = 'extras';
            $valores[] = ':extras';
            $params[':extras'] = $extras;
        }
        
        if (in_array('pagamento_status', $existing_columns)) {
            $campos[] = 'pagamento_status';
            $valores[] = ':pagamento_status';
            $params[':pagamento_status'] = $fechou_contrato === 'sim' ? 'nao_aplicavel' : 'aguardando';
        }
        
        if (in_array('valor_pago', $existing_columns)) {
            $campos[] = 'valor_pago';
            $valores[] = ':valor_pago';
            $params[':valor_pago'] = $fechou_contrato === 'sim' ? 0 : $valor_total;
        }
        
        if (in_array('ip_origem', $existing_columns)) {
            $campos[] = 'ip_origem';
            $valores[] = ':ip_origem';
            $params[':ip_origem'] = $_SERVER['REMOTE_ADDR'] ?? null;
        }
        
        if (in_array('user_agent_origem', $existing_columns)) {
            $campos[] = 'user_agent_origem';
            $valores[] = ':user_agent_origem';
            $params[':user_agent_origem'] = $_SERVER['HTTP_USER_AGENT'] ?? null;
        }
        
        // Adicionar colunas opcionais se existirem
        if ($has_nome_titular) {
            $campos[] = 'nome_titular_contrato';
            $valores[] = ':nome_titular_contrato';
            $params[':nome_titular_contrato'] = $nome_titular_contrato;
        }
        
        if ($has_cpf_3) {
            $campos[] = 'cpf_3_digitos';
            $valores[] = ':cpf_3_digitos';
            $params[':cpf_3_digitos'] = $cpf_3_digitos;
        }
        
        if ($has_me_event_id && $me_event_id > 0) {
            $campos[] = 'me_event_id';
            $valores[] = ':me_event_id';
            $params[':me_event_id'] = $me_event_id;
        }
        
        if ($has_me_cpf && !empty($me_cliente_cpf)) {
            $campos[] = 'me_cliente_cpf';
            $valores[] = ':me_cliente_cpf';
            $params[':me_cliente_cpf'] = $me_cliente_cpf;
        }
        
        // Montar SQL final
        $sql = "INSERT INTO comercial_inscricoes (" . implode(', ', $campos) . ") VALUES (" . implode(', ', $valores) . ")";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inscricao_id = $pdo->lastInsertId();
        
        // Se não fechou contrato, processar pagamento ASAAS
        if ($fechou_contrato === 'nao' && $valor_total > 0) {
            try {
                $asaasHelper = new AsaasHelper();
                
                // Dados do customer
                $customer_data = [
                    'name' => $nome,
                    'email' => $email,
                    'phone' => $telefone,
                    'external_reference' => 'inscricao_' . $inscricao_id
                ];
                
                // Dados do pagamento
                $payment_data = [
                    'value' => $valor_total,
                    'description' => "Degustação: {$degustacao['nome']} - {$tipo_festa} ({$qtd_pessoas} pessoas)",
                    'external_reference' => 'inscricao_' . $inscricao_id,
                    'success_url' => "https://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}?success=1",
                    'customer_data' => $customer_data
                ];
                
                // Criar pagamento no ASAAS
                $payment_response = $asaasHelper->createPixPayment($payment_data);
                
                if ($payment_response && isset($payment_response['id'])) {
                    // Verificar se colunas existem antes de atualizar
                    try {
                        $check_stmt = $pdo->query("SELECT column_name FROM information_schema.columns 
                                                   WHERE table_name = 'comercial_inscricoes' 
                                                   AND column_name IN ('asaas_payment_id', 'valor_pago')");
                        $check_columns = $check_stmt->fetchAll(PDO::FETCH_COLUMN);
                        $has_asaas_payment_id = in_array('asaas_payment_id', $check_columns);
                        $has_valor_pago = in_array('valor_pago', $check_columns);
                    } catch (PDOException $e) {
                        $has_asaas_payment_id = false;
                        $has_valor_pago = false;
                    }
                    
                    // Atualizar inscrição com payment_id do ASAAS
                    $update_fields = ["pagamento_status = 'aguardando'"];
                    $update_params = [':id' => $inscricao_id];
                    
                    if ($has_asaas_payment_id) {
                        $update_fields[] = "asaas_payment_id = :payment_id";
                        $update_params[':payment_id'] = $payment_response['id'];
                    }
                    
                    if ($has_valor_pago) {
                        $update_fields[] = "valor_pago = :valor_pago";
                        $update_params[':valor_pago'] = $valor_total;
                    }
                    
                    $update_sql = "UPDATE comercial_inscricoes SET " . implode(', ', $update_fields) . " WHERE id = :id";
                    $stmt = $pdo->prepare($update_sql);
                    $stmt->execute($update_params);
                    
                    // Redirecionar para página de pagamento
                    header("Location: comercial_pagamento.php?payment_id={$payment_response['id']}&inscricao_id={$inscricao_id}");
                    exit;
                } else {
                    throw new Exception("Erro ao criar pagamento no ASAAS");
                }
                
            } catch (Exception $e) {
                $error_message = "Erro ao processar pagamento: " . $e->getMessage();
            }
        }
        
        // TODO: Enviar e-mail de confirmação
        
        $success_message = "Inscrição realizada com sucesso!";
        
    } catch (Exception $e) {
        $error_message = "Erro: " . $e->getMessage();
    }
}


?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($degustacao['nome']) ?> - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        .public-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .event-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 12px;
        }
        
        .event-title {
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 10px 0;
        }
        
        .event-details {
            font-size: 18px;
            opacity: 0.9;
        }
        
        .instructions {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .form-container {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 16px;
            background: white;
        }
        
        .form-radio-group {
            display: flex;
            gap: 20px;
            margin-top: 10px;
        }
        
        .form-radio {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-radio input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: #3b82f6;
        }
        
        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .form-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #3b82f6;
        }
        
        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 15px;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            margin-top: 20px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .btn-submit:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fbbf24;
        }
        
        .price-info {
            background: #f0f9ff;
            border: 1px solid #0ea5e9;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .price-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .price-total {
            font-weight: 700;
            font-size: 18px;
            border-top: 1px solid #0ea5e9;
            padding-top: 10px;
            margin-top: 10px;
        }
        
        .hidden {
            display: none;
        }
    </style>
</head>
<body>
    <div class="public-container">
        <!-- Header do Evento -->
        <div class="event-header">
            <h1 class="event-title"><?= h($degustacao['nome']) ?></h1>
            <div class="event-details">
                📅 <?= date('d/m/Y', strtotime($degustacao['data'])) ?> 
                🕐 <?= date('H:i', strtotime($degustacao['hora_inicio'])) ?> - <?= date('H:i', strtotime($degustacao['hora_fim'])) ?>
                📍 <?= h($degustacao['local']) ?>
            </div>
        </div>
        
        <!-- Instruções -->
        <?php if ($degustacao['instrutivo_html']): ?>
        <div class="instructions">
            <?= $degustacao['instrutivo_html'] ?>
        </div>
        <?php endif; ?>
        
        <!-- Mensagens -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                ✅ <?= h($success_message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                ❌ <?= h($error_message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($inscricoes_encerradas): ?>
            <div class="alert alert-warning">
                ⏰ Inscrições encerradas. A data limite foi <?= date('d/m/Y', strtotime($degustacao['data_limite'])) ?>.
            </div>
        <?php elseif ($lotado && !$aceita_lista_espera): ?>
            <div class="alert alert-warning">
                🚫 Degustação lotada. Capacidade: <?= $degustacao['capacidade'] ?> pessoas.
            </div>
        <?php elseif ($lotado && $aceita_lista_espera): ?>
            <div class="alert alert-warning">
                ⚠️ Degustação lotada, mas você pode se inscrever na lista de espera.
            </div>
        <?php else: ?>
        
        <!-- Formulário de Inscrição -->
        <div class="form-container">
            <form method="POST" id="inscricaoForm">
                <h2 style="margin-bottom: 20px; color: #1e3a8a;">📝 Inscrição</h2>
                
                <!-- Já fechou contrato? (PRIMEIRO) -->
                <div class="form-group">
                    <label class="form-label">Já fechou seu evento conosco? *</label>
                    <div class="form-radio-group">
                        <div class="form-radio">
                            <input type="radio" name="fechou_contrato" value="sim" id="fechou_sim" required onchange="toggleContratoInfo()">
                            <label for="fechou_sim">Sim, já fechei</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="fechou_contrato" value="nao" id="fechou_nao" required onchange="toggleContratoInfo()">
                            <label for="fechou_nao">Não, ainda não fechei</label>
                        </div>
                    </div>
                </div>
                
                <!-- Buscar Evento (se já fechou) -->
                <div id="buscaEventoContainer" class="hidden" style="margin-bottom: 20px; padding: 15px; background: #f0f9ff; border: 2px solid #0ea5e9; border-radius: 8px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <span style="font-weight: 600; color: #0c4a6e;">🔍 Busque seu evento cadastrado</span>
                    </div>
                    <button type="button" onclick="abrirModalBuscaME()" class="btn-secondary" style="width: 100%; padding: 12px 20px; white-space: nowrap;">
                        🔍 Buscar Evento
                    </button>
                    <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 8px;">
                        💡 Se você já fechou evento conosco, busque aqui e seus dados serão preenchidos automaticamente
                    </small>
                    
                    <!-- Informações do evento encontrado -->
                    <div id="meEventInfo" class="alert alert-success hidden" style="margin-top: 15px;">
                        <h4 style="margin: 0 0 10px 0; font-size: 16px;">✅ Evento encontrado!</h4>
                        <div id="meEventDetails"></div>
                    </div>
                </div>
                
                <!-- Dados Básicos (preenchidos automaticamente ou manualmente) -->
                <div class="form-group">
                    <label class="form-label">Nome Completo *</label>
                    <input type="text" name="nome" id="nomeInput" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">E-mail *</label>
                    <input type="email" name="email" id="emailInput" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Celular *</label>
                    <input type="tel" name="telefone" id="telefoneInput" class="form-input" required>
                </div>
                
                <!-- Tipo de Festa -->
                <div class="form-group">
                    <label class="form-label">Tipo de Festa *</label>
                    <div class="form-radio-group">
                        <div class="form-radio">
                            <input type="radio" name="tipo_festa" value="casamento" id="casamento" required onchange="mostrarPessoasIncluidas()">
                            <label for="casamento">Casamento (R$ <?= number_format($degustacao['preco_casamento'], 2, ',', '.') ?>)</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="tipo_festa" value="15anos" id="15anos" required onchange="mostrarPessoasIncluidas()">
                            <label for="15anos">15 Anos (R$ <?= number_format($degustacao['preco_15anos'], 2, ',', '.') ?>)</label>
                        </div>
                    </div>
                </div>
                
                <!-- Quantidade de Pessoas -->
                <div class="form-group">
                    <label class="form-label">Quantidade de Pessoas Adicionais *</label>
                    <input type="number" name="qtd_pessoas" id="qtdPessoasInput" class="form-input" min="0" value="0" required onchange="calcularPreco()">
                    <small style="color: #6b7280; font-size: 14px; display: block; margin-top: 5px;">
                        💡 Digite quantas pessoas adicionais além das incluídas no valor base
                    </small>
                    <div id="pessoasIncluidasInfo" style="margin-top: 8px; padding: 10px; background: #f0f9ff; border-left: 3px solid #0ea5e9; border-radius: 4px; display: none;">
                        <span style="font-weight: 600; color: #0c4a6e;">ℹ️ Total incluído no valor base:</span>
                        <span id="totalIncluido" style="color: #0369a1; font-weight: 600;"></span>
                        <span style="color: #0369a1;"> pessoas</span>
                    </div>
                </div>
                
                <!-- Informações de Preço -->
                <div id="priceInfo" class="price-info" style="display: none;">
                    <h3 style="margin: 0 0 10px 0; color: #0ea5e9;">💰 Resumo do Valor</h3>
                    <div class="price-item">
                        <span>Valor base:</span>
                        <span id="precoBase">R$ 0,00</span>
                    </div>
                    <div class="price-item">
                        <span>Pessoas extras:</span>
                        <span id="extrasInfo">0 x R$ <?= number_format($degustacao['preco_extra'], 2, ',', '.') ?></span>
                    </div>
                    <div class="price-item price-total">
                        <span>Total:</span>
                        <span id="valorTotal">R$ 0,00</span>
                    </div>
                </div>
                
                <!-- Campos ocultos para dados da ME -->
                <input type="hidden" name="nome_titular_contrato" id="nomeTitularHidden">
                <input type="hidden" name="cpf_3_digitos" id="cpf3DigitosHidden">
                <input type="hidden" name="me_cliente_cpf" id="meClienteCpfHidden">
                <input type="hidden" name="me_event_id" id="meEventIdHidden">
                
                <!-- Campos dinâmicos do Form Builder -->
                <?php
                $campos = json_decode($degustacao['campos_json'], true) ?: [];
                foreach ($campos as $campo):
                ?>
                    <div class="form-group">
                        <label class="form-label"><?= h($campo['label']) ?> <?= $campo['required'] ? '*' : '' ?></label>
                        
                        <?php if ($campo['type'] === 'texto' || $campo['type'] === 'email' || $campo['type'] === 'celular' || $campo['type'] === 'cpf_cnpj' || $campo['type'] === 'numero'): ?>
                            <input type="<?= $campo['type'] === 'email' ? 'email' : ($campo['type'] === 'numero' ? 'number' : 'text') ?>" 
                                   name="<?= h($campo['name']) ?>" class="form-input" 
                                   <?= $campo['required'] ? 'required' : '' ?>>
                        
                        <?php elseif ($campo['type'] === 'data'): ?>
                            <input type="date" name="<?= h($campo['name']) ?>" class="form-input" 
                                   <?= $campo['required'] ? 'required' : '' ?>>
                        
                        <?php elseif ($campo['type'] === 'select'): ?>
                            <select name="<?= h($campo['name']) ?>" class="form-select" 
                                    <?= $campo['required'] ? 'required' : '' ?>>
                                <option value="">Selecione...</option>
                                <?php foreach ($campo['options'] as $option): ?>
                                    <option value="<?= h($option) ?>"><?= h($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        
                        <?php elseif ($campo['type'] === 'radio'): ?>
                            <div class="form-radio-group">
                                <?php foreach ($campo['options'] as $option): ?>
                                    <div class="form-radio">
                                        <input type="radio" name="<?= h($campo['name']) ?>" value="<?= h($option) ?>" 
                                               id="<?= h($campo['name'] . '_' . $option) ?>" 
                                               <?= $campo['required'] ? 'required' : '' ?>>
                                        <label for="<?= h($campo['name'] . '_' . $option) ?>"><?= h($option) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        
                        <?php elseif ($campo['type'] === 'checkbox'): ?>
                            <div class="form-checkbox">
                                <?php foreach ($campo['options'] as $option): ?>
                                    <div class="form-checkbox">
                                        <input type="checkbox" name="<?= h($campo['name']) ?>[]" value="<?= h($option) ?>" 
                                               id="<?= h($campo['name'] . '_' . $option) ?>">
                                        <label for="<?= h($campo['name'] . '_' . $option) ?>"><?= h($option) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        
                        <?php elseif ($campo['type'] === 'textarea'): ?>
                            <textarea name="<?= h($campo['name']) ?>" class="form-input" rows="4" 
                                      <?= $campo['required'] ? 'required' : '' ?>></textarea>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <!-- Campos ocultos para cálculo -->
                <input type="hidden" name="valor_total" id="valorTotalHidden" value="0">
                <input type="hidden" name="extras" id="extrasHidden" value="0">
                
                <button type="submit" class="btn-submit" id="btnSubmitInscricao">
                    <?= $lotado && $aceita_lista_espera ? '📋 Inscrever na Lista de Espera' : '✅ Inscrever-se' ?>
                </button>
            </form>
        </div>
        
        <?php endif; ?>
    </div>
    
    <!-- Modal de Busca ME Eventos -->
    <div id="modalBuscaME" class="modal" style="display: none;" onclick="if(event.target === this) fecharModalBuscaME()">
        <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 600px;">
            <div class="modal-header">
                <h3 class="modal-title">🔍 Buscar Evento</h3>
                <button class="close-btn" onclick="fecharModalBuscaME()">&times;</button>
            </div>
            
            <div style="padding: 20px;">
                <!-- Busca por Nome -->
                <div id="buscaMEPorNome">
                    <div class="form-group">
                        <label class="form-label">Digite o nome do titular do contrato</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" id="buscaMENome" class="form-input" placeholder="Ex: João Silva" style="flex: 1;">
                            <button type="button" onclick="buscarClienteME()" class="btn-primary" style="padding: 12px 24px;">
                                🔍 Buscar
                            </button>
                        </div>
                        <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                            Digite pelo menos 3 caracteres para buscar
                        </small>
                    </div>
                    
                    <div id="buscaMELoading" style="display: none; text-align: center; padding: 20px; color: #64748b;">
                        <div>⏳ Buscando...</div>
                    </div>
                    
                    <div id="buscaMEResultados" style="margin-top: 15px;"></div>
                </div>
                
                <!-- Validação de CPF -->
                <div id="buscaMEValidarCPF" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
                    <div class="form-group">
                        <label class="form-label">Cliente selecionado:</label>
                        <div style="padding: 10px; background: #f0f9ff; border: 1px solid #0ea5e9; border-radius: 6px; font-weight: 600; color: #0c4a6e;">
                            <span id="nomeClienteSelecionado"></span>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Digite seu CPF completo para confirmar *</label>
                        <input type="text" id="buscaMECpf" class="form-input" placeholder="000.000.000-00" maxlength="14" 
                               oninput="this.value = this.value.replace(/\D/g, '').replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')">
                        <small style="color: #6b7280; font-size: 12px; display: block; margin-top: 5px;">
                            🔒 Seus dados estão seguros. O CPF será usado apenas para confirmação.
                        </small>
                    </div>
                    
                    <div id="buscaMEValidarLoading" style="display: none; text-align: center; padding: 10px; color: #64748b;">
                        ⏳ Validando CPF...
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 15px;">
                        <button type="button" onclick="fecharModalBuscaME()" class="btn-cancel" style="flex: 1;">
                            Cancelar
                        </button>
                        <button type="button" onclick="validarCPFME()" class="btn-save" style="flex: 1;">
                            ✅ Confirmar CPF
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <style>
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            padding: 20px;
            box-sizing: border-box;
            overflow: auto;
        }
        
        .modal[style*="flex"] {
            display: flex !important;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            margin: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #6b7280;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }
        
        .close-btn:hover {
            background: #f3f4f6;
        }
        
        .cliente-item-me:hover {
            background: #e0f2fe !important;
            border-color: #0ea5e9 !important;
            transform: translateX(2px);
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
    </style>
    
    <script>
        const PRECOS = {
            casamento: {
                base: <?= $degustacao['preco_casamento'] ?>,
                incluidos: <?= $degustacao['incluidos_casamento'] ?>
            },
            '15anos': {
                base: <?= $degustacao['preco_15anos'] ?>,
                incluidos: <?= $degustacao['incluidos_15anos'] ?>
            },
            extra: <?= $degustacao['preco_extra'] ?>
        };
        
        // Mostrar pessoas incluídas baseado no tipo de festa
        function mostrarPessoasIncluidas() {
            const tipoFesta = document.querySelector('input[name="tipo_festa"]:checked');
            if (!tipoFesta) {
                document.getElementById('pessoasIncluidasInfo').style.display = 'none';
                return;
            }
            
            const incluidos = tipoFesta.value === 'casamento' ? 
                PRECOS.casamento.incluidos : 
                PRECOS['15anos'].incluidos;
            
            document.getElementById('totalIncluido').textContent = incluidos;
            document.getElementById('pessoasIncluidasInfo').style.display = 'block';
            
            // Campo quantidade sempre começa em 0 (extras)
            document.getElementById('qtdPessoasInput').value = 0;
            
            // Calcular preço automaticamente
            calcularPreco();
        }
        
        function calcularPreco() {
            const tipoFesta = document.querySelector('input[name="tipo_festa"]:checked');
            const qtdExtras = parseInt(document.getElementById('qtdPessoasInput').value) || 0;
            
            if (!tipoFesta) {
                document.getElementById('priceInfo').style.display = 'none';
                return;
            }
            
            const precoInfo = tipoFesta.value === 'casamento' ? PRECOS.casamento : PRECOS['15anos'];
            const precoBase = precoInfo.base;
            const incluidos = precoInfo.incluidos;
            const precoExtra = PRECOS.extra;
            
            // Total de pessoas = incluídos + extras
            const totalPessoas = incluidos + qtdExtras;
            
            // Valor total = base + (extras * preço extra)
            const valorTotal = precoBase + (qtdExtras * precoExtra);
            
            document.getElementById('precoBase').textContent = 'R$ ' + precoBase.toFixed(2).replace('.', ',');
            
            if (qtdExtras > 0) {
                document.getElementById('extrasInfo').textContent = qtdExtras + ' pessoa(s) extra(s) x R$ ' + precoExtra.toFixed(2).replace('.', ',') + ' = R$ ' + (qtdExtras * precoExtra).toFixed(2).replace('.', ',');
            } else {
                document.getElementById('extrasInfo').textContent = '0 pessoa(s) extra(s) (dentro do valor base)';
            }
            
            document.getElementById('valorTotal').textContent = 'R$ ' + valorTotal.toFixed(2).replace('.', ',');
            
            document.getElementById('priceInfo').style.display = 'block';
            
            // Atualizar campos ocultos para o formulário (total de pessoas = incluídos + extras)
            document.getElementById('valorTotalHidden').value = valorTotal;
            document.getElementById('extrasHidden').value = qtdExtras;
            
            // Atualizar campo qtd_pessoas com o total (incluídos + extras)
            document.getElementById('qtdPessoasInput').setAttribute('data-total', totalPessoas);
        }
        
        
        // Mostrar pessoas incluídas quando tipo de festa mudar
        document.querySelectorAll('input[name="tipo_festa"]').forEach(radio => {
            radio.addEventListener('change', function() {
                mostrarPessoasIncluidas();
            });
        });
        
        // Ao submeter, garantir que qtd_pessoas seja o total (incluídos + extras)
        document.getElementById('inscricaoForm')?.addEventListener('submit', function(e) {
            const qtdPessoasInput = document.getElementById('qtdPessoasInput');
            const totalPessoas = parseInt(qtdPessoasInput.getAttribute('data-total')) || parseInt(qtdPessoasInput.value) || 0;
            qtdPessoasInput.value = totalPessoas;
        });
        
        // Inicializar texto do botão baseado na seleção inicial
        document.addEventListener('DOMContentLoaded', function() {
            const fechouNao = document.getElementById('fechou_nao');
            if (fechouNao && fechouNao.checked) {
                const btnSubmit = document.getElementById('btnSubmitInscricao');
                if (btnSubmit) {
                    btnSubmit.innerHTML = '💳 Realizar Pagamento';
                    btnSubmit.style.background = 'linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%)';
                }
            }
        });
        
        // Variáveis globais para busca ME
        let clienteSelecionadoME = null;
        
        // Função para abrir modal de busca ME
        function abrirModalBuscaME() {
            document.getElementById('modalBuscaME').style.display = 'flex';
            document.getElementById('buscaMENome').value = '';
            document.getElementById('buscaMEResultados').innerHTML = '';
            document.getElementById('buscaMELoading').style.display = 'none';
            document.getElementById('buscaMEValidarCPF').style.display = 'none';
            clienteSelecionadoME = null;
        }
        
        function toggleContratoInfo() {
            const fechouSim = document.getElementById('fechou_sim').checked;
            const buscaContainer = document.getElementById('buscaEventoContainer');
            const btnSubmit = document.getElementById('btnSubmitInscricao');
            
            if (fechouSim) {
                buscaContainer.classList.remove('hidden');
                // Se já fechou contrato, mudar texto do botão
                if (btnSubmit) {
                    btnSubmit.innerHTML = '✅ Inscrever-se';
                    btnSubmit.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
                }
            } else {
                buscaContainer.classList.add('hidden');
                document.getElementById('meEventInfo').classList.add('hidden');
                
                // Limpar campos ocultos
                document.getElementById('nomeTitularHidden').value = '';
                document.getElementById('cpf3DigitosHidden').value = '';
                document.getElementById('meClienteCpfHidden').value = '';
                document.getElementById('meEventIdHidden').value = '';
                clienteSelecionadoME = null;
                
                // Se não fechou contrato, mudar texto do botão para "Realizar Pagamento"
                if (btnSubmit) {
                    btnSubmit.innerHTML = '💳 Realizar Pagamento';
                    btnSubmit.style.background = 'linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%)';
                }
            }
        }
        
        function fecharModalBuscaME() {
            document.getElementById('modalBuscaME').style.display = 'none';
        }
        
        // Buscar cliente na ME Eventos
        async function buscarClienteME() {
            const nome = document.getElementById('buscaMENome').value.trim();
            
            if (nome.length < 3) {
                alert('Digite pelo menos 3 caracteres para buscar');
                return;
            }
            
            const loadingDiv = document.getElementById('buscaMELoading');
            const resultadosDiv = document.getElementById('buscaMEResultados');
            
            loadingDiv.style.display = 'block';
            resultadosDiv.innerHTML = '';
            
            try {
                const response = await fetch(`me_buscar_cliente.php?nome=${encodeURIComponent(nome)}`);
                const data = await response.json();
                
                loadingDiv.style.display = 'none';
                
                if (!data.ok) {
                    throw new Error(data.error || 'Erro ao buscar cliente');
                }
                
                if (!data.clientes || data.clientes.length === 0) {
                    resultadosDiv.innerHTML = '<div style="padding: 20px; text-align: center; color: #6b7280;">Nenhum cliente encontrado com este nome.</div>';
                    return;
                }
                
                // Mostrar lista de clientes encontrados
                let html = '<div style="margin-bottom: 10px; font-weight: 600; color: #374151;">Cliente(s) encontrado(s):</div>';
                html += '<div style="display: flex; flex-direction: column; gap: 8px; max-height: 300px; overflow-y: auto;">';
                
                data.clientes.forEach((cliente, index) => {
                    html += `
                        <div class="cliente-item-me" onclick="selecionarClienteME('${cliente.nome_cliente.replace(/'/g, "\\'")}', ${cliente.quantidade_eventos})" 
                             style="padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; cursor: pointer; transition: all 0.2s; background: #f8fafc;">
                            <div style="font-weight: 600; color: #1e3a8a;">${escapeHtml(cliente.nome_cliente)}</div>
                            <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">${cliente.quantidade_eventos} evento(s) encontrado(s)</div>
                        </div>
                    `;
                });
                
                html += '</div>';
                resultadosDiv.innerHTML = html;
                
            } catch (error) {
                loadingDiv.style.display = 'none';
                resultadosDiv.innerHTML = `<div style="padding: 20px; text-align: center; color: #dc2626;">Erro: ${error.message}</div>`;
            }
        }
        
        // Selecionar cliente da lista
        function selecionarClienteME(nomeCliente, qtdEventos) {
            clienteSelecionadoME = { nome: nomeCliente, eventos: qtdEventos };
            
            // Esconder resultados e mostrar validação de CPF
            document.getElementById('buscaMEResultados').innerHTML = '';
            document.getElementById('buscaMEValidarCPF').style.display = 'block';
            document.getElementById('nomeClienteSelecionado').textContent = nomeCliente;
        }
        
        // Validar CPF do cliente
        async function validarCPFME() {
            if (!clienteSelecionadoME) {
                alert('Selecione um cliente primeiro');
                return;
            }
            
            const cpf = document.getElementById('buscaMECpf').value.replace(/\D/g, '');
            
            if (cpf.length !== 11) {
                alert('CPF deve ter 11 dígitos');
                return;
            }
            
            const loadingDiv = document.getElementById('buscaMEValidarLoading');
            loadingDiv.style.display = 'block';
            
            try {
                const response = await fetch('me_buscar_cliente.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        nome_cliente: clienteSelecionadoME.nome,
                        cpf: cpf
                    })
                });
                
                const data = await response.json();
                loadingDiv.style.display = 'none';
                
                if (!data.ok) {
                    // Limpar mensagem de evento encontrado anterior
                    document.getElementById('meEventInfo').classList.add('hidden');
                    document.getElementById('meEventDetails').innerHTML = '';
                    
                    // Limpar campos preenchidos anteriormente
                    document.getElementById('nomeInput').value = '';
                    document.getElementById('emailInput').value = '';
                    document.getElementById('telefoneInput').value = '';
                    document.getElementById('nomeTitularHidden').value = '';
                    document.getElementById('cpf3DigitosHidden').value = '';
                    document.getElementById('meClienteCpfHidden').value = '';
                    document.getElementById('meEventIdHidden').value = '';
                    
                    // Mostrar erro
                    const errorMsg = data.error || 'CPF não confere ou cliente não encontrado';
                    document.getElementById('buscaMEResultados').innerHTML = `
                        <div style="padding: 20px; text-align: center; background: #fef2f2; border: 2px solid #dc2626; border-radius: 8px; color: #991b1b;">
                            <div style="font-size: 32px; margin-bottom: 10px;">❌</div>
                            <h4 style="margin: 0 0 10px 0; color: #991b1b;">Dados incorretos</h4>
                            <p style="margin: 0; line-height: 1.6;">
                                ${escapeHtml(errorMsg)}<br>
                                <strong>Por favor, verifique:</strong><br>
                                • O nome digitado está correto?<br>
                                • O CPF está de acordo com o contrato?<br>
                                <small style="display: block; margin-top: 10px; opacity: 0.8;">
                                    Você pode tentar novamente clicando em "Buscar Evento"
                                </small>
                            </p>
                        </div>
                    `;
                    
                    // Voltar para tela de busca
                    document.getElementById('buscaMEValidarCPF').style.display = 'none';
                    document.getElementById('buscaMENome').value = clienteSelecionadoME.nome;
                    clienteSelecionadoME = null;
                    
                    throw new Error(errorMsg);
                }
                
                // CPF validado com sucesso! Preencher campos automaticamente
                const evento = data.evento;
                
                // Limpar mensagem de erro anterior se existir
                document.getElementById('meEventInfo').classList.add('hidden');
                
                // Preencher campos principais automaticamente
                document.getElementById('nomeInput').value = evento.nome_cliente || '';
                
                // Preencher email (buscar em múltiplos campos possíveis)
                const emailVal = evento.email || evento.emailCliente || evento.cliente_email || evento.contato_email || '';
                if (emailVal) {
                    document.getElementById('emailInput').value = emailVal;
                    console.log('Email preenchido:', emailVal);
                } else {
                    console.warn('Email não encontrado na resposta da API ME Eventos');
                }
                
                // Preencher telefone/celular (buscar em múltiplos campos possíveis)
                const telefoneVal = evento.telefone || evento.celular || evento.telefoneCliente || 
                                   evento.celularCliente || evento.cliente_telefone || evento.cliente_celular || 
                                   evento.contato_telefone || evento.telefone_contato || '';
                if (telefoneVal) {
                    document.getElementById('telefoneInput').value = telefoneVal;
                    console.log('Telefone preenchido:', telefoneVal);
                } else {
                    console.warn('Telefone não encontrado na resposta da API ME Eventos');
                }
                
                // Preencher campos ocultos
                document.getElementById('nomeTitularHidden').value = evento.nome_cliente || '';
                document.getElementById('cpf3DigitosHidden').value = cpf.substring(0, 3);
                document.getElementById('meClienteCpfHidden').value = cpf;
                document.getElementById('meEventIdHidden').value = evento.id || '';
                
                // Mostrar informações do evento
                const eventDetails = `
                    <div style="margin-top: 10px; font-size: 14px;">
                        <div style="margin-bottom: 5px;"><strong>📅 Evento:</strong> ${escapeHtml(evento.nome_evento || 'N/A')}</div>
                        <div style="margin-bottom: 5px;"><strong>📆 Data:</strong> ${formatarData(evento.data_evento)}</div>
                        <div style="margin-bottom: 5px;"><strong>🎉 Tipo:</strong> ${escapeHtml(evento.tipo_evento || 'N/A')}</div>
                        ${evento.local_evento ? `<div><strong>📍 Local:</strong> ${escapeHtml(evento.local_evento)}</div>` : ''}
                    </div>
                `;
                
                document.getElementById('meEventDetails').innerHTML = eventDetails;
                document.getElementById('meEventInfo').classList.remove('hidden');
                
                // Fechar modal
                fecharModalBuscaME();
                
            } catch (error) {
                loadingDiv.style.display = 'none';
                alert('Erro ao validar CPF: ' + error.message);
            }
        }
        
        function formatarData(data) {
            if (!data) return '';
            const d = new Date(data + 'T00:00:00');
            return d.toLocaleDateString('pt-BR');
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Permitir busca ao pressionar Enter
        document.getElementById('buscaMENome')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                buscarClienteME();
            }
        });
        
        document.getElementById('buscaMECpf')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                validarCPFME();
            }
        });
    </script>
</body>
</html>
