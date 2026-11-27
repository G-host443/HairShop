-- Création de l'utilisateur et des bases PostgreSQL du projet.
-- À exécuter UNE fois avec un superutilisateur, par exemple :
--
--   psql -U postgres -h localhost -v app_password="'<mot de passe de DB_PASSWORD>'" -f database/setup/create_database.sql
--
-- L'application se connecte ensuite avec cet utilisateur dédié, jamais avec « postgres ».

\set ON_ERROR_STOP on

SELECT format('CREATE ROLE hairstyle_app LOGIN PASSWORD %s', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'hairstyle_app') \gexec

SELECT format('ALTER ROLE hairstyle_app PASSWORD %s', :'app_password') \gexec

SELECT 'CREATE DATABASE hairstyle_garance OWNER hairstyle_app ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'hairstyle_garance') \gexec

SELECT 'CREATE DATABASE hairstyle_garance_test OWNER hairstyle_app ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'hairstyle_garance_test') \gexec

-- Extensions requises (leur création demande des droits élevés : faite ici par le superutilisateur).
\connect hairstyle_garance
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;

\connect hairstyle_garance_test
CREATE EXTENSION IF NOT EXISTS btree_gist;
CREATE EXTENSION IF NOT EXISTS citext;
