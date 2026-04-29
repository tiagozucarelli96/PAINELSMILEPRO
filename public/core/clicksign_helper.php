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

            $token = $_ENV['CLICKSIGN_API_TOKEN']
                ?? getenv('CLICKSIGN_API_TOKEN')
                ?: (defined('CLICKSIGN_API_TOKEN') ? CLICKSIGN_API_TOKEN : '');
            $base = $_ENV['CLICKSIGN_BASE_URL']
                ?? getenv('CLICKSIGN_BASE_URL')
                ?: (defined('CLICKSIGN_BASE_URL') ? CLICKSIGN_BASE_URL : 'https://app.clicksign.com/api/v3');
            $timeout = (int)($_ENV['CLICKSIGN_TIMEOUT']
                ?? getenv('CLICKSIGN_TIMEOUT')
                ?: (defined('CLICKSIGN_TIMEOUT') ? CLICKSIGN_TIMEOUT : 30));

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
            $contentType = trim((string)($input['content_type'] ?? 'application/octet-stream'));
            $deadlineAt = trim((string)($input['deadline_at'] ?? ''));
            if ($contentBase64 !== '' && stripos($contentBase64, 'data:') !== 0) {
                $contentBase64 = 'data:' . $contentType . ';base64,' . $contentBase64;
            }

            $signers = $this->normalizeSignersInput($input);
            if ($contentBase64 === '' || empty($signers)) {
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

            $createdSigners = [];
            $createdRequirements = [];
            foreach ($signers as $signer) {
                $resSigner = $this->request('POST', '/envelopes/' . rawurlencode($envelopeId) . '/signers', [
                    'data' => [
                        'type' => 'signers',
                        'attributes' => [
                            'name' => $signer['name'],
                            'email' => $signer['email'],
                            'group' => $signer['group'],
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
                            'role' => $signer['role'],
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

                $resAuthRequirement = $this->request('POST', '/envelopes/' . rawurlencode($envelopeId) . '/requirements', [
                    'data' => [
                        'type' => 'requirements',
                        'attributes' => [
                            'action' => 'provide_evidence',
                            'auth' => $signer['auth'],
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
                if (!$resAuthRequirement['success']) {
                    return $resAuthRequirement;
                }

                $createdSigners[] = [
                    'id' => $signerId,
                    'name' => $signer['name'],
                    'email' => $signer['email'],
                    'group' => $signer['group'],
                    'role' => $signer['role'],
                    'auth' => $signer['auth'],
                    'signer' => $resSigner['data'] ?? null,
                ];
                $createdRequirements[] = [
                    'signer_id' => $signerId,
                    'agree' => $resRequirement['data'] ?? null,
                    'auth' => $resAuthRequirement['data'] ?? null,
                ];
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
                '/envelopes/' . rawurlencode($envelopeId) . '/notifications',
                [
                    'data' => [
                        'type' => 'notifications',
                        'attributes' => new stdClass(),
                    ],
                ]
            );

            if (!$resNotify['success']) {
                // Notificação falhar não invalida o fluxo criado.
                $notifyError = $resNotify['error'] ?? 'Falha ao notificar signatário.';
            } else {
                $notifyError = null;
            }

            $resSummary = $this->consultarFluxoAssinatura($envelopeId, $documentId);
            $summary = $resSummary['success'] ?? false ? $resSummary : null;
            $primarySigner = $createdSigners[0] ?? null;

            return [
                'success' => true,
                'envelope_id' => $envelopeId,
                'document_id' => $documentId,
                'signer_id' => $primarySigner['id'] ?? null,
                'signers' => $createdSigners,
                'document_links' => $summary['document_links'] ?? [],
                'signed_file_url' => $summary['document_links']['signed'] ?? null,
                'original_file_url' => $summary['document_links']['original'] ?? null,
                'zip_file_url' => $summary['document_links']['ziped'] ?? null,
                'sign_url' => $summary['signers'][0]['signature_url'] ?? null,
                'status' => 'running',
                'notify_error' => $notifyError,
                'raw' => [
                    'envelope' => $resEnvelope['data'] ?? null,
                    'document' => $resDocument['data'] ?? null,
                    'signers' => $createdSigners,
                    'requirements' => $createdRequirements,
                    'activate' => $resActivate['data'] ?? null,
                    'notify' => $resNotify['data'] ?? null,
                    'summary' => $summary,
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

            return $this->request(
                'POST',
                '/envelopes/' . rawurlencode($envelopeId) . '/notifications',
                [
                    'data' => [
                        'type' => 'notifications',
                        'attributes' => new stdClass(),
                    ],
                ]
            );
        }

        public function consultarFluxoAssinatura(string $envelopeId, ?string $documentId = null): array
        {
            $envelopeId = trim($envelopeId);
            $documentId = trim((string)$documentId);
            if ($envelopeId === '') {
                return [
                    'success' => false,
                    'error' => 'Envelope inválido para consulta.',
                ];
            }

            $resEnvelope = $this->request('GET', '/envelopes/' . rawurlencode($envelopeId));
            if (!$resEnvelope['success']) {
                return $resEnvelope;
            }

            $resDocuments = $this->request('GET', '/envelopes/' . rawurlencode($envelopeId) . '/documents');
            if (!$resDocuments['success']) {
                return $resDocuments;
            }

            $resSigners = $this->request('GET', '/envelopes/' . rawurlencode($envelopeId) . '/signers');
            if (!$resSigners['success']) {
                return $resSigners;
            }

            $documents = $resDocuments['data']['data'] ?? [];
            $primaryDocument = $this->pickPrimaryDocument($documents, $documentId);
            $documentLinks = $primaryDocument['links']['files'] ?? [];
            $documentIdResolved = (string)($primaryDocument['id'] ?? $documentId);

            $resEvents = ['success' => true, 'data' => ['data' => []]];
            if ($documentIdResolved !== '') {
                $resEvents = $this->request('GET', '/envelopes/' . rawurlencode($envelopeId) . '/documents/' . rawurlencode($documentIdResolved) . '/events');
                if (!$resEvents['success']) {
                    return $resEvents;
                }
            }

            $signers = $this->summarizeSigners(
                $resSigners['data']['data'] ?? [],
                $resEvents['data']['data'] ?? []
            );

            $envelopeStatus = (string)($resEnvelope['data']['data']['attributes']['status'] ?? '');
            $statusLocal = $this->deriveLocalStatus($envelopeStatus, $signers);
            return [
                'success' => true,
                'status_clicksign' => $envelopeStatus,
                'status_local' => $statusLocal,
                'document_links' => $documentLinks,
                'documents' => $documents,
                'signers' => $signers,
                'events' => $this->normalizeEvents($resEvents['data']['data'] ?? []),
                'data' => [
                    'envelope' => $resEnvelope['data'] ?? null,
                    'documents' => $resDocuments['data'] ?? null,
                    'signers' => $resSigners['data'] ?? null,
                    'events' => $resEvents['data'] ?? null,
                ],
            ];
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

        private function deriveLocalStatus(string $envelopeStatus, array $signers): string
        {
            $fallback = self::mapStatusClicksignParaLocal($envelopeStatus);
            if ($fallback === 'assinado' || $fallback === 'cancelado' || empty($signers)) {
                return $fallback;
            }

            $total = count($signers);
            $signed = 0;
            $started = 0;
            $refused = 0;

            foreach ($signers as $signer) {
                $status = (string)($signer['status'] ?? '');
                if ($status === 'assinado') {
                    $signed++;
                } elseif ($status === 'em_andamento') {
                    $started++;
                } elseif ($status === 'recusado') {
                    $refused++;
                }
            }

            if ($refused > 0) {
                return 'cancelado';
            }
            if ($signed === $total && $total > 0) {
                return 'assinado';
            }
            if ($signed > 0 || $started > 0) {
                return 'em_andamento';
            }

            return $fallback;
        }

        public static function normalizeSignerName(string $name): string
        {
            $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
            return $name;
        }

        public static function getSignerNameValidationError(string $name): ?string
        {
            $name = self::normalizeSignerName($name);
            if ($name === '') {
                return 'O nome do signatário não foi informado.';
            }

            if (preg_match('/\p{N}/u', $name)) {
                return 'O nome do signatário precisa ser o nome completo e não pode conter números.';
            }

            $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 2) {
                return 'O nome do signatário precisa ter pelo menos nome e sobrenome para a Clicksign.';
            }

            return null;
        }

        private function normalizeSignersInput(array $input): array
        {
            $signers = [];
            if (!empty($input['signers']) && is_array($input['signers'])) {
                $candidates = $input['signers'];
            } else {
                $candidates = [[
                    'name' => $input['signer_name'] ?? '',
                    'email' => $input['signer_email'] ?? '',
                    'group' => $input['signer_group'] ?? 1,
                    'role' => $input['signer_role'] ?? 'sign',
                    'auth' => $input['signer_auth'] ?? 'email',
                ]];
            }

            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $name = self::normalizeSignerName((string)($candidate['name'] ?? ''));
                $email = trim((string)($candidate['email'] ?? ''));
                if ($name === '' && $email === '') {
                    continue;
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Informe um e-mail válido para todos os signatários.');
                }

                $nameError = self::getSignerNameValidationError($name);
                if ($nameError !== null) {
                    throw new InvalidArgumentException($nameError);
                }

                $group = max(1, (int)($candidate['group'] ?? 1));
                $role = trim((string)($candidate['role'] ?? 'sign'));
                if (!in_array($role, ['sign', 'agree', 'intervene', 'approve', 'receipt', 'witness', 'party'], true)) {
                    $role = 'sign';
                }

                $auth = trim((string)($candidate['auth'] ?? 'email'));
                if (!in_array($auth, ['email', 'sms', 'whatsapp', 'handwritten', 'selfie', 'official_document', 'liveness', 'facial_biometrics', 'icp_brasil', 'pix'], true)) {
                    $auth = 'email';
                }

                $signers[] = [
                    'name' => $name,
                    'email' => $email,
                    'group' => $group,
                    'role' => $role,
                    'auth' => $auth,
                ];
            }

            return $signers;
        }

        private function pickPrimaryDocument(array $documents, string $documentId): array
        {
            foreach ($documents as $document) {
                if ((string)($document['id'] ?? '') === $documentId && $documentId !== '') {
                    return $document;
                }
            }

            return $documents[0] ?? [];
        }

        private function summarizeSigners(array $signers, array $events): array
        {
            $result = [];
            foreach ($signers as $signer) {
                $signerId = (string)($signer['id'] ?? '');
                if ($signerId === '') {
                    continue;
                }

                $result[$signerId] = [
                    'id' => $signerId,
                    'name' => (string)($signer['attributes']['name'] ?? ''),
                    'email' => (string)($signer['attributes']['email'] ?? ''),
                    'group' => (int)($signer['attributes']['group'] ?? 1),
                    'status' => 'aguardando',
                    'status_label' => 'Aguardando assinatura',
                    'signature_url' => null,
                    'last_event_at' => null,
                ];
            }

            $orderedEvents = $events;
            usort($orderedEvents, static function (array $a, array $b): int {
                return strcmp(
                    (string)($a['attributes']['created'] ?? ''),
                    (string)($b['attributes']['created'] ?? '')
                );
            });

            foreach ($orderedEvents as $event) {
                $eventName = (string)($event['attributes']['name'] ?? '');
                $eventCreated = (string)($event['attributes']['created'] ?? '');
                $eventSigner = $event['attributes']['data']['signer'] ?? null;
                $eventSignerId = (string)($eventSigner['key'] ?? '');
                if ($eventSignerId === '' || !isset($result[$eventSignerId])) {
                    if ($eventName === 'add_signer' && !empty($event['attributes']['data']['signers'])) {
                        foreach ((array)$event['attributes']['data']['signers'] as $addedSigner) {
                            $addedSignerId = (string)($addedSigner['key'] ?? '');
                            if ($addedSignerId !== '' && isset($result[$addedSignerId])) {
                                $result[$addedSignerId]['signature_url'] = $addedSigner['url'] ?? $result[$addedSignerId]['signature_url'];
                                $result[$addedSignerId]['last_event_at'] = $eventCreated;
                            }
                        }
                    }
                    continue;
                }

                $result[$eventSignerId]['last_event_at'] = $eventCreated;
                if (!empty($eventSigner['url'])) {
                    $result[$eventSignerId]['signature_url'] = (string)$eventSigner['url'];
                }

                if ($eventName === 'signature_started') {
                    $result[$eventSignerId]['status'] = 'em_andamento';
                    $result[$eventSignerId]['status_label'] = 'Assinatura iniciada';
                } elseif ($eventName === 'sign') {
                    $result[$eventSignerId]['status'] = 'assinado';
                    $result[$eventSignerId]['status_label'] = 'Assinado';
                } elseif ($eventName === 'refuse') {
                    $result[$eventSignerId]['status'] = 'recusado';
                    $result[$eventSignerId]['status_label'] = 'Recusado';
                } elseif ($eventName === 'add_signer') {
                    $result[$eventSignerId]['status'] = 'aguardando';
                    $result[$eventSignerId]['status_label'] = 'Aguardando assinatura';
                }
            }

            $items = array_values($result);
            usort($items, static function (array $a, array $b): int {
                $groupCompare = ((int)($a['group'] ?? 1)) <=> ((int)($b['group'] ?? 1));
                if ($groupCompare !== 0) {
                    return $groupCompare;
                }

                return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            });

            return $items;
        }

        private function normalizeEvents(array $events): array
        {
            $timeline = [];
            foreach ($events as $event) {
                $timeline[] = [
                    'id' => (string)($event['id'] ?? ''),
                    'name' => (string)($event['attributes']['name'] ?? ''),
                    'created' => (string)($event['attributes']['created'] ?? ''),
                    'signer_name' => (string)($event['attributes']['data']['signer']['name'] ?? ''),
                ];
            }

            return $timeline;
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
                return trim(substr($this->apiToken, 7));
            }

            return $this->apiToken;
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
