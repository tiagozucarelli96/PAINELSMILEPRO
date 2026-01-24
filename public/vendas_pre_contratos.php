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
$usuario_id = (int)($_SESSION['id'] ?? 0);
$is_admin = vendas_is_admin(); // Usar função centralizada
$admin_context = !empty($_GET['admin']) || (!empty($_POST['admin_context']) && $_POST['admin_context'] === '1');

$mensagens = [];
$erros = [];

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
            
            // Verificar conflito de agenda (sempre verificar, mas só bloquear se não for override)
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
                // Não continuar se houver conflito sem override
            } elseif ($conflito['tem_conflito'] && $override_conflito) {
                // Log do override
                error_log('[VENDAS] Override de conflito aplicado. Motivo: ' . $override_motivo);
            }
            
            // Se não há conflito ou é override, continuar
            if (empty($erros)) {
                $pdo->beginTransaction();
                
                // Buscar/verificar cliente na ME
                $clientes_encontrados = vendas_me_buscar_cliente(
                    $pre_contrato['cpf'] ?? '',
                    $pre_contrato['email'] ?? '',
                    $pre_contrato['telefone'] ?? '',
                    $pre_contrato['nome_completo'] ?? ''
                );
                
                $me_client_id = null;
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
                        // docs ME: telefone/celular
                        'celular' => $pre_contrato['telefone'] ?? null,
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
                
                // Buscar tipo de evento na ME
                $tipos_evento = vendas_me_listar_tipos_evento();
                $tipo_evento_id = (int)(getenv('ME_TIPO_EVENTO_ID_' . strtoupper((string)$pre_contrato['tipo_evento'])) ?: 0);
                if ($tipo_evento_id <= 0) {
                    $needles = [];
                    $tipoInterno = (string)($pre_contrato['tipo_evento'] ?? '');
                    if ($tipoInterno === 'casamento') $needles = ['casament'];
                    elseif ($tipoInterno === 'infantil') $needles = ['infantil', 'anivers'];
                    elseif ($tipoInterno === 'pj') $needles = ['corpor', 'empres', 'pj'];
                    else $needles = [$tipoInterno];

                    foreach ($tipos_evento as $tipo) {
                        if (!is_array($tipo)) continue;
                        $nomeTipo = mb_strtolower((string)($tipo['nome'] ?? ''));
                        foreach ($needles as $n) {
                            $n = mb_strtolower((string)$n);
                            if ($n !== '' && strpos($nomeTipo, $n) !== false) {
                                $tipo_evento_id = (int)($tipo['id'] ?? 0);
                                break 2;
                            }
                        }
                    }
                }
                if ($tipo_evento_id <= 0) {
                    throw new Exception('Não foi possível identificar o tipo de evento na ME. Configure ME_TIPO_EVENTO_ID_CASAMENTO/INFANTIL/PJ no ambiente.');
                }
                
                // Validar que local está mapeado antes de criar evento
                $me_local_id_validacao = vendas_obter_me_local_id($pre_contrato['unidade']);
                if (!$me_local_id_validacao) {
                    throw new Exception('Local não mapeado. Ajuste em Logística > Conexão antes de aprovar.');
                }
                
                // Criar evento na ME
                // Para casamento, usar nome_noivos como nome_evento
                $nome_evento = $pre_contrato['nome_noivos'] ?? $pre_contrato['nome_completo'];
                if ($pre_contrato['tipo_evento'] !== 'casamento') {
                    $nome_evento = $pre_contrato['nome_completo'] . ' - ' . ucfirst($pre_contrato['tipo_evento']);
                }
                
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
                $stmt->execute([
                    $me_client_id,
                    $me_event_id,
                    json_encode($me_payload, JSON_UNESCAPED_UNICODE),
                    $usuario_id,
                    $override_conflito ? true : false,
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
                    }
                }
                
                // Log
                $stmt_log = $pdo->prepare("INSERT INTO vendas_logs (pre_contrato_id, acao, usuario_id, detalhes) VALUES (?, 'aprovado_criado_me', ?, ?)");
                $stmt_log->execute([$pre_contrato_id, $usuario_id, json_encode([
                    'me_client_id' => $me_client_id,
                    'me_event_id' => $me_event_id,
                    'override' => $override_conflito
                ])]);
                
                $pdo->commit();
                $mensagens[] = 'Pré-contrato aprovado e criado na ME com sucesso!';
                
            }
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erros[] = 'Erro ao aprovar: ' . $e->getMessage();
            error_log('Erro ao aprovar pré-contrato: ' . $e->getMessage());
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

