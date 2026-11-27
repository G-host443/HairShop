<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/**
 * Fichier reçu dans un formulaire multipart.
 *
 * Seuls les fichiers réellement envoyés par HTTP (is_uploaded_file) peuvent être déplacés,
 * sauf ceux créés explicitement par UploadedFile::forTesting().
 */
final class UploadedFile
{
    private bool $moved = false;

    private function __construct(
        public readonly string $clientName,
        public readonly string $tmpPath,
        public readonly int $size,
        public readonly int $error,
        private readonly bool $trusted,
    ) {
    }

    /**
     * Normalise une entrée de $_FILES (simple ou multiple : name="fichiers[]").
     *
     * @param array<string, mixed>|null $entry
     * @return list<self>
     */
    public static function fromFilesEntry(?array $entry): array
    {
        if ($entry === null || !isset($entry['name'])) {
            return [];
        }

        if (!is_array($entry['name'])) {
            return [new self((string) $entry['name'], (string) $entry['tmp_name'], (int) $entry['size'], (int) $entry['error'], false)];
        }

        $files = [];
        foreach (array_keys($entry['name']) as $i) {
            if ((int) $entry['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = new self((string) $entry['name'][$i], (string) $entry['tmp_name'][$i], (int) $entry['size'][$i], (int) $entry['error'][$i], false);
        }

        return $files;
    }

    /** Fichier local présenté comme un envoi (tests uniquement). */
    public static function forTesting(string $path, string $clientName, int $error = UPLOAD_ERR_OK): self
    {
        return new self($clientName, $path, is_file($path) ? (int) filesize($path) : 0, $error, true);
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && ($this->trusted || is_uploaded_file($this->tmpPath));
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée par le serveur.',
            UPLOAD_ERR_PARTIAL => 'Le fichier n\'a été reçu que partiellement. Réessayez.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier reçu.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Le serveur n\'a pas pu enregistrer le fichier.',
            default => 'Fichier invalide.',
        };
    }

    public function moveTo(string $destination): void
    {
        if ($this->moved) {
            throw new RuntimeException('Le fichier a déjà été déplacé.');
        }

        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Impossible de créer le dossier {$directory}.");
        }

        $ok = $this->trusted ? copy($this->tmpPath, $destination) : move_uploaded_file($this->tmpPath, $destination);
        if (!$ok) {
            throw new RuntimeException('Impossible d\'enregistrer le fichier envoyé.');
        }

        $this->moved = true;
    }
}
