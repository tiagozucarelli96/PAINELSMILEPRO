<?php
// public/lc_calc.php
// Funções de cálculo para Lista de Compras / Encomendas (não altera layout)

function lc_fetch_ficha(PDO $pdo, int $ficha_id) {
  $h = $pdo->prepare("SELECT * FROM lc_fichas WHERE id=:id AND ativo=true");
  $h->execute([':id'=>$ficha_id]);
  $ficha = $h->fetch(PDO::FETCH_ASSOC);
  if (!$ficha) return null;

  $c = $pdo->prepare("
    SELECT fc.*, i.nome AS insumo_nome, i.unidade_id AS insumo_unidade_id,
           i.preco, i.fator_correcao, (i.preco * i.fator_correcao) AS custo_corrigido,
           uLinha.simbolo AS linha_simbolo, uLinha.fator_base AS linha_fator,
           uInsumo.simbolo AS insumo_simbolo, uInsumo.fator_base AS insumo_fator
    FROM lc_ficha_componentes fc
    JOIN lc_insumos i       ON i.id = fc.item_id
    JOIN lc_unidades uLinha ON uLinha.id = fc.unidade_id
    JOIN lc_unidades uInsumo ON uInsumo.id = i.unidade_id
    WHERE fc.ficha_id = :id
    ORDER BY fc.id
  ");
  $c->execute([':id'=>$ficha_id]);
  $comp = $c->fetchAll(PDO::FETCH_ASSOC);

  return ['ficha'=>$ficha,'comp'=>$comp];
}

function lc_convert_to_insumo_unit(float $qtd, float $fatorLinha, float $fatorInsumo): float {
  // converte a quantidade da unidade da linha para a unidade base do insumo
  if ($fatorLinha <= 0 || $fatorInsumo <= 0) return 0.0;
  return $qtd * ($fatorLinha / $fatorInsumo);
}

function lc_explode_ficha_para_evento(array $pack, int $convidados): array {
  // pack = ['ficha'=>row, 'comp'=>[]]
  $ficha = $pack['ficha'];
  $comp  = $pack['comp'];

  $rendimento       = max(1.0, (float)$ficha['rendimento']);
  $consumo_pessoa   = isset($ficha['consumo_pessoa']) && $ficha['consumo_pessoa'] !== null
                      ? (float)$ficha['consumo_pessoa'] : 1.0; // default se não informado
  $perdas_adic_pct  = max(0.0, (float)$ficha['perdas_adicionais']); // % adicional além do FC

  // Demanda "de saída" esperada para o evento (ex.: un/pessoa)
  $demanda_saida = $convidados * $consumo_pessoa;

  // Quantas "receitas" preciso para atender a saída?
  $receitas_necessarias = (int)ceil($demanda_saida / $rendimento);

  $compras   = []; // insumos (para COMPRAS internas + FIXOS)
  $encomendas= []; // itens "comprados" → agrupados depois por fornecedor/evento

  foreach ($comp as $row) {
    // 1) Determinar quantidade líquida necessária desta linha
    $qtd_liquida = 0.0;

    if ($row['calc_modo'] === 'pessoa') {
      // ex.: 2 un/pessoa
      $qtd_liquida = (float)$row['qtd'] * $convidados;
    } else {
      // 'receita' → multiplica pela quantidade de receitas necessárias
      $qtd_liquida = (float)$row['qtd'] * $receitas_necessarias;
    }

    // 2) Converter para unidade do insumo (linha → insumo)
    $qtd_na_unidade_do_insumo = lc_convert_to_insumo_unit(
      $qtd_liquida,
      (float)$row['linha_fator'],
      (float)$row['insumo_fator']
    );

    // 3) Aplicar perdas adicionais (%) da FICHA (além do FC que já está no custo)
    $qtd_final_liquida = $qtd_na_unidade_do_insumo * (1.0 + ($perdas_adic_pct / 100.0));

    // 4) Custo do componente
    $preco_base_fc = (float)$row['custo_corrigido']; // (preço × FC) do insumo
    $preco_usado   = ($row['preco_override'] !== null && $row['preco_override'] !== '')
                      ? (float)$row['preco_override'] : $preco_base_fc;
    $custo_total   = $qtd_final_liquida * $preco_usado;

    // 5) Direcionamento: 'comprado' → ENCOMENDAS; 'preparo' e 'fixo' → COMPRAS
    $tipo_saida = $row['tipo_saida'];

    if ($tipo_saida === 'comprado') {
      // Encomendas contam em "quantidade de saída" (peças/caixas) conforme a unidade da LINHA
      // (para fornecedores faz mais sentido manter a unidade operada na linha)
      $key = $row['item_id'].'|'.$row['unidade_id'].'|'.(string)($row['fornecedor_id'] ?? '0');
      if (!isset($encomendas[$key])) {
        $encomendas[$key] = [
          'item_id'        => (int)$row['item_id'],
          'insumo_nome'    => $row['insumo_nome'],
          'unidade_id'     => (int)$row['unidade_id'],
          'unidade_simbolo'=> $row['linha_simbolo'],
          'fornecedor_id'  => $row['fornecedor_id'] ? (int)$row['fornecedor_id'] : null,
          'qtd'            => 0.0,
          'custo'          => 0.0, // ainda somamos custo para custo total do evento
        ];
      }
      $encomendas[$key]['qtd']   += ($row['calc_modo'] === 'pessoa') ? (float)$row['qtd'] * $convidados
                                                                      : (float)$row['qtd'] * $receitas_necessarias;
      $encomendas[$key]['custo'] += $custo_total;

    } else {
      // COMPRAS internas: consolidar na unidade do INSUMO
      $key = $row['item_id'].'|'.$row['insumo_unidade_id'];
      if (!isset($compras[$key])) {
        $compras[$key] = [
          'item_id'         => (int)$row['item_id'],
          'insumo_nome'     => $row['insumo_nome'],
          'unidade_id'      => (int)$row['insumo_unidade_id'],
          'unidade_simbolo' => $row['insumo_simbolo'],
          'qtd'             => 0.0,
          'custo'           => 0.0,
          'tipo_saida'      => $tipo_saida, // 'preparo' ou 'fixo'
        ];
      }
      $compras[$key]['qtd']   += $qtd_final_liquida;
      $compras[$key]['custo'] += $custo_total;
    }
  }

  return [
    'compras'    => array_values($compras),
    'encomendas' => array_values($encomendas),
    'meta'       => [
      'rendimento'         => $rendimento,
      'consumo_pessoa'     => $consumo_pessoa,
      'receitas_necessarias'=> $receitas_necessarias,
      'perdas_adicionais'  => $perdas_adic_pct
    ]
  ];
}
