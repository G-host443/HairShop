-- Fréquentation du site : nombre de pages vues par jour et par adresse.
-- Aucune donnée personnelle (ni IP, ni cookie, ni identifiant) : un simple compteur agrégé.

-- migrate:up
CREATE TABLE site_visits (
    day   date    NOT NULL,
    path  text    NOT NULL,
    views integer NOT NULL DEFAULT 0,
    PRIMARY KEY (day, path),
    CONSTRAINT site_visits_views_positive CHECK (views >= 0),
    CONSTRAINT site_visits_path_format CHECK (path ~ '^/' AND length(path) <= 200)
);

-- migrate:down
DROP TABLE IF EXISTS site_visits;
