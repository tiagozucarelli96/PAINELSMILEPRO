<?php
/**
 * vendas_pre_contratos.php
 * Painel interno de Pré-contratos - Lista e edição
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/vendas_me_helper.php';
require_once __DIR__ . '/vendas_helper.php';
require_once __DIR__ . '/eventos_reuniao_helper.php';
require_once __DIR__ . '/pacotes_evento_helper.php';
require_once __DIR__ . '/upload_magalu.php';

if (empty($_SESSION['logado'])) {
    header('Location: index.php?page=login');
    exit;
}

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
$admin_context = !empty($_GET['admin']) || (!empty($_POST['admin_context']) && $_POST['admin_context'] === '1');
$perm_comercial = !empty($_SESSION['perm_comercial']);
$can_access_vendas_admin = vendas_can_access_administracao();

if (!$perm_comercial && !($admin_context && $can_access_vendas_admin)) {
    header('Location: index.php?page=dashboard');
    exit;
}

$is_admin = $can_access_vendas_admin;

$mensagens = [];
$erros = [];
if (!empty($_GET['success'])) {
    $mensagens[] = $_GET['success'];
}
if (!empty($_GET['mensagem'])) {
    $mensagens[] = $_GET['mensagem'];
}

// Flash message (ex.: aprovado/criado na ME) - exibido uma única vez
$vendas_flash = $_SESSION['vendas_flash'] ?? null;
if (is_array($vendas_flash)) {
    unset($_SESSION['vendas_flash']);
} else {
    $vendas_flash = null;
}

// Garantir schema do módulo (evita fatal quando SQL ainda não foi aplicado no ambiente)
if (!vendas_ensure_schema($pdo, $erros, $mensagens)) {
    includeSidebar($admin_context ? 'Administrativo' : 'Comercial');
    echo '<div style="padding:2rem;max-width:1100px;margin:0 auto;">';
    foreach ($erros as $e) {
        echo '<div class="alert alert-error">' . htmlspecialchars((string)$e) . '</div>';
    }
    echo '<div class="alert alert-error">Base de Vendas ausente/desatualizada. Execute os SQLs <code>sql/041_modulo_vendas.sql</code> e <code>sql/042_vendas_ajustes.sql</code>.</div>';
    echo '</div>';
    endSidebar();
    exit;
}

function vendas_parse_money($value): float {
    if ($value === null) return 0.0;
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') return 0.0;
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
        $value = preg_replace('/[^\d\.\-]/', '', $value);
    }
    return (float)$value;
}

function vendas_format_money_brl($value): string {
    return number_format((float)$value, 2, ',', '.');
}

function vendas_format_phone_display(?string $raw): string {
    $digits = preg_replace('/\D/', '', (string)$raw);
    if ($digits === '') {
        return '';
    }

    if (strlen($digits) >= 12 && substr($digits, 0, 2) === '55') {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) === 11) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7, 4));
    }

    if (strlen($digits) === 10) {
        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6, 4));
    }

    return trim((string)$raw);
}

function vendas_normalize_pre_contrato_status(array $preContrato): string {
    $status = (string)($preContrato['status'] ?? '');

    if (in_array($status, ['', 'aguardando_conferencia', 'pronto_aprovacao'], true) && !empty($preContrato['me_event_id'])) {
        return 'aprovado_criado_me';
    }

    return $status !== '' ? $status : 'aguardando_conferencia';
}

function vendas_status_meta(string $status): array {
    $map = [
        'aguardando_conferencia' => ['class' => 'status-aguardando', 'text' => 'Aguardando conferência'],
        'pronto_aprovacao' => ['class' => 'status-pronto', 'text' => 'Pronto para aprovação'],
        'aprovado_criado_me' => ['class' => 'status-aprovado', 'text' => 'Aprovado / Criado na ME'],
        'cancelado_nao_fechou' => ['class' => 'status-cancelado', 'text' => 'Cancelado / Não fechou'],
    ];

    return $map[$status] ?? $map['aguardando_conferencia'];
}

function vendas_format_date_display(?string $date): string {
    $timestamp = strtotime((string)$date);
    return $timestamp ? date('d/m/Y', $timestamp) : '-';
}

function vendas_build_list_url(string $baseUrl, array $params = []): string {
    $query = array_merge([
        'status' => '',
        'tipo' => '',
        'busca' => '',
    ], $params);

    return $baseUrl . '&' . http_build_query($query);
}

function vendas_locais_curto_map(PDO $pdo): array {
    try {
        $stmt = $pdo->query("
            SELECT me_local_nome, space_visivel
            FROM logistica_me_locais
            WHERE status_mapeamento = 'MAPEADO'
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $map = [];
    foreach ($rows as $row) {
        $nome = trim((string)($row['me_local_nome'] ?? ''));
        if ($nome === '') {
            continue;
        }
        $curto = trim((string)($row['space_visivel'] ?? ''));
        $key = function_exists('mb_strtolower') ? mb_strtolower($nome) : strtolower($nome);
        $map[$key] = $curto !== '' ? $curto : $nome;
    }

    return $map;
}

function vendas_unidade_curta(array $map, ?string $unidade): string {
    $nome = trim((string)$unidade);
    if ($nome === '') {
        return '';
    }

    $key = function_exists('mb_strtolower') ? mb_strtolower($nome) : strtolower($nome);
    return $map[$key] ?? $nome;
}

function vendas_collect_comercial_payload_from_post(): array {
    $pacote = trim((string)($_POST['pacote_contratado'] ?? ''));
    $forma_pagamento_detalhada = trim((string)($_POST['forma_pagamento_detalhada'] ?? ''));
    $itens_adicionais = trim((string)($_POST['itens_adicionais'] ?? ''));
    $observacoes_internas = trim((string)($_POST['observacoes_internas'] ?? ''));
    $tipo_evento_real = eventos_reuniao_normalizar_tipo_evento_real((string)($_POST['tipo_evento_real'] ?? ''), $GLOBALS['pdo'] ?? null);
    $pacote_evento_raw = trim((string)($_POST['pacote_evento_id'] ?? ''));
    $pacote_evento_id = ctype_digit($pacote_evento_raw) && (int)$pacote_evento_raw > 0 ? (int)$pacote_evento_raw : 0;
    $valor_negociado = vendas_parse_money($_POST['valor_negociado'] ?? 0);
    $desconto = vendas_parse_money($_POST['desconto'] ?? 0);

    $adicionais = [];
    if (!empty($_POST['adicionais']) && is_array($_POST['adicionais'])) {
        foreach ($_POST['adicionais'] as $adicional) {
            $item = trim((string)($adicional['item'] ?? ''));
            $valor_raw = $adicional['valor'] ?? '';
            if ($item !== '' && trim((string)$valor_raw) !== '') {
                $adicionais[] = [
                    'item' => $item,
                    'valor' => vendas_parse_money($valor_raw),
                ];
            }
        }
    }

    return [
        'pacote_contratado' => $pacote,
        'forma_pagamento' => $forma_pagamento_detalhada,
        'itens_adicionais' => $itens_adicionais,
        'observacoes_internas' => $observacoes_internas,
        'tipo_evento_real' => $tipo_evento_real,
        'pacote_evento_id' => $pacote_evento_id,
        'valor_negociado' => $valor_negociado,
        'desconto' => $desconto,
        'valor_total' => $valor_negociado + array_sum(array_column($adicionais, 'valor')) - $desconto,
        'adicionais' => $adicionais,
    ];
}

function vendas_salvar_dados_comerciais(PDO $pdo, int $pre_contrato_id, array $payload, int $usuario_id, bool $registrar_pronto_aprovacao = true): array {
    $stmt_pre = $pdo->prepare("
        SELECT id, status, nome_completo, tipo_evento, data_evento, unidade
        FROM vendas_pre_contratos
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt_pre->execute([$pre_contrato_id]);
    $pre_contrato = $stmt_pre->fetch(PDO::FETCH_ASSOC);
    if (!$pre_contrato) {
        throw new Exception('Pré-contrato não encontrado.');
    }

    $status_anterior = (string)($pre_contrato['status'] ?? '');
    $novo_status = $status_anterior;
    if ($registrar_pronto_aprovacao && !in_array($status_anterior, ['aprovado_criado_me', 'cancelado_nao_fechou'], true)) {
        $novo_status = 'pronto_aprovacao';
    }

    $stmt = $pdo->prepare("
        UPDATE vendas_pre_contratos
        SET pacote_contratado = ?, forma_pagamento = ?, itens_adicionais = ?, observacoes_internas = ?,
            tipo_evento_real = ?, pacote_evento_id = ?,
            valor_negociado = ?, desconto = ?, valor_total = ?,
            atualizado_em = NOW(), atualizado_por = ?, status = ?,
            responsavel_comercial_id = COALESCE(responsavel_comercial_id, ?)
        WHERE id = ?
    ");
    $stmt->execute([
        $payload['pacote_contratado'] !== '' ? $payload['pacote_contratado'] : null,
        $payload['forma_pagamento'] !== '' ? $payload['forma_pagamento'] : null,
        $payload['itens_adicionais'] !== '' ? $payload['itens_adicionais'] : null,
        $payload['observacoes_internas'] !== '' ? $payload['observacoes_internas'] : null,
        $payload['tipo_evento_real'] !== '' ? $payload['tipo_evento_real'] : null,
        $payload['pacote_evento_id'] > 0 ? $payload['pacote_evento_id'] : null,
        $payload['valor_negociado'],
        $payload['desconto'],
        $payload['valor_total'],
        $usuario_id,
        $novo_status,
        $usuario_id,
        $pre_contrato_id
    ]);

    $stmt_del = $pdo->prepare("DELETE FROM vendas_adicionais WHERE pre_contrato_id = ?");
    $stmt_del->execute([$pre_contrato_id]);

    $stmt_add = $pdo->prepare("INSERT INTO vendas_adicionais (pre_contrato_id, item, valor) VALUES (?, ?, ?)");
    foreach ($payload['adicionais'] as $adicional) {
        $stmt_add->execute([$pre_contrato_id, $adicional['item'], $adicional['valor']]);
    }

    if (!empty($_FILES['anexo_orcamento']['tmp_name'])) {
        $uploader = new MagaluUpload();
        $result = $uploader->upload($_FILES['anexo_orcamento'], 'vendas/orcamentos');

        if (!empty($result['chave_storage']) || !empty($result['url'])) {
            $stmt_anexo = $pdo->prepare("
                INSERT INTO vendas_anexos
                (pre_contrato_id, nome_original, nome_arquivo, chave_storage, url, mime_type, tamanho_bytes, upload_por)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_anexo->execute([
                $pre_contrato_id,
                $_FILES['anexo_orcamento']['name'],
                $result['nome_original'] ?? $_FILES['anexo_orcamento']['name'],
                $result['chave_storage'] ?? null,
                $result['url'] ?? null,
                $_FILES['anexo_orcamento']['type'],
                $_FILES['anexo_orcamento']['size'],
                $usuario_id
            ]);
        }
    }

    $stmt_log = $pdo->prepare("INSERT INTO vendas_logs (pre_contrato_id, acao, usuario_id, detalhes) VALUES (?, 'dados_comerciais_salvos', ?, ?)");
    $stmt_log->execute([$pre_contrato_id, $usuario_id, json_encode(['valor_total' => $payload['valor_total']])]);

    return [
        'pre_contrato' => $pre_contrato,
        'status_anterior' => $status_anterior,
        'status_atual' => $novo_status,
        'valor_total' => $payload['valor_total'],
    ];
}

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'salvar_comercial') {
        $pre_contrato_id = (int)($_POST['pre_contrato_id'] ?? 0);
        $payload = vendas_collect_comercial_payload_from_post();
        
        try {
            if ($admin_context) {
                if ($payload['tipo_evento_real'] === '') {
                    throw new Exception('Selecione o tipo de evento para a organização.');
                }
                if ($payload['pacote_evento_id'] <= 0) {
                    throw new Exception('Selecione o pacote do evento para a organização.');
                }
            }
            $pdo->beginTransaction();
            $save_result = vendas_salvar_dados_comerciais($pdo, $pre_contrato_id, $payload, $usuario_id, true);
            
            $pdo->commit();
            $mensagens[] = 'Dados comerciais salvos com sucesso!';

            $deve_notificar_envio = !in_array($save_result['status_anterior'], ['pronto_aprovacao', 'aprovado_criado_me'], true)
                && $save_result['status_atual'] === 'pronto_aprovacao';
            if ($deve_notificar_envio && is_array($save_result['pre_contrato'])) {
                $usuario_nome_notificacao = trim((string)($_SESSION['nome'] ?? $_SESSION['usuario_nome'] ?? $_SESSION['usuario'] ?? ''));
                vendas_notificar_superadmins_contrato($pdo, [
                    'evento' => 'enviado_aprovacao',
                    'pre_contrato_id' => (int)$pre_contrato_id,
                    'nome_cliente' => (string)($save_result['pre_contrato']['nome_completo'] ?? ''),
                    'tipo_evento' => (string)($save_result['pre_contrato']['tipo_evento'] ?? ''),
                    'data_evento' => (string)($save_result['pre_contrato']['data_evento'] ?? ''),
                    'unidade' => (string)($save_result['pre_contrato']['unidade'] ?? ''),
                    'valor_total' => (float)$save_result['valor_total'],
                    'usuario_nome' => $usuario_nome_notificacao,
                    'url_destino' => 'index.php?page=vendas_administracao&editar=' . (int)$pre_contrato_id,
                ]);
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $erros[] = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
    
    if ($action === 'apagar') {
        $pre_contrato_id = (int)($_POST['pre_contrato_id'] ?? 0);
        if ($pre_contrato_id <= 0) {
            $erros[] = 'Pré-contrato inválido.';
        } elseif (!($perm_comercial || $is_admin)) {
            $erros[] = 'Sem permissão para apagar pré-contratos.';
        } else {
            try {
                $pdo->beginTransaction();
                // Remover registros relacionados (ordem por causa de possíveis FKs)
                $pdo->prepare("DELETE FROM vendas_adicionais WHERE pre_contrato_id = ?")->execute([$pre_contrato_id]);
                $pdo->prepare("DELETE FROM vendas_anexos WHERE pre_contrato_id = ?")->execute([$pre_contrato_id]);
                $pdo->prepare("DELETE FROM vendas_logs WHERE pre_contrato_id = ?")->execute([$pre_contrato_id]);
                $pdo->prepare("DELETE FROM vendas_kanban_cards WHERE pre_contrato_id = ?")->execute([$pre_contrato_id]);
                $stmt = $pdo->prepare("DELETE FROM vendas_pre_contratos WHERE id = ?");
                $stmt->execute([$pre_contrato_id]);
                $pdo->commit();
                if ($stmt->rowCount() > 0) {
                    $page_param = (isset($_GET['page']) && $_GET['page'] === 'vendas_administracao') ? 'vendas_administracao' : 'vendas_pre_contratos';
                    $redirect = 'index.php?page=' . $page_param
                        . '&status=' . urlencode((string)($_GET['status'] ?? ''))
                        . '&tipo=' . urlencode((string)($_GET['tipo'] ?? ''))
                        . '&busca=' . urlencode((string)($_GET['busca'] ?? ''));
                    if (function_exists('customAlert')) {
                        header('Location: ' . $redirect . '&success=' . urlencode('Pré-contrato apagado com sucesso.'));
                    } else {
                        header('Location: ' . $redirect . '&mensagem=' . urlencode('Pré-contrato apagado com sucesso.'));
                    }
                    exit;
                }
                $erros[] = 'Pré-contrato não encontrado.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $erros[] = 'Erro ao apagar: ' . $e->getMessage();
            }
        }
    }
    
    if ($action === 'aprovar_criar_me') {
        // Verificar se é admin usando função centralizada
        if (!$can_access_vendas_admin) {
            $erros[] = 'Apenas administradores podem aprovar e criar na ME';
        } elseif (!$admin_context) {
            $erros[] = 'Aprovação disponível apenas em Vendas > Administração.';
        } else {
        $pre_contrato_id = (int)($_POST['pre_contrato_id'] ?? 0);
        $idvendedor = (int)($_POST['idvendedor'] ?? 0);
        $override_conflito = isset($_POST['override_conflito']) && $_POST['override_conflito'] === '1';
        $override_motivo = trim($_POST['override_motivo'] ?? '');
        $atualizar_cliente_me = $_POST['atualizar_cliente_me'] ?? 'manter'; // manter, atualizar, apenas_painel
        $payload_comercial = vendas_collect_comercial_payload_from_post();

        // A aprovação só existe no contexto admin. Em caso de erro, reabrimos o modal para mostrar detalhes.
        // Em caso de sucesso, voltamos para a listagem da Administração com banner (flash).
        $redirect_page = 'vendas_administracao';
        $redirect_url_error = 'index.php?page=' . $redirect_page . '&editar=' . $pre_contrato_id . '&abrir_aprovacao=1&aprovacao_result=1';
        $redirect_url_success = 'index.php?page=' . $redirect_page;

        // Para melhorar o diagnóstico em caso de falha após a ME responder OK
        $me_client_id = null;
        $me_event_id = null;
        $evento_me = null;
        $already_exists = false;
        
        try {
            if ($idvendedor <= 0) {
                throw new Exception('Selecione o vendedor (ME) para criar o evento.');
            }
            if ($payload_comercial['tipo_evento_real'] === '') {
                throw new Exception('Selecione o tipo de evento para a organização.');
            }
            if ($payload_comercial['pacote_evento_id'] <= 0) {
                throw new Exception('Selecione o pacote do evento para a organização.');
            }

            $pdo->beginTransaction();
            vendas_salvar_dados_comerciais($pdo, $pre_contrato_id, $payload_comercial, $usuario_id, true);
            $pdo->commit();

            // Buscar pré-contrato
            $stmt = $pdo->prepare("SELECT * FROM vendas_pre_contratos WHERE id = ?");
            $stmt->execute([$pre_contrato_id]);
            $pre_contrato = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$pre_contrato) {
                throw new Exception('Pré-contrato não encontrado');
            }
            
            if ($pre_contrato['status'] === 'aprovado_criado_me') {
                throw new Exception('Este pré-contrato já foi aprovado e criado na ME');
            }

            // Se não há conflito ou é override, continuar
            if (empty($erros)) {
                $kanban_card_created = false;
                $kanban_card_id = null;

                // Buscar/verificar cliente na ME
                $clientes_encontrados = vendas_me_buscar_cliente(
                    $pre_contrato['cpf'] ?? '',
                    $pre_contrato['email'] ?? '',
                    $pre_contrato['telefone'] ?? '',
                    $pre_contrato['nome_completo'] ?? ''
                );

                $cliente_existente = null;

                // Verificar se encontrou cliente por CPF (match forte)
                foreach ($clientes_encontrados as $match) {
                    if ($match['match_type'] === 'cpf' && $match['match_strength'] === 'forte') {
                        $cliente_existente = $match['cliente'];
                        $me_client_id = $cliente_existente['id'] ?? null;
                        break;
                    }
                }

                // Se não encontrou por CPF, verificar email/telefone
                if (!$me_client_id) {
                    foreach ($clientes_encontrados as $match) {
                        if (in_array($match['match_type'], ['email', 'telefone']) && $match['match_strength'] === 'forte') {
                            $cliente_existente = $match['cliente'];
                            $me_client_id = $cliente_existente['id'] ?? null;
                            break;
                        }
                    }
                }

                // Se encontrou cliente existente e há divergências, processar atualização
                if ($cliente_existente && $atualizar_cliente_me === 'atualizar') {
                    $payload_update = [
                        'nome' => $pre_contrato['nome_completo'] ?? null,
                        'email' => $pre_contrato['email'] ?? null,
                        // docs ME: telefone/celular (normalizado no helper antes de enviar)
                        'telefone' => $pre_contrato['telefone'] ?? null,
                        'rg' => $pre_contrato['rg'] ?? null,
                        'cep' => $pre_contrato['cep'] ?? null,
                        'endereco' => $pre_contrato['endereco_completo'] ?? null,
                        'numero' => $pre_contrato['numero'] ?? null,
                        'complemento' => $pre_contrato['complemento'] ?? null,
                        'bairro' => $pre_contrato['bairro'] ?? null,
                        'cidade' => $pre_contrato['cidade'] ?? null,
                        'estado' => $pre_contrato['estado'] ?? null,
                        'pais' => $pre_contrato['pais'] ?? null,
                        'redesocial' => $pre_contrato['instagram'] ?? null,
                    ];
                    // remover nulos
                    $payload_update = array_filter($payload_update, fn($v) => $v !== null && $v !== '');

                    vendas_me_atualizar_cliente((int)$me_client_id, $payload_update);
                }

                // Se não encontrou cliente, criar novo
                if (!$me_client_id) {
                    $novo_cliente = vendas_me_criar_cliente([
                        'nome' => $pre_contrato['nome_completo'],
                        'cpf' => $pre_contrato['cpf'],
                        'rg' => $pre_contrato['rg'] ?? null,
                        'email' => $pre_contrato['email'],
                        'telefone' => $pre_contrato['telefone'],
                        'cep' => $pre_contrato['cep'] ?? null,
                        'endereco' => $pre_contrato['endereco_completo'] ?? null,
                        'numero' => $pre_contrato['numero'] ?? null,
                        'complemento' => $pre_contrato['complemento'] ?? null,
                        'bairro' => $pre_contrato['bairro'] ?? null,
                        'cidade' => $pre_contrato['cidade'] ?? null,
                        'estado' => $pre_contrato['estado'] ?? null,
                        'pais' => $pre_contrato['pais'] ?? null,
                        'redesocial' => $pre_contrato['instagram'] ?? null
                    ]);
                    $me_client_id = $novo_cliente['id'] ?? null;
                }

                if (!$me_client_id) {
                    throw new Exception('Não foi possível obter/criar cliente na ME');
                }
                
                // Tipo de evento na ME: definido pelo local (mapeamento em Logística > Conexão: ID tipo evento por local)
                $tipo_evento_id = vendas_obter_me_tipo_evento_id_por_local((string)($pre_contrato['unidade'] ?? ''));
                if ($tipo_evento_id === null || $tipo_evento_id <= 0) {
                    throw new Exception('Tipo de evento (ME) não mapeado para o local "' . ($pre_contrato['unidade'] ?? '') . '". Em Logística > Conexão, preencha a coluna "ID tipo evento (ME)" para este local (ex.: Cristal=15, Garden=10, DiverKids=13, Lisbon 1=4).');
                }
                
                // Validar que local está mapeado antes de criar evento
                $me_local_id_validacao = vendas_obter_me_local_id($pre_contrato['unidade']);
                if (!$me_local_id_validacao) {
                    throw new Exception('Local não mapeado. Ajuste em Logística > Conexão antes de aprovar.');
                }

                // Nome do evento: para casamento usar "nome_noivos".
                // Para outros tipos, se o cliente informou um nome do evento, usar; senão, fallback padrão.
                $nome_noivos_ou_evento = trim((string)($pre_contrato['nome_noivos'] ?? ''));
                if ((string)($pre_contrato['tipo_evento'] ?? '') === 'casamento') {
                    $nome_evento = $nome_noivos_ou_evento !== '' ? $nome_noivos_ou_evento : (string)($pre_contrato['nome_completo'] ?? '');
                } else {
                    if ($nome_noivos_ou_evento !== '') {
                        $nome_evento = $nome_noivos_ou_evento;
                    } else {
                        $nome_evento = (string)($pre_contrato['nome_completo'] ?? '') . ' - ' . ucfirst((string)($pre_contrato['tipo_evento'] ?? ''));
                    }
                }

                // Idempotência: se um evento já existe na ME para esse cliente/data/local/horários,
                // não criar novamente; apenas vincular no Painel.
                $norm_time = function ($t): string {
                    $t = trim((string)$t);
                    if ($t === '') return '';
                    if (preg_match('/^\d{2}:\d{2}$/', $t)) return $t . ':00';
                    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $t)) return $t;
                    $ts = strtotime($t);
                    return $ts ? date('H:i:s', $ts) : $t;
                };
                $inicio_norm = $norm_time($pre_contrato['horario_inicio'] ?? '');
                $fim_norm = $norm_time($pre_contrato['horario_termino'] ?? '');

                $evento_existente = null;
                try {
                    $eventos_dia = vendas_me_buscar_eventos($pre_contrato['data_evento'], $pre_contrato['unidade']);
                } catch (Throwable $e) {
                    $eventos_dia = [];
                }
                foreach ($eventos_dia as $ev) {
                    if (!is_array($ev)) continue;
                    $idcli = (int)($ev['idcliente'] ?? 0);
                    if ($idcli <= 0 || $idcli !== (int)$me_client_id) continue;
                    $ev_inicio = $norm_time($ev['horaevento'] ?? $ev['hora_inicio'] ?? $ev['inicio'] ?? '');
                    $ev_fim = $norm_time($ev['horaeventofim'] ?? $ev['hora_termino'] ?? $ev['fim'] ?? '');
                    if ($ev_inicio === $inicio_norm && $ev_fim === $fim_norm) {
                        $evento_existente = $ev;
                        break;
                    }
                }

                if ($evento_existente) {
                    $already_exists = true;
                    $me_event_id = (int)($evento_existente['id'] ?? $evento_existente['idevento'] ?? 0);
                    if ($me_event_id <= 0) {
                        throw new Exception('Evento já existe na ME, mas não foi possível obter o ID para vincular no Painel.');
                    }
                    $evento_me = [
                        'id' => $me_event_id,
                        'data' => $evento_existente,
                        'payload' => null,
                        'already_exists' => true,
                    ];
                } else {
                    // Verificar conflito de agenda (antes de criar o evento)
                    $conflito = vendas_me_verificar_conflito_agenda(
                        $pre_contrato['data_evento'],
                        $pre_contrato['unidade'],
                        $pre_contrato['horario_inicio'],
                        $pre_contrato['horario_termino']
                    );

                    if ($conflito['tem_conflito'] && !$override_conflito) {
                        // Retornar erro com detalhes do conflito
                        $erros[] = 'Conflito de agenda detectado! Existem eventos na mesma unidade e data que não respeitam a distância mínima.';
                        $_SESSION['vendas_conflito_detalhes'] = $conflito;
                        $_SESSION['vendas_pre_contrato_id'] = $pre_contrato_id;
                    } elseif ($conflito['tem_conflito'] && $override_conflito) {
                        // Log do override
                        error_log('[VENDAS] Override de conflito aplicado. Motivo: ' . $override_motivo);
                    }

                    if (empty($erros)) {
                        // Criar evento na ME
                        $dados_evento = [
                            'client_id' => $me_client_id,
                            'tipo_evento_id' => $tipo_evento_id,
                            'nome_evento' => $nome_evento,
                            'data_evento' => $pre_contrato['data_evento'],
                            'hora_inicio' => $pre_contrato['horario_inicio'],
                            'hora_termino' => $pre_contrato['horario_termino'],
                            'local' => $pre_contrato['unidade'],
                            // campos adicionais (docs ME)
                            'idvendedor' => $idvendedor,
                            'nconvidados' => (int)($pre_contrato['num_convidados'] ?? 0),
                            'comoconheceu' => (function() use ($pre_contrato) {
                                $v = (string)($pre_contrato['como_conheceu'] ?? '');
                                if ($v === '') return '';
                                if ($v === 'instagram') return 'Instagram';
                                if ($v === 'facebook') return 'Facebook';
                                if ($v === 'google') return 'Google';
                                if ($v === 'indicacao') return 'Indicação';
                                if ($v === 'outro') {
                                    $o = trim((string)($pre_contrato['como_conheceu_outro'] ?? ''));
                                    return $o !== '' ? ('Outro: ' . $o) : 'Outro';
                                }
                                return $v;
                            })(),
                            // Observação deve ficar apenas interna no Painel.
                            'enviar_observacao' => false
                        ];

                        $evento_me = vendas_me_criar_evento($dados_evento);
                        $me_event_id = $evento_me['id'] ?? null;

                        if (!$me_event_id) {
                            throw new Exception('Não foi possível criar evento na ME');
                        }
                    }
                }

                if (!empty($erros)) {
                    // conflito sem override: apenas renderizar a página com os erros/alertas
                } else {
                    $pdo->beginTransaction();
                
                // Atualizar pré-contrato
                $me_payload = [
                    'cliente' => [
                        'id' => (int)$me_client_id,
                        'atualizar_cliente_me' => $atualizar_cliente_me
                    ],
                    'evento' => [
                        'idvendedor' => (int)$idvendedor,
                        'payload' => $evento_me['payload'] ?? null,
                        'response' => $evento_me['data'] ?? null
                    ]
                ];
                $stmt = $pdo->prepare("
                    UPDATE vendas_pre_contratos 
                    SET me_client_id = ?, me_event_id = ?, me_payload = ?, me_criado_em = NOW(),
                        status = 'aprovado_criado_me', aprovado_por = ?, aprovado_em = NOW(),
                        override_conflito = ?, override_motivo = ?, override_por = ?, override_em = ?
                    WHERE id = ?
                ");
                // Importante: PDO (pgsql) pode converter boolean false em string vazia (''),
                // o que quebra em coluna boolean. Enviar 't'/'f' explicitamente.
                $override_conflito_db = $override_conflito ? 't' : 'f';
                $stmt->execute([
                    $me_client_id,
                    $me_event_id,
                    json_encode($me_payload, JSON_UNESCAPED_UNICODE),
                    $usuario_id,
                    $override_conflito_db,
                    $override_motivo,
                    $override_conflito ? $usuario_id : null,
                    $override_conflito ? date('Y-m-d H:i:s') : null,
                    $pre_contrato_id
                ]);
                
                // Criar card no Kanban
                $stmt_board = $pdo->prepare("SELECT id FROM vendas_kanban_boards WHERE ativo = TRUE LIMIT 1");
                $stmt_board->execute();
                $board = $stmt_board->fetch(PDO::FETCH_ASSOC);
                
                if ($board) {
                    $stmt_coluna = $pdo->prepare("SELECT id FROM vendas_kanban_colunas WHERE board_id = ? AND nome = 'Criado na ME' LIMIT 1");
                    $stmt_coluna->execute([$board['id']]);
                    $coluna = $stmt_coluna->fetch(PDO::FETCH_ASSOC);
                    
                    if ($coluna) {
                        $stmt_card = $pdo->prepare("
                            INSERT INTO vendas_kanban_cards 
                            (board_id, coluna_id, pre_contrato_id, titulo, cliente_nome, data_evento, unidade, valor_total, status, criado_por)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Criado na ME', ?)
                            RETURNING id
                        ");
                        $titulo_card = $pre_contrato['nome_completo'] . ' - ' . date('d/m/Y', strtotime($pre_contrato['data_evento']));
                        $stmt_card->execute([
                            $board['id'],
                            $coluna['id'],
                            $pre_contrato_id,
                            $titulo_card,
                            $pre_contrato['nome_completo'],
                            $pre_contrato['data_evento'],
                            $pre_contrato['unidade'],
                            $pre_contrato['valor_total'],
                            $usuario_id
                        ]);
                        $kanban_card_id = (int)$stmt_card->fetchColumn();
                        $kanban_card_created = $kanban_card_id > 0;
                    }
                }
                
                // Log
                $stmt_log = $pdo->prepare("INSERT INTO vendas_logs (pre_contrato_id, acao, usuario_id, detalhes) VALUES (?, 'aprovado_criado_me', ?, ?)");
                $stmt_log->execute([$pre_contrato_id, $usuario_id, json_encode([
                    'me_client_id' => $me_client_id,
                    'me_event_id' => $me_event_id,
                    'override' => $override_conflito,
                    'kanban_card_id' => $kanban_card_id
                ])]);
                
                $pdo->commit();
                $msg_ok = $already_exists
                    ? 'Evento já existia na ME e foi vinculado no Painel com sucesso.'
                    : 'Evento criado na ME com sucesso.';

                // Banner na listagem (Administração)
                $_SESSION['vendas_flash'] = [
                    'type' => 'success',
                    'message' => 'Pré-contrato #' . (int)$pre_contrato_id . ' aprovado. ' . $msg_ok
                        . ' Cliente ME: ' . (int)$me_client_id . ' — Evento ME: ' . (int)$me_event_id . '.',
                    'pre_contrato_id' => (int)$pre_contrato_id,
                    'me_client_id' => (int)$me_client_id,
                    'me_event_id' => (int)$me_event_id,
                    'kanban_card_created' => (bool)$kanban_card_created,
                    'kanban_card_id' => (int)($kanban_card_id ?? 0),
                ];

                $usuario_nome_notificacao = trim((string)($_SESSION['nome'] ?? $_SESSION['usuario_nome'] ?? $_SESSION['usuario'] ?? ''));
                vendas_notificar_superadmins_contrato($pdo, [
                    'evento' => 'aprovado_me',
                    'pre_contrato_id' => (int)$pre_contrato_id,
                    'nome_cliente' => (string)($pre_contrato['nome_completo'] ?? ''),
                    'tipo_evento' => (string)($pre_contrato['tipo_evento'] ?? ''),
                    'data_evento' => (string)($pre_contrato['data_evento'] ?? ''),
                    'unidade' => (string)($pre_contrato['unidade'] ?? ''),
                    'valor_total' => isset($pre_contrato['valor_total']) ? (float)$pre_contrato['valor_total'] : null,
                    'me_client_id' => (int)$me_client_id,
                    'me_event_id' => (int)$me_event_id,
                    'usuario_nome' => $usuario_nome_notificacao,
                    'url_destino' => 'index.php?page=vendas_administracao&editar=' . (int)$pre_contrato_id,
                ]);

                header('Location: ' . $redirect_url_success);
                exit;
                } // else empty($erros)
                
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['vendas_aprovacao_result'] = [
                'ok' => false,
                'message' => ($me_event_id ? 'Evento criado/válido na ME, mas houve erro ao salvar no Painel.' : 'Erro ao criar na ME.'),
                'error' => $e->getMessage(),
                'me_last' => $_SESSION['vendas_me_last'] ?? null,
                'me_client_id' => $me_client_id ? (int)$me_client_id : null,
                'me_event_id' => $me_event_id ? (int)$me_event_id : null,
            ];
            error_log('Erro ao aprovar pré-contrato: ' . $e->getMessage());
            header('Location: ' . $redirect_url_error);
            exit;
        }
        } // Fechar else do if (!vendas_is_admin())
    }
    
    if ($action === 'atualizar_status') {
        $pre_contrato_id = (int)($_POST['pre_contrato_id'] ?? 0);
        $novo_status = $_POST['novo_status'] ?? '';
        
        if (in_array($novo_status, ['aguardando_conferencia', 'pronto_aprovacao', 'cancelado_nao_fechou'])) {
            $stmt = $pdo->prepare("
                UPDATE vendas_pre_contratos 
                SET status = ?, atualizado_em = NOW(), atualizado_por = ?
                WHERE id = ?
            ");
            $stmt->execute([$novo_status, $usuario_id, $pre_contrato_id]);
            
            $mensagens[] = 'Status atualizado com sucesso!';
        }
    }
}

// Buscar pré-contratos
$filtro_status = $_GET['status'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? '';
$busca = trim($_GET['busca'] ?? '');
$status_tabs = [
    '' => 'Todos',
    'aguardando_conferencia' => 'Aguardando conferência',
    'pronto_aprovacao' => 'Pronto para aprovação',
    'aprovado_criado_me' => 'Aprovado / Criado na ME',
    'cancelado_nao_fechou' => 'Cancelado / Não fechou',
];
$status_expression = "CASE
    WHEN COALESCE(v.me_event_id, 0) > 0 AND COALESCE(v.status, '') IN ('', 'aguardando_conferencia', 'pronto_aprovacao') THEN 'aprovado_criado_me'
    WHEN COALESCE(v.status, '') = '' THEN 'aguardando_conferencia'
    ELSE v.status
END";

$where = [];
$params = [];

if ($filtro_status && isset($status_tabs[$filtro_status])) {
    $where[] = $status_expression . " = ?";
    $params[] = $filtro_status;
}

if ($filtro_tipo) {
    $where[] = "v.tipo_evento = ?";
    $params[] = $filtro_tipo;
}

if ($busca) {
    $where[] = "(COALESCE(v.nome_completo, '') ILIKE ? OR COALESCE(v.email, '') ILIKE ? OR COALESCE(v.cpf, '') ILIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

$sql = "SELECT v.*, 
               {$status_expression} AS status_normalizado,
               u1.nome as atualizado_por_nome,
               u2.nome as aprovado_por_nome
        FROM vendas_pre_contratos v
        LEFT JOIN usuarios u1 ON u1.id = v.atualizado_por
        LEFT JOIN usuarios u2 ON u2.id = v.aprovado_por";
        
if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}

$sql .= " ORDER BY v.criado_em DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pre_contratos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$locais_curto_map = vendas_locais_curto_map($pdo);
$pacotes_evento = pacotes_evento_listar($pdo, false);
$tipos_evento_real_options = eventos_reuniao_tipos_evento_real_options($pdo, false);

// Base de URL para manter o contexto correto (listagem vs administração)
$page_param = $admin_context ? 'vendas_administracao' : 'vendas_pre_contratos';
$base_url = 'index.php?page=' . $page_param;
$base_query = vendas_build_list_url($base_url, [
    'status' => (string)$filtro_status,
    'tipo' => (string)$filtro_tipo,
    'busca' => (string)$busca,
]);

// Buscar pré-contrato específico para edição
$editar_id = (int)($_GET['editar'] ?? 0);
$pre_contrato_editar = null;
$adicionais_editar = [];
$anexos_editar = [];
$pre_contrato_telefone_exibicao = '';

if ($editar_id) {
    $stmt = $pdo->prepare("SELECT * FROM vendas_pre_contratos WHERE id = ?");
    $stmt->execute([$editar_id]);
    $pre_contrato_editar = $stmt->fetch(PDO::FETCH_ASSOC);

    // Diagnóstico (para entender quando a tela não abre o modal)
    try {
        $sp = (string)$pdo->query("SHOW search_path")->fetchColumn();
    } catch (Throwable $e) {
        $sp = '';
    }
    if (!$pre_contrato_editar) {
        error_log('[VENDAS] editar=' . $editar_id . ' NÃO encontrado. admin_context=' . ($admin_context ? '1' : '0') . ' search_path=' . $sp);
    } else {
        error_log('[VENDAS] editar=' . $editar_id . ' OK. status=' . ($pre_contrato_editar['status'] ?? '') . ' admin_context=' . ($admin_context ? '1' : '0') . ' search_path=' . $sp);
    }
    
    if ($pre_contrato_editar) {
        $pre_contrato_telefone_exibicao = vendas_format_phone_display($pre_contrato_editar['telefone'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM vendas_adicionais WHERE pre_contrato_id = ? ORDER BY id");
        $stmt->execute([$editar_id]);
        $adicionais_editar = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT * FROM vendas_anexos WHERE pre_contrato_id = ? ORDER BY criado_em DESC");
        $stmt->execute([$editar_id]);
        $anexos_editar = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

ob_start();
?>

<style>
.vendas-container {
    max-width: 1480px;
    margin: 0 auto;
    padding: 1.25rem 1.5rem;
}

.vendas-header {
    margin-bottom: 2rem;
}

.vendas-header h1 {
    font-size: 1.75rem;
    color: #1e3a8a;
    margin-bottom: 0.5rem;
}

.vendas-filters {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    padding: 0.875rem;
    border: 1px solid #dbe3ef;
    border-radius: 12px;
    background: #f8fafc;
}

.vendas-tabs {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.vendas-tab {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1rem;
    border-radius: 999px;
    border: 1px solid #cbd5e1;
    background: #fff;
    color: #334155;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.18s ease;
}

.vendas-tab:hover {
    border-color: #93c5fd;
    color: #1d4ed8;
}

.vendas-tab.active {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.18);
}

.vendas-filters select,
.vendas-filters input {
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
}

.vendas-card {
    background: white;
    border-radius: 14px;
    border: 1px solid #dbe3ef;
    padding: 1.5rem;
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
    margin-bottom: 1rem;
}

.vendas-table {
    width: 100%;
    border-collapse: collapse;
}
.vendas-table-wrap {
    width: 100%;
    overflow-x: auto;
}

.vendas-table th,
.vendas-table td {
    padding: 0.85rem 0.75rem;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
}

.vendas-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
}

.vendas-table .col-id { width: 56px; }
.vendas-table .col-cliente { width: 24%; }
.vendas-table .col-tipo { width: 110px; }
.vendas-table .col-data { width: 120px; }
.vendas-table .col-unidade { width: 180px; }
.vendas-table .col-valor { width: 130px; }
.vendas-table .col-status { width: 170px; }
.vendas-table .col-acoes { width: 148px; }

.cliente-cell strong {
    display: block;
    color: #0f172a;
    line-height: 1.35;
}

.cliente-cell small,
.unidade-cell small {
    display: block;
    margin-top: 0.2rem;
    color: #64748b;
}

.tipo-cell,
.data-cell,
.valor-cell {
    white-space: nowrap;
}

.unidade-cell {
    color: #0f172a;
    font-weight: 600;
}

.acoes-cell {
    white-space: nowrap;
}

.acoes-cell .btn {
    min-width: 86px;
    text-align: center;
}

.status-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}

.status-aguardando { background: #fef3c7; color: #92400e; }
.status-pronto { background: #dbeafe; color: #1e40af; }
.status-aprovado { background: #dcfce7; color: #166534; }
.status-cancelado { background: #fee2e2; color: #991b1b; }

.vendas-container .btn,
.vendas-toast .btn,
.vendas-modal-content .btn {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 500;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    line-height: 1;
}

.vendas-container .btn-primary,
.vendas-toast .btn-primary,
.vendas-modal-content .btn-primary { background: #2563eb; color: white; }
.vendas-container .btn-success,
.vendas-toast .btn-success,
.vendas-modal-content .btn-success { background: #16a34a; color: white; }
.vendas-container .btn-danger,
.vendas-toast .btn-danger,
.vendas-modal-content .btn-danger { background: #ef4444; color: white; }
.vendas-container .btn-secondary,
.vendas-toast .btn-secondary,
.vendas-modal-content .btn-secondary { background: #6b7280; color: white; }

/* Alerts (inline) */
.vendas-container .alert {
    border-radius: 10px;
    padding: 0.75rem 1rem;
    margin: 0 0 1rem 0;
    border: 1px solid transparent;
    background: #f8fafc;
    color: #0f172a;
}
.vendas-container .alert-success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
.vendas-container .alert-error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
.vendas-container .alert-warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }

