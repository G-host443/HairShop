-- Clientes, favoris, réservations, options choisies, historique des statuts, règles affichées.

-- migrate:up
-- Une cliente peut exister sans compte (réservation en invitée) : user_id est alors NULL.
CREATE TABLE customers (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    user_id           bigint      REFERENCES users (id) ON DELETE SET NULL,
    first_name        text        NOT NULL,
    last_name         text        NOT NULL,
    email             citext,
    phone             text,
    marketing_consent boolean     NOT NULL DEFAULT false,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT customers_user_unique UNIQUE (user_id),
    CONSTRAINT customers_contact_required CHECK (email IS NOT NULL OR phone IS NOT NULL),
    CONSTRAINT customers_email_format CHECK (email IS NULL OR email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$')
);
CREATE INDEX customers_email_idx ON customers (email);
CREATE INDEX customers_phone_idx ON customers (phone);
CREATE INDEX customers_name_idx ON customers (lower(last_name), lower(first_name));
CREATE TRIGGER customers_updated_at BEFORE UPDATE ON customers FOR EACH ROW EXECUTE FUNCTION set_updated_at();

CREATE TABLE customer_notes (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id bigint      NOT NULL REFERENCES customers (id) ON DELETE CASCADE,
    author_id   bigint      REFERENCES users (id) ON DELETE SET NULL,
    body        text        NOT NULL,
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX customer_notes_customer_idx ON customer_notes (customer_id, created_at DESC);

CREATE TABLE favorites (
    customer_id bigint      NOT NULL REFERENCES customers (id) ON DELETE CASCADE,
    service_id  bigint      NOT NULL REFERENCES services (id) ON DELETE CASCADE,
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (customer_id, service_id)
);

-- blocks_slot doit refléter la clause WHERE de bookings_no_overlap (pending, confirmed, in_progress).
CREATE TABLE booking_statuses (
    code        text     PRIMARY KEY,
    label       text     NOT NULL,
    color       text     NOT NULL,
    blocks_slot boolean  NOT NULL,
    position    smallint NOT NULL DEFAULT 0,
    CONSTRAINT booking_statuses_color_format CHECK (color ~ '^#[0-9A-Fa-f]{6}$')
);

CREATE TABLE bookings (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    reference         text          NOT NULL,             -- ex. HSG-7K2P9Q, communiquée à la cliente
    manage_token_hash text,                               -- empreinte du jeton du lien « gérer mon RDV »
    customer_id       bigint        NOT NULL REFERENCES customers (id) ON DELETE RESTRICT,
    service_id        bigint        NOT NULL REFERENCES services (id) ON DELETE RESTRICT,
    variant_id        bigint        REFERENCES service_variants (id) ON DELETE SET NULL,
    length_id         bigint        REFERENCES service_lengths (id) ON DELETE SET NULL,
    service_label     text          NOT NULL,             -- instantané : « Knotless taille M — Mi-dos »
    starts_at         timestamptz   NOT NULL,
    ends_at           timestamptz   NOT NULL,
    during            tstzrange     GENERATED ALWAYS AS (tstzrange(starts_at, ends_at, '[)')) STORED,
    status            text          NOT NULL DEFAULT 'pending' REFERENCES booking_statuses (code) ON UPDATE CASCADE,
    estimated_total   numeric(10,2),                      -- NULL = sur devis
    final_total       numeric(10,2),
    promotion_id      bigint        REFERENCES promotions (id) ON DELETE SET NULL,
    discount_amount   numeric(10,2) NOT NULL DEFAULT 0,
    customer_comment  text,
    rules_accepted_at timestamptz,
    cancelled_at      timestamptz,
    cancel_reason     text,
    source            text          NOT NULL DEFAULT 'web',
    created_by        bigint        REFERENCES users (id) ON DELETE SET NULL,
    created_at        timestamptz   NOT NULL DEFAULT now(),
    updated_at        timestamptz   NOT NULL DEFAULT now(),
    CONSTRAINT bookings_reference_unique UNIQUE (reference),
    CONSTRAINT bookings_reference_format CHECK (reference ~ '^HSG-[A-Z0-9]{6}$'),
    CONSTRAINT bookings_manage_token_unique UNIQUE (manage_token_hash),
    CONSTRAINT bookings_period_valid CHECK (ends_at > starts_at),
    CONSTRAINT bookings_amounts_positive CHECK (
        (estimated_total IS NULL OR estimated_total >= 0)
        AND (final_total IS NULL OR final_total >= 0)
        AND discount_amount >= 0
    ),
    CONSTRAINT bookings_source_valid CHECK (source IN ('web', 'admin', 'whatsapp', 'phone')),
    CONSTRAINT bookings_cancel_coherent CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL),
    -- Garantie absolue contre les doubles réservations, même en cas de requêtes simultanées.
    CONSTRAINT bookings_no_overlap EXCLUDE USING gist (during WITH &&)
        WHERE (status IN ('pending', 'confirmed', 'in_progress'))
);
CREATE INDEX bookings_starts_idx ON bookings (starts_at);
CREATE INDEX bookings_customer_idx ON bookings (customer_id, starts_at DESC);
CREATE INDEX bookings_status_idx ON bookings (status, starts_at);
CREATE INDEX bookings_service_idx ON bookings (service_id);
CREATE TRIGGER bookings_updated_at BEFORE UPDATE ON bookings FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Options choisies, avec libellé et prix figés au moment de la réservation.
CREATE TABLE booking_options (
    booking_id bigint        NOT NULL REFERENCES bookings (id) ON DELETE CASCADE,
    option_id  bigint        NOT NULL REFERENCES service_options (id) ON DELETE RESTRICT,
    label      text          NOT NULL,
    price      numeric(10,2),                             -- NULL = sur devis
    PRIMARY KEY (booking_id, option_id),
    CONSTRAINT booking_options_price_positive CHECK (price IS NULL OR price >= 0)
);

CREATE TABLE booking_status_history (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    booking_id  bigint      NOT NULL REFERENCES bookings (id) ON DELETE CASCADE,
    from_status text        REFERENCES booking_statuses (code) ON UPDATE CASCADE,
    to_status   text        NOT NULL REFERENCES booking_statuses (code) ON UPDATE CASCADE,
    changed_by  bigint      REFERENCES users (id) ON DELETE SET NULL,
    note        text,
    changed_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX booking_status_history_booking_idx ON booking_status_history (booking_id, changed_at);

-- Règles « À savoir avant de venir », à accepter avant de confirmer une réservation.
CREATE TABLE booking_rules (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    body       text        NOT NULL,
    position   integer     NOT NULL DEFAULT 0,
    is_active  boolean     NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER booking_rules_updated_at BEFORE UPDATE ON booking_rules FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- migrate:down
DROP TABLE IF EXISTS booking_rules;
DROP TABLE IF EXISTS booking_status_history;
DROP TABLE IF EXISTS booking_options;
DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS booking_statuses;
DROP TABLE IF EXISTS favorites;
DROP TABLE IF EXISTS customer_notes;
DROP TABLE IF EXISTS customers;
