<?php
// contabilidade_admin_guias.php — Gestão administrativa de Guias
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar permissão de administrador
if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/notificacoes_helper.php';
require_once __DIR__ . '/sidebar_integration.php';

$mensagem = '';
$erro = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'alterar_status') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            
            if (!in_array($status, ['aberto', 'em_andamento', 'concluido', 'pago', 'vencido', 'cancelado'])) {
                throw new Exception('Status inválido');
            }
            
            $stmt = $pdo->prepare("
                UPDATE contabilidade_guias 
                SET status = :status, atualizado_em = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':status' => $status, ':id' => $id]);
            $mensagem = 'Status atualizado com sucesso!';
            
            // Registrar notificação de alteração de status (ETAPA 13)
            try {
                $notificacoes = new NotificacoesHelper();
                $notificacoes->registrarNotificacao(
                    'contabilidade',
                    'alteracao_status',
                    'guia',
                    $id,
                    "Status da guia alterado para: " . ucfirst(str_replace('_', ' ', $status)),
                    '',
                    'ambos'
                );
            } catch (Exception $e) {
                // Ignorar erro silenciosamente
            }
            
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
    
    if ($acao === 'excluir') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM contabilidade_guias WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $mensagem = 'Guia excluída com sucesso!';
        } catch (Exception $e) {
            $erro = $e->getMessage();
        }
    }
}

// Buscar empresas para filtro
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
}

// Filtros
$filtro_status = $_GET['status'] ?? '';
$filtro_mes = $_GET['mes'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_empresa = $_GET['empresa'] ?? '';

$where_conditions = [];
$params = [];

if ($filtro_status) {
    $where_conditions[] = "g.status = :status";
    $params[':status'] = $filtro_status;
}

if ($filtro_mes) {
    $where_conditions[] = "TO_CHAR(g.data_vencimento, 'YYYY-MM') = :mes";
    $params[':mes'] = $filtro_mes;
}

if ($filtro_tipo) {
    if ($filtro_tipo === 'parcela') {
        $where_conditions[] = "g.e_parcela = TRUE";
    } else {
        $where_conditions[] = "g.e_parcela = FALSE";
    }
}

if ($filtro_empresa) {
    $where_conditions[] = "g.empresa_id = :empresa";
    $params[':empresa'] = (int)$filtro_empresa;
}

$where_sql = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar guias
$guias = [];
try {
    $sql = "
        SELECT g.*, p.descricao as parcelamento_desc, p.total_parcelas,
               e.nome as empresa_nome, e.documento as empresa_documento
        FROM contabilidade_guias g
        LEFT JOIN contabilidade_parcelamentos p ON p.id = g.parcelamento_id
        LEFT JOIN contabilidade_empresas e ON e.id = g.empresa_id
        $where_sql
        ORDER BY g.criado_em DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $guias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $erro = "Erro ao buscar guias: " . $e->getMessage();
}

// Contadores
$contadores = ['aberto' => 0, 'em_andamento' => 0, 'concluido' => 0, 'pago' => 0];
try {
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as total
        FROM contabilidade_guias
        GROUP BY status
    ");
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stats as $stat) {
        if (isset($contadores[$stat['status']])) {
            $contadores[$stat['status']] = (int)$stat['total'];
        }
    }
} catch (Exception $e) {
    // Ignorar
}

