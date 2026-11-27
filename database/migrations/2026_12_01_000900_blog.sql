-- Blog et conseils capillaires.

-- migrate:up
CREATE TABLE blog_categories (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name       text        NOT NULL,
    slug       text        NOT NULL,
    position   integer     NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT blog_categories_slug_unique UNIQUE (slug),
    CONSTRAINT blog_categories_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
);

CREATE TABLE blog_tags (
    id   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name text NOT NULL,
    slug text NOT NULL,
    CONSTRAINT blog_tags_slug_unique UNIQUE (slug),
    CONSTRAINT blog_tags_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')
);

-- status : draft (brouillon), scheduled (publication programmée à published_at), published.
CREATE TABLE blog_posts (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    category_id     bigint      REFERENCES blog_categories (id) ON DELETE SET NULL,
    author_id       bigint      REFERENCES users (id) ON DELETE SET NULL,
    title           text        NOT NULL,
    slug            text        NOT NULL,
    excerpt         text,
    content         text        NOT NULL DEFAULT '',  -- HTML nettoyé (HTML Purifier)
    cover_media_id  bigint      REFERENCES media (id) ON DELETE SET NULL,
    status          text        NOT NULL DEFAULT 'draft',
    published_at    timestamptz,
    seo_title       text,
    seo_description text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT blog_posts_slug_unique UNIQUE (slug),
    CONSTRAINT blog_posts_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    CONSTRAINT blog_posts_status_valid CHECK (status IN ('draft', 'scheduled', 'published')),
    CONSTRAINT blog_posts_date_required CHECK (status = 'draft' OR published_at IS NOT NULL)
);
CREATE INDEX blog_posts_listing_idx ON blog_posts (status, published_at DESC);
CREATE INDEX blog_posts_category_idx ON blog_posts (category_id);
CREATE TRIGGER blog_posts_updated_at BEFORE UPDATE ON blog_posts FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE blog_post_tags (
    post_id bigint NOT NULL REFERENCES blog_posts (id) ON DELETE CASCADE,
    tag_id  bigint NOT NULL REFERENCES blog_tags (id) ON DELETE CASCADE,
    PRIMARY KEY (post_id, tag_id)
);
CREATE INDEX blog_post_tags_tag_idx ON blog_post_tags (tag_id);

-- migrate:down
DROP TABLE IF EXISTS blog_post_tags;
DROP TABLE IF EXISTS blog_posts;
DROP TABLE IF EXISTS blog_tags;
DROP TABLE IF EXISTS blog_categories;
