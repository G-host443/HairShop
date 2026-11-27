-- Lien « gérer mon rendez-vous » : en plus de l'empreinte (recherche), le jeton est conservé chiffré
-- avec la clé de l'application (APP_KEY) pour pouvoir le remettre dans chaque e-mail (confirmation, rappel…).
-- Sans la clé, une copie de la base ne permet pas de reconstituer les liens.

-- migrate:up
ALTER TABLE bookings ADD COLUMN manage_token_encrypted text;

-- migrate:down
ALTER TABLE bookings DROP COLUMN manage_token_encrypted;
