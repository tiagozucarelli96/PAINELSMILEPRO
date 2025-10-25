<?php
// agenda_modal_moderno.php — Modal moderno para agenda
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/agenda_helper.php';

// Simular sessão de admin
$_SESSION['logado'] = 1;
$_SESSION['user_id'] = 1;
$_SESSION['perfil'] = 'ADM';

$agenda = new AgendaHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

// Buscar usuários e espaços
$usuarios = [];
$espacos = [];

try {
    $stmt = $pdo->query("SELECT id, nome FROM usuarios WHERE status_empregado = 'ativo' ORDER BY nome");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->query("SELECT id, nome FROM espacos ORDER BY nome");
    $espacos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erro ao buscar dados: " . $e->getMessage());
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda Interna - GRUPO Smile EVENTOS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .title {
            font-size: 2.5rem;
            font-weight: 800;
            color: #1e3a8a;
            margin-bottom: 10px;
        }
        
        .subtitle {
            font-size: 1.2rem;
            color: #6b7280;
        }
        
        .demo-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        
        .demo-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e3a8a;
            margin-bottom: 20px;
        }
        
        .btn-demo {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin: 10px;
        }
        
        .btn-demo:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.4);
        }
        
        .btn-demo.secondary {
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
        }
        
        .btn-demo.secondary:hover {
            box-shadow: 0 8px 25px rgba(239, 68, 68, 0.4);
        }

        /* Modal Styles Modernos */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
        }

        .modal.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            width: 90%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            transform: scale(0.9) translateY(20px);
            transition: all 0.3s ease;
        }

        .modal.active .modal-content {
            transform: scale(1) translateY(0);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 25px 30px;
            border-bottom: 1px solid #e5e7eb;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 20px 20px 0 0;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 30px;
            cursor: pointer;
            transition: opacity 0.2s ease;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .close-btn:hover {
            opacity: 0.7;
            background: rgba(255, 255, 255, 0.1);
        }

        .modal-body {
            padding: 30px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            transition: all 0.3s ease;
            background: white;
        }

        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: flex;
            gap: 15px;
            align-items: end;
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        .btn-cancel {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .btn-cancel:hover {
            background: #4b5563;
        }

        .btn-save {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3);
        }

        .btn-suggest {
            background: linear-gradient(135deg, #059669 0%, #10b981 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-suggest:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
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

        .datetime-input {
            position: relative;
        }

        .datetime-input input {
            padding-right: 40px;
        }

        .datetime-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            pointer-events: none;
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                flex-direction: column;
                align-items: stretch;
            }
            
            .modal-content {
                width: 95%;
                margin: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1 class="title">🗓️ Agenda Interna</h1>
            <p class="subtitle">Sistema de agendamento moderno e intuitivo</p>
        </div>
        
        <div class="demo-section">
            <h2 class="demo-title">Teste os Modais Modernos</h2>
            <p style="margin-bottom: 30px; color: #6b7280;">Clique nos botões abaixo para testar os modais com design moderno:</p>
            
            <button class="btn-demo" onclick="openEventModal('visita')">
                <span>➕</span>
                Nova Visita
            </button>
            
            <button class="btn-demo secondary" onclick="openEventModal('bloqueio')">
                <span>🚫</span>
                Novo Bloqueio
            </button>
        </div>
    </div>

    <!-- Modal de Evento -->
    <div class="modal" id="eventModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">
                    <span id="modalIcon">📅</span>
                    <span id="modalTitleText">Nova Visita</span>
                </h2>
                <button class="close-btn" onclick="closeEventModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <form id="eventForm">
                    <input type="hidden" id="eventId" name="evento_id">
                    <input type="hidden" id="eventTipo" name="tipo">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="responsavel">Responsável *</label>
                            <select id="responsavel" name="responsavel_usuario_id" class="form-select" required>
                                <?php foreach ($usuarios as $user): ?>
                                    <option value="<?= $user['id'] ?>"><?= h($user['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" id="espacoGroup">
                            <label class="form-label" for="espaco">Espaço</label>
                            <select id="espaco" name="espaco_id" class="form-select">
                                <option value="">Selecione um espaço</option>
                                <?php foreach ($espacos as $espaco): ?>
                                    <option value="<?= $espaco['id'] ?>"><?= h($espaco['nome']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group full">
                            <label class="form-label" for="titulo">Título *</label>
                            <input type="text" id="titulo" name="titulo" class="form-input" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="inicio">Data/Hora Início *</label>
                            <div class="datetime-input">
                                <input type="datetime-local" id="inicio" name="inicio" class="form-input" required>
                                <span class="datetime-icon">📅</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="fim">Data/Hora Fim *</label>
                            <div class="datetime-input">
                                <input type="datetime-local" id="fim" name="fim" class="form-input" required>
                                <span class="datetime-icon">📅</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="lembrete_minutos">Lembrete (minutos antes)</label>
                            <input type="number" id="lembrete_minutos" name="lembrete_minutos" class="form-input" value="60" min="0">
                        </div>
                        
                        <div class="form-group full">
                            <label class="form-label" for="descricao">Observações</label>
                            <textarea id="descricao" name="descricao" class="form-textarea" placeholder="Adicione observações sobre o evento..."></textarea>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-cancel" onclick="closeEventModal()">Cancelar</button>
                        <button type="button" class="btn-suggest" onclick="suggestTime()">
                            <span>🕐</span>
                            Sugerir Horário
                        </button>
                        <button type="submit" class="btn-save">
                            <span>💾</span>
                            Salvar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        function openEventModal(tipo) {
            const modal = document.getElementById('eventModal');
            const title = document.getElementById('modalTitleText');
            const icon = document.getElementById('modalIcon');
            const tipoInput = document.getElementById('eventTipo');
            const espacoGroup = document.getElementById('espacoGroup');
            const form = document.getElementById('eventForm');
            
            // Limpar formulário
            form.reset();
            
            // Configurar modal baseado no tipo
            if (tipo === 'visita') {
                title.textContent = 'Nova Visita';
                icon.textContent = '👥';
                espacoGroup.style.display = 'block';
            } else if (tipo === 'bloqueio') {
                title.textContent = 'Novo Bloqueio';
                icon.textContent = '🚫';
                espacoGroup.style.display = 'none';
            }
            
            tipoInput.value = tipo;
            
            // Definir horário padrão (próxima hora)
            const now = new Date();
            const startTime = new Date(now.getTime() + 60 * 60 * 1000); // +1 hora
            const endTime = new Date(startTime.getTime() + 60 * 60 * 1000); // +2 horas
            
            document.getElementById('inicio').value = formatDateTimeLocal(startTime);
            document.getElementById('fim').value = formatDateTimeLocal(endTime);
            
            // Mostrar modal
            modal.classList.add('active');
            
            // Focar no primeiro campo
            setTimeout(() => {
                document.getElementById('titulo').focus();
            }, 300);
        }
        
        function closeEventModal() {
            const modal = document.getElementById('eventModal');
            modal.classList.remove('active');
        }
        
        function suggestTime() {
            // Lógica para sugerir horário baseado em disponibilidade
            const now = new Date();
            const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);
            tomorrow.setHours(9, 0, 0, 0); // 9:00 AM
            
            const endTime = new Date(tomorrow.getTime() + 60 * 60 * 1000); // +1 hora
            
            document.getElementById('inicio').value = formatDateTimeLocal(tomorrow);
            document.getElementById('fim').value = formatDateTimeLocal(endTime);
            
            // Mostrar feedback
            showAlert('success', 'Horário sugerido: Amanhã às 9:00');
        }
        
        function formatDateTimeLocal(date) {
            const d = new Date(date);
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            return d.toISOString().slice(0, 16);
        }
        
        function showAlert(type, message) {
            // Remover alertas existentes
            const existingAlert = document.querySelector('.alert');
            if (existingAlert) {
                existingAlert.remove();
            }
            
            const alert = document.createElement('div');
            alert.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-error');
            alert.textContent = message;
            
            document.querySelector('.modal-body').prepend(alert);
            
            // Remover após 3 segundos
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 3000);
        }
        
        // Fechar modal ao clicar fora
        document.getElementById('eventModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEventModal();
            }
        });
        
        // Prevenir fechamento ao clicar no conteúdo
        document.querySelector('.modal-content').addEventListener('click', function(e) {
            e.stopPropagation();
        });
        
        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEventModal();
            }
        });
        
        // Validação do formulário
        document.getElementById('eventForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const inicio = new Date(document.getElementById('inicio').value);
            const fim = new Date(document.getElementById('fim').value);
            
            if (fim <= inicio) {
                showAlert('error', 'A data/hora de fim deve ser posterior ao início.');
                return;
            }
            
            // Simular salvamento
            showAlert('success', 'Evento salvo com sucesso!');
            
            setTimeout(() => {
                closeEventModal();
            }, 1500);
        });
        
        // Auto-calcular fim baseado no início
        document.getElementById('inicio').addEventListener('change', function() {
            const inicio = new Date(this.value);
            if (inicio) {
                const fim = new Date(inicio.getTime() + 60 * 60 * 1000); // +1 hora
                document.getElementById('fim').value = formatDateTimeLocal(fim);
            }
        });
    </script>
</body>
</html>
