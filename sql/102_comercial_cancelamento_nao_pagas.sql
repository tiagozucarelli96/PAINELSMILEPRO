-- Apoia a rotina que cancela inscrições de degustação sem pagamento após 48 horas.
CREATE INDEX IF NOT EXISTS idx_comercial_inscricoes_pagamento_pendente_criado
ON comercial_inscricoes (criado_em)
WHERE status IN ('confirmado', 'lista_espera')
  AND pagamento_status IN ('aguardando', 'expirado');
