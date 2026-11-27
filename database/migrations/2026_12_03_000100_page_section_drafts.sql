-- Brouillon complet du constructeur de pages : en plus du contenu (draft_content), l'ordre,
-- la visibilité, la variante et la suppression d'une section restent en attente jusqu'à la publication.
-- NULL = pas de modification en attente ; draft_deleted = suppression appliquée à la publication.

-- migrate:up
ALTER TABLE page_sections
    ADD COLUMN draft_variant    text,
    ADD COLUMN draft_position   integer,
    ADD COLUMN draft_is_visible boolean,
    ADD COLUMN draft_deleted    boolean NOT NULL DEFAULT false;

-- migrate:down
ALTER TABLE page_sections
    DROP COLUMN draft_deleted,
    DROP COLUMN draft_is_visible,
    DROP COLUMN draft_position,
    DROP COLUMN draft_variant;
