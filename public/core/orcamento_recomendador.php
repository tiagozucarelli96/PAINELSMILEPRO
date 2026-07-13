<?php
declare(strict_types=1);

final class OrcamentoRecomendador
{
    public static function recomendar(array $r): array
    {
        $tipo = (string)($r['tipo_evento'] ?? '');
        $qtd = (int)($r['convidados'] ?? 0);
        $cerimonia = !empty($r['cerimonia']);
        $perfil = (string)($r['perfil'] ?? 'equilibrado');
        $unidades = [];
        $analise = false;

        if ($tipo === 'infantil') {
            if ($qtd >= 30 && $qtd <= 49) $unidades = ['DiverKids'];
            elseif ($qtd >= 50 && $qtd <= 70) $unidades = ['DiverKids', 'Lisbon Kids'];
            elseif ($qtd >= 71 && $qtd <= 100) $unidades = ['Lisbon Kids'];
            else $analise = true;
        } elseif (in_array($tipo, ['casamento', 'bodas'], true) && $cerimonia) {
            if ($qtd >= 70 && $qtd <= 89) $unidades = ['Cristal'];
            elseif ($qtd >= 90 && $qtd <= 150) $unidades = ['Cristal', 'Garden'];
            elseif ($qtd >= 151 && $qtd <= 250) $unidades = ['Garden'];
            else $analise = true;
        } else {
            if ($qtd >= 50 && $qtd <= 69) $unidades = ['Lisbon'];
            elseif ($qtd >= 70 && $qtd <= 89) $unidades = ['Lisbon', 'Cristal'];
            elseif ($qtd >= 90 && $qtd <= 100) $unidades = ['Lisbon', 'Cristal', 'Garden'];
            elseif ($qtd >= 101 && $qtd <= 150) $unidades = ['Cristal', 'Garden'];
            elseif ($qtd >= 151 && $qtd <= 250) $unidades = ['Garden'];
            else $analise = true;
        }

        $pacote = null;
        if ($tipo !== 'infantil' && !$analise && $unidades) {
            $u = $unidades[0];
            $pacote = $u === 'Lisbon'
                ? ['economico' => 'Açores', 'equilibrado' => 'Alentejo', 'completo' => 'Estrela'][$perfil] ?? 'Alentejo'
                : ($perfil === 'completo' ? 'Ouro' : 'Prata');
        }
        return ['unidades' => array_slice($unidades, 0, 2), 'pacote' => $pacote, 'analise' => $analise];
    }

    public static function whatsapp(array $r, array $rec): string
    {
        $labels = ['casamento'=>'Casamento','bodas'=>'Bodas','15anos'=>'Festa de 15 anos','infantil'=>'Festa infantil'];
        $linhas = ['Olá! Fiz o atendimento guiado no site do Grupo Smile.', '',
            'Evento: '.($labels[$r['tipo_evento'] ?? ''] ?? ''),
            'Data: '.self::dataBr((string)($r['data_evento'] ?? '')),
            'Convidados: '.(int)($r['convidados'] ?? 0)];
        if (in_array($r['tipo_evento'] ?? '', ['casamento','bodas'], true)) $linhas[] = 'Cerimônia/renovação no local: '.(!empty($r['cerimonia']) ? 'Sim' : 'Não');
        if (!empty($r['perfil'])) $linhas[] = 'Perfil: '.ucfirst((string)$r['perfil']);
        if (!empty($r['buffet'])) $linhas[] = 'Buffet: '.ucfirst((string)$r['buffet']);
        $linhas[] = 'Recomendação: '.implode(' ou ', $rec['unidades'] ?? []).(!empty($rec['pacote']) ? ' — '.$rec['pacote'] : '');
        $linhas[] = ''; $linhas[] = 'Gostaria de continuar o atendimento.';
        return implode("\n", $linhas);
    }

    private static function dataBr(string $d): string { $x = DateTimeImmutable::createFromFormat('Y-m-d', $d); return $x ? $x->format('d/m/Y') : $d; }
}
