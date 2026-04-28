<?php
require_once __DIR__ . '/logistica_tz.php';
// logistica_catalogo.php — Catálogo unificado (Insumos + Receitas)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';

$can_manage = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico']);
$can_see_cost = !empty($_SESSION['perm_superadmin']) || !empty($_SESSION['perm_logistico_financeiro']);

if (!$can_manage) {
    http_response_code(403);
    echo '<div class="alert-error">Acesso negado.</div>';
    exit;
}

$tipo = trim((string)($_GET['tipo'] ?? 'todos'));
$status = trim((string)($_GET['status'] ?? 'todos'));
$search = trim((string)($_GET['q'] ?? ''));

$tipo = in_array($tipo, ['todos', 'insumos', 'receitas'], true) ? $tipo : 'todos';
$status = in_array($status, ['todos', 'ativos', 'inativos'], true) ? $status : 'todos';

$itens = [];
$catalog_messages = [];
$catalog_errors = [];

function catalogo_erros_fk(PDOException $e): bool
{
    return $e->getCode() === '23503';
}

function catalogo_buscar_bloqueios_exclusao_insumo(PDO $pdo, int $id): array
{
    $checks = [
        'fichas técnicas' => "SELECT COUNT(*) FROM logistica_receita_componentes WHERE item_tipo = 'insumo' AND item_id = :id",
        'listas de compras' => "SELECT COUNT(*) FROM logistica_lista_itens WHERE insumo_id = :id",
        'saldos de estoque' => "SELECT COUNT(*) FROM logistica_estoque_saldos WHERE insumo_id = :id",
        'contagens de estoque' => "SELECT COUNT(*) FROM logistica_estoque_contagem_itens WHERE insumo_id = :id",
        'movimentações de estoque' => "SELECT COUNT(*) FROM logistica_estoque_movimentos WHERE insumo_id = :id",
        'itens baixados em eventos' => "SELECT COUNT(*) FROM logistica_evento_itens_baixa WHERE insumo_id = :id",
        'alertas/logs' => "SELECT COUNT(*) FROM logistica_alertas_log WHERE insumo_id = :id",
        'revisões de custo' => "SELECT COUNT(*) FROM logistica_custos_log WHERE insumo_id = :id"
    ];

    $blockedBy = [];
    foreach ($checks as $label => $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $blockedBy[] = $label;
            }
        } catch (Throwable $e) {
        }
    }

    return $blockedBy;
}

function catalogo_buscar_bloqueios_exclusao_receita(PDO $pdo, int $id): array
{
    $checks = [
        'componentes de outras fichas técnicas' => "SELECT COUNT(*) FROM logistica_receita_componentes WHERE item_tipo = 'receita' AND item_id = :id"
    ];

    $blockedBy = [];
    foreach ($checks as $label => $sql) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id' => $id]);
            $count = (int)$stmt->fetchColumn();
            if ($count > 0) {
                $blockedBy[] = $label;
            }
        } catch (Throwable $e) {
        }
    }

    return $blockedBy;
}

function catalogo_mensagem_bloqueio(string $tipo, array $blockedBy): string
{
    if (empty($blockedBy)) {
        return "Não foi possível excluir {$tipo} porque ele ainda está sendo usado em outros registros.";
    }

    return "Não foi possível excluir {$tipo} porque ele ainda está vinculado a: " . implode(', ', $blockedBy) . '.';
}

function h_catalogo(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function catalogo_normalizar_texto(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }

    $value = preg_replace('/[^a-z0-9\s]/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', (string)$value);
    return trim((string)$value);
}

