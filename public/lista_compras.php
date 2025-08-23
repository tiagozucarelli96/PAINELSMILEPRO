<?php
declare(strict_types=1);
// public/lista_compras.php - Gerador de Listas de Compras (PostgreSQL/Railway)

// ========= Sessão / Auth robusto =========
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? null) == 443;
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Normaliza variáveis de sessão mais comuns
$uid = $_SESSION['user_id'] ?? $_SESSION['id'] ?? null;
$logadoFlag = $_SESSION['logado'] ?? $_SESSION['logged_in'] ?? $_SESSION['auth'] ?? null;
// Converte flags possíveis em boolean
$estaLogado = filter_var($logadoFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
if ($estaLogado === null) {
    // Trata strings/inteiros típicos ("1","true",1)
    $estaLogado = in_array((string)$logadoFlag, ['1','true','on','yes'], true);
}

if (!$uid || !is_numeric($uid) || !$estaLogado) {
    http_response_code(403);
    echo "Acesso negado. Faça login para continuar.";
    exit;
}
$uid = (int)$uid;

// ========= Conexão =========
require_once __DIR__ . '/conexao.php';
if (!isset($pdo) || !$pdo instanceof PDO) { echo "Falha na conexão com o banco de dados."; exit; }

// ========= Helpers =========
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dow_pt(\DateTime $d): string {
    static $dias = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];
    return $dias[(int)$d->format('w')];
}

$err = '';

// ========= Cargas (formulário) =========
$cats = $pdo->query("SELECT id, nome FROM lc_categorias WHERE ativo IS TRUE ORDER BY ordem, nome")->fetchAll(PDO::FETCH_ASSOC);

