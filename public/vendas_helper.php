<?php
/**
 * vendas_helper.php
 * Funções auxiliares para o módulo de Vendas
 */

require_once __DIR__ . '/conexao.php';

function vendas_formas_pagamento_normalizar(string $valor): string
{
    $valor = trim($valor);
    if (function_exists('mb_strtolower')) {
        $valor = mb_strtolower($valor, 'UTF-8');
    } else {
        $valor = strtolower($valor);
    }
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
        if (is_string($ascii)) {
            $valor = $ascii;
        }
    }
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $valor) ?? '') ?? '');
}

/**
 * Condições comerciais dos eventos infantis, conforme os catálogos das unidades.
 */
function vendas_formas_pagamento_infantil(string $unidade, string $pacote): array
{
    $unidadeNormalizada = vendas_formas_pagamento_normalizar($unidade);
    $pacoteNormalizado = vendas_formas_pagamento_normalizar($pacote);
    $lisbon = str_contains($unidadeNormalizada, 'lisbon')
        && (str_contains($unidadeNormalizada, 'unidade 1')
            || str_contains($unidadeNormalizada, 'lisbon 1')
            || str_contains($unidadeNormalizada, 'parque dos sinos'));
    $diverkids = str_contains($unidadeNormalizada, 'diverkids')
        || str_contains($unidadeNormalizada, 'diver kids');

    if ($lisbon) {
        return [
            ['codigo' => 'pix_avista', 'rotulo' => 'PIX à vista', 'descricao' => 'PIX à vista — 4% de desconto'],
            ['codigo' => 'pix_boleto', 'rotulo' => 'PIX/Boleto parcelado', 'descricao' => 'PIX/Boleto parcelado — pagamento concluído até o evento'],
            ['codigo' => 'entrada', 'rotulo' => '30% de entrada + saldo', 'descricao' => '30% de entrada + saldo até o evento'],
            ['codigo' => 'cartao', 'rotulo' => 'Cartão de crédito', 'descricao' => 'Cartão de crédito — até 12x sem juros (Master ou Visa)'],
        ];
    }

    if ($diverkids) {
        $opcoes = [
            ['codigo' => 'pix_avista', 'rotulo' => 'PIX à vista', 'descricao' => 'PIX à vista — 4% de desconto'],
            ['codigo' => 'pix_boleto', 'rotulo' => 'PIX/Boleto parcelado', 'descricao' => 'PIX/Boleto parcelado — até 15 dias antes do evento (parcela mínima de R$ 300,00)'],
            ['codigo' => 'entrada', 'rotulo' => '30% de entrada + saldo', 'descricao' => '30% de entrada + saldo até 15 dias antes do evento'],
        ];
        if (str_contains($pacoteNormalizado, 'essencial')) {
            $opcoes[] = ['codigo' => 'cartao', 'rotulo' => 'Cartão de crédito', 'descricao' => 'Cartão de crédito — até 8x sem juros (Master ou Visa)'];
        } elseif (str_contains($pacoteNormalizado, 'diver')) {
            $opcoes[] = ['codigo' => 'cartao', 'rotulo' => 'Cartão de crédito', 'descricao' => 'Cartão de crédito — até 12x sem juros (Master ou Visa)'];
        }
        return $opcoes;
    }

    return [];
}

function vendas_forma_pagamento_infantil_valida(string $forma, string $unidade, string $pacote): bool
{
    return trim($forma) !== '';
}

