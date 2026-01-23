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
require_once __DIR__ . '/upload_magalu.php';

// Verificar permissões
if (empty($_SESSION['logado']) || empty($_SESSION['perm_comercial'])) {
    header('Location: index.php?page=login');
    exit;
}

$pdo = $GLOBALS['pdo'];
$usuario_id = (int)($_SESSION['id'] ?? 0);
$is_admin = !empty($_SESSION['perm_administrativo']);

$mensagens = [];
$erros = [];

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
                    atualizado_em = NOW(), atualizado_por = ?, status = 'pronto_aprovacao'
                WHERE id = ?
            ");
            $stmt->execute([$pacote, $valor_negociado, $desconto, $valor_total, $usuario_id, $pre_contrato_id]);
            
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
    
    if ($action === 'aprovar_criar_me' && $is_admin) {
        $pre_contrato_id = (int)($_POST['pre_contrato_id'] ?? 0);
        $override_conflito = isset($_POST['override_conflito']) && $_POST['override_conflito'] === '1';
        $override_motivo = trim($_POST['override_motivo'] ?? '');
        $atualizar_cliente_me = $_POST['atualizar_cliente_me'] ?? 'manter'; // manter, atualizar, apenas_painel
        
        try {
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
                    vendas_me_atualizar_cliente($me_client_id, [
                        'nome' => $pre_contrato['nome_completo'],
                        'email' => $pre_contrato['email'],
                        'telefone' => $pre_contrato['telefone']
                    ]);
                }
                
                // Se não encontrou cliente, criar novo
                if (!$me_client_id) {
                    $novo_cliente = vendas_me_criar_cliente([
                        'nome' => $pre_contrato['nome_completo'],
                        'cpf' => $pre_contrato['cpf'],
                        'email' => $pre_contrato['email'],
                        'telefone' => $pre_contrato['telefone']
                    ]);
                    $me_client_id = $novo_cliente['id'] ?? null;
                }
                
                if (!$me_client_id) {
                    throw new Exception('Não foi possível obter/criar cliente na ME');
                }
                
                // Buscar tipo de evento na ME
                $tipos_evento = vendas_me_listar_tipos_evento();
                $tipo_evento_id = null;
                foreach ($tipos_evento as $tipo) {
                    if (stripos($tipo['nome'] ?? '', $pre_contrato['tipo_evento']) !== false) {
                        $tipo_evento_id = $tipo['id'] ?? null;
                        break;
                    }
                }
                
                // Criar evento na ME
                $dados_evento = [
                    'client_id' => $me_client_id,
                    'tipo_evento_id' => $tipo_evento_id,
                    'nome_evento' => $pre_contrato['nome_completo'] . ' - ' . ucfirst($pre_contrato['tipo_evento']),
                    'data_evento' => $pre_contrato['data_evento'],
                    'hora_inicio' => $pre_contrato['horario_inicio'],
                    'hora_termino' => $pre_contrato['horario_termino'],
                    'local' => $pre_contrato['unidade'],
                    'observacoes' => $pre_contrato['observacoes'] ?? ''
                ];
                
                // Se não tem client_id (endpoint não existe), incluir dados do cliente inline
                if (!$me_client_id) {
                    $dados_evento['cliente_nome'] = $pre_contrato['nome_completo'];
                    $dados_evento['cliente_cpf'] = $pre_contrato['cpf'];
                    $dados_evento['cliente_email'] = $pre_contrato['email'];
                    $dados_evento['cliente_telefone'] = $pre_contrato['telefone'];
                }
                
                $evento_me = vendas_me_criar_evento($dados_evento);
                $me_event_id = $evento_me['id'] ?? null;
                
                // Se cliente não foi criado antes, pegar da resposta do evento
                if (!$me_client_id) {
                    $me_client_id = $evento_me['client_id'] ?? null;
                }
                
                if (!$me_event_id) {
                    throw new Exception('Não foi possível criar evento na ME');
                }
                
                // Atualizar pré-contrato
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
                    json_encode($dados_evento),
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
        <h1>Pré-contratos</h1>
        <p>Gerencie os pré-contratos recebidos dos formulários públicos</p>
    </div>
    
    <?php foreach ($mensagens as $msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
    <?php endforeach; ?>
    
    <?php foreach ($erros as $erro): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($erro); ?></div>
    <?php endforeach; ?>
    
    <div class="vendas-filters">
        <select name="filtro_status" onchange="window.location.href='?status='+this.value+'&tipo=<?php echo htmlspecialchars($filtro_tipo); ?>&busca=<?php echo htmlspecialchars($busca); ?>'">
            <option value="">Todos os status</option>
            <option value="aguardando_conferencia" <?php echo $filtro_status === 'aguardando_conferencia' ? 'selected' : ''; ?>>Aguardando conferência</option>
            <option value="pronto_aprovacao" <?php echo $filtro_status === 'pronto_aprovacao' ? 'selected' : ''; ?>>Pronto para aprovação</option>
            <option value="aprovado_criado_me" <?php echo $filtro_status === 'aprovado_criado_me' ? 'selected' : ''; ?>>Aprovado / Criado na ME</option>
            <option value="cancelado_nao_fechou" <?php echo $filtro_status === 'cancelado_nao_fechou' ? 'selected' : ''; ?>>Cancelado / Não fechou</option>
        </select>
        
        <select name="filtro_tipo" onchange="window.location.href='?status=<?php echo htmlspecialchars($filtro_status); ?>&tipo='+this.value+'&busca=<?php echo htmlspecialchars($busca); ?>'">
            <option value="">Todos os tipos</option>
            <option value="casamento" <?php echo $filtro_tipo === 'casamento' ? 'selected' : ''; ?>>Casamento</option>
            <option value="infantil" <?php echo $filtro_tipo === 'infantil' ? 'selected' : ''; ?>>Infantil</option>
            <option value="pj" <?php echo $filtro_tipo === 'pj' ? 'selected' : ''; ?>>PJ</option>
        </select>
        
        <form method="GET" style="display: flex; gap: 0.5rem; flex: 1;">
            <input type="text" name="busca" placeholder="Buscar por nome, email ou CPF..." 
                   value="<?php echo htmlspecialchars($busca); ?>" style="flex: 1;">
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
                            <a href="?editar=<?php echo $pc['id']; ?>" class="btn btn-primary" style="font-size: 0.875rem;">Editar</a>
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
                        <a href="?" class="btn btn-secondary">Cancelar</a>
                        
                        <?php if ($is_admin && $pre_contrato_editar['status'] === 'pronto_aprovacao'): ?>
                            <button type="button" class="btn btn-primary" onclick="abrirModalAprovacao()">Aprovar e Criar na ME</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Modal de Aprovação -->
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
                
                <?php if (!empty($conflito_detalhes) && $conflito_detalhes['tem_conflito']): ?>
                    <div class="alert alert-warning">
                        <strong>Conflito de agenda detectado!</strong><br>
                        Existem eventos na mesma unidade e data que não respeitam a distância mínima de 
                        <?php echo $_SESSION['vendas_conflito_detalhes']['distancia_minima_horas']; ?> horas.
                        <ul style="margin-top: 0.5rem;">
                            <?php foreach ($_SESSION['vendas_conflito_detalhes']['conflitos'] as $conflito): ?>
                                <li>
                                    Evento: <?php echo htmlspecialchars($conflito['evento']['nome_evento'] ?? 'N/A'); ?> - 
                                    <?php echo htmlspecialchars($conflito['evento']['hora_inicio'] ?? ''); ?> às 
                                    <?php echo htmlspecialchars($conflito['evento']['hora_termino'] ?? ''); ?>
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
                    if (preg_replace('/\D/', '', $cliente_duplicado['telefone'] ?? '')) !== preg_replace('/\D/', '', $pre_contrato_editar['telefone'] ?? '')) {
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
