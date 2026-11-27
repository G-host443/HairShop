-- CMS (pages et sections), templates, réglages du site, galerie de réalisations.

-- migrate:up
CREATE TABLE pages (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title                   text        NOT NULL,
    slug                    text        NOT NULL,
    kind                    text        NOT NULL DEFAULT 'standard',
    status                  text        NOT NULL DEFAULT 'draft',
    seo_title               text,
    seo_description         text,
    og_media_id             bigint      REFERENCES media (id) ON DELETE SET NULL,
    published_at            timestamptz,
    has_unpublished_changes boolean     NOT NULL DEFAULT false,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT pages_slug_unique UNIQUE (slug),
    CONSTRAINT pages_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    CONSTRAINT pages_kind_valid CHECK (kind IN ('home', 'standard', 'legal', 'system')),
    CONSTRAINT pages_status_valid CHECK (status IN ('draft', 'published')),
    CONSTRAINT pages_published_coherent CHECK (status = 'draft' OR published_at IS NOT NULL)
);
CREATE UNIQUE INDEX pages_single_home ON pages (kind) WHERE kind = 'home';
CREATE TRIGGER pages_updated_at BEFORE UPDATE ON pages FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- content       : version publiée (NULL = section jamais publiée, invisible pour les visiteurs) ;
-- draft_content : modifications en cours, visibles uniquement en aperçu ;
-- settings      : apparence de la section (fond, espacement…).
CREATE TABLE page_sections (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    page_id       bigint      NOT NULL REFERENCES pages (id) ON DELETE CASCADE,
    type          text        NOT NULL,
    variant       text,
    position      integer     NOT NULL DEFAULT 0,
    is_visible    boolean     NOT NULL DEFAULT true,
    content       jsonb,
    draft_content jsonb,
    settings      jsonb       NOT NULL DEFAULT '{}'::jsonb,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT page_sections_type_format CHECK (type ~ '^[a-z][a-z_]*$'),
    CONSTRAINT page_sections_content_object CHECK (content IS NULL OR jsonb_typeof(content) = 'object'),
    CONSTRAINT page_sections_draft_object CHECK (draft_content IS NULL OR jsonb_typeof(draft_content) = 'object'),
    CONSTRAINT page_sections_has_content CHECK (content IS NOT NULL OR draft_content IS NOT NULL)
);
CREATE INDEX page_sections_page_idx ON page_sections (page_id, position);
CREATE TRIGGER page_sections_updated_at BEFORE UPDATE ON page_sections FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE templates (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug         text        NOT NULL,
    name         text        NOT NULL,
    version      text        NOT NULL DEFAULT '1.0.0',
    is_active    boolean     NOT NULL DEFAULT false,
    installed_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT templates_slug_unique UNIQUE (slug),
    CONSTRAINT templates_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
);
CREATE UNIQUE INDEX templates_single_active ON templates (is_active) WHERE is_active;

-- Réglages conservés par template : revenir à un ancien template retrouve ses réglages.
CREATE TABLE template_settings (
    template_id    bigint      PRIMARY KEY REFERENCES templates (id) ON DELETE CASCADE,
    settings       jsonb       NOT NULL DEFAULT '{}'::jsonb,
    draft_settings jsonb,
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT template_settings_object CHECK (jsonb_typeof(settings) = 'object'),
    CONSTRAINT template_settings_draft_object CHECK (draft_settings IS NULL OR jsonb_typeof(draft_settings) = 'object')
);
CREATE TRIGGER template_settings_updated_at BEFORE UPDATE ON template_settings FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE site_settings (
    key        text        PRIMARY KEY,
    value      jsonb       NOT NULL,
    group_name text        NOT NULL DEFAULT 'general',
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT site_settings_key_format CHECK (key ~ '^[a-z][a-z0-9_]*(\.[a-z0-9_]+)*$'),
    CONSTRAINT site_settings_group_valid CHECK (group_name IN ('general', 'contact', 'booking', 'notifications', 'seo', 'security', 'social'))
);
CREATE INDEX site_settings_group_idx ON site_settings (group_name);
CREATE TRIGGER site_settings_updated_at BEFORE UPDATE ON site_settings FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Portfolio : une réalisation, éventuellement avec une photo « après » pour l'avant/après.
CREATE TABLE gallery_items (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    media_id       bigint      NOT NULL REFERENCES media (id) ON DELETE RESTRICT,
    after_media_id bigint      REFERENCES media (id) ON DELETE RESTRICT,
    title          text        NOT NULL,
    description    text,
    category_id    bigint      REFERENCES service_categories (id) ON DELETE SET NULL,
    service_id     bigint      REFERENCES services (id) ON DELETE SET NULL,
    tags           text[]      NOT NULL DEFAULT '{}',
    is_featured    boolean     NOT NULL DEFAULT false,
    is_published   boolean     NOT NULL DEFAULT true,
    position       integer     NOT NULL DEFAULT 0,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT gallery_items_before_after_distinct CHECK (after_media_id IS DISTINCT FROM media_id)
);
CREATE INDEX gallery_items_published_idx ON gallery_items (position) WHERE is_published;
CREATE INDEX gallery_items_category_idx ON gallery_items (category_id);
CREATE INDEX gallery_items_tags_idx ON gallery_items USING gin (tags);
CREATE TRIGGER gallery_items_updated_at BEFORE UPDATE ON gallery_items FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- migrate:down
DROP TABLE IF EXISTS gallery_items;
DROP TABLE IF EXISTS site_settings;
DROP TABLE IF EXISTS template_settings;
DROP TABLE IF EXISTS templates;
DROP TABLE IF EXISTS page_sections;
DROP TABLE IF EXISTS pages;
