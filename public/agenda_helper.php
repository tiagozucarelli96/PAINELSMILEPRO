<?php
// agenda_helper.php — Helper principal do sistema de agenda interna
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/email_helper.php';
require_once __DIR__ . '/core/notification_dispatcher.php';
require_once __DIR__ . '/core/google_calendar_helper.php';

class AgendaHelper {
    private const GOOGLE_TIMEZONE = 'America/Sao_Paulo';
    private const USER_COLOR_DEFAULTS = ['#1e40af', '#3b82f6', '#3b96f7', ''];
    private const USER_COLOR_PALETTE = [
        '#2563eb',
        '#16a34a',
        '#9333ea',
        '#f97316',
        '#0891b2',
        '#db2777',
        '#65a30d',
        '#7c3aed',
        '#0d9488',
        '#ea580c',
        '#4f46e5',
        '#be123c',
    ];
    private $pdo;
    private $emailHelper;
    private $notificationDispatcher;
    private $permissionValueCache = [];
    private $availabilityConfiguredCache = [];
    private $availabilityRulesCache = [];
    
    public function __construct() {
        $this->pdo = $GLOBALS['pdo'];
        // EmailHelper agora usa EmailGlobalHelper internamente (sistema_email_config)
        $this->emailHelper = new EmailHelper();
        $this->notificationDispatcher = new NotificationDispatcher($this->pdo);
        $this->notificationDispatcher->ensureInternalSchema();
        $this->ensureGoogleSyncSchema();
        $this->ensureVisitDetailsSchema();
        $this->ensureVisitWhatsappSchema();
        $this->ensureAgendaSettingsSchema();
        $this->ensureAvailabilitySchema();
    }

    public static function corUsuarioAgenda($usuario_id, ?string $corAtual): string {
        $cor = strtolower(trim((string)$corAtual));
        if (preg_match('/^#[0-9a-f]{6}$/', $cor) && !in_array($cor, self::USER_COLOR_DEFAULTS, true)) {
            return $cor;
        }

        $index = abs((int)$usuario_id) % count(self::USER_COLOR_PALETTE);
        return self::USER_COLOR_PALETTE[$index];
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

    private function ensureVisitDetailsSchema(): void {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS visita_tipo VARCHAR(50)");
            $this->pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS cliente_nome VARCHAR(255)");
            $this->pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS cliente_telefone VARCHAR(50)");
            $this->pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN IF NOT EXISTS visita_duracao_minutos INT");
        } catch (Throwable $e) {
            error_log('[AGENDA_VISITA_SCHEMA] Falha ao validar schema: ' . $e->getMessage());
        }
    }

    private function ensureVisitWhatsappSchema(): void {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS agenda_visita_whatsapp_notificacoes (
                    id BIGSERIAL PRIMARY KEY,
                    evento_id INT NOT NULL REFERENCES agenda_eventos(id) ON DELETE CASCADE,
                    tipo VARCHAR(40) NOT NULL,
                    agendada_para TIMESTAMP NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pendente',
                    tentativas INT NOT NULL DEFAULT 0,
                    enviado_em TIMESTAMP,
                    ultimo_erro TEXT,
                    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
                    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW(),
                    UNIQUE (evento_id, tipo)
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_agenda_visita_whatsapp_due ON agenda_visita_whatsapp_notificacoes(status, agendada_para)");
        } catch (Throwable $e) {
            error_log('[AGENDA_VISITA_WHATSAPP_SCHEMA] Falha ao validar schema: ' . $e->getMessage());
        }
    }

    private function normalizeVisitPhoneForWhatsapp(string $phone): string {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        return strlen($digits) >= 12 ? $digits : '';
    }

    private function buildVisitWhatsappMessage(array $evento): string {
        $inicio = strtotime((string)($evento['inicio'] ?? ''));
        $data = $inicio !== false ? date('d/m/Y', $inicio) : '';
        $hora = $inicio !== false ? date('H:i', $inicio) : '';
        $unidade = trim((string)($evento['espaco_nome'] ?? 'Smile Eventos'));
        $endereco = $this->getVisitUnitAddress($evento);

        return trim(
            "✅ *Visita agendada com sucesso!*\n\n" .
            "📍 *Buffet:* {$unidade}\n" .
            ($endereco !== '' ? "🗺️ *Endereço:* {$endereco}\n" : '') .
            "📅 *Data:* {$data}\n" .
            "⏰ *Horário:* {$hora}\n\n" .
            "No próprio dia, entraremos em contato para confirmar sua presença.\n\n" .
            "⚠️ É importante responder a essa confirmação para que a visita permaneça ativa em nossa agenda.\n\n" .
            "Obrigada! 💙"
        );
    }

    private function getVisitUnitAddress(array $evento): string {
        $slug = strtolower(trim((string)($evento['espaco_slug'] ?? '')));
        $nome = strtolower(trim((string)($evento['espaco_nome'] ?? '')));

        if ($slug === 'lisbon' || str_contains($nome, 'lisbon')) {
            return 'Av. Egídio Antônio Coimbra, 458 - Res. Parque dos Sinos, Jacareí - SP, 12328-513';
        }

        if ($slug === 'diverkids' || str_contains($nome, 'diver')) {
            return 'Av. Elmira Martins Moreira, 611 - Altos de Santana, Jacareí - SP, 12306-730';
        }

        if (in_array($slug, ['garden', 'cristal'], true) || str_contains($nome, 'garden') || str_contains($nome, 'cristal')) {
            return 'R. Padre Eugênio, 511 - Jardim Jacinto, Jacareí - SP, 12322-690';
        }

        return '';
    }

    private function buildVisitConfirmationWhatsappMessage(): string {
        return trim(
            "Olá! Tudo bem? 😊\n\n" .
            "📍 Estamos entrando em contato para *confirmar sua visita* ao nosso espaço.\n\n" .
            "Você poderia, por gentileza, confirmar sua presença?\n\n" .
            "⏰ Trabalhamos com tolerância de atraso de até *10 minutos*, garantindo a qualidade do atendimento e evitando conflitos de horário.\n\n" .
            "⚠️ Sem a confirmação, a visita poderá ser cancelada.\n" .
            "Pedimos que nos retorne o quanto antes.\n\n" .
            "Agradecemos sua atenção. 💙"
        );
    }

