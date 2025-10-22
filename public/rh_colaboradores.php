<?php
// rh_colaboradores.php
// Lista de colaboradores (usuários do sistema)

session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
if (!in_array($perfil, ['ADM', 'FIN'])) {
    header('Location: dashboard.php?erro=permissao_negada');
    exit;
}

// Filtros
$filtro_nome = $_GET['nome'] ?? '';
$filtro_cargo = $_GET['cargo'] ?? '';
$filtro_status = $_GET['status'] ?? '';

// Construir query
$where_conditions = [];
$params = [];

if ($filtro_nome) {
    $where_conditions[] = "u.nome ILIKE :nome";
    $params['nome'] = "%$filtro_nome%";
}

if ($filtro_cargo) {
    $where_conditions[] = "u.cargo ILIKE :cargo";
    $params['cargo'] = "%$filtro_cargo%";
}

if ($filtro_status) {
    $where_conditions[] = "u.status_empregado = :status";
    $params['status'] = $filtro_status;
}

$where_sql = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar colaboradores
$colaboradores = [];
try {
    $sql = "
        SELECT u.id, u.nome, u.cargo, u.admissao_data, u.status_empregado,
               u.pix_tipo, u.pix_chave, u.criado_em,
               COUNT(h.id) as total_holerites,
               MAX(h.mes_competencia) as ultimo_holerite
        FROM usuarios u
        LEFT JOIN rh_holerites h ON h.usuario_id = u.id
        $where_sql
        GROUP BY u.id, u.nome, u.cargo, u.admissao_data, u.status_empregado, u.pix_tipo, u.pix_chave, u.criado_em
        ORDER BY u.nome
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $colaboradores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar colaboradores: " . $e->getMessage();
}

// Buscar cargos únicos para filtro
$cargos = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT cargo FROM usuarios WHERE cargo IS NOT NULL AND cargo != '' ORDER BY cargo");
    $cargos = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    // Ignorar erro
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Colaboradores - RH</title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .rh-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .rh-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            color: white;
            border-radius: 12px;
        }
        
        .rh-title {
            font-size: 2rem;
            font-weight: 700;
            margin: 0;
        }
        
        .rh-actions {
            display: flex;
            gap: 10px;
        }
        
        .filters-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-label {
            font-weight: 500;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .filter-input {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .colaboradores-grid {
            display: grid;
            gap: 20px;
        }
        
        .colaborador-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            transition: all 0.3s ease;
        }
        
        .colaborador-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .colaborador-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .colaborador-info {
            flex: 1;
        }
        
        .colaborador-nome {
            font-size: 18px;
            font-weight: 600;
            color: #1e40af;
            margin: 0 0 5px 0;
        }
        
        .colaborador-cargo {
            color: #64748b;
            font-size: 14px;
            margin: 0 0 10px 0;
        }
        
        .colaborador-status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-ativo {
            background: #dcfce7;
            color: #166534;
        }
        
        .status-inativo {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .colaborador-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        
        .detail-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 2px;
        }
        
        .detail-value {
            font-size: 14px;
            color: #374151;
            font-weight: 500;
        }
        
        .colaborador-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary {
            background: #1e40af;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1e3a8a;
        }
        
        .btn-secondary {
            background: #6b7280;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .btn-outline {
            background: transparent;
            color: #1e40af;
            border: 1px solid #1e40af;
        }
        
        .btn-outline:hover {
            background: #1e40af;
            color: white;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }
        
        .empty-state-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .empty-state-text {
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="rh-container">
        <!-- Header -->
        <div class="rh-header">
            <h1 class="rh-title">👥 Colaboradores</h1>
            <div class="rh-actions">
                <a href="rh_dashboard.php" class="smile-btn smile-btn-outline">← Dashboard RH</a>
                <a href="usuarios.php" class="smile-btn smile-btn-primary">+ Novo Colaborador</a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filters-section">
            <form method="GET" class="filters-form">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label">Nome</label>
                        <input type="text" name="nome" value="<?= htmlspecialchars($filtro_nome) ?>" 
                               class="filter-input" placeholder="Buscar por nome...">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Cargo</label>
                        <select name="cargo" class="filter-input">
                            <option value="">Todos os cargos</option>
                            <?php foreach ($cargos as $cargo): ?>
                            <option value="<?= htmlspecialchars($cargo) ?>" 
                                    <?= $filtro_cargo === $cargo ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cargo) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-input">
                            <option value="">Todos</option>
                            <option value="ativo" <?= $filtro_status === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                            <option value="inativo" <?= $filtro_status === 'inativo' ? 'selected' : '' ?>>Inativo</option>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="smile-btn smile-btn-primary">🔍 Filtrar</button>
                    <a href="rh_colaboradores.php" class="smile-btn smile-btn-outline">Limpar</a>
                </div>
            </form>
        </div>
        
        <!-- Lista de Colaboradores -->
        <?php if (!empty($colaboradores)): ?>
        <div class="colaboradores-grid">
            <?php foreach ($colaboradores as $colaborador): ?>
            <div class="colaborador-card">
                <div class="colaborador-header">
                    <div class="colaborador-info">
                        <h3 class="colaborador-nome"><?= htmlspecialchars($colaborador['nome']) ?></h3>
                        <p class="colaborador-cargo"><?= htmlspecialchars($colaborador['cargo'] ?? 'Cargo não informado') ?></p>
                    </div>
                    <span class="colaborador-status <?= $colaborador['status_empregado'] === 'ativo' ? 'status-ativo' : 'status-inativo' ?>">
                        <?= $colaborador['status_empregado'] === 'ativo' ? 'Ativo' : 'Inativo' ?>
                    </span>
                </div>
                
                <div class="colaborador-details">
                    <div class="detail-item">
                        <span class="detail-label">Admissão</span>
                        <span class="detail-value">
                            <?= $colaborador['admissao_data'] ? date('d/m/Y', strtotime($colaborador['admissao_data'])) : 'Não informado' ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">PIX</span>
                        <span class="detail-value">
                            <?= $colaborador['pix_tipo'] ? $colaborador['pix_tipo'] . ': ' . substr($colaborador['pix_chave'], 0, 10) . '...' : 'Não cadastrado' ?>
                        </span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Holerites</span>
                        <span class="detail-value"><?= $colaborador['total_holerites'] ?> registros</span>
                    </div>
                    <div class="detail-item">
                        <span class="detail-label">Último</span>
                        <span class="detail-value">
                            <?= $colaborador['ultimo_holerite'] ? $colaborador['ultimo_holerite'] : 'Nenhum' ?>
                        </span>
                    </div>
                </div>
                
                <div class="colaborador-actions">
                    <a href="rh_colaborador_ver.php?id=<?= $colaborador['id'] ?>" class="action-btn btn-primary">
                        👤 Ver Dossiê
                    </a>
                    <a href="usuarios.php?id=<?= $colaborador['id'] ?>" class="action-btn btn-secondary">
                        ✏️ Editar
                    </a>
                    <?php if ($colaborador['total_holerites'] > 0): ?>
                    <a href="rh_colaborador_ver.php?id=<?= $colaborador['id'] ?>&tab=holerites" class="action-btn btn-outline">
                        💰 Holerites
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">👥</div>
            <div class="empty-state-title">Nenhum colaborador encontrado</div>
            <div class="empty-state-text">
                <?php if ($filtro_nome || $filtro_cargo || $filtro_status): ?>
                Tente ajustar os filtros ou <a href="rh_colaboradores.php">limpar a busca</a>.
                <?php else: ?>
                <a href="usuarios.php">Cadastre o primeiro colaborador</a> para começar.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