function vendas_bolo_infantil_catalogo(PDO $pdo): array
{
    $catalogo = [];
    try {
        $stmt = $pdo->query("
            SELECT p.id AS pacote_id, p.nome AS pacote_nome,
                   s.id AS secao_id, s.nome AS secao_nome,
                   ip.item_tipo, ip.item_id, i.nome_oficial AS item_nome
            FROM logistica_pacotes_evento p
            JOIN logistica_cardapio_item_pacotes ip ON ip.pacote_evento_id = p.id
            JOIN logistica_cardapio_item_secoes ise
              ON ise.item_tipo = ip.item_tipo AND ise.item_id = ip.item_id
            JOIN logistica_cardapio_secoes s ON s.id = ise.secao_cardapio_id
            JOIN logistica_insumos i ON ip.item_tipo = 'insumo' AND i.id = ip.item_id
            WHERE p.deleted_at IS NULL
              AND COALESCE(p.oculto, FALSE) = FALSE
              AND lower(COALESCE(p.categoria, '')) = 'pacote'
              AND lower(COALESCE(p.tipo_evento_real, '')) = 'infantil'
              AND s.deleted_at IS NULL AND s.ativo = TRUE AND i.ativo = TRUE
              AND (lower(s.nome) LIKE '%massa%bolo%' OR lower(s.nome) LIKE '%recheio%bolo%')
            ORDER BY lower(p.nome), s.ordem, lower(i.nome_oficial), i.id
        ");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $pacoteNome = trim((string)($row['pacote_nome'] ?? ''));
            $pacoteKey = vendas_formas_pagamento_normalizar($pacoteNome);
            $secaoNome = vendas_formas_pagamento_normalizar((string)($row['secao_nome'] ?? ''));
            $grupo = str_contains($secaoNome, 'massa') ? 'massas' : (str_contains($secaoNome, 'recheio') ? 'recheios' : '');
            $itemId = (int)($row['item_id'] ?? 0);
            $itemNome = trim((string)($row['item_nome'] ?? ''));
            if ($pacoteKey === '' || $grupo === '' || $itemId <= 0 || $itemNome === '') {
                continue;
            }
            if (!isset($catalogo[$pacoteKey])) {
                $catalogo[$pacoteKey] = ['pacote_id' => (int)$row['pacote_id'], 'pacote_nome' => $pacoteNome, 'massas' => [], 'recheios' => []];
            }
            $nomeKey = vendas_formas_pagamento_normalizar($itemNome);
            if (isset($catalogo[$pacoteKey][$grupo][$nomeKey])) {
                continue;
            }
            $catalogo[$pacoteKey][$grupo][$nomeKey] = [
                'key' => 'insumo:' . $itemId,
                'item_tipo' => 'insumo',
                'item_id' => $itemId,
                'nome' => $itemNome,
                'secao_id' => (int)$row['secao_id'],
            ];
        }
    } catch (Throwable $e) {
        error_log('vendas_bolo_infantil_catalogo: ' . $e->getMessage());
        return [];
    }

    foreach ($catalogo as &$pacote) {
        $pacote['massas'] = array_values($pacote['massas']);
        $pacote['recheios'] = array_values($pacote['recheios']);
    }
    unset($pacote);
    return $catalogo;
}

function vendas_bolo_infantil_validar(PDO $pdo, string $pacoteNome, string $massaKey, string $recheioKey): array
{
    $pacoteKey = vendas_formas_pagamento_normalizar($pacoteNome);
    $pacote = vendas_bolo_infantil_catalogo($pdo)[$pacoteKey] ?? null;
    if (!$pacote) {
        return ['ok' => false, 'error' => 'O pacote escolhido não possui cardápio de bolo configurado.'];
    }
    $massa = null;
    $recheio = null;
    foreach ($pacote['massas'] as $item) {
        if (hash_equals((string)$item['key'], trim($massaKey))) $massa = $item;
    }
    foreach ($pacote['recheios'] as $item) {
        if (hash_equals((string)$item['key'], trim($recheioKey))) $recheio = $item;
    }
    if (!$massa || !$recheio) {
        return ['ok' => false, 'error' => 'Selecione a massa e o recheio do bolo.'];
    }

    $massaNome = vendas_formas_pagamento_normalizar((string)$massa['nome']);
    $recheioNome = vendas_formas_pagamento_normalizar((string)$recheio['nome']);
    $permitidosBranco = ['creme de leite condensado', 'doce de leite', 'doce de leite com coco', 'beijinho', 'ninho', 'abacaxi com coco'];
    $permitidosChocolate = ['brigadeiro', 'ninho', 'mousse de chocolate', 'sensacao', 'prestigio'];
    $permitidos = str_contains($massaNome, 'chocolate') ? $permitidosChocolate : $permitidosBranco;
    if (!in_array($recheioNome, $permitidos, true)) {
        return ['ok' => false, 'error' => 'O recheio escolhido não está disponível para essa massa.'];
    }

    return ['ok' => true, 'pacote_id' => (int)$pacote['pacote_id'], 'massa' => $massa, 'recheio' => $recheio];
}

