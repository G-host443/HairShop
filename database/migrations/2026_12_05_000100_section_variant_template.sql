-- La variante d'une section (présentation) est un choix fait pour un template donné.
-- variant_template retient ce template : avec un autre template actif, c'est la variante par défaut
-- de celui-ci qui s'applique ; en revenant au template d'origine, le choix est retrouvé.
-- draft_variant_template accompagne draft_variant (brouillon, appliqué à la publication).

-- migrate:up
ALTER TABLE page_sections
    ADD COLUMN variant_template       text,
    ADD COLUMN draft_variant_template text;
UPDATE page_sections SET variant_template = 'afro-natural' WHERE variant IS NOT NULL;
UPDATE page_sections SET draft_variant_template = 'afro-natural' WHERE draft_variant IS NOT NULL;

-- migrate:down
ALTER TABLE page_sections
    DROP COLUMN draft_variant_template,
    DROP COLUMN variant_template;