/* Toast / Balão (fixo) para flash */
.vendas-toast {
    position: fixed;
    top: 88px;
    right: 24px;
    width: min(560px, calc(100vw - 48px));
    border-radius: 14px;
    border: 1px solid transparent;
    background: #ffffff;
    box-shadow: 0 10px 30px rgba(0,0,0,0.18);
    padding: 1rem 1rem 0.9rem 1rem;
    z-index: 5200;
    opacity: 1;
    transform: translateY(0);
    transition: opacity .18s ease, transform .18s ease;
}
.vendas-toast.hide {
    opacity: 0;
    transform: translateY(-8px);
    pointer-events: none;
}
.vendas-toast.alert-success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
.vendas-toast.alert-error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
.vendas-toast.alert-warning { background: #fffbeb; border-color: #fde68a; color: #92400e; }
.vendas-toast-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: .75rem;
}
.vendas-toast-message {
    font-weight: 600;
    line-height: 1.35;
}
.vendas-toast-close {
    background: rgba(0,0,0,0.08);
    border: none;
    color: inherit;
    width: 34px;
    height: 34px;
    border-radius: 10px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    line-height: 1;
}
.vendas-toast-actions {
    margin-top: .75rem;
    display: flex;
    gap: .5rem;
    flex-wrap: wrap;
}
@media (max-width: 768px) {
    .vendas-toast {
        top: 74px;
        right: 12px;
        width: calc(100vw - 24px);
    }
}

