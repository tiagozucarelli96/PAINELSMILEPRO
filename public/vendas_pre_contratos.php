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
require_once __DIR__ . '/upload_magalu.php';

// Verificar permissões
if (empty($_SESSION['logado']) || empty($_SESSION['perm_comercial'])) {
    header('Location: index.php?page=login');
    exit;
}

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)($_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? 0);
$is_admin = vendas_is_admin(); // Usar função centralizada
$perm_comercial = !empty($_SESSION['perm_comercial']);
$admin_context = !empty($_GET['admin']) || (!empty($_POST['admin_context']) && $_POST['admin_context'] === '1');

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
    includeSidebar('Comercial');
    echo '<div style="padding:2rem;max-width:1100px;margin:0 auto;">';
    foreach ($erros as $e) {
        echo '<div class="alert alert-error">' . htmlspecialchars((string)$e) . '</div>';
    }
    echo '<div class="alert alert-error">Base de Vendas ausente/desatualizada. Execute os SQLs <code>sql/041_modulo_vendas.sql</code> e <code>sql/042_vendas_ajustes.sql</code>.</div>';
    echo '</div>';
    endSidebar();
    exit;
}

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'salvar_comercial') {
        $pre_contrato_id = (int)($_POST['pre_contrato_id'] ?? 0);
        $pacote = trim($_POST['pacote_contratado'] ?? '');
        $valor_negociado = (float)($_POST['valor_negociado'] ?? 0);
        $desconto = (float)($_POST['desconto'] ?? 0);
        
        // Buscar adicionais
        $adicionais = [];
        if (!empty($_POST['adicionais'])) {
            foreach ($_POST['adicionais'] as $adicional) {
                if (!empty($adicional['item']) && !empty($adicional['valor'])) {
                    $adicionais[] = [
                        'item' => trim($adicional['item']),
                        'valor' => (float)$adicional['valor']
                    ];
                }
            }
        }
        
        // Calcular total
        $total_adicionais = array_sum(array_column($adicionais, 'valor'));
        $valor_total = $valor_negociado + $total_adicionais - $desconto;
        
        try {
            $pdo->beginTransaction();
            
            // Atualizar pré-contrato
            $stmt = $pdo->prepare("
                UPDATE vendas_pre_contratos 
                SET pacote_contratado = ?, valor_negociado = ?, desconto = ?, valor_total = ?,
                    atualizado_em = NOW(), atualizado_por = ?, status = 'pronto_aprovacao',
                    responsavel_comercial_id = COALESCE(responsavel_comercial_id, ?)
                WHERE id = ?
            ");
            $stmt->execute([$pacote, $valor_negociado, $desconto, $valor_total, $usuario_id, $usuario_id, $pre_contrato_id]);
            
            // Remover adicionais antigos
            $stmt_del = $pdo->prepare("DELETE FROM vendas_adicionais WHERE pre_contrato_id = ?");
            $stmt_del->execute([$pre_contrato_id]);
            
            // Inserir novos adicionais
            $stmt_add = $pdo->prepare("INSERT INTO vendas_adicionais (pre_contrato_id, item, valor) VALUES (?, ?, ?)");
            foreach ($adicionais as $adicional) {
                $stmt_add->execute([$pre_contrato_id, $adicional['item'], $adicional['valor']]);
            }
            
            // Upload de anexo (orçamento)
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
            
            // Log
            $stmt_log = $pdo->prepare("INSERT INTO vendas_logs (pre_contrato_id, acao, usuario_id, detalhes) VALUES (?, 'dados_comerciais_salvos', ?, ?)");
            $stmt_log->execute([$pre_contrato_id, $usuario_id, json_encode(['valor_total' => $valor_total])]);
            
            $pdo->commit();
            $mensagens[] = 'Dados comerciais salvos com sucesso!';
            
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
        if (!vendas_is_admin()) {
            $erros[] = 'Apenas administradores podem aprovar e criar na ME';
        } elseif (!$admin_context) {
            $erros[] = 'Aprovação disponível apenas em Vendas > Administração.';
        } else {
        $pre_contrato_id = (int)($_POST['pre_contrato_id'] ?? 0);
        $idvendedor = (int)($_POST['idvendedor'] ?? 0);
        $override_conflito = isset($_POST['override_conflito']) && $_POST['override_conflito'] === '1';
        $override_motivo = trim($_POST['override_motivo'] ?? '');
        $atualizar_cliente_me = $_POST['atualizar_cliente_me'] ?? 'manter'; // manter, atualizar, apenas_painel

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
                            'observacao' => (string)($pre_contrato['observacoes'] ?? '')
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

$where = [];
$params = [];

if ($filtro_status) {
    $where[] = "status = ?";
    $params[] = $filtro_status;
}

if ($filtro_tipo) {
    $where[] = "tipo_evento = ?";
    $params[] = $filtro_tipo;
}

if ($busca) {
    $where[] = "(nome_completo ILIKE ? OR email ILIKE ? OR cpf ILIKE ?)";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
    $params[] = "%$busca%";
}

$sql = "SELECT v.*, 
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

// Base de URL para manter o contexto correto (listagem vs administração)
$page_param = $admin_context ? 'vendas_administracao' : 'vendas_pre_contratos';
$base_url = 'index.php?page=' . $page_param;
$base_query = $base_url
    . '&status=' . urlencode((string)$filtro_status)
    . '&tipo=' . urlencode((string)$filtro_tipo)
    . '&busca=' . urlencode((string)$busca);

// Buscar pré-contrato específico para edição
$editar_id = (int)($_GET['editar'] ?? 0);
$pre_contrato_editar = null;
$adicionais_editar = [];
$anexos_editar = [];

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
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
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
}

.vendas-filters select,
.vendas-filters input {
    padding: 0.5rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
}

.vendas-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.08);
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
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
}

.vendas-table th {
    background: #f9fafb;
    font-weight: 600;
    color: #374151;
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
.vendas-toast .btn {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 500;
    text-decoration: none;
    display: inline-block;
}

.vendas-container .btn-primary,
.vendas-toast .btn-primary { background: #2563eb; color: white; }
.vendas-container .btn-success,
.vendas-toast .btn-success { background: #16a34a; color: white; }
.vendas-container .btn-danger,
.vendas-toast .btn-danger { background: #ef4444; color: white; }
.vendas-container .btn-secondary,
.vendas-toast .btn-secondary { background: #6b7280; color: white; }

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
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    position: relative;
    z-index: 5001;
}

.vendas-container .form-group {
    margin-bottom: 1.5rem;
}

.vendas-container .form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #374151;
}

.vendas-container .form-group input,
.vendas-container .form-group select,
.vendas-container .form-group textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
}

.adicionais-table {
    width: 100%;
    margin-top: 1rem;
}

.adicionais-table th,
.adicionais-table td {
    padding: 0.5rem;
    border-bottom: 1px solid #e5e7eb;
}

.btn-remove {
    background: #ef4444;
    color: white;
    border: none;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    cursor: pointer;
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
    
    <div class="vendas-filters">
        <select name="filtro_status" onchange="window.location.href='<?php echo htmlspecialchars($base_url); ?>&status='+encodeURIComponent(this.value)+'&tipo=<?php echo htmlspecialchars(urlencode((string)$filtro_tipo)); ?>&busca=<?php echo htmlspecialchars(urlencode((string)$busca)); ?>'">
            <option value="">Todos os status</option>
            <option value="aguardando_conferencia" <?php echo $filtro_status === 'aguardando_conferencia' ? 'selected' : ''; ?>>Aguardando conferência</option>
            <option value="pronto_aprovacao" <?php echo $filtro_status === 'pronto_aprovacao' ? 'selected' : ''; ?>>Pronto para aprovação</option>
            <option value="aprovado_criado_me" <?php echo $filtro_status === 'aprovado_criado_me' ? 'selected' : ''; ?>>Aprovado / Criado na ME</option>
            <option value="cancelado_nao_fechou" <?php echo $filtro_status === 'cancelado_nao_fechou' ? 'selected' : ''; ?>>Cancelado / Não fechou</option>
        </select>
        
        <select name="filtro_tipo" onchange="window.location.href='<?php echo htmlspecialchars($base_url); ?>&status=<?php echo htmlspecialchars(urlencode((string)$filtro_status)); ?>&tipo='+encodeURIComponent(this.value)+'&busca=<?php echo htmlspecialchars(urlencode((string)$busca)); ?>'">
            <option value="">Todos os tipos</option>
            <option value="casamento" <?php echo $filtro_tipo === 'casamento' ? 'selected' : ''; ?>>Casamento</option>
            <option value="15anos" <?php echo $filtro_tipo === '15anos' ? 'selected' : ''; ?>>15 Anos</option>
            <option value="infantil" <?php echo $filtro_tipo === 'infantil' ? 'selected' : ''; ?>>Infantil</option>
            <option value="pj" <?php echo $filtro_tipo === 'pj' ? 'selected' : ''; ?>>PJ</option>
        </select>
        
        <form method="GET" style="display: flex; gap: 0.5rem; flex: 1;">
            <input type="text" name="busca" placeholder="Buscar por nome, email ou CPF..." 
                   value="<?php echo htmlspecialchars($busca); ?>" style="flex: 1;">
            <input type="hidden" name="page" value="<?php echo htmlspecialchars($page_param); ?>">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filtro_status); ?>">
            <input type="hidden" name="tipo" value="<?php echo htmlspecialchars($filtro_tipo); ?>">
            <button type="submit" class="btn btn-primary">Buscar</button>
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
                <?php foreach ($pre_contratos as $pc): ?>
                    <tr>
                        <td><?php echo $pc['id']; ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars($pc['nome_completo']); ?></strong><br>
                            <small style="color: #6b7280;"><?php echo htmlspecialchars($pc['email']); ?></small>
                        </td>
                        <td>
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
                        <td><?php echo date('d/m/Y', strtotime($pc['data_evento'])); ?></td>
                        <td><?php echo htmlspecialchars($pc['unidade']); ?></td>
                        <td>R$ <?php echo number_format($pc['valor_total'] ?? 0, 2, ',', '.'); ?></td>
                        <td>
                            <?php
                            $status_class = 'status-aguardando';
                            $status_text = 'Aguardando conferência';
                            $st = (string)($pc['status'] ?? '');
                            // Robustez: se já tem ME IDs, considerar aprovado mesmo que status tenha ficado divergente
                            if (($st === '' || $st === 'aguardando_conferencia' || $st === 'pronto_aprovacao') && !empty($pc['me_event_id'])) {
                                $st = 'aprovado_criado_me';
                            }
                            if ($st === 'pronto_aprovacao') {
                                $status_class = 'status-pronto';
                                $status_text = 'Pronto para aprovação';
                            } elseif ($st === 'aprovado_criado_me') {
                                $status_class = 'status-aprovado';
                                $status_text = 'Aprovado / Criado na ME';
                            } elseif ($st === 'cancelado_nao_fechou') {
                                $status_class = 'status-cancelado';
                                $status_text = 'Cancelado / Não fechou';
                            }
                            ?>
                            <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                        </td>
                        <td>
                            <?php
                                $abrir_aprovacao = ($admin_context && $is_admin && ($pc['status'] ?? '') === 'pronto_aprovacao') ? '&abrir_aprovacao=1' : '';
                            ?>
                            <a href="<?php echo htmlspecialchars($base_query . '&editar=' . (int)$pc['id'] . $abrir_aprovacao); ?>" class="btn btn-primary" style="font-size: 0.875rem;">
                                Editar
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
        <!-- Modal de Edição -->
        <div class="vendas-modal active" id="modalEditar">
            <div class="vendas-modal-content">
                <h2>Editar Pré-contrato #<?php echo $pre_contrato_editar['id']; ?></h2>
                
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="salvar_comercial">
                    <input type="hidden" name="pre_contrato_id" value="<?php echo $pre_contrato_editar['id']; ?>">
                    
                    <div class="form-group">
                        <label>Cliente:</label>
                        <input type="text" value="<?php echo htmlspecialchars($pre_contrato_editar['nome_completo']); ?>" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label>Data do Evento:</label>
                        <input type="text" value="<?php echo date('d/m/Y', strtotime($pre_contrato_editar['data_evento'])); ?>" disabled>
                    </div>
                    
                    <div class="form-group">
                        <label for="pacote_contratado">Pacote Contratado:</label>
                        <input type="text" id="pacote_contratado" name="pacote_contratado" 
                               value="<?php echo htmlspecialchars($pre_contrato_editar['pacote_contratado'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="valor_negociado">Valor Negociado (R$):</label>
                        <input type="number" id="valor_negociado" name="valor_negociado" step="0.01" 
                               value="<?php echo $pre_contrato_editar['valor_negociado'] ?? 0; ?>" 
                               onchange="calcularTotal()">
                    </div>
                    
                    <div class="form-group">
                        <label for="desconto">Desconto (R$):</label>
                        <input type="number" id="desconto" name="desconto" step="0.01" 
                               value="<?php echo $pre_contrato_editar['desconto'] ?? 0; ?>" 
                               onchange="calcularTotal()">
                    </div>
                    
                    <div class="form-group">
                        <label>Adicionais:</label>
                        <button type="button" class="btn btn-secondary" onclick="adicionarItem()">+ Adicionar Item</button>
                        <table class="adicionais-table" id="tabelaAdicionais">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Valor (R$)</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adicionais_editar as $idx => $adicional): ?>
                                    <tr>
                                        <td><input type="text" name="adicionais[<?php echo $idx; ?>][item]" 
                                                   value="<?php echo htmlspecialchars($adicional['item']); ?>" required></td>
                                        <td><input type="number" name="adicionais[<?php echo $idx; ?>][valor]" 
                                                   step="0.01" value="<?php echo $adicional['valor']; ?>" 
                                                   onchange="calcularTotal()" required></td>
                                        <td><button type="button" class="btn-remove" onclick="removerItem(this)">Remover</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="form-group">
                        <label>Valor Total:</label>
                        <input type="text" id="valor_total_display" value="R$ 0,00" disabled style="font-weight: bold; font-size: 1.2rem;">
                    </div>
                    
                    <div class="form-group">
                        <label for="anexo_orcamento">Orçamento/Proposta (PDF, DOC, etc):</label>
                        <input type="file" id="anexo_orcamento" name="anexo_orcamento" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
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
                    
                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="submit" class="btn btn-success">Salvar Dados Comerciais</button>
                        <button type="button" class="btn btn-secondary" onclick="fecharModalEditar()">Cancelar</button>
                        
                        <?php if ($is_admin && $admin_context && $pre_contrato_editar['status'] === 'pronto_aprovacao'): ?>
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
            $show_aprovacao_modal = ($is_admin && $admin_context && ($pre_contrato_editar['status'] === 'pronto_aprovacao' || is_array($aprovacao_result)));
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
                        <select id="idvendedor_select" name="idvendedor" form="formAprovacao" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($vendedores_me as $v): ?>
                                <option value="<?= (int)($v['id'] ?? 0) ?>">
                                    <?= htmlspecialchars((string)($v['nome'] ?? '')) ?> (<?= (int)($v['id'] ?? 0) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color:#6b7280;">Este vendedor será enviado como <code>idvendedor</code> na criação do evento na ME.</small>
                    <?php else: ?>
                        <input type="number" id="idvendedor_select" name="idvendedor" form="formAprovacao" min="1" step="1" placeholder="ID do vendedor na ME" required>
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
                
                <?php if ($pre_contrato_editar['status'] === 'pronto_aprovacao'): ?>
                    <form method="POST" id="formAprovacao">
                        <input type="hidden" name="action" value="aprovar_criar_me">
                        <input type="hidden" name="pre_contrato_id" value="<?php echo $pre_contrato_editar['id']; ?>">
                        <input type="hidden" name="admin_context" value="1">
                        <input type="hidden" name="override_conflito" id="input_override_conflito" value="0">
                        <input type="hidden" name="override_motivo" id="input_override_motivo" value="">
                        <input type="hidden" name="atualizar_cliente_me" id="input_atualizar_cliente_me" value="manter">
                        
                        <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                            <button type="submit" class="btn btn-success">Confirmar Aprovação</button>
                            <button type="button" class="btn btn-secondary" onclick="fecharModalAprovacao()">Fechar</button>
                        </div>
                    </form>
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

function adicionarItem() {
    const tbody = document.querySelector('#tabelaAdicionais tbody');
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><input type="text" name="adicionais[${itemIndex}][item]" required></td>
        <td><input type="number" name="adicionais[${itemIndex}][valor]" step="0.01" onchange="calcularTotal()" required></td>
        <td><button type="button" class="btn-remove" onclick="removerItem(this)">Remover</button></td>
    `;
    tbody.appendChild(row);
    itemIndex++;
}

function removerItem(btn) {
    btn.closest('tr').remove();
    calcularTotal();
}

function calcularTotal() {
    const valorNegociado = parseFloat(document.getElementById('valor_negociado').value || 0);
    const desconto = parseFloat(document.getElementById('desconto').value || 0);
    
    let totalAdicionais = 0;
    document.querySelectorAll('#tabelaAdicionais input[name*="[valor]"]').forEach(input => {
        totalAdicionais += parseFloat(input.value || 0);
    });
    
    const total = valorNegociado + totalAdicionais - desconto;
    document.getElementById('valor_total_display').value = 'R$ ' + total.toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
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
    const inputOverride = document.getElementById('input_override_conflito');
    
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
    document.getElementById('input_override_motivo').value = this.value;
});

// Validar formulário de aprovação
document.getElementById('formAprovacao')?.addEventListener('submit', function(e) {
    const overrideCheckbox = document.getElementById('override_conflito');
    const motivoTextarea = document.getElementById('override_motivo');
    
    if (overrideCheckbox && overrideCheckbox.checked && (!motivoTextarea || !motivoTextarea.value.trim())) {
        e.preventDefault();
        alert('Por favor, informe o motivo do override para continuar.');
        return false;
    }
});

document.getElementById('atualizar_cliente_me')?.addEventListener('change', function() {
    document.getElementById('input_atualizar_cliente_me').value = this.value;
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
calcularTotal();
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Comercial');
echo $conteudo;
endSidebar();
