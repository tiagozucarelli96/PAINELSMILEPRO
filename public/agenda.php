<?php
// agenda.php — Sistema de Agenda Interna
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/agenda_helper.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';

// Verificar permissões
$agenda = new AgendaHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

if (!$agenda->canAccessAgenda($usuario_id)) {
    header('Location: index.php?page=dashboard');
    exit;
}

$usuario_id = $_SESSION['user_id'] ?? 1;

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

// Renderizar página completa
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agenda - GRUPO Smile EVENTOS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/locales/pt-br.global.min.js" defer></script>
    <link rel="stylesheet" href="estilo.css">
    
    <style>
        .agenda-page-content {
            font-family: 'Inter', sans-serif;
            padding: 20px;
        }

        .agenda-container {
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

        .close-button {
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

        .close-button:hover {
            opacity: 0.7;
            background: rgba(255, 255, 255, 0.1);
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
    <?php require_once __DIR__ . '/sidebar_unified.php'; ?>
    
<div id="pageContent">
<div class="agenda-page-content">
<div class="agenda-container">
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
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">
                    <span id="modalIcon">📅</span>
                    <span id="modalTitleText">Novo Evento</span>
                </h2>
                <button class="close-button" onclick="closeEventModal()">&times;</button>
            </div>
            
            <div class="modal-body">
            
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
            
            modal.classList.add('active');
        }
        
        // Fechar modal
        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('active');
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
            
            // Calcular duração em minutos
            const duracao = Math.round((new Date(fim) - new Date(inicio)) / (1000 * 60));
            
            // Mostrar loading
            const suggestBtn = document.querySelector('button[onclick="suggestTime()"]');
            if (suggestBtn) {
                const originalText = suggestBtn.innerHTML;
                suggestBtn.innerHTML = '⏳ Buscando...';
                suggestBtn.disabled = true;
            }
            
            fetch('agenda.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `acao=sugerir_horario&responsavel_id=${responsavel}&espaco_id=${espaco}&duracao=${duracao}`
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erro na requisição: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.sugestao) {
                    const sugestao = data.sugestao;
                    
                    // Aplicar sugestão diretamente nos campos
                    document.getElementById('inicio').value = formatDateTimeLocal(new Date(sugestao.inicio));
                    document.getElementById('fim').value = formatDateTimeLocal(new Date(sugestao.fim));
                    
                    // Mostrar feedback
                    showSuggestionFeedback('Horário sugerido aplicado com sucesso!');
                } else {
                    showSuggestionFeedback('Nenhum horário livre encontrado para os próximos 7 dias.');
                }
            })
            .catch(error => {
                console.error('Erro ao sugerir horário:', error);
                showSuggestionFeedback('Erro ao buscar horários disponíveis.');
            })
            .finally(() => {
                // Restaurar botão
                if (suggestBtn) {
                    suggestBtn.innerHTML = '🕐 Sugerir Horário';
                    suggestBtn.disabled = false;
                }
            });
        }
        
        // Mostrar feedback da sugestão
        function showSuggestionFeedback(message) {
            // Remover feedback anterior
            const existingFeedback = document.getElementById('suggestionFeedback');
            if (existingFeedback) {
                existingFeedback.remove();
            }
            
            // Criar novo feedback
            const feedback = document.createElement('div');
            feedback.id = 'suggestionFeedback';
            feedback.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #10b981;
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 1001;
                font-size: 14px;
                font-weight: 500;
            `;
            feedback.textContent = message;
            
            document.body.appendChild(feedback);
            
            // Remover após 3 segundos
            setTimeout(() => {
                if (feedback.parentNode) {
                    feedback.remove();
                }
            }, 3000);
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
            
            // Adicionar ação
            formData.append('acao', acao);
            
            // Mostrar loading
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '⏳ Salvando...';
            submitBtn.disabled = true;
            
            fetch('agenda.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erro na requisição: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Resposta do servidor:', data);
                
                if (data.success) {
                    // Sucesso
                    submitBtn.innerHTML = '✅ Salvo!';
                    setTimeout(() => {
                        if (typeof calendar !== 'undefined' && calendar.refetchEvents) {
                            calendar.refetchEvents();
                        }
                        closeEventModal();
                    }, 1000);
                } else {
                    // Erro
                    console.error('Erro ao salvar:', data.message || 'Erro desconhecido');
                    submitBtn.innerHTML = '❌ Erro';
                    alert('Erro ao salvar: ' + (data.message || 'Erro desconhecido'));
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 2000);
                    
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
            })
            .catch(error => {
                console.error('Erro na requisição:', error);
                submitBtn.innerHTML = '❌ Erro de Rede';
                alert('Erro de conexão: ' + error.message);
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 2000);
            });
        });
        
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
    </div><!-- agenda-container -->
</div><!-- agenda-page-content -->
</body>
</html>
