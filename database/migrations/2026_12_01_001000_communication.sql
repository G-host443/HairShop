-- Messages du formulaire de contact et file d'envoi des notifications.

-- migrate:up
CREATE TABLE contact_messages (
    id         bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name       text        NOT NULL,
    email      citext      NOT NULL,
    phone      text,
    subject    text,
    body       text        NOT NULL,
    ip         inet,
    is_read    boolean     NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT contact_messages_email_format CHECK (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$')
);
CREATE INDEX contact_messages_inbox_idx ON contact_messages (is_read, created_at DESC);

-- Traitée par « bin/console notifications:send » (cron).
CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel      text        NOT NULL,
    event        text        NOT NULL,             -- booking.created, booking.reminder…
    recipient    text        NOT NULL,
    subject      text,
    payload      jsonb       NOT NULL DEFAULT '{}'::jsonb,
    status       text        NOT NULL DEFAULT 'queued',
    attempts     smallint    NOT NULL DEFAULT 0,
    scheduled_at timestamptz NOT NULL DEFAULT now(),
    sent_at      timestamptz,
    error        text,
    booking_id   bigint      REFERENCES bookings (id) ON DELETE SET NULL,
    created_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT notifications_channel_valid CHECK (channel IN ('email', 'whatsapp_link')),
    CONSTRAINT notifications_status_valid CHECK (status IN ('queued', 'sent', 'failed', 'cancelled')),
    CONSTRAINT notifications_attempts_positive CHECK (attempts >= 0)
);
CREATE INDEX notifications_queue_idx ON notifications (scheduled_at) WHERE status = 'queued';
CREATE INDEX notifications_booking_idx ON notifications (booking_id);
-- Un seul rappel par réservation et par canal (évite les doublons si le cron repasse).
CREATE UNIQUE INDEX notifications_single_reminder ON notifications (booking_id, channel)
    WHERE event = 'booking.reminder' AND status <> 'cancelled';

-- migrate:down
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS contact_messages;
