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
require_once __DIR__ . '/magalu_storage_helper.php';
require_once __DIR__ . '/core/ocr_google_vision.php';

$debugLocal = getenv('APP_DEBUG') === '1';
if ($debugLocal) {
    @ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    @ini_set('display_errors', 0);
}

$pdo = $GLOBALS['pdo'];

function cartao_ofx_normalize_uploads(array $files): array {
    $normalized = [];
    if (!isset($files['name']) || !is_array($files['name'])) {
        return $normalized;
    }

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        if (empty($files['tmp_name'][$i])) {
            continue;
        }
        $normalized[] = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i] ?? mime_content_type($files['tmp_name'][$i]),
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i] ?? UPLOAD_ERR_OK,
            'size' => $files['size'][$i] ?? 0,
        ];
    }

    return $normalized;
}

function cartao_ofx_estimate_pages(array $uploads): int {
    $pages = 0;
    foreach ($uploads as $file) {
        $mimeType = $file['type'] ?? '';
        $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        if ($mimeType === 'application/pdf' || $extension === 'pdf') {
            if (extension_loaded('imagick')) {
                try {
                    $imagick = new Imagick();
                    $imagick->pingImage($file['tmp_name']);
                    $pages += max(1, $imagick->getNumberImages());
                    $imagick->clear();
                    $imagick->destroy();
                    continue;
                } catch (Exception $e) {
                    // fallback below
                }
            }
            $pages += 1;
            continue;
        }
        $pages += 1;
    }
    return $pages;
}

function cartao_ofx_parse_competencia(string $competencia): ?array {
    $competencia = trim($competencia);
    if (!preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $competencia, $matches)) {
        return null;
    }
    return [(int)$matches[1], (int)$matches[2]];
}

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

