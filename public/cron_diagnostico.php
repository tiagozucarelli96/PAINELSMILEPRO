<?php
/**
 * cron_diagnostico.php
 * Painel de diagnóstico para visualizar execuções de cron jobs
 */

// Habilitar erros para debug
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/cron_logger.php';

// Verificar permissão (superadmin ou configurações)
if (empty($_SESSION['logado']) || (empty($_SESSION['perm_superadmin']) && empty($_SESSION['perm_configuracoes']))) {
    header('Location: index.php?page=login');
    exit;
}

$pdo = $GLOBALS['pdo'];

// Buscar última execução de cada tipo
$ultimas = [];
$stats = [];

try {
    $ultimas = cron_logger_get_ultimas($pdo);
    $stats = cron_logger_get_stats($pdo, 7);
} catch (Exception $e) {
    error_log("Erro ao buscar dados de cron: " . $e->getMessage());
}

// Buscar histórico se solicitado
$historico = [];
$tipo_historico = $_GET['historico'] ?? '';
if (!empty($tipo_historico)) {
    $historico = cron_logger_get_historico($pdo, $tipo_historico, 30);
}

// Lista de crons disponíveis com descrições
$crons_info = [
    'demandas_fixas' => [
        'nome' => 'Demandas Fixas',
        'descricao' => 'Gera cards de demandas fixas (diárias, semanais, mensais)',
        'frequencia' => 'Diário às 06:00'
    ],
    'notificacoes' => [
        'nome' => 'Notificações',
        'descricao' => 'Processa e envia notificações pendentes',
        'frequencia' => 'A cada 5 minutos'
    ],
    'google_calendar_daily' => [
        'nome' => 'Google Calendar - Sync Diário',
        'descricao' => 'Sincronização completa dos calendários Google',
        'frequencia' => 'Diário às 03:00'
    ],
    'google_calendar_renewal' => [
        'nome' => 'Google Calendar - Renovar Webhooks',
        'descricao' => 'Renova webhooks próximos de expirar',
        'frequencia' => 'A cada 6 horas'
    ],
];

ob_start();
?>

<style>
.cron-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 1.5rem;
}

.cron-header {
    margin-bottom: 1.5rem;
}

.cron-header h1 {
    font-size: 1.75rem;
    color: #1e3a8a;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.cron-header p {
    color: #64748b;
}

/* Cards de status */
.cron-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.cron-card {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.25rem;
    position: relative;
    overflow: hidden;
}

.cron-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
}

