<?php
// test_agenda_save.php — Teste de salvamento da agenda
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/agenda_helper.php';

// Simular sessão de admin
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['perfil'] = 'ADM';

$agenda = new AgendaHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

// Processar teste de salvamento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $dados = [
        'tipo' => $_POST['tipo'] ?? 'visita',
        'titulo' => $_POST['titulo'] ?? 'Teste',
        'descricao' => $_POST['descricao'] ?? '',
        'inicio' => $_POST['inicio'] ?? date('Y-m-d H:i:s'),
        'fim' => $_POST['fim'] ?? date('Y-m-d H:i:s', strtotime('+1 hour')),
        'responsavel_usuario_id' => $_POST['responsavel_usuario_id'] ?? 1,
        'criado_por_usuario_id' => $usuario_id,
        'espaco_id' => $_POST['espaco_id'] ?: null,
        'lembrete_minutos' => $_POST['lembrete_minutos'] ?? 60,
        'participantes' => [],
        'forcar_conflito' => false
    ];
    
    try {
        $response = $agenda->criarEvento($dados);
        echo json_encode($response);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Teste de sugestão de horário
if (isset($_GET['test_suggestion'])) {
    header('Content-Type: application/json');
    
    $responsavel_id = $_GET['responsavel_id'] ?? 1;
    $espaco_id = $_GET['espaco_id'] ?? null;
    $duracao = $_GET['duracao'] ?? 60;
    
    try {
        $sugestao = $agenda->sugerirProximoHorario($responsavel_id, $espaco_id, $duracao);
        echo json_encode([
            'success' => true,
            'sugestao' => $sugestao
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Erro: ' . $e->getMessage()
        ]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Agenda - GRUPO Smile EVENTOS</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
        .result { margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <h1>🧪 Teste de Salvamento da Agenda</h1>
    
    <form id="testForm">
        <div class="form-group">
            <label for="tipo">Tipo:</label>
            <select id="tipo" name="tipo">
                <option value="visita">Visita</option>
                <option value="bloqueio">Bloqueio</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="titulo">Título:</label>
            <input type="text" id="titulo" name="titulo" value="Teste de Evento" required>
        </div>
        
        <div class="form-group">
            <label for="descricao">Descrição:</label>
            <textarea id="descricao" name="descricao">Descrição do teste</textarea>
        </div>
        
        <div class="form-group">
            <label for="inicio">Início:</label>
            <input type="datetime-local" id="inicio" name="inicio" required>
        </div>
        
        <div class="form-group">
            <label for="fim">Fim:</label>
            <input type="datetime-local" id="fim" name="fim" required>
        </div>
        
        <div class="form-group">
            <label for="responsavel_usuario_id">Responsável ID:</label>
            <input type="number" id="responsavel_usuario_id" name="responsavel_usuario_id" value="1" required>
        </div>
        
        <div class="form-group">
            <label for="espaco_id">Espaço ID (opcional):</label>
            <input type="number" id="espaco_id" name="espaco_id">
        </div>
        
        <div class="form-group">
            <label for="lembrete_minutos">Lembrete (minutos):</label>
            <input type="number" id="lembrete_minutos" name="lembrete_minutos" value="60">
        </div>
        
        <button type="submit">💾 Testar Salvamento</button>
    </form>
    
    <div style="margin-top: 30px;">
        <h3>Teste de Sugestão de Horário</h3>
        <button onclick="testSuggestion()">🕐 Testar Sugestão</button>
    </div>
    
    <div id="result" class="result" style="display: none;"></div>
    
    <script>
        // Definir horários padrão
        const now = new Date();
        const startTime = new Date(now.getTime() + 60 * 60 * 1000); // +1 hora
        const endTime = new Date(startTime.getTime() + 60 * 60 * 1000); // +2 horas
        
        document.getElementById('inicio').value = formatDateTimeLocal(startTime);
        document.getElementById('fim').value = formatDateTimeLocal(endTime);
        
        function formatDateTimeLocal(date) {
            const d = new Date(date);
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            return d.toISOString().slice(0, 16);
        }
        
        document.getElementById('testForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('test_agenda_save.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const result = document.getElementById('result');
                result.style.display = 'block';
                result.className = 'result ' + (data.success ? 'success' : 'error');
                result.innerHTML = `
                    <strong>Resultado:</strong><br>
                    Success: ${data.success}<br>
                    Message: ${data.message || 'N/A'}<br>
                    Data: ${JSON.stringify(data, null, 2)}
                `;
            })
            .catch(error => {
                const result = document.getElementById('result');
                result.style.display = 'block';
                result.className = 'result error';
                result.innerHTML = `
                    <strong>Erro:</strong><br>
                    ${error.message}
                `;
            });
        });
        
        function testSuggestion() {
            fetch('test_agenda_save.php?test_suggestion=1&responsavel_id=1&duracao=60')
                .then(response => response.json())
                .then(data => {
                    const result = document.getElementById('result');
                    result.style.display = 'block';
                    result.className = 'result ' + (data.success ? 'success' : 'error');
                    result.innerHTML = `
                        <strong>Teste de Sugestão:</strong><br>
                        Success: ${data.success}<br>
                        Message: ${data.message || 'N/A'}<br>
                        Sugestão: ${JSON.stringify(data.sugestao, null, 2)}
                    `;
                })
                .catch(error => {
                    const result = document.getElementById('result');
                    result.style.display = 'block';
                    result.className = 'result error';
                    result.innerHTML = `
                        <strong>Erro na Sugestão:</strong><br>
                        ${error.message}
                    `;
                });
        }
    </script>
</body>
</html>
