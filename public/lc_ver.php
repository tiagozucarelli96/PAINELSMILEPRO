<?php
// public/lc_ver.php
// Visualiza uma lista gerada (compras ou encomendas)

if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/core/helpers.php';

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'compras'; // compras|encomendas

if (!$id) {
  echo "Lista inválida.";
  exit;
}

// Cabeçalho
$stmt = $pdo->prepare("
  SELECT l.*, u.nome AS criado_por_nome
  FROM smilee12_painel_smile.lc_listas l
  LEFT JOIN smilee12_painel_smile.usuarios u ON u.id = l.criado_por
  WHERE l.id = :id
");
$stmt->execute([':id' => $id]);
$lista = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$lista) {
  echo "Lista não encontrada.";
  exit;
}

// Eventos vinculados (desabilitado - nenhuma lista criada)
// $stmtEv = $pdo->prepare("SELECT * FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id ORDER BY id");
// $stmtEv->execute([':id' => $id]);
// $eventos = $stmtEv->fetchAll(PDO::FETCH_ASSOC);
$eventos = []; // Array vazio para evitar erros

// Itens (dependendo do tipo)
if ($tipo === 'encomendas') {
  $stmtItens = $pdo->prepare("
    SELECT e.*, f.nome AS fornecedor_nome
    FROM smilee12_painel_smile.lc_encomendas_itens e
    LEFT JOIN smilee12_painel_smile.fornecedores f ON f.id = e.fornecedor_id
    WHERE e.lista_id = :id
    ORDER BY f.nome NULLS LAST, e.evento_id, e.item_nome
  ");
} else {
  $stmtItens = $pdo->prepare("
    SELECT * FROM smilee12_painel_smile.lc_compras_consolidadas
    WHERE lista_id = :id
    ORDER BY insumo_nome
  ");
}
$stmtItens->execute([':id' => $id]);
$itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);



// Calcular totais
$totalGeral = 0;
$totCompras = 0;
$totEncom = 0;

if ($tipo === 'compras') {
  // compras
  $stTmp = $pdo->prepare("SELECT COALESCE(SUM(custo),0) FROM smilee12_painel_smile.lc_compras_consolidadas WHERE lista_id = :id");
  $stTmp->execute([':id'=>$id]);
  $totCompras = (float)$stTmp->fetchColumn();

  // encomendas
  $stTmp = $pdo->prepare("SELECT COALESCE(SUM(custo),0) FROM smilee12_painel_smile.lc_encomendas_itens WHERE lista_id = :id");
  $stTmp->execute([':id'=>$id]);
  $totEncom = (float)$stTmp->fetchColumn();

  // total convidados (desabilitado - nenhuma lista criada)
  // $stTmp = $pdo->prepare("SELECT COALESCE(SUM(convidados),0) FROM smilee12_painel_smile.lc_listas_eventos WHERE lista_id = :id");
  // $stTmp->execute([':id'=>$id]);
  // $totConvidados = (int)$stTmp->fetchColumn();
  $totConvidados = 0; // Valor padrão para evitar erros
}

$totalGeral = $totCompras + $totEncom;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de <?= ucfirst($tipo) ?> - #<?= $id ?></title>
    <link rel="stylesheet" href="estilo.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 20px; }
        .info { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .info h3 { margin-top: 0; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
        .info-item { display: flex; justify-content: space-between; }
        .info-label { font-weight: bold; }
        .info-value { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .total { font-size: 18px; font-weight: bold; color: #2c5aa0; text-align: right; margin-top: 20px; }
        .no-data { text-align: center; color: #666; font-style: italic; padding: 20px; }
        .btn { display: inline-block; padding: 8px 16px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; margin: 5px; }
        .btn:hover { background: #0056b3; }
        .btn-secondary { background: #6c757d; }
        .btn-secondary:hover { background: #545b62; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Lista de <?= ucfirst($tipo) ?> - #<?= $id ?></h1>
        <p>Gerada em: <?= date('d/m/Y H:i', strtotime($lista['data_gerada'])) ?></p>
    </div>

    <div class="info">
        <h3>Informações da Lista</h3>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">ID:</span>
                <span class="info-value">#<?= $id ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Tipo:</span>
                <span class="info-value"><?= ucfirst($tipo) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Espaço:</span>
                <span class="info-value"><?= h($lista['espaco_consolidado'] ?: 'Múltiplos') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Criado por:</span>
                <span class="info-value"><?= h($lista['criado_por_nome'] ?: 'Sistema') ?></span>
            </div>
            <?php if ($lista['eventos_resumo']): ?>
            <div class="info-item">
                <span class="info-label">Eventos:</span>
                <span class="info-value"><?= h($lista['eventos_resumo']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($totConvidados > 0): ?>
            <div class="info-item">
                <span class="info-label">Total de Convidados:</span>
                <span class="info-value"><?= number_format($totConvidados) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($itens)): ?>
        <div class="no-data">
            <p>Nenhum item encontrado nesta lista.</p>
        </div>
    <?php else: ?>
        <h3>Itens da Lista</h3>
        <table>
            <thead>
                <tr>
                    <?php if ($tipo === 'encomendas'): ?>
                        <th>Fornecedor</th>
                        <th>Item</th>
                        <th>Quantidade</th>
                        <th>Unidade</th>
                        <th>Preço Unit.</th>
                        <th>Total</th>
                    <?php else: ?>
                        <th>Insumo</th>
                        <th>Quantidade</th>
                        <th>Unidade</th>
                        <th>Preço Unit.</th>
                        <th>Total</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($itens as $item): ?>
                    <tr>
                        <?php if ($tipo === 'encomendas'): ?>
                            <td><?= h($item['fornecedor_nome'] ?: 'N/A') ?></td>
                            <td><?= h($item['item_nome']) ?></td>
                            <td><?= number_format($item['quantidade'], 2, ',', '.') ?></td>
                            <td><?= h($item['unidade']) ?></td>
                            <td>R$ <?= number_format($item['preco_unitario'], 2, ',', '.') ?></td>
                            <td>R$ <?= number_format($item['custo'], 2, ',', '.') ?></td>
                        <?php else: ?>
                            <td><?= h($item['insumo_nome']) ?></td>
                            <td><?= number_format($item['quantidade'], 2, ',', '.') ?></td>
                            <td><?= h($item['unidade']) ?></td>
                            <td>R$ <?= number_format($item['preco_unitario'], 2, ',', '.') ?></td>
                            <td>R$ <?= number_format($item['custo'], 2, ',', '.') ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="total">
        <strong>Total Geral: R$ <?= number_format($totalGeral, 2, ',', '.') ?></strong>
    </div>

    <div style="margin-top: 30px; text-align: center;">
        <a href="lc_index.php" class="btn btn-secondary">← Voltar para Listas</a>
        <a href="lc_pdf.php?id=<?= $id ?>&tipo=<?= $tipo ?>" class="btn">📄 Gerar PDF</a>
    </div>
</body>
</html>