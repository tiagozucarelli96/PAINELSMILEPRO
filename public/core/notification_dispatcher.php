<?php
// notification_dispatcher.php — Dispatcher único de notificações (interna + push + e-mail)
require_once __DIR__ . '/../conexao.php';

class NotificationDispatcher {
    private $pdo;
    private $schemaEnsured = false;
    private $internalColumns = null;
    private $pushHelper = null;
    private $pushHelperReady = false;
    private $emailHelper = null;
    private $emailHelperReady = false;

    public function __construct($pdo = null) {
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
        } else {
            $this->pdo = $GLOBALS['pdo'] ?? null;
        }
    }

    public function ensureInternalSchema() {
        if ($this->schemaEnsured || !($this->pdo instanceof PDO)) {
            return;
        }

        $this->safeExec("
            CREATE TABLE IF NOT EXISTS demandas_notificacoes (
                id BIGSERIAL PRIMARY KEY,
                usuario_id BIGINT REFERENCES usuarios(id) ON DELETE CASCADE,
                tipo VARCHAR(50) NOT NULL,
                referencia_id INT,
                titulo VARCHAR(180),
                mensagem TEXT NOT NULL,
                url_destino TEXT,
                lida BOOLEAN DEFAULT FALSE,
                criada_em TIMESTAMPTZ DEFAULT NOW()
            )
        ");
        $this->safeExec("ALTER TABLE demandas_notificacoes ADD COLUMN IF NOT EXISTS referencia_id INT");
        $this->safeExec("ALTER TABLE demandas_notificacoes ADD COLUMN IF NOT EXISTS titulo VARCHAR(180)");
        $this->safeExec("ALTER TABLE demandas_notificacoes ADD COLUMN IF NOT EXISTS url_destino TEXT");
        $this->safeExec("CREATE INDEX IF NOT EXISTS idx_demandas_notificacoes_usuario ON demandas_notificacoes(usuario_id, lida)");
        $this->safeExec("CREATE INDEX IF NOT EXISTS idx_demandas_notificacoes_criada_em_desc ON demandas_notificacoes(criada_em DESC)");

        $this->schemaEnsured = true;
        $this->internalColumns = null;
    }

    public function dispatch(array $recipients, array $payload = [], array $channels = []): array {
        $normalizedRecipients = $this->normalizeRecipients($recipients);

        $result = [
            'total_destinatarios' => count($normalizedRecipients),
            'enviados_interno' => 0,
            'enviados_push' => 0,
            'enviados_email' => 0,
            'falhas_interno' => 0,
            'falhas_push' => 0,
            'falhas_email' => 0,
            'emails_sem_endereco' => 0,
        ];

        if (empty($normalizedRecipients)) {
            return $result;
        }

        $sendInternal = !empty($channels['internal']);
        $sendPush = !empty($channels['push']);
        $sendEmail = !empty($channels['email']);
        if (!$sendInternal && !$sendPush && !$sendEmail) {
            return $result;
        }

        $tipo = trim((string)($payload['tipo'] ?? 'sistema'));
        if ($tipo === '') {
            $tipo = 'sistema';
        }

        $titulo = trim((string)($payload['titulo'] ?? 'Notificação'));
        if ($titulo === '') {
            $titulo = 'Notificação';
        }

        $mensagem = trim((string)($payload['mensagem'] ?? ''));
        if ($mensagem === '') {
            $mensagem = $titulo;
        }

        $urlDestino = trim((string)($payload['url_destino'] ?? ''));
        $referenciaId = isset($payload['referencia_id']) ? (int)$payload['referencia_id'] : 0;
        if ($referenciaId <= 0) {
            $referenciaId = null;
        }

        $pushTitulo = trim((string)($payload['push_titulo'] ?? $titulo));
        $pushMensagem = trim((string)($payload['push_mensagem'] ?? $mensagem));
        $pushData = is_array($payload['push_data'] ?? null) ? $payload['push_data'] : [];
        if (empty($pushData['url'])) {
            $pushData['url'] = $urlDestino !== '' ? $urlDestino : 'index.php?page=dashboard';
        }
        if (empty($pushData['tipo'])) {
            $pushData['tipo'] = $tipo;
        }
        if (!isset($pushData['referencia_id']) && $referenciaId !== null) {
            $pushData['referencia_id'] = $referenciaId;
        }

        $emailAssunto = trim((string)($payload['email_assunto'] ?? $titulo));
        if ($emailAssunto === '') {
            $emailAssunto = $titulo;
        }
        $emailHtml = (string)($payload['email_html'] ?? $this->buildDefaultEmailBody($titulo, $mensagem, $urlDestino));

        if ($sendEmail) {
            $this->hydrateRecipientEmails($normalizedRecipients);
        }

        $pushHelper = $sendPush ? $this->getPushHelper() : null;
        $emailHelper = $sendEmail ? $this->getEmailHelper() : null;

        foreach ($normalizedRecipients as $recipient) {
            $userId = (int)$recipient['id'];

            if ($sendInternal) {
                $okInternal = $this->insertInternalNotification($userId, $tipo, $titulo, $mensagem, $urlDestino, $referenciaId);
                if ($okInternal) {
                    $result['enviados_interno']++;
                } else {
                    $result['falhas_interno']++;
                }
            }

            if ($sendPush) {
                if ($pushHelper) {
                    $retPush = $pushHelper->enviarPush($userId, $pushTitulo, $pushMensagem, $pushData);
                    if (!empty($retPush['success'])) {
                        $result['enviados_push']++;
                    } else {
                        $result['falhas_push']++;
                    }
                } else {
                    $result['falhas_push']++;
                }
            }

            if ($sendEmail) {
                $email = trim((string)($recipient['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $result['emails_sem_endereco']++;
                    $result['falhas_email']++;
                } elseif ($emailHelper) {
                    $okEmail = $emailHelper->enviarEmail($email, $emailAssunto, $emailHtml, true);
                    if ($okEmail) {
                        $result['enviados_email']++;
                    } else {
                        $result['falhas_email']++;
                    }
                } else {
                    $result['falhas_email']++;
                }
            }
        }

        return $result;
    }

    private function normalizeRecipients(array $recipients): array {
        $buffer = [];
        foreach ($recipients as $recipient) {
            if (is_array($recipient)) {
                $id = (int)($recipient['id'] ?? $recipient['usuario_id'] ?? 0);
                $email = trim((string)($recipient['email'] ?? ''));
            } else {
                $id = (int)$recipient;
                $email = '';
            }

            if ($id <= 0) {
                continue;
            }

            $buffer[$id] = [
                'id' => $id,
                'email' => $email,
            ];
        }

        return array_values($buffer);
    }

    private function hydrateRecipientEmails(array &$recipients): void {
        if (!($this->pdo instanceof PDO) || empty($recipients)) {
            return;
        }

        $missingIds = [];
        foreach ($recipients as $recipient) {
            $email = trim((string)($recipient['email'] ?? ''));
            if ($email === '') {
                $missingIds[] = (int)$recipient['id'];
            }
        }

        $missingIds = array_values(array_unique(array_filter($missingIds)));
        if (empty($missingIds)) {
            return;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
            $stmt = $this->pdo->prepare("SELECT id, email FROM usuarios WHERE id IN ({$placeholders})");
            $stmt->execute($missingIds);
            $emailMap = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $emailMap[(int)$row['id']] = trim((string)($row['email'] ?? ''));
            }

            foreach ($recipients as &$recipient) {
                if (trim((string)($recipient['email'] ?? '')) !== '') {
                    continue;
                }
                $id = (int)$recipient['id'];
                if (!empty($emailMap[$id])) {
                    $recipient['email'] = $emailMap[$id];
                }
            }
            unset($recipient);
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Falha ao buscar e-mails de destinatários: " . $e->getMessage());
        }
    }

    private function insertInternalNotification(int $usuarioId, string $tipo, string $titulo, string $mensagem, string $urlDestino, ?int $referenciaId): bool {
        if (!($this->pdo instanceof PDO) || $usuarioId <= 0) {
            return false;
        }

        $this->ensureInternalSchema();
        $columns = $this->getInternalColumns();
        if (empty($columns)) {
            return false;
        }

        $fields = ['usuario_id', 'tipo', 'mensagem'];
        $values = [':usuario_id', ':tipo', ':mensagem'];
        $params = [
            ':usuario_id' => $usuarioId,
            ':tipo' => $tipo,
            ':mensagem' => $mensagem,
        ];

        if (!empty($columns['referencia_id'])) {
            $fields[] = 'referencia_id';
            $values[] = ':referencia_id';
            $params[':referencia_id'] = $referenciaId;
        }

        if (!empty($columns['titulo'])) {
            $fields[] = 'titulo';
            $values[] = ':titulo';
            $params[':titulo'] = $titulo;
        }

        if (!empty($columns['url_destino'])) {
            $fields[] = 'url_destino';
            $values[] = ':url_destino';
            $params[':url_destino'] = $urlDestino !== '' ? $urlDestino : null;
        }

        $sql = "INSERT INTO demandas_notificacoes (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return true;
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Falha ao inserir notificação interna: " . $e->getMessage());
            return false;
        }
    }

    private function getInternalColumns(): array {
        if ($this->internalColumns !== null) {
            return $this->internalColumns;
        }

        $this->internalColumns = [];
        if (!($this->pdo instanceof PDO)) {
            return $this->internalColumns;
        }

        try {
            $stmt = $this->pdo->query("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_name = 'demandas_notificacoes'
            ");
            foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $column) {
                $column = (string)$column;
                if ($column !== '') {
                    $this->internalColumns[$column] = true;
                }
            }
        } catch (Throwable $e) {
            $this->internalColumns = [];
        }

        return $this->internalColumns;
    }

    private function getPushHelper() {
        if ($this->pushHelperReady) {
            return $this->pushHelper;
        }

        $this->pushHelperReady = true;
        try {
            require_once __DIR__ . '/push_helper.php';
            $this->pushHelper = new PushHelper();
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Push indisponível: " . $e->getMessage());
            $this->pushHelper = null;
        }

        return $this->pushHelper;
    }

    private function getEmailHelper() {
        if ($this->emailHelperReady) {
            return $this->emailHelper;
        }

        $this->emailHelperReady = true;
        try {
            require_once __DIR__ . '/email_global_helper.php';
            $this->emailHelper = new EmailGlobalHelper();
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] E-mail indisponível: " . $e->getMessage());
            $this->emailHelper = null;
        }

        return $this->emailHelper;
    }

    private function safeExec($sql): void {
        if (!($this->pdo instanceof PDO)) {
            return;
        }

        try {
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            error_log("[NOTIFICATION_DISPATCHER] Falha ao executar SQL de schema: " . $e->getMessage());
        }
    }

    private function buildDefaultEmailBody(string $titulo, string $mensagem, string $urlDestino): string {
        $tituloHtml = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
        $mensagemHtml = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));
        $urlHtml = htmlspecialchars($urlDestino, ENT_QUOTES, 'UTF-8');

        $cta = '';
        if ($urlDestino !== '') {
            $cta = "
                <p style='margin-top:16px;'>
                    <a href='{$urlHtml}' style='display:inline-block;background:#1e3a8a;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;'>
                        Abrir no Painel
                    </a>
                </p>
            ";
        }

        return "
            <div style='font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;max-width:700px;margin:0 auto;'>
                <div style='background:#1e3a8a;color:#fff;padding:16px 18px;border-radius:10px 10px 0 0;'>
                    <h2 style='margin:0;font-size:20px;'>{$tituloHtml}</h2>
                </div>
                <div style='border:1px solid #dbe3ef;border-top:none;padding:16px 18px;border-radius:0 0 10px 10px;background:#fff;'>
                    <div style='font-size:15px;color:#334155;'>{$mensagemHtml}</div>
                    {$cta}
                </div>
            </div>
        ";
    }
}
