-- Double authentification (codes TOTP d'une application d'authentification) pour les comptes d'administration.
-- Désactivée par défaut : réglage « security.two_factor_enabled » à false, puis activation compte par compte.

-- migrate:up
ALTER TABLE users
    ADD COLUMN two_factor_secret         text,          -- clé secrète chiffrée avec APP_KEY
    ADD COLUMN two_factor_confirmed_at   timestamptz,   -- NULL = double authentification non activée pour ce compte
    ADD COLUMN two_factor_recovery_codes jsonb,         -- empreintes SHA-256 des codes de secours non utilisés
    ADD COLUMN two_factor_last_step      bigint;        -- dernier pas de 30 s accepté : un code ne sert qu'une fois

ALTER TABLE users ADD CONSTRAINT users_two_factor_coherent
    CHECK (two_factor_confirmed_at IS NULL OR two_factor_secret IS NOT NULL);

-- migrate:down
ALTER TABLE users DROP CONSTRAINT users_two_factor_coherent;
ALTER TABLE users
    DROP COLUMN two_factor_last_step,
    DROP COLUMN two_factor_recovery_codes,
    DROP COLUMN two_factor_confirmed_at,
    DROP COLUMN two_factor_secret;
