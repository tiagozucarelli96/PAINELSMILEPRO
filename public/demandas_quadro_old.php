<?php
// demandas_quadro.php - Visualizar quadro de demandas
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

$quadro_id = $_GET['id'] ?? 0;
if (!$quadro_id) {
    header('Location: index.php?page=demandas');
    exit;
}

$demandas = new DemandasHelper();
$usuario_id = $_SESSION['user_id'] ?? 1;

// Obter dados do quadro
$quadro = $demandas->obterQuadro($quadro_id, $usuario_id);
if (!$quadro) {
    header('Location: index.php?page=demandas');
    exit;
}

// Obter colunas e cartões
$colunas = $demandas->obterColunasQuadro($quadro_id);
$cartoes = $demandas->obterCartoesQuadro($quadro_id);

// Renderizar página
includeSidebar('Demandas');
?>

<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    .page-container {
        padding: 2rem;
        max-width: 100%;
        overflow-x: auto;
    }
    
    .quadro-header {
        background: white;
        padding: 1.5rem;
        border-radius: 12px;
        margin-bottom: 2rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .quadro-header h1 {
        font-size: 2rem;
        color: #1f2937;
        margin-bottom: 0.5rem;
    }
    
    .quadro-header p {
        color: #6b7280;
    }
    
    .colunas-container {
        display: flex;
        gap: 1.5rem;
        min-height: 500px;
    }
    
    .coluna {
        flex: 1;
        min-width: 300px;
        background: #f3f4f6;
        border-radius: 8px;
        padding: 1rem;
    }
    
    .coluna-header {
        background: white;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        font-weight: 600;
        color: #1f2937;
    }
    
    .cartao {
        background: white;
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 0.75rem;
        cursor: pointer;
        transition: all 0.2s;
        border-left: 4px solid #3b82f6;
    }
    
    .cartao:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .cartao-titulo {
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #1f2937;
    }
    
    .btn {
        padding: 0.5rem 1rem;
        border-radius: 6px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
        transition: all 0.2s;
    }
    
    .btn-outline {
        background: white;
        color: #3b82f6;
        border: 1px solid #3b82f6;
    }
</style>

<div class="page-container">
    <div class="quadro-header">
        <h1><?= htmlspecialchars($quadro['nome']) ?></h1>
        <p><?= htmlspecialchars($quadro['descricao'] ?? 'Sem descrição') ?></p>
        <div style="margin-top: 1rem;">
            <a href="index.php?page=demandas" class="btn btn-outline">← Voltar</a>
        </div>
    </div>
    
    <?php if (empty($colunas)): ?>
        <div style="text-align: center; padding: 3rem; background: white; border-radius: 12px;">
            <p style="color: #6b7280;">Nenhuma coluna configurada ainda.</p>
        </div>
    <?php else: ?>
        <div class="colunas-container">
            <?php foreach ($colunas as $coluna): ?>
                <div class="coluna">
                    <div class="coluna-header">
                        <?= htmlspecialchars($coluna['nome']) ?>
                    </div>
                    
                    <?php 
                    $cartoes_coluna = array_filter($cartoes, function($c) use ($coluna) {
                        return $c['coluna_id'] == $coluna['id'];
                    });
                    ?>
                    
                    <?php foreach ($cartoes_coluna as $cartao): ?>
                        <div class="cartao">
                            <div class="cartao-titulo"><?= htmlspecialchars($cartao['titulo']) ?></div>
                            <?php if ($cartao['descricao']): ?>
                                <div style="font-size: 0.875rem; color: #6b7280; margin-top: 0.5rem;">
                                    <?= htmlspecialchars(substr($cartao['descricao'], 0, 100)) ?>...
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php endSidebar(); ?>

