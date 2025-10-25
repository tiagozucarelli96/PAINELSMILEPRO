<?php
// agenda.php — Sistema de Agenda Interna
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/agenda_helper.php';

// Verificar permissões
$agenda = new AgendaHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

if (!$agenda->canAccessAgenda($usuario_id)) {
    header('Location: index.php?page=dashboard');
    exit;
}

// Processar ações AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json');
    
    $acao = $_POST['acao'];
    $response = ['success' => false];
    
    switch ($acao) {
        case 'criar_evento':
            $dados = [
                'tipo' => $_POST['tipo'],
                'titulo' => $_POST['titulo'],
                'descricao' => $_POST['descricao'],
                'inicio' => $_POST['inicio'],
                'fim' => $_POST['fim'],
                'responsavel_usuario_id' => $_POST['responsavel_usuario_id'],
                'criado_por_usuario_id' => $usuario_id,
                'espaco_id' => $_POST['espaco_id'] ?: null,
                'lembrete_minutos' => $_POST['lembrete_minutos'],
                'participantes' => json_decode($_POST['participantes'] ?? '[]', true),
                'forcar_conflito' => isset($_POST['forcar_conflito']) && $agenda->canForceConflict($usuario_id)
            ];
            
            $response = $agenda->criarEvento($dados);
            break;
            
        case 'atualizar_evento':
            $evento_id = $_POST['evento_id'];
            $dados = [
                'tipo' => $_POST['tipo'],
                'titulo' => $_POST['titulo'],
                'descricao' => $_POST['descricao'],
                'inicio' => $_POST['inicio'],
                'fim' => $_POST['fim'],
                'responsavel_usuario_id' => $_POST['responsavel_usuario_id'],
                'espaco_id' => $_POST['espaco_id'] ?: null,
                'lembrete_minutos' => $_POST['lembrete_minutos'],
                'status' => $_POST['status'],
                'compareceu' => isset($_POST['compareceu']),
                'fechou_contrato' => isset($_POST['fechou_contrato']),
                'fechou_ref' => $_POST['fechou_ref'] ?? null,
                'participantes' => json_decode($_POST['participantes'] ?? '[]', true),
                'forcar_conflito' => isset($_POST['forcar_conflito']) && $agenda->canForceConflict($usuario_id)
            ];
            
            $response = $agenda->atualizarEvento($evento_id, $dados);
            break;
            
        case 'excluir_evento':
            $evento_id = $_POST['evento_id'];
            $response = $agenda->excluirEvento($evento_id);
            break;
            
        case 'sugerir_horario':
            $responsavel_id = $_POST['responsavel_id'];
            $espaco_id = $_POST['espaco_id'] ?: null;
            $duracao = $_POST['duracao'] ?? 60;
            
            $response = [
                'success' => true,
                'sugestao' => $agenda->sugerirProximoHorario($responsavel_id, $espaco_id, $duracao)
            ];
            break;
    }
    
    echo json_encode($response);
    exit;
}

