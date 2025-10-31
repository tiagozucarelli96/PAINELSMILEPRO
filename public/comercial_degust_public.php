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
$stmt = $pdo->prepare("SELECT COUNT(*) FROM comercial_inscricoes WHERE event_id = :id AND status = 'confirmado'");
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
        $stmt = $pdo->prepare("SELECT id FROM comercial_inscricoes WHERE event_id = :event_id AND email = :email");
        $stmt->execute([':event_id' => $degustacao['id'], ':email' => $email]);
        if ($stmt->fetch()) {
            throw new Exception("Já existe uma inscrição com este e-mail para esta degustação");
        }
        
        // Inserir inscrição
        $sql = "INSERT INTO comercial_inscricoes 
                (event_id, status, fechou_contrato, me_event_id, nome_titular_contrato, nome, email, celular, 
                 dados_json, qtd_pessoas, tipo_festa, extras, pagamento_status, valor_pago, ip_origem, user_agent_origem)
                VALUES 
                (:event_id, :status, :fechou_contrato, :me_event_id, :nome_titular_contrato, :nome, :email, :celular,
                 :dados_json, :qtd_pessoas, :tipo_festa, :extras, :pagamento_status, :valor_pago, :ip_origem, :user_agent_origem)";
        
        $params = [
            ':event_id' => $degustacao['id'],
            ':status' => $status,
            ':fechou_contrato' => $fechou_contrato,
            ':me_event_id' => null,
            ':nome_titular_contrato' => $nome_titular_contrato,
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
                    // Atualizar inscrição com payment_id do ASAAS
                    $stmt = $pdo->prepare("UPDATE comercial_inscricoes SET asaas_payment_id = :payment_id, pagamento_status = 'aguardando' WHERE id = :id");
                    $stmt->execute([
                        ':payment_id' => $payment_response['id'],
                        ':id' => $inscricao_id
                    ]);
                    
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
                            <input type="radio" name="tipo_festa" value="casamento" id="casamento" required>
                            <label for="casamento">Casamento (R$ <?= number_format($degustacao['preco_casamento'], 2, ',', '.') ?> - <?= $degustacao['incluidos_casamento'] ?> pessoas)</label>
                        </div>
                        <div class="form-radio">
                            <input type="radio" name="tipo_festa" value="15anos" id="15anos" required>
                            <label for="15anos">15 Anos (R$ <?= number_format($degustacao['preco_15anos'], 2, ',', '.') ?> - <?= $degustacao['incluidos_15anos'] ?> pessoas)</label>
                        </div>
                    </div>
                </div>
                
                <!-- Quantidade de Pessoas -->
                <div class="form-group">
                    <label class="form-label">Quantidade de Pessoas *</label>
                    <input type="number" name="qtd_pessoas" class="form-input" min="1" required onchange="calcularPreco()">
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
                        <input type="text" name="nome_titular_contrato" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">3 primeiros dígitos do CPF do titular *</label>
                        <input type="text" name="cpf_3_digitos" class="form-input" maxlength="3" pattern="[0-9]{3}">
                    </div>
                    
                    <div id="meEventInfo" class="alert alert-success hidden">
                        <h4>✅ Contrato encontrado!</h4>
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
    
    <script>
        function calcularPreco() {
            const tipoFesta = document.querySelector('input[name="tipo_festa"]:checked');
            const qtdPessoas = parseInt(document.querySelector('input[name="qtd_pessoas"]').value) || 0;
            
            if (!tipoFesta || qtdPessoas === 0) {
                document.getElementById('priceInfo').style.display = 'none';
                return;
            }
            
            const precoBase = tipoFesta.value === 'casamento' ? 
                <?= $degustacao['preco_casamento'] ?> : 
                <?= $degustacao['preco_15anos'] ?>;
            const incluidos = tipoFesta.value === 'casamento' ? 
                <?= $degustacao['incluidos_casamento'] ?> : 
                <?= $degustacao['incluidos_15anos'] ?>;
            const precoExtra = <?= $degustacao['preco_extra'] ?>;
            
            const extras = Math.max(0, qtdPessoas - incluidos);
            const valorTotal = precoBase + (extras * precoExtra);
            
            document.getElementById('precoBase').textContent = 'R$ ' + precoBase.toFixed(2).replace('.', ',');
            document.getElementById('extrasInfo').textContent = extras + ' x R$ ' + precoExtra.toFixed(2).replace('.', ',');
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
                document.querySelector('input[name="nome_titular_contrato"]').required = true;
                document.querySelector('input[name="cpf_3_digitos"]').required = true;
            } else {
                contratoInfo.classList.add('hidden');
                document.querySelector('input[name="nome_titular_contrato"]').required = false;
                document.querySelector('input[name="cpf_3_digitos"]').required = false;
            }
        }
        
        // Calcular preço quando tipo de festa mudar
        document.querySelectorAll('input[name="tipo_festa"]').forEach(radio => {
            radio.addEventListener('change', calcularPreco);
        });
        
        // Buscar contrato na ME Eventos quando nome do titular for preenchido
        document.querySelector('input[name="nome_titular_contrato"]').addEventListener('blur', function() {
            const nome = this.value.trim();
            const cpf3 = document.querySelector('input[name="cpf_3_digitos"]').value.trim();
            
            if (nome && cpf3 && cpf3.length === 3) {
                // TODO: Implementar busca na ME Eventos
                // Por enquanto, simular sucesso
                document.getElementById('meEventInfo').classList.remove('hidden');
                document.getElementById('meEventDetails').innerHTML = `
                    <p><strong>Nome:</strong> ${nome}</p>
                    <p><strong>CPF:</strong> ${cpf3}***.***.***-**</p>
                    <p><strong>Evento:</strong> Casamento - 15/06/2024</p>
                    <p><strong>Local:</strong> Espaço Eventos</p>
                `;
            }
        });
    </script>
</body>
</html>
