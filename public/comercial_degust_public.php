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
        $campos = ['degustacao_id', 'status', 'fechou_contrato', 'nome', 'email', 'celular', 
                   'dados_json', 'qtd_pessoas', 'tipo_festa', 'extras', 'pagamento_status', 'valor_pago', 'ip_origem', 'user_agent_origem'];
        $valores = [':degustacao_id', ':status', ':fechou_contrato', ':nome', ':email', ':celular',
                    ':dados_json', ':qtd_pessoas', ':tipo_festa', ':extras', ':pagamento_status', ':valor_pago', ':ip_origem', ':user_agent_origem'];
        $params = [
            ':degustacao_id' => $degustacao['id'],
            ':status' => $status,
            ':fechou_contrato' => $fechou_contrato,
            ':nome' => $nome,
            ':email' => $email,
            ':celular' => $celular,
            ':dados_json' => json_encode($dados_json),
            ':qtd_pessoas' => $qtd_pessoas,
            ':tipo_festa' => $tipo_festa,
            ':extras' => $extras,
            ':pagamento_status' => $fechou_contrato === 'sim' ? 'nao_aplicavel' : 'aguardando',
            ':valor_pago' => $fechou_contrato === 'sim' ? 0 : $valor_total,
            ':ip_origem' => $_SERVER['REMOTE_ADDR'] ?? null,
            ':user_agent_origem' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ];
        
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
                    'phone' => $celular,
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
                
                <!-- Dados Básicos -->
                <div class="form-group">
                    <label class="form-label">Nome Completo *</label>
                    <input type="text" name="nome" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">E-mail *</label>
                    <input type="email" name="email" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Celular</label>
                    <input type="tel" name="celular" class="form-input">
                </div>
                
                <!-- Tipo de Festa -->
                <div class="form-group">
                    <label class="form-label">Tipo de Festa *</label>
                    <div class="form-radio-group">
                        <div class="form-radio">
                            <input type="radio" name="tipo_festa" value="casamento" id="casamento" required onchange="autoPreencherPessoas()">
                            <label for="casamento">Casamento (R$ <?= number_format($degustacao['preco_casamento'], 2, ',', '.') ?> - <?= $degustacao['incluidos_casamento'] ?> pessoas incluídas)</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="tipo_festa" value="15anos" id="15anos" required onchange="autoPreencherPessoas()">
                            <label for="15anos">15 Anos (R$ <?= number_format($degustacao['preco_15anos'], 2, ',', '.') ?> - <?= $degustacao['incluidos_15anos'] ?> pessoas incluídas)</label>
                        </div>
                    </div>
                </div>
                
                <!-- Quantidade de Pessoas -->
                <div class="form-group">
                    <label class="form-label">Quantidade de Pessoas *</label>
                    <input type="number" name="qtd_pessoas" id="qtdPessoasInput" class="form-input" min="1" required onchange="calcularPreco()">
                    <small style="color: #6b7280; font-size: 14px; display: block; margin-top: 5px;">
                        💡 Quantidade padrão preenchida automaticamente baseada no tipo de festa escolhido
                    </small>
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
                
                <!-- Já fechou contrato? -->
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
                
                <!-- Informações do Contrato (se já fechou) -->
                <div id="contratoInfo" class="hidden">
                    <div class="form-group">
                        <label class="form-label">Nome completo do titular do contrato *</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" name="nome_titular_contrato" id="nomeTitularInput" class="form-input" style="flex: 1;">
                            <button type="button" onclick="abrirModalBuscaME()" class="btn-secondary" style="padding: 12px 20px; white-space: nowrap;">
                                🔍 Buscar na ME
                            </button>
                        </div>
                        <small style="color: #6b7280; font-size: 14px; display: block; margin-top: 5px;">
                            💡 Clique em "Buscar na ME" para verificar se você já tem contrato cadastrado
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">3 primeiros dígitos do CPF do titular *</label>
                        <input type="text" name="cpf_3_digitos" id="cpf3DigitosInput" class="form-input" maxlength="3" pattern="[0-9]{3}" placeholder="Ex: 123">
                    </div>
                    
                    <!-- CPF completo (oculto, preenchido automaticamente após validação) -->
                    <input type="hidden" name="me_cliente_cpf" id="meClienteCpfHidden">
                    <input type="hidden" name="me_event_id" id="meEventIdHidden">
                    
                    <div id="meEventInfo" class="alert alert-success hidden" style="margin-top: 15px;">
                        <h4 style="margin: 0 0 10px 0;">✅ Contrato encontrado!</h4>
                        <div id="meEventDetails"></div>
                    </div>
                </div>
                
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
                
                <button type="submit" class="btn-submit" id="submitBtn">
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
                <h3 class="modal-title">🔍 Buscar Contrato na ME Eventos</h3>
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
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
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
        
        // Auto-preenchimento de quantidade de pessoas baseado no tipo de festa
        function autoPreencherPessoas() {
            const tipoFesta = document.querySelector('input[name="tipo_festa"]:checked');
            if (!tipoFesta) return;
            
            const qtdPadrao = tipoFesta.value === 'casamento' ? 
                PRECOS.casamento.incluidos : 
                PRECOS['15anos'].incluidos;
            
            const qtdPessoasInput = document.getElementById('qtdPessoasInput');
            qtdPessoasInput.value = qtdPadrao;
            
            // Calcular preço automaticamente após preencher
            calcularPreco();
        }
        
        function calcularPreco() {
            const tipoFesta = document.querySelector('input[name="tipo_festa"]:checked');
            const qtdPessoas = parseInt(document.getElementById('qtdPessoasInput').value) || 0;
            
            if (!tipoFesta || qtdPessoas === 0) {
                document.getElementById('priceInfo').style.display = 'none';
                return;
            }
            
            const precoInfo = tipoFesta.value === 'casamento' ? PRECOS.casamento : PRECOS['15anos'];
            const precoBase = precoInfo.base;
            const incluidos = precoInfo.incluidos;
            const precoExtra = PRECOS.extra;
            
            const extras = Math.max(0, qtdPessoas - incluidos);
            const valorTotal = precoBase + (extras * precoExtra);
            
            document.getElementById('precoBase').textContent = 'R$ ' + precoBase.toFixed(2).replace('.', ',');
            
            if (extras > 0) {
                document.getElementById('extrasInfo').textContent = extras + ' pessoa(s) extra(s) x R$ ' + precoExtra.toFixed(2).replace('.', ',') + ' = R$ ' + (extras * precoExtra).toFixed(2).replace('.', ',');
            } else {
                document.getElementById('extrasInfo').textContent = '0 pessoa(s) extra(s) (dentro do valor base)';
            }
            
            document.getElementById('valorTotal').textContent = 'R$ ' + valorTotal.toFixed(2).replace('.', ',');
            
            document.getElementById('priceInfo').style.display = 'block';
            
            // Atualizar campos ocultos para o formulário
            document.getElementById('valorTotalHidden').value = valorTotal;
            document.getElementById('extrasHidden').value = extras;
        }
        
        function toggleContratoInfo() {
            const fechouSim = document.getElementById('fechou_sim').checked;
            const contratoInfo = document.getElementById('contratoInfo');
            
            if (fechouSim) {
                contratoInfo.classList.remove('hidden');
                document.getElementById('nomeTitularInput').required = true;
                document.getElementById('cpf3DigitosInput').required = true;
            } else {
                contratoInfo.classList.add('hidden');
                document.getElementById('nomeTitularInput').required = false;
                document.getElementById('cpf3DigitosInput').required = false;
                document.getElementById('meEventInfo').classList.add('hidden');
                
                // Limpar campos
                document.getElementById('nomeTitularInput').value = '';
                document.getElementById('cpf3DigitosInput').value = '';
                document.getElementById('meClienteCpfHidden').value = '';
                document.getElementById('meEventIdHidden').value = '';
                clienteSelecionadoME = null;
            }
        }
        
        // Calcular preço quando tipo de festa mudar
        document.querySelectorAll('input[name="tipo_festa"]').forEach(radio => {
            radio.addEventListener('change', function() {
                autoPreencherPessoas();
            });
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
                    throw new Error(data.error || 'CPF não confere ou cliente não encontrado');
                }
                
                // CPF validado com sucesso! Preencher campos
                const evento = data.evento;
                
                document.getElementById('nomeTitularInput').value = evento.nome_cliente;
                document.getElementById('cpf3DigitosInput').value = cpf.substring(0, 3);
                document.getElementById('meClienteCpfHidden').value = cpf;
                document.getElementById('meEventIdHidden').value = evento.id || '';
                
                // Mostrar informações do evento
                const eventDetails = `
                    <div style="margin-top: 10px;">
                        <div><strong>Evento:</strong> ${escapeHtml(evento.nome_evento)}</div>
                        <div><strong>Data:</strong> ${formatarData(evento.data_evento)}</div>
                        <div><strong>Tipo:</strong> ${escapeHtml(evento.tipo_evento)}</div>
                        ${evento.local_evento ? `<div><strong>Local:</strong> ${escapeHtml(evento.local_evento)}</div>` : ''}
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
                `;
            }
        });
    </script>
</body>
</html>
