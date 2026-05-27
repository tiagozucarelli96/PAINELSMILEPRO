-- 077_logistica_eventos_telefone_cliente.sql
-- Guarda o telefone/WhatsApp do cliente recebido da ME no espelho local de eventos.

ALTER TABLE logistica_eventos_espelho
ADD COLUMN IF NOT EXISTS whatsapp_cliente VARCHAR(40);

ALTER TABLE logistica_eventos_espelho
ADD COLUMN IF NOT EXISTS telefone_cliente VARCHAR(40);

WITH telefones AS (
    SELECT DISTINCT ON (evento_id::INTEGER)
        evento_id::INTEGER AS me_event_id,
        NULLIF(TRIM(CONCAT_WS(
            ' ',
            NULLIF(webhook_data::jsonb #>> '{data,0,ddicelular}', ''),
            COALESCE(
                NULLIF(webhook_data::jsonb #>> '{data,0,celular}', ''),
                NULLIF(webhook_data::jsonb #>> '{data,0,telefone}', ''),
                NULLIF(webhook_data::jsonb #>> '{data,0,telefone2}', ''),
                NULLIF(webhook_data::jsonb #>> '{data,0,whatsapp}', ''),
                NULLIF(webhook_data::jsonb #>> '{data,0,client_phone}', ''),
                NULLIF(webhook_data::jsonb #>> '{data,0,client_whatsapp}', '')
            )
        )), '') AS whatsapp_cliente,
        COALESCE(
            NULLIF(webhook_data::jsonb #>> '{data,0,celular}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,telefone}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,telefone2}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,whatsapp}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,client_phone}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,client_whatsapp}', '')
        ) AS telefone_cliente,
        recebido_em
    FROM me_eventos_webhook
    WHERE evento_id ~ '^[0-9]+$'
      AND COALESCE(
            NULLIF(webhook_data::jsonb #>> '{data,0,celular}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,telefone}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,telefone2}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,whatsapp}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,client_phone}', ''),
            NULLIF(webhook_data::jsonb #>> '{data,0,client_whatsapp}', '')
      ) IS NOT NULL
    ORDER BY evento_id::INTEGER, recebido_em DESC
)
UPDATE logistica_eventos_espelho e
SET whatsapp_cliente = telefones.whatsapp_cliente,
    telefone_cliente = telefones.telefone_cliente,
    updated_at = NOW()
FROM telefones
WHERE e.me_event_id = telefones.me_event_id;
