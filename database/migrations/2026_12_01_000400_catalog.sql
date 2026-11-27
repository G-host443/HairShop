-- Catalogue : catégories, prestations, grille de prix taille × longueur, options et promotions.

-- migrate:up
CREATE TABLE service_categories (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name           text        NOT NULL,
    slug           text        NOT NULL,
    description    text,
    header_note    text,                       -- ex. « Pour les enfants âgés de 4 à 10 ans. »
    image_media_id bigint      REFERENCES media (id) ON DELETE SET NULL,
    position       integer     NOT NULL DEFAULT 0,
    is_active      boolean     NOT NULL DEFAULT true,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT service_categories_slug_unique UNIQUE (slug),
    CONSTRAINT service_categories_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
);
CREATE INDEX service_categories_position_idx ON service_categories (position);
CREATE TRIGGER service_categories_updated_at BEFORE UPDATE ON service_categories FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- pricing_mode :
--   matrix → prix par variante de taille × longueur (table variant_prices) ;
--   simple → prix unique porté par la prestation (price_kind + price).
-- price_kind : fixed = « 60 € », from = « à partir de 60 € », quote = « sur devis ».
CREATE TABLE services (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category_id      bigint        NOT NULL REFERENCES service_categories (id) ON DELETE RESTRICT,
    name             text          NOT NULL,
    slug             text          NOT NULL,
    summary          text,
    description      text,                     -- HTML nettoyé (HTML Purifier)
    pricing_mode     text          NOT NULL DEFAULT 'simple',
    price_kind       text          NOT NULL DEFAULT 'fixed',
    price            numeric(10,2),
    duration_minutes integer       NOT NULL,   -- durée par défaut si la variante / la longueur n'en précise pas
    alert_text       text,                     -- ex. « Si knotless à l'arrière, un rajout de 15 € est appliqué. »
    note             text,
    benefits         jsonb         NOT NULL DEFAULT '[]'::jsonb,
    steps            jsonb         NOT NULL DEFAULT '[]'::jsonb,
    recommendations  text,
    is_featured      boolean       NOT NULL DEFAULT false,
    is_active        boolean       NOT NULL DEFAULT true,
    position         integer       NOT NULL DEFAULT 0,
    seo_title        text,
    seo_description  text,
    created_at       timestamptz   NOT NULL DEFAULT now(),
    updated_at       timestamptz   NOT NULL DEFAULT now(),
    CONSTRAINT services_slug_unique UNIQUE (slug),
    CONSTRAINT services_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    CONSTRAINT services_pricing_mode_valid CHECK (pricing_mode IN ('matrix', 'simple')),
    CONSTRAINT services_price_kind_valid CHECK (price_kind IN ('fixed', 'from', 'quote')),
    CONSTRAINT services_price_positive CHECK (price IS NULL OR price >= 0),
    CONSTRAINT services_simple_price_required CHECK (pricing_mode = 'matrix' OR price_kind = 'quote' OR price IS NOT NULL),
    CONSTRAINT services_duration_positive CHECK (duration_minutes > 0),
    CONSTRAINT services_benefits_array CHECK (jsonb_typeof(benefits) = 'array'),
    CONSTRAINT services_steps_array CHECK (jsonb_typeof(steps) = 'array')
);
CREATE INDEX services_category_idx ON services (category_id, position);
CREATE INDEX services_featured_idx ON services (is_featured) WHERE is_featured AND is_active;
CREATE TRIGGER services_updated_at BEFORE UPDATE ON services FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE service_images (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    service_id bigint  NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    media_id   bigint  NOT NULL REFERENCES media (id) ON DELETE CASCADE,
    position   integer NOT NULL DEFAULT 0,
    is_cover   boolean NOT NULL DEFAULT false,
    CONSTRAINT service_images_unique UNIQUE (service_id, media_id)
);
CREATE UNIQUE INDEX service_images_one_cover ON service_images (service_id) WHERE is_cover;

