<?php
// cartao_ofx_me.php — Importacao de faturas e geracao de OFX (ME Eventos)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logado']) || empty($_SESSION['perm_administrativo'])) {
    header('Location: index.php?page=login');
    exit;
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/sidebar_integration.php';
require_once __DIR__ . '/upload_magalu.php';

$debugLocal = getenv('APP_DEBUG') === '1';
if ($debugLocal) {
    @ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    @ini_set('display_errors', 0);
}

$pdo = $GLOBALS['pdo'];

function cartao_ofx_calc_vencimento(int $dia, int $mes, int $ano, int $addMonths = 0): DateTimeImmutable {
    $timezone = new DateTimeZone('America/Sao_Paulo');
    $base = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $ano, $mes), $timezone);
    if (!$base) {
        $base = new DateTimeImmutable('now', $timezone);
    }
    if ($addMonths !== 0) {
        $base = $base->modify(($addMonths > 0 ? '+' : '') . $addMonths . ' month');
    }
    $ultimoDia = (int)$base->format('t');
    $diaFinal = min($dia, $ultimoDia);
    return $base->setDate((int)$base->format('Y'), (int)$base->format('m'), $diaFinal);
}

function cartao_ofx_calc_vencimento_from_date(DateTimeImmutable $baseDate, int $addMonths = 0): DateTimeImmutable {
    $tz = $baseDate->getTimezone();
    $day = (int)$baseDate->format('d');
    if ($addMonths !== 0) {
        $baseDate = $baseDate->modify(($addMonths > 0 ? '+' : '') . $addMonths . ' month');
    }
    $ultimoDia = (int)$baseDate->format('t');
    $diaFinal = min($day, $ultimoDia);
    return $baseDate->setDate((int)$baseDate->format('Y'), (int)$baseDate->format('m'), $diaFinal);
}

function cartao_ofx_parse_data_pagamento(string $data): ?DateTimeImmutable {
    $data = trim($data);
    if (!preg_match('/^(0[1-9]|[12]\d|3[01])\/(0[1-9]|1[0-2])\/(\d{4})$/', $data, $m)) {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('d/m/Y', $data, new DateTimeZone('America/Sao_Paulo'));
    return $dt ?: null;
}

function cartao_ofx_parse_valor(string $valor): ?float {
    $valor = trim($valor);
    $valor = str_replace(['R$', 'r$'], '', $valor);
    $valor = preg_replace('/\s+/', '', $valor);
    if ($valor === '') {
        return null;
    }

    // Formato pt-BR com milhar e vírgula decimal
    if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d{2}$/', $valor)) {
        $val = str_replace('.', '', $valor);
        $val = str_replace(',', '.', $val);
        return (float)$val;
    }

    // Formato pt-BR simples com vírgula decimal
    if (preg_match('/^-?\d+,\d{2}$/', $valor)) {
        $val = str_replace(',', '.', $valor);
        return (float)$val;
    }

    // Decimal com ponto (OCR às vezes troca vírgula por ponto)
    if (preg_match('/^-?\d+\.\d{2}$/', $valor)) {
        return (float)$valor;
    }

    // Somente dígitos (fallback) - interpretar como centavos
    if (preg_match('/^-?\d{3,6}$/', $valor)) {
        $intVal = (int)$valor;
        return $intVal / 100;
    }

    return null;
}

function cartao_ofx_normalize_descricao(string $descricao): string {
    $descricao = trim($descricao);
    if ($descricao === '') {
        return '';
    }
    $descricao = mb_strtoupper($descricao, 'UTF-8');
    $translit = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $descricao);
    if ($translit !== false) {
        $descricao = $translit;
    }
    $descricao = preg_replace('/[^A-Z0-9 ]+/', ' ', $descricao);
    $descricao = preg_replace('/\s+/', ' ', $descricao);
    return trim($descricao);
}

function cartao_ofx_identificar_cobranca(string $descricaoNormalizada): ?string {
    $kinds = [
        'iof' => ['IOF'],
        'juros' => ['JUROS'],
        'anuidade' => ['ANUID'],
        'tarifa' => ['TARIFA','TAXA','ENCARGO','SEGURO'],
    ];
    foreach ($kinds as $kind => $terms) {
        foreach ($terms as $t) {
            if (strpos($descricaoNormalizada, $t) !== false) {
                return $kind;
            }
        }
    }
    return null;
}

function cartao_ofx_filtrar_estornos_cobranca(array $itens): array {
    $chargeMap = [];
    $keep = [];
    foreach ($itens as $item) {
        $kind = cartao_ofx_identificar_cobranca($item['descricao_normalizada']);
        $isEstorno = false;
        if ($kind !== null && preg_match('/ESTORNO|CREDITO|REVERS|CANCEL/i', $item['descricao_original'])) {
            $isEstorno = true;
        }

        if ($kind !== null && !$isEstorno) {
            $key = $kind . '|' . number_format($item['valor_total'], 2, '.', '');
            if (!isset($chargeMap[$key])) {
                $chargeMap[$key] = [];
            }
            $chargeMap[$key][] = $item;
            $keep[] = $item;
            continue;
        }

        if ($isEstorno && $kind !== null) {
            $key = $kind . '|' . number_format($item['valor_total'], 2, '.', '');
            if (!empty($chargeMap[$key])) {
                array_pop($chargeMap[$key]);
                for ($i = count($keep) - 1; $i >= 0; $i--) {
                    $kitem = $keep[$i];
                    $kkind = cartao_ofx_identificar_cobranca($kitem['descricao_normalizada']);
                    if ($kkind === $kind && abs($kitem['valor_total'] - $item['valor_total']) < 0.001) {
                        array_splice($keep, $i, 1);
                        break;
                    }
                }
                continue;
            } else {
                $item['is_credito'] = true;
                $keep[] = $item;
                continue;
            }
        }

        $keep[] = $item;
    }
    return $keep;
}

