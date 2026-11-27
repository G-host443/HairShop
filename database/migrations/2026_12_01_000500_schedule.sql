-- Planning : horaires hebdomadaires, horaires exceptionnels, fermetures, créneaux bloqués.

-- migrate:up
-- Plusieurs lignes pour un même jour = journée avec pause(s) ; jour sans ligne = fermé.
CREATE TABLE opening_hours (
    id        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    weekday   smallint NOT NULL,              -- ISO-8601 : 1 = lundi … 7 = dimanche
    opens_at  time     NOT NULL,
    closes_at time     NOT NULL,
    CONSTRAINT opening_hours_weekday_valid CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT opening_hours_order CHECK (closes_at > opens_at),
    CONSTRAINT opening_hours_no_overlap EXCLUDE USING gist (weekday WITH =, timerange(opens_at, closes_at) WITH &&)
);

-- Remplace les horaires habituels pour une date donnée (ouverture exceptionnelle, horaires réduits).
CREATE TABLE special_hours (
    id        bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    date      date NOT NULL,
    opens_at  time NOT NULL,
    closes_at time NOT NULL,
    note      text,
    CONSTRAINT special_hours_order CHECK (closes_at > opens_at),
    CONSTRAINT special_hours_no_overlap EXCLUDE USING gist (date WITH =, timerange(opens_at, closes_at) WITH &&)
);

-- Journées entières fermées : congés, fermetures exceptionnelles.
CREATE TABLE blocked_dates (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    starts_on  date        NOT NULL,
    ends_on    date        NOT NULL,
    reason     text        NOT NULL DEFAULT 'holiday',
    note       text,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT blocked_dates_order CHECK (ends_on >= starts_on),
    CONSTRAINT blocked_dates_reason_valid CHECK (reason IN ('holiday', 'closure', 'other'))
);
CREATE INDEX blocked_dates_range_idx ON blocked_dates USING gist (daterange(starts_on, ends_on, '[]'));

-- Créneaux ponctuels indisponibles (rendez-vous personnel, formation…).
CREATE TABLE blocked_slots (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    period     tstzrange   NOT NULL,
    reason     text,
    created_by bigint      REFERENCES users (id) ON DELETE SET NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT blocked_slots_not_empty CHECK (NOT isempty(period) AND NOT lower_inf(period) AND NOT upper_inf(period))
);
CREATE INDEX blocked_slots_period_idx ON blocked_slots USING gist (period);

-- migrate:down
DROP TABLE IF EXISTS blocked_slots;
DROP TABLE IF EXISTS blocked_dates;
DROP TABLE IF EXISTS special_hours;
DROP TABLE IF EXISTS opening_hours;