function cartao_ofx_detect_parcela(string $texto): ?array {
    if (!preg_match_all('/\b(\d{1,2})\s*\/\s*(\d{1,2})\b/', $texto, $matches, PREG_OFFSET_CAPTURE)) {
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
        if (preg_match_all('/-?\d{1,3}(?:\.\d{3})*,\d{2}|-?\d+,\d{2}|-?\d+\.\d{2}|\b\d{3,6}\b/', $joinedClean, $matches)) {
            $ultimo = end($matches[0]);
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

$cartoesStmt = $pdo->query('SELECT * FROM cartao_ofx_cartoes WHERE status = TRUE ORDER BY nome_cartao');
$cartoes = $cartoesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'processar') {
        // Nova tentativa de processamento limpa prévia anterior
        unset($_SESSION['cartao_ofx_preview']);
        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $competencia = trim($_POST['competencia'] ?? '');
        $competenciaInfo = cartao_ofx_parse_competencia($competencia);

        if (!$cartaoId) {
            $erros[] = 'Selecione um cartao.';
        }
        if (!$competenciaInfo) {
            $erros[] = 'Competencia invalida. Use MM/AAAA.';
        }
        if (empty($_FILES['faturas'])) {
            $erros[] = 'Envie ao menos um PDF ou imagem.';
        }

        $uploads = cartao_ofx_normalize_uploads($_FILES['faturas'] ?? []);
        if (empty($uploads)) {
            $erros[] = 'Nao foi possivel ler os arquivos enviados.';
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
            $mesReferencia = date('Y-m');
            $limiteMensal = (int)($_ENV['CARTAO_OFX_OCR_MONTHLY_LIMIT'] ?? getenv('CARTAO_OFX_OCR_MONTHLY_LIMIT') ?: 200);
            $usoAtual = cartao_ofx_get_ocr_usage($pdo, $mesReferencia);
            $paginasEstimadas = cartao_ofx_estimate_pages($uploads);

            if ($limiteMensal > 0 && ($usoAtual + $paginasEstimadas) > $limiteMensal) {
                $erros[] = 'Limite mensal de OCR excedido. Limite atual: ' . $limiteMensal . ' paginas.';
            }

            try {
                if (empty($erros)) {
                    $ocrProvider = new GoogleVisionOcrProvider();
                    $ocrResultado = $ocrProvider->extractText($uploads);
                    $paginas = (int)$ocrResultado['pages'];

                    if ($limiteMensal > 0 && ($usoAtual + $paginas) > $limiteMensal) {
                        $erros[] = 'Limite mensal de OCR excedido. Limite atual: ' . $limiteMensal . ' paginas.';
                    } else {
                        cartao_ofx_add_ocr_usage($pdo, $mesReferencia, $paginas);
                    }
                }

                if (empty($erros)) {
                    error_log('[CARTAO_OFX] OCR realizado. Paginas: ' . $paginas . ', arquivos: ' . count($uploads));
                    $rawText = $ocrResultado['text'] ?? '';
                    $rawLen = strlen($rawText);
                    $snippet = $rawLen > 600 ? substr($rawText, 0, 600) . '...' : $rawText;
                    error_log('[CARTAO_OFX] OCR texto len=' . $rawLen . ' snippet="' . str_replace(["\n", "\r"], ['\\n', ''], $snippet) . '"');

                    $parseResult = cartao_ofx_parse_fatura_texto($rawText);
                    $itensBase = $parseResult['itens'] ?? [];
                    $descartados = $parseResult['descartados'] ?? [];
                    error_log('[CARTAO_OFX] Itens base identificados: ' . count($itensBase));

                    if (empty($itensBase)) {
                        $erros[] = 'Nenhum lancamento identificado. Ajuste a qualidade do arquivo.';
                        error_log('[CARTAO_OFX] Nenhum lançamento identificado na fatura.');
                    } else {
                        [$mes, $ano] = $competenciaInfo;
                        $hashesParcelas = [];
                        $transacoes = [];
                        $baseItems = [];

                        foreach ($itensBase as $index => $item) {
                            $totalParcelas = max(1, (int)$item['total_parcelas']);
                            if (!empty($item['parcela_atual']) && $totalParcelas > 1 && (int)$item['parcela_atual'] >= $totalParcelas) {
                                // Última parcela vira 1x
                                $totalParcelas = 1;
                                $item['indicador_parcela'] = null;
                            }
                            $baseHash = cartao_ofx_hash_base(
                                $cartaoId,
                                $item['descricao_normalizada'],
                                $item['valor_total'],
                                $item['indicador_parcela'],
                                $competencia
                            );
                            $parcelas = cartao_ofx_split_parcelas($item['valor_total'], $totalParcelas);
                            $baseDate = cartao_ofx_calc_vencimento((int)$cartaoSelecionado['dia_vencimento'], $mes, $ano, 0);
                            $baseDateStr = $baseDate->format('Ymd');
                            $sign = $item['is_credito'] ? 1 : -1;
                            $noExplodeValor = $item['valor_total'] * $sign;
                            $noExplodeHash = cartao_ofx_hash_parcela($baseHash, 1, $baseDateStr, $noExplodeValor);
                            $hashesParcelas[] = $noExplodeHash;

                            $baseItems[] = [
                                'base_key' => $baseHash,
                                'descricao_original' => $item['descricao_original'],
                                'descricao_normalizada' => $item['descricao_normalizada'],
                                'valor_total' => $item['valor_total'],
                                'indicador_parcela' => $item['indicador_parcela'],
                                'total_parcelas' => $totalParcelas,
                                'data_base' => $baseDateStr,
                                'is_credito' => $item['is_credito'],
                                'no_explode_hash' => $noExplodeHash,
                                'no_explode_valor' => $noExplodeValor,
                            ];

                            for ($p = 1; $p <= $totalParcelas; $p++) {
                                $vencimento = cartao_ofx_calc_vencimento((int)$cartaoSelecionado['dia_vencimento'], $mes, $ano, $p - 1);
                                $vencimentoStr = $vencimento->format('Ymd');
                                $valorParcela = $parcelas[$p - 1] * $sign;
                                $hashParcela = cartao_ofx_hash_parcela($baseHash, $p, $vencimentoStr, $valorParcela);
                                $descricaoFinal = $item['descricao_original'];
                                if ($totalParcelas > 1) {
                                    $descricaoFinal .= ' (Parcela ' . $p . '/' . $totalParcelas . ')';
                                }

                                $hashesParcelas[] = $hashParcela;
                                $transacoes[] = [
                                    'base_hash' => $baseHash,
                                    'descricao' => $descricaoFinal,
                                    'descricao_base' => $item['descricao_original'],
                                    'descricao_normalizada' => $item['descricao_normalizada'],
                                    'valor_total' => $item['valor_total'],
                                    'indicador_parcela' => $item['indicador_parcela'],
                                    'parcela_numero' => $p,
                                    'total_parcelas' => $totalParcelas,
                                    'data_vencimento' => $vencimentoStr,
                                    'valor' => $valorParcela,
                                    'hash_parcela' => $hashParcela,
                                    'is_credito' => $item['is_credito'],
                                ];
                            }
                        }

                        $duplicados = cartao_ofx_existing_parcel_hashes($pdo, $hashesParcelas);
                        $duplicados = array_flip($duplicados);

                        $previewTransacoes = [];
                        foreach ($transacoes as $tx) {
                            $tx['duplicado'] = isset($duplicados[$tx['hash_parcela']]);
                            $previewTransacoes[] = $tx;
                        }

                        foreach ($baseItems as &$baseItem) {
                            $baseItem['no_explode_duplicado'] = isset($duplicados[$baseItem['no_explode_hash']]);
                        }
                        unset($baseItem);

                        $preview = [
                            'cartao' => $cartaoSelecionado,
                            'cartao_id' => $cartaoId,
                            'competencia' => $competencia,
                            'base_items' => $baseItems,
                            'transacoes' => $previewTransacoes,
                            'descartados' => $descartados,
                        ];
                        error_log('[CARTAO_OFX] Previa pronta. Transacoes: ' . count($previewTransacoes));
                        $_SESSION['cartao_ofx_preview'] = $preview;
                    }
                }
            } catch (OcrException $e) {
                $erros[] = $e->getMessage();
                error_log('[CARTAO_OFX] Erro OCR: ' . $e->getMessage());
            } catch (Exception $e) {
                $erros[] = 'Erro ao processar OCR.';
                error_log('[CARTAO_OFX] Erro OCR: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'confirmar') {
        $cartaoId = (int)($_POST['cartao_id'] ?? 0);
        $competencia = trim($_POST['competencia'] ?? '');
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
        $selecionadas = [];
        $hashesSelecionadas = [];

        foreach ($txRows as $row) {
            if (!isset($row['include'])) {
                continue;
            }
            $hashParcela = $row['hash_parcela'] ?? '';
            if ($hashParcela === '') {
                continue;
            }
            $descricao = trim($row['descricao'] ?? '');
            if ($descricao === '') {
                $descricao = 'SEM DESCRICAO';
            }
            $descricaoNormalizada = $row['descricao_normalizada'] ?? '';
            if ($descricaoNormalizada === '') {
                $descricaoNormalizada = cartao_ofx_normalize_descricao($descricao);
            }
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
            ];
            $hashesSelecionadas[] = $hashParcela;
        }

        if (empty($selecionadas)) {
            $erros[] = 'Nenhuma transacao selecionada.';
        }

        if (empty($erros)) {
            $duplicados = cartao_ofx_existing_parcel_hashes($pdo, $hashesSelecionadas);
            $duplicados = array_flip($duplicados);

            $transacoesFinal = [];
            $transacoesJson = [];
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
            } else {
                try {
                    $ofxContent = cartao_ofx_generate_ofx($transacoesFinal);
                    $tmpFile = tempnam(sys_get_temp_dir(), 'ofx_');
                    if ($tmpFile === false) {
                        throw new RuntimeException('Nao foi possivel criar arquivo temporario.');
                    }
                    $ofxPath = $tmpFile . '.ofx';
                    file_put_contents($ofxPath, $ofxContent);

                    $magalu = new MagaluStorageHelper();
                    $pasta = 'administrativo/cartao_ofx/' . date('Y') . '/' . date('m');
                    $upload = $magalu->uploadFileFromPath($ofxPath, $pasta, 'application/x-ofx');

                    if (empty($upload['success'])) {
                        throw new RuntimeException('Falha ao enviar OFX para Magalu.');
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
                                $competencia,
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
                    ');
                    $stmtGeracao->execute([
                        $cartaoId,
                        $competencia,
                        $_SESSION['id'] ?? null,
                        count($transacoesFinal),
                        $upload['url'] ?? null,
                        $upload['key'] ?? null,
                        'gerado',
                        json_encode($transacoesJson),
                    ]);

                    $pdo->commit();

                    $ofxGerado = [
                        'url' => $upload['url'] ?? null,
                        'quantidade' => count($transacoesFinal),
                    ];
                    // Limpar prévia após confirmar
                    unset($_SESSION['cartao_ofx_preview']);
                    $preview = null;
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
        <p>Importe faturas em PDF/imagem e gere OFX com vencimentos ajustados.</p>
    </div>

    <div class="ofx-nav">
        <a href="index.php?page=cartao_ofx_me">Importar Fatura</a>
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

    <?php if ($ofxGerado): ?>
        <div class="ofx-alert success">
            OFX gerado com sucesso! Transacoes: <?php echo (int)$ofxGerado['quantidade']; ?>
            <?php if (!empty($ofxGerado['url'])): ?>
                <div><a href="<?php echo htmlspecialchars($ofxGerado['url']); ?>" target="_blank">Baixar OFX</a></div>
            <?php endif; ?>
        </div>
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
                    <label for="competencia">Competencia (MM/AAAA)</label>
                    <input type="text" name="competencia" id="competencia" placeholder="01/2026" value="<?php echo htmlspecialchars($preview['competencia'] ?? ''); ?>" required>
                </div>
                <div class="ofx-field">
                    <label for="faturas">PDF/Imagem</label>
                    <input type="file" name="faturas[]" id="faturas" accept="application/pdf,image/*" multiple required>
                    <div class="ofx-muted">Pode enviar mais de um arquivo.</div>
                </div>
            </div>
            <div style="margin-top: 1rem;">
                <button class="ofx-button" type="submit">Processar (OCR → Previa)</button>
            </div>
        </form>
    </div>

    <?php if ($preview): ?>
        <div class="ofx-card">
            <h3>Previa de Lancamentos</h3>
            <p class="ofx-muted">Edite a descricao (NAME) e remova linhas antes de gerar.</p>

            <form method="post">
                <input type="hidden" name="action" value="confirmar">
                <input type="hidden" name="cartao_id" value="<?php echo (int)$preview['cartao_id']; ?>">
                <input type="hidden" name="competencia" value="<?php echo htmlspecialchars($preview['competencia']); ?>">

                <?php
                $transacoesPorBase = [];
                foreach ($preview['transacoes'] as $idx => $tx) {
                    $tx['idx'] = $idx;
                    $transacoesPorBase[$tx['base_hash']][] = $tx;
                }
                ?>

                <table class="ofx-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Descricao (ME)</th>
                            <th>Valor</th>
                            <th>Parcelado?</th>
                            <th>Status</th>
                            <th>Acao</th>
                        </tr>
                    </thead>
                    <tbody data-next-index="<?php echo count($preview['transacoes']); ?>">
                        <?php foreach ($preview['base_items'] as $baseItem): ?>
                            <?php
                                $baseHash = $baseItem['base_key'];
                                $children = $transacoesPorBase[$baseHash] ?? [];
                                $primeiraDescricao = $children[0]['descricao'] ?? $baseItem['descricao_original'];
                                $duplicadosFilhos = array_reduce($children, function($carry, $tx) { return $carry + ($tx['duplicado'] ? 1 : 0); }, 0);
                            ?>
                            <tr class="ofx-base-row" data-base-hash="<?php echo htmlspecialchars($baseHash); ?>">
                                <td><?php echo htmlspecialchars(cartao_ofx_format_date_display($baseItem['data_base'])); ?></td>
                                <td>
                                    <button type="button" class="ofx-button" style="background:#e2e8f0;color:#0f172a;padding:0.35rem 0.6rem;margin-right:0.4rem;" data-toggle-children="<?php echo htmlspecialchars($baseHash); ?>">▼</button>
                                    <input style="width:70%;" type="text" class="base-desc" data-base-hash="<?php echo htmlspecialchars($baseHash); ?>" value="<?php echo htmlspecialchars($primeiraDescricao); ?>">
                                </td>
                                <td>R$ <?php echo number_format($baseItem['valor_total'], 2, ',', '.'); ?></td>
                                <td><?php echo $baseItem['total_parcelas'] > 1 ? $baseItem['total_parcelas'] . 'x' : '1x'; ?></td>
                                <td>
                                    <?php if ($duplicadosFilhos > 0): ?>
                                        <span class="ofx-tag duplicado"><?php echo $duplicadosFilhos; ?> duplicado(s)</span>
                                    <?php else: ?>
                                        <span class="ofx-tag novo">Novo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <label class="ofx-inline">
                                        <input type="checkbox" class="toggle-no-explode" data-base-hash="<?php echo htmlspecialchars($baseHash); ?>">
                                        Nao explodir parcelas
                                    </label>
                                </td>
                            </tr>
                            <?php foreach ($children as $tx): ?>
                                <tr class="child-row" data-base-hash="<?php echo htmlspecialchars($baseHash); ?>" style="display:none;">
                                    <td style="padding-left:24px;"><?php echo htmlspecialchars(cartao_ofx_format_date_display($tx['data_vencimento'])); ?></td>
                                    <td>
                                        <input style="width:100%;" type="text" name="tx[<?php echo $tx['idx']; ?>][descricao]" value="<?php echo htmlspecialchars($tx['descricao']); ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][descricao_normalizada]" value="<?php echo htmlspecialchars($tx['descricao_normalizada']); ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][valor_total]" value="<?php echo htmlspecialchars((string)$tx['valor_total']); ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][indicador_parcela]" value="<?php echo htmlspecialchars($tx['indicador_parcela'] ?? ''); ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][base_hash]" value="<?php echo htmlspecialchars($tx['base_hash']); ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][parcela_numero]" value="<?php echo (int)$tx['parcela_numero']; ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][total_parcelas]" value="<?php echo (int)$tx['total_parcelas']; ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][data_vencimento]" value="<?php echo htmlspecialchars($tx['data_vencimento']); ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][valor]" value="<?php echo htmlspecialchars((string)$tx['valor']); ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][hash_parcela]" value="<?php echo htmlspecialchars($tx['hash_parcela']); ?>">
                                        <input type="hidden" name="tx[<?php echo $tx['idx']; ?>][is_credito]" value="<?php echo $tx['is_credito'] ? '1' : ''; ?>">
                                    </td>
                                    <td>R$ <?php echo number_format($tx['valor'], 2, ',', '.'); ?></td>
                                    <td>Parc <?php echo (int)$tx['parcela_numero']; ?>/<?php echo (int)$tx['total_parcelas']; ?></td>
                                    <td>
                                        <span class="ofx-tag status-tag <?php echo $tx['duplicado'] ? 'duplicado' : 'novo'; ?>">
                                            <?php echo $tx['duplicado'] ? 'Duplicado' : 'Novo'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <label class="ofx-inline">
                                            <input type="checkbox" class="tx-include" name="tx[<?php echo $tx['idx']; ?>][include]" <?php echo $tx['duplicado'] ? '' : 'checked'; ?>>
                                            Incluir
                                        </label>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
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
document.querySelectorAll('[data-toggle-children]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var baseHash = this.getAttribute('data-toggle-children');
        var children = document.querySelectorAll('tr.child-row[data-base-hash="' + baseHash + '"]');
        var showing = children.length > 0 && children[0].style.display !== 'none';
        children.forEach(function(row) {
            row.style.display = showing ? 'none' : '';
        });
        this.textContent = showing ? '▼' : '▲';
    });
});

document.querySelectorAll('.base-desc').forEach(function(input) {
    input.addEventListener('input', function() {
        var baseHash = this.getAttribute('data-base-hash');
        var children = document.querySelectorAll('tr.child-row[data-base-hash="' + baseHash + '"] input[type="text"]');
        children.forEach(function(childInput) {
            if (!childInput.dataset.userEdited) {
                childInput.value = input.value;
            }
        });
    });
});

document.querySelectorAll('.child-row input[type="text"]').forEach(function(input) {
    input.addEventListener('input', function() {
        this.dataset.userEdited = '1';
    });
});

document.querySelectorAll('.toggle-no-explode').forEach(function(toggle) {
    toggle.addEventListener('change', function() {
        var baseHash = this.getAttribute('data-base-hash');
        var children = document.querySelectorAll('tr.child-row[data-base-hash="' + baseHash + '"]');
        children.forEach(function(row) {
            var checkbox = row.querySelector('input[type="checkbox"][name*="include"]');
            row.style.display = toggle.checked ? 'none' : '';
            if (checkbox) {
                checkbox.checked = !toggle.checked;
                checkbox.dispatchEvent(new Event('change'));
            }
        });
    });
});

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
</script>

<?php
$conteudo = ob_get_clean();
includeSidebar('Administrativo');
echo $conteudo;
endSidebar();
?>
