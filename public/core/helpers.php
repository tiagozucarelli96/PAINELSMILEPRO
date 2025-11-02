<?php
// core/helpers.php — Utilitários globais centralizados
// Todas as funções usam !function_exists() para evitar redeclaração

if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('brDate')) {
    function brDate(?string $isoTs): string {
        if (empty($isoTs)) return '';
        $t = strtotime($isoTs);
        return $t ? date('d/m/Y H:i', $t) : $isoTs;
    }
}

if (!function_exists('brDateOnly')) {
    function brDateOnly(?string $isoTs): string {
        if (empty($isoTs)) return '';
        $t = strtotime($isoTs);
        return $t ? date('d/m/Y', $t) : $isoTs;
    }
}

if (!function_exists('dow_pt')) {
    function dow_pt($ts): string {
        static $dias = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
        // Aceitar DateTime, timestamp numérico ou string de data
        if ($ts instanceof \DateTime) {
            return $dias[(int)$ts->format('w')];
        }
        $timestamp = is_numeric($ts) ? $ts : strtotime($ts);
        return $dias[(int)date('w', $timestamp)];
    }
}

if (!function_exists('validarCPF')) {
    function validarCPF(?string $cpf): bool {
        if (empty($cpf)) return false;
        $cpf = preg_replace('/\D/', '', $cpf);
        if (strlen($cpf) != 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
        
        $s = 0;
        for ($i = 0, $p = 10; $i < 9; $i++, $p--) {
            $s += (int)$cpf[$i] * $p;
        }
        $r = $s % 11;
        $d1 = ($r < 2) ? 0 : 11 - $r;
        
        $s = 0;
        for ($i = 0, $p = 11; $i < 10; $i++, $p--) {
            $s += (int)$cpf[$i] * $p;
        }
        $r = $s % 11;
        $d2 = ($r < 2) ? 0 : 11 - $r;
        
        return ($cpf[9] == $d1) && ($cpf[10] == $d2);
    }
}

if (!function_exists('validarCNPJ')) {
    function validarCNPJ(?string $cnpj): bool {
        if (empty($cnpj)) return false;
        $cnpj = preg_replace('/\D/', '', $cnpj);
        if (strlen($cnpj) != 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
        
        $p1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $p2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        
        $s = 0;
        for ($i = 0; $i < 12; $i++) {
            $s += (int)$cnpj[$i] * $p1[$i];
        }
        $r = $s % 11;
        $d1 = ($r < 2) ? 0 : 11 - $r;
        
        $s = 0;
        for ($i = 0; $i < 13; $i++) {
            $s += (int)$cnpj[$i] * $p2[$i];
        }
        $r = $s % 11;
        $d2 = ($r < 2) ? 0 : 11 - $r;
        
        return ($cnpj[12] == $d1) && ($cnpj[13] == $d2);
    }
}

if (!function_exists('js')) {
    function js($string): string {
        return json_encode((string)($string ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }
}

if (!function_exists('format_currency')) {
    function format_currency($value): string {
        return 'R$ ' . number_format((float)($value ?? 0), 2, ',', '.');
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'd/m/Y H:i'): string {
        if (empty($date)) return '';
        return date($format, strtotime($date));
    }
}

if (!function_exists('getStatusBadge')) {
    function getStatusBadge(?string $status): string {
        $badges = [
            'rascunho' => '<span class="badge badge-warning">Rascunho</span>',
            'publicado' => '<span class="badge badge-success">Publicado</span>',
            'encerrado' => '<span class="badge badge-secondary">Encerrado</span>',
            'confirmado' => '<span class="badge badge-success">Confirmado</span>',
            'lista_espera' => '<span class="badge badge-warning">Lista de Espera</span>',
            'cancelado' => '<span class="badge badge-danger">Cancelado</span>',
            'ativo' => '<span class="badge badge-success">Ativo</span>',
            'inativo' => '<span class="badge badge-secondary">Inativo</span>',
            'pendente' => '<span class="badge badge-warning">Pendente</span>',
            'pago' => '<span class="badge badge-success">Pago</span>',
        ];
        return $badges[$status] ?? '<span class="badge badge-secondary">' . htmlspecialchars($status ?? '') . '</span>';
    }
}
