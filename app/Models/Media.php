<?php

declare(strict_types=1);

namespace App\Models;

final class Media
{
    /**
     * @param array<string, string> $variants  nom de déclinaison => chemin relatif (thumb, medium, large)
     */
    public function __construct(
        public readonly int $id,
        public readonly ?int $folderId,
        public readonly string $diskPath,
        public readonly string $originalName,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly string $altText,
        public readonly array $variants,
        public readonly string $createdAt,
        private readonly string $baseUrl = '/uploads',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row, string $baseUrl = '/uploads'): self
    {
        return new self(
            (int) $row['id'],
            $row['folder_id'] !== null ? (int) $row['folder_id'] : null,
            (string) $row['disk_path'],
            (string) $row['original_name'],
            (string) $row['mime_type'],
            (int) $row['size_bytes'],
            $row['width'] !== null ? (int) $row['width'] : null,
            $row['height'] !== null ? (int) $row['height'] : null,
            (string) $row['alt_text'],
            json_decode((string) $row['variants'], true) ?: [],
            (string) $row['created_at'],
            $baseUrl,
        );
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mimeType, 'video/');
    }

    /**
     * URL publique. Si la déclinaison demandée n'existe pas (image trop petite, vidéo), renvoie la
     * plus proche disponible, puis l'original.
     */
    public function url(?string $variant = null): string
    {
        $path = $this->diskPath;

        if ($variant !== null && $this->variants !== []) {
            $order = ['thumb', 'medium', 'large'];
            $start = array_search($variant, $order, true);
            $candidates = $start === false ? [$variant] : array_slice($order, (int) $start);
            foreach ($candidates as $candidate) {
                if (isset($this->variants[$candidate])) {
                    $path = $this->variants[$candidate];
                    break;
                }
            }
        }

        return rtrim($this->baseUrl, '/') . '/' . str_replace('\\', '/', $path);
    }

    /** Tous les chemins physiques relatifs (original + déclinaisons). */
    public function allPaths(): array
    {
        return array_values(array_unique([$this->diskPath, ...array_values($this->variants)]));
    }

    public function humanSize(): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $size = (float) $this->sizeBytes;
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return number_format($size, $i === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$i];
    }

    /** @return array<string, mixed> représentation JSON pour l'API / le sélecteur */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->originalName,
            'alt' => $this->altText,
            'type' => $this->isImage() ? 'image' : 'video',
            'mime' => $this->mimeType,
            'width' => $this->width,
            'height' => $this->height,
            'size' => $this->humanSize(),
            'url' => $this->url(),
            'thumb' => $this->isImage() ? $this->url('thumb') : null,
            'medium' => $this->isImage() ? $this->url('medium') : null,
        ];
    }
}
