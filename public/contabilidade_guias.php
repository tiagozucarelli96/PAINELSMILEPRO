<?php
// contabilidade_guias.php — Tela de Guias para Pagamento
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/upload_magalu.php';
require_once __DIR__ . '/core/notificacoes_helper.php';

// Verificar se está logado
if (empty($_SESSION['contabilidade_logado']) || $_SESSION['contabilidade_logado'] !== true) {
    header('Location: contabilidade_login.php');
    exit;
}

$mensagem = '';
$erro = '';

function contabilidadeColunaExiste(PDO $pdo, string $tabela, string $coluna): bool
{
    $stmt = $pdo->prepare(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :tabela AND column_name = :coluna"
    );
    $stmt->execute([':tabela' => $tabela, ':coluna' => $coluna]);

    return (bool) $stmt->fetchColumn();
}

// Processar cadastro de guia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'cadastrar_guia') {
    try {
        $descricao = trim($_POST['descricao'] ?? '');
        $data_vencimento = trim($_POST['data_vencimento'] ?? '');
        $e_parcela = isset($_POST['e_parcela']) && $_POST['e_parcela'] === '1';
        $parcelamento_id = !empty($_POST['parcelamento_id']) ? (int)$_POST['parcelamento_id'] : null;
        $criar_parcelamento = isset($_POST['criar_parcelamento']) && $_POST['criar_parcelamento'] === '1';
        $total_parcelas = !empty($_POST['total_parcelas']) ? (int)$_POST['total_parcelas'] : null;
        $parcela_inicial = !empty($_POST['parcela_inicial']) ? (int)$_POST['parcela_inicial'] : 1;
        
        if (empty($descricao)) {
            throw new Exception('Descrição é obrigatória');
        }
        
        if (empty($data_vencimento)) {
            throw new Exception('Data de vencimento é obrigatória');
        }
        
        // Processar upload do arquivo
        $arquivo_url = null;
        $arquivo_nome = null;
        $chave_storage = null;
        
        if (isset($_FILES['arquivo']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
            try {
                $uploader = new MagaluUpload();
                $resultado = $uploader->upload($_FILES['arquivo'], 'contabilidade/guias');
                
                // Salvar chave_storage (para presigned URLs) e URL (fallback)
                $arquivo_url = $resultado['url'] ?? null;
                $chave_storage = $resultado['chave_storage'] ?? null;
                $arquivo_nome = $resultado['nome_original'] ?? $_FILES['arquivo']['name'];
            } catch (Exception $e) {
                throw new Exception('Erro ao fazer upload: ' . $e->getMessage());
            }
        }
        
        $pdo->beginTransaction();
        
        try {
            // Se é parcela e precisa criar parcelamento
            if ($e_parcela && $criar_parcelamento && $total_parcelas) {
                $stmt = $pdo->prepare("
                    INSERT INTO contabilidade_parcelamentos (descricao, total_parcelas, parcela_atual, status)
                    VALUES (:desc, :total, :atual, 'ativo')
                    RETURNING id
                ");
                $stmt->execute([
                    ':desc' => $descricao,
                    ':total' => $total_parcelas,
                    ':atual' => $parcela_inicial
                ]);
                $parcelamento_id = $stmt->fetchColumn();
            }
            
            // Determinar número da parcela
            $numero_parcela = null;
            if ($e_parcela && $parcelamento_id) {
                // Buscar parcela atual do parcelamento
                $stmt = $pdo->prepare("SELECT parcela_atual FROM contabilidade_parcelamentos WHERE id = :id");
                $stmt->execute([':id' => $parcelamento_id]);
                $parcela_atual = $stmt->fetchColumn();
                $numero_parcela = $parcela_atual;
                
                // Atualizar parcela atual
                $stmt = $pdo->prepare("
                    UPDATE contabilidade_parcelamentos 
                    SET parcela_atual = parcela_atual + 1,
                        atualizado_em = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $parcelamento_id]);
                
                // Verificar se encerrou
                $stmt = $pdo->prepare("
                    SELECT parcela_atual, total_parcelas 
                    FROM contabilidade_parcelamentos 
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $parcelamento_id]);
                $parc_info = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($parc_info['parcela_atual'] > $parc_info['total_parcelas']) {
                    $stmt = $pdo->prepare("
                        UPDATE contabilidade_parcelamentos 
                        SET status = 'encerrado', atualizado_em = NOW()
                        WHERE id = :id
                    ");
                    $stmt->execute([':id' => $parcelamento_id]);
                }
            }
            
            // Inserir guia
            // Garantir que e_parcela seja boolean explícito
            $e_parcela_bool = (bool)$e_parcela;
            
            $has_chave_storage = contabilidadeColunaExiste($pdo, 'contabilidade_guias', 'chave_storage');
            if ($has_chave_storage) {
                $stmt = $pdo->prepare("
                    INSERT INTO contabilidade_guias 
                    (arquivo_url, arquivo_nome, chave_storage, data_vencimento, descricao, e_parcela, parcelamento_id, numero_parcela)
                    VALUES (:arquivo_url, :arquivo_nome, :chave_storage, :vencimento, :desc, :e_parcela, :parc_id, :num_parc)
                ");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO contabilidade_guias 
                    (arquivo_url, arquivo_nome, data_vencimento, descricao, e_parcela, parcelamento_id, numero_parcela)
                    VALUES (:arquivo_url, :arquivo_nome, :vencimento, :desc, :e_parcela, :parc_id, :num_parc)
                ");
            }

            $stmt->bindValue(':arquivo_url', $arquivo_url, PDO::PARAM_STR);
            $stmt->bindValue(':arquivo_nome', $arquivo_nome, PDO::PARAM_STR);
            if ($has_chave_storage) {
                $stmt->bindValue(':chave_storage', $chave_storage, PDO::PARAM_STR);
            }
            $stmt->bindValue(':vencimento', $data_vencimento, PDO::PARAM_STR);
            $stmt->bindValue(':desc', $descricao, PDO::PARAM_STR);
            $stmt->bindValue(':e_parcela', $e_parcela_bool, PDO::PARAM_BOOL);
            $stmt->bindValue(':parc_id', $parcelamento_id, PDO::PARAM_INT);
            $stmt->bindValue(':num_parc', $numero_parcela, PDO::PARAM_INT);
            $stmt->execute();
            
            $guia_id = $pdo->lastInsertId();
            
            $pdo->commit();
            $mensagem = 'Guia cadastrada com sucesso!';
            
            // Registrar notificação (ETAPA 13)
            try {
                $notificacoes = new NotificacoesHelper();
                $titulo = $e_parcela && $numero_parcela ? 
                    "Nova guia cadastrada: {$descricao} (Parcela {$numero_parcela})" : 
                    "Nova guia cadastrada: {$descricao}";
                $notificacoes->registrarNotificacao(
                    'contabilidade',
                    'novo_cadastro',
                    'guia',
                    $guia_id,
                    $titulo,
                    "Data de vencimento: " . date('d/m/Y', strtotime($data_vencimento)),
                    'ambos'
                );
            } catch (Exception $e) {
                // Ignorar erro de notificação silenciosamente
                error_log("Erro ao registrar notificação: " . $e->getMessage());
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar parcelamentos ativos
$parcelamentos_ativos = [];
try {
    $stmt = $pdo->query("
        SELECT id, descricao, total_parcelas, parcela_atual, status
        FROM contabilidade_parcelamentos
        WHERE status = 'ativo'
        ORDER BY criado_em DESC
    ");
    $parcelamentos_ativos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir
}

// Buscar guias
$guias = [];
try {
    $stmt = $pdo->query("
        SELECT g.*, p.descricao as parcelamento_desc, p.total_parcelas
        FROM contabilidade_guias g
        LEFT JOIN contabilidade_parcelamentos p ON p.id = g.parcelamento_id
        ORDER BY g.criado_em DESC
        LIMIT 50
    ");
    $guias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Guias para Pagamento - Contabilidade</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
        }
        .header {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 1.5rem; font-weight: 700; }
        .btn-back { background: rgba(255,255,255,0.2); color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; }
        .container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
        .alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        .form-section {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .form-section-title { font-size: 1.25rem; font-weight: 600; color: #1e40af; margin-bottom: 1.5rem; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; }
        .form-group { display: flex; flex-direction: column; }
        .form-label { font-weight: 500; color: #374151; margin-bottom: 0.5rem; }
        .form-input, .form-select { padding: 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem; }
        .form-input:focus, .form-select:focus { outline: none; border-color: #1e40af; box-shadow: 0 0 0 3px rgba(30,64,175,0.1); }
        .checkbox-group { display: flex; align-items: center; gap: 0.5rem; margin: 1rem 0; }
        .parcelamento-section { background: #f8fafc; border-radius: 8px; padding: 1.5rem; margin-top: 1rem; display: none; }
        .parcelamento-section.active { display: block; }
        .btn-primary { background: #1e40af; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; }
        .btn-primary:hover { background: #1e3a8a; }
        .table { width: 100%; border-collapse: collapse; background: white; border-radius: 12px; overflow: hidden; }
        .table th { background: #1e40af; color: white; padding: 1rem; text-align: left; }
        .table td { padding: 1rem; border-bottom: 1px solid #e5e7eb; }
        .badge { display: inline-block; padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.875rem; font-weight: 500; }
        .badge-aberto { background: #fef3c7; color: #92400e; }
        .badge-pago { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
    <div class="header">
        <h1>💰 Guias para Pagamento</h1>
        <a href="contabilidade_painel.php" class="btn-back">← Voltar</a>
    </div>
    
    <div class="container">
        <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>
        
        <!-- Formulário de Cadastro -->
        <div class="form-section">
            <h2 class="form-section-title">➕ Cadastrar Nova Guia</h2>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="acao" value="cadastrar_guia">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Descrição *</label>
                        <input type="text" name="descricao" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Data de Vencimento *</label>
                        <input type="date" name="data_vencimento" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Arquivo</label>
                        <input type="file" name="arquivo" class="form-input" accept=".pdf,.jpg,.jpeg,.png">
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="e_parcela" id="e_parcela" value="1" onchange="toggleParcelamento()">
                    <label for="e_parcela">É parcela?</label>
                </div>
                
                <!-- Seção de Parcelamento -->
                <div id="parcelamento-section" class="parcelamento-section">
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label class="form-label">Parcelamento Ativo</label>
                        <select name="parcelamento_id" id="parcelamento_id" class="form-select" onchange="toggleCriarParcelamento()">
                            <option value="">Selecione um parcelamento...</option>
                            <?php foreach ($parcelamentos_ativos as $parc): ?>
                            <option value="<?= $parc['id'] ?>">
                                <?= htmlspecialchars($parc['descricao']) ?> 
                                (Parcela <?= $parc['parcela_atual'] ?>/<?= $parc['total_parcelas'] ?>)
                            </option>
                            <?php endforeach; ?>
                            <option value="novo">+ Criar novo parcelamento</option>
                        </select>
                    </div>
                    
                    <div id="novo-parcelamento" style="display: none;">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Total de Parcelas *</label>
                                <input type="number" name="total_parcelas" class="form-input" min="2">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Parcela Inicial</label>
                                <input type="number" name="parcela_inicial" class="form-input" value="1" min="1">
                            </div>
                        </div>
                        <input type="hidden" name="criar_parcelamento" value="1">
                    </div>
                </div>
                
                <button type="submit" class="btn-primary">💾 Cadastrar Guia</button>
            </form>
        </div>
        
        <!-- Lista de Guias -->
        <div class="form-section">
            <h2 class="form-section-title">📋 Guias Cadastradas</h2>
            <?php if (empty($guias)): ?>
            <p style="color: #64748b; text-align: center; padding: 2rem;">Nenhuma guia cadastrada ainda.</p>
            <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Descrição</th>
                        <th>Vencimento</th>
                        <th>Parcela</th>
                        <th>Status</th>
                        <th>Arquivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($guias as $guia): ?>
                    <tr>
                        <td><?= htmlspecialchars($guia['descricao']) ?></td>
                        <td><?= date('d/m/Y', strtotime($guia['data_vencimento'])) ?></td>
                        <td>
                            <?php if ($guia['e_parcela'] && $guia['numero_parcela']): ?>
                                Parcela <?= $guia['numero_parcela'] ?>/<?= $guia['total_parcelas'] ?>
                                <?php if ($guia['parcelamento_desc']): ?>
                                    <br><small style="color: #64748b;"><?= htmlspecialchars($guia['parcelamento_desc']) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-<?= $guia['status'] === 'aberto' ? 'aberto' : 'pago' ?>">
                                <?= ucfirst($guia['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($guia['chave_storage']) || !empty($guia['arquivo_url'])): ?>
                                <a href="contabilidade_download.php?tipo=guia&id=<?= $guia['id'] ?>" target="_blank">📎 Ver</a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        function toggleParcelamento() {
            const checkbox = document.getElementById('e_parcela');
            const section = document.getElementById('parcelamento-section');
            if (checkbox.checked) {
                section.classList.add('active');
            } else {
                section.classList.remove('active');
            }
        }
        
        function toggleCriarParcelamento() {
            const select = document.getElementById('parcelamento_id');
            const novoDiv = document.getElementById('novo-parcelamento');
            if (select.value === 'novo') {
                novoDiv.style.display = 'block';
            } else {
                novoDiv.style.display = 'none';
            }
        }
    </script>
</body>
</html>