function cartao_ofx_detect_parcela(string $texto): ?array {
    // Permitir parcelas mesmo coladas a letras (ex.: KA01/02)
    if (!preg_match_all('/(\d{1,2})\s*\/\s*(\d{1,2})/', $texto, $matches, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $count = count($matches[0]);
    if ($count === 0) {
        return null;
    }
    $idx = $count - 1;
    $atual = (int)$matches[1][$idx][0];
    $total = (int)$matches[2][$idx][0];
    if ($total <= 1) {
        return null;
    }
    return [
        'atual' => $atual,
        'total' => $total,
        'indicador' => $matches[0][$idx][0],
    ];
}

function cartao_ofx_parse_fatura_texto(string $texto): array {
    $linhas = preg_split('/\r\n|\r|\n/', $texto) ?: [];
    $itens = [];
    $descartados = [];
    $dateRegex = '/^(\d{1,2}\/\d{1,2})\b/';
    $isNoise = function(string $linha) {
        $up = mb_strtoupper($linha, 'UTF-8');
        $ruidos = ['LANÇAMENTOS','LANCAMENTOS','DATA','ESTABELECIMENTO','VALOR EM','VALOR EM R$','COMPRAS E SAQUES'];
        foreach ($ruidos as $r) { if (strpos($up, $r) !== false) { return true; } }
        return (bool)preg_match('/\bFINAL\s+\d{3,4}\b/i', $linha);
    };
    $extractValor = function(array $partes) {
        $joined = implode(' ', $partes);
        $joinedClean = preg_replace('/[^0-9,.\-]+/', ' ', $joined);
        $valor = null;
        $matches = [];
        // Tenta encontrar o último valor
        if (preg_match_all('/-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2}|-?\d+\.\d{2}|\b\d{3,6}\b/', $joinedClean, $matches)) {
            $ultimo = end($matches[0]);
            $valor = cartao_ofx_parse_valor($ultimo);
        }
        // Se ainda não achou, tenta pegar qualquer número com vírgula/ponto em todo o texto
        if ($valor === null && preg_match_all('/\d+[,\.]\d{2}/', $joined, $m2)) {
            $ultimo = end($m2[0]);
            $valor = cartao_ofx_parse_valor($ultimo);
        }
        return $valor;
    };

    $blocks = [];
    $current = null;

    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '') continue;
        $linha = preg_replace('/\s+/', ' ', $linha);

        if (preg_match($dateRegex, $linha, $m)) {
            // flush bloco anterior
            if ($current !== null) {
                $blocks[] = $current;
            }
            $current = [
                'linhas' => [],
                'valor' => null,
                'descartado' => false,
            ];
            $resto = trim(preg_replace($dateRegex, '', $linha, 1));
            // Se a mesma linha tem outro dd/mm, divide a linha (valor primeiro)
            if (preg_match('/(\d{1,2}\/\d{1,2})/', $resto, $mdate2, PREG_OFFSET_CAPTURE)) {
                $pos = $mdate2[0][1];
                $antes = trim(substr($resto, 0, $pos));
                $depois = trim(substr($resto, $pos));
                if ($antes !== '') {
                    $current['linhas'][] = $antes;
                    $val = $extractValor([$antes]);
                    if ($val !== null) { $current['valor'] = $val; }
                }
                // inicia um novo bloco virtual com a segunda data
                $blocks[] = $current;
                $current = [
                    'linhas' => [],
                    'valor' => null,
                    'descartado' => false,
                ];
                // remove data do começo do depois
                $depois = trim(preg_replace($dateRegex, '', $depois, 1));
                if ($depois !== '') {
                    $current['linhas'][] = $depois;
                    $val = $extractValor([$depois]);
                    if ($val !== null) { $current['valor'] = $val; }
                }
                continue;
            }
            if ($isNoise($linha)) {
                $current['descartado'] = true;
                $current['motivo'] = 'linha de ruído/cabeçalho';
            }
            if ($resto !== '') {
                $current['linhas'][] = $resto;
            }
            $val = $extractValor([$resto]);
            if ($val !== null) { $current['valor'] = $val; }
            continue;
        }

        if ($current === null) {
            $descartados[] = ['linha' => $linha, 'motivo' => 'linha de ruído/cabeçalho'];
            continue;
        }

        if ($isNoise($linha)) {
            $descartados[] = ['linha' => $linha, 'motivo' => 'linha de ruído/cabeçalho'];
            continue;
        }

        // valor ou descrição
        $valHere = $extractValor([$linha]);
        $linhaSemValor = $linha;
        if ($valHere !== null && preg_match('/(-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2}|-?\d+\.\d{2}|\b\d{3,6}\b)/', $linha, $mval)) {
            $linhaSemValor = trim(str_replace($mval[0], '', $linha));
        }
        if ($linhaSemValor !== '') {
            $current['linhas'][] = $linhaSemValor;
        }
        if ($valHere !== null) {
            $current['valor'] = $valHere;
        }
    }
    if ($current !== null) {
        $blocks[] = $current;
    }

    foreach ($blocks as $blk) {
        if ($blk['descartado'] ?? false) {
            $descartados[] = ['linha' => implode(' ', $blk['linhas']), 'motivo' => $blk['motivo'] ?? 'ruído'];
            continue;
        }
        if ($blk['valor'] === null && !empty($blk['linhas'])) {
            $blk['valor'] = $extractValor($blk['linhas']);
        }
        if (empty($blk['linhas'])) {
            $descartados[] = ['linha' => '(vazio)', 'motivo' => 'sem descricao'];
            continue;
        }
        if ($blk['valor'] === null) {
            $descartados[] = ['linha' => implode(' ', $blk['linhas']), 'motivo' => 'sem valor detectado'];
            continue;
        }
        $descricaoParte = trim(implode(' ', $blk['linhas']));
        $parcelaInfo = cartao_ofx_detect_parcela($descricaoParte);
        if ($parcelaInfo) {
            if ($parcelaInfo['total'] > 1 && $parcelaInfo['atual'] >= $parcelaInfo['total']) {
                $parcelaInfo = null; // última parcela vira 1x
            } else {
                $descricaoParte = trim(str_replace($parcelaInfo['indicador'], '', $descricaoParte));
            }
        }
        $descricaoNormalizada = cartao_ofx_normalize_descricao($descricaoParte);
        if ($descricaoNormalizada === '') {
            $descartados[] = ['linha' => $descricaoParte, 'motivo' => 'formato inválido'];
            continue;
        }
        $isCredito = preg_match('/\b(ESTORNO|CREDITO|CR[EÉ]DITO|DEVOLUCAO|REEMBOLSO)\b/i', $descricaoParte) === 1;
        $itens[] = [
            'descricao_original' => $descricaoParte,
            'descricao_normalizada' => $descricaoNormalizada,
            'valor_total' => abs($blk['valor']),
            'indicador_parcela' => $parcelaInfo['indicador'] ?? null,
            'total_parcelas' => $parcelaInfo['total'] ?? 1,
            'parcela_atual' => $parcelaInfo['atual'] ?? null,
            'is_credito' => $isCredito,
            'descartados' => [],
        ];
    }

    return ['itens' => $itens, 'descartados' => $descartados];
}

