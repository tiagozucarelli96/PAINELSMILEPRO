<?php
// demandas.php — Sistema principal de demandas
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/demandas_helper.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';

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

// Obter quadros do usuário usando o helper
$quadros = $demandas->obterQuadrosUsuario($usuario_id);

// Renderizar página completa
header('Content-Type: text/html; charset=utf-8');
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demandas - GRUPO Smile EVENTOS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
<div class="page-container">
    <!-- sidebar.php removido; sidebar é carregada via includeSidebar() -->

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
</div><!-- page-container -->
</body>
</html>