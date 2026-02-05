-- 046_logistica_me_locais_tipo_evento.sql
-- ID do tipo de evento na ME por local: usado em Vendas ao aprovar e criar na ME (tipoevento = por local)

ALTER TABLE logistica_me_locais
ADD COLUMN IF NOT EXISTS me_tipo_evento_id INTEGER;

COMMENT ON COLUMN logistica_me_locais.me_tipo_evento_id IS 'ID do tipo de evento na ME para este local (Cristal=15, Lisbon Garden=10, DiverKids=13, Lisbon 1=4)';