function cartao_ofx_parse_manual_texto(string $texto): array {
    $linhas = preg_split('/\r\n|\r|\n/', $texto) ?: [];
    $itens = [];
    $descartados = [];

    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $linha));
        $parts = array_values($parts);

        $dataStr = null;
        if (!empty($parts[0]) && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $parts[0])) {
            $dataStr = array_shift($parts);
        }

        if (count($parts) < 2) {
            $descartados[] = ['linha' => $linha, 'motivo' => 'formato inválido'];
            continue;
        }

        $descricao = $parts[0];
        $valorStr = $parts[1] ?? '';
        $parcelaStr = $parts[2] ?? '';

        if ($descricao === '' || $valorStr === '') {
            $descartados[] = ['linha' => $linha, 'motivo' => 'formato inválido'];
            continue;
        }

        $valor = cartao_ofx_parse_valor($valorStr);
        if ($valor === null) {
            $descartados[] = ['linha' => $linha, 'motivo' => 'sem valor detectado'];
            continue;
        }

        $dataYmd = null;
        if ($dataStr) {
            $dt = DateTimeImmutable::createFromFormat('d/m/Y', $dataStr, new DateTimeZone('America/Sao_Paulo'));
            if ($dt instanceof DateTimeImmutable) {
                $dataYmd = $dt->format('Ymd');
            } else {
                $descartados[] = ['linha' => $linha, 'motivo' => 'data inválida'];
                continue;
            }
        }

        $parcelaInfo = null;
        if ($parcelaStr !== '' && preg_match('/(\d{1,2})\s*\/\s*(\d{1,2})/', $parcelaStr, $m)) {
            $atual = (int)$m[1];
            $total = (int)$m[2];
            if ($total > 1) {
                $parcelaInfo = [
                    'atual' => $atual,
                    'total' => $total,
                    'indicador' => $m[0],
                ];
            }
        }

        $descricaoNormalizada = cartao_ofx_normalize_descricao($descricao);
        if ($descricaoNormalizada === '') {
            $descartados[] = ['linha' => $linha, 'motivo' => 'formato inválido'];
            continue;
        }

        $itens[] = [
            'data_linha' => $dataYmd,
            'descricao_original' => $descricao,
            'descricao_normalizada' => $descricaoNormalizada,
            'valor_total' => abs($valor),
            'indicador_parcela' => $parcelaInfo['indicador'] ?? null,
            'total_parcelas' => $parcelaInfo['total'] ?? 1,
            'parcela_atual' => $parcelaInfo['atual'] ?? 1,
            'is_credito' => $valor < 0,
        ];
    }

    return ['itens' => $itens, 'descartados' => $descartados];
}

function cartao_ofx_split_parcelas(float $valorTotal, int $totalParcelas): array {
    if ($totalParcelas <= 1) {
        return [$valorTotal];
    }
    $valorTotalCents = (int) round($valorTotal * 100);
    $baseCents = (int) floor($valorTotalCents / $totalParcelas);
    $resto = $valorTotalCents - ($baseCents * $totalParcelas);
    $parcelas = [];
    for ($i = 0; $i < $totalParcelas; $i++) {
        $cents = $baseCents + ($i < $resto ? 1 : 0);
        $parcelas[] = $cents / 100;
    }
    return $parcelas;
}

function cartao_ofx_hash_base(int $cartaoId, string $descricaoNorm, float $valorTotal, ?string $indicador, string $competencia): string {
    $payload = implode('|', [
        $cartaoId,
        $descricaoNorm,
        number_format($valorTotal, 2, '.', ''),
        $indicador ?? '',
        $competencia,
    ]);
    return hash('sha256', $payload);
}

function cartao_ofx_hash_parcela(string $hashBase, int $parcelaNumero, string $dataVencimento, float $valorParcela): string {
    $payload = implode('|', [
        $hashBase,
        $parcelaNumero,
        $dataVencimento,
        number_format($valorParcela, 2, '.', ''),
    ]);
    return hash('sha256', $payload);
}

function cartao_ofx_existing_parcel_hashes(PDO $pdo, array $hashes): array {
    $hashes = array_values(array_unique(array_filter($hashes)));
    if (empty($hashes)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($hashes), '?'));
    $stmt = $pdo->prepare("SELECT hash_parcela FROM cartao_ofx_parcelas WHERE hash_parcela IN ($placeholders)");
    $stmt->execute($hashes);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function cartao_ofx_get_ocr_usage(PDO $pdo, string $mes): int {
    $stmt = $pdo->prepare('SELECT paginas_processadas FROM cartao_ofx_ocr_usage WHERE mes = ?');
    $stmt->execute([$mes]);
    $valor = $stmt->fetchColumn();
    return $valor ? (int)$valor : 0;
}

