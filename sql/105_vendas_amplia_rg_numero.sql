-- Amplia campos de identificação/endereço usados nos formulários públicos.
-- Evita falhas ao receber RG com órgão expedidor ou números com complemento.

ALTER TABLE vendas_pre_contratos
    ALTER COLUMN rg TYPE VARCHAR(50),
    ALTER COLUMN numero TYPE VARCHAR(50);