.vendas-modal {
    display: none;
    position: fixed !important;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    background: rgba(0,0,0,0.5);
    z-index: 5000 !important; /* acima do sidebar/overlays globais */
    align-items: center;
    justify-content: center;
}

.vendas-modal.active {
    display: flex !important;
}

.vendas-modal-content {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    max-width: 960px;
    width: min(960px, 94vw);
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    z-index: 5001;
}

.modal-header {
    margin-bottom: 1.5rem;
}

.modal-header h2 {
    color: #0f172a;
    font-size: 1.8rem;
    margin-bottom: 0.25rem;
}

.modal-header p {
    color: #64748b;
    margin: 0;
}

.modal-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem 1.25rem;
    margin-bottom: 1.5rem;
}

.modal-field {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #f8fafc;
    padding: 0.85rem 1rem;
}

.modal-field-label {
    display: block;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 0.35rem;
}

.modal-field-value {
    color: #0f172a;
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.modal-secao {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e2e8f0;
}

.modal-secao h3 {
    margin: 0 0 1rem;
    color: #1e3a8a;
    font-size: 1.1rem;
}

.modal-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem 1.25rem;
}

.modal-form-grid > * {
    min-width: 0;
}

.modal-form-grid .full-width {
    grid-column: 1 / -1;
}