function cartao_ofx_add_ocr_usage(PDO $pdo, string $mes, int $paginas): void {
    $stmt = $pdo->prepare('
        INSERT INTO cartao_ofx_ocr_usage (mes, paginas_processadas, atualizado_em)
        VALUES (?, ?, NOW())
        ON CONFLICT (mes)
        DO UPDATE SET paginas_processadas = cartao_ofx_ocr_usage.paginas_processadas + EXCLUDED.paginas_processadas,
                      atualizado_em = NOW()
    ');
    $stmt->execute([$mes, $paginas]);
}

function cartao_ofx_format_date_display(string $ymd): string {
    $dt = DateTimeImmutable::createFromFormat('Ymd', $ymd);
    if ($dt instanceof DateTimeImmutable) {
        return $dt->format('d/m/Y');
    }
    return $ymd;
}

function cartao_ofx_generate_ofx(array $transacoes): string {
    if (empty($transacoes)) {
        throw new RuntimeException('Nenhuma transacao para gerar OFX.');
    }
    $datas = array_column($transacoes, 'data_vencimento');
    sort($datas);
    $dtStart = $datas[0];
    $dtEnd = $datas[count($datas) - 1];
    $dtServer = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('YmdHis');

    $lines = [];
    $lines[] = 'OFXHEADER:100';
    $lines[] = 'DATA:OFXSGML';
    $lines[] = 'VERSION:102';
    $lines[] = 'SECURITY:NONE';
    $lines[] = 'ENCODING:UTF-8';
    $lines[] = 'CHARSET:1252';
    $lines[] = 'COMPRESSION:NONE';
    $lines[] = 'OLDFILEUID:NONE';
    $lines[] = 'NEWFILEUID:NONE';
    $lines[] = '';
    $lines[] = '<OFX>';
    $lines[] = '<SIGNONMSGSRSV1>';
    $lines[] = '<SONRS>';
    $lines[] = '<STATUS>';
    $lines[] = '<CODE>0';
    $lines[] = '<SEVERITY>INFO';
    $lines[] = '</STATUS>';
    $lines[] = '<DTSERVER>' . $dtServer;
    $lines[] = '<LANGUAGE>POR';
    $lines[] = '</SONRS>';
    $lines[] = '</SIGNONMSGSRSV1>';
    $lines[] = '<BANKMSGSRSV1>';
    $lines[] = '<STMTTRNRS>';
    $lines[] = '<TRNUID>1';
    $lines[] = '<STATUS>';
    $lines[] = '<CODE>0';
    $lines[] = '<SEVERITY>INFO';
    $lines[] = '</STATUS>';
    $lines[] = '<STMTRS>';
    $lines[] = '<CURDEF>BRL';
    $lines[] = '<BANKACCTFROM>';
    $lines[] = '<BANKID>000';
    $lines[] = '<ACCTID>CARTAO';
    $lines[] = '<ACCTTYPE>CHECKING';
    $lines[] = '</BANKACCTFROM>';
    $lines[] = '<BANKTRANLIST>';
    $lines[] = '<DTSTART>' . $dtStart;
    $lines[] = '<DTEND>' . $dtEnd;

    foreach ($transacoes as $transacao) {
        $lines[] = '<STMTTRN>';
        $lines[] = '<TRNTYPE>' . $transacao['trntype'];
        $lines[] = '<DTPOSTED>' . $transacao['data_vencimento'];
        $lines[] = '<TRNAMT>' . number_format($transacao['valor'], 2, '.', '');
        $lines[] = '<FITID>' . $transacao['fitid'];
        $lines[] = '<NAME>' . $transacao['nome'];
        $lines[] = '</STMTTRN>';
    }

    $lines[] = '</BANKTRANLIST>';
    $lines[] = '</STMTRS>';
    $lines[] = '</STMTTRNRS>';
    $lines[] = '</BANKMSGSRSV1>';
    $lines[] = '</OFX>';

    return implode("\n", $lines);
}

$mensagens = [];
$erros = [];
$preview = $_SESSION['cartao_ofx_preview'] ?? null;
$ofxGerado = null;
$flashSuccess = $_SESSION['cartao_ofx_flash'] ?? null;
unset($_SESSION['cartao_ofx_flash']);

// Se há parâmetro success mas não há flash message, buscar último arquivo gerado
if (!empty($_GET['success']) && !$flashSuccess && !empty($_SESSION['id'])) {
    $stmt = $pdo->prepare('
        SELECT id, quantidade_transacoes, arquivo_key, arquivo_url
        FROM cartao_ofx_geracoes
        WHERE usuario_id = ? AND status = \'gerado\'
        ORDER BY gerado_em DESC
        LIMIT 1
    ');
    $stmt->execute([$_SESSION['id']]);
    $ultimaGeracao = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($ultimaGeracao) {
        $flashSuccess = [
            'url' => 'index.php?page=cartao_ofx_me_historico&download=' . (int)$ultimaGeracao['id'],
            'download_url' => 'index.php?page=cartao_ofx_me_historico&download=' . (int)$ultimaGeracao['id'],
            'quantidade' => (int)$ultimaGeracao['quantidade_transacoes'],
            'geracao_id' => (int)$ultimaGeracao['id'],
        ];
    }
}

$cartoesStmt = $pdo->query('SELECT * FROM cartao_ofx_cartoes WHERE status = TRUE ORDER BY nome_cartao');
$cartoes = $cartoesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'processar') {
        // Nova tentativa de processamento limpa prévia anterior
        unset($_SESSION['cartao_ofx_preview']);
        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $dataPagamentoStr = trim($_POST['data_pagamento'] ?? '');
        $textoCru = trim($_POST['texto_cru'] ?? '');
        $dataPagamento = cartao_ofx_parse_data_pagamento($dataPagamentoStr);

        if (!$cartaoId) {
            $erros[] = 'Selecione um cartao.';
        }
        if (!$dataPagamento) {
            $erros[] = 'Data de pagamento invalida. Use dd/mm/aaaa.';
        }
        if ($textoCru === '') {
            $erros[] = 'Informe o texto cru com os lancamentos (1 por linha).';
        }

        $cartaoSelecionado = null;
        foreach ($cartoes as $cartao) {
            if ((int)$cartao['id'] === $cartaoId) {
                $cartaoSelecionado = $cartao;
                break;
            }
        }
        if (!$cartaoSelecionado || empty($cartaoSelecionado['status'])) {
            $erros[] = 'Cartao nao encontrado.';
        }

        if (empty($erros)) {
            try {
                $parseResult = cartao_ofx_parse_manual_texto($textoCru);
                $itensBase = cartao_ofx_filtrar_estornos_cobranca($parseResult['itens'] ?? []);
                $descartados = $parseResult['descartados'] ?? [];
                error_log('[CARTAO_OFX] Itens base identificados (manual): ' . count($itensBase));
                if (!empty($descartados)) {
                    error_log('[CARTAO_OFX] Descartados: ' . json_encode($descartados));
                }

                if (empty($itensBase)) {
                    $erros[] = 'Nenhum lancamento identificado. Verifique o formato Descricao | Valor | Parcela(opcional).';
                } else {
                    $hashesParcelas = [];
                    $transacoes = [];
                    $tz = new DateTimeZone('America/Sao_Paulo');

                    foreach ($itensBase as $index => $item) {
                        $dt = null;
                        if (!empty($item['data_linha'])) {
                            $dt = DateTimeImmutable::createFromFormat('Ymd', $item['data_linha'], $tz);
                        }
                        if (!$dt) {
                            $dt = $dataPagamento ?: new DateTimeImmutable('now', $tz);
                        }
                        $dataVencStr = $dt->format('Ymd');
                        $competenciaItem = $dt->format('m/Y');

                        $parcelaNumero = max(1, (int)($item['parcela_atual'] ?? 1));
                        $totalParcelas = max(1, (int)($item['total_parcelas'] ?? 1));

                        $descricaoFinal = $item['descricao_original'];
                        if ($totalParcelas > 1) {
                            $descricaoFinal .= ' (Parcela ' . $parcelaNumero . '/' . $totalParcelas . ')';
                        }

                        $baseHash = cartao_ofx_hash_base(
                            $cartaoId,
                            $item['descricao_normalizada'],
                            $item['valor_total'],
                            $item['indicador_parcela'],
                            $competenciaItem
                        );
                        $valorAssinado = $item['valor_total'] * ($item['is_credito'] ? 1 : -1);
                        $hashParcela = cartao_ofx_hash_parcela($baseHash, $parcelaNumero, $dataVencStr, $valorAssinado);
                        $hashesParcelas[] = $hashParcela;

                        $transacoes[] = [
                            'idx' => $index,
                            'base_hash' => $baseHash,
                            'descricao' => $descricaoFinal,
                            'descricao_normalizada' => $item['descricao_normalizada'],
                            'valor_total' => $item['valor_total'],
                            'indicador_parcela' => $item['indicador_parcela'],
                            'parcela_numero' => $parcelaNumero,
                            'total_parcelas' => $totalParcelas,
                            'data_vencimento' => $dataVencStr,
                            'valor' => $valorAssinado,
                            'hash_parcela' => $hashParcela,
                            'is_credito' => $item['is_credito'],
                            'competencia_base' => $competenciaItem,
                        ];
                    }

                    $duplicados = cartao_ofx_existing_parcel_hashes($pdo, $hashesParcelas);
                    $duplicados = array_flip($duplicados);

                    $previewTransacoes = [];
                    foreach ($transacoes as $tx) {
                        $tx['duplicado'] = isset($duplicados[$tx['hash_parcela']]);
                        $previewTransacoes[] = $tx;
                    }

                    $competenciaLote = $dataPagamento ? $dataPagamento->format('m/Y') : ($previewTransacoes[0]['competencia_base'] ?? '');

                    $preview = [
                        'cartao' => $cartaoSelecionado,
                        'cartao_id' => $cartaoId,
                        'competencia' => $competenciaLote,
                        'transacoes' => $previewTransacoes,
                        'descartados' => $descartados,
                    ];
                    $_SESSION['cartao_ofx_preview'] = $preview;
                }
            } catch (Exception $e) {
                $erros[] = 'Erro ao processar texto.';
                error_log('[CARTAO_OFX] Erro texto: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'limpar_preview') {
        unset($_SESSION['cartao_ofx_preview']);
        $preview = null;
    }

    if ($action === 'confirmar') {
        error_log('[CARTAO_OFX] Confirmar geração iniciado');
        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $cartaoSelecionado = null;
        foreach ($cartoes as $cartao) {
            if ((int)$cartao['id'] === $cartaoId) {
                $cartaoSelecionado = $cartao;
                break;
            }
        }

        if (!$cartaoSelecionado || empty($cartaoSelecionado['status'])) {
            $erros[] = 'Cartao nao encontrado.';
        }

        $txRows = $_POST['tx'] ?? [];
        error_log('[CARTAO_OFX] Confirmar: linhas recebidas=' . count($txRows) . ' cartao=' . $cartaoId);
        $selecionadas = [];
        $hashesSelecionadas = [];

        foreach ($txRows as $row) {
            $isExcluded = isset($row['include']); // agora checkbox significa excluir
            $hashParcela = $row['hash_parcela'] ?? '';
            if ($hashParcela === '') {
                continue;
            }
            if ($isExcluded) {
                continue;
            }
            $descricao = trim($row['descricao'] ?? '');
            if ($descricao === '') {
                $descricao = 'SEM DESCRICAO';
            }
            $descricaoNormalizada = cartao_ofx_normalize_descricao($descricao);
            $selecionadas[] = [
                'hash_parcela' => $hashParcela,
                'base_hash' => $row['base_hash'] ?? '',
                'descricao' => $descricao,
                'descricao_normalizada' => $descricaoNormalizada,
                'valor_total' => (float)($row['valor_total'] ?? 0),
                'indicador_parcela' => $row['indicador_parcela'] ?? null,
                'parcela_numero' => (int)($row['parcela_numero'] ?? 1),
                'total_parcelas' => (int)($row['total_parcelas'] ?? 1),
                'data_vencimento' => $row['data_vencimento'] ?? '',
                'valor' => (float)($row['valor'] ?? 0),
                'is_credito' => !empty($row['is_credito']),
                'competencia_base' => $row['competencia_base'] ?? '',
            ];
            $hashesSelecionadas[] = $hashParcela;
        }

        if (empty($selecionadas)) {
            $erros[] = 'Nenhuma transacao selecionada (todas marcadas para excluir?).';
            error_log('[CARTAO_OFX] Confirmar: nenhuma transacao selecionada');
        }

        if (empty($erros)) {
            error_log('[CARTAO_OFX] Confirmar: selecionadas=' . count($selecionadas));
            $duplicados = cartao_ofx_existing_parcel_hashes($pdo, $hashesSelecionadas);
            $duplicados = array_flip($duplicados);

            $transacoesFinal = [];
            $transacoesJson = [];
            $competenciaGeracao = $selecionadas[0]['competencia_base'] ?? ($_POST['competencia'] ?? '');
            foreach ($selecionadas as $tx) {
                if (isset($duplicados[$tx['hash_parcela']])) {
                    continue;
                }
                $trnType = $tx['valor'] >= 0 ? 'CREDIT' : 'DEBIT';
                $transacoesFinal[] = [
                    'trntype' => $trnType,
                    'data_vencimento' => $tx['data_vencimento'],
                    'valor' => $tx['valor'],
                    'fitid' => $tx['hash_parcela'],
                    'nome' => $tx['descricao'],
                    'hash_parcela' => $tx['hash_parcela'],
                    'base_hash' => $tx['base_hash'],
                    'parcela_numero' => $tx['parcela_numero'],
                    'total_parcelas' => $tx['total_parcelas'],
                ];
                $transacoesJson[] = [
                    'data' => $tx['data_vencimento'],
                    'descricao' => $tx['descricao'],
                    'valor' => $tx['valor'],
                    'hash' => $tx['hash_parcela'],
                ];
            }

            if (empty($transacoesFinal)) {
                $erros[] = 'Todas as transacoes selecionadas ja existiam.';
                error_log('[CARTAO_OFX] Confirmar: todas selecionadas ja existiam');
            } else {
                try {
                    $ofxContent = cartao_ofx_generate_ofx($transacoesFinal);
                    $tmpFile = tempnam(sys_get_temp_dir(), 'ofx_');
                    if ($tmpFile === false) {
                        throw new RuntimeException('Nao foi possivel criar arquivo temporario.');
                    }
                    $ofxPath = $tmpFile . '.ofx';
                    file_put_contents($ofxPath, $ofxContent);

                    $uploader = new MagaluUpload();
                    $pasta = 'administrativo/cartao_ofx';
                    $upload = $uploader->uploadFromPath($ofxPath, $pasta, 'ofx_' . date('Ymd_His') . '.ofx', 'application/x-ofx');
                    error_log('[CARTAO_OFX] Upload Magalu resultado: ' . json_encode($upload));
                    if (empty($upload['success'])) {
                        $detail = $upload['error'] ?? '';
                        throw new RuntimeException('Falha ao enviar OFX para Magalu.' . ($detail ? ' ' . $detail : ''));
                    }

                    $pdo->beginTransaction();
                    $baseIds = [];

                    foreach ($selecionadas as $tx) {
                        if (isset($duplicados[$tx['hash_parcela']])) {
                            continue;
                        }
                        $baseHash = $tx['base_hash'];
                        if (!isset($baseIds[$baseHash])) {
                            $stmtBase = $pdo->prepare('
                                INSERT INTO cartao_ofx_compra_base
                                (cartao_id, competencia_base, descricao_normalizada, valor_total, indicador_parcela, hash_base, criado_em)
                                VALUES (?, ?, ?, ?, ?, ?, NOW())
                                ON CONFLICT (hash_base) DO NOTHING
                            ');
                            $stmtBase->execute([
                                $cartaoId,
                                $tx['competencia_base'],
                                $tx['descricao_normalizada'],
                                $tx['valor_total'],
                                $tx['indicador_parcela'],
                                $baseHash,
                            ]);
                            $stmtFetch = $pdo->prepare('SELECT id FROM cartao_ofx_compra_base WHERE hash_base = ?');
                            $stmtFetch->execute([$baseHash]);
                            $baseIds[$baseHash] = (int)$stmtFetch->fetchColumn();
                        }

                        $stmtParcela = $pdo->prepare('
                            INSERT INTO cartao_ofx_parcelas
                            (compra_base_id, numero_parcela, total_parcelas, data_vencimento, valor_parcela, hash_parcela, criado_em)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                            ON CONFLICT (hash_parcela) DO NOTHING
                        ');
                        $dtParcela = DateTimeImmutable::createFromFormat('Ymd', $tx['data_vencimento']);
                        $dataSql = $dtParcela instanceof DateTimeImmutable ? $dtParcela->format('Y-m-d') : date('Y-m-d');
                        $stmtParcela->execute([
                            $baseIds[$baseHash],
                            $tx['parcela_numero'],
                            $tx['total_parcelas'],
                            $dataSql,
                            $tx['valor'],
                            $tx['hash_parcela'],
                        ]);
                    }

                    $stmtGeracao = $pdo->prepare('
                        INSERT INTO cartao_ofx_geracoes
                        (cartao_id, competencia, gerado_em, usuario_id, quantidade_transacoes, arquivo_url, arquivo_key, status, transacoes_json)
                        VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)
                        RETURNING id
                    ');
                    $stmtGeracao->execute([
                        $cartaoId,
                        $competenciaGeracao,
                        $_SESSION['id'] ?? null,
                        count($transacoesFinal),
                        $upload['url'] ?? null,
                        $upload['chave_storage'] ?? ($upload['key'] ?? null),
                        'gerado',
                        json_encode($transacoesJson),
                    ]);
                    $geracaoRow = $stmtGeracao->fetch(PDO::FETCH_ASSOC);
                    $geracaoId = $geracaoRow ? (int)$geracaoRow['id'] : (int)$pdo->lastInsertId();

                    $pdo->commit();
                    error_log('[CARTAO_OFX] Geracao salva. ID: ' . $geracaoId . ', Tx: ' . count($transacoesFinal) . ', Key: ' . ($upload['chave_storage'] ?? $upload['key'] ?? 'N/A'));

                    $ofxGerado = [
                        'url' => $geracaoId ? 'index.php?page=cartao_ofx_me_historico&download=' . $geracaoId : ($upload['url'] ?? null),
                        'quantidade' => count($transacoesFinal),
                        'geracao_id' => $geracaoId,
                        'download_url' => $geracaoId ? 'index.php?page=cartao_ofx_me_historico&download=' . $geracaoId : ($upload['url'] ?? null),
                    ];
                    $_SESSION['cartao_ofx_flash'] = $ofxGerado;
                    // Limpar prévia após confirmar
                    unset($_SESSION['cartao_ofx_preview']);
                    $preview = null;
                    // Redirect para evitar re-submit e exibir banner corretamente
                    header('Location: index.php?page=cartao_ofx_me&success=1');
                    exit;
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $erros[] = 'Erro ao gerar OFX: ' . $e->getMessage();
                }
            }
        }
    }
}

ob_start();
?>

<style>
.ofx-container {
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    padding: 1.5rem;
}

.ofx-header {
    margin-bottom: 1.5rem;
}

.ofx-header h1 {
    font-size: 1.75rem;
    color: #1e3a8a;
    margin-bottom: 0.25rem;
}

.ofx-header p {
    color: #64748b;
}

.ofx-nav {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}

.ofx-nav a {
    text-decoration: none;
    padding: 0.5rem 1rem;
    border-radius: 8px;
    background: #f1f5f9;
    color: #1e3a8a;
    font-weight: 600;
}

.ofx-card {
    background: #fff;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(15, 23, 42, 0.08);
    margin-bottom: 1.5rem;
}

.ofx-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem;
}

.ofx-field label {
    font-weight: 600;
    color: #0f172a;
    display: block;
    margin-bottom: 0.4rem;
}

.ofx-field input,
.ofx-field select {
    width: 100%;
    padding: 0.55rem 0.75rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    font-size: 0.95rem;
}

.ofx-button {
    background: #2563eb;
    color: #fff;
    border: none;
    padding: 0.6rem 1.2rem;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
}

.ofx-alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.ofx-alert.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}

.ofx-alert.success {
    background: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.ofx-success-banner {
    padding: 1.25rem 1.5rem;
    border-left: 4px solid #16a34a;
    box-shadow: 0 2px 8px rgba(22, 163, 74, 0.15);
}

.ofx-download-btn {
    transition: all 0.2s;
}

.ofx-download-btn:hover {
    background: #1d4ed8 !important;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
}

.ofx-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
    font-size: 0.95rem;
}

.ofx-table th,
.ofx-table td {
    padding: 0.65rem;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
    vertical-align: middle;
}

.ofx-table th {
    background: #f8fafc;
    font-weight: 600;
    color: #0f172a;
}

.ofx-tag {
    padding: 0.2rem 0.5rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}

.ofx-tag.novo {
    background: #dcfce7;
    color: #166534;
}

.ofx-tag.duplicado {
    background: #fee2e2;
    color: #991b1b;
}

.ofx-tag.ignorado {
    background: #e2e8f0;
    color: #475569;
}

.ofx-muted {
    color: #64748b;
    font-size: 0.85rem;
}

.ofx-base-row {
    background: #f1f5f9;
}

.ofx-inline {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.ofx-inline input[type="text"] {
    width: 100%;
}

.child-row {
    background: #f8fafc;
}

.child-row td {
    border-top: 0;
}

.toggle-btn {
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #e2e8f0;
    color: #0f172a;
    font-weight: 700;
}

@media (max-width: 900px) {
    .ofx-table th:nth-child(1),
    .ofx-table td:nth-child(1) {
        display: none;
    }
}
</style>

<div class="ofx-container">
    <div class="ofx-header">
        <h1>Cartao → OFX (ME Eventos)</h1>
        <p>Modo Texto Cru: informe os lançamentos manualmente (1 por linha).</p>
    </div>

    <div class="ofx-nav">
        <a href="index.php?page=cartao_ofx_me">Texto cru</a>
        <a href="index.php?page=cartao_ofx_me_cartoes">Cartoes</a>
        <a href="index.php?page=cartao_ofx_me_historico">Historico</a>
    </div>

<?php foreach ($erros as $erro): ?>
        <div class="ofx-alert error"><?php echo htmlspecialchars($erro); ?></div>
    <?php endforeach; ?>

    <?php if ($preview && empty($erros)): ?>
        <div class="ofx-alert success">
            Prévia gerada: <?php echo count($preview['transacoes'] ?? []); ?> transações.
        </div>
    <?php endif; ?>

    <?php if ($ofxGerado || $flashSuccess || !empty($_GET['success'])): ?>
        <?php $successData = $ofxGerado ?: $flashSuccess; ?>
        <?php if ($successData || !empty($_GET['success'])): ?>
            <div class="ofx-alert success ofx-success-banner">
                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <strong style="font-size: 1.1rem;">✓ Arquivo gerado com sucesso!</strong>
                        <div style="margin-top: 0.5rem; color: #166534;">
                            <?php if ($successData): ?>
                                <?php echo (int)($successData['quantidade'] ?? 0); ?> transação(ões) processada(s).
                            <?php else: ?>
                                Arquivo OFX gerado com sucesso.
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
                        <?php if (!empty($successData['download_url']) || !empty($successData['url'])): ?>
                            <a class="ofx-button ofx-download-btn" href="<?php echo htmlspecialchars($successData['download_url'] ?? $successData['url'] ?? ''); ?>" style="background: #2563eb; text-decoration: none; display: inline-block;">
                                📥 Baixar OFX
                            </a>
                        <?php endif; ?>
                        <a class="ofx-button" href="index.php?page=cartao_ofx_me_historico" style="background: #0f172a; text-decoration: none; display: inline-block;">
                            📋 Ver histórico
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="ofx-card">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="processar">
            <div class="ofx-grid">
                <div class="ofx-field">
                    <label for="cartao_id">Cartao</label>
                    <select name="cartao_id" id="cartao_id" required>
                        <option value="">Selecione</option>
                        <?php foreach ($cartoes as $cartao): ?>
                            <option value="<?php echo (int)$cartao['id']; ?>" <?php echo (!empty($preview['cartao_id']) && (int)$preview['cartao_id'] === (int)$cartao['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cartao['nome_cartao']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ofx-field">
                    <label for="data_pagamento">Data de pagamento (dd/mm/aaaa)</label>
                    <input type="text" name="data_pagamento" id="data_pagamento" placeholder="17/01/2026" value="<?php echo htmlspecialchars($_POST['data_pagamento'] ?? ''); ?>" required>
                </div>
            </div>
            <div class="ofx-field" style="margin-top:1rem;">
                <label for="texto_cru">Lançamentos (1 por linha) — Formato: DATA | DESCRICAO | VALOR | PARCELA(opcional)</label>
                <textarea id="texto_cru" name="texto_cru" rows="8" placeholder="17/01/2026 | APPLE.COM/BILL | 264,90 | 1/4&#10;17/01/2026 | UBER VIAGEM | 28,95 |"><?php echo htmlspecialchars($_POST['texto_cru'] ?? ''); ?></textarea>
            </div>
            <div style="margin-top: 1rem;">
                <button class="ofx-button" type="submit">Processar (Texto → Previa)</button>
            </div>
        </form>
    </div>

    <?php if ($preview): ?>
        <div style="margin-bottom:0.5rem;">
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="limpar_preview">
                <button class="ofx-button" type="submit" style="background:#475569;">Limpar prévia</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($preview): ?>
        <div class="ofx-card">
            <h3>Previa de Lancamentos</h3>
            <p class="ofx-muted">Edite a descricao (NAME) e remova linhas antes de gerar.</p>

            <form method="post">
                <input type="hidden" name="action" value="confirmar">
                <input type="hidden" name="cartao_id" value="<?php echo (int)$preview['cartao_id']; ?>">
                <input type="hidden" name="competencia" value="<?php echo htmlspecialchars($preview['competencia']); ?>">

                <table class="ofx-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Descricao (ME)</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Acao</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview['transacoes'] as $idx => $tx): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(cartao_ofx_format_date_display($tx['data_vencimento'])); ?></td>
                                <td>
                                    <input style="width:100%;" type="text" name="tx[<?php echo $idx; ?>][descricao]" value="<?php echo htmlspecialchars($tx['descricao']); ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][descricao_normalizada]" value="<?php echo htmlspecialchars($tx['descricao_normalizada']); ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][valor_total]" value="<?php echo htmlspecialchars((string)$tx['valor_total']); ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][indicador_parcela]" value="<?php echo htmlspecialchars($tx['indicador_parcela'] ?? ''); ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][base_hash]" value="<?php echo htmlspecialchars($tx['base_hash']); ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][parcela_numero]" value="<?php echo (int)$tx['parcela_numero']; ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][total_parcelas]" value="<?php echo (int)$tx['total_parcelas']; ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][data_vencimento]" value="<?php echo htmlspecialchars($tx['data_vencimento']); ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][valor]" value="<?php echo htmlspecialchars((string)$tx['valor']); ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][hash_parcela]" value="<?php echo htmlspecialchars($tx['hash_parcela']); ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][is_credito]" value="<?php echo $tx['is_credito'] ? '1' : ''; ?>">
                                    <input type="hidden" name="tx[<?php echo $idx; ?>][competencia_base]" value="<?php echo htmlspecialchars($tx['competencia_base'] ?? ''); ?>">
                                </td>
                                <td>R$ <?php echo number_format($tx['valor'], 2, ',', '.'); ?></td>
                                <td>
                                    <span class="ofx-tag status-tag <?php echo $tx['duplicado'] ? 'duplicado' : 'novo'; ?>">
                                        <?php echo $tx['duplicado'] ? 'Duplicado' : 'Novo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <label class="ofx-inline">
                                        <input type="checkbox" class="tx-include" name="tx[<?php echo $idx; ?>][include]">
                                        Nao incluir
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 1rem;">
                    <button class="ofx-button" type="submit">Confirmar Geracao</button>
                </div>
            </form>
        </div>

        <?php if (!empty($preview['descartados'])): ?>
            <div class="ofx-card">
                <h4>Itens descartados</h4>
                <p class="ofx-muted">Linhas ignoradas pelo parser e motivo.</p>
                <?php
                $maxDescartados = 30;
                $mostrar = array_slice($preview['descartados'], 0, $maxDescartados);
                ?>
                <table class="ofx-table">
                    <thead>
                        <tr>
                            <th>Motivo</th>
                            <th>Linha</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mostrar as $desc): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($desc['motivo'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($desc['linha'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($preview['descartados']) > $maxDescartados): ?>
                    <div class="ofx-muted" style="margin-top:8px;">... e mais <?php echo count($preview['descartados']) - $maxDescartados; ?> linhas. (Considere ajustar o OCR ou a fatura.)</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.tx-include').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var row = this.closest('tr');
            if (!row) return;
            var tag = row.querySelector('.status-tag');
            if (!tag) return;
            if (this.checked) {
                if (tag.classList.contains('duplicado')) {
                    tag.textContent = 'Duplicado';
                } else {
                    tag.textContent = 'Novo';
                }
                tag.classList.remove('ignorado');
            } else {
                tag.textContent = 'Ignorado';
                tag.classList.add('ignorado');
            }
        });
    });
});
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Administrativo');
echo $conteudo;
endSidebar();