function catalogo_match_score(string $query, string $candidate): ?float
{
    $query = catalogo_normalizar_texto($query);
    $candidate = catalogo_normalizar_texto($candidate);

    if ($query === '' || $candidate === '') {
        return null;
    }

    if ($query === $candidate) {
        return 1000.0;
    }

    if (str_contains($candidate, $query)) {
        return 900.0 - (strlen($candidate) - strlen($query));
    }

    $queryTokens = array_values(array_filter(explode(' ', $query)));
    $candidateTokens = array_values(array_filter(explode(' ', $candidate)));

    $tokenHits = 0;
    foreach ($queryTokens as $token) {
        foreach ($candidateTokens as $candidateToken) {
            if ($candidateToken === $token || str_contains($candidateToken, $token) || str_contains($token, $candidateToken)) {
                $tokenHits++;
                break;
            }

            $distance = levenshtein($token, $candidateToken);
            $maxLen = max(strlen($token), strlen($candidateToken));
            if ($maxLen <= 0) {
                continue;
            }
            if ($distance <= 1 || ($maxLen >= 6 && $distance <= 2)) {
                $tokenHits++;
                break;
            }
        }
    }

    similar_text($query, $candidate, $similarityPercent);
    $distance = levenshtein($query, $candidate);
    $maxLen = max(strlen($query), strlen($candidate));
    $distanceRatio = $maxLen > 0 ? 1 - ($distance / $maxLen) : 0;

    if ($tokenHits === 0 && $similarityPercent < 55 && $distanceRatio < 0.45) {
        return null;
    }

    return ($tokenHits * 100) + $similarityPercent + ($distanceRatio * 100);
}

function catalogo_filtrar_itens(array $items, string $search): array
{
    $search = trim($search);
    if ($search === '') {
        return $items;
    }

    $ranked = [];
    foreach ($items as $item) {
        $searchables = [
            (string)($item['nome'] ?? ''),
            (string)($item['tipologia'] ?? ''),
            (string)($item['extra'] ?? ''),
            (string)($item['search_extra'] ?? '')
        ];

        $bestScore = null;
        foreach ($searchables as $candidate) {
            $score = catalogo_match_score($search, $candidate);
            if ($score !== null && ($bestScore === null || $score > $bestScore)) {
                $bestScore = $score;
            }
        }

        if ($bestScore === null) {
            continue;
        }

        $item['_score'] = $bestScore;
        $ranked[] = $item;
    }

    usort($ranked, static function (array $a, array $b): int {
        $scoreCompare = ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }
        return strcmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? ''));
    });

    foreach ($ranked as &$item) {
        unset($item['_score']);
    }
    unset($item);

    return $ranked;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            if ($action === 'delete_insumo') {
                $pdo->prepare("DELETE FROM logistica_insumos WHERE id = :id")->execute([':id' => $id]);
                $catalog_messages[] = 'Insumo excluído.';
            } elseif ($action === 'delete_receita') {
                $pdo->prepare("DELETE FROM logistica_receitas WHERE id = :id")->execute([':id' => $id]);
                $catalog_messages[] = 'Receita excluída.';
            }
        } catch (PDOException $e) {
            if ($action === 'delete_insumo' && catalogo_erros_fk($e)) {
                $catalog_errors[] = catalogo_mensagem_bloqueio('este insumo', catalogo_buscar_bloqueios_exclusao_insumo($pdo, $id));
            } elseif ($action === 'delete_receita' && catalogo_erros_fk($e)) {
                $catalog_errors[] = catalogo_mensagem_bloqueio('esta receita', catalogo_buscar_bloqueios_exclusao_receita($pdo, $id));
            } else {
                $catalog_errors[] = 'Não foi possível concluir a exclusão agora.';
                error_log('Erro ao excluir item do catálogo: ' . $e->getMessage());
            }
        }
    }
}

if ($tipo === 'todos' || $tipo === 'insumos') {
    $sql = "
        SELECT i.id,
               i.nome_oficial AS nome,
               i.ativo,
               i.sinonimos,
               t.nome AS tipologia,
               u.nome AS unidade,
               i.custo_padrao
        FROM logistica_insumos i
        LEFT JOIN logistica_tipologias_insumo t ON t.id = i.tipologia_insumo_id
        LEFT JOIN logistica_unidades_medida u ON u.id = i.unidade_medida_padrao_id
    ";
    $conds = [];
    $params = [];
    if ($status === 'ativos') {
        $conds[] = "i.ativo IS TRUE";
    } elseif ($status === 'inativos') {
        $conds[] = "i.ativo IS FALSE";
    }
    if ($conds) {
        $sql .= " WHERE " . implode(' AND ', $conds);
    }
    $sql .= " ORDER BY i.nome_oficial";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $valores = [];
        if ($can_see_cost && $row['custo_padrao'] !== null && $row['custo_padrao'] !== '') {
            $valores[] = 'Custo: R$ ' . number_format((float)$row['custo_padrao'], 2, ',', '.');
        }

        $itens[] = [
            'tipo' => 'Insumo',
            'nome' => $row['nome'] ?? '',
            'tipologia' => $row['tipologia'] ?? '',
            'extra' => $row['unidade'] ?? '',
            'search_extra' => (string)($row['sinonimos'] ?? ''),
            'valores' => $valores ? implode(' | ', $valores) : '-',
            'ativo' => !empty($row['ativo']),
            'id' => (int)$row['id'],
            'page' => 'logistica_insumos'
        ];
    }
}