.cron-card.status-sucesso::before {
    background: linear-gradient(90deg, #22c55e, #16a34a);
}

.cron-card.status-erro::before {
    background: linear-gradient(90deg, #ef4444, #dc2626);
}

.cron-card.status-executando::before {
    background: linear-gradient(90deg, #f59e0b, #d97706);
    animation: pulse 1.5s infinite;
}

.cron-card.status-nunca::before {
    background: linear-gradient(90deg, #94a3b8, #64748b);
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.cron-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.cron-card-title {
    font-weight: 700;
    color: #1e293b;
    font-size: 1rem;
}

.cron-card-badge {
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-sucesso {
    background: #dcfce7;
    color: #166534;
}

.badge-erro {
    background: #fee2e2;
    color: #991b1b;
}

.badge-executando {
    background: #fef3c7;
    color: #92400e;
}

.badge-nunca {
    background: #f1f5f9;
    color: #64748b;
}

.cron-card-desc {
    color: #64748b;
    font-size: 0.85rem;
    margin-bottom: 0.75rem;
}

.cron-card-info {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    font-size: 0.85rem;
}

.cron-card-info span {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    color: #475569;
}

.cron-card-info svg {
    width: 14px;
    height: 14px;
    color: #94a3b8;
}

.cron-card-actions {
    margin-top: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 0.5rem;
}

.btn-sm {
    padding: 0.35rem 0.65rem;
    font-size: 0.8rem;
    border-radius: 6px;
    border: 1px solid transparent;
    cursor: pointer;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.btn-outline {
    background: white;
    color: #1e3a8a;
    border-color: #93c5fd;
}

.btn-outline:hover {
    background: #eff6ff;
}

/* Tabela de histórico */
.historico-section {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.25rem;
    margin-top: 1.5rem;
}

.historico-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.historico-header h2 {
    font-size: 1.125rem;
    color: #1e293b;
}

.historico-table {
    width: 100%;
    border-collapse: collapse;
}

.historico-table th,
.historico-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.9rem;
}

.historico-table th {
    background: #f8fafc;
    font-weight: 600;
    color: #475569;
}

.historico-table tr:hover {
    background: #f8fafc;
}

.resultado-json {
    max-width: 300px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-family: monospace;
    font-size: 0.8rem;
    color: #64748b;
}

/* Responsivo */
@media (max-width: 768px) {
    .cron-grid {
        grid-template-columns: 1fr;
    }
    
    .historico-table {
        display: block;
        overflow-x: auto;
    }
}
</style>

<div class="cron-container">
    <div class="cron-header">
        <h1>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="width:28px;height:28px;color:#3b82f6;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Diagnóstico de Crons
        </h1>
        <p>Monitore as execuções automáticas do sistema</p>
    </div>

    <!-- Cards de cada cron -->
    <div class="cron-grid">
        <?php foreach ($crons_info as $tipo => $info): 
            // Buscar última execução deste tipo
            $ultima = null;
            foreach ($ultimas as $u) {
                if ($u['tipo'] === $tipo) {
                    $ultima = $u;
                    break;
                }
            }
            
            $status = $ultima ? $ultima['status_texto'] : 'nunca';
            $status_class = 'status-' . $status;
            $badge_class = 'badge-' . $status;
            
            // Compatível com PHP 7.x
            $status_labels = [
                'sucesso' => 'Sucesso',
                'erro' => 'Erro',
                'executando' => 'Executando',
                'nunca' => 'Nunca executou'
            ];
            $status_label = $status_labels[$status] ?? 'Nunca executou';
        ?>
        <div class="cron-card <?php echo $status_class; ?>">
            <div class="cron-card-header">
                <div class="cron-card-title"><?php echo htmlspecialchars($info['nome']); ?></div>
                <span class="cron-card-badge <?php echo $badge_class; ?>"><?php echo $status_label; ?></span>
            </div>
            
            <div class="cron-card-desc"><?php echo htmlspecialchars($info['descricao']); ?></div>
            
            <div class="cron-card-info">
                <span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Frequência: <?php echo htmlspecialchars($info['frequencia']); ?>
                </span>
                
                <?php if ($ultima): ?>
                <span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    Última: <?php echo date('d/m/Y H:i:s', strtotime($ultima['iniciado_em'])); ?>
                </span>
                
                <?php if ($ultima['duracao_ms']): ?>
                <span>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    Duração: <?php echo number_format($ultima['duracao_ms'] / 1000, 2); ?>s
                </span>
                <?php endif; ?>
                <?php else: ?>
                <span style="color: #94a3b8;">Nenhuma execução registrada</span>
                <?php endif; ?>
            </div>
            
            <div class="cron-card-actions">
                <a href="?page=cron_diagnostico&historico=<?php echo urlencode($tipo); ?>" class="btn-sm btn-outline">
                    📋 Ver histórico
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Histórico detalhado -->
    <?php if (!empty($tipo_historico) && !empty($historico)): ?>
    <div class="historico-section">
        <div class="historico-header">
            <h2>Histórico: <?php echo htmlspecialchars($crons_info[$tipo_historico]['nome'] ?? $tipo_historico); ?></h2>
            <a href="?page=cron_diagnostico" class="btn-sm btn-outline">← Voltar</a>
        </div>
        
        <table class="historico-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Iniciado em</th>
                    <th>Finalizado em</th>
                    <th>Status</th>
                    <th>Duração</th>
                    <th>Resultado</th>
                    <th>IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historico as $h): ?>
                <tr>
                    <td><?php echo $h['execucao_id']; ?></td>
                    <td><?php echo date('d/m/Y H:i:s', strtotime($h['iniciado_em'])); ?></td>
                    <td><?php echo $h['finalizado_em'] ? date('d/m/Y H:i:s', strtotime($h['finalizado_em'])) : '-'; ?></td>
                    <td>
                        <span class="cron-card-badge badge-<?php echo $h['status_texto']; ?>">
                            <?php echo ucfirst($h['status_texto']); ?>
                        </span>
                    </td>
                    <td><?php echo $h['duracao_ms'] ? number_format($h['duracao_ms'] / 1000, 2) . 's' : '-'; ?></td>
                    <td>
                        <?php if ($h['resultado']): ?>
                        <span class="resultado-json" title="<?php echo htmlspecialchars($h['resultado']); ?>">
                            <?php echo htmlspecialchars(substr($h['resultado'], 0, 50)) . (strlen($h['resultado']) > 50 ? '...' : ''); ?>
                        </span>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($h['ip_origem'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif (!empty($tipo_historico)): ?>
    <div class="historico-section">
        <div class="historico-header">
            <h2>Histórico: <?php echo htmlspecialchars($crons_info[$tipo_historico]['nome'] ?? $tipo_historico); ?></h2>
            <a href="?page=cron_diagnostico" class="btn-sm btn-outline">← Voltar</a>
        </div>
        <p style="color: #64748b; text-align: center; padding: 2rem;">Nenhuma execução registrada para este cron.</p>
    </div>
    <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
includeSidebar('Configurações');
echo $conteudo;
endSidebar();
?>
