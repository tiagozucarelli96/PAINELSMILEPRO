-- Links curtos do Portal do Cliente.
-- O token original permanece intacto para compatibilidade com URLs já enviadas.

ALTER TABLE IF EXISTS eventos_cliente_portais
    ADD COLUMN IF NOT EXISTS short_code VARCHAR(24);

CREATE UNIQUE INDEX IF NOT EXISTS idx_eventos_cliente_portais_short_code
    ON eventos_cliente_portais(short_code)
    WHERE short_code IS NOT NULL;

-- Atualiza os portais já existentes sem trocar nem invalidar o token original.
UPDATE eventos_cliente_portais
SET short_code = SUBSTRING(MD5(token || ':portal-curto:v1') FROM 1 FOR 20)
WHERE short_code IS NULL OR short_code = '';
