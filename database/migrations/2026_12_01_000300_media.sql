-- Bibliothèque de médias (images, vidéos) et dossiers.

-- migrate:up
CREATE TABLE media_folders (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    parent_id  bigint      REFERENCES media_folders (id) ON DELETE CASCADE,
    name       text        NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT media_folders_name_unique UNIQUE NULLS NOT DISTINCT (parent_id, name),
    CONSTRAINT media_folders_not_self CHECK (parent_id IS DISTINCT FROM id)
);

CREATE TABLE media (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    folder_id     bigint      REFERENCES media_folders (id) ON DELETE SET NULL,
    disk_path     text        NOT NULL,              -- relatif à public/uploads
    original_name text        NOT NULL,
    mime_type     text        NOT NULL,
    size_bytes    bigint      NOT NULL,
    width         integer,
    height        integer,
    alt_text      text        NOT NULL DEFAULT '',
    variants      jsonb       NOT NULL DEFAULT '{}'::jsonb, -- {"thumb": "...", "medium": "...", "webp": "..."}
    uploaded_by   bigint      REFERENCES users (id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT media_disk_path_unique UNIQUE (disk_path),
    CONSTRAINT media_size_positive CHECK (size_bytes > 0),
    CONSTRAINT media_dimensions_positive CHECK ((width IS NULL OR width > 0) AND (height IS NULL OR height > 0)),
    CONSTRAINT media_mime_allowed CHECK (mime_type ~ '^(image|video)/')
);
CREATE INDEX media_folder_idx ON media (folder_id);
CREATE INDEX media_created_idx ON media (created_at DESC);
CREATE TRIGGER media_updated_at BEFORE UPDATE ON media FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- migrate:down
DROP TABLE IF EXISTS media;
DROP TABLE IF EXISTS media_folders;