if ($tipo === 'todos' || $tipo === 'receitas') {
    $sql = "
        SELECT r.id,
               r.nome,
               r.ativo,
               r.rendimento_base_pessoas,
               t.nome AS tipologia
        FROM logistica_receitas r
        LEFT JOIN logistica_tipologias_receita t ON t.id = r.tipologia_receita_id
    ";
    $conds = [];
    $params = [];
    if ($status === 'ativos') {
        $conds[] = "r.ativo IS TRUE";
    } elseif ($status === 'inativos') {
        $conds[] = "r.ativo IS FALSE";
    }
    if ($conds) {
        $sql .= " WHERE " . implode(' AND ', $conds);
    }
    $sql .= " ORDER BY r.nome";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rendimento = (int)($row['rendimento_base_pessoas'] ?? 0);
        $itens[] = [
            'tipo' => 'Receita',
            'nome' => $row['nome'] ?? '',
            'tipologia' => $row['tipologia'] ?? '',
            'extra' => $rendimento > 0 ? ('Rendimento: ' . $rendimento) : '',
            'search_extra' => '',
            'valores' => '-',
            'ativo' => !empty($row['ativo']),
            'id' => (int)$row['id'],
            'page' => 'logistica_receitas'
        ];
    }
}

$itens = catalogo_filtrar_itens($itens, $search);

if ($search === '') {
    usort($itens, static function ($a, $b) {
        return strcmp($a['nome'], $b['nome']);
    });
}

includeSidebar('Catálogo Logístico');
?>

