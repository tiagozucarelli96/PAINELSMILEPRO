<?php
/**
 * eventos_cliente_portal_ui.php
 * Helpers visuais/temporais para páginas públicas do portal do cliente.
 */

if (!function_exists('eventos_cliente_ui_normalizar_horario_curto')) {
    function eventos_cliente_ui_normalizar_horario_curto(string $value, string $fallback = '-'): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }

        if (preg_match('/\b(\d{1,2}):(\d{2})/', $value, $matches)) {
            $hour = max(0, min(23, (int)$matches[1]));
            $minute = max(0, min(59, (int)$matches[2]));
            return sprintf('%02d:%02d', $hour, $minute);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return $fallback;
        }

        return date('H:i', $timestamp);
    }
}

if (!function_exists('eventos_cliente_ui_horario_evento')) {
    function eventos_cliente_ui_horario_evento(array $snapshot, string $fallback = '-'): string
    {
        $candidates = [
            (string)($snapshot['hora_inicio'] ?? ''),
            (string)($snapshot['hora'] ?? ''),
            (string)($snapshot['horainicio'] ?? ''),
            (string)($snapshot['hora_evento'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $formatted = eventos_cliente_ui_normalizar_horario_curto($candidate, '');
            if ($formatted !== '') {
                return $formatted;
            }
        }

        return $fallback;
    }
}

if (!function_exists('eventos_cliente_ui_event_datetime')) {
    function eventos_cliente_ui_event_datetime(array $snapshot): ?DateTimeImmutable
    {
        $dateRaw = trim((string)($snapshot['data'] ?? ''));
        $timeRaw = eventos_cliente_ui_horario_evento($snapshot, '');
        if ($dateRaw === '' || $timeRaw === '') {
            return null;
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateRaw . ' ' . $timeRaw, $timezone);
        if ($date instanceof DateTimeImmutable) {
            return $date;
        }

        try {
            return new DateTimeImmutable($dateRaw . ' ' . $timeRaw, $timezone);
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('eventos_cliente_ui_event_datetime_iso')) {
    function eventos_cliente_ui_event_datetime_iso(array $snapshot): string
    {
        $eventDate = eventos_cliente_ui_event_datetime($snapshot);
        return $eventDate ? $eventDate->format(DateTimeInterface::ATOM) : '';
    }
}

if (!function_exists('eventos_cliente_ui_form_back_link')) {
    function eventos_cliente_ui_form_back_link(?array $portal, string $linkType, string $linkSection, bool $isCombinedReuniao = false): array
    {
        $portalToken = trim((string)($portal['token'] ?? ''));
        if ($portalToken === '') {
            return ['href' => '', 'label' => 'Voltar'];
        }

        $linkType = strtolower(trim($linkType));
        $linkSection = strtolower(trim($linkSection));

        if ($linkType === 'cliente_formulario' || $linkSection === 'formulario') {
            return [
                'href' => 'index.php?page=eventos_cliente_formulario_portal&token=' . urlencode($portalToken),
                'label' => 'Voltar para Formulários',
            ];
        }

        if ($isCombinedReuniao || in_array($linkSection, ['decoracao', 'observacoes_gerais'], true)) {
            return [
                'href' => 'index.php?page=eventos_cliente_reuniao&token=' . urlencode($portalToken),
                'label' => 'Voltar para Reunião Final',
            ];
        }

        if ($linkType === 'cliente_dj' || $linkSection === 'dj_protocolo') {
            return [
                'href' => 'index.php?page=eventos_cliente_dj_portal&token=' . urlencode($portalToken),
                'label' => 'Voltar para DJ e Protocolos',
            ];
        }

        return [
            'href' => 'index.php?page=eventos_cliente_portal&token=' . urlencode($portalToken),
            'label' => 'Voltar ao Portal',
        ];
    }
}
