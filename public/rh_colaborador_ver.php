<?php
// rh_colaborador_ver.php
// Dossiê completo do colaborador

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/lc_permissions_helper.php';

// Verificar permissões
$perfil = lc_get_user_perfil();
$usuario_logado_id = $_SESSION['usuario_id'] ?? null;

$colaborador_id = $_GET['id'] ?? null;
if (!$colaborador_id) {
    header('Location: rh_colaboradores.php?erro=colaborador_nao_encontrado');
    exit;
}

// Verificar se o usuário pode acessar este colaborador
$pode_acessar = false;
if (in_array($perfil, ['ADM', 'FIN'])) {
    $pode_acessar = true; // ADM e FIN podem ver todos
} elseif ($colaborador_id == $usuario_logado_id) {
    $pode_acessar = true; // Usuário pode ver seus próprios dados
}

if (!$pode_acessar) {
    header('Location: dashboard.php?erro=acesso_negado');
    exit;
}

// Buscar dados do colaborador
$colaborador = null;
try {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               COUNT(h.id) as total_holerites,
               MAX(h.mes_competencia) as ultimo_holerite
        FROM usuarios u
        LEFT JOIN rh_holerites h ON h.usuario_id = u.id
        WHERE u.id = ?
        GROUP BY u.id
    ");
    $stmt->execute([$colaborador_id]);
    $colaborador = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$colaborador) {
        header('Location: rh_colaboradores.php?erro=colaborador_nao_encontrado');
        exit;
    }
} catch (Exception $e) {
    $erro = "Erro ao buscar dados do colaborador: " . $e->getMessage();
}

// Buscar holerites
$holerites = [];
try {
    $stmt = $pdo->prepare("
        SELECT h.*, 
               COUNT(a.id) as total_anexos
        FROM rh_holerites h
        LEFT JOIN rh_anexos a ON a.holerite_id = h.id
        WHERE h.usuario_id = ?
        GROUP BY h.id
        ORDER BY h.mes_competencia DESC
    ");
    $stmt->execute([$colaborador_id]);
    $holerites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar erro
}

// Buscar anexos gerais do colaborador
$anexos_gerais = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.nome as autor_nome
        FROM rh_anexos a
        LEFT JOIN usuarios u ON u.id = a.autor_id
        WHERE a.usuario_id = ? AND a.holerite_id IS NULL
        ORDER BY a.criado_em DESC
    ");
    $stmt->execute([$colaborador_id]);
    $anexos_gerais = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignorar erro
}

