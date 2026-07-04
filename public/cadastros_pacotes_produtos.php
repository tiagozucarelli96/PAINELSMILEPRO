<?php
/**
 * cadastros_pacotes_produtos.php
 * Cadastro unificado de pacotes, serviços e produtos na base de pacotes existente.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/pacotes_evento_helper.php';

if (empty($_SESSION['perm_cadastros']) && empty($_SESSION['perm_superadmin'])) {
    header('Location: index.php?page=dashboard');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function cadastros_pp_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cadastros_pp_money_to_float(string $value): float
{
    $value = trim($value);
    if ($value === '') {
        return 0.0;
    }

    $value = preg_replace('/[^\d,.\-]/u', '', $value) ?? '';
    if ($value === '') {
        return 0.0;
    }

    if (str_contains($value, ',') && str_contains($value, '.')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (str_contains($value, ',')) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function cadastros_pp_money($value): string
{
    return 'R$ ' . number_format((float)($value ?? 0), 2, ',', '.');
}

function cadastros_pp_query_params(): array
{
    $routeParams = $GLOBALS['PAINEL_CURRENT_ROUTE_QUERY'] ?? null;
    $params = is_array($routeParams) ? array_merge($_GET, $routeParams) : $_GET;
    $queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
    $routeQueryString = (string)($GLOBALS['PAINEL_CURRENT_ROUTE_QUERY_STRING'] ?? '');
    if ($routeQueryString !== '') {
        $queryString = $routeQueryString . ($queryString !== '' ? '&' . $queryString : '');
    }
    if ($queryString !== '') {
        parse_str(str_replace('&amp;', '&', $queryString), $parsed);
        if (is_array($parsed)) {
            $params = array_merge($parsed, $params);
        }
    }

    $requestUri = (string)($GLOBALS['PAINEL_CURRENT_ROUTE_URI'] ?? ($_SERVER['REQUEST_URI'] ?? ''));
    $requestQuery = (string)(parse_url($requestUri, PHP_URL_QUERY) ?? '');
    if ($requestQuery !== '') {
        parse_str(str_replace('&amp;', '&', $requestQuery), $parsed);
        if (is_array($parsed)) {
            $params = array_merge($parsed, $params);
        }
    }

    return $params;
}

function cadastros_pp_route_has_param(string $name, array $params): bool
{
    if (array_key_exists($name, $params)) {
        return true;
    }

    $needle = preg_quote($name, '/');
    foreach ([$_SERVER['QUERY_STRING'] ?? '', $_SERVER['REQUEST_URI'] ?? ''] as $source) {
        $source = str_replace('&amp;', '&', (string)$source);
        if (preg_match('/(?:^|[?&])' . $needle . '(?:=|&|$)/', $source)) {
            return true;
        }
    }

    return false;
}

function cadastros_pp_route_int_param(string $name, array $params): int
{
    if (isset($params[$name])) {
        return (int)$params[$name];
    }

    $needle = preg_quote($name, '/');
    foreach ([$_SERVER['QUERY_STRING'] ?? '', $_SERVER['REQUEST_URI'] ?? ''] as $source) {
        $source = str_replace('&amp;', '&', (string)$source);
        if (preg_match('/(?:^|[?&])' . $needle . '=([^&#]*)/', $source, $match)) {
            return (int)urldecode((string)$match[1]);
        }
    }

    return 0;
}

function cadastros_pp_ensure_schema(PDO $pdo): void
{
    pacotes_evento_ensure_schema($pdo);
    try {
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS categoria VARCHAR(20) NOT NULL DEFAULT 'Pacote'");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_venda NUMERIC(12,2) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_pacote NUMERIC(12,2) NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS pessoas_base INTEGER NULL");
        $pdo->exec("ALTER TABLE IF EXISTS logistica_pacotes_evento ADD COLUMN IF NOT EXISTS valor_convidado_adicional NUMERIC(12,2) NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_logistica_pacotes_evento_categoria ON logistica_pacotes_evento(categoria, deleted_at)");
    } catch (Throwable $e) {
        error_log('cadastros_pp_ensure_schema: ' . $e->getMessage());
    }
}

cadastros_pp_ensure_schema($pdo);

$userId = (int)($_SESSION['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id_usuario'] ?? 0);
$success = '';
$errors = [];
$queryParams = cadastros_pp_query_params();
$modalOpen = cadastros_pp_route_has_param('novo', $queryParams);
$editId = cadastros_pp_route_int_param('edit_id', $queryParams);
$modalItem = [
    'id' => 0,
    'categoria' => 'Pacote',
    'tipo_evento_real' => '',
    'modelo_preco' => 'simples',
    'nome' => '',
    'valor_venda' => '',
    'valor_pacote' => '',
    'pessoas_base' => '',
    'valor_convidado_adicional' => '',
    'descricao' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $postActiveTab = trim((string)($_POST['active_tab'] ?? 'pacotes'));
    if (!in_array($postActiveTab, ['pacotes', 'servicos', 'produtos'], true)) {
        $postActiveTab = 'pacotes';
    }

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $categoria = trim((string)($_POST['categoria'] ?? 'Pacote'));
        if (!in_array($categoria, ['Pacote', 'Serviço', 'Produto'], true)) {
            $categoria = 'Pacote';
        }

        $nome = trim((string)($_POST['nome'] ?? ''));
        $descricao = trim((string)($_POST['descricao'] ?? ''));
        $valorVenda = cadastros_pp_money_to_float((string)($_POST['valor_venda'] ?? '0'));
        $valorPacote = cadastros_pp_money_to_float((string)($_POST['valor_pacote'] ?? '0'));
        $pessoasBase = max(0, (int)($_POST['pessoas_base'] ?? 0));
        $valorConvidadoAdicional = cadastros_pp_money_to_float((string)($_POST['valor_convidado_adicional'] ?? '0'));
        $tipoEventoReal = trim((string)($_POST['tipo_evento_real'] ?? ''));
        $modeloPreco = in_array(($_POST['modelo_preco'] ?? 'simples'), ['simples', 'tabela'], true) ? (string)$_POST['modelo_preco'] : 'simples';

        if ($nome === '') {
            $errors[] = 'Informe o nome.';
        }

        if (empty($errors)) {
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE logistica_pacotes_evento
                        SET categoria = :categoria,
                            nome = :nome,
                            descricao = :descricao,
                            tipo_evento_real = :tipo_evento_real,
                            modelo_preco = :modelo_preco,
                            valor_venda = :valor_venda,
                            valor_pacote = :valor_pacote,
                            pessoas_base = :pessoas_base,
                            valor_convidado_adicional = :valor_convidado_adicional,
                            updated_at = NOW()
                        WHERE id = :id
                          AND deleted_at IS NULL
                    ");
                    $stmt->execute([
                        ':id' => $id,
                        ':categoria' => $categoria,
                        ':nome' => $nome,
                        ':descricao' => $descricao !== '' ? $descricao : null,
                        ':tipo_evento_real' => $categoria === 'Pacote' && $tipoEventoReal !== '' ? $tipoEventoReal : null,
                        ':modelo_preco' => $categoria === 'Pacote' ? $modeloPreco : 'simples',
                        ':valor_venda' => $categoria === 'Pacote' ? null : $valorVenda,
                        ':valor_pacote' => $categoria === 'Pacote' ? $valorPacote : null,
                        ':pessoas_base' => $categoria === 'Pacote' && $pessoasBase > 0 ? $pessoasBase : null,
                        ':valor_convidado_adicional' => $categoria === 'Pacote' ? $valorConvidadoAdicional : null,
                    ]);
                    $_SESSION['cadastros_pp_success'] = 'Cadastro atualizado com sucesso.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO logistica_pacotes_evento (
                            categoria, nome, descricao, tipo_evento_real, modelo_preco, valor_venda, valor_pacote, pessoas_base,
                            valor_convidado_adicional, oculto, created_by_user_id, created_at, updated_at
                        ) VALUES (
                            :categoria, :nome, :descricao, :tipo_evento_real, :modelo_preco, :valor_venda, :valor_pacote, :pessoas_base,
                            :valor_convidado_adicional, FALSE, :created_by_user_id, NOW(), NOW()
                        )
                    ");
                    $stmt->execute([
                        ':categoria' => $categoria,
                        ':nome' => $nome,
                        ':descricao' => $descricao !== '' ? $descricao : null,
                        ':tipo_evento_real' => $categoria === 'Pacote' && $tipoEventoReal !== '' ? $tipoEventoReal : null,
                        ':modelo_preco' => $categoria === 'Pacote' ? $modeloPreco : 'simples',
                        ':valor_venda' => $categoria === 'Pacote' ? null : $valorVenda,
                        ':valor_pacote' => $categoria === 'Pacote' ? $valorPacote : null,
                        ':pessoas_base' => $categoria === 'Pacote' && $pessoasBase > 0 ? $pessoasBase : null,
                        ':valor_convidado_adicional' => $categoria === 'Pacote' ? $valorConvidadoAdicional : null,
                        ':created_by_user_id' => $userId > 0 ? $userId : null,
                    ]);
                    $_SESSION['cadastros_pp_success'] = 'Cadastro criado com sucesso.';
                }
                $redirectTab = ['Pacote' => 'pacotes', 'Serviço' => 'servicos', 'Produto' => 'produtos'][$categoria] ?? 'pacotes';
                header('Location: index.php?page=cadastros_pacotes_produtos&tab=' . $redirectTab . '#pp-listagem');
                exit;
            } catch (Throwable $e) {
                error_log('cadastros_pp save: ' . $e->getMessage());
                $errors[] = 'Não foi possível salvar.';
            }
        }
    }

    if ($action === 'save_prices') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $result = pacotes_evento_preco_variacoes_salvar($pdo, $id, $_POST['variacoes'] ?? []);
            if (!empty($result['ok'])) {
                $_SESSION['cadastros_pp_success'] = 'Tabela de valores salva com sucesso.';
                header('Location: index.php?page=cadastros_pacotes_produtos&tab=pacotes&edit_id=' . $id . '#pp-modal');
                exit;
            }
            $errors[] = (string)($result['error'] ?? 'Não foi possível salvar a tabela de valores.');
        } catch (Throwable $e) {
            error_log('cadastros_pp save_prices: ' . $e->getMessage());
            $errors[] = 'Não foi possível salvar a tabela de valores.';
        }
    }

    if ($action === 'duplicate') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO logistica_pacotes_evento (
                    categoria, nome, descricao, tipo_evento_real, modelo_preco, valor_venda, valor_pacote, pessoas_base,
                    valor_convidado_adicional, oculto, created_by_user_id, created_at, updated_at
                )
                SELECT categoria, nome || ' (Cópia)', descricao, tipo_evento_real, modelo_preco, valor_venda, valor_pacote, pessoas_base,
                       valor_convidado_adicional, FALSE, :created_by_user_id, NOW(), NOW()
                FROM logistica_pacotes_evento
                WHERE id = :id
                  AND deleted_at IS NULL
                RETURNING id
            ");
            $stmt->execute([
                ':id' => $id,
                ':created_by_user_id' => $userId > 0 ? $userId : null,
            ]);
            $novoId = (int)$stmt->fetchColumn();
            if ($novoId > 0) {
                $variacoesCopia = [];
                foreach (pacotes_evento_preco_variacoes_listar($pdo, $id) as $variacao) {
                    $variacoesCopia[] = $variacao;
                }
                if (!empty($variacoesCopia)) {
                    pacotes_evento_preco_variacoes_salvar($pdo, $novoId, $variacoesCopia);
                }
            }
            $_SESSION['cadastros_pp_success'] = 'Cadastro duplicado com sucesso.';
            header('Location: index.php?page=cadastros_pacotes_produtos&tab=' . $postActiveTab);
            exit;
        } catch (Throwable $e) {
            error_log('cadastros_pp duplicate: ' . $e->getMessage());
            $errors[] = 'Não foi possível duplicar.';
        }
    }

    if ($action === 'archive') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $stmt = $pdo->prepare("
                UPDATE logistica_pacotes_evento
                SET deleted_at = NOW(),
                    deleted_by_user_id = :user_id,
                    updated_at = NOW()
                WHERE id = :id
                  AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $userId > 0 ? $userId : null,
            ]);
            $_SESSION['cadastros_pp_success'] = 'Cadastro arquivado com sucesso.';
            header('Location: index.php?page=cadastros_pacotes_produtos&tab=' . $postActiveTab);
            exit;
        } catch (Throwable $e) {
            error_log('cadastros_pp archive: ' . $e->getMessage());
            $errors[] = 'Não foi possível arquivar.';
        }
    }
}

if (!empty($_SESSION['cadastros_pp_success'])) {
    $success = (string)$_SESSION['cadastros_pp_success'];
    unset($_SESSION['cadastros_pp_success']);
}

if (!empty($errors) && ($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['action'] ?? '') === 'save')) {
    $modalOpen = true;
    $modalItem = [
        'id' => (int)($_POST['id'] ?? 0),
        'categoria' => trim((string)($_POST['categoria'] ?? 'Pacote')),
        'tipo_evento_real' => trim((string)($_POST['tipo_evento_real'] ?? '')),
        'modelo_preco' => trim((string)($_POST['modelo_preco'] ?? 'simples')),
        'nome' => trim((string)($_POST['nome'] ?? '')),
        'valor_venda' => trim((string)($_POST['valor_venda'] ?? '')),
        'valor_pacote' => trim((string)($_POST['valor_pacote'] ?? '')),
        'pessoas_base' => trim((string)($_POST['pessoas_base'] ?? '')),
        'valor_convidado_adicional' => trim((string)($_POST['valor_convidado_adicional'] ?? '')),
        'descricao' => trim((string)($_POST['descricao'] ?? '')),
    ];
} elseif ($editId > 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM logistica_pacotes_evento
            WHERE id = :id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $editId]);
        $itemEdicao = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($itemEdicao) {
            $modalOpen = true;
            $modalItem = [
                'id' => (int)$itemEdicao['id'],
                'categoria' => (string)($itemEdicao['categoria'] ?? 'Pacote'),
                'tipo_evento_real' => (string)($itemEdicao['tipo_evento_real'] ?? ''),
                'modelo_preco' => (string)($itemEdicao['modelo_preco'] ?? 'simples'),
                'nome' => (string)($itemEdicao['nome'] ?? ''),
                'valor_venda' => (string)($itemEdicao['valor_venda'] ?? ''),
                'valor_pacote' => (string)($itemEdicao['valor_pacote'] ?? ''),
                'pessoas_base' => (string)($itemEdicao['pessoas_base'] ?? ''),
                'valor_convidado_adicional' => (string)($itemEdicao['valor_convidado_adicional'] ?? ''),
                'descricao' => (string)($itemEdicao['descricao'] ?? ''),
            ];
        } else {
            $errors[] = 'Cadastro não encontrado para edição.';
        }
    } catch (Throwable $e) {
        error_log('cadastros_pp edit: ' . $e->getMessage());
        $errors[] = 'Não foi possível carregar o cadastro para edição.';
    }
}

try {
    $stmt = $pdo->query("
        SELECT *
        FROM logistica_pacotes_evento
        WHERE deleted_at IS NULL
        ORDER BY LOWER(nome) ASC, id ASC
    ");
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('cadastros_pp list: ' . $e->getMessage());
    $itens = [];
    $errors[] = 'Não foi possível carregar a listagem.';
}

$categoriaTabs = [
    'pacotes' => ['label' => 'Pacotes', 'categoria' => 'Pacote'],
    'servicos' => ['label' => 'Serviços', 'categoria' => 'Serviço'],
    'produtos' => ['label' => 'Produtos', 'categoria' => 'Produto'],
];
$activeTab = trim((string)($queryParams['tab'] ?? 'pacotes'));
if (!isset($categoriaTabs[$activeTab])) {
    $activeTab = 'pacotes';
}
$itensPorCategoria = [];
foreach ($categoriaTabs as $tabKey => $tabConfig) {
    $itensPorCategoria[(string)$tabConfig['categoria']] = [];
}
foreach ($itens as $item) {
    $categoriaItem = trim((string)($item['categoria'] ?? 'Pacote'));
    if (!isset($itensPorCategoria[$categoriaItem])) {
        $categoriaItem = 'Pacote';
    }
    $itensPorCategoria[$categoriaItem][] = $item;
}
$activeCategoria = (string)$categoriaTabs[$activeTab]['categoria'];
$itensListagem = $itensPorCategoria[$activeCategoria] ?? [];

$galeriaItens = [];
try {
    $table = $pdo->query("SELECT to_regclass('eventos_galeria')")->fetchColumn();
    if (trim((string)$table) !== '') {
        $stmt = $pdo->query("
            SELECT id, categoria, nome, descricao, tags, public_url
            FROM eventos_galeria
            WHERE deleted_at IS NULL
            ORDER BY uploaded_at DESC NULLS LAST, id DESC
            LIMIT 96
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $publicUrl = trim((string)($row['public_url'] ?? ''));
            $fallbackUrl = 'eventos_galeria_imagem.php?id=' . $id;
            $galeriaItens[] = [
                'id' => $id,
                'categoria' => (string)($row['categoria'] ?? ''),
                'nome' => (string)($row['nome'] ?? 'Imagem'),
                'texto' => trim((string)($row['descricao'] ?? '')) !== '' ? (string)$row['descricao'] : (string)($row['tags'] ?? ''),
                'preview_url' => $publicUrl !== '' ? $publicUrl : $fallbackUrl,
                'source_url' => $publicUrl !== '' ? $publicUrl : $fallbackUrl,
            ];
        }
    }
} catch (Throwable $e) {
    error_log('cadastros_pp galeria: ' . $e->getMessage());
}

includeSidebar('Pacotes, Serviços e Produtos');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (int)$modalItem['id'] === 0 && $modalOpen) {
    $modalItem['categoria'] = $activeCategoria;
}
$modalCategoria = in_array((string)$modalItem['categoria'], ['Pacote', 'Serviço', 'Produto'], true) ? (string)$modalItem['categoria'] : 'Pacote';
$modalIsPacote = $modalCategoria === 'Pacote';
$tiposEvento = pacotes_evento_tipos_evento_listar($pdo);
$editPriceVariations = [];
if ($modalIsPacote && (int)$modalItem['id'] > 0) {
    $editPriceVariations = pacotes_evento_preco_variacoes_listar($pdo, (int)$modalItem['id']);
}
$diasSemanaLabels = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];
$defaultPriceVariations = [
    ['nome' => 'Segunda a quinta', 'dias_semana' => '1,2,3,4', 'inclui_feriado' => false, 'inclui_vespera_feriado' => false, 'ativo' => true, 'faixas' => []],
    ['nome' => 'Sexta, sábado, domingo e feriado', 'dias_semana' => '5,6,7', 'inclui_feriado' => true, 'inclui_vespera_feriado' => false, 'ativo' => true, 'faixas' => []],
    ['nome' => 'Domingo e feriado', 'dias_semana' => '7', 'inclui_feriado' => true, 'inclui_vespera_feriado' => false, 'ativo' => false, 'faixas' => []],
];
$priceVariationsForForm = !empty($editPriceVariations) ? $editPriceVariations : $defaultPriceVariations;
$defaultPricePeople = [30, 40, 50, 60, 70, 80, 90, 100, 110, 120];
?>

<style>
.pp-page { padding: 1.5rem; max-width: 1500px; margin: 0 auto; }
.pp-header { display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap; margin-bottom: 1rem; }
.pp-title { margin: 0; color: #1e3a8a; font-size: 1.8rem; font-weight: 800; }
.pp-subtitle { margin: 0.35rem 0 0; color: #64748b; }
.pp-actions { display: flex; gap: 0.65rem; flex-wrap: wrap; }
.pp-btn { border: none; border-radius: 10px; background: #1e3a8a; color: #fff; font-weight: 800; padding: 0.7rem 1rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
.pp-btn.secondary { background: #fff; color: #1e293b; border: 1px solid #dbe3ef; }
.pp-alert { margin-bottom: 1rem; border-radius: 10px; padding: 0.85rem 1rem; font-weight: 800; }
.pp-alert.success { background: #ecfdf5; color: #166534; border: 1px solid #a7f3d0; }
.pp-alert.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.pp-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 14px 34px rgba(15, 23, 42, 0.07); overflow: hidden; }
.pp-card-header { padding: 1rem 1.1rem; border-bottom: 1px solid #e2e8f0; background: #f8fbff; }
.pp-card-title { margin: 0; color: #1e293b; font-weight: 800; font-size: 1.05rem; }
.pp-list-tabs { display: flex; gap: 0.45rem; flex-wrap: wrap; padding: 0.8rem 1.1rem 0; background: #f8fbff; }
.pp-list-tab { border: 1px solid #dbe3ef; border-bottom-color: #cbd5e1; border-radius: 10px 10px 0 0; background: #fff; color: #475569; font-weight: 900; padding: 0.62rem 0.9rem; text-decoration: none; display: inline-flex; gap: 0.45rem; align-items: center; }
.pp-list-tab.active { background: #1e3a8a; border-color: #1e3a8a; color: #fff; }
.pp-list-count { border-radius: 999px; background: rgba(15, 23, 42, 0.08); padding: 0.12rem 0.45rem; font-size: 0.76rem; }
.pp-list-tab.active .pp-list-count { background: rgba(255, 255, 255, 0.18); }
.pp-table-wrap { overflow: auto; }
.pp-table { width: 100%; border-collapse: collapse; min-width: 980px; }
.pp-table th, .pp-table td { padding: 0.85rem; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: middle; }
.pp-table th { background: #f8fafc; color: #475569; font-size: 0.78rem; text-transform: uppercase; }
.pp-name { color: #2878b8; font-weight: 900; }
.pp-pill { display: inline-flex; border-radius: 999px; padding: 0.22rem 0.58rem; font-size: 0.78rem; font-weight: 900; background: #e0f2fe; color: #075985; }
.pp-muted { color: #64748b; font-size: 0.84rem; }
.pp-row-actions { display: flex; gap: 0.45rem; flex-wrap: wrap; }
.pp-icon-btn { width: 38px; height: 38px; border: none; border-radius: 8px; color: #fff; font-weight: 900; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
.pp-icon-btn.copy { background: #263747; }
.pp-icon-btn.edit { background: #f2c94c; }
.pp-icon-btn.delete { background: #d9534f; }
.pp-modal-backdrop { position: fixed; inset: 0; z-index: 1300; display: none; align-items: center; justify-content: center; padding: 1rem; background: rgba(15, 23, 42, 0.55); }
.pp-modal-backdrop.open,
#pp-modal:target { display: flex; }
.pp-modal { width: min(1120px, 100%); max-height: calc(100dvh - 2rem); overflow: hidden; background: #fff; border-radius: 16px; box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28); display: flex; flex-direction: column; }
.pp-modal-header { padding: 1rem 1.1rem; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; gap: 1rem; align-items: center; }
.pp-modal-title { margin: 0; color: #1e293b; font-weight: 900; font-size: 1.15rem; }
.pp-modal-close { width: 36px; height: 36px; border: none; border-radius: 999px; background: #f1f5f9; color: #334155; cursor: pointer; font-size: 1.25rem; line-height: 1; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.pp-modal-close:hover { background: #e2e8f0; color: #0f172a; }
.pp-modal-scroll { padding: 1rem; display: grid; gap: 0.9rem; overflow: auto; min-height: 0; }
.pp-form { display: grid; gap: 0.9rem; min-height: 0; }
.pp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.85rem; }
.pp-field { display: grid; gap: 0.35rem; }
.pp-field.full { grid-column: 1 / -1; }
.pp-field label { color: #334155; font-size: 0.82rem; font-weight: 800; }
.pp-field input, .pp-field select, .pp-field textarea { width: 100%; border: 1px solid #d1d9e6; border-radius: 10px; padding: 0.68rem 0.78rem; color: #1e293b; background: #fff; }
.pp-modal-actions { display: flex; justify-content: flex-end; gap: 0.65rem; border-top: 1px solid #e2e8f0; padding: 1rem; flex-shrink: 0; background: #fff; }
.pp-service-fields.hidden, .pp-package-fields.hidden { display: none; }
.pp-tabs { display: flex; gap: 0.5rem; border-bottom: 1px solid #e2e8f0; margin-top: 0.25rem; }
.pp-tab-button { border: none; border-radius: 9px 9px 0 0; background: transparent; color: #475569; cursor: pointer; font-weight: 900; padding: 0.65rem 0.9rem; }
.pp-tab-button.active { background: #eff6ff; color: #1d4ed8; }
.pp-tab-button[disabled] { cursor: not-allowed; opacity: 0.45; }
.pp-tab-panel { display: none; min-width: 0; }
.pp-tab-panel.active { display: block; }
.pp-price-section { padding: 0; gap: 0.8rem; overflow: visible; }
.pp-tab-panel.active.pp-price-section { display: grid; }
.pp-price-title { margin: 0; color: #1e293b; font-size: 1rem; font-weight: 900; }
.pp-price-help { margin: 0; color: #64748b; font-size: 0.84rem; }
.pp-price-card { border: 1px solid #dbe6f3; border-radius: 12px; padding: 0.9rem; background: #f8fafc; display: grid; gap: 0.75rem; min-width: 0; }
.pp-price-head { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(280px, 1.5fr); gap: 0.8rem; }
.pp-days { display: flex; flex-wrap: wrap; gap: 0.4rem; }
.pp-day-chip { display: inline-flex; align-items: center; gap: 0.28rem; border: 1px solid #cbd5e1; border-radius: 999px; padding: 0.24rem 0.45rem; background: #fff; color: #334155; font-size: 0.78rem; font-weight: 800; }
.pp-price-rows { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 0.65rem; }
.pp-price-row { display: grid; grid-template-columns: 76px minmax(0, 1fr); gap: 0.45rem; align-items: center; min-width: 0; }
.pp-price-row input { width: 100%; min-width: 0; box-sizing: border-box; border: 1px solid #d1d9e6; border-radius: 8px; padding: 0.48rem 0.58rem; color: #1e293b; background: #fff; }
.pp-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 0.8rem; padding: 1rem; }
.pp-gallery-item { border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; overflow: hidden; display: grid; text-align: left; cursor: pointer; }
.pp-gallery-thumb { aspect-ratio: 4 / 3; background: #f1f5f9; overflow: hidden; }
.pp-gallery-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pp-gallery-body { padding: 0.7rem; display: grid; gap: 0.25rem; }
.pp-gallery-name { font-weight: 900; color: #1e293b; font-size: 0.86rem; }
.pp-gallery-meta { color: #64748b; font-size: 0.76rem; }
.pp-gallery-empty { padding: 1rem; color: #64748b; }
@media (max-width: 900px) { .pp-grid, .pp-price-head { grid-template-columns: 1fr; } }
@media (max-width: 768px) {
    .pp-modal-backdrop { padding: 0.75rem; }
    .pp-modal { width: 100%; max-height: calc(100dvh - 1.5rem); border-radius: 12px; }
}
</style>

<main class="pp-page">
    <div class="pp-header">
        <div>
            <h1 class="pp-title">Pacotes, serviços e produtos</h1>
            <p class="pp-subtitle">Cadastros comerciais reutilizando a base atual de pacotes.</p>
        </div>
        <div class="pp-actions">
            <a class="pp-btn secondary" href="index.php?page=cadastros">← Cadastros</a>
            <a class="pp-btn" href="index.php?page=cadastros_pacotes_produtos&tab=<?= cadastros_pp_e($activeTab) ?>&novo=1#pp-modal" data-open-pp-modal>+ Adicionar</a>
        </div>
    </div>

    <?php if ($success !== ''): ?><div class="pp-alert success"><?= cadastros_pp_e($success) ?></div><?php endif; ?>
    <?php foreach ($errors as $error): ?><div class="pp-alert error"><?= cadastros_pp_e((string)$error) ?></div><?php endforeach; ?>

    <section class="pp-card" id="pp-listagem">
        <div class="pp-card-header"><h2 class="pp-card-title">Listagem</h2></div>
        <nav class="pp-list-tabs" aria-label="Categorias da listagem">
            <?php foreach ($categoriaTabs as $tabKey => $tabConfig): ?>
                <?php
                $tabCategoria = (string)$tabConfig['categoria'];
                $tabCount = count($itensPorCategoria[$tabCategoria] ?? []);
                ?>
                <a
                    class="pp-list-tab <?= $activeTab === $tabKey ? 'active' : '' ?>"
                    href="index.php?page=cadastros_pacotes_produtos&tab=<?= cadastros_pp_e((string)$tabKey) ?>#pp-listagem"
                    <?= $activeTab === $tabKey ? 'aria-current="page"' : '' ?>
                >
                    <span><?= cadastros_pp_e((string)$tabConfig['label']) ?></span>
                    <span class="pp-list-count"><?= (int)$tabCount ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="pp-table-wrap">
            <table class="pp-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Categoria</th>
                        <th>Base</th>
                        <th>Preço</th>
                        <th>Valor venda</th>
                        <th>Opções</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($itensListagem as $item): ?>
                        <?php
                        $categoria = trim((string)($item['categoria'] ?? 'Pacote'));
                        $valorVenda = $categoria === 'Pacote' ? ($item['valor_pacote'] ?? 0) : ($item['valor_venda'] ?? 0);
                        $modeloPrecoItem = (string)($item['modelo_preco'] ?? 'simples');
                        ?>
                        <tr>
                            <td><span class="pp-name"><?= cadastros_pp_e((string)$item['nome']) ?></span></td>
                            <td><span class="pp-pill"><?= cadastros_pp_e($categoria) ?></span></td>
                            <td><?= $categoria === 'Pacote' ? (int)($item['pessoas_base'] ?? 0) . ' pessoas' : '-' ?></td>
                            <td>
                                <?php if ($categoria === 'Pacote'): ?>
                                    <span class="pp-pill"><?= $modeloPrecoItem === 'tabela' ? 'Tabela' : 'Simples' ?></span>
                                    <?php if (trim((string)($item['tipo_evento_real'] ?? '')) !== ''): ?>
                                        <div class="pp-muted"><?= cadastros_pp_e((string)($tiposEvento[(string)$item['tipo_evento_real']] ?? $item['tipo_evento_real'])) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="pp-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= cadastros_pp_money($valorVenda) ?></td>
                            <td>
                                <div class="pp-row-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="duplicate">
                                        <input type="hidden" name="active_tab" value="<?= cadastros_pp_e($activeTab) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button class="pp-icon-btn copy" type="submit" title="Duplicar">⧉</button>
                                    </form>
                                    <a
                                        class="pp-icon-btn edit"
                                        href="index.php?page=cadastros_pacotes_produtos&tab=<?= cadastros_pp_e($activeTab) ?>&edit_id=<?= (int)$item['id'] ?>#pp-modal"
                                        title="Editar"
                                        data-id="<?= (int)$item['id'] ?>"
                                    >✎</a>
                                    <form method="post" onsubmit="return confirm('Arquivar este cadastro?');">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="active_tab" value="<?= cadastros_pp_e($activeTab) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                        <button class="pp-icon-btn delete" type="submit" title="Arquivar">🗑</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($itensListagem)): ?>
                        <tr><td colspan="6">Nenhum cadastro encontrado em <?= cadastros_pp_e((string)$categoriaTabs[$activeTab]['label']) ?>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<div class="pp-modal-backdrop <?= $modalOpen ? 'open' : '' ?>" id="pp-modal" role="dialog" aria-modal="true" aria-labelledby="pp-modal-title"<?= $modalOpen ? ' style="display:flex"' : '' ?>>
    <div class="pp-modal">
        <div class="pp-modal-header">
            <h2 class="pp-modal-title" id="pp-modal-title"><?= (int)$modalItem['id'] > 0 ? 'Editar cadastro' : 'Adicionar cadastro' ?></h2>
            <button class="pp-modal-close" type="button" data-close-pp-modal aria-label="Fechar">×</button>
        </div>
        <div class="pp-modal-scroll">
        <form method="post" class="pp-form" id="pp-form">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="active_tab" value="<?= cadastros_pp_e($activeTab) ?>">
            <input type="hidden" name="id" id="pp-id" value="<?= (int)$modalItem['id'] ?>">
            <div class="pp-grid">
                <div class="pp-field">
                    <label for="pp-categoria">Categoria</label>
                    <select name="categoria" id="pp-categoria" required>
                        <option value="Pacote" <?= $modalCategoria === 'Pacote' ? 'selected' : '' ?>>Pacote</option>
                        <option value="Serviço" <?= $modalCategoria === 'Serviço' ? 'selected' : '' ?>>Serviço</option>
                        <option value="Produto" <?= $modalCategoria === 'Produto' ? 'selected' : '' ?>>Produto</option>
                    </select>
                </div>
                <div class="pp-field">
                    <label for="pp-nome">Nome</label>
                    <input type="text" name="nome" id="pp-nome" maxlength="180" required value="<?= cadastros_pp_e((string)$modalItem['nome']) ?>">
                </div>
                <div class="pp-field pp-service-fields <?= $modalIsPacote ? 'hidden' : '' ?>">
                    <label for="pp-valor-venda">Valor de venda</label>
                    <input type="text" name="valor_venda" id="pp-valor-venda" inputmode="decimal" placeholder="0,00" value="<?= cadastros_pp_e((string)$modalItem['valor_venda']) ?>">
                </div>
                <div class="pp-field pp-package-fields <?= $modalIsPacote ? '' : 'hidden' ?>">
                    <label for="pp-tipo-evento-real">Tipo de evento</label>
                    <select name="tipo_evento_real" id="pp-tipo-evento-real">
                        <option value="">Sem tipo definido</option>
                        <?php foreach ($tiposEvento as $tipoKey => $tipoLabel): ?>
                            <option value="<?= cadastros_pp_e((string)$tipoKey) ?>" <?= (string)$modalItem['tipo_evento_real'] === (string)$tipoKey ? 'selected' : '' ?>>
                                <?= cadastros_pp_e((string)$tipoLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pp-field pp-package-fields <?= $modalIsPacote ? '' : 'hidden' ?>">
                    <label for="pp-modelo-preco">Modelo de preço</label>
                    <select name="modelo_preco" id="pp-modelo-preco">
                        <?php $modalModeloPreco = (string)($modalItem['modelo_preco'] ?? 'simples'); ?>
                        <option value="simples" <?= $modalModeloPreco !== 'tabela' ? 'selected' : '' ?>>Simples</option>
                        <option value="tabela" <?= $modalModeloPreco === 'tabela' ? 'selected' : '' ?>>Tabela por dia/quantidade</option>
                    </select>
                </div>
                <div class="pp-field pp-package-fields <?= $modalIsPacote ? '' : 'hidden' ?>">
                    <label for="pp-valor-pacote">Valor Base</label>
                    <input type="text" name="valor_pacote" id="pp-valor-pacote" inputmode="decimal" placeholder="R$ 0,00" value="<?= cadastros_pp_e((string)$modalItem['valor_pacote']) ?>">
                </div>
                <div class="pp-field pp-package-fields <?= $modalIsPacote ? '' : 'hidden' ?>">
                    <label for="pp-pessoas-base">Quantia de convidados base</label>
                    <input type="number" name="pessoas_base" id="pp-pessoas-base" min="0" step="1" value="<?= cadastros_pp_e((string)$modalItem['pessoas_base']) ?>">
                </div>
                <div class="pp-field pp-package-fields <?= $modalIsPacote ? '' : 'hidden' ?>">
                    <label for="pp-valor-convidado-adicional">Convidado adicional</label>
                    <input type="text" name="valor_convidado_adicional" id="pp-valor-convidado-adicional" inputmode="decimal" placeholder="R$ 0,00" value="<?= cadastros_pp_e((string)$modalItem['valor_convidado_adicional']) ?>">
                </div>
            </div>
            <div class="pp-tabs" role="tablist" aria-label="Cadastro do pacote">
                <button type="button" class="pp-tab-button active" data-pp-tab="descricao">Descrição</button>
                <button type="button" class="pp-tab-button pp-package-fields <?= $modalIsPacote ? '' : 'hidden' ?>" data-pp-tab="precos" <?= (string)($modalItem['modelo_preco'] ?? 'simples') === 'tabela' ? '' : 'disabled' ?>>Tabela de valores</button>
            </div>
            <div class="pp-tab-panel active" data-pp-panel="descricao">
                <div class="pp-field full">
                    <label for="pp-descricao">Descrição</label>
                    <textarea name="descricao" id="pp-descricao" rows="14"><?= cadastros_pp_e((string)$modalItem['descricao']) ?></textarea>
                </div>
            </div>
        </form>
        <?php if ($modalIsPacote && (int)$modalItem['id'] > 0): ?>
            <form method="post" class="pp-price-section pp-package-fields pp-tab-panel" data-pp-panel="precos" id="pp-price-form">
                <input type="hidden" name="action" value="save_prices">
                <input type="hidden" name="active_tab" value="<?= cadastros_pp_e($activeTab) ?>">
                <input type="hidden" name="id" value="<?= (int)$modalItem['id'] ?>">
                <div>
                    <h3 class="pp-price-title">Tabela de valores</h3>
                    <p class="pp-price-help">Use quando o modelo de preço estiver como tabela. Pacotes simples continuam usando o valor base acima.</p>
                </div>
                <?php foreach ($priceVariationsForForm as $vIndex => $variation): ?>
                    <?php
                    $diasSelecionados = array_filter(array_map('intval', explode(',', (string)($variation['dias_semana'] ?? ''))));
                    $faixasMap = [];
                    foreach (($variation['faixas'] ?? []) as $faixa) {
                        $faixasMap[(int)($faixa['pessoas'] ?? 0)] = $faixa;
                    }
                    $peopleForVariation = array_values(array_unique(array_merge($defaultPricePeople, array_keys($faixasMap))));
                    sort($peopleForVariation);
                    ?>
                    <div class="pp-price-card">
                        <input type="hidden" name="variacoes[<?= $vIndex ?>][ordem]" value="<?= $vIndex + 1 ?>">
                        <input type="hidden" name="variacoes[<?= $vIndex ?>][ativo]" value="0">
                        <div class="pp-price-head">
                            <div class="pp-field">
                                <label>Nome da variação</label>
                                <input type="text" name="variacoes[<?= $vIndex ?>][nome]" maxlength="120" value="<?= cadastros_pp_e((string)($variation['nome'] ?? '')) ?>">
                                <label class="pp-day-chip">
                                    <input type="checkbox" name="variacoes[<?= $vIndex ?>][ativo]" value="1" <?= !isset($variation['ativo']) || !empty($variation['ativo']) ? 'checked' : '' ?>>
                                    Ativa
                                </label>
                            </div>
                            <div class="pp-field">
                                <label>Dias aplicáveis</label>
                                <div class="pp-days">
                                    <?php foreach ($diasSemanaLabels as $diaNumero => $diaLabel): ?>
                                        <label class="pp-day-chip">
                                            <input type="checkbox" name="variacoes[<?= $vIndex ?>][dias_semana][]" value="<?= $diaNumero ?>" <?= in_array($diaNumero, $diasSelecionados, true) ? 'checked' : '' ?>>
                                            <?= cadastros_pp_e($diaLabel) ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <label class="pp-day-chip">
                                        <input type="checkbox" name="variacoes[<?= $vIndex ?>][inclui_feriado]" value="1" <?= !empty($variation['inclui_feriado']) ? 'checked' : '' ?>>
                                        Feriado
                                    </label>
                                    <label class="pp-day-chip">
                                        <input type="checkbox" name="variacoes[<?= $vIndex ?>][inclui_vespera_feriado]" value="1" <?= !empty($variation['inclui_vespera_feriado']) ? 'checked' : '' ?>>
                                        Véspera
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="pp-price-rows">
                            <?php foreach ($peopleForVariation as $fIndex => $pessoas): ?>
                                <?php $faixa = $faixasMap[(int)$pessoas] ?? null; ?>
                                <div class="pp-price-row">
                                    <input type="number" min="0" name="variacoes[<?= $vIndex ?>][faixas][<?= $fIndex ?>][pessoas]" value="<?= (int)$pessoas ?>" placeholder="Pessoas">
                                    <input type="text" inputmode="decimal" class="pp-price-money" name="variacoes[<?= $vIndex ?>][faixas][<?= $fIndex ?>][valor]" value="<?= $faixa ? cadastros_pp_e(pacotes_evento_format_money($faixa['valor'] ?? 0)) : '' ?>" placeholder="R$ 0,00">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </form>
        <?php elseif ($modalIsPacote): ?>
            <div class="pp-price-section pp-package-fields pp-tab-panel" data-pp-panel="precos">
                <h3 class="pp-price-title">Tabela de valores</h3>
                <p class="pp-price-help">Salve o pacote primeiro para liberar a tabela por dia da semana e quantidade de pessoas.</p>
            </div>
        <?php endif; ?>
        </div>
        <div class="pp-modal-actions">
            <a class="pp-btn secondary" href="index.php?page=cadastros_pacotes_produtos&tab=<?= cadastros_pp_e($activeTab) ?>" data-close-pp-modal>Cancelar</a>
            <?php if ($modalIsPacote && (int)$modalItem['id'] > 0): ?>
                <button class="pp-btn secondary" type="submit" form="pp-price-form" data-pp-table-submit <?= (string)($modalItem['modelo_preco'] ?? 'simples') === 'tabela' ? '' : 'hidden disabled' ?>>Salvar tabela</button>
            <?php endif; ?>
            <button class="pp-btn" type="submit" form="pp-form">Salvar</button>
        </div>
    </div>
</div>

<div class="pp-modal-backdrop" id="pp-gallery-modal" role="dialog" aria-modal="true" aria-labelledby="pp-gallery-title">
    <div class="pp-modal">
        <div class="pp-modal-header">
            <h2 class="pp-modal-title" id="pp-gallery-title">Selecionar imagem da galeria</h2>
            <button class="pp-modal-close" type="button" data-close-pp-gallery aria-label="Fechar">×</button>
        </div>
        <?php if (!empty($galeriaItens)): ?>
            <div class="pp-gallery-grid">
                <?php foreach ($galeriaItens as $imagem): ?>
                    <button
                        type="button"
                        class="pp-gallery-item"
                        data-gallery-image
                        data-src="<?= cadastros_pp_e((string)$imagem['source_url']) ?>"
                        data-name="<?= cadastros_pp_e((string)$imagem['nome']) ?>"
                    >
                        <span class="pp-gallery-thumb"><img src="<?= cadastros_pp_e((string)$imagem['preview_url']) ?>" alt="<?= cadastros_pp_e((string)$imagem['nome']) ?>"></span>
                        <span class="pp-gallery-body">
                            <span class="pp-gallery-name"><?= cadastros_pp_e((string)$imagem['nome']) ?></span>
                            <span class="pp-gallery-meta"><?= cadastros_pp_e((string)$imagem['categoria']) ?></span>
                        </span>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="pp-gallery-empty">Nenhuma imagem encontrada na galeria.</div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tinymce@6/tinymce.min.js"></script>
<script type="application/json" id="pp-items-json"><?= json_encode(array_column($itens, null, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script>
const ppActiveTab = <?= json_encode($activeTab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const ppDefaultCategoria = <?= json_encode($activeCategoria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const ppModal = document.getElementById('pp-modal');
const ppGalleryModal = document.getElementById('pp-gallery-modal');
const ppForm = document.getElementById('pp-form');
const ppCategoria = document.getElementById('pp-categoria');
const ppModeloPreco = document.getElementById('pp-modelo-preco');
const ppDescricao = document.getElementById('pp-descricao');
const ppPackageMoneyFieldIds = ['pp-valor-pacote', 'pp-valor-convidado-adicional'];
let ppItems = {};
try {
    ppItems = JSON.parse(document.getElementById('pp-items-json')?.textContent || '{}');
} catch (error) {
    ppItems = {};
}

function ppFormatMoneyValue(value) {
    const digits = String(value || '').replace(/\D/g, '');
    if (!digits) return '';
    const amount = Number.parseInt(digits, 10) / 100;
    return amount.toLocaleString('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function ppFormatMoneyField(field) {
    if (!field) return;
    field.value = ppFormatMoneyValue(field.value);
}

function ppFormatPackageMoneyFields() {
    ppPackageMoneyFieldIds.forEach((id) => ppFormatMoneyField(document.getElementById(id)));
}

function initPpTiny() {
    if (typeof tinymce === 'undefined' || tinymce.get('pp-descricao')) return;
    tinymce.init({
        selector: '#pp-descricao',
        base_url: 'https://cdn.jsdelivr.net/npm/tinymce@6',
        suffix: '.min',
        menubar: false,
        branding: false,
        promotion: false,
        plugins: 'lists link image table code fullscreen',
        toolbar: 'undo redo | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist outdent indent | link image galeriaSmile table | removeformat | code fullscreen',
        height: 340,
        paste_data_images: true,
        automatic_uploads: true,
        setup: function(editor) {
            editor.ui.registry.addButton('galeriaSmile', {
                text: 'Galeria',
                tooltip: 'Inserir imagem da galeria',
                onAction: function() {
                    ppGalleryModal?.classList.add('open');
                }
            });
        },
        images_upload_handler: function(blobInfo) {
            return new Promise(function(resolve, reject) {
                const xhr = new XMLHttpRequest();
                const formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());
                xhr.open('POST', `${window.location.pathname}?page=cadastros_upload_imagem`);
                xhr.onload = function() {
                    if (xhr.status < 200 || xhr.status >= 300) {
                        reject('Upload falhou: ' + xhr.status);
                        return;
                    }
                    try {
                        const data = JSON.parse(xhr.responseText);
                        if (data.location) resolve(data.location);
                        else reject(data.error || 'Resposta inválida');
                    } catch (error) {
                        reject('Resposta inválida');
                    }
                };
                xhr.onerror = function() { reject('Falha de rede'); };
                xhr.send(formData);
            });
        },
        content_style: 'body { font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 1.45; color: #111827; }'
    });
}

function setTinyContent(html) {
    if (typeof tinymce !== 'undefined' && tinymce.get('pp-descricao')) {
        tinymce.get('pp-descricao').setContent(html || '');
    } else {
        ppDescricao.value = html || '';
    }
}

function updateCategoriaFields() {
    const isPacote = ppCategoria.value === 'Pacote';
    document.querySelectorAll('.pp-package-fields').forEach((el) => el.classList.toggle('hidden', !isPacote));
    document.querySelectorAll('.pp-service-fields').forEach((el) => el.classList.toggle('hidden', isPacote));
    updatePrecoTabState();
}

function switchPpTab(tabName) {
    const button = document.querySelector(`[data-pp-tab="${tabName}"]`);
    if (!button || button.disabled || button.classList.contains('hidden')) return;
    document.querySelectorAll('[data-pp-tab]').forEach((tab) => tab.classList.toggle('active', tab === button));
    document.querySelectorAll('[data-pp-panel]').forEach((panel) => panel.classList.toggle('active', panel.dataset.ppPanel === tabName));
    if (tabName === 'descricao') {
        initPpTiny();
    }
}

function updatePrecoTabState() {
    const isPacote = ppCategoria?.value === 'Pacote';
    const isTabela = ppModeloPreco?.value === 'tabela';
    const priceTab = document.querySelector('[data-pp-tab="precos"]');
    const tableSubmit = document.querySelector('[data-pp-table-submit]');
    if (priceTab) {
        priceTab.disabled = !isPacote || !isTabela;
        priceTab.classList.toggle('hidden', !isPacote);
    }
    if (tableSubmit) {
        tableSubmit.hidden = !isPacote || !isTabela;
        tableSubmit.disabled = !isPacote || !isTabela;
    }
    const pricePanelActive = document.querySelector('[data-pp-panel="precos"]')?.classList.contains('active');
    if ((!isPacote || !isTabela) && pricePanelActive) {
        switchPpTab('descricao');
    }
}

function openPpModal(data = null) {
    document.getElementById('pp-modal-title').textContent = data ? 'Editar cadastro' : 'Adicionar cadastro';
    document.getElementById('pp-id').value = data?.id || '0';
    ppCategoria.value = data?.categoria || ppDefaultCategoria || 'Pacote';
    document.getElementById('pp-nome').value = data?.nome || '';
    document.getElementById('pp-tipo-evento-real').value = data?.tipoEventoReal || '';
    document.getElementById('pp-modelo-preco').value = data?.modeloPreco || 'simples';
    document.getElementById('pp-valor-venda').value = data?.valorVenda || '';
    document.getElementById('pp-valor-pacote').value = data?.valorPacote || '';
    document.getElementById('pp-pessoas-base').value = data?.pessoasBase || '';
    document.getElementById('pp-valor-convidado-adicional').value = data?.valorConvidadoAdicional || '';
    ppFormatPackageMoneyFields();
    updateCategoriaFields();
    updatePrecoTabState();
    ppModal.style.display = 'flex';
    ppModal.classList.add('open');
    initPpTiny();
    window.setTimeout(() => setTinyContent(data?.descricao || ''), 120);
}

function closePpModal() {
    if (!ppModal) return;
    ppModal.classList.remove('open');
    ppModal.style.display = 'none';
    history.replaceState(null, '', `index.php?page=cadastros_pacotes_produtos&tab=${ppActiveTab}`);
}

function getPpItemModalData(id) {
    const item = ppItems[id || ''] || {};
    return {
        id: item.id || id || '0',
        categoria: item.categoria || 'Pacote',
        tipoEventoReal: item.tipo_evento_real || '',
        modeloPreco: item.modelo_preco || 'simples',
        nome: item.nome || '',
        valorVenda: item.valor_venda || '',
        valorPacote: item.valor_pacote || '',
        pessoasBase: item.pessoas_base || '',
        valorConvidadoAdicional: item.valor_convidado_adicional || '',
        descricao: item.descricao || '',
    };
}

window.ppOpenCadastroModal = function () {
    openPpModal();
};

window.ppOpenCadastroModalFromButton = function (button) {
    openPpModal(getPpItemModalData(button?.dataset?.id || ''));
};

window.ppCloseCadastroModal = closePpModal;

function closePpGalleryModal() {
    ppGalleryModal?.classList.remove('open');
}

function openPpModalFromUrl() {
    const params = new URLSearchParams(window.location.search || '');
    const editId = params.get('edit_id');
    if (editId) {
        openPpModal(getPpItemModalData(editId));
        return;
    }
    if (params.has('novo')) {
        openPpModal();
    }
}

document.querySelector('[data-open-pp-modal]')?.addEventListener('click', (event) => {
    event.preventDefault();
    history.replaceState(null, '', `index.php?page=cadastros_pacotes_produtos&tab=${ppActiveTab}&novo=1#pp-modal`);
    openPpModal();
});
document.querySelectorAll('[data-close-pp-modal]').forEach((button) => button.addEventListener('click', (event) => {
    event.preventDefault();
    closePpModal();
}));
ppModal?.addEventListener('click', (event) => {
    if (event.target === ppModal) closePpModal();
});
ppGalleryModal?.addEventListener('click', (event) => {
    if (event.target === ppGalleryModal) closePpGalleryModal();
});
document.querySelectorAll('[data-close-pp-gallery]').forEach((button) => button.addEventListener('click', closePpGalleryModal));
ppCategoria?.addEventListener('change', updateCategoriaFields);
ppModeloPreco?.addEventListener('change', updatePrecoTabState);

document.querySelectorAll('[data-pp-tab]').forEach((button) => {
    button.addEventListener('click', () => switchPpTab(button.dataset.ppTab || 'descricao'));
});

document.querySelectorAll('[data-edit-pp]').forEach((button) => {
    button.addEventListener('click', (event) => {
        event.preventDefault();
        history.replaceState(null, '', `index.php?page=cadastros_pacotes_produtos&tab=${ppActiveTab}&edit_id=${button.dataset.id || ''}#pp-modal`);
        openPpModal(getPpItemModalData(button.dataset.id || ''));
    });
});

openPpModalFromUrl();

if (ppModal?.classList.contains('open')) {
    updateCategoriaFields();
    initPpTiny();
    window.setTimeout(() => setTinyContent(ppDescricao?.value || ''), 120);
}

ppForm?.addEventListener('submit', () => {
    if (typeof tinymce !== 'undefined') {
        tinymce.triggerSave();
    }
    ppFormatPackageMoneyFields();
});

ppPackageMoneyFieldIds.forEach((id) => {
    const field = document.getElementById(id);
    field?.addEventListener('input', () => ppFormatMoneyField(field));
    field?.addEventListener('blur', () => ppFormatMoneyField(field));
});

document.querySelectorAll('.pp-price-money').forEach((field) => {
    field.addEventListener('input', () => ppFormatMoneyField(field));
    field.addEventListener('blur', () => ppFormatMoneyField(field));
});

document.querySelectorAll('[data-gallery-image]').forEach((button) => {
    button.addEventListener('click', () => {
        const src = button.dataset.src || '';
        const name = button.dataset.name || 'Imagem';
        const editor = typeof tinymce !== 'undefined' ? tinymce.get('pp-descricao') : null;
        if (src && editor) {
            editor.insertContent(`<p><img src="${src}" alt="${name}" style="max-width:100%;height:auto;"></p>`);
        }
        closePpGalleryModal();
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closePpGalleryModal();
        closePpModal();
    }
});
</script>