-- Prestations complémentaires proposées sur la fiche d'une prestation.
CREATE TABLE service_related (
    service_id         bigint NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    related_service_id bigint NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    position           integer NOT NULL DEFAULT 0,
    PRIMARY KEY (service_id, related_service_id),
    CONSTRAINT service_related_not_self CHECK (service_id <> related_service_id)
);

-- Tailles de tresses : S (fines) … XL (épaisses).
CREATE TABLE service_sizes (
    id       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code     text    NOT NULL,
    label    text    NOT NULL,
    position integer NOT NULL DEFAULT 0,
    CONSTRAINT service_sizes_code_unique UNIQUE (code)
);

-- Longueurs ordonnées : Nuque → Mi-cuisse.
CREATE TABLE service_lengths (
    id       bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    label    text    NOT NULL,
    slug     text    NOT NULL,
    position integer NOT NULL DEFAULT 0,
    CONSTRAINT service_lengths_slug_unique UNIQUE (slug),
    CONSTRAINT service_lengths_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
);

-- Variante de taille d'une prestation en mode matrix (ex. Knotless taille M).
CREATE TABLE service_variants (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    service_id       bigint      NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    size_id          bigint      NOT NULL REFERENCES service_sizes (id) ON DELETE RESTRICT,
    label            text,                     -- libellé spécifique, ex. « S – box braids »
    note             text,                     -- ex. « Avec mèches Xpression. »
    image_media_id   bigint      REFERENCES media (id) ON DELETE SET NULL,
    duration_minutes integer,
    position         integer     NOT NULL DEFAULT 0,
    is_active        boolean     NOT NULL DEFAULT true,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT service_variants_unique UNIQUE NULLS NOT DISTINCT (service_id, size_id, label),
    CONSTRAINT service_variants_duration_positive CHECK (duration_minutes IS NULL OR duration_minutes > 0)
);
CREATE INDEX service_variants_service_idx ON service_variants (service_id, position);
CREATE TRIGGER service_variants_updated_at BEFORE UPDATE ON service_variants FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Cellule de la grille. Absence de ligne = longueur non proposée pour cette variante.
CREATE TABLE variant_prices (
    variant_id       bigint        NOT NULL REFERENCES service_variants (id) ON DELETE CASCADE,
    length_id        bigint        NOT NULL REFERENCES service_lengths (id) ON DELETE RESTRICT,
    price_kind       text          NOT NULL DEFAULT 'fixed',
    price            numeric(10,2),
    duration_minutes integer,
    PRIMARY KEY (variant_id, length_id),
    CONSTRAINT variant_prices_kind_valid CHECK (price_kind IN ('fixed', 'quote')),
    CONSTRAINT variant_prices_price_coherent CHECK (
        (price_kind = 'fixed' AND price IS NOT NULL AND price >= 0)
        OR (price_kind = 'quote' AND price IS NULL)
    ),
    CONSTRAINT variant_prices_duration_positive CHECK (duration_minutes IS NULL OR duration_minutes > 0)
);
CREATE INDEX variant_prices_length_idx ON variant_prices (length_id);

-- Groupes d'options : finition (une seule), entretien (une seule), suppléments (plusieurs).
CREATE TABLE option_groups (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        text    NOT NULL,
    label       text    NOT NULL,
    selection   text    NOT NULL DEFAULT 'single',
    is_required boolean NOT NULL DEFAULT false,
    position    integer NOT NULL DEFAULT 0,
    CONSTRAINT option_groups_code_unique UNIQUE (code),
    CONSTRAINT option_groups_selection_valid CHECK (selection IN ('single', 'multiple'))
);

