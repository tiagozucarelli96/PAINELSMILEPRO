<?php
// agenda.php — Sistema de Agenda Interna
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/agenda_helper.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/google_calendar_auto_sync.php';

// Verificar permissões
$agenda = new AgendaHelper();
$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
$is_superadmin = !empty($_SESSION['perm_superadmin']);

if (!$agenda->canAccessAgenda($usuario_id)) {
    header('Location: index.php?page=dashboard');
    exit;
}

$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);

// Auto-sync Google Calendar ao carregar a agenda (com throttling por sessão)
google_calendar_auto_sync($GLOBALS['pdo'], 'agenda');

// Processar ações AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    // Desabilitar display de erros para retornar apenas JSON
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
    ini_set('log_errors', 1);
    
    header('Content-Type: application/json');
    
    $acao = $_POST['acao'];
    $response = ['success' => false];
    
    try {
    
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
                'forcar_conflito' => !empty($_POST['forcar_conflito']) && $agenda->canForceConflict($usuario_id)
            ];
            
            $response = $agenda->criarEvento($dados);
            break;
            
        case 'atualizar_evento':
            $evento_id = $_POST['evento_id'];
            
            // Debug: log dos valores recebidos
            error_log("POST compareceu: " . var_export($_POST['compareceu'] ?? 'NULL', true));
            error_log("POST fechou_contrato: " . var_export($_POST['fechou_contrato'] ?? 'NULL', true));
            
            // VALIDAR que temos '1' ou '0', nunca vazio
            $compareceu = $_POST['compareceu'] ?? '1';
            $fechou_contrato = $_POST['fechou_contrato'] ?? '0';
            
            // Se receber string vazia, usar default
            if ($compareceu === '' || $compareceu === null) $compareceu = '1';
            if ($fechou_contrato === '' || $fechou_contrato === null) $fechou_contrato = '0';
            
            // Debug: Log DOIS NÍVEIS - valores absolutos recebidos
            error_log("POST RAW - compareceu: '" . var_export($compareceu, true) . "', fechou_contrato: '" . var_export($fechou_contrato, true) . "'");
            error_log("POST PROCESSADO - compareceu: '$compareceu', fechou_contrato: '$fechou_contrato'");
            
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
                'compareceu' => $compareceu,
                'fechou_contrato' => $fechou_contrato,
                'fechou_ref' => $_POST['fechou_ref'] ?? null,
                'participantes' => json_decode($_POST['participantes'] ?? '[]', true),
                'forcar_conflito' => !empty($_POST['forcar_conflito']) && $agenda->canForceConflict($usuario_id)
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
            $inicio_base = $_POST['inicio_base'] ?? null;

            $sugestao = $agenda->sugerirProximoHorario($responsavel_id, $espaco_id, $duracao, $inicio_base);
            if ($sugestao) {
                $response = [
                    'success' => true,
                    'sugestao' => $sugestao
                ];
            } else {
                $response = [
                    'success' => false,
                    'error' => 'Nenhum horário livre encontrado para os próximos dias.'
                ];
            }
            break;
    }
    } catch (Exception $e) {
        $response = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
    
    echo json_encode($response);
    exit;
}

// Obter dados para a página
$espacos = $agenda->obterEspacos();
$usuarios = $agenda->obterUsuariosComCores();
$agenda_dia = $agenda->obterAgendaDia($usuario_id, 24);

// Renderizar página completa usando sidebar_integration
includeSidebar('Agenda');
?>

