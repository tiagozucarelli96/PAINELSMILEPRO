<?php
// agenda_helper.php — Helper principal do sistema de agenda interna
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/core/notification_dispatcher.php';
require_once __DIR__ . '/core/google_calendar_helper.php';

class AgendaHelper {
    private const GOOGLE_TIMEZONE = 'America/Sao_Paulo';
    private $pdo;
    private $emailHelper;
    private $notificationDispatcher;
    private $permissionValueCache = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        // EmailHelper agora usa EmailGlobalHelper internamente (sistema_email_config)
        $this->emailHelper = new EmailHelper();
        $this->notificationDispatcher = new NotificationDispatcher($this->pdo);
        $this->notificationDispatcher->ensureInternalSchema();
        $this->ensureGoogleSyncSchema();
    }

    /**
     * Garante os metadados necessários para vincular eventos internos ao Google Calendar.
     */
    private function ensureGoogleSyncSchema(): void {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS google_calendar_id VARCHAR(255)");
            $this->pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS google_event_id VARCHAR(255)");
            $this->pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS google_sync_status VARCHAR(20)");
            $this->pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS google_sync_error TEXT");
            $this->pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS google_synced_at TIMESTAMP");
            $this->pdo->exec("
                CREATE UNIQUE INDEX IF NOT EXISTS idx_agenda_eventos_google_event
                ON agenda_eventos(google_calendar_id, google_event_id)
                WHERE google_event_id IS NOT NULL
            ");
        } catch (Throwable $e) {
            error_log('[AGENDA_GOOGLE_SYNC] Falha ao validar schema: ' . $e->getMessage());
        }
    }

    /**
     * Normaliza valores vindos do banco para boolean.
     */
    private function normalizeBool($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int)$value === 1;
        }

        $normalized = strtolower(trim((string)$value));
        if ($normalized === '' || in_array($normalized, ['0', 'f', 'false', 'off', 'no', 'n'], true)) {
            return false;
        }
        if (in_array($normalized, ['1', 't', 'true', 'on', 'yes', 'y'], true)) {
            return true;
        }

        return (bool)$value;
    }

    private function getActiveGoogleCalendarConfig(): ?array {
        try {
            $stmt = $this->pdo->query("
                SELECT google_calendar_id, google_calendar_name, ativo
                FROM google_calendar_config
                WHERE ativo = TRUE
                LIMIT 1
            ");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);
            return $config ?: null;
        } catch (Throwable $e) {
            error_log('[AGENDA_GOOGLE_SYNC] Config Google indisponível: ' . $e->getMessage());
            return null;
        }
    }

    private function getAgendaEventoForGoogle($evento_id): ?array {
        $stmt = $this->pdo->prepare("
            SELECT ae.*, esp.nome AS espaco_nome, u.nome AS responsavel_nome
            FROM agenda_eventos ae
            LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
            LEFT JOIN usuarios u ON u.id = ae.responsavel_usuario_id
            WHERE ae.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$evento_id]);
        $evento = $stmt->fetch(PDO::FETCH_ASSOC);
        return $evento ?: null;
    }

    private function googleDateTime($value): string {
        $dt = new DateTimeImmutable((string)$value, new DateTimeZone(self::GOOGLE_TIMEZONE));
        return $dt->format('Y-m-d\TH:i:s');
    }

    private function buildGoogleEventPayload(array $evento): array {
        $descricao = trim((string)($evento['descricao'] ?? ''));
        $metadados = [
            'Origem: Painel Smile PRO',
            'ID interno: ' . (int)$evento['id'],
        ];

        if (!empty($evento['responsavel_nome'])) {
            $metadados[] = 'Responsável: ' . (string)$evento['responsavel_nome'];
        }

        $descricaoGoogle = trim($descricao . "\n\n" . implode("\n", $metadados));

        $payload = [
            'summary' => (string)$evento['titulo'],
            'description' => $descricaoGoogle,
            'start' => [
                'dateTime' => $this->googleDateTime($evento['inicio']),
                'timeZone' => self::GOOGLE_TIMEZONE,
            ],
            'end' => [
                'dateTime' => $this->googleDateTime($evento['fim']),
                'timeZone' => self::GOOGLE_TIMEZONE,
            ],
            'extendedProperties' => [
                'private' => [
                    'painel_smile' => '1',
                    'agenda_evento_id' => (string)(int)$evento['id'],
                ],
            ],
        ];

        if (!empty($evento['espaco_nome'])) {
            $payload['location'] = (string)$evento['espaco_nome'];
        }

        return $payload;
    }

    private function markGoogleSyncSuccess($evento_id, string $calendar_id, string $google_event_id): void {
        $stmt = $this->pdo->prepare("
            UPDATE agenda_eventos
            SET google_calendar_id = ?,
                google_event_id = ?,
                google_sync_status = 'sincronizado',
                google_sync_error = NULL,
                google_synced_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $calendar_id,
            $google_event_id !== '' ? $google_event_id : null,
            (int)$evento_id
        ]);
    }

    private function markGoogleSyncError($evento_id, Throwable $e): void {
        $stmt = $this->pdo->prepare("
            UPDATE agenda_eventos
            SET google_sync_status = 'erro',
                google_sync_error = ?,
                google_synced_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([substr($e->getMessage(), 0, 1000), (int)$evento_id]);
    }

    private function syncEventoToGoogle($evento_id): void {
        $config = $this->getActiveGoogleCalendarConfig();
        if (!$config || empty($config['google_calendar_id'])) {
            return;
        }

        $evento = $this->getAgendaEventoForGoogle($evento_id);
        if (!$evento) {
            return;
        }

        try {
            $helper = new GoogleCalendarHelper();
            $calendar_id = (string)$config['google_calendar_id'];
            $google_event_id = trim((string)($evento['google_event_id'] ?? ''));

            if (($evento['status'] ?? '') === 'cancelado') {
                if ($google_event_id !== '') {
                    $helper->deleteEvent($calendar_id, $google_event_id);
                }
                $this->markGoogleSyncSuccess($evento_id, $calendar_id, $google_event_id);
                return;
            }

            $payload = $this->buildGoogleEventPayload($evento);
            if ($google_event_id !== '') {
                $response = $helper->updateEvent($calendar_id, $google_event_id, $payload);
            } else {
                $response = $helper->createEvent($calendar_id, $payload);
                $google_event_id = (string)($response['id'] ?? '');
            }

            if ($google_event_id === '') {
                throw new RuntimeException('Google não retornou o ID do evento sincronizado.');
            }

            $this->markGoogleSyncSuccess($evento_id, $calendar_id, $google_event_id);
        } catch (Throwable $e) {
            error_log('[AGENDA_GOOGLE_SYNC] Falha ao sincronizar evento ' . (int)$evento_id . ': ' . $e->getMessage());
            $this->markGoogleSyncError($evento_id, $e);
        }
    }

    private function deleteEventoFromGoogleIfLinked(array $evento): void {
        $calendar_id = trim((string)($evento['google_calendar_id'] ?? ''));
        $google_event_id = trim((string)($evento['google_event_id'] ?? ''));
        if ($calendar_id === '' || $google_event_id === '') {
            return;
        }

        try {
            $helper = new GoogleCalendarHelper();
            $helper->deleteEvent($calendar_id, $google_event_id);
        } catch (Throwable $e) {
            error_log('[AGENDA_GOOGLE_SYNC] Falha ao excluir evento Google ' . $google_event_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Lê uma permissão de usuário de forma resiliente (coluna opcional/legado).
     * Retorna null quando coluna/registro não existir.
     */
    private function getUserPermissionValue($usuario_id, $columnName): ?bool {
        $usuario_id = (int)$usuario_id;
        if ($usuario_id <= 0 || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string)$columnName)) {
            return null;
        }

        $cacheKey = $usuario_id . ':' . $columnName;
        if (array_key_exists($cacheKey, $this->permissionValueCache)) {
            return $this->permissionValueCache[$cacheKey];
        }

        try {
            $stmt = $this->pdo->prepare("SELECT {$columnName} FROM usuarios WHERE id = ?");
            $stmt->execute([$usuario_id]);
            $value = $stmt->fetchColumn();

            if ($value === false) {
                $this->permissionValueCache[$cacheKey] = null;
                return null;
            }

            $normalized = $this->normalizeBool($value);
            $this->permissionValueCache[$cacheKey] = $normalized;
            return $normalized;
        } catch (Throwable $e) {
            $this->permissionValueCache[$cacheKey] = null;
            return null;
        }
    }
    
    /**
     * Verificar permissões de agenda
     */
    public function canAccessAgenda($usuario_id) {
        // Compatibilidade: alguns ambientes usam somente perm_agenda (sidebar),
        // outros usam perm_agenda_ver (agenda legada).
        $permAgenda = $this->getUserPermissionValue($usuario_id, 'perm_agenda');
        $permAgendaVer = $this->getUserPermissionValue($usuario_id, 'perm_agenda_ver');

        return (bool)($permAgenda || $permAgendaVer);
    }

    public function canCreateEvents($usuario_id) {
        $permAgendaMeus = $this->getUserPermissionValue($usuario_id, 'perm_agenda_meus');
        if ($permAgendaMeus !== null) {
            return $permAgendaMeus;
        }

        // Fallback para bancos legados sem perm_agenda_meus.
        return $this->canAccessAgenda($usuario_id);
    }

    public function canManageOthersEvents($usuario_id) {
        return (bool)$this->getUserPermissionValue($usuario_id, 'perm_gerir_eventos_outros');
    }

    public function canForceConflict($usuario_id) {
        return (bool)$this->getUserPermissionValue($usuario_id, 'perm_forcar_conflito');
    }

    public function canViewReports($usuario_id) {
        return (bool)$this->getUserPermissionValue($usuario_id, 'perm_agenda_relatorios');
    }
    
    /**
     * Obter espaços disponíveis
     */
    public function obterEspacos() {
        $stmt = $this->pdo->query("SELECT * FROM agenda_espacos WHERE ativo = TRUE ORDER BY nome");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter usuários com cores
     */
    public function obterUsuariosComCores() {
        $stmt = $this->pdo->query("
            SELECT id, nome, cor_agenda, agenda_lembrete_padrao_min 
            FROM usuarios 
            WHERE ativo = TRUE 
            ORDER BY nome
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verificar conflitos
     */
    public function verificarConflitos($responsavel_id, $espaco_id, $inicio, $fim, $evento_id = null) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM verificar_conflito_agenda(?, ?, ?, ?, ?)
        ");
        $stmt->execute([$responsavel_id, $espaco_id, $inicio, $fim, $evento_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Criar evento
     */
    public function criarEvento($dados) {
        try {
            $inicio_ts = strtotime((string)($dados['inicio'] ?? ''));
            $fim_ts = strtotime((string)($dados['fim'] ?? ''));
            if ($inicio_ts === false || $fim_ts === false || $fim_ts <= $inicio_ts) {
                return [
                    'success' => false,
                    'error' => 'Período inválido. Ajuste data/hora de início e fim.'
                ];
            }
            if (($dados['tipo'] ?? '') === 'visita' && empty($dados['espaco_id'])) {
                return [
                    'success' => false,
                    'error' => 'Selecione um espaço para agendar uma visita.'
                ];
            }

            // Verificar conflitos se não forçar
            if (!$dados['forcar_conflito']) {
                $conflito = $this->verificarConflitos(
                    $dados['responsavel_usuario_id'],
                    $dados['espaco_id'],
                    $dados['inicio'],
                    $dados['fim']
                );
                
                if ($conflito['conflito_responsavel'] || $conflito['conflito_espaco']) {
                    return [
                        'success' => false,
                        'error' => 'Conflito detectado',
                        'conflito' => $conflito
                    ];
                }
            }
            
            // Obter cor do responsável
            $stmt = $this->pdo->prepare("SELECT cor_agenda FROM usuarios WHERE id = ?");
            $stmt->execute([$dados['responsavel_usuario_id']]);
            $cor_responsavel = $stmt->fetchColumn();
            
            // Criar evento - valores padrão: checkboxes desmarcados
            $stmt = $this->pdo->prepare("
                INSERT INTO agenda_eventos (
                    tipo, titulo, descricao, inicio, fim, responsavel_usuario_id, 
                    criado_por_usuario_id, espaco_id, lembrete_minutos, 
                    compareceu, fechou_contrato, participantes, cor_evento
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '1', '0', ?, ?)
            ");
            
            $stmt->execute([
                $dados['tipo'],
                $dados['titulo'],
                $dados['descricao'],
                $dados['inicio'],
                $dados['fim'],
                $dados['responsavel_usuario_id'],
                $dados['criado_por_usuario_id'],
                $dados['espaco_id'],
                $dados['lembrete_minutos'],
                json_encode($dados['participantes'] ?? []),
                $cor_responsavel
            ]);
            
            $evento_id = $this->pdo->lastInsertId();
            
            // Enviar notificação de criação
            $this->enviarNotificacaoEvento($evento_id, 'criacao');
            $this->syncEventoToGoogle($evento_id);
            
            return [
                'success' => true,
                'evento_id' => $evento_id
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Atualizar evento
     */
    public function atualizarEvento($evento_id, $dados) {
        try {
            $inicio_ts = strtotime((string)($dados['inicio'] ?? ''));
            $fim_ts = strtotime((string)($dados['fim'] ?? ''));
            if ($inicio_ts === false || $fim_ts === false || $fim_ts <= $inicio_ts) {
                return [
                    'success' => false,
                    'error' => 'Período inválido. Ajuste data/hora de início e fim.'
                ];
            }
            if (($dados['tipo'] ?? '') === 'visita' && empty($dados['espaco_id'])) {
                return [
                    'success' => false,
                    'error' => 'Selecione um espaço para agendar uma visita.'
                ];
            }

            // Verificar conflitos se não forçar
            if (!$dados['forcar_conflito']) {
                $conflito = $this->verificarConflitos(
                    $dados['responsavel_usuario_id'],
                    $dados['espaco_id'],
                    $dados['inicio'],
                    $dados['fim'],
                    $evento_id
                );
                
                if ($conflito['conflito_responsavel'] || $conflito['conflito_espaco']) {
                    return [
                        'success' => false,
                        'error' => 'Conflito detectado',
                        'conflito' => $conflito
                    ];
                }
            }
            
            // Converter valores booleanos corretamente
            // VALIDAR primeiro - NUNCA permitir string vazia
            $compareceu_val = $dados['compareceu'] ?? '1';
            $fechou_contrato_val = $dados['fechou_contrato'] ?? '0';
            
            // Se string vazia chegou aqui, substituir por default
            if ($compareceu_val === '' || $compareceu_val === null) $compareceu_val = '1';
            if ($fechou_contrato_val === '' || $fechou_contrato_val === null) $fechou_contrato_val = '0';
            
            // Debug
            error_log("compareceu recebido no helper: " . var_export($compareceu_val, true));
            error_log("fechou_contrato recebido no helper: " . var_export($fechou_contrato_val, true));
            
            // Para compareceu: '1' = true, '0' = false
            // Lógica invertida: marcado no checkbox = '0' (não compareceu)
            $compareceu = ($compareceu_val === '1' || $compareceu_val === 1 || $compareceu_val === true || $compareceu_val === 'true');
            
            // Para fechou_contrato: '1' = true, '0' = false
            $fechou_contrato = ($fechou_contrato_val === '1' || $fechou_contrato_val === 1 || $fechou_contrato_val === true || $fechou_contrato_val === 'true');
            
            // Converter para boolean strict - GARANTE que seja true ou false
            $compareceu = (bool)$compareceu;
            $fechou_contrato = (bool)$fechou_contrato;
            
            error_log("Valores finais boolean: compareceu=" . var_export($compareceu, true) . ", fechou_contrato=" . var_export($fechou_contrato, true));
            
            // CRÍTICO: PDO pode converter false para string vazia em PostgreSQL
            // Converter explicitamente para PgSQL boolean format
            $compareceu_db = $compareceu ? '1' : '0';
            $fechou_contrato_db = $fechou_contrato ? '1' : '0';
            
            error_log("Valores para PostgreSQL: compareceu='$compareceu_db', fechou_contrato='$fechou_contrato_db'");
            
            $stmt = $this->pdo->prepare("
                UPDATE agenda_eventos SET 
                    tipo = ?, titulo = ?, descricao = ?, inicio = ?, fim = ?, 
                    responsavel_usuario_id = ?, espaco_id = ?, lembrete_minutos = ?, 
                    status = ?, compareceu = ?, fechou_contrato = ?, fechou_ref = ?,
                    participantes = ?
                WHERE id = ?
            ");
            
            // VALIDAR fechou_ref ANTES de executar
            $fechou_ref = $dados['fechou_ref'] ?? null;
            if ($fechou_ref === '') $fechou_ref = null;
            
            // Garantir que participantes seja array válido
            $participantes = $dados['participantes'] ?? [];
            if (is_array($participantes)) {
                $participantes_json = json_encode($participantes);
            } else {
                $participantes_json = '[]';
            }
            
            error_log("Executando SQL com valores: compareceu=" . var_export($compareceu, true) . ", fechou_contrato=" . var_export($fechou_contrato, true) . ", fechou_ref=" . var_export($fechou_ref, true));
            
            // Usar valores convertidos para PostgreSQL em vez de boolean nativo
            $stmt->execute([
                $dados['tipo'],
                $dados['titulo'],
                $dados['descricao'],
                $dados['inicio'],
                $dados['fim'],
                $dados['responsavel_usuario_id'],
                $dados['espaco_id'],
                $dados['lembrete_minutos'],
                $dados['status'],
                $compareceu_db,        // Usar string '1' ou '0' em vez de boolean
                $fechou_contrato_db,   // Usar string '1' ou '0' em vez de boolean
                $fechou_ref,
                $participantes_json,
                $evento_id
            ]);
            
            // Enviar notificação de alteração
            $this->enviarNotificacaoEvento($evento_id, 'alteracao');
            $this->syncEventoToGoogle($evento_id);
            
            return [
                'success' => true
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Excluir evento
     */
    public function excluirEvento($evento_id) {
        try {
            $evento = $this->getAgendaEventoForGoogle($evento_id);
            if ($evento) {
                $this->deleteEventoFromGoogleIfLinked($evento);
            }

            $stmt = $this->pdo->prepare("DELETE FROM agenda_eventos WHERE id = ?");
            $stmt->execute([$evento_id]);
            
            return [
                'success' => true
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obter eventos para calendário
     */
    public function obterEventosCalendario($usuario_id, $inicio, $fim, $filtros = []) {
        // Mostrar eventos que se sobrepõem ao período solicitado
        $where_conditions = ["(ae.inicio < ? AND ae.fim > ?)"];
        $params = [$fim, $inicio];
        
        // Filtro por responsável
        if (isset($filtros['responsavel_id']) && $filtros['responsavel_id']) {
            $where_conditions[] = "ae.responsavel_usuario_id = ?";
            $params[] = $filtros['responsavel_id'];
        }
        
        // Filtro por espaço
        if (isset($filtros['espaco_id']) && $filtros['espaco_id']) {
            $where_conditions[] = "ae.espaco_id = ?";
            $params[] = $filtros['espaco_id'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
            $stmt = $this->pdo->prepare("
            SELECT 
                ae.id,
                ae.tipo,
                ae.titulo,
                ae.descricao,
                ae.inicio,
                ae.fim,
                ae.status,
                ae.compareceu,
                ae.fechou_contrato,
                ae.cor_evento,
                ae.responsavel_usuario_id,
                ae.espaco_id,
                u.nome as responsavel_nome,
                u.cor_agenda as cor_agenda,
                esp.nome as espaco_nome,
                criador.nome as criado_por_nome
            FROM agenda_eventos ae
            LEFT JOIN usuarios u ON ae.responsavel_usuario_id = u.id
            LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
            LEFT JOIN usuarios criador ON ae.criado_por_usuario_id = criador.id
            WHERE {$where_clause}
            AND ae.status != 'cancelado'
            ORDER BY ae.inicio ASC
        ");
        
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter agenda do dia
     */
    public function obterAgendaDia($usuario_id, $horas = 24) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM obter_proximos_eventos(?, ?)
        ");
        $stmt->execute([$usuario_id, $horas]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Enviar notificação de evento
     */
    private function enviarNotificacaoEvento($evento_id, $tipo) {
        try {
            // Buscar dados do evento
            $stmt = $this->pdo->prepare("
                SELECT ae.*, u.nome as responsavel_nome, u.email as responsavel_email,
                       esp.nome as espaco_nome, criador.nome as criado_por_nome
                FROM agenda_eventos ae
                JOIN usuarios u ON ae.responsavel_usuario_id = u.id
                LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
                JOIN usuarios criador ON ae.criado_por_usuario_id = criador.id
                WHERE ae.id = ?
            ");
            $stmt->execute([$evento_id]);
            $evento = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$evento) {
                return;
            }

            $tituloEvento = (string)($evento['titulo'] ?? 'Evento');
            $inicioEvento = (string)($evento['inicio'] ?? '');
            $fimEvento = (string)($evento['fim'] ?? '');
            $responsavelNome = (string)($evento['responsavel_nome'] ?? 'Responsável');
            $responsavelEmail = (string)($evento['responsavel_email'] ?? '');
            $responsavelId = (int)($evento['responsavel_usuario_id'] ?? 0);
            $espacoNome = (string)($evento['espaco_nome'] ?? '');
            $descricaoEvento = (string)($evento['descricao'] ?? '');
            $criadoPorNome = (string)($evento['criado_por_nome'] ?? '');
            $acaoTexto = $tipo === 'criacao' ? 'Evento criado com sucesso.' : 'Evento atualizado.';
            
            // Preparar dados do e-mail
            $assunto = "Agenda: {$tituloEvento} - " . date('d/m/Y H:i', strtotime($inicioEvento));
            
            $mensagem = "
            <h3>📅 " . htmlspecialchars($tituloEvento, ENT_QUOTES, 'UTF-8') . "</h3>
            <p><strong>Data/Hora:</strong> " . date('d/m/Y H:i', strtotime($inicioEvento)) . " - " . date('H:i', strtotime($fimEvento)) . "</p>
            <p><strong>Duração:</strong> " . $this->calcularDuracao($inicioEvento, $fimEvento) . "</p>
            ";
            
            if ($espacoNome !== '') {
                $mensagem .= "<p><strong>Espaço:</strong> " . htmlspecialchars($espacoNome, ENT_QUOTES, 'UTF-8') . "</p>";
            }
            
            if ($descricaoEvento !== '') {
                $mensagem .= "<p><strong>Observações:</strong> " . nl2br(htmlspecialchars($descricaoEvento, ENT_QUOTES, 'UTF-8')) . "</p>";
            }
            
            $mensagem .= "<p><strong>Agendado por:</strong> " . htmlspecialchars($criadoPorNome, ENT_QUOTES, 'UTF-8') . "</p>";
            
            if ($tipo === 'criacao') {
                $mensagem .= "<p>✅ Evento criado com sucesso!</p>";
            } else {
                $mensagem .= "<p>📝 Evento atualizado!</p>";
            }

            $mensagemInterna = sprintf(
                '%s em %s (%s). %s',
                $tituloEvento,
                date('d/m/Y H:i', strtotime($inicioEvento)),
                $this->calcularDuracao($inicioEvento, $fimEvento),
                $acaoTexto
            );

            if ($responsavelId > 0) {
                $this->notificationDispatcher->dispatch(
                    [['id' => $responsavelId, 'email' => $responsavelEmail]],
                    [
                        'tipo' => $tipo === 'criacao' ? 'agenda_evento_criado' : 'agenda_evento_atualizado',
                        'referencia_id' => (int)$evento_id,
                        'titulo' => $assunto,
                        'mensagem' => $mensagemInterna,
                        'url_destino' => 'index.php?page=agenda',
                        'push_titulo' => 'Agenda atualizada',
                        'push_mensagem' => $mensagemInterna,
                        'email_assunto' => $assunto,
                        'email_html' => $mensagem,
                    ],
                    [
                        'internal' => true,
                        'push' => true,
                        'email' => true,
                    ]
                );
            } elseif ($responsavelEmail !== '') {
                // Fallback de e-mail para cenários sem responsável interno válido.
                $this->emailHelper->enviarNotificacao(
                    $responsavelEmail,
                    $responsavelNome,
                    $assunto,
                    $mensagem
                );
            }
            
        } catch (Exception $e) {
            error_log("Erro ao enviar notificação de evento: " . $e->getMessage());
        }
    }
    
    /**
     * Calcular duração entre dois timestamps
     */
    private function calcularDuracao($inicio, $fim) {
        $diff = strtotime($fim) - strtotime($inicio);
        $horas = floor($diff / 3600);
        $minutos = floor(($diff % 3600) / 60);
        
        if ($horas > 0) {
            return "{$horas}h {$minutos}min";
        } else {
            return "{$minutos}min";
        }
    }
    
    /**
     * Obter relatório de conversão
     */
    public function obterRelatorioConversao($data_inicio, $data_fim, $espaco_id = null, $responsavel_id = null) {
        // Usar função SQL que já inclui eventos do Google Calendar (atualizada)
        $stmt = $this->pdo->prepare("
            SELECT * FROM calcular_conversao_visitas(?, ?, ?, ?)
        ");
        $stmt->execute([$data_inicio, $data_fim, $espaco_id, $responsavel_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Gerar token ICS
     */
    public function gerarTokenICS($usuario_id) {
        $stmt = $this->pdo->prepare("SELECT gerar_token_ics(?)");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchColumn();
    }
    
    /**
     * Obter eventos para ICS
     */
    public function obterEventosICS($usuario_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                ae.id,
                ae.titulo,
                ae.descricao,
                ae.inicio,
                ae.fim,
                ae.tipo,
                esp.nome as espaco_nome
            FROM agenda_eventos ae
            LEFT JOIN agenda_espacos esp ON ae.espaco_id = esp.id
            WHERE ae.responsavel_usuario_id = ?
            AND ae.status != 'cancelado'
            ORDER BY ae.inicio ASC
        ");
        $stmt->execute([$usuario_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Sugerir próximo horário livre
     */
    public function sugerirProximoHorario($responsavel_id, $espaco_id, $duracao_minutos = 60, $inicio_base = null) {
        $responsavel_id = (int)$responsavel_id;
        $espaco_id = $espaco_id ? (int)$espaco_id : null;
        $duracao_minutos = max(15, (int)$duracao_minutos);

        $agora = time();
        $base_ts = $inicio_base ? strtotime((string)$inicio_base) : $agora;
        if ($base_ts === false) {
            $base_ts = $agora;
        }
        if ($base_ts < $agora) {
            $base_ts = $agora;
        }

        $inicio_ts = $this->roundUpToStep($base_ts, 30 * 60);
        $limite_busca = $inicio_ts + (14 * 24 * 60 * 60);

        while ($inicio_ts < $limite_busca) {
            $fim_ts = $inicio_ts + ($duracao_minutos * 60);

            $where_espaco = '';
            $params = [
                ':responsavel_id' => $responsavel_id,
                ':inicio' => date('Y-m-d H:i:s', $inicio_ts),
                ':fim' => date('Y-m-d H:i:s', $fim_ts)
            ];
            if ($espaco_id !== null) {
                $where_espaco = "
                        OR (
                            ae.espaco_id = :espaco_id
                            AND ae.tipo = 'visita'
                        )
                ";
                $params[':espaco_id'] = $espaco_id;
            }

            $sql = "
                SELECT ae.id, ae.fim
                FROM agenda_eventos ae
                WHERE ae.status != 'cancelado'
                  AND (
                        ae.responsavel_usuario_id = :responsavel_id
                        {$where_espaco}
                  )
                  AND ae.inicio < :fim
                  AND ae.fim > :inicio
                ORDER BY ae.fim ASC
                LIMIT 1
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $conflito = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$conflito) {
                return [
                    'inicio' => date('Y-m-d H:i:s', $inicio_ts),
                    'fim' => date('Y-m-d H:i:s', $fim_ts)
                ];
            }

            $fim_conflito_ts = strtotime((string)$conflito['fim']);
            if ($fim_conflito_ts === false || $fim_conflito_ts <= $inicio_ts) {
                $inicio_ts += 30 * 60;
            } else {
                $inicio_ts = $this->roundUpToStep($fim_conflito_ts, 30 * 60);
            }
        }

        return null;
    }

    private function roundUpToStep(int $timestamp, int $step_seconds): int {
        $resto = $timestamp % $step_seconds;
        if ($resto === 0) {
            return $timestamp;
        }
        return $timestamp + ($step_seconds - $resto);
    }
}
?>