ob_start();
?>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f5f5f5;
}
.container { max-width: 1400px; margin: 0 auto; padding: 2rem; }
.header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    color: white;
    padding: 1.5rem 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    border-radius: 12px;
}
.header h1 { font-size: 1.5rem; font-weight: 700; }
.btn-back { background: rgba(255,255,255,0.2); color: white; padding: 0.5rem 1rem; border-radius: 6px; text-decoration: none; }
.alert { padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; }
.alert-success { background: #d1fae5; color: #065f46; }
.alert-error { background: #fee2e2; color: #991b1b; }
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}
.stat-card {
    background: white;
    padding: 1.5rem;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    text-align: center;
}
.stat-number { font-size: 2rem; font-weight: 700; color: #1e40af; }
.stat-label { color: #64748b; font-size: 0.875rem; margin-top: 0.5rem; }
.filters-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
}
.form-group { display: flex; flex-direction: column; }
.form-label { font-weight: 500; color: #374151; margin-bottom: 0.5rem; }
.form-input, .form-select {
    padding: 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: 1rem;
}
.table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.table th {
    background: #1e40af;
    color: white;
    padding: 1rem;
    text-align: left;
    font-weight: 600;
}
.table td {
    padding: 1rem;
    border-bottom: 1px solid #e5e7eb;
}
.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 12px;
    font-size: 0.875rem;
    font-weight: 500;
}
.badge-aberto { background: #fef3c7; color: #92400e; }
.badge-em_andamento { background: #dbeafe; color: #1e40af; }
.badge-concluido { background: #d1fae5; color: #065f46; }
.badge-pago { background: #d1fae5; color: #065f46; }
.btn-action {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin-right: 0.5rem;
}
.btn-view { background: #1e40af; color: white; }
.btn-edit { background: #059669; color: white; }
.btn-delete { background: #dc2626; color: white; }
.btn-download { background: #6b7280; color: white; }
</style>

<div class="container">
    <div class="header">
        <h1>💰 Guias para Pagamento - Gestão Administrativa</h1>
        <a href="index.php?page=contabilidade" class="btn-back">← Voltar</a>
    </div>
    
    <?php if ($mensagem): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($mensagem) ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $contadores['aberto'] ?></div>
            <div class="stat-label">Abertas</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $contadores['em_andamento'] ?></div>
            <div class="stat-label">Em Andamento</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $contadores['concluido'] ?></div>
            <div class="stat-label">Concluídas</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $contadores['pago'] ?></div>
            <div class="stat-label">Pagas</div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filters-section">
        <form method="GET" style="display: contents;">
            <div class="filters-grid">
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="aberto" <?= $filtro_status === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                        <option value="em_andamento" <?= $filtro_status === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                        <option value="concluido" <?= $filtro_status === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                        <option value="pago" <?= $filtro_status === 'pago' ? 'selected' : '' ?>>Pago</option>
                        <option value="vencido" <?= $filtro_status === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Mês (YYYY-MM)</label>
                    <input type="month" name="mes" class="form-input" value="<?= htmlspecialchars($filtro_mes) ?>" onchange="this.form.submit()">
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="parcela" <?= $filtro_tipo === 'parcela' ? 'selected' : '' ?>>Parceladas</option>
                        <option value="unica" <?= $filtro_tipo === 'unica' ? 'selected' : '' ?>>Únicas</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Empresa</label>
                    <select name="empresa" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas</option>
                        <?php foreach ($empresas as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $filtro_empresa == $emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Tabela de Guias -->
    <table class="table">
        <thead>
            <tr>
                <th>Empresa</th>
                <th>Descrição</th>
                <th>Vencimento</th>
                <th>Parcela</th>
                <th>Status</th>
                <th>Arquivo</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($guias)): ?>
            <tr>
                <td colspan="7" style="text-align: center; padding: 3rem; color: #64748b;">
                    Nenhuma guia encontrada com os filtros selecionados.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($guias as $guia): ?>
            <tr>
                <td><?= $guia['empresa_nome'] ? htmlspecialchars($guia['empresa_nome']) : '-' ?></td>
                <td><?= htmlspecialchars($guia['descricao']) ?></td>
                <td><?= $guia['data_vencimento'] ? date('d/m/Y', strtotime($guia['data_vencimento'])) : '-' ?></td>
                <td>
                    <?php if ($guia['e_parcela'] && $guia['numero_parcela']): ?>
                        Parcela <?= $guia['numero_parcela'] ?>/<?= $guia['total_parcelas'] ?>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= str_replace('_', '-', $guia['status']) ?>">
                        <?= ucfirst(str_replace('_', ' ', $guia['status'])) ?>
                    </span>
                </td>
                <td>
                    <?php if ($guia['chave_storage'] || $guia['arquivo_url']): ?>
                        <button type="button"
                                class="btn-action btn-download btn-ver-arquivo"
                                data-guia-id="<?= $guia['id'] ?>"
                                data-arquivo-nome="<?= htmlspecialchars($guia['arquivo_nome'] ?? 'arquivo') ?>">
                            📎 Ver
                        </button>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="acao" value="alterar_status">
                        <input type="hidden" name="id" value="<?= $guia['id'] ?>">
                        <select name="status" onchange="this.form.submit()" style="padding: 0.5rem; border-radius: 6px; border: 1px solid #d1d5db;">
                            <option value="aberto" <?= $guia['status'] === 'aberto' ? 'selected' : '' ?>>Aberto</option>
                            <option value="em_andamento" <?= $guia['status'] === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                            <option value="concluido" <?= $guia['status'] === 'concluido' ? 'selected' : '' ?>>Concluído</option>
                            <option value="pago" <?= $guia['status'] === 'pago' ? 'selected' : '' ?>>Pago</option>
                            <option value="vencido" <?= $guia['status'] === 'vencido' ? 'selected' : '' ?>>Vencido</option>
                        </select>
                    </form>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja excluir esta guia?');">
                        <input type="hidden" name="acao" value="excluir">
                        <input type="hidden" name="id" value="<?= $guia['id'] ?>">
                        <button type="submit" class="btn-action btn-delete">🗑️ Excluir</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal de Preview de Arquivo -->
<div id="modal-arquivo" class="modal-arquivo" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 10000; overflow: auto;">
    <div class="modal-arquivo-header" style="position: fixed; top: 0; left: 0; right: 0; background: rgba(0,0,0,0.8); padding: 1rem; display: flex; justify-content: space-between; align-items: center; z-index: 10001;">
        <h3 id="modal-arquivo-titulo" style="color: white; margin: 0; font-size: 1.1rem;"></h3>
        <div class="modal-arquivo-toolbar" style="display: flex; gap: 0.5rem;">
            <button id="btn-zoom-in" class="btn-toolbar" style="display: none; background: #1e40af; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">🔍+</button>
            <button id="btn-zoom-out" class="btn-toolbar" style="display: none; background: #1e40af; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">🔍-</button>
            <button id="btn-zoom-reset" class="btn-toolbar" style="display: none; background: #1e40af; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">↺</button>
            <button id="btn-download" class="btn-toolbar" style="background: #10b981; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">⬇️ Download</button>
            <button id="btn-fechar-modal" class="btn-toolbar" style="background: #ef4444; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; cursor: pointer;">✕ Fechar</button>
        </div>
    </div>
    <div class="modal-arquivo-content" style="margin-top: 80px; padding: 2rem; display: flex; justify-content: center; align-items: center; min-height: calc(100vh - 80px);">
        <img id="modal-arquivo-img" style="display: none; max-width: 100%; max-height: calc(100vh - 120px); object-fit: contain; transition: transform 0.3s ease; cursor: zoom-in;" />
        <iframe id="modal-arquivo-pdf" style="display: none; width: 100%; height: calc(100vh - 120px); border: none; background: white;"></iframe>
        <div id="modal-arquivo-loading" style="color: white; font-size: 1.2rem;">Carregando...</div>
    </div>
</div>

<script>
    // Modal de Preview de Arquivo
    (function() {
        const modal = document.getElementById('modal-arquivo');
        const modalImg = document.getElementById('modal-arquivo-img');
        const modalPdf = document.getElementById('modal-arquivo-pdf');
        const modalTitulo = document.getElementById('modal-arquivo-titulo');
        const modalLoading = document.getElementById('modal-arquivo-loading');
        const btnFechar = document.getElementById('btn-fechar-modal');
        const btnDownload = document.getElementById('btn-download');
        const btnZoomIn = document.getElementById('btn-zoom-in');
        const btnZoomOut = document.getElementById('btn-zoom-out');
        const btnZoomReset = document.getElementById('btn-zoom-reset');

        let currentZoom = 1;
        let currentGuiaId = null;
        let currentDownloadUrl = null;
        let isImage = false;

        function abrirModal(guiaId, arquivoNome) {
            currentGuiaId = guiaId;
            currentDownloadUrl = `contabilidade_download.php?tipo=guia&id=${guiaId}`;
            modalTitulo.textContent = arquivoNome || 'Visualizar Arquivo';
            modal.style.display = 'block';
            modalLoading.style.display = 'block';
            modalImg.style.display = 'none';
            modalPdf.style.display = 'none';

            const extensao = arquivoNome ? arquivoNome.split('.').pop().toLowerCase() : '';
            isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].includes(extensao);
            const isPdf = extensao === 'pdf';

            if (isImage) {
                btnZoomIn.style.display = 'inline-block';
                btnZoomOut.style.display = 'inline-block';
                btnZoomReset.style.display = 'inline-block';
            } else {
                btnZoomIn.style.display = 'none';
                btnZoomOut.style.display = 'none';
                btnZoomReset.style.display = 'none';
            }

            if (isImage) {
                modalImg.src = currentDownloadUrl;
                modalImg.onload = function() {
                    modalLoading.style.display = 'none';
                    modalImg.style.display = 'block';
                    currentZoom = 1;
                    modalImg.style.transform = `scale(${currentZoom})`;
                };
                modalImg.onerror = function() {
                    modalLoading.textContent = 'Erro ao carregar imagem';
                };
            } else if (isPdf) {
                modalPdf.src = currentDownloadUrl;
                modalPdf.onload = function() {
                    modalLoading.style.display = 'none';
                    modalPdf.style.display = 'block';
                };
            } else {
                modalLoading.textContent = 'Este tipo de arquivo não pode ser visualizado. Use o botão Download.';
            }
        }

        function fecharModal() {
            modal.style.display = 'none';
            modalImg.src = '';
            modalPdf.src = '';
            currentZoom = 1;
            if (modalImg) {
                modalImg.style.transform = 'scale(1)';
            }
        }

        document.addEventListener('click', function(e) {
            const botaoVer = e.target.closest('.btn-ver-arquivo');
            if (botaoVer) {
                const guiaId = botaoVer.getAttribute('data-guia-id');
                const arquivoNome = botaoVer.getAttribute('data-arquivo-nome');
                abrirModal(guiaId, arquivoNome);
            }
        });

        btnFechar.addEventListener('click', fecharModal);
        btnDownload.addEventListener('click', function() {
            if (currentDownloadUrl) {
                window.open(currentDownloadUrl, '_blank');
            }
        });

        btnZoomIn.addEventListener('click', function() {
            if (isImage && modalImg) {
                currentZoom = Math.min(currentZoom + 0.25, 3);
                modalImg.style.transform = `scale(${currentZoom})`;
            }
        });

        btnZoomOut.addEventListener('click', function() {
            if (isImage && modalImg) {
                currentZoom = Math.max(currentZoom - 0.25, 0.5);
                modalImg.style.transform = `scale(${currentZoom})`;
            }
        });

        btnZoomReset.addEventListener('click', function() {
            if (isImage && modalImg) {
                currentZoom = 1;
                modalImg.style.transform = `scale(${currentZoom})`;
            }
        });

        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                fecharModal();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display === 'block') {
                fecharModal();
            }
        });

        modalImg.addEventListener('wheel', function(e) {
            if (isImage) {
                e.preventDefault();
                const delta = e.deltaY > 0 ? -0.1 : 0.1;
                currentZoom = Math.max(0.5, Math.min(3, currentZoom + delta));
                modalImg.style.transform = `scale(${currentZoom})`;
            }
        }, { passive: false });
    })();
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Contabilidade - Guias');
echo $conteudo;
endSidebar();
?>
