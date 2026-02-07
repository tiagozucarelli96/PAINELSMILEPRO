<?php
/**
 * Integração simples com a API Clicksign v3 (JSON:API).
 */

if (!class_exists('ClicksignHelper')) {
    class ClicksignHelper
    {
        private string $baseUrl;
        private string $apiToken;
        private int $timeout;

        public function __construct()
        {
            if (file_exists(__DIR__ . '/../config_env.php')) {
                require_once __DIR__ . '/../config_env.php';
            }

            $token = $_ENV['CLICKSIGN_API_TOKEN'] ?? getenv('CLICKSIGN_API_TOKEN') ?: '';
            $base = $_ENV['CLICKSIGN_BASE_URL'] ?? getenv('CLICKSIGN_BASE_URL') ?: 'https://sandbox.clicksign.com/api/v3';
            $timeout = (int)($_ENV['CLICKSIGN_TIMEOUT'] ?? getenv('CLICKSIGN_TIMEOUT') ?: 30);

            $this->apiToken = trim((string)$token);
            $this->baseUrl = rtrim(trim((string)$base), '/');
            $this->timeout = max(10, $timeout);
        }

        public function isConfigured(): bool
        {
            return $this->apiToken !== '';
        }

        public function getConfigurationError(): string
        {
            if ($this->apiToken === '') {
                return 'CLICKSIGN_API_TOKEN não configurado.';
            }

            return 'Configuração da Clicksign incompleta.';
        }

        public function criarFluxoAssinatura(array $input): array
        {
            if (!$this->isConfigured()) {
                return [
                    'success' => false,
                    'error' => $this->getConfigurationError(),
                ];
            }

            $envelopeName = trim((string)($input['envelope_name'] ?? 'Documento para assinatura'));
            $filename = trim((string)($input['filename'] ?? 'documento.pdf'));
            $contentBase64 = trim((string)($input['content_base64'] ?? ''));
            $signerName = trim((string)($input['signer_name'] ?? ''));
            $signerEmail = trim((string)($input['signer_email'] ?? ''));
            $deadlineAt = trim((string)($input['deadline_at'] ?? ''));
            $message = trim((string)($input['notification_message'] ?? 'Você possui um documento pendente para assinatura.'));

            if ($contentBase64 === '' || $signerName === '' || $signerEmail === '') {
                return [
                    'success' => false,
                    'error' => 'Dados obrigatórios para assinatura não informados (arquivo/signatário).',
                ];
            }

            $envelopeAttributes = [
                'name' => $envelopeName,
                'locale' => 'pt-BR',
                'auto_close' => true,
                'remind_interval' => '3',
            ];
            if ($deadlineAt !== '') {
                $envelopeAttributes['deadline_at'] = $deadlineAt;
            }

            $resEnvelope = $this->request('POST', '/envelopes', [
                'data' => [
                    'type' => 'envelopes',
                    'attributes' => $envelopeAttributes,
                ],
            ]);
            if (!$resEnvelope['success']) {
                return $resEnvelope;
            }

            $envelopeId = $this->extractResourceId($resEnvelope['data']);
            if ($envelopeId === '') {
                return [
                    'success' => false,
                    'error' => 'Clicksign não retornou o ID do envelope.',
                    'response' => $resEnvelope['data'] ?? null,
                ];
            }

            $resDocument = $this->request('POST', '/envelopes/' . rawurlencode($envelopeId) . '/documents', [
                'data' => [
                    'type' => 'documents',
                    'attributes' => [
                        'filename' => $filename,
                        'content_base64' => $contentBase64,
                    ],
                ],
            ]);
            if (!$resDocument['success']) {
                return $resDocument;
            }

            $documentId = $this->extractResourceId($resDocument['data']);
            if ($documentId === '') {
                return [
                    'success' => false,
                    'error' => 'Clicksign não retornou o ID do documento.',
                    'response' => $resDocument['data'] ?? null,
                ];
            }

            $resSigner = $this->request('POST', '/envelopes/' . rawurlencode($envelopeId) . '/signers', [
                'data' => [
                    'type' => 'signers',
                    'attributes' => [
                        'name' => $signerName,
                        'email' => $signerEmail,
                        'has_documentation' => false,
                        'refusable' => false,
                        'group' => 1,
                        'communicate_events' => [
                            'signature_request' => 'email',
                            'signature_reminder' => 'email',
                            'document_signed' => 'email',
                        ],
                    ],
                ],
            ]);
            if (!$resSigner['success']) {
                return $resSigner;
            }

            $signerId = $this->extractResourceId($resSigner['data']);
            if ($signerId === '') {
                return [
                    'success' => false,
                    'error' => 'Clicksign não retornou o ID do signatário.',
                    'response' => $resSigner['data'] ?? null,
                ];
            }

            $resRequirement = $this->request('POST', '/envelopes/' . rawurlencode($envelopeId) . '/requirements', [
                'data' => [
                    'type' => 'requirements',
                    'attributes' => [
                        'action' => 'agree',
                        'role' => 'sign',
                    ],
                    'relationships' => [
                        'document' => [
                            'data' => [
                                'type' => 'documents',
                                'id' => $documentId,
                            ],
                        ],
                        'signer' => [
                            'data' => [
                                'type' => 'signers',
                                'id' => $signerId,
                            ],
                        ],
                    ],
                ],
            ]);
            if (!$resRequirement['success']) {
                return $resRequirement;
            }

            $resActivate = $this->request('PATCH', '/envelopes/' . rawurlencode($envelopeId), [
                'data' => [
                    'id' => $envelopeId,
                    'type' => 'envelopes',
                    'attributes' => [
                        'status' => 'running',
                    ],
                ],
            ]);
            if (!$resActivate['success']) {
                return $resActivate;
            }

            $resNotify = $this->request(
                'POST',
                '/envelopes/' . rawurlencode($envelopeId) . '/signers/' . rawurlencode($signerId) . '/notifications',
                [
                    'data' => [
                        'type' => 'notifications',
                        'attributes' => [
                            'message' => $message,
                        ],
                    ],
                ]
            );

            if (!$resNotify['success']) {
                // Notificação falhar não invalida o fluxo criado.
                $notifyError = $resNotify['error'] ?? 'Falha ao notificar signatário.';
            } else {
                $notifyError = null;
            }

            return [
                'success' => true,
                'envelope_id' => $envelopeId,
                'document_id' => $documentId,
                'signer_id' => $signerId,
                'status' => 'running',
                'notify_error' => $notifyError,
                'raw' => [
                    'envelope' => $resEnvelope['data'] ?? null,
                    'document' => $resDocument['data'] ?? null,
                    'signer' => $resSigner['data'] ?? null,
                    'requirement' => $resRequirement['data'] ?? null,
                    'activate' => $resActivate['data'] ?? null,
                    'notify' => $resNotify['data'] ?? null,
                ],
            ];
        }

        public function atualizarStatusEnvelope(string $envelopeId): array
        {
            $envelopeId = trim($envelopeId);
            if ($envelopeId === '') {
                return [
                    'success' => false,
                    'error' => 'Envelope inválido para consulta.',
                ];
            }

            $res = $this->request('GET', '/envelopes/' . rawurlencode($envelopeId));
            if (!$res['success']) {
                return $res;
            }

            $status = $res['data']['data']['attributes']['status'] ?? '';
            return [
                'success' => true,
                'status_clicksign' => $status,
                'status_local' => self::mapStatusClicksignParaLocal((string)$status),
                'data' => $res['data'] ?? null,
            ];
        }

        public function reenviarNotificacao(string $envelopeId, string $signerId, string $message = ''): array
        {
            if (!$this->isConfigured()) {
                return [
                    'success' => false,
                    'error' => $this->getConfigurationError(),
                ];
            }

            if ($message === '') {
                $message = 'Você possui um documento pendente para assinatura.';
            }

            return $this->request(
                'POST',
                '/envelopes/' . rawurlencode($envelopeId) . '/signers/' . rawurlencode($signerId) . '/notifications',
                [
                    'data' => [
                        'type' => 'notifications',
                        'attributes' => [
                            'message' => $message,
                        ],
                    ],
                ]
            );
        }

        public static function mapStatusClicksignParaLocal(string $status): string
        {
            $status = strtolower(trim($status));
            if ($status === 'closed') {
                return 'assinado';
            }
            if ($status === 'canceled') {
                return 'cancelado';
            }
            if ($status === 'running') {
                return 'enviado';
            }
            if ($status === 'draft') {
                return 'pendente_envio';
            }

            return 'enviado';
        }

        private function request(string $method, string $path, ?array $payload = null): array
        {
            $path = '/' . ltrim($path, '/');
            $url = $this->baseUrl . $path;

            $headers = [
                'Authorization: ' . $this->normalizedAuthorization(),
                'Content-Type: application/vnd.api+json',
                'Accept: application/vnd.api+json',
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            if ($payload !== null) {
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            }

            $body = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                return [
                    'success' => false,
                    'status' => $httpCode,
                    'error' => 'Falha de comunicação com Clicksign: ' . $curlErr,
                ];
            }

            $decoded = json_decode($body, true);

            if ($httpCode >= 200 && $httpCode < 300) {
                return [
                    'success' => true,
                    'status' => $httpCode,
                    'data' => $decoded,
                ];
            }

            $error = 'Erro ao comunicar com Clicksign.';
            if (is_array($decoded) && !empty($decoded['errors'][0])) {
                $err = $decoded['errors'][0];
                $error = (string)($err['detail'] ?? $err['title'] ?? $error);
            } elseif (is_string($body) && trim($body) !== '') {
                $error = trim($body);
            }

            return [
                'success' => false,
                'status' => $httpCode,
                'error' => $error,
                'response' => $decoded,
            ];
        }

        private function normalizedAuthorization(): string
        {
            if (stripos($this->apiToken, 'Bearer ') === 0) {
                return $this->apiToken;
            }

            return 'Bearer ' . $this->apiToken;
        }

        private function extractResourceId(?array $response): string
        {
            if (!is_array($response)) {
                return '';
            }

            $id = $response['data']['id'] ?? '';
            return is_string($id) ? trim($id) : '';
        }
    }
}