$itensByCat = [];
$st = $pdo->query("SELECT id, categoria_id, tipo::text AS tipo, nome, unidade, fornecedor_id, ficha_id 
                   FROM lc_itens 
                   WHERE ativo IS TRUE 
                   ORDER BY nome");
foreach ($st as $r) { $itensByCat[$r['categoria_id']][] = $r; }

$forns = $pdo->query("SELECT id, nome, modo_padrao::text AS modo_padrao FROM fornecedores ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $usuarioId   = $uid;
        $usuarioNome = (string)($_SESSION['user_name'] ?? ($_SESSION['nome'] ?? ''));

        // Eventos
        $evs = $_POST['eventos'] ?? [];
        if (!$evs || !is_array($evs)) { throw new Exception('Informe ao menos um evento.'); }

        // Itens selecionados
        $sel = $_POST['itens'] ?? []; // itens[categoria_id][] = item_id
        $selItemIds = [];
        foreach ($sel as $ids) {
            foreach ((array)$ids as $iid) { $iid = (int)$iid; if ($iid>0) $selItemIds[$iid]=true; }
        }
        if (!$selItemIds) { throw new Exception('Selecione itens em alguma categoria.'); }

        // Overrides por fornecedor
        $ov = $_POST['fornecedor_modo'] ?? []; // [fornecedor_id] => 'consolidado'|'separado'

        // Cabeçalho/resumo
        $espacos = [];
        $eventosRows = [];
        foreach ($evs as $e) {
            $espacos[] = trim((string)($e['espaco'] ?? ''));
            $dt = new DateTime($e['data'] ?? 'now');
            $eventosRows[] = sprintf('%s (%s %s %s)',
                trim((string)($e['evento'] ?? '')),
                dow_pt($dt),
                $dt->format('d/m'),
                trim((string)($e['horario'] ?? ''))
            );
        }
        $espacos = array_values(array_unique(array_filter($espacos)));
        $espacoConsolidado = count($espacos) > 1 ? 'Múltiplos' : ($espacos[0] ?? '');
        $eventosResumo = count($eventosRows) . ' evento(s): ' . implode(' • ', $eventosRows);
        $numEvs = count($evs);

        // Transação
        $pdo->beginTransaction();

        // grupo_id (simples): max+1
        $grupoId = (int)$pdo->query("SELECT COALESCE(MAX(grupo_id),0)+1 FROM lc_listas")->fetchColumn();

        // Cria cabeçalhos
        $insLista = $pdo->prepare("
            INSERT INTO lc_listas (grupo_id, tipo, espaco_consolidado, eventos_resumo, criado_por, criado_por_nome)
            VALUES (:g, :t, :e, :r, :u, :un)
        ");
        foreach (['compras','encomendas'] as $tipo) {
            $insLista->execute([
                ':g'=>$grupoId, ':t'=>$tipo,
                ':e'=>$espacoConsolidado, ':r'=>$eventosResumo,
                ':u'=>$usuarioId, ':un'=>$usuarioNome
            ]);
        }

        // Salva eventos (RETURNING)
        $eventosIdx = [];
        $insEv = $pdo->prepare("
            INSERT INTO lc_listas_eventos (grupo_id, espaco, convidados, horario, evento, data, dia_semana)
            VALUES (:g,:es,:cv,:hr,:ev,:dt,:dw)
            RETURNING id, espaco, to_char(data,'DD/MM') AS d, horario
        ");
        foreach ($evs as $e) {
            $esp = trim((string)($e['espaco'] ?? ''));
            $cv  = (int)($e['convidados'] ?? 0);
            $hr  = trim((string)($e['horario'] ?? ''));
            $evn = trim((string)($e['evento'] ?? ''));
            $dt  = new DateTime($e['data'] ?? 'now');

            $insEv->execute([
                ':g'=>$grupoId, ':es'=>$esp, ':cv'=>$cv, ':hr'=>$hr, ':ev'=>$evn,
                ':dt'=>$dt->format('Y-m-d'), ':dw'=>dow_pt($dt)
            ]);
            $eventosIdx[] = $insEv->fetch(PDO::FETCH_ASSOC);
        }

        // Carrega apenas ITENS selecionados
        $ids = implode(',', array_map('intval', array_keys($selItemIds)));
        $itens = $pdo->query("
            SELECT id, tipo::text AS tipo, nome, unidade, fornecedor_id, ficha_id
            FROM lc_itens
            WHERE ativo IS TRUE AND id IN ($ids)
            ORDER BY nome
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Auxiliares
        $compras = []; // "nome|unidade" => qtd
        $encom   = []; // "fornecedorId|eventoId" => ["nome|un" => qtd]

        $getFichaComp = $pdo->prepare("
            SELECT i.nome AS nome_insumo, c.quantidade, c.unidade
            FROM lc_ficha_componentes c
            JOIN lc_insumos i ON i.id=c.insumo_id
            WHERE c.ficha_id=:f
        ");

        $fornMap = [];
        foreach ($forns as $f) { $fornMap[(int)$f['id']] = $f; }

        // Processa itens
        foreach ($itens as $it) {
            $tipo = $it['tipo'];

            if ($tipo === 'preparo') {
                if (!empty($it['ficha_id'])) {
                    $getFichaComp->execute([':f'=>(int)$it['ficha_id']]);
                    foreach ($getFichaComp as $fc) {
                        $key = $fc['nome_insumo'].'|'.$fc['unidade'];
                        $compras[$key] = ($compras[$key] ?? 0) + (float)$fc['quantidade'] * $numEvs;
                    }
                }
            } elseif ($tipo === 'comprado') {
                $fid  = (int)($it['fornecedor_id'] ?? 0);
                $modo = $ov[$fid] ?? ($fornMap[$fid]['modo_padrao'] ?? 'consolidado'); // 'consolidado'|'separado'

                $nk = $it['nome'].'|'.$it['unidade'];
                if ($modo === 'separado') {
                    foreach ($eventosIdx as $e) {
                        $k = $fid.'|'.(int)$e['id']; // por evento
                        $encom[$k][$nk] = ($encom[$k][$nk] ?? 0) + 1;
                    }
                } else {
                    $k = $fid.'|0'; // consolidado
                    $encom[$k][$nk] = ($encom[$k][$nk] ?? 0) + $numEvs;
                }
            } else { // fixo
                $key = $it['nome'].'|'.$it['unidade'];
                $compras[$key] = ($compras[$key] ?? 0) + 1 * $numEvs;
            }
        }

        // Persiste COMPRAS
        $insC = $pdo->prepare("
            INSERT INTO lc_compras_consolidadas (grupo_id, insumo_id, nome_insumo, unidade, quantidade)
            VALUES (:g, NULL, :n, :u, :q)
        ");
        foreach ($compras as $k=>$qtd) {
            [$nome,$uni] = explode('|',$k,2);
            $insC->execute([':g'=>$grupoId, ':n'=>$nome, ':u'=>$uni, ':q'=>$qtd]);
        }

        // Persiste ENCOMENDAS
        $insE = $pdo->prepare("
            INSERT INTO lc_encomendas_itens
                (grupo_id, fornecedor_id, fornecedor_nome, evento_id, evento_label, item_id, nome_item, unidade, quantidade)
            VALUES (:g,:f,:fn,:ev,:el,:it,:n,:u,:q)
        ");
        $evLabelById = [];
        foreach ($eventosIdx as $e) { $evLabelById[(int)$e['id']] = $e['espaco'].' • '.$e['d'].' '.$e['horario']; }

        foreach ($encom as $k=>$map) {
            [$fid,$evId] = array_map('intval', explode('|',$k,2));
            $fn = $fornMap[$fid]['nome'] ?? 'Sem fornecedor';
            $el = $evId>0 ? ($evLabelById[$evId] ?? null) : null;

            foreach ($map as $nk=>$qtd) {
                [$nome,$uni] = explode('|',$nk,2);
                $insE->execute([
                    ':g'=>$grupoId,
                    ':f'=>$fid ?: null,
                    ':fn'=>$fn,
                    ':ev'=>$evId>0 ? $evId : null,
                    ':el'=>$el,
                    ':it'=>null,
                    ':n'=>$nome,
                    ':u'=>$uni,
                    ':q'=>$qtd
                ]);
            }
        }

        // Overrides escolhidos
        if (!empty($ov)) {
            $insOv = $pdo->prepare("
                INSERT INTO lc_encomendas_overrides (grupo_id, fornecedor_id, modo)
                VALUES (:g,:f,:m)
                ON CONFLICT (grupo_id, fornecedor_id) DO UPDATE SET modo = EXCLUDED.modo
            ");
            foreach ($ov as $fid=>$m) {
                if ($m==='consolidado' || $m==='separado') {
                    $insOv->execute([':g'=>$grupoId, ':f'=>(int)$fid, ':m'=>$m]);
                }
            }
        }

        $pdo->commit();
        header('Location: lc_index.php?msg='.urlencode('Listas geradas com sucesso!'));
exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Gerar Lista de Compras</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="estilo.css">
    <style>
        .form{background:#fff;border:1px solid #dfe7f4;border-radius:12px;padding:16px}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .grid .full{grid-column:1/-1}
        .block{border:1px dashed #dbe6ff;border-radius:10px;padding:12px;margin-top:12px}
        h2{margin:10px 0}
        label.small{display:block;font-size:13px;font-weight:700;margin-bottom:6px}
        .input{width:100%;padding:10px;border:1px solid #cfe0ff;border-radius:8px}
        .category{margin-bottom:10px}
        .items{display:none;margin-top:8px;padding-left:12px}
        .items.active{display:block}
        .btn{background:#004aad;color:#fff;border:none;border-radius:8px;padding:10px 14px;font-weight:700;cursor:pointer}
        .btn.gray{background:#e9efff;color:#004aad}
        .alert{padding:10px;border-radius:8px;margin-bottom:10px}
        .alert.err{background:#ffeded;border:1px solid #ffb3b3;color:#8a0c0c}
        .alert.success{background:#edffed;border:1px solid #b3ffb3;color:#0c8a0c}
    </style>
    <script>
        function toggleCat(id){
            const el = document.getElementById('items-'+id);
            if (el) el.classList.toggle('active');
        }
    </script>
</head>
<body class="panel">
<?php if (is_file(__DIR__.'/sidebar.php')) include __DIR__.'/sidebar.php'; ?>
<div class="main-content">
    <h1>Gerar Lista de Compras</h1>

    <div class="form">
        <?php if ($err): ?><div class="alert err"><?=h($err)?></div><?php endif; ?>
        <?php if (isset($_GET['msg'])): ?><div class="alert success"><?=h($_GET['msg'])?></div><?php endif; ?>

        <form method="post">
            <div class="block">
                <h2>Eventos</h2>
                <div id="ev-wrap">
                    <div class="grid ev-row">
                        <div>
                            <label class="small">Espaço</label>
                            <input class="input" name="eventos[0][espaco]" required>
                        </div>
                        <div>
                            <label class="small">Convidados</label>
                            <input class="input" type="number" name="eventos[0][convidados]" min="0" value="0">
                        </div>
                        <div>
                            <label class="small">Horário</label>
                            <input class="input" name="eventos[0][horario]" placeholder="19:00">
                        </div>
                        <div>
                            <label class="small">Evento</label>
                            <input class="input" name="eventos[0][evento]" placeholder="Aniversário, Casamento...">
                        </div>
                        <div class="full">
                            <label class="small">Data</label>
                            <input class="input" type="date" name="eventos[0][data]" required>
                        </div>
                    </div>
                </div>
                <!-- futuro: botão "+ evento" via JS -->
            </div>

            <div class="block">
                <h2>Categorias e Itens</h2>
                <?php foreach ($cats as $c): ?>
                    <div class="category">
                        <label>
                            <input type="checkbox" onclick="toggleCat(<?= (int)$c['id'] ?>)">
                            <?= h($c['nome']) ?>
                        </label>
                        <div class="items" id="items-<?= (int)$c['id'] ?>">
                            <?php foreach (($itensByCat[$c['id']] ?? []) as $it): ?>
                                <label style="display:block;margin:6px 0">
                                    <input type="checkbox" name="itens[<?= (int)$c['id'] ?>][]" value="<?= (int)$it['id'] ?>">
                                    <?= h($it['nome']) ?>
                                    <small>(
                                        <?= h($it['tipo']) ?>
                                        <?php
                                            if ($it['tipo']==='preparo'  && $it['ficha_id'])      echo ', ficha #'.(int)$it['ficha_id'];
                                            if ($it['tipo']==='comprado' && $it['fornecedor_id']) echo ', forn #'.(int)$it['fornecedor_id'];
                                        ?>
                                    )</small>
                                </label>
                            <?php endforeach; ?>
                            <?php if (empty($itensByCat[$c['id']])): ?>
                                <div><em>Nenhum item nesta categoria.</em></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($forns): ?>
            <div class="block">
                <h2>Encomendas — Modo por Fornecedor</h2>
                <?php foreach ($forns as $f): ?>
                    <label style="display:block;margin:6px 0">
                        <?= h($f['nome']) ?>:
                        <select name="fornecedor_modo[<?= (int)$f['id'] ?>]" class="input" style="max-width:220px; display:inline-block">
                            <option value="">Padrão: <?= h($f['modo_padrao']) ?></option>
                            <option value="consolidado">Consolidado</option>
                            <option value="separado">Separado por evento</option>
                        </select>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="margin-top:12px; display:flex; gap:10px">
                <button class="btn" type="submit">Gerar</button>
                <a class="btn gray" href="dashboard.php">Cancelar</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
