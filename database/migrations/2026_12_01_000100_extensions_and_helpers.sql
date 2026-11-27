-- Extensions et fonctions utilitaires partagées par toutes les tables.

-- migrate:up
-- btree_gist : contraintes d'exclusion mêlant égalité et chevauchement de plages.
-- citext     : e-mails comparés sans tenir compte de la casse.
-- (extensions « trusted » : le propriétaire de la base peut les créer.)
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;

-- Mise à jour automatique de la colonne updated_at.
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$;

-- Plage d'heures dans la journée (horaires d'ouverture), pour interdire les chevauchements.
CREATE TYPE timerange AS RANGE (subtype = time);

-- migrate:down
DROP TYPE IF EXISTS timerange;
DROP FUNCTION IF EXISTS set_updated_at();
