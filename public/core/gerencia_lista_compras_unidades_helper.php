<?php
declare(strict_types=1);

function glc_normalize_operational_text(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function glc_evento_grupo_unidade(array $event): string
{
    $unitName = glc_normalize_operational_text((string)($event['unidade_nome'] ?? ''));
    $location = glc_normalize_operational_text(implode(' ', [
        (string)($event['localevento'] ?? ''),
        (string)($event['space_visivel'] ?? ''),
    ]));

    if ($unitName === 'gardencentral'
        || str_contains($location, 'garden')
        || str_contains($location, 'cristal')
    ) {
        return 'garden_cristal';
    }

    $unitId = (int)($event['unidade_interna_id'] ?? 0);
    if ($unitId > 0) {
        return 'unidade_' . $unitId;
    }
    if ($unitName !== '') {
        return 'nome_' . $unitName;
    }
    if ($location !== '') {
        return 'local_' . $location;
    }
    return 'sem_unidade';
}

function glc_evento_grupo_unidade_label(array $event): string
{
    if (glc_evento_grupo_unidade($event) === 'garden_cristal') {
        return 'Garden/Cristal';
    }

    $label = trim((string)($event['unidade_nome'] ?? $event['space_visivel'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    $unitId = (int)($event['unidade_interna_id'] ?? 0);
    return $unitId > 0 ? 'Unidade #' . $unitId : 'Sem unidade';
}

function glc_eventos_grupos_unidade(array $events): array
{
    $groups = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $group = glc_evento_grupo_unidade($event);
        $groups[$group] = glc_evento_grupo_unidade_label($event);
    }
    return $groups;
}

function glc_eventos_mesma_unidade_operacional(array $events): bool
{
    return count(glc_eventos_grupos_unidade($events)) <= 1;
}

function glc_eventos_unidade_lista_id(array $events): int
{
    foreach ($events as $event) {
        if (glc_evento_grupo_unidade((array)$event) === 'garden_cristal'
            && glc_normalize_operational_text((string)($event['unidade_nome'] ?? '')) === 'gardencentral'
        ) {
            return (int)($event['unidade_interna_id'] ?? 0);
        }
    }

    $firstEvent = reset($events);
    return is_array($firstEvent) ? (int)($firstEvent['unidade_interna_id'] ?? 0) : 0;
}