<style>
.catalogo-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}
.section-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    align-items: center;
}
.form-input {
    padding: 0.6rem 0.75rem;
    border: 1px solid #cbd5f5;
    border-radius: 8px;
}
.btn-primary {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.6rem 1rem;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
}
.btn-secondary {
    background: #e2e8f0;
    color: #1f2937;
    border: none;
    padding: 0.5rem 0.9rem;
    border-radius: 8px;
    cursor: pointer;
}
.table {
    width: 100%;
    border-collapse: collapse;
}
.table th, .table td {
    text-align: left;
    padding: 0.6rem;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.95rem;
}
.status-pill {
    display: inline-block;
    padding: 0.15rem 0.6rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    color: #fff;
}
.status-ativo { background: #16a34a; }
.status-inativo { background: #f97316; }
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(2px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    z-index: 9999;
}
.modal {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    width: min(1200px, 95vw);
    height: min(90vh, 860px);
    overflow: hidden;
    box-shadow: 0 20px 50px rgba(0,0,0,0.25);
    display: flex;
    flex-direction: column;
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.85rem 1.15rem;
    border-bottom: 1px solid #e5e7eb;
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
}
.modal-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: 0.01em;
}
.modal-close {
    border: none;
    background: #e2e8f0;
    color: #0f172a;
    border-radius: 8px;
    width: 32px;
    height: 32px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-weight: 700;
}
.modal-close:hover {
    background: #cbd5e1;
}
.modal-body {
    position: relative;
    flex: 1;
    background: #f8fafc;
}
.modal-iframe {
    width: 100%;
    height: 100%;
    border: none;
    background: #f8fafc;
    transition: opacity 0.18s ease;
}
.modal-body.is-loading .modal-iframe {
    opacity: 0;
}
.modal-loader {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.7rem;
    background: linear-gradient(180deg, rgba(248, 250, 252, 0.96), rgba(241, 245, 249, 0.96));
    color: #475569;
    font-size: 0.95rem;
    transition: opacity 0.18s ease;
}
.modal-body:not(.is-loading) .modal-loader {
    opacity: 0;
    pointer-events: none;
}
.catalog-hidden {
    display: none;
}
.modal-loader-spinner {
    width: 18px;
    height: 18px;
    border: 2px solid #cbd5e1;
    border-top-color: #2563eb;
    border-radius: 999px;
    animation: modalSpin 0.8s linear infinite;
}
@keyframes modalSpin {
    to {
        transform: rotate(360deg);
    }
}
@media (max-width: 768px) {
    .modal-overlay {
        padding: 0.4rem;
    }
    .modal {
        width: 100%;
        height: 94vh;
        border-radius: 12px;
    }
    .modal-header {
        padding: 0.75rem 0.9rem;
    }
}
</style>

<div class="catalogo-container">
    <div class="section-card">
        <?php foreach ($catalog_errors as $msg): ?>
            <div class="alert alert-error"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($catalog_messages as $msg): ?>
            <div class="alert alert-success"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <div class="form-row" style="justify-content: space-between;">
            <div class="form-row">
                <button class="btn-primary" type="button" onclick="openCatalogModal('insumo')">+ Insumo</button>
                <button class="btn-primary" type="button" onclick="openCatalogModal('receita')">+ Receita</button>
                <a class="btn-secondary" href="index.php?page=logistica_pacotes_evento" style="text-decoration:none;display:inline-flex;align-items:center;">Pacotes</a>
                <a class="btn-secondary" href="index.php?page=logistica_cardapio_secoes" style="text-decoration:none;display:inline-flex;align-items:center;">Seção de Cardápio</a>
                <button class="btn-secondary" type="button" onclick="location.reload()">Atualizar</button>
            </div>
            <form method="GET" class="form-row">
                <input type="hidden" name="page" value="logistica_catalogo">
                <select class="form-input" name="tipo">
                    <option value="todos" <?= $tipo === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="insumos" <?= $tipo === 'insumos' ? 'selected' : '' ?>>Insumos</option>
                    <option value="receitas" <?= $tipo === 'receitas' ? 'selected' : '' ?>>Receitas</option>
                </select>
                <select class="form-input" name="status">
                    <option value="todos" <?= $status === 'todos' ? 'selected' : '' ?>>Status: todos</option>
                    <option value="ativos" <?= $status === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                    <option value="inativos" <?= $status === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                </select>
                <input class="form-input" id="catalog-search-input" name="q" placeholder="Buscar por nome" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                <button class="btn-secondary" type="submit">Filtrar</button>
            </form>
        </div>
    </div>

    <div class="section-card">
        <h2>Catálogo (Insumos + Receitas)</h2>
        <table class="table">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Nome</th>
                    <th>Tipologia</th>
                    <th>Unidade / Rendimento</th>
                    <th>Valores</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody id="catalogo-tbody">
                <?php foreach ($itens as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['tipo'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['nome'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['tipologia'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['extra'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($item['valores'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <span class="status-pill <?= $item['ativo'] ? 'status-ativo' : 'status-inativo' ?>">
                                <?= $item['ativo'] ? 'Ativo' : 'Inativo' ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn-secondary" type="button" onclick="openCatalogModal('<?= $item['page'] === 'logistica_insumos' ? 'insumo' : 'receita' ?>', <?= (int)$item['id'] ?>)">Editar</button>
                            <button class="btn-secondary" type="button" onclick="openCatalogModal('<?= $item['page'] === 'logistica_insumos' ? 'insumo' : 'receita' ?>', null, <?= (int)$item['id'] ?>)">Copiar</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir este item?');">
                                <input type="hidden" name="action" value="<?= $item['page'] === 'logistica_insumos' ? 'delete_insumo' : 'delete_receita' ?>">
                                <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                                <button class="btn-secondary" type="submit">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div id="catalog-empty-state" class="<?= empty($itens) ? '' : 'catalog-hidden' ?>" style="padding:1rem 0;color:#64748b;">Nenhum item encontrado.</div>
    </div>
</div>

<div class="modal-overlay" id="modal-catalogo">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modal-catalogo-title">Cadastro</div>
            <button class="modal-close" type="button" onclick="closeCatalogModal()">X</button>
        </div>
        <div class="modal-body">
            <div class="modal-loader" aria-hidden="true">
                <span class="modal-loader-spinner"></span>
                <span>Carregando cadastro...</span>
            </div>
            <iframe class="modal-iframe" id="modal-catalogo-iframe" src="about:blank"></iframe>
        </div>
    </div>
</div>

<script>
const CATALOGO_ITEMS = <?= json_encode(array_map(static function (array $item): array {
    return [
        'tipo' => (string)($item['tipo'] ?? ''),
        'nome' => (string)($item['nome'] ?? ''),
        'tipologia' => (string)($item['tipologia'] ?? ''),
        'extra' => (string)($item['extra'] ?? ''),
        'search_extra' => (string)($item['search_extra'] ?? ''),
        'valores' => (string)($item['valores'] ?? ''),
        'ativo' => !empty($item['ativo']),
        'id' => (int)($item['id'] ?? 0),
        'page' => (string)($item['page'] ?? '')
    ];
}, $itens), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[char] || char));
}

function normalizeCatalogText(value) {
    return String(value ?? '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function levenshteinDistance(a, b) {
    if (a === b) return 0;
    if (!a.length) return b.length;
    if (!b.length) return a.length;
    const matrix = Array.from({ length: b.length + 1 }, () => []);
    for (let i = 0; i <= b.length; i++) matrix[i][0] = i;
    for (let j = 0; j <= a.length; j++) matrix[0][j] = j;
    for (let i = 1; i <= b.length; i++) {
        for (let j = 1; j <= a.length; j++) {
            const cost = b[i - 1] === a[j - 1] ? 0 : 1;
            matrix[i][j] = Math.min(
                matrix[i - 1][j] + 1,
                matrix[i][j - 1] + 1,
                matrix[i - 1][j - 1] + cost
            );
        }
    }
    return matrix[b.length][a.length];
}

function catalogMatchScore(query, candidate) {
    const normalizedQuery = normalizeCatalogText(query);
    const normalizedCandidate = normalizeCatalogText(candidate);
    if (!normalizedQuery || !normalizedCandidate) return null;
    if (normalizedQuery === normalizedCandidate) return 1000;
    if (normalizedCandidate.includes(normalizedQuery)) {
        return 900 - (normalizedCandidate.length - normalizedQuery.length);
    }

    const queryTokens = normalizedQuery.split(' ').filter(Boolean);
    const candidateTokens = normalizedCandidate.split(' ').filter(Boolean);
    let tokenHits = 0;

    for (const token of queryTokens) {
        for (const candidateToken of candidateTokens) {
            if (candidateToken === token || candidateToken.includes(token) || token.includes(candidateToken)) {
                tokenHits++;
                break;
            }
            const distance = levenshteinDistance(token, candidateToken);
            const maxLen = Math.max(token.length, candidateToken.length);
            if (distance <= 1 || (maxLen >= 6 && distance <= 2)) {
                tokenHits++;
                break;
            }
        }
    }

    const distance = levenshteinDistance(normalizedQuery, normalizedCandidate);
    const maxLen = Math.max(normalizedQuery.length, normalizedCandidate.length);
    const distanceRatio = maxLen > 0 ? 1 - (distance / maxLen) : 0;

    let sameChars = 0;
    for (const char of normalizedQuery) {
        if (normalizedCandidate.includes(char)) sameChars++;
    }
    const similarityPercent = normalizedQuery.length > 0 ? (sameChars / normalizedQuery.length) * 100 : 0;

    if (tokenHits === 0 && similarityPercent < 55 && distanceRatio < 0.45) {
        return null;
    }

    return (tokenHits * 100) + similarityPercent + (distanceRatio * 100);
}

function buildCatalogRow(item) {
    const modalType = item.page === 'logistica_insumos' ? 'insumo' : 'receita';
    return `
        <tr>
            <td>${escapeHtml(item.tipo)}</td>
            <td>${escapeHtml(item.nome)}</td>
            <td>${escapeHtml(item.tipologia)}</td>
            <td>${escapeHtml(item.extra)}</td>
            <td>${escapeHtml(item.valores)}</td>
            <td>
                <span class="status-pill ${item.ativo ? 'status-ativo' : 'status-inativo'}">
                    ${item.ativo ? 'Ativo' : 'Inativo'}
                </span>
            </td>
            <td>
                <button class="btn-secondary" type="button" onclick="openCatalogModal('${modalType}', ${item.id})">Editar</button>
                <button class="btn-secondary" type="button" onclick="openCatalogModal('${modalType}', null, ${item.id})">Copiar</button>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir este item?');">
                    <input type="hidden" name="action" value="${item.page === 'logistica_insumos' ? 'delete_insumo' : 'delete_receita'}">
                    <input type="hidden" name="id" value="${item.id}">
                    <button class="btn-secondary" type="submit">Excluir</button>
                </form>
            </td>
        </tr>
    `;
}

function filterCatalogItems(query) {
    const normalizedQuery = String(query ?? '').trim();
    if (!normalizedQuery) {
        return [...CATALOGO_ITEMS].sort((a, b) => a.nome.localeCompare(b.nome, 'pt-BR'));
    }

    return CATALOGO_ITEMS
        .map((item) => {
            const candidates = [item.nome, item.tipologia, item.extra, item.search_extra];
            let bestScore = null;
            for (const candidate of candidates) {
                const score = catalogMatchScore(normalizedQuery, candidate);
                if (score !== null && (bestScore === null || score > bestScore)) {
                    bestScore = score;
                }
            }
            return bestScore === null ? null : { ...item, _score: bestScore };
        })
        .filter(Boolean)
        .sort((a, b) => {
            if (b._score !== a._score) return b._score - a._score;
            return a.nome.localeCompare(b.nome, 'pt-BR');
        });
}

function renderCatalogItems(items) {
    const tbody = document.getElementById('catalogo-tbody');
    const emptyState = document.getElementById('catalog-empty-state');
    if (!tbody || !emptyState) return;

    if (!items.length) {
        tbody.innerHTML = '';
        emptyState.classList.remove('catalog-hidden');
        return;
    }

    tbody.innerHTML = items.map(buildCatalogRow).join('');
    emptyState.classList.add('catalog-hidden');
}

function setCatalogModalLoading(isLoading) {
    const body = document.querySelector('#modal-catalogo .modal-body');
    if (body) {
        body.classList.toggle('is-loading', !!isLoading);
    }
}

function openCatalogModal(tipo, id, cloneId) {
    const iframe = document.getElementById('modal-catalogo-iframe');
    const modal = document.getElementById('modal-catalogo');
    const title = document.getElementById('modal-catalogo-title');
    let page = tipo === 'insumo' ? 'logistica_insumos' : 'logistica_receitas';
    let url = `index.php?page=${page}&modal=1`;
    if (id) {
        url += `&edit_id=${id}`;
    }
    if (cloneId) {
        url += `&clone_id=${cloneId}`;
    }
    if (title) {
        const isClone = !!cloneId;
        if (tipo === 'insumo') {
            title.textContent = isClone ? 'Copiar Insumo' : 'Cadastro de Insumo';
        } else {
            title.textContent = isClone ? 'Copiar Receita' : 'Cadastro de Receita';
        }
    }
    if (iframe) {
        setCatalogModalLoading(true);
        iframe.onload = () => setCatalogModalLoading(false);
        iframe.src = url;
    }
    if (modal) {
        modal.style.display = 'flex';
    }
}

function closeCatalogModal() {
    const modal = document.getElementById('modal-catalogo');
    const iframe = document.getElementById('modal-catalogo-iframe');
    if (iframe) {
        iframe.onload = null;
        iframe.src = 'about:blank';
    }
    setCatalogModalLoading(true);
    if (modal) {
        modal.style.display = 'none';
    }
}

document.getElementById('modal-catalogo')?.addEventListener('click', (event) => {
    if (event.target.id === 'modal-catalogo') {
        closeCatalogModal();
    }
});

const catalogSearchInput = document.getElementById('catalog-search-input');
catalogSearchInput?.addEventListener('input', (event) => {
    renderCatalogItems(filterCatalogItems(event.target.value || ''));
});

if (catalogSearchInput) {
    renderCatalogItems(filterCatalogItems(catalogSearchInput.value || ''));
}
</script>

<?php endSidebar(); ?>
