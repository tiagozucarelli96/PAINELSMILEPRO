<?php
// demandas.php — Sistema principal de demandas
session_start();
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/demandas_helper.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Função temporária para verificar acesso a demandas
if (!function_exists('lc_can_access_demandas')) {
    function lc_can_access_demandas(): bool {
        $perfil = $_SESSION['perfil'] ?? 'ADM';
        return in_array($perfil, ['ADM', 'OPER']);
    }
}

// Verificar permissões
if (!lc_can_access_demandas()) {
    header('Location: index.php?page=dashboard');
    exit;
}

$demandas = new DemandasHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    switch ($acao) {
        case 'criar_quadro':
            $nome = $_POST['nome'] ?? '';
            $descricao = $_POST['descricao'] ?? '';
            $cor = $_POST['cor'] ?? '#3b82f6';
            
            if ($nome && lc_can_create_quadros()) {
                $quadro_id = $demandas->criarQuadro($nome, $descricao, $cor, $usuario_id);
                header('Location: demandas_quadro.php?id=' . $quadro_id);
                exit;
            }
            break;
            
        case 'concluir_cartao':
            $cartao_id = $_POST['cartao_id'] ?? 0;
            if ($cartao_id) {
                $demandas->concluirCartao($cartao_id, $usuario_id);
            }
            break;
    }
}

// Obter dados para o dashboard
$agenda_hoje = $demandas->obterAgendaDia($usuario_id, false);
$agenda_48h = $demandas->obterAgendaDia($usuario_id, true);
$notificacoes = $demandas->contarNotificacoesNaoLidas($usuario_id);