<style>
        .agenda-page-content {
            font-family: 'Inter', sans-serif;
            padding: 24px;
        }

        .agenda-container {
            max-width: 1480px;
            width: 100%;
            margin: 0 auto;
            background-color: #fff;
            padding: 24px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 16px 34px rgba(15, 23, 42, 0.05);
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
            margin-bottom: 20px;
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
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 12px;
            border-radius: 12px;
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
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
            border: 1px solid #e2e8f0;
            margin: 0;
            margin-top: 0 !important;
        }
        
        /* Garantir que o calendário tenha altura */
        #calendar {
            min-height: 600px;
            width: 100%;
        }
        
        /* FullCalendar específico */
        .fc {
            width: 100%;
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
                    <?php if ($is_superadmin): ?>
                    <a href="agenda_config.php" class="btn btn-outline">
                        ⚙️ Config
                    </a>
                    <a href="index.php?page=google_calendar_config" class="btn btn-outline" style="text-decoration: none;">
                        📅 Google Calendar
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Calendário no TOPO -->
            <div class="calendar-container">
                <div id="calendar"></div>
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

            <!-- Legenda -->
            <div class="legend">
                <!-- Legenda por tipo de evento -->
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #dc2626;"></div>
                    <span>Bloqueio</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #10b981;"></div>
                    <span>Visita</span>
                </div>
                <div class="legend-item">
                    <div class="legend-color" style="background-color: #3b96f7;"></div>
                    <span>Outro</span>
                </div>
                <!-- Legenda por usuário -->
                <?php foreach ($usuarios as $user): ?>
                    <div class="legend-item">
                        <div class="legend-color" style="background-color: <?= htmlspecialchars($user['cor_agenda']) ?>"></div>
                        <span class="legend-text"><?= htmlspecialchars($user['nome']) ?></span>
                    </div>
                <?php endforeach; ?>
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
                <input type="hidden" id="forcarConflitoInput" name="forcar_conflito" value="0">
                
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
                    <!-- Campos ocultos SEM name para não serem enviados diretamente -->
                    <input type="hidden" id="compareceu_hidden" value="1">
                    <input type="hidden" id="fechou_contrato_hidden" value="0">
                    
                    <div class="form-row" style="display: flex; gap: 20px; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                            <input type="checkbox" id="compareceu" style="margin: 0; width: 16px; height: 16px;"> Não Compareceu
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; margin: 0;">
                            <input type="checkbox" id="fechou_contrato" style="margin: 0; width: 16px; height: 16px;"> Fechou Contrato
                        </label>
                    </div>
                </div>
                
                <!-- Seção específica para eventos do Google Calendar -->
                <div id="googleEventGroup" style="display: none; padding: 1rem; background: #eff6ff; border-radius: 8px; margin-top: 1rem; border-left: 4px solid #10b981;">
                    <p style="margin: 0 0 1rem 0; color: #1e40af; font-weight: 600;">
                        📅 Evento do Google Calendar (somente leitura)
                    </p>
                    
                    <!-- Dados do Evento (somente leitura) -->
                    <div id="googleEventDetails" style="background: white; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                        <div style="margin-bottom: 0.75rem;">
                            <strong style="color: #374151;">Título:</strong>
                            <div id="google_event_titulo" style="color: #64748b; margin-top: 0.25rem;"></div>
                        </div>
                        <div style="margin-bottom: 0.75rem;">
                            <strong style="color: #374151;">Data/Hora:</strong>
                            <div id="google_event_datas" style="color: #64748b; margin-top: 0.25rem;"></div>
                        </div>
                        <div id="google_event_descricao_group" style="margin-bottom: 0.75rem; display: none;">
                            <strong style="color: #374151;">Descrição:</strong>
                            <div id="google_event_descricao" style="color: #64748b; margin-top: 0.25rem; white-space: pre-wrap;"></div>
                        </div>
                        <div id="google_event_localizacao_group" style="margin-bottom: 0.75rem; display: none;">
                            <strong style="color: #374151;">Localização:</strong>
                            <div id="google_event_localizacao" style="color: #64748b; margin-top: 0.25rem;"></div>
                        </div>
                        <div id="google_event_organizador_group" style="margin-bottom: 0.75rem; display: none;">
                            <strong style="color: #374151;">Organizador:</strong>
                            <div id="google_event_organizador" style="color: #64748b; margin-top: 0.25rem;"></div>
                        </div>
                    </div>
                    
                    <div class="form-row" style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                        <label style="display: flex; align-items: center; gap: 8px; margin: 0; cursor: pointer;">
                            <input type="checkbox" id="google_eh_visita" style="margin: 0; width: 16px; height: 16px;" onchange="updateGoogleEvent(this)"> É visita agendada?
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; margin: 0; cursor: pointer;">
                            <input type="checkbox" id="google_contrato_fechado" style="margin: 0; width: 16px; height: 16px;" onchange="updateGoogleEvent(this)"> Contrato fechado?
                        </label>
                    </div>
                    <input type="hidden" id="google_event_id" value="">
                    <p style="margin: 0.5rem 0 0 0; font-size: 0.875rem; color: #64748b;">
                        Marque os checkboxes para que este evento seja contabilizado nas visitas e contratos fechados.
                    </p>
                </div>
                
                <div id="conflictWarning" class="conflict-warning" style="display: none;">
                    <h4>⚠️ Conflito Detectado</h4>
                    <div class="conflict-details" id="conflictDetails"></div>
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
        // Função auxiliar para escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Modal customizado de alerta
        function customAlert(mensagem, titulo = 'Aviso') {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.className = 'custom-alert-overlay';
                overlay.innerHTML = `
                    <div class="custom-alert">
                        <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                        <div class="custom-alert-body">${escapeHtml(mensagem)}</div>
                        <div class="custom-alert-actions">
                            <button class="custom-alert-btn custom-alert-btn-primary" onclick="this.closest('.custom-alert-overlay').remove(); resolveCustomAlert()">OK</button>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(overlay);
                
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        overlay.remove();
                        resolveCustomAlert();
                    }
                });
                
                window.resolveCustomAlert = () => {
                    overlay.remove();
                    resolve();
                };
            });
        }
        
        // Modal customizado de confirmação
        async function customConfirm(mensagem, titulo = 'Confirmar') {
            return new Promise((resolve) => {
                const overlay = document.createElement('div');
                overlay.className = 'custom-alert-overlay';
                overlay.innerHTML = `
                    <div class="custom-alert">
                        <div class="custom-alert-header">${escapeHtml(titulo)}</div>
                        <div class="custom-alert-body">${escapeHtml(mensagem)}</div>
                        <div class="custom-alert-actions">
                            <button class="custom-alert-btn custom-alert-btn-secondary" onclick="resolveCustomConfirm(false)">Cancelar</button>
                            <button class="custom-alert-btn custom-alert-btn-primary" onclick="resolveCustomConfirm(true)">Confirmar</button>
                        </div>
                    </div>
                `;
                
                document.body.appendChild(overlay);
                
                overlay.addEventListener('click', (e) => {
                    if (e.target === overlay) {
                        overlay.remove();
                        resolve(false);
                    }
                });
                
                window.resolveCustomConfirm = (resultado) => {
                    overlay.remove();
                    resolve(resultado);
                };
            });
        }
        
        let calendar;
        let currentFilters = {};
        
        // Inicializar calendário
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            
            if (!calendarEl) {
                console.error('Elemento calendar não encontrado!');
                return;
            }
            
            console.log('Inicializando FullCalendar...');
            
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
                    loadEvents(info.start, info.end, successCallback, failureCallback);
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

            setTimeout(() => {
                if (calendar && calendar.updateSize) {
                    calendar.updateSize();
                }
            }, 50);

            const mainContent = document.getElementById('mainContent');
            if (mainContent && 'MutationObserver' in window) {
                const observer = new MutationObserver(() => {
                    if (calendar && calendar.updateSize) {
                        setTimeout(() => calendar.updateSize(), 50);
                    }
                });
                observer.observe(mainContent, { attributes: true, attributeFilter: ['class', 'style'] });
            }
        });
        
        // Carregar eventos
        function loadEvents(start, end, successCallback, failureCallback) {
            const params = new URLSearchParams({
                start: start.toISOString(),
                end: end.toISOString(),
                ...currentFilters
            });
            
            fetch(`agenda_api.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (successCallback) {
                        successCallback(data);
                    }
                })
                .catch(error => {
                    console.error('Erro ao carregar eventos:', error);
                    if (failureCallback) {
                        failureCallback(error);
                    }
                });
        }
        
        // Abrir modal de evento
        function openEventModal(tipo, event = null, date = null) {
            const modal = document.getElementById('eventModal');
            const form = document.getElementById('eventForm');
            const title = document.getElementById('modalTitle');
            const tipoInput = document.getElementById('eventTipo');
            const espacoInput = document.getElementById('espaco');
            const espacoGroup = document.getElementById('espacoGroup');
            const statusGroup = document.getElementById('statusGroup');
            const conversionGroup = document.getElementById('conversionGroup');
            const deleteBtn = document.getElementById('deleteBtn');
            const forceBtn = document.getElementById('forceBtn');
            
            // Limpar formulário
            form.reset();
            document.getElementById('forcarConflitoInput').value = '0';
            document.getElementById('conflictWarning').style.display = 'none';
            document.getElementById('conflictDetails').innerHTML = '';
            
            if (event) {
                // Editar evento existente
                const eventTipo = event.extendedProps.tipo;
                const isGoogleEvent = eventTipo === 'google';
                
                // Configurar título e campos baseado no tipo
                if (isGoogleEvent) {
                    title.textContent = 'Evento do Google Calendar';
                    espacoInput.required = false;
                    
                    // Ocultar campos de edição para eventos do Google
                    document.getElementById('responsavel').closest('.form-group').style.display = 'none';
                    document.getElementById('espacoGroup').style.display = 'none';
                    document.getElementById('titulo').closest('.form-group').style.display = 'none';
                    document.getElementById('inicio').closest('.form-group').style.display = 'none';
                    document.getElementById('fim').closest('.form-group').style.display = 'none';
                    document.getElementById('descricao').closest('.form-group').style.display = 'none';
                    document.getElementById('lembrete').closest('.form-group').style.display = 'none';
                    statusGroup.style.display = 'none';
                    conversionGroup.style.display = 'none';
                    
                    // Mostrar seção do Google
                    document.getElementById('googleEventGroup').style.display = 'block';
                    document.getElementById('google_event_id').value = event.extendedProps.google_id || '';
                    
                    // Carregar dados do evento do Google
                    const googleEventDetails = document.getElementById('googleEventDetails');
                    if (googleEventDetails) {
                        // Título
                        document.getElementById('google_event_titulo').textContent = event.title || 'Sem título';
                        
                        // Data/Hora
                        const startDate = new Date(event.start);
                        const endDate = new Date(event.end);
                        const startStr = startDate.toLocaleString('pt-BR', { 
                            day: '2-digit', month: '2-digit', year: 'numeric',
                            hour: '2-digit', minute: '2-digit'
                        });
                        const endStr = endDate.toLocaleString('pt-BR', { 
                            hour: '2-digit', minute: '2-digit'
                        });
                        document.getElementById('google_event_datas').textContent = `${startStr} - ${endStr}`;
                        
                        // Descrição
                        const descricao = event.extendedProps.descricao;
                        if (descricao) {
                            document.getElementById('google_event_descricao').textContent = descricao;
                            document.getElementById('google_event_descricao_group').style.display = 'block';
                        } else {
                            document.getElementById('google_event_descricao_group').style.display = 'none';
                        }
                        
                        // Localização
                        const localizacao = event.extendedProps.espaco_nome;
                        if (localizacao) {
                            document.getElementById('google_event_localizacao').textContent = localizacao;
                            document.getElementById('google_event_localizacao_group').style.display = 'block';
                        } else {
                            document.getElementById('google_event_localizacao_group').style.display = 'none';
                        }
                        
                        // Organizador
                        const organizador = event.extendedProps.responsavel_nome;
                        if (organizador && organizador !== 'Google Calendar') {
                            document.getElementById('google_event_organizador').textContent = organizador;
                            document.getElementById('google_event_organizador_group').style.display = 'block';
                        } else {
                            document.getElementById('google_event_organizador_group').style.display = 'none';
                        }
                    }
                    
                    // Carregar checkboxes do Google
                    const ehVisita = event.extendedProps.eh_visita_agendada || false;
                    const contratoFechado = event.extendedProps.contrato_fechado || false;
                    document.getElementById('google_eh_visita').checked = ehVisita;
                    document.getElementById('google_contrato_fechado').checked = contratoFechado;
                    
                    // Ocultar botões de ação que não fazem sentido para Google
                    deleteBtn.style.display = 'none';
                    forceBtn.style.display = 'none';
                    document.querySelector('button[type="submit"]').style.display = 'none';
                    
                    // Mostrar link para Google Calendar
                    let linkDiv = document.getElementById('google_link_div');
                    if (!linkDiv && event.extendedProps.google_link) {
                        linkDiv = document.createElement('div');
                        linkDiv.className = 'form-group';
                        linkDiv.id = 'google_link_div';
                        linkDiv.style.marginTop = '1rem';
                        linkDiv.innerHTML = `<a href="${event.extendedProps.google_link}" target="_blank" class="btn btn-outline" style="text-decoration: none;">🔗 Abrir no Google Calendar</a>`;
                        document.getElementById('googleEventGroup').appendChild(linkDiv);
                    }
                } else {
                    title.textContent = 'Editar Evento';
                    // Mostrar todos os campos para eventos normais
                    form.querySelectorAll('.form-group').forEach(el => {
                        if (el.id !== 'googleEventGroup') {
                            el.style.display = '';
                        }
                    });
                    document.getElementById('googleEventGroup').style.display = 'none';
                }
                
                document.getElementById('eventId').value = event.id;
                document.getElementById('eventTipo').value = eventTipo;
                // Carregar responsável corretamente
                const responsavelId = event.extendedProps.responsavel_usuario_id;
                if (responsavelId) {
                    document.getElementById('responsavel').value = responsavelId;
                } else {
                    document.getElementById('responsavel').value = '1';
                }
                
                // Carregar espaço corretamente
                const espacoId = event.extendedProps.espaco_id;
                if (espacoId) {
                    document.getElementById('espaco').value = espacoId;
                } else {
                    document.getElementById('espaco').value = '';
                }
                document.getElementById('titulo').value = event.title;
                document.getElementById('inicio').value = formatDateTimeLocal(event.start);
                document.getElementById('fim').value = formatDateTimeLocal(event.end);
                document.getElementById('descricao').value = event.extendedProps.descricao || '';
                document.getElementById('lembrete').value = event.extendedProps.lembrete_minutos || 60;
                document.getElementById('status').value = event.extendedProps.status || 'agendado';
                
                // Carregar checkboxes com valores corretos (PostgreSQL retorna 't' ou 'f')
                const compareceu = event.extendedProps.compareceu;
                const fechouContrato = event.extendedProps.fechou_contrato;
                
                // Debug
                console.log('📥 Loading event data:', {
                    compareceu: compareceu,
                    fechou_contrato: fechouContrato,
                    compareceu_type: typeof compareceu,
                    fechou_contrato_type: typeof fechouContrato
                });
                
                // Inverter lógica: marcado = Não Compareceu
                const compareceuChecked = !(compareceu === true || compareceu === 'true' || compareceu === '1' || compareceu === 1 || compareceu === 't' || compareceu === 'T');
                const fechouContratoChecked = (fechouContrato === true || fechouContrato === 'true' || fechouContrato === '1' || fechouContrato === 1 || fechouContrato === 't' || fechouContrato === 'T');
                
                document.getElementById('compareceu').checked = compareceuChecked;
                document.getElementById('fechou_contrato').checked = fechouContratoChecked;
                
                console.log('✅ Checkboxes setados:', {
                    'Não Compareceu': compareceuChecked,
                    'Fechou Contrato': fechouContratoChecked
                });
                // Mostrar/ocultar campos baseado no tipo
                espacoGroup.style.display = eventTipo === 'visita' ? 'block' : 'none';
                espacoInput.required = eventTipo === 'visita';
                statusGroup.style.display = 'block';
                conversionGroup.style.display = eventTipo === 'visita' ? 'block' : 'none';
                deleteBtn.style.display = 'block';
                forceBtn.style.display = 'none';
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
                espacoInput.required = tipo === 'visita';
                statusGroup.style.display = 'none';
                conversionGroup.style.display = 'none';
                deleteBtn.style.display = 'none';
                forceBtn.style.display = 'none';
            }
            
            modal.classList.add('active');
        }
        
        // Fechar modal
        function closeEventModal() {
            const modal = document.getElementById('eventModal');
            modal.classList.remove('active');
            document.getElementById('forcarConflitoInput').value = '0';
            document.getElementById('conflictWarning').style.display = 'none';
            document.getElementById('conflictDetails').innerHTML = '';
            document.getElementById('forceBtn').style.display = 'none';
            document.getElementById('forceBtn').textContent = '⚡ Forçar Conflito';
            
            // Resetar campos do Google
            document.getElementById('googleEventGroup').style.display = 'none';
            document.getElementById('google_event_id').value = '';
            document.getElementById('google_eh_visita').checked = false;
            document.getElementById('google_contrato_fechado').checked = false;
            
            // Remover link do Google se existir
            const linkDiv = document.getElementById('google_link_div');
            if (linkDiv) {
                linkDiv.remove();
            }
            
            // Mostrar todos os campos novamente
            form.querySelectorAll('.form-group').forEach(el => {
                if (el.id !== 'googleEventGroup') {
                    el.style.display = '';
                }
            });
            
            // Mostrar botões novamente
            document.querySelector('button[type="submit"]').style.display = '';
        }
        
        // Formatar data para input datetime-local
        function formatDateTimeLocal(date) {
            const d = new Date(date);
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            return d.toISOString().slice(0, 16);
        }
        
        // Auto-preencer data fim ao preencher data início
        document.getElementById('inicio').addEventListener('change', function() {
            const inicio = this.value;
            if (inicio && !document.getElementById('fim').value) {
                const inicioDate = new Date(inicio);
                const fimDate = new Date(inicioDate.getTime() + 60 * 60 * 1000); // +1 hora
                document.getElementById('fim').value = formatDateTimeLocal(fimDate);
            }
        });
        
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
            const isHidden = filters.style.display === 'none' || filters.style.display === '';
            filters.style.display = isHidden ? 'flex' : 'none';
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
                customAlert('Selecione um responsável primeiro', '⚠️ Validação');
                return;
            }
            
            // Calcular duração em minutos
            let duracao = Math.round((new Date(fim) - new Date(inicio)) / (1000 * 60));
            if (!Number.isFinite(duracao) || duracao <= 0) {
                duracao = 60;
            }
            
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
                body: `acao=sugerir_horario&responsavel_id=${encodeURIComponent(responsavel)}&espaco_id=${encodeURIComponent(espaco)}&duracao=${encodeURIComponent(duracao)}&inicio_base=${encodeURIComponent(inicio || '')}`
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
                    showSuggestionFeedback(data.error || 'Nenhum horário livre encontrado para os próximos dias.');
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
        
        // Função para mostrar toast/snackbar
        function showToast(message, type = 'success') {
            // Remover toast anterior se existir
            const existingToast = document.getElementById('agendaToast');
            if (existingToast) {
                existingToast.remove();
            }
            
            // Criar novo toast
            const toast = document.createElement('div');
            toast.id = 'agendaToast';
            toast.textContent = message;
            
            // Estilos baseados no tipo
            const styles = {
                success: { bg: '#10b981', color: 'white' },
                error: { bg: '#dc2626', color: 'white' },
                info: { bg: '#3b82f6', color: 'white' }
            };
            
            const style = styles[type] || styles.success;
            
            toast.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                background: ${style.bg};
                color: ${style.color};
                padding: 16px 24px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 10002;
                font-size: 14px;
                font-weight: 500;
                animation: slideInUp 0.3s ease-out;
                max-width: 400px;
            `;
            
            // Adicionar animação
            const styleSheet = document.createElement('style');
            styleSheet.textContent = `
                @keyframes slideInUp {
                    from {
                        transform: translateY(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateY(0);
                        opacity: 1;
                    }
                }
                @keyframes slideOutDown {
                    from {
                        transform: translateY(0);
                        opacity: 1;
                    }
                    to {
                        transform: translateY(100%);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(styleSheet);
            
            document.body.appendChild(toast);
            
            // Auto-remover após 3 segundos
            setTimeout(() => {
                toast.style.animation = 'slideOutDown 0.3s ease-out';
                setTimeout(() => {
                    if (toast.parentNode) {
                        toast.remove();
                    }
                }, 300);
            }, 3000);
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
        
        // Forçar conflito
        function forceConflict() {
            if (!canForceConflict()) return;
            document.getElementById('forcarConflitoInput').value = '1';
            document.getElementById('forceBtn').textContent = '⏳ Salvando com conflito...';
            document.getElementById('eventForm').requestSubmit();
        }
        
        // Atualizar evento do Google Calendar
        function updateGoogleEvent(checkboxElement = null) {
            const eventId = document.getElementById('google_event_id').value;
            if (!eventId) {
                console.error('ID do evento Google não encontrado');
                return;
            }
            
            const ehVisita = document.getElementById('google_eh_visita').checked;
            const contratoFechado = document.getElementById('google_contrato_fechado').checked;
            
            const formData = new FormData();
            formData.append('event_id', eventId);
            formData.append('eh_visita_agendada', ehVisita ? '1' : '0');
            formData.append('contrato_fechado', contratoFechado ? '1' : '0');
            
            fetch('agenda_google_event_update.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Feedback visual
                    if (checkboxElement) {
                        const originalBg = checkboxElement.style.backgroundColor;
                        checkboxElement.style.backgroundColor = '#10b981';
                        setTimeout(() => {
                            checkboxElement.style.backgroundColor = originalBg;
                        }, 500);
                    }
                    
                    // Recarregar eventos para atualizar contadores
                    calendar.refetchEvents();
                } else {
                    customAlert('Erro ao atualizar evento: ' + (data.error || 'Erro desconhecido'), '❌ Erro');
                    // Reverter checkbox
                    if (checkboxElement) {
                        checkboxElement.checked = !checkboxElement.checked;
                    }
                }
            })
            .catch(error => {
                customAlert('Erro de conexão: ' + error.message, '❌ Erro');
                // Reverter checkbox
                if (checkboxElement) {
                    checkboxElement.checked = !checkboxElement.checked;
                }
            });
        }
        
        // Excluir evento
        async function deleteEvent() {
            const confirmado = await customConfirm('Tem certeza que deseja excluir este evento?', '⚠️ Confirmar Exclusão');
            if (!confirmado) return;
            
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
                    customAlert('Erro ao excluir evento: ' + data.error, '❌ Erro');
                }
            })
            .catch(error => {
                customAlert('Erro de conexão: ' + error.message, '❌ Erro');
            });
        }
        
        // Submeter formulário
        document.getElementById('eventForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const eventoId = formData.get('evento_id');
            const acao = eventoId ? 'atualizar_evento' : 'criar_evento';
            
            // Adicionar ação
            formData.append('acao', acao);
            
            // REMOVER qualquer campo que possa estar vazio ANTES de adicionar os corretos
            if (formData.has('compareceu')) formData.delete('compareceu');
            if (formData.has('fechou_contrato')) formData.delete('fechou_contrato');
            if (formData.has('fechou_ref')) formData.delete('fechou_ref');
            if (formData.has('participantes')) formData.delete('participantes');
            
            // SEMPRE definir valores VALIDOS - NUNCA string vazia
            const compareceuEl = document.getElementById('compareceu');
            const fechouContratoEl = document.getElementById('fechou_contrato');
            
            if (compareceuEl) {
                const value = compareceuEl.checked ? '0' : '1';
                formData.append('compareceu', value);
                console.log('✅ compareceu:', compareceuEl.checked, '→ enviado:', value);
            } else {
                formData.append('compareceu', '1'); // Default
            }
            
            if (fechouContratoEl) {
                const value = fechouContratoEl.checked ? '1' : '0';
                formData.append('fechou_contrato', value);
                console.log('✅ fechou_contrato:', fechouContratoEl.checked, '→ enviado:', value);
            } else {
                formData.append('fechou_contrato', '0'); // Default
            }
            
            // Campos opcionais - garantir que existam
            if (formData.has('fechou_ref')) {
                formData.delete('fechou_ref');
                formData.append('fechou_ref', formData.get('fechou_ref') || '');
            }
            
            // Garantir que participantes exista
            if (!formData.has('participantes')) {
                formData.append('participantes', '[]');
            }
            
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
                const contentType = response.headers.get("content-type");
                if (!response.ok) {
                    throw new Error('Erro na requisição: ' + response.status);
                }
                // Verificar se a resposta é JSON
                if (contentType && contentType.includes("application/json")) {
                    return response.json();
                } else {
                    // Se não for JSON, tentar converter
                    return response.text().then(text => {
                        console.error('Resposta não é JSON:', text);
                        throw new Error('Resposta do servidor não é JSON válido');
                    });
                }
            })
            .then(data => {
                console.log('Resposta do servidor:', data);
                
                if (data.success) {
                    // Sucesso
                    document.getElementById('forcarConflitoInput').value = '0';
                    submitBtn.innerHTML = '✅ Salvo!';
                    
                    // Mostrar mensagem suspensa
                    const tipoEvento = formData.get('eventoTipo') || formData.get('tipo');
                    const tipoText = tipoEvento === 'bloqueio' ? 'Bloqueio' : 'Visita';
                    const isEdit = eventoId ? 'editado' : 'criado';
                    showToast('✅ ' + tipoText + ' ' + isEdit + ' com sucesso!', 'success');
                    
                    setTimeout(() => {
                        if (typeof calendar !== 'undefined' && calendar.refetchEvents) {
                            calendar.refetchEvents();
                        }
                        closeEventModal();
                        
                        // Restaurar botão após fechar modal
                        setTimeout(() => {
                            submitBtn.innerHTML = originalText;
                            submitBtn.disabled = false;
                        }, 500);
                    }, 1000);
                } else {
                    // Erro
                    console.error('Erro ao salvar:', data.error || data.message || 'Erro desconhecido');
                    submitBtn.innerHTML = '❌ Erro';
                    showToast('❌ Erro ao salvar: ' + (data.error || data.message || 'Erro desconhecido'), 'error');
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 2000);
                    
                    if (data.conflito) {
                        document.getElementById('forcarConflitoInput').value = '0';
                        const conflitoTitulo = data.conflito.evento_conflito_titulo ? `<br>Evento em conflito: <strong>${escapeHtml(data.conflito.evento_conflito_titulo)}</strong>` : '';
                        // Mostrar conflito
                        document.getElementById('conflictDetails').innerHTML = `
                            <strong>Conflito detectado:</strong><br>
                            ${data.conflito.conflito_responsavel ? 'Responsável já tem evento neste horário' : ''}
                            ${data.conflito.conflito_espaco ? 'Espaço já está ocupado' : ''}
                            ${conflitoTitulo}
                        `;
                        document.getElementById('conflictWarning').style.display = 'block';
                        
                        if (canForceConflict()) {
                            document.getElementById('forceBtn').style.display = 'block';
                            document.getElementById('forceBtn').textContent = '⚡ Salvar Mesmo Assim';
                        }
                    } else {
                        customAlert('Erro: ' + (data.error || data.message || 'Erro desconhecido'), '❌ Erro');
                    }
                }
            })
            .catch(error => {
                console.error('Erro na requisição:', error);
                submitBtn.innerHTML = '❌ Erro de Rede';
                customAlert('Erro de conexão: ' + error.message, '❌ Erro');
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

<?php endSidebar(); ?>
