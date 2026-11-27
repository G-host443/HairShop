-- Avis clients, modérés avant publication.

-- migrate:up
CREATE TABLE reviews (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    customer_id    bigint      REFERENCES customers (id) ON DELETE SET NULL,
    booking_id     bigint      REFERENCES bookings (id) ON DELETE SET NULL,
    author_name    text        NOT NULL,
    rating         smallint    NOT NULL,
    comment        text        NOT NULL,
    photo_media_id bigint      REFERENCES media (id) ON DELETE SET NULL,
    status         text        NOT NULL DEFAULT 'pending',
    is_featured    boolean     NOT NULL DEFAULT false,
    created_at     timestamptz NOT NULL DEFAULT now(),
    moderated_at   timestamptz,
    moderated_by   bigint      REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT reviews_rating_range CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT reviews_status_valid CHECK (status IN ('pending', 'approved', 'rejected')),
    CONSTRAINT reviews_featured_requires_approval CHECK (NOT is_featured OR status = 'approved'),
    CONSTRAINT reviews_one_per_booking UNIQUE (booking_id)
);
CREATE INDEX reviews_status_idx ON reviews (status, created_at DESC);
CREATE INDEX reviews_customer_idx ON reviews (customer_id);

-- migrate:down
DROP TABLE IF EXISTS reviews;
