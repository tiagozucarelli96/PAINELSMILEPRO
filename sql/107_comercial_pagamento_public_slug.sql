-- Link público curto para solicitações de pagamento comercial.

ALTER TABLE comercial_pagamento_solicitacoes
    ADD COLUMN IF NOT EXISTS public_slug VARCHAR(16);

CREATE UNIQUE INDEX IF NOT EXISTS idx_comercial_pagamento_solicitacoes_public_slug
    ON comercial_pagamento_solicitacoes(public_slug)
    WHERE public_slug IS NOT NULL;