.modal-form-grid.single-column {
    grid-template-columns: 1fr;
}

.modal-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
}

.modal-actions .btn {
    min-width: 180px;
}

.vendas-container .form-group,
.vendas-modal-content .form-group {
    margin-bottom: 1.5rem;
}

.vendas-container .cliente-contato-texto,
.vendas-modal-content .cliente-contato-texto {
    color: #475569;
    display: block;
    font-size: 0.875rem;
    margin-top: 0.25rem;
}

.vendas-container .form-group label,
.vendas-modal-content .form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #374151;
}

.vendas-container .form-group input,
.vendas-container .form-group select,
.vendas-container .form-group textarea,
.vendas-modal-content .form-group input,
.vendas-modal-content .form-group select,
.vendas-modal-content .form-group textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font: inherit;
    color: #0f172a;
    background: #fff;
    box-sizing: border-box;
}

.adicionais-table {
    width: 100%;
    margin-top: 1rem;
    border-collapse: collapse;
}

.adicionais-toolbar {
    display: flex;
    justify-content: flex-start;
    margin: 0.85rem 0 0.35rem;
}

.adicionais-toolbar .btn {
    min-width: 0;
    padding: 0.7rem 1rem;
    font-weight: 600;
}

.adicionais-table th,
.adicionais-table td {
    padding: 0.8rem 0.5rem;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: middle;
}