.btn {
    padding: 0.5rem 1rem;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-weight: 500;
    text-decoration: none;
    display: inline-block;
}

.btn-primary { background: #2563eb; color: white; }
.btn-success { background: #16a34a; color: white; }
.btn-danger { background: #ef4444; color: white; }
.btn-secondary { background: #6b7280; color: white; }

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #374151;
}

.form-group input,
.form-group select,
.form-group textarea {
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
    
    <?php foreach ($mensagens as $msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endforeach; ?>
    
    <?php foreach ($erros as $erro): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($erro); ?></div>
    <?php endforeach; ?>
    
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
                        <td><?php echo ucfirst($pc['tipo_evento']); ?></td>
                        <td><?php echo date('d/m/Y', strtotime($pc['data_evento'])); ?></td>
                        <td><?php echo htmlspecialchars($pc['unidade']); ?></td>
                        <td>R$ <?php echo number_format($pc['valor_total'] ?? 0, 2, ',', '.'); ?></td>
                        <td>
                            <?php
                            $status_class = 'status-aguardando';
                            $status_text = 'Aguardando';
                            if ($pc['status'] === 'pronto_aprovacao') {
                                $status_class = 'status-pronto';
                                $status_text = 'Pronto';
                            } elseif ($pc['status'] === 'aprovado_criado_me') {
                                $status_class = 'status-aprovado';
                                $status_text = 'Aprovado';
                            } elseif ($pc['status'] === 'cancelado_nao_fechou') {
                                $status_class = 'status-cancelado';
                                $status_text = 'Cancelado';
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
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($pre_contrato_editar): ?>
        <!-- Modal de Edição -->
        <div class="modal active" id="modalEditar">
            <div class="modal-content">
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
                        <a href="<?php echo htmlspecialchars($base_query); ?>" class="btn btn-secondary">Cancelar</a>
                        
                        <?php if ($is_admin && $admin_context && $pre_contrato_editar['status'] === 'pronto_aprovacao'): ?>
                            <button type="button" class="btn btn-primary" onclick="abrirModalAprovacao()">Aprovar e Criar na ME</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Modal de Aprovação -->
        <?php if ($is_admin && $admin_context && $pre_contrato_editar['status'] === 'pronto_aprovacao'): ?>
        <div class="modal" id="modalAprovacao">
            <div class="modal-content">
                <h2>Aprovar e Criar na ME</h2>
                
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
                
                <form method="POST" id="formAprovacao">
                    <input type="hidden" name="action" value="aprovar_criar_me">
                    <input type="hidden" name="pre_contrato_id" value="<?php echo $pre_contrato_editar['id']; ?>">
                    <input type="hidden" name="admin_context" value="1">
                    <input type="hidden" name="override_conflito" id="input_override_conflito" value="0">
                    <input type="hidden" name="override_motivo" id="input_override_motivo" value="">
                    <input type="hidden" name="atualizar_cliente_me" id="input_atualizar_cliente_me" value="manter">
                    
                    <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                        <button type="submit" class="btn btn-success">Confirmar Aprovação</button>
                        <button type="button" class="btn btn-secondary" onclick="fecharModalAprovacao()">Cancelar</button>
                    </div>
                </form>
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

// No admin, se veio do botão "Editar" em um card pronto, abrir o modal automaticamente
(function() {
    try {
        const params = new URLSearchParams(window.location.search);
        if (params.get('abrir_aprovacao') === '1' && document.getElementById('modalAprovacao')) {
            abrirModalAprovacao();
        }
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

// Calcular total inicial
calcularTotal();
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Comercial');
echo $conteudo;
endSidebar();