// Obter dados para a página
$espacos = $agenda->obterEspacos();
$usuarios = $agenda->obterUsuariosComCores();
$agenda_dia = $agenda->obterAgendaDia($usuario_id, 24);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda Interna - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/locales/pt-br.global.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
            color: #333;
            margin: 0;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s ease;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        h1 {
            color: #1e3a8a;
            font-size: 2.2rem;
            margin-bottom: 25px;
            border-bottom: 2px solid #e0e7ff;
            padding-bottom: 15px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-primary {
            background-color: #1e3a8a;
            color: #fff;
            border: 1px solid #1e3a8a;
        }

        .btn-primary:hover {
            background-color: #1c327a;
            border-color: #1c327a;
            transform: translateY(-1px);
        }

        .btn-success {
            background-color: #10b981;
            color: #fff;
            border: 1px solid #10b981;
        }

        .btn-danger {
            background-color: #ef4444;
            color: #fff;
            border: 1px solid #ef4444;
        }

        .btn-outline {
            background-color: transparent;
            color: #1e3a8a;
            border: 1px solid #1e3a8a;
        }

        .btn-outline:hover {
            background-color: #e0e7ff;
            transform: translateY(-1px);
        }

        .toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .toolbar-left {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .toolbar-right {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filters {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .filter-group label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
        }

        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .calendar-container {
            background: #fff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .legend {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 50%;
        }

        .legend-text {
            font-size: 0.9rem;
            color: #374151;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            position: relative;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .close-button {
            color: #aaa;
            position: absolute;
            top: 15px;
            right: 25px;
            font-size: 30px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-button:hover,
        .close-button:focus {
            color: #333;
            text-decoration: none;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
            box-sizing: border-box;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: #1e3a8a;
            outline: none;
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 30px;
            border-top: 1px solid #e0e7ff;
            padding-top: 20px;
        }

        .conflict-warning {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .conflict-warning h4 {
            margin: 0 0 10px 0;
            font-size: 1.1rem;
        }

        .conflict-details {
            font-size: 0.9rem;
        }

        .suggestion-box {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            color: #0369a1;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .suggestion-box h4 {
            margin: 0 0 10px 0;
            font-size: 1.1rem;
        }

        .suggestion-details {
            font-size: 0.9rem;
        }

        .btn-suggestion {
            background: #0ea5e9;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .btn-suggestion:hover {
            background: #0284c7;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .container {
                padding: 15px;
            }
            h1 {
                font-size: 1.8rem;
            }
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .toolbar-left,
            .toolbar-right {
                justify-content: center;
            }
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            .form-row {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <div class="toolbar">
                <div class="toolbar-left">
                    <h1>🗓️ Agenda Interna</h1>
                </div>
                <div class="toolbar-right">
                    <?php if ($agenda->canCreateEvents($usuario_id)): ?>
                        <button class="btn btn-primary" onclick="openEventModal('visita')">
                            ➕ Nova Visita
                        </button>
                        <button class="btn btn-outline" onclick="openEventModal('bloqueio')">
                            🚫 Bloqueio
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-outline" onclick="calendar.today()">
                        📅 Hoje
                    </button>
                    <button class="btn btn-outline" onclick="toggleFilters()">
                        🔍 Filtros
                    </button>
                    <a href="agenda_config.php" class="btn btn-outline">
                        ⚙️ Config
                    </a>
                </div>
            </div>

            <!-- Filtros -->
            <div id="filters" class="filters" style="display: none;">
                <div class="filter-group">
                    <label>Responsável</label>
                    <select id="filter_responsavel">
                        <option value="">Todos</option>
                        <?php foreach ($usuarios as $user): ?>
                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Espaço</label>
                    <select id="filter_espaco">
                        <option value="">Todos</option>
                        <?php foreach ($espacos as $espaco): ?>
                            <option value="<?= $espaco['id'] ?>"><?= htmlspecialchars($espaco['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary" onclick="applyFilters()">
                    Aplicar
                </button>
                <button class="btn btn-outline" onclick="clearFilters()">
                    Limpar
                </button>
            </div>

            <!-- Calendário -->
            <div class="calendar-container">
                <div id="calendar"></div>
            </div>

            <!-- Legenda -->
            <div class="legend">
                <?php foreach ($usuarios as $user): ?>
                    <div class="legend-item">
                        <div class="legend-color" style="background-color: <?= htmlspecialchars($user['cor_agenda']) ?>"></div>
                        <span class="legend-text"><?= htmlspecialchars($user['nome']) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="legend-item">
                    <span class="legend-text">👤 Visita</span>
                </div>
                <div class="legend-item">
                    <span class="legend-text">🔒 Bloqueio</span>
                </div>
                <div class="legend-item">
                    <span class="legend-text">🕓 Outro</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Evento -->
    <div id="eventModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeEventModal()">&times;</span>
            <h2 id="modalTitle">Novo Evento</h2>
            
            <form id="eventForm">
                <input type="hidden" id="eventId" name="evento_id">
                <input type="hidden" id="eventTipo" name="tipo">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="responsavel">Responsável *</label>
                        <select id="responsavel" name="responsavel_usuario_id" required>
                            <?php foreach ($usuarios as $user): ?>
                                <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="espacoGroup">
                        <label for="espaco">Espaço</label>
                        <select id="espaco" name="espaco_id">
                            <option value="">Selecione um espaço</option>
                            <?php foreach ($espacos as $espaco): ?>
                                <option value="<?= $espaco['id'] ?>"><?= htmlspecialchars($espaco['nome']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="titulo">Título *</label>
                    <input type="text" id="titulo" name="titulo" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="inicio">Data/Hora Início *</label>
                        <input type="datetime-local" id="inicio" name="inicio" required>
                    </div>
                    <div class="form-group">
                        <label for="fim">Data/Hora Fim *</label>
                        <input type="datetime-local" id="fim" name="fim" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="descricao">Observações</label>
                    <textarea id="descricao" name="descricao" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="lembrete">Lembrete (minutos antes)</label>
                        <input type="number" id="lembrete" name="lembrete_minutos" min="0" max="1440" value="60">
                    </div>
                    <div class="form-group" id="statusGroup" style="display: none;">
                        <label for="status">Status</label>
                        <select id="status" name="status">
                            <option value="agendado">Agendado</option>
                            <option value="realizado">Realizado</option>
                            <option value="no_show">No Show</option>
                            <option value="cancelado">Cancelado</option>
                        </select>
                    </div>
                </div>
                
                <div id="conversionGroup" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="compareceu" name="compareceu"> Compareceu
                            </label>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" id="fechou_contrato" name="fechou_contrato"> Fechou Contrato
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="fechou_ref">Referência do Contrato</label>
                        <input type="text" id="fechou_ref" name="fechou_ref">
                    </div>
                </div>
                
                <div id="conflictWarning" class="conflict-warning" style="display: none;">
                    <h4>⚠️ Conflito Detectado</h4>
                    <div class="conflict-details" id="conflictDetails"></div>
                </div>
                
                <div id="suggestionBox" class="suggestion-box" style="display: none;">
                    <h4>💡 Sugestão de Horário</h4>
                    <div class="suggestion-details" id="suggestionDetails"></div>
                    <button type="button" class="btn-suggestion" onclick="applySuggestion()">
                        Usar Sugestão
                    </button>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeEventModal()">
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-danger" id="deleteBtn" onclick="deleteEvent()" style="display: none;">
                        🗑️ Excluir
                    </button>
                    <button type="button" class="btn btn-primary" id="forceBtn" onclick="forceConflict()" style="display: none;">
                        ⚡ Forçar Conflito
                    </button>
                    <button type="button" class="btn btn-outline" onclick="suggestTime()">
                        ⏰ Sugerir Horário
                    </button>
                    <button type="submit" class="btn btn-success">
                        ✅ Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let calendar;
        let currentFilters = {};
        
        // Inicializar calendário
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            
            calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'pt-br',
                firstDay: 1, // Segunda-feira
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
                },
                buttonText: {
                    today: 'Hoje',
                    month: 'Mês',
                    week: 'Semana',
                    day: 'Dia',
                    list: 'Lista'
                },
                events: function(info, successCallback, failureCallback) {
                    loadEvents(info.start, info.end);
                },
                eventClick: function(info) {
                    openEventModal('edit', info.event);
                },
                dateClick: function(info) {
                    if (canCreateEvents()) {
                        openEventModal('visita', null, info.dateStr);
                    }
                },
                eventDidMount: function(info) {
                    // Adicionar tooltip
                    info.el.title = `${info.event.title}\n${info.event.extendedProps.espaco_nome || ''}\n${info.event.start.toLocaleString('pt-BR')} - ${info.event.end.toLocaleString('pt-BR')}`;
                }
            });
            
            calendar.render();
        });
        
        // Carregar eventos
        function loadEvents(start, end) {
            const params = new URLSearchParams({
                start: start.toISOString(),
                end: end.toISOString(),
                ...currentFilters
            });
            
            fetch(`agenda_api.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    calendar.removeAllEvents();
                    calendar.addEventSource(data);
                })
                .catch(error => {
                    console.error('Erro ao carregar eventos:', error);
                });
        }
        
        // Abrir modal de evento
        function openEventModal(tipo, event = null, date = null) {
            const modal = document.getElementById('eventModal');
            const form = document.getElementById('eventForm');
            const title = document.getElementById('modalTitle');
            const tipoInput = document.getElementById('eventTipo');
            const espacoGroup = document.getElementById('espacoGroup');
            const statusGroup = document.getElementById('statusGroup');
            const conversionGroup = document.getElementById('conversionGroup');
            const deleteBtn = document.getElementById('deleteBtn');
            const forceBtn = document.getElementById('forceBtn');
            
            // Limpar formulário
            form.reset();
            
            if (event) {
                // Editar evento existente
                title.textContent = 'Editar Evento';
                document.getElementById('eventId').value = event.id;
                document.getElementById('responsavel').value = event.extendedProps.responsavel_usuario_id;
                document.getElementById('espaco').value = event.extendedProps.espaco_id || '';
                document.getElementById('titulo').value = event.title;
                document.getElementById('inicio').value = formatDateTimeLocal(event.start);
                document.getElementById('fim').value = formatDateTimeLocal(event.end);
                document.getElementById('descricao').value = event.extendedProps.descricao || '';
                document.getElementById('lembrete').value = event.extendedProps.lembrete_minutos || 60;
                document.getElementById('status').value = event.extendedProps.status || 'agendado';
                document.getElementById('compareceu').checked = event.extendedProps.compareceu || false;
                document.getElementById('fechou_contrato').checked = event.extendedProps.fechou_contrato || false;
                document.getElementById('fechou_ref').value = event.extendedProps.fechou_ref || '';
                
                statusGroup.style.display = 'block';
                conversionGroup.style.display = event.extendedProps.tipo === 'visita' ? 'block' : 'none';
                deleteBtn.style.display = 'block';
                
                if (canForceConflict()) {
                    forceBtn.style.display = 'block';
                }
            } else {
                // Novo evento
                title.textContent = tipo === 'visita' ? 'Nova Visita' : 'Novo Bloqueio';
                tipoInput.value = tipo;
                
                if (date) {
                    const startDate = new Date(date);
                    const endDate = new Date(startDate.getTime() + 60 * 60 * 1000); // +1 hora
                    document.getElementById('inicio').value = formatDateTimeLocal(startDate);
                    document.getElementById('fim').value = formatDateTimeLocal(endDate);
                }
                
                espacoGroup.style.display = tipo === 'visita' ? 'block' : 'none';
                statusGroup.style.display = 'none';
                conversionGroup.style.display = 'none';
                deleteBtn.style.display = 'none';
                forceBtn.style.display = 'none';
            }
            
            modal.style.display = 'flex';
        }
        
        // Fechar modal
        function closeEventModal() {
            document.getElementById('eventModal').style.display = 'none';
        }
        
        // Formatar data para input datetime-local
        function formatDateTimeLocal(date) {
            const d = new Date(date);
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            return d.toISOString().slice(0, 16);
        }
        
        // Verificar permissões
        function canCreateEvents() {
            return <?= $agenda->canCreateEvents($usuario_id) ? 'true' : 'false' ?>;
        }
        
        function canForceConflict() {
            return <?= $agenda->canForceConflict($usuario_id) ? 'true' : 'false' ?>;
        }
        
        // Toggle filtros
        function toggleFilters() {
            const filters = document.getElementById('filters');
            filters.style.display = filters.style.display === 'none' ? 'block' : 'none';
        }
        
        // Aplicar filtros
        function applyFilters() {
            currentFilters = {
                responsavel_id: document.getElementById('filter_responsavel').value,
                espaco_id: document.getElementById('filter_espaco').value
            };
            
            calendar.refetchEvents();
        }
        
        // Limpar filtros
        function clearFilters() {
            document.getElementById('filter_responsavel').value = '';
            document.getElementById('filter_espaco').value = '';
            currentFilters = {};
            calendar.refetchEvents();
        }
        
        // Sugerir horário
        function suggestTime() {
            const responsavel = document.getElementById('responsavel').value;
            const espaco = document.getElementById('espaco').value;
            const inicio = document.getElementById('inicio').value;
            const fim = document.getElementById('fim').value;
            
            if (!responsavel) {
                alert('Selecione um responsável primeiro');
                return;
            }
            
            const duracao = Math.round((new Date(fim) - new Date(inicio)) / (1000 * 60));
            
            fetch('agenda.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `acao=sugerir_horario&responsavel_id=${responsavel}&espaco_id=${espaco}&duracao=${duracao}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const sugestao = data.sugestao;
                    document.getElementById('suggestionDetails').innerHTML = `
                        <strong>Próximo horário livre:</strong><br>
                        ${new Date(sugestao.inicio).toLocaleString('pt-BR')} - ${new Date(sugestao.fim).toLocaleString('pt-BR')}
                    `;
                    document.getElementById('suggestionBox').style.display = 'block';
                }
            });
        }
        
        // Aplicar sugestão
        function applySuggestion() {
            fetch('agenda.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `acao=sugerir_horario&responsavel_id=${document.getElementById('responsavel').value}&espaco_id=${document.getElementById('espaco').value}&duracao=60`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const sugestao = data.sugestao;
                    document.getElementById('inicio').value = formatDateTimeLocal(new Date(sugestao.inicio));
                    document.getElementById('fim').value = formatDateTimeLocal(new Date(sugestao.fim));
                    document.getElementById('suggestionBox').style.display = 'none';
                }
            });
        }
        
        // Forçar conflito
        function forceConflict() {
            document.getElementById('forceBtn').style.display = 'none';
            document.getElementById('conflictWarning').style.display = 'none';
        }
        
        // Excluir evento
        function deleteEvent() {
            if (confirm('Tem certeza que deseja excluir este evento?')) {
                const eventId = document.getElementById('eventId').value;
                
                fetch('agenda.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `acao=excluir_evento&evento_id=${eventId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        calendar.refetchEvents();
                        closeEventModal();
                    } else {
                        alert('Erro ao excluir evento: ' + data.error);
                    }
                });
            }
        }
        
        // Submeter formulário
        document.getElementById('eventForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const eventoId = formData.get('evento_id');
            const acao = eventoId ? 'atualizar_evento' : 'criar_evento';
            
            formData.append('acao', acao);
            
            fetch('agenda.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    calendar.refetchEvents();
                    closeEventModal();
                } else {
                    if (data.conflito) {
                        // Mostrar conflito
                        document.getElementById('conflictDetails').innerHTML = `
                            <strong>Conflito detectado:</strong><br>
                            ${data.conflito.conflito_responsavel ? 'Responsável já tem evento neste horário' : ''}
                            ${data.conflito.conflito_espaco ? 'Espaço já está ocupado' : ''}
                        `;
                        document.getElementById('conflictWarning').style.display = 'block';
                        
                        if (canForceConflict()) {
                            document.getElementById('forceBtn').style.display = 'block';
                        }
                    } else {
                        alert('Erro: ' + data.error);
                    }
                }
            });
        });
        
        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById('eventModal');
            if (event.target === modal) {
                closeEventModal();
            }
        }
        
        // Atalhos de teclado
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey || e.metaKey) {
                switch(e.key) {
                    case 'n':
                        e.preventDefault();
                        if (canCreateEvents()) {
                            openEventModal('visita');
                        }
                        break;
                    case 'b':
                        e.preventDefault();
                        if (canCreateEvents()) {
                            openEventModal('bloqueio');
                        }
                        break;
                    case 't':
                        e.preventDefault();
                        calendar.today();
                        break;
                }
            }
        });
    </script>
</body>
</html>