.adicionais-table th {
    color: #334155;
    font-size: 0.95rem;
    font-weight: 700;
}

.adicionais-table td:first-child {
    color: #0f172a;
}

.adicionais-table input[type="text"] {
    min-height: 42px;
}

.vendas-empty-state {
    text-align: center;
    color: #64748b;
    padding: 2rem 1rem;
}
.valor-base-badge {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    background: #eef2ff;
    color: #3730a3;
}

.btn-remove {
    background: #ef4444;
    color: white;
    border: none;
    padding: 0.65rem 0.9rem;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
    min-height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.totais-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem 1.25rem;
    margin-top: 1.5rem;
}

.totais-grid > * {
    min-width: 0;
}

.totais-grid .full-width {
    grid-column: 1 / -1;
}

@media (max-width: 900px) {
    .modal-grid,
    .modal-form-grid,
    .totais-grid {
        grid-template-columns: 1fr;
    }

    .modal-actions .btn {
        width: 100%;
        min-width: 0;
    }
}
</style>

<div class="vendas-container">
    <div class="vendas-header">
        <h1><?php echo $admin_context ? 'Administração de Vendas' : 'Pré-contratos'; ?></h1>
        <p>Gerencie os pré-contratos recebidos dos formulários públicos</p>
    </div>

    <?php if (is_array($vendas_flash)): ?>
        <?php
            $flash_type = (string)($vendas_flash['type'] ?? 'success');
            $flash_class = 'alert-success';
            if ($flash_type === 'error') $flash_class = 'alert-error';
            elseif ($flash_type === 'warning') $flash_class = 'alert-warning';
        ?>
        <div id="vendasToast" class="vendas-toast <?php echo $flash_class; ?>" role="status" aria-live="polite">
            <div class="vendas-toast-header">
                <div class="vendas-toast-message">
                    <?php echo htmlspecialchars((string)($vendas_flash['message'] ?? '')); ?>
                </div>
                <button type="button" class="vendas-toast-close" data-toast-close aria-label="Fechar">×</button>
            </div>
            <div class="vendas-toast-actions">
                <?php if (!empty($vendas_flash['pre_contrato_id'])): ?>
                    <a class="btn btn-primary" href="<?php echo htmlspecialchars($base_query . '&editar=' . (int)$vendas_flash['pre_contrato_id']); ?>">Abrir Pré-contrato</a>
                <?php endif; ?>
                <a class="btn btn-primary" href="index.php?page=vendas_kanban">Ir para o Kanban</a>
            </div>
        </div>
    <?php endif; ?>
    
    <?php foreach ($mensagens as $msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endforeach; ?>
    
    <?php foreach ($erros as $erro): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($erro); ?></div>
    <?php endforeach; ?>

    <?php if ($editar_id && !$pre_contrato_editar): ?>
        <div class="alert alert-error">
            Não foi possível abrir o Pré-contrato #<?php echo (int)$editar_id; ?> para edição. Recarregue e tente novamente.
        </div>
    <?php endif; ?>

    <?php if ($editar_id && $pre_contrato_editar): ?>
        <div class="alert alert-success">
            Modo edição ativo: Pré-contrato #<?php echo (int)$editar_id; ?>.
        </div>
    <?php endif; ?>
    
    <div class="vendas-tabs">
        <?php foreach ($status_tabs as $tab_status => $tab_label): ?>
            <?php
                $tab_url = vendas_build_list_url($base_url, [
                    'status' => $tab_status,
                    'tipo' => (string)$filtro_tipo,
                    'busca' => (string)$busca,
                ]);
                $tab_active = $filtro_status === $tab_status;
            ?>
            <a href="<?php echo htmlspecialchars($tab_url); ?>" class="vendas-tab <?php echo $tab_active ? 'active' : ''; ?>">
                <?php echo htmlspecialchars($tab_label); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="vendas-filters">
        <form method="GET" action="index.php" style="display: flex; gap: 0.5rem; flex: 1; flex-wrap: wrap;">
            <select name="tipo">
                <option value="">Todos os tipos</option>
                <option value="casamento" <?php echo $filtro_tipo === 'casamento' ? 'selected' : ''; ?>>Casamento</option>
                <option value="15anos" <?php echo $filtro_tipo === '15anos' ? 'selected' : ''; ?>>15 Anos</option>
                <option value="infantil" <?php echo $filtro_tipo === 'infantil' ? 'selected' : ''; ?>>Infantil</option>
                <option value="pj" <?php echo $filtro_tipo === 'pj' ? 'selected' : ''; ?>>PJ</option>
            </select>

            <input type="text" name="busca" placeholder="Buscar por nome, email ou CPF..." 
                   value="<?php echo htmlspecialchars($busca); ?>" style="flex: 1;">
            <input type="hidden" name="page" value="<?php echo htmlspecialchars($page_param); ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filtro_status); ?>">
            <button type="submit" class="btn btn-primary">Buscar</button>
            <a href="<?php echo htmlspecialchars(vendas_build_list_url($base_url, ['status' => (string)$filtro_status])); ?>" class="btn btn-secondary">Limpar</a>
        </form>
    </div>
    
    <div class="vendas-card">
        <div class="vendas-table-wrap">
        <table class="vendas-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Cliente</th>
                    <th>Tipo</th>
                    <th>Data Evento</th>
                    <th>Unidade</th>
                    <th>Valor Total</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pre_contratos): ?>
                    <tr>
                        <td colspan="8" class="vendas-empty-state">Nenhum pré-contrato encontrado para os filtros informados.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($pre_contratos as $pc): ?>
                    <tr>
                        <td class="col-id"><?php echo $pc['id']; ?></td>
                        <td class="col-cliente cliente-cell">
                            <strong><?php echo htmlspecialchars((string)($pc['nome_completo'] ?? 'Sem nome')); ?></strong>
                        </td>
                        <td class="col-tipo tipo-cell">
                            <?php
                                $tipo = (string)($pc['tipo_evento'] ?? '');
                                if ($tipo === '15anos') {
                                    echo '15 Anos';
                                } elseif ($tipo === 'pj') {
                                    echo 'PJ';
                                } else {
                                    echo htmlspecialchars(ucfirst($tipo));
                                }
                            ?>
                        </td>
                        <td class="col-data data-cell"><?php echo htmlspecialchars(vendas_format_date_display($pc['data_evento'] ?? null)); ?></td>
                        <td class="col-unidade unidade-cell">
                            <?php
                                $unidade_original = (string)($pc['unidade'] ?? '');
                                $unidade_curta = vendas_unidade_curta($locais_curto_map, $unidade_original);
                                echo htmlspecialchars($unidade_curta);
                                if ($unidade_curta !== $unidade_original && $unidade_original !== ''):
                            ?>
                                <small><?php echo htmlspecialchars($unidade_original); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="col-valor valor-cell">R$ <?php echo number_format($pc['valor_total'] ?? 0, 2, ',', '.'); ?></td>
                        <td class="col-status">
                            <?php
                            $status_meta = vendas_status_meta((string)($pc['status_normalizado'] ?? vendas_normalize_pre_contrato_status($pc)));
                            ?>
                            <span class="status-badge <?php echo htmlspecialchars($status_meta['class']); ?>"><?php echo htmlspecialchars($status_meta['text']); ?></span>
                        </td>
                        <td class="col-acoes acoes-cell">
                            <?php
                                $status_normalizado = (string)($pc['status_normalizado'] ?? vendas_normalize_pre_contrato_status($pc));
                                $abrir_aprovacao = ($is_admin && $status_normalizado === 'pronto_aprovacao') ? '&abrir_aprovacao=1' : '';
                            ?>
                            <a href="<?php echo htmlspecialchars($base_query . '&editar=' . (int)$pc['id'] . $abrir_aprovacao); ?>" class="btn btn-primary" style="font-size: 0.875rem;">
                                Visualizar
                            </a>
                            <?php if ($perm_comercial || $is_admin): ?>
                            <form id="formApagar<?php echo (int)$pc['id']; ?>" method="POST" style="display:inline-block; margin-left:6px;">
                                <input type="hidden" name="action" value="apagar">
                                <input type="hidden" name="pre_contrato_id" value="<?php echo (int)$pc['id']; ?>">
                                <button type="button" class="btn btn-secondary btn-apagar-pc" style="font-size: 0.875rem; background:#dc2626; color:#fff; border-color:#dc2626;" data-id="<?php echo (int)$pc['id']; ?>" data-nome="<?php echo htmlspecialchars($pc['nome_completo'] ?? ''); ?>">Apagar</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    
    <?php if ($pre_contrato_editar): ?>
        <?php
            $observacoes_internas_valor = trim((string)($pre_contrato_editar['observacoes_internas'] ?? ''));
            if ($observacoes_internas_valor === '') {
                $observacoes_internas_valor = '';
            }
            $itens_adicionais_valor = trim((string)($pre_contrato_editar['itens_adicionais'] ?? ''));
            $itens_adicionais_label = 'Itens adicionais';
            $pacote_atual_edicao = trim((string)($pre_contrato_editar['pacote_contratado'] ?? ''));
            $tipo_evento_real_edicao = eventos_reuniao_normalizar_tipo_evento_real((string)($pre_contrato_editar['tipo_evento_real'] ?? ''), $pdo);
            $pacote_evento_id_edicao = (int)($pre_contrato_editar['pacote_evento_id'] ?? 0);
            $pacote_atual_existe = false;
            foreach ($pacotes_evento as $pacote_evento_item) {
                if (trim((string)($pacote_evento_item['nome'] ?? '')) === $pacote_atual_edicao) {
                    $pacote_atual_existe = true;
                    break;
                }
            }
        ?>
        <!-- Modal de Edição -->
        <div class="vendas-modal active" id="modalEditar">
            <div class="vendas-modal-content">
                <div class="modal-header">
                    <h2>Visualizar Pré-contrato #<?php echo $pre_contrato_editar['id']; ?></h2>
                    <p>Revise os dados preenchidos pelo cliente, ajuste o comercial e siga para a aprovação na ME.</p>
                </div>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" id="action_comercial" name="action" value="salvar_comercial">
                    <input type="hidden" name="pre_contrato_id" value="<?php echo $pre_contrato_editar['id']; ?>">
                    <input type="hidden" name="admin_context" value="<?php echo $admin_context ? '1' : '0'; ?>">
                    <input type="hidden" name="idvendedor" id="approve_idvendedor" value="">
                    <input type="hidden" name="override_conflito" id="approve_override_conflito" value="0">
                    <input type="hidden" name="override_motivo" id="approve_override_motivo" value="">
                    <input type="hidden" name="atualizar_cliente_me" id="approve_atualizar_cliente_me" value="manter">

                    <div class="modal-grid">
                        <div class="modal-field">
                            <span class="modal-field-label">Cliente</span>
                            <div class="modal-field-value"><?php echo htmlspecialchars((string)$pre_contrato_editar['nome_completo']); ?></div>
                        </div>
                        <div class="modal-field">
                            <span class="modal-field-label">Data do evento</span>
                            <div class="modal-field-value"><?php echo htmlspecialchars(vendas_format_date_display($pre_contrato_editar['data_evento'] ?? null)); ?></div>
                        </div>
                        <div class="modal-field">
                            <span class="modal-field-label">Telefone</span>
                            <div class="modal-field-value"><?php echo htmlspecialchars($pre_contrato_telefone_exibicao !== '' ? $pre_contrato_telefone_exibicao : 'Não informado'); ?></div>
                        </div>
                        <div class="modal-field">
                            <span class="modal-field-label">Unidade</span>
                            <div class="modal-field-value"><?php echo htmlspecialchars(vendas_unidade_curta($locais_curto_map, $pre_contrato_editar['unidade'] ?? '')); ?></div>
                        </div>
                    </div>

                    <div class="modal-secao">
                        <h3>Dados comerciais</h3>
                        <div class="modal-form-grid single-column">
                            <div class="form-group">
                                <label for="tipo_evento_real">Tipo de evento da organização <?php echo $admin_context ? '<span class="required">*</span>' : ''; ?></label>
                                <select id="tipo_evento_real" name="tipo_evento_real" <?php echo $admin_context ? 'required' : ''; ?>>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($tipos_evento_real_options as $tipo_key => $tipo_label): ?>
                                        <option value="<?php echo htmlspecialchars((string)$tipo_key); ?>" <?php echo $tipo_evento_real_edicao === (string)$tipo_key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)$tipo_label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="pacote_evento_id">Pacote da organização <?php echo $admin_context ? '<span class="required">*</span>' : ''; ?></label>
                                <select id="pacote_evento_id" name="pacote_evento_id" <?php echo $admin_context ? 'required' : ''; ?>>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($pacotes_evento as $pacote_evento): ?>
                                        <?php $pacote_item_id = (int)($pacote_evento['id'] ?? 0); ?>
                                        <option value="<?php echo $pacote_item_id; ?>" <?php echo $pacote_evento_id_edicao === $pacote_item_id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)($pacote_evento['nome'] ?? '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="pacote_contratado">Pacote contratado</label>
                                <input
                                    type="text"
                                    id="pacote_contratado"
                                    name="pacote_contratado"
                                    list="pacotes_evento_lista"
                                    value="<?php echo htmlspecialchars($pre_contrato_editar['pacote_contratado'] ?? ''); ?>"
                                    placeholder="Selecione ou digite o pacote contratado"
                                >
                                <?php if (!empty($pacotes_evento)): ?>
                                    <datalist id="pacotes_evento_lista">
                                        <?php if ($pacote_atual_edicao !== '' && !$pacote_atual_existe): ?>
                                            <option value="<?php echo htmlspecialchars($pacote_atual_edicao); ?>">
                                        <?php endif; ?>
                                        <?php foreach ($pacotes_evento as $pacote_evento): ?>
                                            <option value="<?php echo htmlspecialchars((string)$pacote_evento['nome']); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label for="forma_pagamento_detalhada">Forma de pagamento</label>
                                <textarea id="forma_pagamento_detalhada" name="forma_pagamento_detalhada" rows="3" placeholder="Ex.: Entrada de 30% + saldo em 3x no cartão"><?php echo htmlspecialchars($pre_contrato_editar['forma_pagamento'] ?? ''); ?></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="itens_adicionais"><?php echo htmlspecialchars($itens_adicionais_label); ?></label>
                                <textarea id="itens_adicionais" name="itens_adicionais" rows="3" placeholder="Itens adicionais solicitados pelo cliente"><?php echo htmlspecialchars($itens_adicionais_valor); ?></textarea>
                            </div>

                            <div class="form-group full-width">
                                <label for="observacoes_internas">Observação interna</label>
                                <textarea id="observacoes_internas" name="observacoes_internas" rows="3" placeholder="Observações internas da equipe comercial"><?php echo htmlspecialchars($observacoes_internas_valor); ?></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-secao">
                        <h3>Itens e valores</h3>
                        <small style="display:block;color:#64748b;margin:.2rem 0 .6rem;">Preencha item e valor por linha. O total considera todas as linhas e aplica desconto, se houver.</small>
                        <div class="adicionais-toolbar">
                            <button type="button" class="btn btn-secondary" onclick="adicionarItem()">+ Adicionar linha</button>
                        </div>
                        <table class="adicionais-table" id="tabelaAdicionais">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Valor (R$)</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Pacote/Plano principal</td>
                                    <td>
                                        <input
                                            type="text"
                                            inputmode="numeric"
                                            class="money-input"
                                            id="valor_negociado"
                                            name="valor_negociado"
                                            value="<?php echo htmlspecialchars(vendas_format_money_brl($pre_contrato_editar['valor_negociado'] ?? 0)); ?>"
                                            required
                                        >
                                    </td>
                                    <td><span class="valor-base-badge">Base</span></td>
                                </tr>
                                <?php foreach ($adicionais_editar as $idx => $adicional): ?>
                                    <tr>
                                        <td><input type="text" name="adicionais[<?php echo $idx; ?>][item]" 
                                                   value="<?php echo htmlspecialchars($adicional['item']); ?>" required></td>
                                        <td>
                                            <input
                                                type="text"
                                                inputmode="numeric"
                                                class="money-input"
                                                name="adicionais[<?php echo $idx; ?>][valor]"
                                                value="<?php echo htmlspecialchars(vendas_format_money_brl($adicional['valor'])); ?>"
                                                required
                                            >
                                        </td>
                                        <td><button type="button" class="btn-remove" onclick="removerItem(this)">Remover</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="totais-grid">
                            <div class="form-group">
                                <label for="desconto">Desconto aplicado (R$)</label>
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    class="money-input"
                                    id="desconto"
                                    name="desconto"
                                    value="<?php echo htmlspecialchars(vendas_format_money_brl($pre_contrato_editar['desconto'] ?? 0)); ?>"
                                >
                            </div>

                            <div class="form-group">
                                <label>Subtotal das linhas</label>
                                <input type="text" id="valor_subtotal_display" value="R$ 0,00" disabled>
                            </div>

                            <div class="form-group">
                                <label>Desconto</label>
                                <input type="text" id="desconto_display" value="R$ 0,00" disabled>
                            </div>
                            
                            <div class="form-group">
                                <label>Total final</label>
                                <input type="text" id="valor_total_display" value="R$ 0,00" disabled style="font-weight: bold; font-size: 1.2rem;">
                            </div>
                            
                            <div class="form-group full-width">
                                <label for="anexo_orcamento">Orçamento/Proposta (PDF, DOC, etc)</label>
                                <input type="file" id="anexo_orcamento" name="anexo_orcamento" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($anexos_editar)): ?>
                        <div class="form-group">
                            <label>Anexos existentes:</label>
                            <ul>
                                <?php foreach ($anexos_editar as $anexo): ?>
                                    <li>
                                        <a href="<?php echo htmlspecialchars($anexo['url'] ?? '#'); ?>" target="_blank">
                                            <?php echo htmlspecialchars($anexo['nome_original']); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <div class="modal-actions">
                        <button type="submit" class="btn btn-success">Salvar Dados Comerciais</button>
                        <button type="button" class="btn btn-secondary" onclick="fecharModalEditar()">Cancelar</button>
                        
                        <?php if ($is_admin && in_array((string)($pre_contrato_editar['status'] ?? ''), ['pronto_aprovacao', 'aguardando_conferencia', ''], true)): ?>
                            <button type="button" class="btn btn-primary" onclick="abrirModalAprovacao()">Aprovar e Criar na ME</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Modal de Aprovação -->
        <?php
            $aprovacao_result = $_SESSION['vendas_aprovacao_result'] ?? null;
            if ($aprovacao_result !== null) { unset($_SESSION['vendas_aprovacao_result']); }
            $show_aprovacao_modal = ($is_admin && (in_array((string)($pre_contrato_editar['status'] ?? ''), ['pronto_aprovacao', 'aguardando_conferencia', ''], true) || is_array($aprovacao_result)));
        ?>
        <?php if ($show_aprovacao_modal): ?>
        <div class="vendas-modal" id="modalAprovacao">
            <div class="vendas-modal-content">
                <h2>Aprovar e Criar na ME</h2>

                <?php if (is_array($aprovacao_result)): ?>
                    <?php if (!empty($aprovacao_result['ok'])): ?>
                        <div class="alert alert-success">
                            <strong>Sucesso:</strong> <?php echo htmlspecialchars((string)($aprovacao_result['message'] ?? '')); ?><br>
                            Cliente ME: <strong><?php echo (int)($aprovacao_result['me_client_id'] ?? 0); ?></strong> —
                            Evento ME: <strong><?php echo (int)($aprovacao_result['me_event_id'] ?? 0); ?></strong><br>
                            Kanban: <?php echo !empty($aprovacao_result['kanban_card_created']) ? 'Card criado' : 'Card não criado'; ?>
                            <?php if (!empty($aprovacao_result['kanban_card_id'])): ?>
                                (ID <?php echo (int)$aprovacao_result['kanban_card_id']; ?>)
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-error">
                            <strong>Falhou:</strong> <?php echo htmlspecialchars((string)($aprovacao_result['message'] ?? '')); ?><br>
                            <?php if (!empty($aprovacao_result['error'])): ?>
                                <div style="margin-top:.5rem;"><?php echo htmlspecialchars((string)$aprovacao_result['error']); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($aprovacao_result['me_last']) && is_array($aprovacao_result['me_last'])): ?>
                        <details style="margin: .75rem 0;">
                            <summary style="cursor:pointer; color:#1e3a8a; font-weight:600;">Resposta da ME (detalhes)</summary>
                            <pre style="white-space:pre-wrap; background:#0b1220; color:#e5e7eb; padding: .75rem; border-radius: 10px; margin-top:.5rem; max-height: 320px; overflow:auto;"><?php echo htmlspecialchars(json_encode($aprovacao_result['me_last'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php 
                // Verificar conflito antes de mostrar modal
                $conflito_verificar = null;
                if ($pre_contrato_editar && $pre_contrato_editar['status'] === 'pronto_aprovacao') {
                    try {
                        $conflito_verificar = vendas_me_verificar_conflito_agenda(
                            $pre_contrato_editar['data_evento'],
                            $pre_contrato_editar['unidade'],
                            $pre_contrato_editar['horario_inicio'],
                            $pre_contrato_editar['horario_termino']
                        );
                    } catch (Exception $e) {
                        error_log('Erro ao verificar conflito: ' . $e->getMessage());
                    }
                }
                
                $conflito_detalhes = $_SESSION['vendas_conflito_detalhes'] ?? $conflito_verificar;
                unset($_SESSION['vendas_conflito_detalhes']);
                ?>
                
                <?php if (!empty($conflito_detalhes) && !empty($conflito_detalhes['tem_conflito'])): ?>
                    <div class="alert alert-warning">
                        <strong>Conflito de agenda detectado!</strong><br>
                        Existem eventos na mesma unidade e data que não respeitam a distância mínima de 
                        <?php echo htmlspecialchars((string)($conflito_detalhes['distancia_minima_horas'] ?? '')); ?> horas.
                        <ul style="margin-top: 0.5rem;">
                            <?php foreach (($conflito_detalhes['conflitos'] ?? []) as $conflito): ?>
                                <li>
                                    Evento: <?php echo htmlspecialchars((string)($conflito['evento']['nomeevento'] ?? $conflito['evento']['nome_evento'] ?? 'N/A')); ?> - 
                                    <?php echo htmlspecialchars((string)($conflito['evento']['horaevento'] ?? $conflito['evento']['hora_inicio'] ?? '')); ?> às 
                                    <?php echo htmlspecialchars((string)($conflito['evento']['horaeventofim'] ?? $conflito['evento']['hora_termino'] ?? '')); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="override_conflito" value="1" id="override_conflito">
                            Forçar criação (override) - Ignorar conflito de agenda
                        </label>
                    </div>
                    
                    <div class="form-group" id="div_motivo_override" style="display: none;">
                        <label for="override_motivo">Motivo do override <span style="color: #ef4444;">*</span>:</label>
                        <textarea id="override_motivo" name="override_motivo" rows="3" required></textarea>
                        <small style="color: #6b7280;">É obrigatório informar o motivo para forçar a criação com conflito de agenda.</small>
                    </div>
                <?php endif; ?>

                <?php
                    // Vendedores (ME) - dropdown obrigatório na aprovação
                    $vendedores_me = [];
                    try {
                        $vendedores_me = vendas_me_listar_vendedores();
                    } catch (Throwable $e) {
                        $vendedores_me = [];
                    }
                ?>
                <div class="form-group">
                    <label for="idvendedor_select">Vendedor (ME) <span style="color: #ef4444;">*</span>:</label>
                    <?php if (!empty($vendedores_me)): ?>
                        <select id="idvendedor_select" name="idvendedor" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($vendedores_me as $v): ?>
                                <option value="<?= (int)($v['id'] ?? 0) ?>">
                                    <?= htmlspecialchars((string)($v['nome'] ?? '')) ?> (<?= (int)($v['id'] ?? 0) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:#6b7280;">Este vendedor será enviado como <code>idvendedor</code> na criação do evento na ME.</small>
                    <?php else: ?>
                        <input type="number" id="idvendedor_select" name="idvendedor" min="1" step="1" placeholder="ID do vendedor na ME" required>
                        <small style="color:#6b7280;">Não foi possível listar vendedores da ME agora. Informe o ID manualmente.</small>
                    <?php endif; ?>
                </div>
                
                <?php
                // Verificar duplicidade de cliente
                $clientes_encontrados = vendas_me_buscar_cliente(
                    $pre_contrato_editar['cpf'] ?? '',
                    $pre_contrato_editar['email'] ?? '',
                    $pre_contrato_editar['telefone'] ?? '',
                    $pre_contrato_editar['nome_completo'] ?? ''
                );
                
                $cliente_duplicado = null;
                foreach ($clientes_encontrados as $match) {
                    if ($match['match_type'] === 'cpf' && $match['match_strength'] === 'forte') {
                        $cliente_duplicado = $match['cliente'];
                        break;
                    }
                }
                
                if ($cliente_duplicado):
                    $divergencias = [];
                    if (strtolower(trim($cliente_duplicado['nome'] ?? '')) !== strtolower(trim($pre_contrato_editar['nome_completo']))) {
                        $divergencias[] = 'Nome';
                    }
                    if (strtolower(trim($cliente_duplicado['email'] ?? '')) !== strtolower(trim($pre_contrato_editar['email']))) {
                        $divergencias[] = 'E-mail';
                    }
                    if (preg_replace('/\D/', '', $cliente_duplicado['telefone'] ?? '') !== preg_replace('/\D/', '', $pre_contrato_editar['telefone'] ?? '')) {
                        $divergencias[] = 'Telefone';
                    }
                ?>
                    <div class="alert alert-warning">
                        <strong>Cliente duplicado detectado na ME!</strong><br>
                        Cliente encontrado por CPF: <?php echo htmlspecialchars($cliente_duplicado['nome'] ?? 'N/A'); ?><br>
                        <?php if (!empty($divergencias)): ?>
                            <strong>Divergências detectadas:</strong> <?php echo implode(', ', $divergencias); ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Como atualizar dados do cliente?</label>
                        <select name="atualizar_cliente_me" id="atualizar_cliente_me">
                            <option value="manter">Manter dados atuais da ME</option>
                            <option value="atualizar">Atualizar dados na ME com os novos</option>
                            <option value="apenas_painel">Atualizar apenas no Painel (não mexer na ME)</option>
                        </select>
                    </div>
                <?php endif; ?>
                
                <?php if ($pre_contrato_editar['status'] === 'pronto_aprovacao' || $pre_contrato_editar['status'] === 'aguardando_conferencia' || $pre_contrato_editar['status'] === ''): ?>
                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="button" class="btn btn-success" onclick="submeterAprovacao()">Confirmar Aprovação</button>
                        <button type="button" class="btn btn-secondary" onclick="fecharModalAprovacao()">Fechar</button>
                    </div>
                <?php else: ?>
                    <div style="display:flex; gap:1rem; margin-top:1.25rem; justify-content:flex-end;">
                        <a class="btn btn-primary" href="index.php?page=vendas_kanban">Ir para o Kanban</a>
                        <button type="button" class="btn btn-secondary" onclick="fecharModalAprovacao()">Fechar</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
let itemIndex = <?php echo count($adicionais_editar); ?>;

function formatMoneyFromDigits(digits) {
    if (!digits) return '';
    const cents = digits.slice(-2).padStart(2, '0');
    let ints = digits.slice(0, -2);
    ints = ints.replace(/^0+(?=\d)/, '');
    if (!ints) ints = '0';
    ints = ints.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${ints},${cents}`;
}

function parseMoneyValue(value) {
    const raw = String(value || '').trim();
    if (!raw) return 0;
    const normalized = raw.replace(/\./g, '').replace(',', '.').replace(/[^\d.-]/g, '');
    const num = parseFloat(normalized);
    return Number.isFinite(num) ? num : 0;
}

function formatMoneyDisplay(value) {
    const num = Number.isFinite(value) ? value : 0;
    return 'R$ ' + num.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function bindMoneyInput(el) {
    if (!el || el.dataset.moneyBound === '1') return;
    el.dataset.moneyBound = '1';
    el.addEventListener('input', function() {
        const digits = (this.value || '').replace(/\D/g, '');
        this.value = formatMoneyFromDigits(digits);
        calcularTotal();
    });
    el.addEventListener('blur', function() {
        const digits = (this.value || '').replace(/\D/g, '');
        this.value = digits ? formatMoneyFromDigits(digits) : '0,00';
        calcularTotal();
    });
}

function initMoneyInputs(scope) {
    (scope || document).querySelectorAll('.money-input').forEach(bindMoneyInput);
}

function adicionarItem() {
    const tbody = document.querySelector('#tabelaAdicionais tbody');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><input type="text" name="adicionais[${itemIndex}][item]" required></td>
        <td><input type="text" inputmode="numeric" class="money-input" name="adicionais[${itemIndex}][valor]" required></td>
        <td><button type="button" class="btn-remove" onclick="removerItem(this)">Remover</button></td>
    `;
    tbody.appendChild(row);
    initMoneyInputs(row);
    itemIndex++;
    calcularTotal();
}

function removerItem(btn) {
    btn.closest('tr').remove();
    calcularTotal();
}

function calcularTotal() {
    const valorNegociado = parseMoneyValue(document.getElementById('valor_negociado')?.value || 0);
    const desconto = parseMoneyValue(document.getElementById('desconto')?.value || 0);
    
    let totalAdicionais = 0;
    document.querySelectorAll('#tabelaAdicionais input[name*="[valor]"]').forEach(input => {
        totalAdicionais += parseMoneyValue(input.value);
    });
    
    const subtotal = valorNegociado + totalAdicionais;
    const total = subtotal - desconto;
    document.getElementById('valor_subtotal_display').value = formatMoneyDisplay(subtotal);
    document.getElementById('desconto_display').value = formatMoneyDisplay(desconto);
    document.getElementById('valor_total_display').value = formatMoneyDisplay(total);
}

function abrirModalAprovacao() {
    document.getElementById('modalAprovacao').classList.add('active');
}

function fecharModalAprovacao() {
    document.getElementById('modalAprovacao').classList.remove('active');
}

// Fechar modal de edição
function fecharModalEditar() {
    const m = document.getElementById('modalEditar');
    if (!m) return;
    m.classList.remove('active');
    // Voltar para a listagem mantendo filtros/contexto
    try {
        const params = new URLSearchParams(window.location.search);
        params.delete('editar');
        params.delete('abrir_aprovacao');
        const next = 'index.php?' + params.toString();
        window.location.href = next;
    } catch (e) {
        window.location.href = '<?php echo htmlspecialchars($base_query); ?>';
    }
}

// Garantir que os modais fiquem no topo do DOM (evita conflitos de stacking context do layout)
(function() {
    try {
        const modalEditar = document.getElementById('modalEditar');
        if (modalEditar && modalEditar.parentElement !== document.body) {
            document.body.appendChild(modalEditar);
            modalEditar.classList.add('active');
            modalEditar.style.display = 'flex';
        }
        const modalAprovacao = document.getElementById('modalAprovacao');
        if (modalAprovacao && modalAprovacao.parentElement !== document.body) {
            document.body.appendChild(modalAprovacao);
        }
    } catch (e) {}
})();

// No admin, se veio do botão "Editar" em um card pronto, abrir o modal automaticamente
(function() {
    try {
        const params = new URLSearchParams(window.location.search);
        if (params.get('abrir_aprovacao') === '1' && document.getElementById('modalAprovacao')) {
            abrirModalAprovacao();
        }
    } catch (e) {}
})();

// Toast/Balão do flash (sucesso/erro) - não quebra layout
(function() {
    try {
        const toast = document.getElementById('vendasToast');
        if (!toast) return;

        // Garantir que não será "cortado" por containers
        if (toast.parentElement !== document.body) {
            document.body.appendChild(toast);
        }

        const closeBtn = toast.querySelector('[data-toast-close]');
        const hide = () => {
            toast.classList.add('hide');
            window.setTimeout(() => toast.remove(), 250);
        };

        closeBtn?.addEventListener('click', hide);
        window.setTimeout(hide, 8000);
    } catch (e) {}
})();

document.getElementById('override_conflito')?.addEventListener('change', function() {
    const divMotivo = document.getElementById('div_motivo_override');
    const inputMotivo = document.getElementById('override_motivo');
    const inputOverride = document.getElementById('approve_override_conflito');
    
    if (this.checked) {
        divMotivo.style.display = 'block';
        inputOverride.value = '1';
        inputMotivo.required = true;
    } else {
        divMotivo.style.display = 'none';
        inputOverride.value = '0';
        inputMotivo.required = false;
        inputMotivo.value = '';
    }
});

document.getElementById('override_motivo')?.addEventListener('input', function() {
    document.getElementById('approve_override_motivo').value = this.value;
});

function submeterAprovacao() {
    const overrideCheckbox = document.getElementById('override_conflito');
    const motivoTextarea = document.getElementById('override_motivo');
    const vendedor = document.getElementById('idvendedor_select');
    const actionInput = document.getElementById('action_comercial');
    const formComercial = actionInput ? actionInput.form : null;

    if (overrideCheckbox && overrideCheckbox.checked && (!motivoTextarea || !motivoTextarea.value.trim())) {
        alert('Por favor, informe o motivo do override para continuar.');
        return;
    }

    if (vendedor && !String(vendedor.value || '').trim()) {
        alert('Selecione o vendedor (ME) para continuar.');
        vendedor.focus();
        return;
    }

    if (!formComercial || !actionInput) {
        return;
    }

    actionInput.value = 'aprovar_criar_me';
    document.getElementById('approve_idvendedor').value = vendedor ? String(vendedor.value || '') : '';
    document.getElementById('approve_override_conflito').value = overrideCheckbox?.checked ? '1' : '0';
    document.getElementById('approve_override_motivo').value = motivoTextarea ? String(motivoTextarea.value || '') : '';
    document.getElementById('approve_atualizar_cliente_me').value = document.getElementById('atualizar_cliente_me')?.value || 'manter';
    formComercial.submit();
}

document.getElementById('atualizar_cliente_me')?.addEventListener('change', function() {
    document.getElementById('approve_atualizar_cliente_me').value = this.value;
});

function confirmarApagar(id, nome) {
    var msg = 'Tem certeza que deseja apagar o pré-contrato de "' + (nome || '') + '"? Esta ação não pode ser desfeita.';
    if (typeof customConfirm === 'function') {
        customConfirm(msg).then(function(ok) { if (ok) document.getElementById('formApagar' + id).submit(); });
    } else if (confirm(msg)) {
        document.getElementById('formApagar' + id).submit();
    }
}
document.querySelectorAll('.btn-apagar-pc').forEach(function(btn) {
    btn.addEventListener('click', function() {
        confirmarApagar(parseInt(this.getAttribute('data-id'), 10), this.getAttribute('data-nome') || '');
    });
});

// Calcular total inicial
initMoneyInputs();
calcularTotal();
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Comercial');
echo $conteudo;
endSidebar();