-- price = 0 avec price_kind = fixed → « offert ».
CREATE TABLE service_options (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    group_id      bigint        NOT NULL REFERENCES option_groups (id) ON DELETE CASCADE,
    name          text          NOT NULL,
    description   text,
    price_kind    text          NOT NULL DEFAULT 'fixed',
    price         numeric(10,2) DEFAULT 0,
    extra_minutes integer       NOT NULL DEFAULT 0,
    position      integer       NOT NULL DEFAULT 0,
    is_active     boolean       NOT NULL DEFAULT true,
    created_at    timestamptz   NOT NULL DEFAULT now(),
    updated_at    timestamptz   NOT NULL DEFAULT now(),
    CONSTRAINT service_options_name_unique UNIQUE (group_id, name),
    CONSTRAINT service_options_kind_valid CHECK (price_kind IN ('fixed', 'from', 'quote')),
    CONSTRAINT service_options_price_coherent CHECK (price_kind = 'quote' OR (price IS NOT NULL AND price >= 0)),
    CONSTRAINT service_options_minutes_positive CHECK (extra_minutes >= 0)
);
CREATE INDEX service_options_group_idx ON service_options (group_id, position);
CREATE TRIGGER service_options_updated_at BEFORE UPDATE ON service_options FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Rattachement d'un groupe d'options à une catégorie OU à une prestation.
-- is_excluded = true sur une prestation retire un groupe hérité de sa catégorie.
CREATE TABLE option_group_assignments (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    option_group_id bigint  NOT NULL REFERENCES option_groups (id) ON DELETE CASCADE,
    category_id     bigint  REFERENCES service_categories (id) ON DELETE CASCADE,
    service_id      bigint  REFERENCES services (id) ON DELETE CASCADE,
    is_excluded     boolean NOT NULL DEFAULT false,
    CONSTRAINT option_group_assignments_one_target CHECK (num_nonnulls(category_id, service_id) = 1),
    CONSTRAINT option_group_assignments_unique UNIQUE NULLS NOT DISTINCT (option_group_id, category_id, service_id)
);
CREATE INDEX option_group_assignments_category_idx ON option_group_assignments (category_id) WHERE category_id IS NOT NULL;
CREATE INDEX option_group_assignments_service_idx ON option_group_assignments (service_id) WHERE service_id IS NOT NULL;

-- discount_type : percent (value = %), amount (value = € retirés), fixed_price (value = nouveau prix, mode simple uniquement).
CREATE TABLE promotions (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name          text          NOT NULL,
    description   text,
    discount_type text          NOT NULL,
    value         numeric(10,2) NOT NULL,
    starts_at     timestamptz   NOT NULL,
    ends_at       timestamptz   NOT NULL,
    is_active     boolean       NOT NULL DEFAULT true,
    created_at    timestamptz   NOT NULL DEFAULT now(),
    updated_at    timestamptz   NOT NULL DEFAULT now(),
    CONSTRAINT promotions_type_valid CHECK (discount_type IN ('percent', 'amount', 'fixed_price')),
    CONSTRAINT promotions_value_positive CHECK (value > 0),
    CONSTRAINT promotions_percent_max CHECK (discount_type <> 'percent' OR value <= 100),
    CONSTRAINT promotions_period_valid CHECK (ends_at > starts_at)
);
CREATE INDEX promotions_active_idx ON promotions (starts_at, ends_at) WHERE is_active;
CREATE TRIGGER promotions_updated_at BEFORE UPDATE ON promotions FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE promotion_services (
    promotion_id bigint NOT NULL REFERENCES promotions (id) ON DELETE CASCADE,
    service_id   bigint NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    PRIMARY KEY (promotion_id, service_id)
);
CREATE INDEX promotion_services_service_idx ON promotion_services (service_id);

-- migrate:down
DROP TABLE IF EXISTS promotion_services;
DROP TABLE IF EXISTS promotions;
DROP TABLE IF EXISTS option_group_assignments;
DROP TABLE IF EXISTS service_options;
DROP TABLE IF EXISTS option_groups;
DROP TABLE IF EXISTS variant_prices;
DROP TABLE IF EXISTS service_variants;
DROP TABLE IF EXISTS service_lengths;
DROP TABLE IF EXISTS service_sizes;
DROP TABLE IF EXISTS service_related;
DROP TABLE IF EXISTS service_images;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS service_categories;