// Tab ativa
$tab_ativa = $_GET['tab'] ?? 'dados';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dossiê - <?= htmlspecialchars($colaborador['nome']) ?></title>
    <link rel="stylesheet" href="estilo.css">
    <link rel="stylesheet" href="css/smile-ui.css">
    <style>
        .rh-container {
            max-width: 1200px;
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
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
        }
        
        .rh-actions {
            display: flex;
            gap: 10px;
        }
        
        .tabs-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .tabs-header {
            display: flex;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .tab-button {
            flex: 1;
            padding: 15px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #64748b;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        
        .tab-button:hover {
            color: #1e40af;
            background: #f8fafc;
        }
        
        .tab-button.active {
            color: #1e40af;
            border-bottom-color: #1e40af;
            background: #f8fafc;
        }
        
        .tab-content {
            padding: 30px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }
        
        .info-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
        }
        
        .info-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e40af;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 500;
            color: #374151;
        }
        
        .info-value {
            color: #64748b;
            text-align: right;
        }
        
        .holerites-list {
            display: grid;
            gap: 15px;
        }
        
        .holerite-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #e5e7eb;
        }
        
        .holerite-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .holerite-competencia {
            font-weight: 600;
            color: #1e40af;
        }
        
        .holerite-valor {
            font-weight: 600;
            color: #059669;
        }
        
        .holerite-meta {
            font-size: 12px;
            color: #64748b;
        }
        
        .anexos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .anexo-item {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 15px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .anexo-item:hover {
            border-color: #1e40af;
            transform: translateY(-2px);
        }
        
        .anexo-icon {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .anexo-nome {
            font-weight: 500;
            color: #374151;
            margin-bottom: 5px;
        }
        
        .anexo-meta {
            font-size: 12px;
            color: #64748b;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #64748b;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .masked-pix {
            font-family: monospace;
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="rh-container">
        <!-- Header -->
        <div class="rh-header">
            <h1 class="rh-title">👤 <?= htmlspecialchars($colaborador['nome']) ?></h1>
            <div class="rh-actions">
                <a href="rh_colaboradores.php" class="smile-btn smile-btn-outline">← Voltar</a>
                <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                <a href="usuarios.php?id=<?= $colaborador['id'] ?>" class="smile-btn smile-btn-primary">✏️ Editar</a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-button <?= $tab_ativa === 'dados' ? 'active' : '' ?>" 
                        onclick="showTab('dados')">📋 Dados</button>
                <button class="tab-button <?= $tab_ativa === 'holerites' ? 'active' : '' ?>" 
                        onclick="showTab('holerites')">💰 Holerites</button>
                <button class="tab-button <?= $tab_ativa === 'anexos' ? 'active' : '' ?>" 
                        onclick="showTab('anexos')">📎 Anexos</button>
            </div>
            
            <!-- Tab Dados -->
            <div id="tab-dados" class="tab-content" style="<?= $tab_ativa !== 'dados' ? 'display: none;' : '' ?>">
                <div class="info-grid">
                    <div class="info-section">
                        <h3 class="info-title">👤 Dados Pessoais</h3>
                        <div class="info-item">
                            <span class="info-label">Nome Completo</span>
                            <span class="info-value"><?= htmlspecialchars($colaborador['nome']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">CPF</span>
                            <span class="info-value">
                                <?= $colaborador['cpf'] ? substr($colaborador['cpf'], 0, 3) . '.***.***-' . substr($colaborador['cpf'], -2) : 'Não informado' ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Cargo</span>
                            <span class="info-value"><?= htmlspecialchars($colaborador['cargo'] ?? 'Não informado') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Status</span>
                            <span class="info-value">
                                <span class="smile-badge <?= $colaborador['status_empregado'] === 'ativo' ? 'smile-badge-success' : 'smile-badge-danger' ?>">
                                    <?= $colaborador['status_empregado'] === 'ativo' ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h3 class="info-title">💼 Dados Funcionais</h3>
                        <div class="info-item">
                            <span class="info-label">Data de Admissão</span>
                            <span class="info-value">
                                <?= $colaborador['admissao_data'] ? date('d/m/Y', strtotime($colaborador['admissao_data'])) : 'Não informado' ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Salário Base</span>
                            <span class="info-value">
                                <?= $colaborador['salario_base'] ? 'R$ ' . number_format($colaborador['salario_base'], 2, ',', '.') : 'Não informado' ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Cadastrado em</span>
                            <span class="info-value"><?= date('d/m/Y H:i', strtotime($colaborador['criado_em'])) ?></span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h3 class="info-title">💳 Dados Bancários</h3>
                        <div class="info-item">
                            <span class="info-label">Tipo PIX</span>
                            <span class="info-value"><?= htmlspecialchars($colaborador['pix_tipo'] ?? 'Não cadastrado') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Chave PIX</span>
                            <span class="info-value">
                                <?php if ($colaborador['pix_chave']): ?>
                                    <span class="masked-pix">
                                        <?= substr($colaborador['pix_chave'], 0, 4) . '...' . substr($colaborador['pix_chave'], -4) ?>
                                    </span>
                                    <?php if (in_array($perfil, ['ADM', 'FIN'])): ?>
                                    <button onclick="copiarPix('<?= htmlspecialchars($colaborador['pix_chave']) ?>')" 
                                            class="smile-btn smile-btn-sm smile-btn-outline" style="margin-left: 8px;">
                                        📋 Copiar
                                    </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    Não cadastrado
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tab Holerites -->
            <div id="tab-holerites" class="tab-content" style="<?= $tab_ativa !== 'holerites' ? 'display: none;' : '' ?>">
                <?php if (!empty($holerites)): ?>
                <div class="holerites-list">
                    <?php foreach ($holerites as $holerite): ?>
                    <div class="holerite-item">
                        <div class="holerite-header">
                            <div class="holerite-competencia"><?= $holerite['mes_competencia'] ?></div>
                            <div class="holerite-valor">
                                <?= $holerite['valor_liquido'] ? 'R$ ' . number_format($holerite['valor_liquido'], 2, ',', '.') : 'Valor não informado' ?>
                            </div>
                        </div>
                        <div class="holerite-meta">
                            <?= $holerite['total_anexos'] ?> anexo(s) • 
                            Criado em <?= date('d/m/Y H:i', strtotime($holerite['criado_em'])) ?>
                            <?php if ($holerite['observacao']): ?>
                            <br><strong>Obs:</strong> <?= htmlspecialchars($holerite['observacao']) ?>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 10px;">
                            <a href="download_anexo.php?tipo=rh&id=<?= $holerite['id'] ?>" 
                               class="smile-btn smile-btn-sm smile-btn-primary">
                                📄 Baixar Holerite
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">💰</div>
                    <div style="font-size: 18px; font-weight: 600; margin-bottom: 10px;">Nenhum holerite encontrado</div>
                    <div>Este colaborador ainda não possui holerites cadastrados.</div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Tab Anexos -->
            <div id="tab-anexos" class="tab-content" style="<?= $tab_ativa !== 'anexos' ? 'display: none;' : '' ?>">
                <?php if (!empty($anexos_gerais)): ?>
                <div class="anexos-grid">
                    <?php foreach ($anexos_gerais as $anexo): ?>
                    <div class="anexo-item">
                        <div class="anexo-icon">
                            <?php
                            $ext = strtolower(pathinfo($anexo['nome_original'], PATHINFO_EXTENSION));
                            if (in_array($ext, ['pdf'])) echo '📄';
                            elseif (in_array($ext, ['jpg', 'jpeg', 'png'])) echo '🖼️';
                            else echo '📎';
                            ?>
                        </div>
                        <div class="anexo-nome"><?= htmlspecialchars($anexo['nome_original']) ?></div>
                        <div class="anexo-meta">
                            <?= $anexo['tipo_anexo'] ?> • 
                            <?= number_format($anexo['tamanho_bytes'] / 1024, 1) ?> KB
                        </div>
                        <div style="margin-top: 10px;">
                            <a href="download_anexo.php?tipo=rh_geral&id=<?= $anexo['id'] ?>" 
                               class="smile-btn smile-btn-sm smile-btn-primary">
                                📥 Baixar
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📎</div>
                    <div style="font-size: 18px; font-weight: 600; margin-bottom: 10px;">Nenhum anexo encontrado</div>
                    <div>Este colaborador ainda não possui documentos anexados.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function showTab(tabName) {
            // Esconder todas as tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Remover classe active de todos os botões
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Mostrar tab selecionada
            document.getElementById('tab-' + tabName).style.display = 'block';
            
            // Adicionar classe active ao botão
            event.target.classList.add('active');
            
            // Atualizar URL
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }
        
        function copiarPix(pix) {
            navigator.clipboard.writeText(pix).then(() => {
                alert('Chave PIX copiada para a área de transferência!');
            }).catch(() => {
                alert('Erro ao copiar chave PIX');
            });
        }
    </script>
</body>
</html>