// Obter quadros do usuário
$stmt = $pdo->prepare("
    SELECT dq.*, 
           COUNT(dc.id) as total_cartoes,
           SUM(CASE WHEN dc.concluido = FALSE THEN 1 ELSE 0 END) as cartoes_pendentes
    FROM demandas_quadros dq
    LEFT JOIN demandas_participantes dp ON dq.id = dp.quadro_id
    LEFT JOIN demandas_cartoes dc ON dq.id = dc.quadro_id AND dc.arquivado = FALSE
    WHERE dp.usuario_id = ? AND dq.ativo = TRUE
    GROUP BY dq.id
    ORDER BY dq.atualizado_em DESC
");
$stmt->execute([$usuario_id]);
$quadros = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demandas - GRUPO Smile EVENTOS</title>
    <link rel="stylesheet" href="estilo.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f6;
            color: #333;
            margin: 0;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            transition: margin-left 0.3s ease;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }

        h1 {
            color: #1e3a8a;
            font-size: 2.2rem;
            margin-bottom: 25px;
            border-bottom: 2px solid #e0e7ff;
            padding-bottom: 15px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-primary {
            background-color: #1e3a8a;
            color: #fff;
            border: 1px solid #1e3a8a;
        }

        .btn-primary:hover {
            background-color: #1c327a;
            border-color: #1c327a;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background-color: #6b7280;
            color: #fff;
            border: 1px solid #6b7280;
        }

        .btn-success {
            background-color: #10b981;
            color: #fff;
            border: 1px solid #10b981;
        }

        .btn-danger {
            background-color: #ef4444;
            color: #fff;
            border: 1px solid #ef4444;
        }

        .btn-outline {
            background-color: transparent;
            color: #1e3a8a;
            border: 1px solid #1e3a8a;
        }

        .btn-outline:hover {
            background-color: #e0e7ff;
            transform: translateY(-1px);
        }

        .header-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .agenda-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
        }

        .agenda-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .agenda-item {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            backdrop-filter: blur(10px);
        }

        .agenda-item:last-child {
            margin-bottom: 0;
        }

        .agenda-item-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .agenda-item-meta {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .agenda-actions {
            margin-top: 15px;
        }

        .quadros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .quadro-card {
            background: #f8faff;
            border: 1px solid #e0e7ff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
        }

        .quadro-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .quadro-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .quadro-nome {
            font-size: 1.2rem;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0;
        }

        .quadro-cor {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .quadro-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .stat {
            text-align: center;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e3a8a;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #6b7280;
            text-transform: uppercase;
            font-weight: 600;
        }

        .quadro-actions {
            display: flex;
            gap: 10px;
        }

        .notification-badge {
            position: relative;
        }

        .notification-badge::after {
            content: attr(data-count);
            position: absolute;
            top: -8px;
            right: -8px;
            background: #ef4444;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 15px;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
            position: relative;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .close-button {
            color: #aaa;
            position: absolute;
            top: 15px;
            right: 25px;
            font-size: 30px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-button:hover,
        .close-button:focus {
            color: #333;
            text-decoration: none;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
            box-sizing: border-box;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            border-color: #1e3a8a;
            outline: none;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 30px;
            border-top: 1px solid #e0e7ff;
            padding-top: 20px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            .container {
                padding: 15px;
            }
            h1 {
                font-size: 1.8rem;
            }
            .quadros-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="container">
            <div class="header-actions">
                <h1>📋 Demandas</h1>
                <div>
                    <?php if (lc_can_create_quadros()): ?>
                        <button class="btn btn-primary" onclick="openCreateQuadroModal()">➕ Novo Quadro</button>
                    <?php endif; ?>
                    <a href="demandas_notificacoes.php" class="btn btn-outline notification-badge" data-count="<?= $notificacoes ?>">
                        🔔 Notificações
                    </a>
                </div>
            </div>

            <!-- Agenda do Dia -->
            <div class="agenda-section">
                <div class="agenda-title">
                    📅 Agenda do Dia
                    <?php if (count($agenda_hoje) > 0): ?>
                        <span class="notification-badge" data-count="<?= count($agenda_hoje) ?>"></span>
                    <?php endif; ?>
                </div>
                
                <?php if (count($agenda_hoje) > 0): ?>
                    <?php foreach ($agenda_hoje as $item): ?>
                        <div class="agenda-item">
                            <div class="agenda-item-title"><?= htmlspecialchars($item['titulo']) ?></div>
                            <div class="agenda-item-meta">
                                📋 <?= htmlspecialchars($item['quadro_nome']) ?> • 
                                ⏰ <?= date('H:i', strtotime($item['vencimento'])) ?>
                                <?php if ($item['prioridade'] === 'urgente'): ?>
                                    • 🔴 Urgente
                                <?php elseif ($item['prioridade'] === 'alta'): ?>
                                    • 🟠 Alta
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="agenda-item">
                        <div class="agenda-item-title">🎉 Nenhuma tarefa vencendo hoje!</div>
                        <div class="agenda-item-meta">Você está em dia com suas responsabilidades.</div>
                    </div>
                <?php endif; ?>
                
                <div class="agenda-actions">
                    <a href="demandas_agenda.php" class="btn btn-outline">📅 Ver Agenda Completa</a>
                    <?php if (count($agenda_48h) > count($agenda_hoje)): ?>
                        <button class="btn btn-outline" onclick="toggle48h()">👁️ Exibir próximas 48h</button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quadros -->
            <h2>📊 Meus Quadros</h2>
            
            <?php if (count($quadros) > 0): ?>
                <div class="quadros-grid">
                    <?php foreach ($quadros as $quadro): ?>
                        <div class="quadro-card">
                            <div class="quadro-header">
                                <h3 class="quadro-nome"><?= htmlspecialchars($quadro['nome']) ?></h3>
                                <div class="quadro-cor" style="background-color: <?= htmlspecialchars($quadro['cor']) ?>"></div>
                            </div>
                            
                            <div class="quadro-stats">
                                <div class="stat">
                                    <div class="stat-number"><?= $quadro['total_cartoes'] ?></div>
                                    <div class="stat-label">Total</div>
                                </div>
                                <div class="stat">
                                    <div class="stat-number"><?= $quadro['cartoes_pendentes'] ?></div>
                                    <div class="stat-label">Pendentes</div>
                                </div>
                            </div>
                            
                            <div class="quadro-actions">
                                <a href="demandas_quadro.php?id=<?= $quadro['id'] ?>" class="btn btn-primary">👁️ Abrir</a>
                                <a href="demandas_quadro.php?id=<?= $quadro['id'] ?>&action=settings" class="btn btn-outline">⚙️ Configurar</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <h3>Nenhum quadro encontrado</h3>
                    <p>Você ainda não foi convidado para nenhum quadro ou não criou nenhum.</p>
                    <?php if (lc_can_create_quadros()): ?>
                        <button class="btn btn-primary" onclick="openCreateQuadroModal()">➕ Criar Primeiro Quadro</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Criar Quadro -->
    <div id="createQuadroModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeCreateQuadroModal()">&times;</span>
            <h2>Criar Novo Quadro</h2>
            
            <form method="POST">
                <input type="hidden" name="acao" value="criar_quadro">
                
                <div class="form-group">
                    <label for="nome">Nome do Quadro</label>
                    <input type="text" id="nome" name="nome" required>
                </div>
                
                <div class="form-group">
                    <label for="descricao">Descrição (opcional)</label>
                    <textarea id="descricao" name="descricao" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="cor">Cor</label>
                    <select id="cor" name="cor">
                        <option value="#3b82f6">Azul</option>
                        <option value="#10b981">Verde</option>
                        <option value="#f59e0b">Amarelo</option>
                        <option value="#ef4444">Vermelho</option>
                        <option value="#8b5cf6">Roxo</option>
                        <option value="#06b6d4">Ciano</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-outline" onclick="closeCreateQuadroModal()">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Criar Quadro</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openCreateQuadroModal() {
            document.getElementById('createQuadroModal').style.display = 'flex';
        }

        function closeCreateQuadroModal() {
            document.getElementById('createQuadroModal').style.display = 'none';
        }

        function toggle48h() {
            // Implementar toggle para mostrar próximas 48h
            location.href = 'demandas_agenda.php?periodo=48h';
        }

        // Fechar modal ao clicar fora
        window.onclick = function(event) {
            const modal = document.getElementById('createQuadroModal');
            if (event.target === modal) {
                closeCreateQuadroModal();
            }
        }
    </script>
</body>
</html>