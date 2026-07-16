<?php
declare(strict_types=1);

require_once __DIR__ . '/config_env.php';

final class PixGoHelper
{
    private string $apiKey;
    private string $baseUrl;
    private string $webhookUrl;
    private bool $enabled;

    public function __construct()
    {
        $this->apiKey = trim((string)PIXGO_API_KEY);
        $this->baseUrl = rtrim((string)PIXGO_BASE_URL, '/');
        $this->webhookUrl = trim((string)PIXGO_WEBHOOK_URL);
        $this->enabled = (bool)PIXGO_ENABLED;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->apiKey !== '';
    }

    public function createPayment(array $data): array
    {
        $this->assertConfigured();

        $amount = round((float)($data['amount'] ?? 0), 2);
        if ($amount < 10) {
            throw new InvalidArgumentException('A PixGo exige valor mínimo de R$ 10,00 por cobrança.');
        }

        $cpfCnpj = preg_replace('/\D+/', '', (string)($data['receiver_cpf'] ?? ''));
        if (!in_array(strlen($cpfCnpj), [11, 14], true)) {
            throw new InvalidArgumentException('CPF/CNPJ do pagador é obrigatório para criar uma cobrança PixGo.');
        }

        $payload = [
            'amount' => $amount,
            'description' => mb_substr(trim((string)($data['description'] ?? 'Cobrança Smile Eventos')), 0, 200, 'UTF-8'),
            'external_id' => mb_substr(trim((string)($data['external_id'] ?? '')), 0, 50, 'UTF-8'),
            'receiver_name' => mb_substr(trim((string)($data['receiver_name'] ?? '')), 0, 100, 'UTF-8'),
            'receiver_cpf' => $cpfCnpj,
        ];
        if (mb_strlen($payload['receiver_name'], 'UTF-8') < 2) {
            throw new InvalidArgumentException('Nome completo do pagador é obrigatório para criar uma cobrança PixGo.');
        }

        $optional = [
            'receiver_email' => 255,
            'receiver_phone' => 11,
            'receiver_address' => 500,
        ];
        foreach ($optional as $field => $limit) {
            $value = trim((string)($data[$field] ?? ''));
            if ($field === 'receiver_phone') {
                $value = preg_replace('/\D+/', '', $value);
            }
            if ($field === 'receiver_email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $value = '';
            }
            if ($field === 'receiver_phone' && !in_array(strlen($value), [10, 11], true)) {
                $value = '';
            }
            if ($field === 'receiver_address' && mb_strlen($value, 'UTF-8') < 10) {
                $value = '';
            }
            if ($value !== '') {
                $payload[$field] = mb_substr($value, 0, $limit, 'UTF-8');
            }
        }
        $webhookUrl = trim((string)($data['webhook_url'] ?? $this->webhookUrl));
        if ($webhookUrl !== '') {
            $payload['webhook_url'] = $webhookUrl;
        }

        $idempotencyKey = trim((string)($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('A chave de idempotência da cobrança PixGo é obrigatória.');
        }

        return $this->request('POST', '/payment/create', $payload, $idempotencyKey);
    }

    public function getPayment(string $paymentId): array
    {
        $this->assertConfigured();
        return $this->request('GET', '/payment/' . rawurlencode(trim($paymentId)));
    }

    public function getPaymentStatus(string $paymentId): array
    {
        $this->assertConfigured();
        return $this->request('GET', '/payment/' . rawurlencode(trim($paymentId)) . '/status');
    }

    public static function validateWebhookSignature(string $rawBody, string $timestamp, string $signature): bool
    {
        $webhookSecret = trim((string)PIXGO_WEBHOOK_SECRET);
        $timestamp = trim($timestamp);
        $signature = strtolower(trim($signature));
        if ($webhookSecret === '' || $timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }

        $age = abs(time() - (int)$timestamp);
        if ($age > (int)PIXGO_WEBHOOK_MAX_AGE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $webhookSecret);
        return hash_equals($expected, $signature);
    }

    public static function normalizeTimestampForDatabase(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/i', $value)) {
            return $value;
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('America/Sao_Paulo')))->format(DateTimeInterface::ATOM);
        } catch (Throwable $e) {
            return $value;
        }
    }

    private function assertConfigured(): void
    {
        if (!$this->enabled) {
            throw new RuntimeException('A integração PixGo está desativada. Defina PIXGO_ENABLED=true no Railway.');
        }
        if ($this->apiKey === '') {
            throw new RuntimeException('PIXGO_API_KEY não configurada no Railway.');
        }
    }

    private function request(string $method, string $path, ?array $payload = null, string $idempotencyKey = ''): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: PainelSmilePRO/1.0',
            'X-API-Key: ' . $this->apiKey,
        ];
        if ($idempotencyKey !== '') {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($payload !== null) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        }

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            throw new RuntimeException('Falha de comunicação com a PixGo: ' . $curlError);
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Resposta inválida da PixGo (HTTP {$status}).");
        }
        if ($status < 200 || $status >= 300 || ($decoded['success'] ?? true) === false) {
            $error = $decoded['error'] ?? null;
            $message = is_array($error)
                ? (string)($error['message'] ?? $decoded['message'] ?? 'Erro não informado')
                : (string)($decoded['message'] ?? $error ?? 'Erro não informado');
            $requestId = is_array($error) ? trim((string)($error['request_id'] ?? '')) : '';
            throw new RuntimeException("PixGo HTTP {$status}: {$message}" . ($requestId !== '' ? " (request_id: {$requestId})" : ''));
        }

        $data = $decoded['data'] ?? $decoded;
        if (!is_array($data)) {
            throw new RuntimeException('A PixGo retornou uma resposta sem dados de pagamento.');
        }
        return $data;
    }
}
