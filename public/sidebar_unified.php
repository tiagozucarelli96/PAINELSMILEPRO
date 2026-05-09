<?php
// sidebar_unified.php — Sistema unificado de sidebar para todas as páginas
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/env_bootstrap.php';

// Headers para evitar cache na dashboard (especialmente demandas do dia)
if (isset($_GET['page']) && $_GET['page'] === 'dashboard') {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Garantir que $pdo está disponível
if (!isset($pdo)) {
    require_once __DIR__ . '/conexao.php';
}
// Garantir que $pdo está disponível globalmente
if (!isset($pdo) && isset($GLOBALS['pdo'])) {
    $pdo = $GLOBALS['pdo'];
}

if (!function_exists('dashboardEnsureConfigTable')) {
    function dashboardEnsureConfigTable(PDO $pdo): void
    {
        $markerPath = sys_get_temp_dir() . '/dashboard_config_schema_ready';
        $markerMtime = @filemtime($markerPath);
        if ($markerMtime !== false && (time() - $markerMtime) < 900) {
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS demandas_configuracoes (
                id SERIAL PRIMARY KEY,
                chave VARCHAR(100) UNIQUE NOT NULL,
                valor TEXT,
                descricao TEXT,
                tipo VARCHAR(50) DEFAULT 'string' CHECK (tipo IN ('string', 'number', 'boolean', 'json')),
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW()
            )
        ");

        @touch($markerPath);
    }
}

if (!function_exists('dashboardCachePath')) {
    function dashboardCachePath(string $key): string
    {
        return sys_get_temp_dir() . '/dashboard_cache_' . md5($key) . '.tmp';
    }
}

if (!function_exists('dashboardCacheRead')) {
    function dashboardCacheRead(string $key, int $ttl)
    {
        $path = dashboardCachePath($key);
        $mtime = @filemtime($path);
        if ($mtime === false || (time() - $mtime) > $ttl) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $value = @unserialize($raw);
        return $value === false && $raw !== serialize(false) ? null : $value;
    }
}

if (!function_exists('dashboardCacheWrite')) {
    function dashboardCacheWrite(string $key, $value): void
    {
        @file_put_contents(dashboardCachePath($key), serialize($value), LOCK_EX);
    }
}

if (!function_exists('dashboardGetManualSalesKey')) {
    function dashboardGetManualSalesKey(DateTimeInterface $date): string
    {
        return 'dashboard_vendas_realizadas_' . $date->format('Y_m');
    }
}

if (!function_exists('dashboardGetConfigTableColumns')) {
    function dashboardGetConfigTableColumns(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = CURRENT_SCHEMA()
              AND table_name = 'demandas_configuracoes'
        ");

        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $columns[(string)$column] = true;
        }

        return $columns;
    }
}

if (!function_exists('dashboardSaveConfigValue')) {
    function dashboardSaveConfigValue(PDO $pdo, string $key, string $value, ?string $description = null, ?string $type = null): void
    {
        $columns = dashboardGetConfigTableColumns($pdo);
        $assignments = ['valor = :valor'];
        $params = [
            ':chave' => $key,
            ':valor' => $value,
        ];

        if (isset($columns['descricao']) && $description !== null) {
            $assignments[] = 'descricao = :descricao';
            $params[':descricao'] = $description;
        }

        if (isset($columns['tipo']) && $type !== null) {
            $assignments[] = 'tipo = :tipo';
            $params[':tipo'] = $type;
        }

        if (isset($columns['updated_at'])) {
            $assignments[] = 'updated_at = NOW()';
        } elseif (isset($columns['atualizado_em'])) {
            $assignments[] = 'atualizado_em = NOW()';
        }

        $updateSql = 'UPDATE demandas_configuracoes SET ' . implode(', ', $assignments) . ' WHERE chave = :chave';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute($params);

        if ($updateStmt->rowCount() > 0) {
            return;
        }

        $insertColumns = ['chave', 'valor'];
        $insertValues = [':chave', ':valor'];

        if (isset($columns['descricao']) && $description !== null) {
            $insertColumns[] = 'descricao';
            $insertValues[] = ':descricao';
        }

        if (isset($columns['tipo']) && $type !== null) {
            $insertColumns[] = 'tipo';
            $insertValues[] = ':tipo';
        }

        if (isset($columns['created_at'])) {
            $insertColumns[] = 'created_at';
            $insertValues[] = 'NOW()';
        } elseif (isset($columns['criado_em'])) {
            $insertColumns[] = 'criado_em';
            $insertValues[] = 'NOW()';
        }

        if (isset($columns['updated_at'])) {
            $insertColumns[] = 'updated_at';
            $insertValues[] = 'NOW()';
        } elseif (isset($columns['atualizado_em'])) {
            $insertColumns[] = 'atualizado_em';
            $insertValues[] = 'NOW()';
        }

        $insertSql = sprintf(
            'INSERT INTO demandas_configuracoes (%s) VALUES (%s)',
            implode(', ', $insertColumns),
            implode(', ', $insertValues)
        );

        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute($params);
    }
}

$nomeUser = $_SESSION['nome'] ?? 'Usuário';
$current_page = $_GET['page'] ?? 'dashboard';
$show_top_account_access = ($current_page === 'dashboard');
$push_user_id = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$local_production_banner_visible = painel_should_show_local_production_banner($GLOBALS['painel_database_config'] ?? null);
$can_view_dashboard_agenda = !empty($_SESSION['perm_agenda']);

// Buscar cargo do usuário logado do banco de dados
$perfil = 'CONSULTA'; // Valor padrão
$foto_usuario = null;
$usuario_id_sidebar = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? null;
if ($usuario_id_sidebar && isset($pdo)) {
    try {
        $stmt_cargo = $pdo->prepare("SELECT cargo, foto FROM usuarios WHERE id = :id");
        $stmt_cargo->execute([':id' => $usuario_id_sidebar]);
        $cargo_result = $stmt_cargo->fetch(PDO::FETCH_ASSOC);
        if ($cargo_result) {
            if (!empty($cargo_result['cargo'])) {
                $perfil = $cargo_result['cargo'];
            }
            if (!empty($cargo_result['foto'])) {
                $foto_usuario = trim((string)$cargo_result['foto']);
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar cargo do usuário: " . $e->getMessage());
    }
}

// Inicializar variáveis de conteúdo (para evitar erros undefined)
$dashboard_content = '';
$comercial_content = '';
$logistico_content = '';
$configuracoes_content = '';
$cadastros_content = '';
$financeiro_content = '';
$administrativo_content = '';

// Função para determinar se item está ativo
if (!function_exists('isActiveUnified')) {
    function isActiveUnified($page) {
        global $current_page;
        return $current_page === $page ? 'active' : '';
    }
}

// Para todas as páginas, incluir o conteúdo da página atual
$page_file = $_GET['page'] ?? 'dashboard';
$page_path = __DIR__ . '/' . $page_file . '.php';

// Se for dashboard, comercial ou logistico, criar conteúdo diretamente
if ($current_page === 'dashboard') {
    // Buscar dados reais do banco
    require_once __DIR__ . '/conexao.php';
    require_once __DIR__ . '/administrativo_avisos_helper.php';
    adminAvisosEnsureSchema($pdo);
    $is_superadmin_dashboard = !empty($_SESSION['perm_superadmin']);
    $dashboard_can_view_logistica_alertas = $is_superadmin_dashboard || !empty($_SESSION['perm_logistico']) || !empty($_SESSION['perm_logistico_divergencias']);
    $dashboard_current_month = new DateTimeImmutable('now');
    $dashboard_manual_sales_key = dashboardGetManualSalesKey($dashboard_current_month);
    $dashboard_manual_sales_feedback = null;
    $dashboard_bypass_cache = false;
    
    $stats = [];
    $user_email = $_SESSION['email'] ?? $_SESSION['user_email'] ?? 'Não informado';
    $usuario_id_dashboard = $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? $_SESSION['id'] ?? null;
    $avisos_dashboard = [];

    if (
        $is_superadmin_dashboard &&
        ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' &&
        ($_POST['action'] ?? '') === 'save_manual_vendas_realizadas'
    ) {
        try {
            dashboardEnsureConfigTable($pdo);
            $manual_sales_value = max(0, (int)($_POST['vendas_realizadas_manual'] ?? 0));
            dashboardSaveConfigValue(
                $pdo,
                $dashboard_manual_sales_key,
                (string)$manual_sales_value,
                'Valor manual mensal do card Vendas Realizadas da dashboard',
                'number'
            );
            $dashboard_manual_sales_feedback = 'saved';
            $dashboard_bypass_cache = true;
        } catch (Throwable $e) {
            error_log('[SIDEBAR] Erro ao salvar vendas realizadas manualmente: ' . $e->getMessage());
            $dashboard_manual_sales_feedback = 'error';
            $dashboard_bypass_cache = true;
        }
    }

    $dashboard_stats_cache_key = 'stats:' . $dashboard_current_month->format('Y-m') . ':' . ($is_superadmin_dashboard ? '1' : '0');
    $cached_stats = $dashboard_bypass_cache ? null : dashboardCacheRead($dashboard_stats_cache_key, 60);
    if (is_array($cached_stats)) {
        $stats = $cached_stats;
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM comercial_inscricoes ci
                JOIN comercial_degustacoes cd ON ci.degustacao_id = cd.id
                WHERE cd.status = 'publicado'
                AND DATE_TRUNC('month', ci.criado_em) = DATE_TRUNC('month', CURRENT_DATE)
                AND ci.status IN ('confirmado', 'lista_espera')
            ");
            $stmt->execute();
            $stats['inscritos_degustacao'] = $stmt->fetchColumn() ?: 0;

            dashboardEnsureConfigTable($pdo);
            $stmt = $pdo->prepare("
                SELECT valor
                FROM demandas_configuracoes
                WHERE chave = :chave
                LIMIT 1
            ");
            $stmt->execute([':chave' => $dashboard_manual_sales_key]);
            $stats['vendas_realizadas'] = max(0, (int)($stmt->fetchColumn() ?: 0));

            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM (
                    SELECT inicio 
                    FROM agenda_eventos 
                    WHERE tipo = 'visita'
                    AND status = 'realizado'
                    AND DATE_TRUNC('month', inicio) = DATE_TRUNC('month', CURRENT_DATE)
                    UNION ALL
                    SELECT inicio 
                    FROM google_calendar_eventos 
                    WHERE eh_visita_agendada = true
                    AND DATE_TRUNC('month', inicio) = DATE_TRUNC('month', CURRENT_DATE)
                ) as todas_visitas
            ");
            $stmt->execute();
            $stats['visitas_realizadas'] = $stmt->fetchColumn() ?: 0;

            $stats['fechamentos_realizados'] = $stats['visitas_realizadas'] > 0
                ? round(($stats['vendas_realizadas'] / $stats['visitas_realizadas']) * 100, 1)
                : 0;
            dashboardCacheWrite($dashboard_stats_cache_key, $stats);
        } catch (Exception $e) {
            $stats = [
                'inscritos_degustacao' => 0,
                'vendas_realizadas' => 0,
                'visitas_realizadas' => 0,
                'fechamentos_realizados' => 0
            ];
        }
    }
    
    // Buscar agenda do dia atual apenas para usuários com permissão de Agenda
    $agenda_hoje = [];
    if ($can_view_dashboard_agenda) {
        $agenda_cache_key = 'agenda:' . date('Y-m-d') . ':' . ((int)($usuario_id_dashboard ?? 0)) . ':' . ($can_view_dashboard_agenda ? '1' : '0');
        $cached_agenda = $dashboard_bypass_cache ? null : dashboardCacheRead($agenda_cache_key, 60);
        if (is_array($cached_agenda)) {
            $agenda_hoje = $cached_agenda;
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT 
                        ae.id, 
                        ae.titulo, 
                        ae.inicio as data_inicio, 
                        ae.fim as data_fim, 
                        ae.tipo, 
                        ae.cor_evento as cor, 
                        ae.descricao as observacoes,
                        u.nome as responsavel_nome,
                        'interno' as origem
                    FROM agenda_eventos ae
                    LEFT JOIN usuarios u ON u.id = ae.responsavel_usuario_id
                    WHERE DATE(ae.inicio) = CURRENT_DATE
                    
                    UNION ALL
                    
                    SELECT 
                        gce.id, 
                        gce.titulo, 
                        gce.inicio as data_inicio, 
                        gce.fim as data_fim, 
                        'google' as tipo, 
                        '#10b981' as cor, 
                        gce.descricao as observacoes,
                        COALESCE(gce.organizador_email, 'Google Calendar') as responsavel_nome,
                        'google' as origem
                    FROM google_calendar_eventos gce
                    WHERE DATE(gce.inicio) = CURRENT_DATE
                    AND EXISTS (
                        SELECT 1 FROM google_calendar_config gcc 
                        WHERE gcc.google_calendar_id = gce.google_calendar_id 
                        AND gcc.ativo = TRUE
                    )
                    
                    ORDER BY data_inicio ASC
                    LIMIT 10
                ");
                $stmt->execute();
                $agenda_hoje = $stmt->fetchAll(PDO::FETCH_ASSOC);
                dashboardCacheWrite($agenda_cache_key, $agenda_hoje);
            } catch (Exception $e) {
                error_log("[SIDEBAR] Erro ao buscar agenda do dia: " . $e->getMessage());
                $agenda_hoje = [];
            }
        }
    }

    $avisos_cache_key = 'avisos:' . ((int)($usuario_id_dashboard ?? 0));
    $cached_avisos = $dashboard_bypass_cache ? null : dashboardCacheRead($avisos_cache_key, 60);
    if (is_array($cached_avisos)) {
        $avisos_dashboard = $cached_avisos;
    } else {
        try {
            $avisos_dashboard = adminAvisosBuscarParaDashboard($pdo, (int)($usuario_id_dashboard ?? 0), 8);
            dashboardCacheWrite($avisos_cache_key, $avisos_dashboard);
        } catch (Exception $e) {
            error_log("[SIDEBAR] Erro ao buscar avisos da dashboard: " . $e->getMessage());
            $avisos_dashboard = [];
        }
    }
    
    // Buscar demandas do dia atual (sistema Trello - demandas_cards)
    // IMPORTANTE: Sistema atual usa demandas_cards (Trello), não a tabela antiga 'demandas'
    // Removido fallback para tabela antiga - só mostra cards do sistema atual
    $demandas_hoje = [];
    $is_admin_dashboard = (
        !empty($_SESSION['perm_superadmin']) ||
        !empty($_SESSION['perm_administrativo']) ||
        (isset($_SESSION['permissao']) && strpos((string)$_SESSION['permissao'], 'admin') !== false)
    );
    $demandas_cache_key = 'demandas:' . date('Y-m-d-H') . ':' . ((int)($usuario_id_dashboard ?? 0)) . ':' . ($is_admin_dashboard ? '1' : '0');
    $cached_demandas = $dashboard_bypass_cache ? null : dashboardCacheRead($demandas_cache_key, 60);
    if (is_array($cached_demandas)) {
        $demandas_hoje = $cached_demandas;
    } else {
        try {
            $filtro_visibilidade_dashboard = "
                db.ativo = TRUE
                AND (
                    :is_admin = TRUE
                    OR (
                        NOT EXISTS (
                            SELECT 1
                            FROM demandas_boards_usuarios dbu_all
                            WHERE dbu_all.board_id = db.id
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM demandas_boards_usuarios dbu_user
                            WHERE dbu_user.board_id = db.id
                              AND dbu_user.usuario_id = :user_id
                        )
                    )
                )
            ";

            $params_dashboard = [
                ':is_admin' => $is_admin_dashboard ? 't' : 'f',
                ':user_id' => (int)($usuario_id_dashboard ?? 0),
            ];

            $stmt = $pdo->prepare("
                SELECT DISTINCT ON (dc.id)
                       dc.id,
                       dc.titulo,
                       dc.titulo as descricao,
                       dc.prazo,
                       dc.status,
                       db.nome as quadro_nome,
                       u.nome as responsavel_nome
                FROM demandas_cards dc
                JOIN demandas_listas dl ON dl.id = dc.lista_id
                JOIN demandas_boards db ON db.id = dl.board_id
                LEFT JOIN demandas_cards_usuarios dcu ON dcu.card_id = dc.id
                LEFT JOIN usuarios u ON u.id = dcu.usuario_id
                WHERE DATE(dc.prazo) = CURRENT_DATE
                  AND dc.status NOT IN ('concluido', 'cancelado')
                  AND {$filtro_visibilidade_dashboard}
                ORDER BY dc.id, dc.prazo ASC
                LIMIT 10
            ");
            $stmt->execute($params_dashboard);
            $demandas_hoje = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($demandas_hoje)) {
                try {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT ON (dc.id)
                               dc.id,
                               dc.titulo,
                               dc.titulo as descricao,
                               dc.prazo,
                               dc.status,
                               db.nome as quadro_nome,
                               u.nome as responsavel_nome
                        FROM demandas_cards dc
                        JOIN demandas_listas dl ON dl.id = dc.lista_id
                        JOIN demandas_boards db ON db.id = dl.board_id
                        LEFT JOIN demandas_cards_usuarios dcu ON dcu.card_id = dc.id
                        LEFT JOIN usuarios u ON u.id = dcu.usuario_id
                        WHERE dc.status NOT IN ('concluido', 'cancelado')
                          AND DATE(dc.criado_em) >= CURRENT_DATE - INTERVAL '7 days'
                          AND {$filtro_visibilidade_dashboard}
                        ORDER BY dc.id, dc.criado_em DESC
                        LIMIT 5
                    ");
                    $stmt->execute($params_dashboard);
                    $demandas_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($demandas_recentes)) {
                        $demandas_hoje = $demandas_recentes;
                    }
                } catch (Exception $e2) {
                    error_log("Erro ao buscar demandas recentes: " . $e2->getMessage());
                }
            }
            dashboardCacheWrite($demandas_cache_key, $demandas_hoje);
        } catch (Exception $e) {
            error_log("Erro ao buscar demandas do dia (Trello): " . $e->getMessage());
            $demandas_hoje = [];
        }
    }
    
    $dashboard_content = '
    <style>
    .dashboard-header-container {
        margin-bottom: 1.5rem;
    }
    .dashboard-header-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .dashboard-header-info h1 {
        font-size: 1.75rem;
        color: #1e293b;
        margin-bottom: 0.25rem;
    }
    .dashboard-header-info p {
        color: #64748b;
        font-size: 0.9rem;
    }
    .dashboard-notifications-wrapper {
        flex: 1;
        max-width: 100%;
    }
    .dashboard-alertas-loading {
        padding: 1rem 1.25rem;
        border-radius: 14px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        color: #64748b;
        font-size: 0.9rem;
        box-shadow: 0 2px 10px rgba(15, 23, 42, 0.05);
    }
    .metric-card-form {
        margin-top: 0.65rem;
    }
    .metric-card-form form {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .metric-card-form input[type="number"] {
        width: 92px;
        padding: 0.45rem 0.55rem;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        font-size: 0.85rem;
    }
    .metric-card-form button {
        border: none;
        border-radius: 10px;
        background: #1d4ed8;
        color: #fff;
        padding: 0.48rem 0.8rem;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
    }
    .metric-card-form small {
        display: block;
        margin-top: 0.35rem;
        color: #64748b;
        font-size: 0.75rem;
    }
    .metric-card-feedback {
        margin-top: 0.35rem;
        font-size: 0.75rem;
        color: #047857;
    }
    .metric-card-feedback.error {
        color: #b91c1c;
    }
    .avisos-list {
        display: grid;
        gap: 0.75rem;
    }
    .aviso-item-btn {
        width: 100%;
        display: flex;
        align-items: center;
        gap: 0.85rem;
        border: 1px solid #dbeafe;
        border-radius: 14px;
        background: linear-gradient(135deg, #eff6ff, #f8fafc);
        padding: 0.9rem 1rem;
        cursor: pointer;
        text-align: left;
        transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
    }
    .aviso-item-btn:hover {
        transform: translateY(-1px);
        border-color: #93c5fd;
        box-shadow: 0 12px 22px rgba(37, 99, 235, 0.10);
    }
    .aviso-item-icon {
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 999px;
        background: #2563eb;
        color: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        font-size: 1rem;
    }
    .aviso-item-content {
        min-width: 0;
        flex: 1;
    }
    .aviso-item-title {
        color: #0f172a;
        font-weight: 700;
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .dashboard-aviso-modal {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        display: none;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        z-index: 1300;
    }
    .dashboard-aviso-modal.open {
        display: flex;
    }
    .dashboard-aviso-modal-card {
        width: min(760px, 100%);
        max-height: min(86vh, 820px);
        overflow: auto;
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 24px 50px rgba(15, 23, 42, 0.24);
    }
    .dashboard-aviso-modal-header {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        padding: 1.1rem 1.25rem;
        border-bottom: 1px solid #e5e7eb;
    }
    .dashboard-aviso-modal-header h3 {
        margin: 0;
        color: #0f172a;
        font-size: 1.2rem;
    }
    .dashboard-aviso-modal-meta {
        margin-top: 0.3rem;
        color: #64748b;
        font-size: 0.84rem;
    }
    .dashboard-aviso-modal-close {
        border: none;
        background: transparent;
        color: #475569;
        font-size: 1.8rem;
        line-height: 1;
        cursor: pointer;
    }
    .dashboard-aviso-modal-body {
        padding: 1.25rem;
        color: #1f2937;
        line-height: 1.65;
    }
    .dashboard-aviso-modal-body img {
        max-width: 100%;
        height: auto;
    }
    .dashboard-aviso-modal-loading {
        color: #64748b;
    }
    @media (max-width: 1024px) {
        .dashboard-header-top {
            flex-direction: column;
        }
        .dashboard-notifications-wrapper {
            width: 100%;
        }
    }
    </style>
    <div class="page-container">
        <div class="dashboard-header-container">
            <div class="dashboard-header-top">
                <div class="dashboard-header-info">
                    <h1>🏠 Dashboard</h1>
                    <p>Bem-vindo, ' . htmlspecialchars($nomeUser) . '! | Email: ' . htmlspecialchars($user_email) . '</p>
                </div>
            </div>
            ' . ($dashboard_can_view_logistica_alertas ? '
            <div class="dashboard-notifications-wrapper" id="dashboard-logistica-alertas-wrapper" hidden>
                <div class="dashboard-alertas-loading">Carregando alertas logísticos...</div>
            </div>
            ' : '') . '
        </div>
        
        <!-- Métricas Principais -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-icon">📋</div>
                <div class="metric-content">
                    <h3>' . $stats['inscritos_degustacao'] . '</h3>
                    <p>Inscritos em Degustações</p>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon">🎉</div>
                <div class="metric-content">
                    <h3>' . $stats['vendas_realizadas'] . '</h3>
                    <p>Vendas Realizadas</p>
                    ' . ($is_superadmin_dashboard ? '
                    <div class="metric-card-form">
                        <form method="POST">
                            <input type="hidden" name="action" value="save_manual_vendas_realizadas">
                            <input type="number" name="vendas_realizadas_manual" min="0" step="1" value="' . (int)$stats['vendas_realizadas'] . '" aria-label="Vendas realizadas do mês">
                            <button type="submit">Salvar</button>
                        </form>
                        <small>Valor manual de ' . htmlspecialchars($dashboard_current_month->format('m/Y')) . '.</small>
                        ' . ($dashboard_manual_sales_feedback === 'saved'
                            ? '<div class="metric-card-feedback">Valor atualizado.</div>'
                            : ($dashboard_manual_sales_feedback === 'error'
                                ? '<div class="metric-card-feedback error">Erro ao salvar.</div>'
                                : '')) . '
                    </div>
                    ' : '') . '
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon">📅</div>
                <div class="metric-content">
                    <h3>' . $stats['visitas_realizadas'] . '</h3>
                    <p>Visitas Realizadas</p>
                </div>
            </div>
            
            <div class="metric-card">
                <div class="metric-icon">✅</div>
                <div class="metric-content">
                    <h3>' . number_format((float)$stats['fechamentos_realizados'], 1, ',', '.') . '%</h3>
                    <p>Conversão</p>
                    <small>Conversão do mês entre visitas e vendas.</small>
                </div>
            </div>
        </div>
        
	        ' . ($can_view_dashboard_agenda ? '
	        <!-- Agenda do Dia -->
        <div class="dashboard-section">
            <div class="section-header">
                <h2>📅 Agenda do Dia</h2>
                <span class="section-badge">' . count($agenda_hoje) . ' eventos</span>
            </div>
            <div class="agenda-list">
                ' . (empty($agenda_hoje) ? 
                    '<div class="empty-state">
                        <div class="empty-icon">📅</div>
                        <p>Nenhum evento agendado para hoje</p>
                    </div>' : 
                    implode('', array_map(function($evento) {
                        // Para eventos de dia todo do Google, mostrar "Dia todo"
                        $is_all_day = ($evento['origem'] ?? 'interno') === 'google' && 
                                      date('H:i', strtotime($evento['data_inicio'])) === '00:00' &&
                                      date('H:i', strtotime($evento['data_fim'])) === '23:59';
                        $hora = $is_all_day ? 'Dia todo' : date('H:i', strtotime($evento['data_inicio']));
                        $tipo_icon = ($evento['origem'] ?? 'interno') === 'google' ? '📅' : 
                                     ($evento['tipo'] === 'visita' ? '🏠' : ($evento['tipo'] === 'bloqueio' ? '🚫' : '📅'));
                        return '
                        <div class="agenda-item">
                            <div class="agenda-time">' . $hora . '</div>
                            <div class="agenda-content">
                                <div class="agenda-title">' . $tipo_icon . ' ' . htmlspecialchars($evento['titulo']) . '</div>
                                <div class="agenda-meta">' . htmlspecialchars($evento['responsavel_nome'] ?? 'Sem responsável') . '</div>
                            </div>
                        </div>';
                    }, $agenda_hoje))
                ) . '
            </div>
	        </div>
	        ' : '') . '

            <!-- Avisos -->
            <div class="dashboard-section" id="dashboard-avisos-section">
                <div class="section-header">
                    <h2>📣 Avisos</h2>
                    <span class="section-badge" id="dashboard-avisos-count">' . count($avisos_dashboard) . ' avisos</span>
                </div>
                <div class="avisos-list">
                    ' . (empty($avisos_dashboard) ?
                        '<div class="empty-state">
                            <div class="empty-icon">📣</div>
                            <p>Nenhum aviso ativo para você</p>
                        </div>' :
                        implode('', array_map(function($aviso) {
                            return '
                            <button type="button" class="aviso-item-btn" data-aviso-id="' . (int)$aviso['id'] . '" data-aviso-unico="' . (adminAvisosBoolValue($aviso['visualizacao_unica'] ?? false) ? '1' : '0') . '" onclick="abrirAvisoDashboard(this)">
                                <span class="aviso-item-icon">📣</span>
                                <span class="aviso-item-content">
                                    <span class="aviso-item-title">' . htmlspecialchars((string)$aviso['assunto']) . '</span>
                                </span>
                            </button>';
                        }, $avisos_dashboard))
                    ) . '
                </div>
            </div>

	        <!-- Demandas do Dia -->
	        <div class="dashboard-section">
            <div class="section-header">
                <h2>📋 Demandas do Dia</h2>
                <span class="section-badge">' . count($demandas_hoje) . ' demandas</span>
            </div>
            <div class="demandas-list">
                ' . (empty($demandas_hoje) ? 
                    '<div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <p>Nenhuma demanda para hoje</p>
                    </div>' : 
                    implode('', array_map(function($demanda) {
                        $status_color = $demanda['status'] === 'concluido' ? '#10b981' : 
                                     ($demanda['status'] === 'em_andamento' ? '#f59e0b' : '#6b7280');
                        $status_icon = $demanda['status'] === 'concluido' ? '✅' : 
                                     ($demanda['status'] === 'em_andamento' ? '🔄' : '⏳');
                        return '
                        <div class="demanda-item">
                            <div class="demanda-status" style="background-color: ' . $status_color . '">' . $status_icon . '</div>
                            <div class="demanda-content">
                                <div class="demanda-title">' . htmlspecialchars($demanda['titulo']) . '</div>
                                <div class="demanda-meta">' . htmlspecialchars($demanda['quadro_nome'] ?? 'Sem quadro') . ' • ' . htmlspecialchars($demanda['responsavel_nome'] ?? 'Sem responsável') . '</div>
                            </div>
                        </div>';
                    }, $demandas_hoje))
                ) . '
            </div>
        </div>
        <div id="dashboardAvisoModal" class="dashboard-aviso-modal" aria-hidden="true">
            <div class="dashboard-aviso-modal-card">
                <div class="dashboard-aviso-modal-header">
                    <div>
                        <h3 id="dashboardAvisoModalTitle">Aviso</h3>
                        <div class="dashboard-aviso-modal-meta" id="dashboardAvisoModalMeta"></div>
                    </div>
                    <button type="button" class="dashboard-aviso-modal-close" onclick="fecharAvisoDashboard()" aria-label="Fechar">&times;</button>
                </div>
                <div class="dashboard-aviso-modal-body" id="dashboardAvisoModalBody">
                    <div class="dashboard-aviso-modal-loading">Carregando aviso...</div>
                </div>
            </div>
        </div>
        
        <!-- Modal de Notificações na Dashboard -->
        <div id="dashboard-notificacoes-dropdown" class="dashboard-notificacoes-dropdown">
            <div class="dashboard-notificacoes-header">
                <h3 style="margin: 0; font-size: 1.125rem; font-weight: 600;">🔔 Notificações</h3>
                <button onclick="marcarTodasDashboardNotificacoesLidas()" style="background: transparent; border: none; color: #3b82f6; cursor: pointer; font-size: 0.875rem; padding: 0.5rem;" title="Marcar todas como lidas">Marcar todas</button>
            </div>
            <div class="dashboard-notificacoes-content" id="dashboard-notificacoes-content">
                <div class="dashboard-notificacoes-empty">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔔</div>
                    <div>Nenhuma notificação</div>
                </div>
            </div>
        </div>
    </div>';
    
    // Conteúdo será renderizado diretamente via PHP (linha 1548-1563)
    // NOTA: A página Comercial usa comercial_landing.php (definida no index.php)
    // Os cards duplicados foram removidos para evitar confusão
} elseif ($current_page === 'logistico') {
    // Conteúdo removido: módulo logístico desativado
    $logistico_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">📦 Logístico</h1>
            <p class="page-subtitle">Módulo removido</p>
        </div>
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-content">
                    <p>Este módulo foi removido do sistema.</p>
                </div>
            </div>
        </div>
    </div>';
} elseif ($current_page === 'configuracoes') {
    // Conteúdo da página Configurações
    $configuracoes_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">⚙️ Configurações</h1>
            <p class="page-subtitle">Configurações do sistema</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>👥 Usuários</h3>
                    <span class="card-icon">👥</span>
                </div>
                <div class="card-content">
                    <p>Gerenciar usuários e permissões</p>
                    <a href="index.php?page=usuarios" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🔧 Sistema</h3>
                    <span class="card-icon">🔧</span>
                </div>
                <div class="card-content">
                    <p>Configurações gerais do sistema</p>
                    <a href="index.php?page=configuracoes" class="btn-primary">Acessar</a>
                </div>
            </div>
        </div>
    </div>';
} elseif ($current_page === 'cadastros') {
    // Conteúdo da página Cadastros
    $cadastros_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">📝 Cadastros</h1>
            <p class="page-subtitle">Cadastros gerais do sistema</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-content" style="text-align: center; padding: 2rem;">
                    <div style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;">📝</div>
                    <p style="color: #64748b;">Módulo de cadastros em desenvolvimento.</p>
                    <p style="color: #94a3b8; font-size: 0.875rem; margin-top: 0.5rem;">Para gerenciar usuários, acesse Configurações.</p>
                </div>
            </div>
        </div>
    </div>';
} elseif ($current_page === 'financeiro') {
    // Conteúdo da página Financeiro
    $financeiro_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">💰 Financeiro</h1>
            <p class="page-subtitle">Módulo removido</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-content">
                    <p>Este módulo foi removido do sistema.</p>
                </div>
            </div>
        </div>
    </div>';
} elseif ($current_page === 'administrativo') {
    // Conteúdo da página Administrativo
    $administrativo_content = '
    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">👥 Administrativo</h1>
            <p class="page-subtitle">Relatórios e administração</p>
        </div>
        
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>🔍 Auditoria</h3>
                    <span class="card-icon">🔍</span>
                </div>
                <div class="card-content">
                    <p>Logs e auditoria do sistema</p>
                    <a href="index.php?page=verificacao_completa_erros" class="btn-primary">Acessar</a>
                </div>
            </div>
            
            <div class="dashboard-card">
                <div class="card-header">
                    <h3>📄 Notas Fiscais</h3>
                    <span class="card-icon">📄</span>
                </div>
                <div class="card-content">
                    <p>Gestão de notas fiscais</p>
                    <a href="index.php?page=notas_fiscais" class="btn-primary">Acessar</a>
                </div>
            </div>
            
        </div>
    </div>';
} else {
    // Para outras páginas (que usam includeSidebar/endSidebar), 
    // NÃO renderizar conteúdo aqui - a página já renderiza seu próprio conteúdo
    // via ob_start() antes de chamar includeSidebar()
    // O conteúdo será incluído quando a página chamar includeSidebar() e endSidebar()
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GRUPO Smile EVENTOS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- FullCalendar para Agenda -->
    <?php if ($current_page === 'agenda'): ?>
        <link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/locales/pt-br.global.min.js"></script>
    <?php endif; ?>
    <!-- Modais customizados (sucesso/erro/confirmação) em todo o sistema -->
    <link rel="stylesheet" href="assets/css/custom_modals.css">
    <script src="assets/js/custom_modals.js"></script>
    <link rel="stylesheet" href="assets/css/sidebar_unified.css">
</head>
<body>
    <button class="sidebar-toggle" onclick="toggleSidebar()">☰</button>
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar(false)"></div>
    
    <!-- Sidebar fixa -->
    <div class="sidebar" id="sidebar" style="position: fixed !important; top: 0 !important; left: 0 !important; width: 280px !important; height: 100vh !important; z-index: 1200 !important;">
            <div class="sidebar-header">
                <div class="user-info">
                    <div class="user-avatar" style="<?= $foto_usuario ? "background-image: url('" . htmlspecialchars($foto_usuario) . "'); background-size: cover; background-position: center; color: transparent;" : '' ?>">
                        <?php if (!$foto_usuario): ?>
                            <?= strtoupper(substr($nomeUser, 0, 2)) ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-name"><?= htmlspecialchars($nomeUser) ?></div>
                    <div class="user-plan"><?= strtoupper($perfil) ?></div>
                </div>
            </div>
            
            <div class="sidebar-controls">
                <button class="sidebar-btn" onclick="goBack()">← Voltar</button>
            </div>
            
            <nav class="sidebar-nav">
                <a href="index.php?page=dashboard" class="nav-item <?= isActiveUnified('dashboard') ?>">
                    <span class="nav-item-icon">🏠</span>
                    Dashboard
                </a>
                
                <?php if (!empty($_SESSION['perm_agenda'])): ?>
                <a href="index.php?page=agenda" class="nav-item <?= isActiveUnified('agenda') ?>">
                    <span class="nav-item-icon">📅</span>
                    Agenda
                </a>
                <?php endif; ?>

                <?php if (!empty($_SESSION['perm_agenda_eventos'])): ?>
                <a href="index.php?page=agenda_eventos" class="nav-item <?= isActiveUnified('agenda_eventos') ?>">
                    <span class="nav-item-icon">🗓️</span>
                    Agenda de eventos
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_demandas'])): ?>
                <a href="index.php?page=demandas" class="nav-item <?= isActiveUnified('demandas') ?>">
                    <span class="nav-item-icon">📝</span>
                    Demandas
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_comercial'])): ?>
                <a href="index.php?page=comercial" class="nav-item <?= isActiveUnified('comercial') ?>">
                    <span class="nav-item-icon">📋</span>
                    Comercial
                </a>
                <?php endif; ?>

                <?php if (!empty($_SESSION['perm_marketing']) || !empty($_SESSION['perm_superadmin'])): ?>
                <a href="index.php?page=marketing" class="nav-item <?= isActiveUnified('marketing') ?>">
                    <span class="nav-item-icon">📣</span>
                    Marketing
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_eventos'])): ?>
                <a href="index.php?page=eventos" class="nav-item <?= isActiveUnified('eventos') ?>">
                    <span class="nav-item-icon">🎉</span>
                    Eventos
                </a>
                <?php endif; ?>

                <?php if (!empty($_SESSION['perm_eventos_realizar'])): ?>
                <a href="index.php?page=eventos_realizar" class="nav-item <?= isActiveUnified('eventos_realizar') ?>">
                    <span class="nav-item-icon">✅</span>
                    Realizar evento
                </a>
                <?php endif; ?>
                
                <?php
                $scope_none = (($_SESSION['unidade_scope'] ?? 'todas') === 'nenhuma') && empty($_SESSION['perm_superadmin']);
                ?>
                <?php if ((!empty($_SESSION['perm_logistico']) || !empty($_SESSION['perm_superadmin'])) && !$scope_none): ?>
                <a href="index.php?page=logistica" class="nav-item <?= isActiveUnified('logistica') ?>">
                    <span class="nav-item-icon">📦</span>
                    Logística
                </a>
                <?php endif; ?>
                
                <?php /* REMOVIDO: Módulo Logístico (Estoque + Lista de Compras) */ ?>
                
                <?php if (!empty($_SESSION['perm_configuracoes'])): ?>
                <a href="index.php?page=configuracoes" class="nav-item <?= isActiveUnified('configuracoes') ?>">
                    <span class="nav-item-icon">⚙️</span>
                    Configurações
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_cadastros'])): ?>
                <a href="index.php?page=cadastros" class="nav-item <?= isActiveUnified('cadastros') ?>">
                    <span class="nav-item-icon">📝</span>
                    Cadastros
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_financeiro'])): ?>
                <a href="index.php?page=financeiro" class="nav-item <?= isActiveUnified('financeiro') ?>">
                    <span class="nav-item-icon">💰</span>
                    Financeiro
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_administrativo']) || !empty($_SESSION['perm_vendas_administracao']) || !empty($_SESSION['perm_superadmin'])): ?>
                <a href="index.php?page=administrativo" class="nav-item <?= isActiveUnified('administrativo') ?>">
                    <span class="nav-item-icon">👥</span>
                    Administrativo
                </a>
                <?php endif; ?>

                <?php if (!empty($_SESSION['perm_administrativo'])): ?>
                <a href="index.php?page=contabilidade" class="nav-item <?= isActiveUnified('contabilidade') ?>">
                    <span class="nav-item-icon">📑</span>
                    Contabilidade
                </a>
                <?php endif; ?>
                
                <?php if (!empty($_SESSION['perm_portao']) || !empty($_SESSION['perm_superadmin'])): ?>
                <a href="index.php?page=portao" class="nav-item nav-item-gate <?= isActiveUnified('portao') ?>">
                    <span class="nav-item-icon">🔓</span>
                    Portao
                </a>
                <?php endif; ?>
                
                <!-- Separador visual antes do botão Sair -->
                <div style="height: 1px; background: rgba(255, 255, 255, 0.1); margin: 20px 0;"></div>
                
                <!-- Botão Sair -->
                <a href="logout.php" class="nav-item nav-item-logout">
                    <span class="nav-item-icon">🚪</span>
                    Sair
                </a>
            </nav>
    </div>
    <!-- Sidebar fechada -->
    
    <!-- Conteúdo principal -->
    <div class="main-content" id="mainContent" style="margin-left: 280px !important; width: calc(100% - 280px) !important; position: relative !important; top: 0 !important;">
            <?php if ($show_top_account_access): ?>
            <div class="top-account-access">
                <a class="top-account-link" href="index.php?page=minha_conta" title="Meus dados">
                    <span class="top-account-avatar" style="<?= $foto_usuario ? "background-image: url('" . htmlspecialchars($foto_usuario) . "'); color: transparent;" : '' ?>">
                        <?php if (!$foto_usuario): ?>
                            <?= strtoupper(substr($nomeUser, 0, 2)) ?>
                        <?php endif; ?>
                    </span>
                    <span class="top-account-text">Meus dados</span>
                </a>
            </div>
            <?php endif; ?>
            <?php if ($local_production_banner_visible): ?>
            <div class="local-production-banner" role="alert">
                <strong>Aviso de ambiente</strong>
                Ambiente local conectado ao banco de produção
            </div>
            <?php endif; ?>
            <div id="pageContent">
                <?php
                $logistica_breadcrumbs = [
                    'logistica' => 'Logística',
                    'logistica_estoque' => 'Logística > Estoque',
                    'logistica_contagem' => 'Logística > Estoque > Contagem',
                    'logistica_entrada' => 'Logística > Estoque > Entrada',
                    'logistica_saldo' => 'Logística > Estoque > Saldo',
                    'logistica_transferencias' => 'Logística > Estoque > Transferências',
                    'logistica_transferencia_ver' => 'Logística > Estoque > Transferências > Detalhe',
                    'logistica_transferencia_receber' => 'Logística > Estoque > Transferências > Receber',
                    'logistica_gerar_lista' => 'Logística > Listas > Gerar',
                    'logistica_listas' => 'Logística > Listas > Histórico',
                    'logistica_separacao_lista' => 'Logística > Listas > Separação',
                    'logistica_faltas_evento' => 'Logística > Alertas > Faltas',
                    'logistica_divergencias' => 'Logística > Divergências',
                    'logistica_resolver_conflitos' => 'Logística > Divergências > Resolver conflitos',
                    'logistica_financeiro' => 'Logística > Financeiro',
                    'logistica_financeiro_estoque' => 'Logística > Financeiro > Estoque',
                    'logistica_financeiro_lista' => 'Logística > Financeiro > Lista',
                    'logistica_revisar_custos' => 'Logística > Financeiro > Revisar custos',
                    'logistica_catalogo' => 'Logística > Catálogo',
                    'logistica_tipologias' => 'Logística > Catálogo > Tipologias',
                    'logistica_insumos' => 'Logística > Catálogo > Insumos',
                    'logistica_receitas' => 'Logística > Catálogo > Receitas',
                    'logistica_conexao' => 'Logística > Configurações > Conexão',
                    'logistica_unidades_medida' => 'Logística > Configurações > Unidades de medida',
                    'logistica_diagnostico' => 'Logística > Configurações > Diagnóstico',
                    'logistica_upload' => 'Logística > Upload'
                ];
                if (isset($logistica_breadcrumbs[$current_page])): ?>
                    <div style="margin: 12px 24px 0 24px; font-size: 0.9rem; color: #64748b;">
                        <?= htmlspecialchars($logistica_breadcrumbs[$current_page]) ?>
                    </div>
                <?php endif; ?>
                <?php 
                // Renderizar conteúdo diretamente se for uma página especial
                // NOTA: 'logistico', 'configuracoes', 'cadastros', 'financeiro', 'administrativo' agora usam
                // arquivos separados que gerenciam seu próprio conteúdo via includeSidebar
                if (in_array($current_page, ['dashboard', 'demandas', 'demandas_quadro'])) {
                    // O conteúdo já foi definido nas variáveis acima
                    if ($current_page === 'dashboard' && !empty($dashboard_content)) {
                        echo $dashboard_content;
                    }
                }
                // Outras páginas (logistico, configuracoes, cadastros, financeiro, administrativo) 
                // não renderizam aqui - seus arquivos PHP gerenciam seu próprio conteúdo via echo $conteudo
                
                // Fechar divs apenas se for página especial que já renderizou o conteúdo
                // NOTA: comercial usa comercial_landing.php que gerencia seus próprios divs
                // NOTA: logistico, configuracoes, cadastros, financeiro, administrativo agora 
                // usam arquivos separados que gerenciam seus próprios divs
                if (in_array($current_page, ['dashboard'])) {
                    echo '</div>'; // fecha #pageContent
                    echo '</div>'; // fecha .main-content
                }
                ?>
    
    <script src="/js/push-notifications.js"></script>
    <script>
        window.sidebarUnifiedConfig = {
            currentPage: <?= json_encode($current_page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            pushUserId: <?= (int)$push_user_id ?>
        };
    </script>
    <script src="assets/js/sidebar_unified.js"></script>