    private function agendarWhatsappConfirmacaoVisita(int $evento_id): void {
        if ($evento_id <= 0) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO agenda_visita_whatsapp_notificacoes (
                    evento_id, tipo, agendada_para, status, tentativas, ultimo_erro, atualizado_em
                )
                SELECT id, 'confirmacao_8h', (DATE(inicio) + TIME '08:00'), 'pendente', 0, NULL, NOW()
                FROM agenda_eventos
                WHERE id = :evento_id AND tipo = 'visita'
                ON CONFLICT (evento_id, tipo) DO UPDATE SET
                    agendada_para = EXCLUDED.agendada_para,
                    status = 'pendente',
                    ultimo_erro = NULL,
                    atualizado_em = NOW()
                WHERE agenda_visita_whatsapp_notificacoes.status <> 'enviada'
            ");
            $stmt->execute([':evento_id' => $evento_id]);
        } catch (Throwable $e) {
            error_log('[AGENDA_VISITA_WHATSAPP] Falha ao agendar confirmação da visita ' . $evento_id . ': ' . $e->getMessage());
        }
    }

    private function agendarWhatsappConfirmacoesVisitasDoDia(string $dataLocal, bool $dryRun = false): int {
        try {
            if ($dryRun) {
                $stmt = $this->pdo->prepare("
                    SELECT COUNT(*)
                    FROM agenda_eventos ae
                    WHERE ae.tipo = 'visita'
                      AND ae.status = 'agendado'
                      AND DATE(ae.inicio) = CAST(:data_local AS DATE)
                ");
                $stmt->execute([':data_local' => $dataLocal]);
                return (int)$stmt->fetchColumn();
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO agenda_visita_whatsapp_notificacoes (
                    evento_id, tipo, agendada_para, status, tentativas, ultimo_erro, atualizado_em
                )
                SELECT ae.id, 'confirmacao_8h', (DATE(ae.inicio) + TIME '08:00'), 'pendente', 0, NULL, NOW()
                FROM agenda_eventos ae
                WHERE ae.tipo = 'visita'
                  AND ae.status = 'agendado'
                  AND DATE(ae.inicio) = CAST(:data_local AS DATE)
                ON CONFLICT (evento_id, tipo) DO UPDATE SET
                    agendada_para = EXCLUDED.agendada_para,
                    status = 'pendente',
                    ultimo_erro = NULL,
                    atualizado_em = NOW()
                WHERE agenda_visita_whatsapp_notificacoes.status <> 'enviada'
            ");
            $stmt->execute([':data_local' => $dataLocal]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[AGENDA_VISITA_WHATSAPP] Falha ao agendar confirmações do dia ' . $dataLocal . ': ' . $e->getMessage());
            return 0;
        }
    }

    private function enviarWhatsappClienteVisita(int $evento_id): array {
        try {
            $stmt = $this->pdo->prepare("
                SELECT ae.id, ae.inicio, ae.cliente_nome, ae.cliente_telefone, esp.nome AS espaco_nome, esp.slug AS espaco_slug
                FROM agenda_eventos ae
                LEFT JOIN agenda_espacos esp ON esp.id = ae.espaco_id
                WHERE ae.id = ? AND ae.tipo = 'visita'
                LIMIT 1
            ");
            $stmt->execute([$evento_id]);
            $evento = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$evento) {
                return ['success' => false, 'error' => 'Visita não encontrada para notificação.'];
            }

            $telefone = $this->normalizeVisitPhoneForWhatsapp((string)($evento['cliente_telefone'] ?? ''));
            if ($telefone === '') {
                return ['success' => false, 'error' => 'Telefone da visita inválido para WhatsApp.'];
            }

            $mensagem = $this->buildVisitWhatsappMessage($evento);
            $ok = $this->notificationDispatcher->sendWhatsappDirect(
                $telefone,
                $mensagem,
                (string)($evento['cliente_nome'] ?? $telefone)
            );

            return ['success' => $ok, 'error' => $ok ? null : 'Falha ao enviar WhatsApp pela SMClick.'];
        } catch (Throwable $e) {
            error_log('[AGENDA_VISITA_WHATSAPP] Falha ao enviar WhatsApp da visita ' . $evento_id . ': ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function processarWhatsappConfirmacoesVisitas(int $limit = 100, bool $dryRun = false): array {
        $this->ensureVisitWhatsappSchema();
        $limit = max(1, min(500, $limit));
        $hojeLocal = (new DateTimeImmutable('now', new DateTimeZone(self::GOOGLE_TIMEZONE)))->format('Y-m-d');
        $agendadasHoje = $this->agendarWhatsappConfirmacoesVisitasDoDia($hojeLocal, $dryRun);
        $resultado = [
            'success' => true,
            'processadas' => 0,
            'enviadas' => 0,
            'falhas' => 0,
            'canceladas' => 0,
            'agendadas_hoje' => $agendadasHoje,
            'data_referencia' => $hojeLocal,
            'dry_run' => $dryRun,
        ];

        $stmt = $this->pdo->prepare("
            SELECT
                n.id AS notificacao_id,
                n.evento_id,
                ae.tipo,
                ae.status AS evento_status,
                ae.cliente_nome,
                ae.cliente_telefone,
                DATE(ae.inicio) AS data_visita
            FROM agenda_visita_whatsapp_notificacoes n
            JOIN agenda_eventos ae ON ae.id = n.evento_id
            WHERE n.tipo = 'confirmacao_8h'
              AND n.status IN ('pendente', 'erro')
              AND n.agendada_para <= (NOW() AT TIME ZONE 'America/Sao_Paulo')
            ORDER BY n.agendada_para ASC, n.id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pendentes as $item) {
            $resultado['processadas']++;
            $notificacaoId = (int)$item['notificacao_id'];

            if (($item['tipo'] ?? '') !== 'visita' || ($item['evento_status'] ?? '') !== 'agendado') {
                if (!$dryRun) {
                    $this->marcarWhatsappConfirmacao($notificacaoId, 'cancelada', null);
                }
                $resultado['canceladas']++;
                continue;
            }

            $dataVisita = (string)($item['data_visita'] ?? '');
            if ($dataVisita !== $hojeLocal) {
                if (!$dryRun) {
                    $erro = $dataVisita < $hojeLocal
                        ? 'Confirmação expirada: cron executado após a data da visita.'
                        : 'Confirmação ignorada: visita não é do dia atual.';
                    $this->marcarWhatsappConfirmacao($notificacaoId, 'cancelada', $erro);
                }
                $resultado['canceladas']++;
                continue;
            }

            $telefone = $this->normalizeVisitPhoneForWhatsapp((string)($item['cliente_telefone'] ?? ''));
            if ($telefone === '') {
                if (!$dryRun) {
                    $this->marcarWhatsappConfirmacao($notificacaoId, 'erro', 'Telefone inválido para WhatsApp.');
                }
                $resultado['falhas']++;
                continue;
            }

            if ($dryRun) {
                continue;
            }

            $ok = $this->notificationDispatcher->sendWhatsappDirect(
                $telefone,
                $this->buildVisitConfirmationWhatsappMessage(),
                (string)($item['cliente_nome'] ?? $telefone)
            );

            if ($ok) {
                $this->marcarWhatsappConfirmacao($notificacaoId, 'enviada', null);
                $resultado['enviadas']++;
            } else {
                $this->marcarWhatsappConfirmacao($notificacaoId, 'erro', 'Falha ao enviar WhatsApp pela SMClick.');
                $resultado['falhas']++;
            }
        }

        return $resultado;
    }

    private function marcarWhatsappConfirmacao(int $notificacaoId, string $status, ?string $erro): void {
        $stmt = $this->pdo->prepare("
            UPDATE agenda_visita_whatsapp_notificacoes
            SET status = :status,
                tentativas = CASE WHEN :status = 'erro' THEN tentativas + 1 ELSE tentativas END,
                enviado_em = CASE WHEN :status = 'enviada' THEN NOW() ELSE enviado_em END,
                ultimo_erro = :erro,
                atualizado_em = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $status,
            ':erro' => $erro,
            ':id' => $notificacaoId,
        ]);
    }

    private function ensureAgendaSettingsSchema(): void {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS agenda_configuracoes (
                    chave VARCHAR(100) PRIMARY KEY,
                    valor JSONB NOT NULL,
                    descricao TEXT,
                    atualizado_em TIMESTAMP DEFAULT NOW()
                )
            ");
        } catch (Throwable $e) {
            error_log('[AGENDA_CONFIG_SCHEMA] Falha ao validar schema: ' . $e->getMessage());
        }
    }

    private function ensureAvailabilitySchema(): void {
        if (!$this->pdo instanceof PDO) {
            return;
        }

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS agenda_disponibilidade (
                    id SERIAL PRIMARY KEY,
                    usuario_id INT NOT NULL REFERENCES usuarios(id) ON DELETE CASCADE,
                    tipo VARCHAR(20) NOT NULL DEFAULT 'disponivel',
                    recorrencia VARCHAR(20) NOT NULL DEFAULT 'semanal',
                    dia_semana SMALLINT,
                    data_especifica DATE,
                    hora_inicio TIME NOT NULL,
                    hora_fim TIME NOT NULL,
                    valido_de DATE NOT NULL,
                    valido_ate DATE,
                    observacao TEXT,
                    ativo BOOLEAN NOT NULL DEFAULT TRUE,
                    criado_por_usuario_id INT REFERENCES usuarios(id) ON DELETE SET NULL,
                    criado_em TIMESTAMP NOT NULL DEFAULT NOW(),
                    atualizado_em TIMESTAMP NOT NULL DEFAULT NOW(),
                    CONSTRAINT agenda_disponibilidade_tipo_chk CHECK (tipo IN ('disponivel', 'bloqueio')),
                    CONSTRAINT agenda_disponibilidade_recorrencia_chk CHECK (recorrencia IN ('semanal', 'data')),
                    CONSTRAINT agenda_disponibilidade_dia_chk CHECK (dia_semana IS NULL OR dia_semana BETWEEN 0 AND 6),
                    CONSTRAINT agenda_disponibilidade_periodo_chk CHECK (hora_fim > hora_inicio),
                    CONSTRAINT agenda_disponibilidade_validade_chk CHECK (valido_ate IS NULL OR valido_ate >= valido_de)
                )
            ");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_agenda_disp_usuario ON agenda_disponibilidade(usuario_id, ativo)");
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_agenda_disp_periodo ON agenda_disponibilidade(usuario_id, valido_de, valido_ate)");
        } catch (Throwable $e) {
            error_log('[AGENDA_DISPONIBILIDADE_SCHEMA] Falha ao validar schema: ' . $e->getMessage());
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

    private function canBeVisitResponsible($usuario_id): bool {
        $stmt = $this->pdo->prepare("
            SELECT LOWER(TRIM(COALESCE(login, ''))) AS login
            FROM usuarios
            WHERE id = ? AND ativo = TRUE
            LIMIT 1
        ");
        $stmt->execute([(int)$usuario_id]);
        $login = (string)$stmt->fetchColumn();

        return in_array($login, $this->getVisitResponsibleLogins(), true);
    }

    public function getAgendaGlobalSettings(): array {
        $defaults = [
            'visit_responsible_logins' => ['tay', 'marilia', 'tiago zucarelli', 'ays'],
            'visit_type_durations' => [
                'Conhecer espaço' => 30,
                'Reunião final' => 120,
                'Pagamento' => 30,
            ],
            'transit_min_minutes' => 30,
            'space_transit_groups' => [
                'garden' => 'garden_cristal',
                'cristal' => 'garden_cristal',
                'lisbon' => 'lisbon',
                'diverkids' => 'diverkids',
            ],
        ];

        try {
            $stmt = $this->pdo->query("SELECT chave, valor FROM agenda_configuracoes");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = (string)($row['chave'] ?? '');
                if (!array_key_exists($key, $defaults)) {
                    continue;
                }

                $value = json_decode((string)$row['valor'], true);
                if (json_last_error() === JSON_ERROR_NONE && $value !== null) {
                    $defaults[$key] = $value;
                }
            }
        } catch (Throwable $e) {
            error_log('[AGENDA_CONFIG] Falha ao carregar configurações: ' . $e->getMessage());
        }

        $defaults['visit_responsible_logins'] = array_values(array_unique(array_filter(array_map(
            static fn($login) => strtolower(trim((string)$login)),
            (array)$defaults['visit_responsible_logins']
        ))));

        foreach ($defaults['visit_type_durations'] as $type => $duration) {
            $defaults['visit_type_durations'][$type] = max(1, (int)$duration);
        }

        $defaults['transit_min_minutes'] = max(0, (int)$defaults['transit_min_minutes']);

        $normalizedGroups = [];
        foreach ((array)$defaults['space_transit_groups'] as $slug => $group) {
            $slug = strtolower(trim((string)$slug));
            $group = strtolower(trim((string)$group));
            if ($slug !== '' && $group !== '') {
                $normalizedGroups[$slug] = $group;
            }
        }
        $defaults['space_transit_groups'] = $normalizedGroups;

        return $defaults;
    }

    public function saveAgendaGlobalSettings(array $settings): void {
        $allowedKeys = [
            'visit_responsible_logins' => 'Logins permitidos como responsáveis de nova visita.',
            'visit_type_durations' => 'Duração em minutos por tipo de visita.',
            'transit_min_minutes' => 'Intervalo mínimo em minutos entre unidades diferentes.',
            'space_transit_groups' => 'Grupos de deslocamento por unidade/espaço.',
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO agenda_configuracoes (chave, valor, descricao, atualizado_em)
            VALUES (:chave, CAST(:valor AS jsonb), :descricao, NOW())
            ON CONFLICT (chave) DO UPDATE SET
                valor = EXCLUDED.valor,
                descricao = EXCLUDED.descricao,
                atualizado_em = NOW()
        ");

        foreach ($allowedKeys as $key => $description) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $stmt->execute([
                ':chave' => $key,
                ':valor' => json_encode($settings[$key], JSON_UNESCAPED_UNICODE),
                ':descricao' => $description,
            ]);
        }
    }

    public function getVisitResponsibleLogins(): array {
        return (array)$this->getAgendaGlobalSettings()['visit_responsible_logins'];
    }

    public function getVisitTypeDurations(): array {
        return (array)$this->getAgendaGlobalSettings()['visit_type_durations'];
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
            SELECT id, nome, login, cor_agenda, agenda_lembrete_padrao_min 
            FROM usuarios 
            WHERE ativo = TRUE 
            ORDER BY nome
        ");
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($usuarios as &$usuario) {
            $usuario['cor_agenda'] = self::corUsuarioAgenda($usuario['id'] ?? 0, (string)($usuario['cor_agenda'] ?? ''));
        }
        unset($usuario);
        return $usuarios;
    }
    
    /**
     * Verificar conflitos
     */
    public function verificarConflitos($responsavel_id, $espaco_id, $inicio, $fim, $evento_id = null) {
        $resultado = [
            'conflito_responsavel' => false,
            'conflito_espaco' => false,
            'conflito_transito' => false,
            'evento_conflito_id' => null,
            'evento_conflito_titulo' => null,
            'evento_conflito_inicio' => null,
            'evento_conflito_fim' => null,
            'evento_conflito_espaco' => null,
            'espaco_solicitado' => null,
            'tipo_conflito' => null,
            'mensagem_conflito' => null,
            'minutos_intervalo' => null,
        ];

        $espacoSolicitado = $this->obterEspacoAgenda($espaco_id);
        $resultado['espaco_solicitado'] = $espacoSolicitado['nome'] ?? null;

        $paramsBase = [
            ':inicio' => $inicio,
            ':fim' => $fim,
            ':evento_id' => $evento_id ? (int)$evento_id : 0,
        ];

        $stmt = $this->pdo->prepare("
            SELECT ae.id, ae.titulo, ae.inicio, ae.fim, ae.espaco_id, esp.nome AS espaco_nome, esp.slug AS espaco_slug
            FROM agenda_eventos ae
            LEFT JOIN agenda_espacos esp ON esp.id = ae.espaco_id
            WHERE ae.responsavel_usuario_id = :responsavel_id
              AND ae.status != 'cancelado'
              AND (:evento_id = 0 OR ae.id != :evento_id)
              AND ae.inicio < :fim
              AND ae.fim > :inicio
            ORDER BY ae.inicio ASC
            LIMIT 1
        ");
        $stmt->execute($paramsBase + [':responsavel_id' => (int)$responsavel_id]);
        $conflitoResponsavel = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($conflitoResponsavel) {
            $resultado['conflito_responsavel'] = true;
            $resultado['tipo_conflito'] = 'responsavel';
            $resultado['mensagem_conflito'] = 'Este responsável já possui compromisso neste mesmo horário.';
            return $this->preencherDadosConflito($resultado, $conflitoResponsavel);
        }

        if ($espaco_id) {
            $stmt = $this->pdo->prepare("
                SELECT ae.id, ae.titulo, ae.inicio, ae.fim, ae.espaco_id, esp.nome AS espaco_nome, esp.slug AS espaco_slug
                FROM agenda_eventos ae
                LEFT JOIN agenda_espacos esp ON esp.id = ae.espaco_id
                WHERE ae.espaco_id = :espaco_id
                  AND ae.tipo = 'visita'
                  AND ae.status != 'cancelado'
                  AND (:evento_id = 0 OR ae.id != :evento_id)
                  AND ae.inicio < :fim
                  AND ae.fim > :inicio
                ORDER BY ae.inicio ASC
                LIMIT 1
            ");
            $stmt->execute($paramsBase + [':espaco_id' => (int)$espaco_id]);
            $conflitoEspaco = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($conflitoEspaco) {
                $resultado['conflito_espaco'] = true;
                $resultado['tipo_conflito'] = 'espaco';
                $resultado['mensagem_conflito'] = 'Este espaço já possui visita neste mesmo horário.';
                return $this->preencherDadosConflito($resultado, $conflitoEspaco);
            }
        }

        $conflitoTransito = $this->verificarConflitoTransito(
            (int)$responsavel_id,
            $espacoSolicitado,
            $inicio,
            $fim,
            $evento_id ? (int)$evento_id : null
        );

        if ($conflitoTransito) {
            return $this->preencherDadosConflito($resultado, $conflitoTransito);
        }

        return $resultado;
    }

    private function obterEspacoAgenda($espaco_id): ?array {
        if (!$espaco_id) {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT id, nome, slug FROM agenda_espacos WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$espaco_id]);
        $espaco = $stmt->fetch(PDO::FETCH_ASSOC);
        return $espaco ?: null;
    }

    private function grupoDeslocamentoEspaco(?array $espaco): ?string {
        if (!$espaco || empty($espaco['slug'])) {
            return null;
        }

        $slug = strtolower((string)$espaco['slug']);
        $groups = (array)$this->getAgendaGlobalSettings()['space_transit_groups'];
        return $groups[$slug] ?? $slug;
    }

    private function preencherDadosConflito(array $resultado, array $evento): array {
        $resultado['evento_conflito_id'] = isset($evento['id']) ? (int)$evento['id'] : null;
        $resultado['evento_conflito_titulo'] = $evento['titulo'] ?? null;
        $resultado['evento_conflito_inicio'] = $evento['inicio'] ?? null;
        $resultado['evento_conflito_fim'] = $evento['fim'] ?? null;
        $resultado['evento_conflito_espaco'] = $evento['espaco_nome'] ?? null;

        if (isset($evento['tipo_conflito'])) {
            $resultado['tipo_conflito'] = $evento['tipo_conflito'];
        }
        if (isset($evento['mensagem_conflito'])) {
            $resultado['mensagem_conflito'] = $evento['mensagem_conflito'];
        }
        if (isset($evento['minutos_intervalo'])) {
            $resultado['minutos_intervalo'] = (int)$evento['minutos_intervalo'];
        }
        if (isset($evento['conflito_transito'])) {
            $resultado['conflito_transito'] = (bool)$evento['conflito_transito'];
        }

        return $resultado;
    }

    private function verificarConflitoTransito(int $responsavel_id, ?array $espacoSolicitado, $inicio, $fim, ?int $evento_id = null): ?array {
        $grupoSolicitado = $this->grupoDeslocamentoEspaco($espacoSolicitado);
        if (!$grupoSolicitado) {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT ae.id, ae.titulo, ae.inicio, ae.fim, ae.espaco_id, esp.nome AS espaco_nome, esp.slug AS espaco_slug
            FROM agenda_eventos ae
            LEFT JOIN agenda_espacos esp ON esp.id = ae.espaco_id
            WHERE ae.responsavel_usuario_id = :responsavel_id
              AND ae.status != 'cancelado'
              AND ae.espaco_id IS NOT NULL
              AND (:evento_id = 0 OR ae.id != :evento_id)
                  AND (
                      (ae.fim <= CAST(:inicio AS timestamp) AND ae.fim > (CAST(:inicio AS timestamp) - (CAST(:transit_minutes AS int) * INTERVAL '1 minute')))
                      OR
                      (ae.inicio >= CAST(:fim AS timestamp) AND ae.inicio < (CAST(:fim AS timestamp) + (CAST(:transit_minutes AS int) * INTERVAL '1 minute')))
                  )
            ORDER BY ABS(EXTRACT(EPOCH FROM (ae.inicio - CAST(:inicio AS timestamp)))) ASC
            LIMIT 5
        ");
        $stmt->execute([
            ':responsavel_id' => $responsavel_id,
            ':evento_id' => $evento_id ?: 0,
            ':inicio' => $inicio,
            ':fim' => $fim,
            ':transit_minutes' => (int)$this->getAgendaGlobalSettings()['transit_min_minutes'],
        ]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $evento) {
            $grupoEvento = $this->grupoDeslocamentoEspaco([
                'slug' => $evento['espaco_slug'] ?? null,
            ]);

            if (!$grupoEvento || $grupoEvento === $grupoSolicitado) {
                continue;
            }

            $fimEvento = strtotime((string)$evento['fim']);
            $inicioEvento = strtotime((string)$evento['inicio']);
            $inicioSolicitado = strtotime((string)$inicio);
            $fimSolicitado = strtotime((string)$fim);
            $intervalo = 0;

            if ($fimEvento <= $inicioSolicitado) {
                $intervalo = (int)floor(($inicioSolicitado - $fimEvento) / 60);
            } elseif ($inicioEvento >= $fimSolicitado) {
                $intervalo = (int)floor(($inicioEvento - $fimSolicitado) / 60);
            }

            $evento['conflito_transito'] = true;
            $evento['tipo_conflito'] = 'transito';
            $evento['minutos_intervalo'] = $intervalo;
            $minutosTransito = (int)$this->getAgendaGlobalSettings()['transit_min_minutes'];
            $evento['mensagem_conflito'] = sprintf(
                'Este responsável tem apenas %d minuto(s) entre %s e %s. Entre unidades diferentes é necessário intervalo mínimo de %d minutos.',
                $intervalo,
                (string)($evento['espaco_nome'] ?? 'outra unidade'),
                (string)($espacoSolicitado['nome'] ?? 'esta unidade'),
                $minutosTransito
            );

            return $evento;
        }

        return null;
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
            if (($dados['tipo'] ?? '') === 'visita' && !$this->canBeVisitResponsible($dados['responsavel_usuario_id'] ?? 0)) {
                return [
                    'success' => false,
                    'error' => 'Selecione um responsável válido para nova visita.'
                ];
            }
            if (($dados['tipo'] ?? '') === 'visita') {
                $visita_tipo = trim((string)($dados['visita_tipo'] ?? ''));
                $cliente_nome = trim((string)($dados['cliente_nome'] ?? ''));
                $cliente_telefone = trim((string)($dados['cliente_telefone'] ?? ''));
                $visita_duracao = (int)($dados['visita_duracao_minutos'] ?? 0);

                $duracao_por_tipo = $this->getVisitTypeDurations();
                if (!array_key_exists($visita_tipo, $duracao_por_tipo)) {
                    return [
                        'success' => false,
                        'error' => 'Selecione um tipo de visita válido.'
                    ];
                }
                if ($cliente_nome === '' || $cliente_telefone === '') {
                    return [
                        'success' => false,
                        'error' => 'Informe nome e telefone do cliente.'
                    ];
                }
                $cliente_telefone_normalizado = $this->normalizeVisitPhoneForWhatsapp($cliente_telefone);
                if ($cliente_telefone_normalizado === '') {
                    return [
                        'success' => false,
                        'error' => 'Informe o telefone do cliente com DDD. Exemplo: 12999999999.'
                    ];
                }
                if ($visita_duracao !== $duracao_por_tipo[$visita_tipo]) {
                    return [
                        'success' => false,
                        'error' => 'A duração não confere com o tipo de visita selecionado.'
                    ];
                }
            }
            $erroDisponibilidade = $this->validarDisponibilidadeVisita($dados, $inicio_ts, $fim_ts);
            if ($erroDisponibilidade !== null) {
                return $erroDisponibilidade;
            }

            // Verificar conflitos se não forçar
            if (!$dados['forcar_conflito']) {
                $conflito = $this->verificarConflitos(
                    $dados['responsavel_usuario_id'],
                    $dados['espaco_id'],
                    $dados['inicio'],
                    $dados['fim']
                );
                
                if ($conflito['conflito_responsavel'] || $conflito['conflito_espaco'] || $conflito['conflito_transito']) {
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
            $cor_responsavel = self::corUsuarioAgenda($dados['responsavel_usuario_id'], (string)$stmt->fetchColumn());
            
            // Criar evento - valores padrão: checkboxes desmarcados
            $stmt = $this->pdo->prepare("
                INSERT INTO agenda_eventos (
                    tipo, titulo, descricao, inicio, fim, responsavel_usuario_id, 
                    criado_por_usuario_id, espaco_id, lembrete_minutos, 
                    compareceu, fechou_contrato, participantes, cor_evento,
                    visita_tipo, cliente_nome, cliente_telefone, visita_duracao_minutos
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '1', '0', ?, ?, ?, ?, ?, ?)
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
                $cor_responsavel,
                ($dados['tipo'] ?? '') === 'visita' ? trim((string)($dados['visita_tipo'] ?? '')) : null,
                ($dados['tipo'] ?? '') === 'visita' ? trim((string)($dados['cliente_nome'] ?? '')) : null,
                ($dados['tipo'] ?? '') === 'visita' ? $this->normalizeVisitPhoneForWhatsapp((string)($dados['cliente_telefone'] ?? '')) : null,
                ($dados['tipo'] ?? '') === 'visita' ? (int)($dados['visita_duracao_minutos'] ?? 0) : null
            ]);
            
            $evento_id = $this->pdo->lastInsertId();
            
            // Enviar notificação de criação
            $this->enviarNotificacaoEvento($evento_id, 'criacao');
            $this->syncEventoToGoogle($evento_id);
            $whatsappCliente = ($dados['tipo'] ?? '') === 'visita'
                ? $this->enviarWhatsappClienteVisita((int)$evento_id)
                : ['success' => false, 'error' => null];
            if (($dados['tipo'] ?? '') === 'visita') {
                $this->agendarWhatsappConfirmacaoVisita((int)$evento_id);
            }
            
            return [
                'success' => true,
                'evento_id' => $evento_id,
                'whatsapp_cliente_enviado' => !empty($whatsappCliente['success']),
                'whatsapp_cliente_erro' => $whatsappCliente['error'] ?? null
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
            $erroDisponibilidade = $this->validarDisponibilidadeVisita($dados, $inicio_ts, $fim_ts);
            if ($erroDisponibilidade !== null) {
                return $erroDisponibilidade;
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
                
                if ($conflito['conflito_responsavel'] || $conflito['conflito_espaco'] || $conflito['conflito_transito']) {
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
            if (($dados['tipo'] ?? '') === 'visita') {
                $this->agendarWhatsappConfirmacaoVisita((int)$evento_id);
            }
            
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
            if (!is_scalar($evento_id) || !preg_match('/^\d+$/', (string)$evento_id)) {
                return [
                    'success' => false,
                    'error' => 'Evento do Google Calendar não pode ser excluído pela Agenda interna.'
                ];
            }

            $evento_id = (int)$evento_id;
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
                u.login as responsavel_login,
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
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($eventos as &$evento) {
            if (!empty($evento['responsavel_usuario_id'])) {
                $evento['cor_agenda'] = self::corUsuarioAgenda($evento['responsavel_usuario_id'], (string)($evento['cor_agenda'] ?? ''));
            }
        }
        unset($evento);
        return $eventos;
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

    public function obterDisponibilidades(?int $usuario_id = null): array {
        $where = '';
        $params = [];
        if ($usuario_id && $usuario_id > 0) {
            $where = 'WHERE ad.usuario_id = :usuario_id';
            $params[':usuario_id'] = $usuario_id;
        }

        $stmt = $this->pdo->prepare("
            SELECT ad.*, u.nome AS usuario_nome, u.login AS usuario_login
            FROM agenda_disponibilidade ad
            JOIN usuarios u ON u.id = ad.usuario_id
            {$where}
            ORDER BY u.nome ASC, ad.ativo DESC, ad.valido_de DESC, ad.dia_semana ASC, ad.hora_inicio ASC
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function salvarDisponibilidade(array $dados): array {
        try {
            $usuario_id = (int)($dados['usuario_id'] ?? 0);
            $tipo = (string)($dados['tipo'] ?? 'disponivel');
            $recorrencia = (string)($dados['recorrencia'] ?? 'semanal');
            $dia_semana = isset($dados['dia_semana']) && $dados['dia_semana'] !== '' ? (int)$dados['dia_semana'] : null;
            $data_especifica = trim((string)($dados['data_especifica'] ?? ''));
            $hora_inicio = trim((string)($dados['hora_inicio'] ?? ''));
            $hora_fim = trim((string)($dados['hora_fim'] ?? ''));
            $valido_de = trim((string)($dados['valido_de'] ?? ''));
            $valido_ate = trim((string)($dados['valido_ate'] ?? ''));
            $observacao = trim((string)($dados['observacao'] ?? ''));
            $ativo = !empty($dados['ativo']);
            $criado_por = (int)($dados['criado_por_usuario_id'] ?? 0);

            if ($usuario_id <= 0) {
                return ['success' => false, 'error' => 'Selecione um responsável.'];
            }
            if (!in_array($tipo, ['disponivel', 'bloqueio'], true)) {
                return ['success' => false, 'error' => 'Tipo de regra inválido.'];
            }
            if (!in_array($recorrencia, ['semanal', 'data'], true)) {
                return ['success' => false, 'error' => 'Recorrência inválida.'];
            }
            if ($recorrencia === 'semanal' && ($dia_semana === null || $dia_semana < 0 || $dia_semana > 6)) {
                return ['success' => false, 'error' => 'Selecione o dia da semana.'];
            }
            if ($recorrencia === 'data' && !$this->isValidDate($data_especifica)) {
                return ['success' => false, 'error' => 'Informe a data específica.'];
            }
            if (!$this->isValidDate($valido_de)) {
                return ['success' => false, 'error' => 'Informe a data inicial de validade.'];
            }
            if ($valido_ate !== '' && !$this->isValidDate($valido_ate)) {
                return ['success' => false, 'error' => 'Informe uma data final válida.'];
            }
            if (!$this->isValidTime($hora_inicio) || !$this->isValidTime($hora_fim) || $hora_fim <= $hora_inicio) {
                return ['success' => false, 'error' => 'Informe um intervalo de horário válido.'];
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO agenda_disponibilidade (
                    usuario_id, tipo, recorrencia, dia_semana, data_especifica,
                    hora_inicio, hora_fim, valido_de, valido_ate, observacao,
                    ativo, criado_por_usuario_id, atualizado_em
                ) VALUES (
                    :usuario_id, :tipo, :recorrencia, :dia_semana, :data_especifica,
                    :hora_inicio, :hora_fim, :valido_de, :valido_ate, :observacao,
                    :ativo, :criado_por_usuario_id, NOW()
                )
            ");
            $stmt->execute([
                ':usuario_id' => $usuario_id,
                ':tipo' => $tipo,
                ':recorrencia' => $recorrencia,
                ':dia_semana' => $recorrencia === 'semanal' ? $dia_semana : null,
                ':data_especifica' => $recorrencia === 'data' ? $data_especifica : null,
                ':hora_inicio' => $hora_inicio,
                ':hora_fim' => $hora_fim,
                ':valido_de' => $valido_de,
                ':valido_ate' => $valido_ate !== '' ? $valido_ate : null,
                ':observacao' => $observacao,
                ':ativo' => $ativo,
                ':criado_por_usuario_id' => $criado_por > 0 ? $criado_por : null,
            ]);

            $this->availabilityConfiguredCache = [];
            $this->availabilityRulesCache = [];

            return ['success' => true, 'id' => (int)$this->pdo->lastInsertId()];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function excluirDisponibilidade(int $id): array {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM agenda_disponibilidade WHERE id = ?");
            $stmt->execute([$id]);
            $this->availabilityConfiguredCache = [];
            $this->availabilityRulesCache = [];
            return ['success' => true];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function responsavelTemDisponibilidadeConfigurada(int $usuario_id): bool {
        $cacheKey = 'any|' . $usuario_id;
        if (array_key_exists($cacheKey, $this->availabilityConfiguredCache)) {
            return $this->availabilityConfiguredCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM agenda_disponibilidade
            WHERE usuario_id = ?
              AND ativo = TRUE
            LIMIT 1
        ");
        $stmt->execute([$usuario_id]);
        $this->availabilityConfiguredCache[$cacheKey] = (bool)$stmt->fetchColumn();
        return $this->availabilityConfiguredCache[$cacheKey];
    }

    private function responsavelTemJanelaDisponivelConfigurada(int $usuario_id): bool {
        $cacheKey = 'disponivel|' . $usuario_id;
        if (array_key_exists($cacheKey, $this->availabilityConfiguredCache)) {
            return $this->availabilityConfiguredCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM agenda_disponibilidade
            WHERE usuario_id = ?
              AND ativo = TRUE
              AND tipo = 'disponivel'
            LIMIT 1
        ");
        $stmt->execute([$usuario_id]);
        $this->availabilityConfiguredCache[$cacheKey] = (bool)$stmt->fetchColumn();
        return $this->availabilityConfiguredCache[$cacheKey];
    }

    private function obterRegrasDisponibilidadeData(int $usuario_id, string $data): array {
        $cacheKey = $usuario_id . '|' . $data;
        if (array_key_exists($cacheKey, $this->availabilityRulesCache)) {
            return $this->availabilityRulesCache[$cacheKey];
        }

        $dow = (int)date('w', strtotime($data));
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM agenda_disponibilidade
            WHERE usuario_id = :usuario_id
              AND ativo = TRUE
              AND valido_de <= :data
              AND (valido_ate IS NULL OR valido_ate >= :data)
              AND (
                    (recorrencia = 'semanal' AND dia_semana = :dia_semana)
                    OR
                    (recorrencia = 'data' AND data_especifica = :data)
              )
            ORDER BY tipo ASC, hora_inicio ASC
        ");
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':data' => $data,
            ':dia_semana' => $dow,
        ]);
        $this->availabilityRulesCache[$cacheKey] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->availabilityRulesCache[$cacheKey];
    }

    private function estaDentroDaDisponibilidade(int $usuario_id, int $inicio_ts, int $fim_ts): bool {
        if (!$this->responsavelTemDisponibilidadeConfigurada($usuario_id)) {
            return true;
        }

        $data = date('Y-m-d', $inicio_ts);
        if ($data !== date('Y-m-d', $fim_ts)) {
            return false;
        }

        $regras = $this->obterRegrasDisponibilidadeData($usuario_id, $data);
        foreach ($regras as $regra) {
            if (($regra['tipo'] ?? '') !== 'bloqueio') {
                continue;
            }
            $bloqueioInicio = strtotime($data . ' ' . $regra['hora_inicio']);
            $bloqueioFim = strtotime($data . ' ' . $regra['hora_fim']);
            if ($bloqueioInicio !== false && $bloqueioFim !== false && $inicio_ts < $bloqueioFim && $fim_ts > $bloqueioInicio) {
                return false;
            }
        }

        $disponiveis = array_filter($regras, static fn($regra) => ($regra['tipo'] ?? '') === 'disponivel');
        if (!$disponiveis) {
            return !$this->responsavelTemJanelaDisponivelConfigurada($usuario_id);
        }

        $dentroDisponivel = false;
        foreach ($disponiveis as $regra) {
            $janelaInicio = strtotime($data . ' ' . $regra['hora_inicio']);
            $janelaFim = strtotime($data . ' ' . $regra['hora_fim']);
            if ($janelaInicio !== false && $janelaFim !== false && $inicio_ts >= $janelaInicio && $fim_ts <= $janelaFim) {
                $dentroDisponivel = true;
                break;
            }
        }
        if (!$dentroDisponivel) {
            return false;
        }

        return true;
    }

    private function validarDisponibilidadeVisita(array $dados, int $inicio_ts, int $fim_ts): ?array {
        if (($dados['tipo'] ?? '') !== 'visita') {
            return null;
        }

        $responsavel_id = (int)($dados['responsavel_usuario_id'] ?? 0);
        if ($responsavel_id <= 0 || $this->estaDentroDaDisponibilidade($responsavel_id, $inicio_ts, $fim_ts)) {
            return null;
        }

        return [
            'success' => false,
            'error' => 'Responsável indisponível ou bloqueado no horário selecionado. Ajuste o horário da visita ou a regra em Disponibilidade.'
        ];
    }

    private function isValidDate(string $date): bool {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parts = array_map('intval', explode('-', $date));
        return checkdate($parts[1], $parts[2], $parts[0]);
    }

    private function isValidTime(string $time): bool {
        return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
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
     * Sugerir opções de horário para visita com base nas possibilidades do cliente.
     */
    public function sugerirHorariosVisita(array $filtros): array {
        $responsavel_id = (int)($filtros['responsavel_id'] ?? 0);
        $espaco_id = (int)($filtros['espaco_id'] ?? 0);
        $duracao_minutos = max(15, (int)($filtros['duracao'] ?? 60));
        $data_inicio = trim((string)($filtros['data_inicio'] ?? ''));
        $data_fim = trim((string)($filtros['data_fim'] ?? ''));
        $hora_inicio = trim((string)($filtros['hora_inicio'] ?? ''));
        $hora_fim = trim((string)($filtros['hora_fim'] ?? ''));
        $dias_semana = array_values(array_unique(array_map('intval', (array)($filtros['dias_semana'] ?? []))));
        $dias_semana = array_values(array_filter($dias_semana, static fn($dia) => $dia >= 0 && $dia <= 6));
        $limite = min(30, max(1, (int)($filtros['limite'] ?? 12)));

        if ($responsavel_id <= 0) {
            return ['success' => false, 'error' => 'Selecione um responsável.'];
        }
        if ($espaco_id <= 0) {
            return ['success' => false, 'error' => 'Selecione uma unidade.'];
        }
        if (!$this->isValidDate($data_inicio) || !$this->isValidDate($data_fim) || $data_fim < $data_inicio) {
            return ['success' => false, 'error' => 'Informe um período de busca válido.'];
        }
        if (!$this->isValidTime($hora_inicio) || !$this->isValidTime($hora_fim) || $hora_fim <= $hora_inicio) {
            return ['success' => false, 'error' => 'Informe uma janela de horário válida.'];
        }
        if (!$dias_semana) {
            return ['success' => false, 'error' => 'Selecione pelo menos um dia da semana.'];
        }

        $inicioPeriodo = new DateTimeImmutable($data_inicio);
        $fimPeriodo = new DateTimeImmutable($data_fim);
        if ($fimPeriodo->getTimestamp() - $inicioPeriodo->getTimestamp() > 90 * 24 * 60 * 60) {
            return ['success' => false, 'error' => 'Busque no máximo 90 dias por vez.'];
        }

        $opcoes = [];
        $diaAtual = $inicioPeriodo;
        $agora = time();

        while ($diaAtual <= $fimPeriodo && count($opcoes) < $limite) {
            $data = $diaAtual->format('Y-m-d');
            $dow = (int)$diaAtual->format('w');

            if (in_array($dow, $dias_semana, true)) {
                $slotInicio = strtotime($data . ' ' . $hora_inicio);
                $janelaFim = strtotime($data . ' ' . $hora_fim);

                while ($slotInicio !== false && $janelaFim !== false && ($slotInicio + ($duracao_minutos * 60)) <= $janelaFim && count($opcoes) < $limite) {
                    if ($slotInicio < $agora) {
                        $slotInicio += 30 * 60;
                        continue;
                    }

                    $slotFim = $slotInicio + ($duracao_minutos * 60);
                    $inicioFormatado = date('Y-m-d H:i:s', $slotInicio);
                    $fimFormatado = date('Y-m-d H:i:s', $slotFim);

                    if (!$this->estaDentroDaDisponibilidade($responsavel_id, $slotInicio, $slotFim)) {
                        $slotInicio += 30 * 60;
                        continue;
                    }

                    $conflitos = $this->verificarConflitos($responsavel_id, $espaco_id, $inicioFormatado, $fimFormatado);
                    if ($conflitos['conflito_responsavel'] || $conflitos['conflito_espaco'] || $conflitos['conflito_transito']) {
                        $slotInicio += 30 * 60;
                        continue;
                    }

                    $opcoes[] = [
                        'inicio' => $inicioFormatado,
                        'fim' => $fimFormatado,
                        'data_label' => date('d/m/Y', $slotInicio),
                        'hora_label' => date('H:i', $slotInicio) . ' - ' . date('H:i', $slotFim),
                        'dia_semana' => $this->nomeDiaSemana((int)date('w', $slotInicio)),
                    ];

                    $slotInicio += 30 * 60;
                }
            }

            $diaAtual = $diaAtual->modify('+1 day');
        }

        return [
            'success' => true,
            'opcoes' => $opcoes,
            'message' => $opcoes ? null : 'Nenhum horário livre encontrado com esses critérios.',
        ];
    }

    private function nomeDiaSemana(int $dia): string {
        $dias = [
            0 => 'Domingo',
            1 => 'Segunda',
            2 => 'Terça',
            3 => 'Quarta',
            4 => 'Quinta',
            5 => 'Sexta',
            6 => 'Sábado',
        ];
        return $dias[$dia] ?? '';
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
        $limite_busca = $inicio_ts + (45 * 24 * 60 * 60);

        while ($inicio_ts < $limite_busca) {
            $fim_ts = $inicio_ts + ($duracao_minutos * 60);
            $inicio_formatado = date('Y-m-d H:i:s', $inicio_ts);
            $fim_formatado = date('Y-m-d H:i:s', $fim_ts);

            if (!$this->estaDentroDaDisponibilidade($responsavel_id, $inicio_ts, $fim_ts)) {
                $inicio_ts += 30 * 60;
                continue;
            }

            $where_espaco = '';
            $params = [
                ':responsavel_id' => $responsavel_id,
                ':inicio' => $inicio_formatado,
                ':fim' => $fim_formatado
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
                $conflitosAgenda = $this->verificarConflitos($responsavel_id, $espaco_id, $inicio_formatado, $fim_formatado);
                if ($conflitosAgenda['conflito_responsavel'] || $conflitosAgenda['conflito_espaco'] || $conflitosAgenda['conflito_transito']) {
                    $fim_conflito = strtotime((string)($conflitosAgenda['evento_conflito_fim'] ?? ''));
                    $inicio_ts = $fim_conflito && $fim_conflito > $inicio_ts
                        ? $this->roundUpToStep($fim_conflito, 30 * 60)
                        : $inicio_ts + (30 * 60);
                    continue;
                }

                return [
                    'inicio' => $inicio_formatado,
                    'fim' => $fim_formatado
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
