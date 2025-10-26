<?php
// helpers.php — Funções auxiliares globais

/**
 * Função global para escapar HTML
 * @param string $string
 * @return string
 */
if (!function_exists('h')) {
    function h($string) {
        return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Função global para escapar JavaScript
 * @param string $string
 * @return string
 */
if (!function_exists('js')) {
    function js($string) {
        return json_encode((string)$string, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }
}

/**
 * Função global para formatar moeda
 * @param float $value
 * @return string
 */
if (!function_exists('format_currency')) {
    function format_currency($value) {
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    }
}

/**
 * Função global para formatar data
 * @param string $date
 * @param string $format
 * @return string
 */
if (!function_exists('format_date')) {
    function format_date($date, $format = 'd/m/Y H:i') {
        if (empty($date)) return '';
        return date($format, strtotime($date));
    }
}

/**
 * Função global para badges de status
 * @param string $status
 * @return string
 */
if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status) {
        $badges = [
            'rascunho' => '<span class="badge badge-warning">Rascunho</span>',
            'publicado' => '<span class="badge badge-success">Publicado</span>',
            'encerrado' => '<span class="badge badge-secondary">Encerrado</span>',
            'confirmado' => '<span class="badge badge-success">Confirmado</span>',
            'lista_espera' => '<span class="badge badge-warning">Lista de Espera</span>',
            'cancelado' => '<span class="badge badge-danger">Cancelado</span>',
            'ativo' => '<span class="badge badge-success">Ativo</span>',
            'inativo' => '<span class="badge badge-secondary">Inativo</span>'
        ];
        return $badges[$status] ?? '<span class="badge badge-secondary">' . $status . '</span>';
    }
}
