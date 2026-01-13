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

// Processar exclusão de guia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'excluir_guia') {
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new Exception('ID inválido');
        }
        
        // Buscar parcelamento_id antes de excluir
        $stmt = $pdo->prepare("SELECT parcelamento_id FROM contabilidade_guias WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $guia = $stmt->fetch(PDO::FETCH_ASSOC);
        $parcelamento_id = $guia['parcelamento_id'] ?? null;
        
        // Excluir a guia
        $stmt = $pdo->prepare("DELETE FROM contabilidade_guias WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        // Se a guia estava associada a um parcelamento, verificar se ainda há outras guias
        if ($parcelamento_id) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM contabilidade_guias 
                WHERE parcelamento_id = :parc_id
            ");
            $stmt->execute([':parc_id' => $parcelamento_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Se não há mais guias, marcar parcelamento como encerrado
            if ($result['total'] == 0) {
                $stmt = $pdo->prepare("
                    UPDATE contabilidade_parcelamentos 
                    SET status = 'encerrado', atualizado_em = NOW() 
                    WHERE id = :parc_id
                ");
                $stmt->execute([':parc_id' => $parcelamento_id]);
            }
        }
        
        $mensagem = 'Guia excluída com sucesso!';
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Processar cadastro de guia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'cadastrar_guia') {
    try {
        $descricao = trim($_POST['descricao'] ?? '');
        $data_vencimento = trim($_POST['data_vencimento'] ?? '');
        $empresa_id = !empty($_POST['empresa_id']) ? (int)$_POST['empresa_id'] : null;
        $e_parcela = isset($_POST['e_parcela']) && $_POST['e_parcela'] === '1';
        $parcelamento_id = !empty($_POST['parcelamento_id']) ? (int)$_POST['parcelamento_id'] : null;
        $criar_parcelamento = isset($_POST['criar_parcelamento']) && $_POST['criar_parcelamento'] === '1';
        $total_parcelas = !empty($_POST['total_parcelas']) ? (int)$_POST['total_parcelas'] : null;
        $parcela_atual = !empty($_POST['parcela_atual']) ? (int)$_POST['parcela_atual'] : 1;
        
        if (empty($descricao)) {
            throw new Exception('Descrição é obrigatória');
        }
        
        if (empty($data_vencimento)) {
            throw new Exception('Data de vencimento é obrigatória');
        }
        
        if (empty($empresa_id)) {
            throw new Exception('Empresa é obrigatória');
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
            if ($e_parcela && $criar_parcelamento && $total_parcelas && $parcela_atual) {
                // Validar que parcela_atual não seja maior que total_parcelas
                if ($parcela_atual > $total_parcelas) {
                    throw new Exception("A parcela atual ({$parcela_atual}) não pode ser maior que o total de parcelas ({$total_parcelas})");
                }
                
                // Verificar se coluna empresa_id existe na tabela parcelamentos
                $has_empresa_id_parc = contabilidadeColunaExiste($pdo, 'contabilidade_parcelamentos', 'empresa_id');
                
                if ($has_empresa_id_parc && $empresa_id) {
                    $stmt = $pdo->prepare("
                        INSERT INTO contabilidade_parcelamentos (descricao, total_parcelas, parcela_atual, status, empresa_id)
                        VALUES (:desc, :total, :atual, 'ativo', :empresa_id)
                        RETURNING id
                    ");
                    $stmt->execute([
                        ':desc' => $descricao,
                        ':total' => $total_parcelas,
                        ':atual' => $parcela_atual,
                        ':empresa_id' => $empresa_id
                    ]);
                } else {
                $stmt = $pdo->prepare("
                    INSERT INTO contabilidade_parcelamentos (descricao, total_parcelas, parcela_atual, status)
                    VALUES (:desc, :total, :atual, 'ativo')
                    RETURNING id
                ");
                $stmt->execute([
                    ':desc' => $descricao,
                    ':total' => $total_parcelas,
                        ':atual' => $parcela_atual
                ]);
                }
                $parcelamento_id = $stmt->fetchColumn();
            }
            
            // Determinar número da parcela
            $numero_parcela = null;
            if ($e_parcela && $criar_parcelamento && $parcela_atual) {
                // Se está criando novo parcelamento, usar o valor informado pelo usuário
                $numero_parcela = $parcela_atual;
                // Não incrementar parcela_atual ainda, pois esta é a primeira guia do parcelamento
            } elseif ($e_parcela && $parcelamento_id) {
                // Se está usando parcelamento existente, buscar parcela atual e incrementar
                $stmt = $pdo->prepare("SELECT parcela_atual FROM contabilidade_parcelamentos WHERE id = :id");
                $stmt->execute([':id' => $parcelamento_id]);
                $parcela_atual_db = $stmt->fetchColumn();
                $numero_parcela = $parcela_atual_db;
                
                // Atualizar parcela atual (incrementar para próxima)
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
            
            error_log("[CONTABILIDADE_GUIAS] Preparando inserção. É parcela: " . ($e_parcela ? 'sim' : 'não') . ", Empresa ID: " . ($empresa_id ?? 'null'));
            
            $has_chave_storage = contabilidadeColunaExiste($pdo, 'contabilidade_guias', 'chave_storage');
            $has_empresa_id = contabilidadeColunaExiste($pdo, 'contabilidade_guias', 'empresa_id');
            
            $campos = ['arquivo_url', 'arquivo_nome'];
            $valores = [':arquivo_url', ':arquivo_nome'];
            $bindings = [
                ':arquivo_url' => $arquivo_url,
                ':arquivo_nome' => $arquivo_nome
            ];
            
            if ($has_chave_storage) {
                $campos[] = 'chave_storage';
                $valores[] = ':chave_storage';
                $bindings[':chave_storage'] = $chave_storage;
            }
            
            $campos[] = 'data_vencimento';
            $campos[] = 'descricao';
            $campos[] = 'e_parcela';
            
            // Só adicionar parcelamento_id e numero_parcela se for parcela
            if ($e_parcela) {
                $campos[] = 'parcelamento_id';
                $campos[] = 'numero_parcela';
                $valores[] = ':parc_id';
                $valores[] = ':num_parc';
                $bindings[':parc_id'] = $parcelamento_id;
                $bindings[':num_parc'] = $numero_parcela;
            }
            
            $valores[] = ':vencimento';
            $valores[] = ':desc';
            $valores[] = ':e_parcela';
            $bindings[':vencimento'] = $data_vencimento;
            $bindings[':desc'] = $descricao;
            $bindings[':e_parcela'] = $e_parcela_bool;
            
            // Empresa_id é sempre obrigatório
            if ($has_empresa_id) {
                if (!$empresa_id) {
                    throw new Exception('Empresa é obrigatória');
                }
                $campos[] = 'empresa_id';
                $valores[] = ':empresa_id';
                $bindings[':empresa_id'] = $empresa_id;
            }
            
            error_log("[CONTABILIDADE_GUIAS] Campos a inserir: " . implode(', ', $campos));
            
            $stmt = $pdo->prepare("
                INSERT INTO contabilidade_guias (" . implode(', ', $campos) . ")
                VALUES (" . implode(', ', $valores) . ")
            ");
            
            foreach ($bindings as $key => $value) {
                if ($key === ':e_parcela') {
                    $stmt->bindValue($key, $value, PDO::PARAM_BOOL);
                } elseif ($key === ':parc_id' || $key === ':num_parc' || $key === ':empresa_id') {
                    // Permitir null para campos opcionais
                    if ($value === null) {
                        $stmt->bindValue($key, $value, PDO::PARAM_NULL);
                    } else {
                        $stmt->bindValue($key, $value, PDO::PARAM_INT);
                    }
                } else {
                    $stmt->bindValue($key, $value, PDO::PARAM_STR);
                }
            }
            $stmt->execute();
            
            $guia_id = $pdo->lastInsertId();
            
            error_log("[CONTABILIDADE_GUIAS] Guia inserida com sucesso. ID: $guia_id, É parcela: " . ($e_parcela ? 'sim' : 'não'));
            
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
        error_log("[CONTABILIDADE_GUIAS] Erro ao cadastrar guia: " . $e->getMessage());
        error_log("[CONTABILIDADE_GUIAS] Stack trace: " . $e->getTraceAsString());
    }
}

// Buscar empresas
$empresas = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM contabilidade_empresas
        WHERE ativo = TRUE
        ORDER BY nome ASC
    ");
    $empresas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir
    error_log("Erro ao buscar empresas: " . $e->getMessage());
}

// Buscar parcelamentos ativos com dados da primeira guia (para descrição) e empresa do parcelamento
$parcelamentos_ativos = [];
try {
    $has_empresa_id_parc = contabilidadeColunaExiste($pdo, 'contabilidade_parcelamentos', 'empresa_id');
    $has_empresa_id_guias = contabilidadeColunaExiste($pdo, 'contabilidade_guias', 'empresa_id');
    
    if ($has_empresa_id_parc) {
        // Se parcelamentos tem empresa_id, usar diretamente
        // Só mostrar parcelamentos que têm pelo menos uma guia associada
        $stmt = $pdo->query("
            SELECT DISTINCT
                p.id, 
                p.descricao, 
                p.total_parcelas, 
                p.parcela_atual, 
                p.status,
                p.empresa_id,
                g.descricao as primeira_descricao
            FROM contabilidade_parcelamentos p
            INNER JOIN contabilidade_guias g ON g.parcelamento_id = p.id
            LEFT JOIN contabilidade_guias g1 ON g1.parcelamento_id = p.id AND g1.numero_parcela = 1
            WHERE p.status = 'ativo'
            ORDER BY p.criado_em DESC
        ");
    } elseif ($has_empresa_id_guias) {
        // Se não tem empresa_id em parcelamentos, buscar da primeira guia
        // Só mostrar parcelamentos que têm pelo menos uma guia associada
        $stmt = $pdo->query("
            SELECT DISTINCT
                p.id, 
                p.descricao, 
                p.total_parcelas, 
                p.parcela_atual, 
                p.status,
                g1.empresa_id,
                g1.descricao as primeira_descricao
            FROM contabilidade_parcelamentos p
            INNER JOIN contabilidade_guias g ON g.parcelamento_id = p.id
            LEFT JOIN contabilidade_guias g1 ON g1.parcelamento_id = p.id AND g1.numero_parcela = 1
            WHERE p.status = 'ativo'
            ORDER BY p.criado_em DESC
        ");
    } else {
        // Fallback: sem empresa_id
        // Só mostrar parcelamentos que têm pelo menos uma guia associada
        $stmt = $pdo->query("
            SELECT DISTINCT
                p.id, 
                p.descricao, 
                p.total_parcelas, 
                p.parcela_atual, 
                p.status,
                NULL as empresa_id,
                g1.descricao as primeira_descricao
            FROM contabilidade_parcelamentos p
            INNER JOIN contabilidade_guias g ON g.parcelamento_id = p.id
            LEFT JOIN contabilidade_guias g1 ON g1.parcelamento_id = p.id AND g1.numero_parcela = 1
            WHERE p.status = 'ativo'
            ORDER BY p.criado_em DESC
        ");
    }
    $parcelamentos_ativos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Tabela pode não existir
    error_log("Erro ao buscar parcelamentos: " . $e->getMessage());
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
            <form method="POST" enctype="multipart/form-data" id="form-guia">
                <input type="hidden" name="acao" value="cadastrar_guia">
                
                <!-- Checkbox É parcela? no topo -->
                <div class="checkbox-group" style="margin-bottom: 1.5rem;">
                    <input type="checkbox" name="e_parcela" id="e_parcela" value="1" onchange="toggleParcelamento()">
                    <label for="e_parcela" style="font-weight: 600; font-size: 1.1rem;">É parcela?</label>
                </div>
                
                <!-- Seção de Parcelamento (aparece quando É parcela? está marcado) -->
                <div id="parcelamento-section" class="parcelamento-section" style="display: none;">
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label class="form-label">Parcelamento</label>
                        <select name="parcelamento_id" id="parcelamento_id" class="form-select" onchange="handleParcelamentoChange()">
                            <option value="">Selecione um parcelamento...</option>
                            <?php foreach ($parcelamentos_ativos as $parc): ?>
                            <option value="<?= $parc['id'] ?>" 
                                    data-descricao="<?= htmlspecialchars($parc['descricao'] ?? $parc['primeira_descricao'] ?? '') ?>"
                                    data-empresa-id="<?= $parc['empresa_id'] ?? '' ?>"
                                    data-parcela-atual="<?= $parc['parcela_atual'] ?>"
                                    data-total-parcelas="<?= $parc['total_parcelas'] ?>">
                                <?= htmlspecialchars($parc['descricao'] ?? $parc['primeira_descricao'] ?? 'Sem descrição') ?>
                            </option>
                            <?php endforeach; ?>
                            <option value="novo">+ Criar novo parcelamento</option>
                        </select>
                    </div>
                    
                    <!-- Mostrar parcela que será cadastrada (quando parcelamento existente selecionado) -->
                    <div id="parcela-info" style="display: none; background: #e0f2fe; border: 1px solid #0ea5e9; border-radius: 6px; padding: 0.75rem; margin-bottom: 1rem;">
                        <strong style="color: #0c4a6e;">Parcela a ser cadastrada:</strong>
                        <span id="parcela-texto" style="color: #0369a1; font-weight: 600;"></span>
                    </div>
                    
                    <!-- Novo parcelamento -->
                    <div id="novo-parcelamento" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Parcela e Total *</label>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <span>Parcela</span>
                                <input type="number" name="parcela_atual" id="parcela_atual" class="form-input" style="width: 80px;" min="1" required>
                                <span>de um total de</span>
                                <input type="number" name="total_parcelas" id="total_parcelas" class="form-input" style="width: 80px;" min="2" required>
                            </div>
                            <small style="color: #64748b; font-size: 0.875rem; margin-top: 0.25rem; display: block;">
                                Exemplo: Parcela 5 de um total de 30
                            </small>
                        </div>
                        <input type="hidden" name="criar_parcelamento" value="1">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Empresa *</label>
                        <select name="empresa_id" id="empresa_id" class="form-select" required>
                            <option value="">Selecione uma empresa...</option>
                            <?php foreach ($empresas as $emp): ?>
                            <option value="<?= $emp['id'] ?>">
                                <?= htmlspecialchars($emp['nome']) ?> - <?= htmlspecialchars($emp['documento'] ?? $emp['cnpj'] ?? '') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Descrição *</label>
                        <input type="text" name="descricao" id="descricao" class="form-input" required>
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
                        <th>Ações</th>
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
                        <td>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja excluir esta guia?');">
                                <input type="hidden" name="acao" value="excluir_guia">
                                <input type="hidden" name="id" value="<?= $guia['id'] ?>">
                                <button type="submit" style="background: #ef4444; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">🗑️ Excluir</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Dados dos parcelamentos para preenchimento automático
        const parcelamentosData = <?= json_encode(array_map(function($p) {
            return [
                'id' => $p['id'],
                'descricao' => $p['descricao'] ?? $p['primeira_descricao'] ?? '',
                'empresa_id' => $p['empresa_id'] ?? null
            ];
        }, $parcelamentos_ativos)) ?>;
        
        function toggleParcelamento() {
            const checkbox = document.getElementById('e_parcela');
            const section = document.getElementById('parcelamento-section');
            if (checkbox.checked) {
                section.style.display = 'block';
            } else {
                section.style.display = 'none';
                // Limpar campos de parcelamento quando desmarcar (mas manter empresa_id, pois é obrigatório)
                document.getElementById('parcelamento_id').value = '';
                document.getElementById('novo-parcelamento').style.display = 'none';
                document.getElementById('parcela-info').style.display = 'none';
                // Não limpar descricao e empresa_id, pois são campos obrigatórios
            }
        }
        
        function handleParcelamentoChange() {
            const select = document.getElementById('parcelamento_id');
            const novoDiv = document.getElementById('novo-parcelamento');
            const parcelaInfoDiv = document.getElementById('parcela-info');
            const parcelaTexto = document.getElementById('parcela-texto');
            const descricaoInput = document.getElementById('descricao');
            const empresaSelect = document.getElementById('empresa_id');
            
            if (select.value === 'novo') {
                // Mostrar campos de novo parcelamento
                novoDiv.style.display = 'block';
                parcelaInfoDiv.style.display = 'none';
                // Limpar campos (usuário vai preencher)
                descricaoInput.value = '';
                empresaSelect.value = '';
            } else if (select.value) {
                // Esconder campos de novo parcelamento
                novoDiv.style.display = 'none';
                
                // Buscar dados do parcelamento selecionado
                const selectedOption = select.options[select.selectedIndex];
                const parcelaAtual = parseInt(selectedOption.getAttribute('data-parcela-atual')) || 0;
                const totalParcelas = parseInt(selectedOption.getAttribute('data-total-parcelas')) || 0;
                const proximaParcela = parcelaAtual + 1;
                
                // Mostrar informação da parcela que será cadastrada
                if (proximaParcela <= totalParcelas) {
                    parcelaTexto.textContent = `Parcela ${proximaParcela} de ${totalParcelas}`;
                    parcelaInfoDiv.style.display = 'block';
                } else {
                    parcelaInfoDiv.style.display = 'none';
                }
                
                // Buscar dados do parcelamento selecionado para preencher campos
                const parcelamentoId = parseInt(select.value);
                const parcelamento = parcelamentosData.find(p => p.id === parcelamentoId);
                
                if (parcelamento) {
                    // Preencher descrição automaticamente
                    if (parcelamento.descricao) {
                        descricaoInput.value = parcelamento.descricao;
                    }
                    
                    // Preencher empresa automaticamente
                    if (parcelamento.empresa_id) {
                        empresaSelect.value = parcelamento.empresa_id;
                    }
                }
            } else {
                // Limpar quando nenhum parcelamento selecionado
                novoDiv.style.display = 'none';
                parcelaInfoDiv.style.display = 'none';
                descricaoInput.value = '';
                empresaSelect.value = '';
            }
        }
        // Validação do formulário antes de enviar
        document.getElementById('form-guia').addEventListener('submit', function(e) {
            const empresaId = document.getElementById('empresa_id').value;
            const descricao = document.getElementById('descricao').value;
            const dataVencimento = document.getElementById('data_vencimento').value;
            
            if (!empresaId) {
                e.preventDefault();
                alert('Por favor, selecione uma empresa.');
                document.getElementById('empresa_id').focus();
                return false;
            }
            
            if (!descricao.trim()) {
                e.preventDefault();
                alert('Por favor, preencha a descrição.');
                document.getElementById('descricao').focus();
                return false;
            }
            
            if (!dataVencimento) {
                e.preventDefault();
                alert('Por favor, preencha a data de vencimento.');
                document.getElementById('data_vencimento').focus();
                return false;
            }
        });
    </script>
</body>
</html>
