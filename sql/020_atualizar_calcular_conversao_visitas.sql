-- 020_atualizar_calcular_conversao_visitas.sql
-- Atualizar função calcular_conversao_visitas para incluir eventos do Google Calendar

CREATE OR REPLACE FUNCTION calcular_conversao_visitas(
    p_data_inicio DATE,
    p_data_fim DATE,
    p_espaco_id INT DEFAULT NULL,
    p_responsavel_id INT DEFAULT NULL
)
RETURNS TABLE(
    total_visitas INT,
    comparecimentos INT,
    contratos_fechados INT,
    taxa_conversao NUMERIC
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        COUNT(*)::INT as total_visitas,
        SUM(CASE WHEN compareceu THEN 1 ELSE 0 END)::INT as comparecimentos,
        SUM(CASE WHEN fechou_contrato THEN 1 ELSE 0 END)::INT as contratos_fechados,
        CASE 
            WHEN SUM(CASE WHEN compareceu THEN 1 ELSE 0 END) > 0 
            THEN ROUND(
                (SUM(CASE WHEN fechou_contrato THEN 1 ELSE 0 END)::NUMERIC / 
                 SUM(CASE WHEN compareceu THEN 1 ELSE 0 END)::NUMERIC) * 100, 2
            )
            ELSE 0
        END as taxa_conversao
    FROM (
        -- Visitas da agenda interna
        SELECT 
            ae.compareceu,
            ae.fechou_contrato
        FROM agenda_eventos ae
        WHERE ae.tipo = 'visita'
        AND DATE(ae.inicio) BETWEEN p_data_inicio AND p_data_fim
        AND (p_espaco_id IS NULL OR ae.espaco_id = p_espaco_id)
        AND (p_responsavel_id IS NULL OR ae.responsavel_usuario_id = p_responsavel_id)
        
        UNION ALL
        
        -- Visitas do Google Calendar marcadas como visita agendada
        SELECT 
            false as compareceu,
            gce.contrato_fechado as fechou_contrato
        FROM google_calendar_eventos gce
        WHERE gce.eh_visita_agendada = true
        AND DATE(gce.inicio) BETWEEN p_data_inicio AND p_data_fim
    ) as todas_visitas;
END;
$$ LANGUAGE plpgsql;