function vendas_bolo_item_nome(PDO $pdo, int $itemId): string
{
    if ($itemId <= 0) return '';
    try {
        $stmt = $pdo->prepare("SELECT nome_oficial FROM logistica_insumos WHERE id = :id AND ativo = TRUE LIMIT 1");
        $stmt->execute([':id' => $itemId]);
        return trim((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Verifica se o usuário pode acessar Vendas > Administração.
 * Mantém compatibilidade com o perfil administrativo já existente.
 */
function vendas_can_access_administracao(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!empty($_SESSION['perm_superadmin'])) {
        return true;
    }

    if (!empty($_SESSION['perm_vendas_administracao'])) {
        return true;
    }

    if (!empty($_SESSION['perm_administrativo'])) {
        return true;
    }
    
    $usuario_id = (int)($_SESSION['id'] ?? 0);
    $login = $_SESSION['login'] ?? $_SESSION['usuario'] ?? '';
    
    if ($usuario_id === 1 || strtolower($login) === 'admin') {
        return true;
    }
    
    // Verificar flag is_admin na sessão (se existir)
    if (!empty($_SESSION['is_admin'])) {
        return true;
    }
    
    return false;
}

/**
 * Compatibilidade com o nome antigo usado nas telas de vendas.
 */
function vendas_is_admin(): bool {
    return vendas_can_access_administracao();
}

/**
 * Busca locais mapeados via logistica_me_locais
 * Retorna apenas locais com status MAPEADO e com me_local_id válido
 */
function vendas_buscar_locais_mapeados(): array {
    $pdo = $GLOBALS['pdo'];
    
    try {
        $stmt = $pdo->query("
            SELECT 
                me_local_id,
                me_local_nome,
                space_visivel,
                unidade_interna_id
            FROM logistica_me_locais
            WHERE status_mapeamento = 'MAPEADO'
            AND me_local_id IS NOT NULL
            AND me_local_id > 0
            ORDER BY me_local_nome
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('[VENDAS] Erro ao buscar locais mapeados: ' . $e->getMessage());
        return [];
    }
}

/**
 * Valida se um local está mapeado e retorna o me_local_id
 * Retorna null se não estiver mapeado
 */
function vendas_validar_local_mapeado(string $unidade_nome): ?int {
    $pdo = $GLOBALS['pdo'];
    
    try {
        // Tentar buscar por nome exato (case-insensitive)
        $stmt = $pdo->prepare("
            SELECT me_local_id
            FROM logistica_me_locais
            WHERE LOWER(me_local_nome) = LOWER(?)
            AND status_mapeamento = 'MAPEADO'
            AND me_local_id IS NOT NULL
            AND me_local_id > 0
            LIMIT 1
        ");
        $stmt->execute([$unidade_nome]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['me_local_id'])) {
            return (int)$result['me_local_id'];
        }
        
        return null;
    } catch (Exception $e) {
        error_log('[VENDAS] Erro ao validar local mapeado: ' . $e->getMessage());
        return null;
    }
}

/**
 * Busca o me_local_id a partir do nome do local/unidade
 * Usado na criação do evento na ME
 */
function vendas_obter_me_local_id(string $unidade_nome): ?int {
    return vendas_validar_local_mapeado($unidade_nome);
}

/**
 * Obtém o ID do tipo de evento na ME para o local (Logística > Conexão).
 * Cada local pode ter um me_tipo_evento_id (ex.: Cristal=15, Lisbon Garden=10, DiverKids=13, Lisbon 1=4).
 * Retorna null se não houver mapeamento.
 */
function vendas_obter_me_tipo_evento_id_por_local(string $unidade_nome): ?int {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return null;
    $nome = trim($unidade_nome);
    if ($nome === '') return null;
    try {
        $stmt = $pdo->prepare("
            SELECT me_tipo_evento_id
            FROM logistica_me_locais
            WHERE status_mapeamento = 'MAPEADO'
            AND me_tipo_evento_id IS NOT NULL
            AND me_tipo_evento_id > 0
            AND (LOWER(me_local_nome) = LOWER(?) OR LOWER(TRIM(COALESCE(space_visivel, ''))) = LOWER(?))
            LIMIT 1
        ");
        $stmt->execute([$nome, $nome]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (Throwable $e) {
        error_log('[VENDAS] Erro ao obter me_tipo_evento_id por local: ' . $e->getMessage());
        return null;
    }
}

/**
 * Obtém o space_visivel do local (Logística > Conexão).
 * Ex.: "Lisbon 1", "DiverKids", "Lisbon Garden", "Cristal"
 */
function vendas_obter_space_visivel(string $unidade_nome): ?string {
    $pdo = $GLOBALS['pdo'];
    try {
        $stmt = $pdo->prepare("
            SELECT space_visivel
            FROM logistica_me_locais
            WHERE LOWER(me_local_nome) = LOWER(?)
            AND status_mapeamento = 'MAPEADO'
            LIMIT 1
        ");
        $stmt->execute([$unidade_nome]);
        $space = $stmt->fetchColumn();
        $space = is_string($space) ? trim($space) : '';
        return $space !== '' ? $space : null;
    } catch (Throwable $e) {
        error_log('[VENDAS] Erro ao obter space_visivel: ' . $e->getMessage());
        return null;
    }
}

/**
 * Distância mínima (em segundos) para conflito de agenda, baseada no space_visivel.
 * Regras:
 * - Lisbon (Lisbon 1): 2h
 * - Diverkids (DiverKids): 1h30
 * - Garden/Cristal (Lisbon Garden / Cristal): 3h
 */
function vendas_distancia_minima_conflito_segundos(string $unidade_nome): int {
    $space = vendas_obter_space_visivel($unidade_nome) ?? $unidade_nome;
    $spaceNorm = mb_strtolower(trim($space));

    if (strpos($spaceNorm, 'diver') !== false) {
        return (int)round(1.5 * 3600);
    }
    if (strpos($spaceNorm, 'garden') !== false || strpos($spaceNorm, 'cristal') !== false) {
        return 3 * 3600;
    }
    // Lisbon 1 (ou fallback)
    return 2 * 3600;
}

/**
 * Validação básica de CPF (dígitos verificadores).
 */
function vendas_validar_cpf(string $cpf): bool {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) return false;
    if (preg_match('/(\d)\1{10}/', $cpf)) return false;

    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += ((int)$cpf[$c]) * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ((int)$cpf[$c] !== $d) return false;
    }
    return true;
}

function vendas_normalizar_cpf(?string $cpf): string {
    return preg_replace('/\D/', '', (string)$cpf);
}

function vendas_memoria_pre_contrato_dias(): int {
    return 30;
}

/**
 * Busca destinatários de notificações de contratos:
 * - superadmins ativos
 * - usuária específica solicitada (Taina / alias "tay"), quando encontrada.
 */
function vendas_buscar_destinatarios_superadmin(PDO $pdo): array {
    try {
        if (!vendas_has_column($pdo, 'usuarios', 'email')) {
            return [];
        }

        $whereDestinatarios = [];
        if (vendas_has_column($pdo, 'usuarios', 'perm_superadmin')) {
            $whereDestinatarios[] = "perm_superadmin = TRUE";
        }

        $filtrosTaina = [];
        if (vendas_has_column($pdo, 'usuarios', 'nome')) {
            $filtrosTaina[] = "LOWER(TRIM(COALESCE(nome, ''))) = LOWER('Taina Aparecida Silva Pereira')";
            $filtrosTaina[] = "LOWER(COALESCE(nome, '')) LIKE '%taina aparecida silva pereira%'";
        }

        foreach (['login', 'loguin', 'usuario', 'username', 'user'] as $colLogin) {
            if (vendas_has_column($pdo, 'usuarios', $colLogin)) {
                $filtrosTaina[] = "LOWER(TRIM(COALESCE({$colLogin}, ''))) = 'tay'";
            }
        }

        $filtrosTaina[] = "LOWER(COALESCE(email, '')) LIKE 'tay@%'";
        if (!empty($filtrosTaina)) {
            $whereDestinatarios[] = '(' . implode(' OR ', $filtrosTaina) . ')';
        }

        if (empty($whereDestinatarios)) {
            return [];
        }

        $sql = "
            SELECT id, nome, email
            FROM usuarios
            WHERE email IS NOT NULL
              AND TRIM(email) <> ''
              AND (" . implode(' OR ', $whereDestinatarios) . ")
        ";
        if (vendas_has_column($pdo, 'usuarios', 'ativo')) {
            $sql .= " AND ativo IS DISTINCT FROM FALSE";
        }
        $sql .= " ORDER BY id ASC";

        $stmt = $pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[VENDAS] Erro ao buscar destinatarios superadmin: ' . $e->getMessage());
        return [];
    }
}

function vendas_resolver_base_url_notificacao(): string {
    $base = trim((string)(getenv('APP_URL') ?: getenv('BASE_URL') ?: ''));
    if ($base !== '') {
        return rtrim($base, '/');
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host;
}

function vendas_resolver_url_notificacao(string $urlDestino): string {
    $urlDestino = trim($urlDestino);
    if ($urlDestino === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $urlDestino)) {
        return $urlDestino;
    }

    $base = vendas_resolver_base_url_notificacao();
    if ($base === '') {
        return $urlDestino;
    }

    return $base . '/' . ltrim($urlDestino, '/');
}

function vendas_formatar_tipo_evento_notificacao(string $tipoEvento): string {
    $tipo = trim(mb_strtolower($tipoEvento));
    $map = [
        'casamento' => 'Casamento',
        '15anos' => '15 anos',
        'infantil' => 'Infantil',
        'pj' => 'PJ',
    ];
    return $map[$tipo] ?? ($tipoEvento !== '' ? ucfirst($tipoEvento) : '-');
}

function vendas_formatar_data_notificacao(?string $dataEvento): string {
    $dataEvento = trim((string)$dataEvento);
    if ($dataEvento === '') {
        return '-';
    }
    $ts = strtotime($dataEvento);
    return $ts ? date('d/m/Y', $ts) : $dataEvento;
}

/**
 * Dispara e-mail para superadmins em mudanças relevantes de contratos.
 *
 * Contexto esperado:
 * - evento: enviado_aprovacao | aprovado_me
 * - pre_contrato_id, nome_cliente, tipo_evento, data_evento, unidade, valor_total
 * - url_destino (relativa ou absoluta)
 * - me_client_id / me_event_id (para aprovado_me)
 * - usuario_nome (opcional)
 */
function vendas_notificar_superadmins_contrato(PDO $pdo, array $contexto): bool {
    try {
        $destinatarios = vendas_buscar_destinatarios_superadmin($pdo);
        if (empty($destinatarios)) {
            return false;
        }

        require_once __DIR__ . '/core/notification_dispatcher.php';
        static $dispatcher = null;
        if (!($dispatcher instanceof NotificationDispatcher)) {
            $dispatcher = new NotificationDispatcher($pdo);
        }

        $evento = trim((string)($contexto['evento'] ?? ''));
        $preContratoId = (int)($contexto['pre_contrato_id'] ?? 0);
        $nomeCliente = trim((string)($contexto['nome_cliente'] ?? 'Cliente'));
        $tipoEvento = vendas_formatar_tipo_evento_notificacao((string)($contexto['tipo_evento'] ?? ''));
        $dataEvento = vendas_formatar_data_notificacao((string)($contexto['data_evento'] ?? ''));
        $unidade = trim((string)($contexto['unidade'] ?? '-'));
        $valorTotal = isset($contexto['valor_total']) ? (float)$contexto['valor_total'] : null;
        $valorTotalFmt = $valorTotal !== null ? ('R$ ' . number_format($valorTotal, 2, ',', '.')) : '-';
        $usuarioNome = trim((string)($contexto['usuario_nome'] ?? ''));
        $urlDestino = trim((string)($contexto['url_destino'] ?? 'index.php?page=vendas_administracao'));
        $urlDestinoAbs = vendas_resolver_url_notificacao($urlDestino);

        $titulo = 'Contrato atualizado';
        $assunto = '[Vendas] Contrato atualizado';
        $mensagem = "Pré-contrato #{$preContratoId} foi atualizado no módulo de Vendas.";

        if ($evento === 'enviado_aprovacao') {
            $titulo = 'Contrato enviado para aprovação';
            $assunto = '[Vendas] Novo contrato enviado para aprovação';
            $mensagem = "Pré-contrato #{$preContratoId} enviado para aprovação.";
        } elseif ($evento === 'aprovado_me') {
            $titulo = 'Contrato aprovado e criado na ME';
            $assunto = '[Vendas] Contrato aprovado e criado na ME';
            $meClientId = (int)($contexto['me_client_id'] ?? 0);
            $meEventId = (int)($contexto['me_event_id'] ?? 0);
            $mensagem = "Pré-contrato #{$preContratoId} aprovado e criado na ME (Cliente {$meClientId} / Evento {$meEventId}).";
        }

        $nomeClienteHtml = htmlspecialchars($nomeCliente, ENT_QUOTES, 'UTF-8');
        $tipoEventoHtml = htmlspecialchars($tipoEvento, ENT_QUOTES, 'UTF-8');
        $dataEventoHtml = htmlspecialchars($dataEvento, ENT_QUOTES, 'UTF-8');
        $unidadeHtml = htmlspecialchars($unidade !== '' ? $unidade : '-', ENT_QUOTES, 'UTF-8');
        $valorTotalHtml = htmlspecialchars($valorTotalFmt, ENT_QUOTES, 'UTF-8');
        $tituloHtml = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
        $mensagemHtml = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));
        $urlHtml = htmlspecialchars($urlDestinoAbs !== '' ? $urlDestinoAbs : $urlDestino, ENT_QUOTES, 'UTF-8');
        $usuarioHtml = htmlspecialchars($usuarioNome !== '' ? $usuarioNome : '-', ENT_QUOTES, 'UTF-8');
        $preHtml = htmlspecialchars((string)$preContratoId, ENT_QUOTES, 'UTF-8');

        $extraHtml = '';
        if ($evento === 'aprovado_me') {
            $meClientId = (int)($contexto['me_client_id'] ?? 0);
            $meEventId = (int)($contexto['me_event_id'] ?? 0);
            $extraHtml = "
                <tr><td style='padding:6px 0;color:#64748b;'>Cliente ME</td><td style='padding:6px 0;color:#111827;font-weight:600;'>{$meClientId}</td></tr>
                <tr><td style='padding:6px 0;color:#64748b;'>Evento ME</td><td style='padding:6px 0;color:#111827;font-weight:600;'>{$meEventId}</td></tr>
            ";
        }

        $emailHtml = "
            <div style='font-family:Arial,sans-serif;line-height:1.5;color:#1f2937;max-width:720px;margin:0 auto;'>
                <div style='background:#1e3a8a;color:#fff;padding:14px 18px;border-radius:10px 10px 0 0;'>
                    <h2 style='margin:0;font-size:20px;'>{$tituloHtml}</h2>
                </div>
                <div style='border:1px solid #dbe3ef;border-top:none;padding:16px 18px;border-radius:0 0 10px 10px;background:#fff;'>
                    <p style='margin:0 0 14px 0;color:#334155;'>{$mensagemHtml}</p>
                    <table style='width:100%;border-collapse:collapse;'>
                        <tr><td style='padding:6px 0;color:#64748b;'>Pré-contrato</td><td style='padding:6px 0;color:#111827;font-weight:600;'>#{$preHtml}</td></tr>
                        <tr><td style='padding:6px 0;color:#64748b;'>Cliente</td><td style='padding:6px 0;color:#111827;font-weight:600;'>{$nomeClienteHtml}</td></tr>
                        <tr><td style='padding:6px 0;color:#64748b;'>Tipo</td><td style='padding:6px 0;color:#111827;font-weight:600;'>{$tipoEventoHtml}</td></tr>
                        <tr><td style='padding:6px 0;color:#64748b;'>Data do evento</td><td style='padding:6px 0;color:#111827;font-weight:600;'>{$dataEventoHtml}</td></tr>
                        <tr><td style='padding:6px 0;color:#64748b;'>Unidade</td><td style='padding:6px 0;color:#111827;font-weight:600;'>{$unidadeHtml}</td></tr>
                        <tr><td style='padding:6px 0;color:#64748b;'>Valor total</td><td style='padding:6px 0;color:#111827;font-weight:600;'>{$valorTotalHtml}</td></tr>
                        <tr><td style='padding:6px 0;color:#64748b;'>Atualizado por</td><td style='padding:6px 0;color:#111827;font-weight:600;'>{$usuarioHtml}</td></tr>
                        {$extraHtml}
                    </table>
                    <p style='margin:16px 0 0 0;'>
                        <a href='{$urlHtml}' style='display:inline-block;background:#1e3a8a;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600;'>
                            Abrir no Painel
                        </a>
                    </p>
                </div>
            </div>
        ";

        $result = $dispatcher->dispatch(
            $destinatarios,
            [
                'tipo' => 'vendas_' . ($evento !== '' ? $evento : 'contrato_atualizado'),
                'referencia_id' => $preContratoId > 0 ? $preContratoId : null,
                'titulo' => $titulo,
                'mensagem' => $mensagem,
                'url_destino' => $urlDestinoAbs !== '' ? $urlDestinoAbs : $urlDestino,
                'email_assunto' => $assunto,
                'email_html' => $emailHtml,
            ],
            ['email' => true]
        );

        return ((int)($result['enviados_email'] ?? 0)) > 0;
    } catch (Throwable $e) {
        error_log('[VENDAS] Erro ao notificar superadmins por e-mail: ' . $e->getMessage());
        return false;
    }
}

/**
 * Busca o pré-contrato público mais recente do mesmo cliente/tipo dentro da janela de memória.
 * A consulta é feita por CPF normalizado para evitar duplicatas com máscara diferente.
 */
function vendas_buscar_pre_contrato_publico_recente(string $cpf, string $tipoEvento, ?int $preContratoId = null, ?int $dias = null): ?array {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        return null;
    }

    $cpf = vendas_normalizar_cpf($cpf);
    $tipoEvento = trim($tipoEvento);
    $dias = $dias ?? vendas_memoria_pre_contrato_dias();

    if ($cpf === '' || strlen($cpf) !== 11 || $tipoEvento === '' || $dias < 1) {
        return null;
    }

    try {
        $limite = (new DateTimeImmutable('now'))->modify('-' . $dias . ' days')->format('Y-m-d H:i:s');

        $sql = "
            SELECT
                id,
                tipo_evento,
                status,
                origem,
                nome_completo,
                cpf,
                rg,
                telefone,
                email,
                cep,
                endereco_completo,
                numero,
                complemento,
                bairro,
                cidade,
                estado,
                pais,
                instagram,
                data_evento,
                unidade,
                horario_inicio,
                horario_termino,
                nome_noivos,
                num_convidados,
                como_conheceu,
                como_conheceu_outro,
                pacote_contratado,
                itens_adicionais,
                observacoes,
                criado_em,
                atualizado_em
            FROM vendas_pre_contratos
            WHERE origem = 'publico'
              AND tipo_evento = :tipo_evento
              AND regexp_replace(COALESCE(cpf, ''), '[^0-9]', '', 'g') = :cpf
              AND criado_em >= :limite
        ";

        $params = [
            ':tipo_evento' => $tipoEvento,
            ':cpf' => $cpf,
            ':limite' => $limite,
        ];

        if ($preContratoId !== null && $preContratoId > 0) {
            $sql .= " AND id = :id";
            $params[':id'] = $preContratoId;
        }

        $sql .= " ORDER BY criado_em DESC, id DESC LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($registro) ? $registro : null;
    } catch (Throwable $e) {
        error_log('[VENDAS] Erro ao buscar pré-contrato público recente: ' . $e->getMessage());
        return null;
    }
}

/**
 * Helpers de schema (evitar fatals quando o SQL não foi aplicado).
 */
function vendas_has_table(PDO $pdo, string $table): bool {
    try {
        // Importante: em produção o projeto usa search_path (ex.: smilee12_painel_smile, public).
        // Então a checagem precisa respeitar o search_path, e não só "public".
        $stmt = $pdo->prepare("SELECT to_regclass(:t)");
        $stmt->execute([':t' => $table]);
        $reg = $stmt->fetchColumn();
        return is_string($reg) && trim($reg) !== '';
    } catch (Throwable $e) {
        return false;
    }
}

function vendas_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        // Checar coluna via pg_attribute usando to_regclass (respeita search_path)
        $stmt = $pdo->prepare("
            SELECT 1
            FROM pg_attribute
            WHERE attrelid = to_regclass(:t)
              AND attname = :c
              AND NOT attisdropped
            LIMIT 1
        ");
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function vendas_verify_password_input(string $senhaInput, string $stored): bool {
    if ($senhaInput === '' || $stored === '') return false;

    if (preg_match('/^\$2[ayb]\$|\$argon2/i', $stored) === 1 && password_verify($senhaInput, $stored)) {
        return true;
    }

    if (preg_match('/^[a-f0-9]{32}$/i', $stored) === 1 && strtolower($stored) === md5($senhaInput)) {
        return true;
    }

    return hash_equals($stored, $senhaInput);
}

function vendas_validar_senha_superadmin(PDO $pdo, string $senhaInput): ?array {
    $senhaInput = trim($senhaInput);
    if ($senhaInput === '') {
        return null;
    }

    try {
        $cols = $pdo->query("
            SELECT column_name
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'usuarios'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $has = function(string $c) use ($cols): bool {
            return in_array($c, $cols, true);
        };

        if (!$has('perm_superadmin')) {
            return null;
        }

        $senhaCol = null;
        foreach (['senha', 'senha_hash', 'password', 'pass'] as $col) {
            if ($has($col)) {
                $senhaCol = $col;
                break;
            }
        }
        if ($senhaCol === null) {
            return null;
        }

        $select = ['id', $senhaCol . ' AS senha_verificacao'];
        $select[] = $has('nome') ? 'nome' : "'' AS nome";
        $where = ['perm_superadmin = TRUE'];
        if ($has('ativo')) {
            $where[] = 'COALESCE(ativo, TRUE) = TRUE';
        }

        $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM usuarios WHERE ' . implode(' AND ', $where));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (vendas_verify_password_input($senhaInput, (string)($row['senha_verificacao'] ?? ''))) {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'nome' => (string)($row['nome'] ?? ''),
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('[VENDAS] Erro ao validar senha superadmin: ' . $e->getMessage());
    }

    return null;
}

/**
 * Garante que o schema do módulo Vendas exista (executa SQL 041/042 se necessário).
 * Retorna false se não conseguir garantir.
 */
function vendas_ensure_schema(PDO $pdo, array &$errors, array &$messages): bool {
    $requiredTables = [
        'vendas_pre_contratos',
        'vendas_adicionais',
        'vendas_anexos',
        'vendas_kanban_boards',
        'vendas_kanban_colunas',
        'vendas_kanban_cards',
        'vendas_kanban_historico',
        'vendas_logs',
    ];

    $missing = [];
    foreach ($requiredTables as $t) {
        if (!vendas_has_table($pdo, $t)) $missing[] = $t;
    }

    // Se faltam tabelas, NÃO executar SQL via web.
    // O provisionamento deve ser feito por migration/psql.
    if (!empty($missing)) {
        $sp = '';
        try { $sp = (string)$pdo->query("SHOW search_path")->fetchColumn(); } catch (Throwable $e) {}
        $errors[] = 'Base de Vendas ausente. Tabelas faltando: ' . implode(', ', $missing);
        if ($sp !== '') {
            $errors[] = 'search_path atual: ' . $sp;
        }
        $errors[] = 'Aplique as migrations: sql/041_modulo_vendas.sql e sql/042_vendas_ajustes.sql.';
        return false;
    }

    // Se tabelas existem mas faltam colunas do 042, tentar aplicar 042
    $cols042 = ['origem', 'rg', 'cep', 'endereco_completo', 'nome_noivos', 'num_convidados', 'como_conheceu', 'forma_pagamento', 'itens_adicionais', 'observacoes_internas', 'responsavel_comercial_id', 'tipo_evento_real', 'pacote_evento_id', 'bolo_massa_item_id', 'bolo_recheio_item_id'];
    $needs042 = false;
    foreach ($cols042 as $c) {
        if (!vendas_has_column($pdo, 'vendas_pre_contratos', $c)) {
            $needs042 = true;
            break;
        }
    }

    if ($needs042) {
        $sql042 = __DIR__ . '/../sql/042_vendas_ajustes.sql';
        try {
            if (!is_file($sql042)) {
                $errors[] = 'Base de Vendas desatualizada. Execute os SQLs sql/042_vendas_ajustes.sql, sql/059_vendas_itens_adicionais.sql e sql/060_vendas_organizacao_defaults.sql.';
                return false;
            }
            $errors[] = 'Base de Vendas desatualizada. Execute os SQLs pendentes, incluindo sql/102_vendas_pre_contrato_bolo.sql.';
            return false;
        } catch (Throwable $e) {
            $errors[] = 'Base de Vendas desatualizada. Execute os SQLs pendentes, incluindo sql/102_vendas_pre_contrato_bolo.sql.';
            return false;
        }
    }

    return true;
}
