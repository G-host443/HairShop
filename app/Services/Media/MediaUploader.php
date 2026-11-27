<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Config;
use App\Core\Http\UploadedFile;
use App\Models\Media;
use App\Repositories\MediaRepository;
use finfo;
use RuntimeException;
use Throwable;

/**
 * Importation d'un fichier dans la bibliothèque.
 *
 * Contrôles : type réel détecté par le contenu (finfo), liste blanche, taille, dimensions.
 * Fichiers enregistrés sous un nom aléatoire dans uploads/AAAA/MM/ (jamais le nom fourni).
 */
final class MediaUploader
{
    public function __construct(
        private readonly Config $config,
        private readonly MediaRepository $media,
        private readonly ImageProcessor $images,
    ) {
    }

    /**
     * @throws MediaException message destiné à l'utilisateur
     */
    public function upload(UploadedFile $file, ?int $folderId, ?int $userId, string $altText = ''): Media
    {
        if (!$file->isValid()) {
            throw new MediaException($file->errorMessage());
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file->tmpPath) ?: 'application/octet-stream';
        $allowed = (array) $this->config->get('media.allowed', []);
        if (!isset($allowed[$mime])) {
            throw new MediaException('Format non accepté. Formats autorisés : JPEG, PNG, WebP, GIF, AVIF, MP4, WebM.');
        }

        $isImage = str_starts_with($mime, 'image/');
        $maxBytes = (int) $this->config->get($isImage ? 'media.max_image_bytes' : 'media.max_video_bytes');
        if ($file->size > $maxBytes) {
            throw new MediaException(sprintf('Fichier trop lourd (maximum %d Mo).', intdiv($maxBytes, 1024 * 1024)));
        }

        $originalName = $this->cleanName($file->clientName);
        $relativeDir = date('Y') . '/' . date('m');
        $basename = bin2hex(random_bytes(12));

        return $isImage
            ? $this->storeImage($file, $mime, $allowed[$mime], $relativeDir, $basename, $originalName, $folderId, $userId, $altText)
            : $this->storeVideo($file, $mime, $allowed[$mime], $relativeDir, $basename, $originalName, $folderId, $userId, $altText);
    }

    private function storeImage(
        UploadedFile $file,
        string $mime,
        string $extension,
        string $relativeDir,
        string $basename,
        string $originalName,
        ?int $folderId,
        ?int $userId,
        string $altText,
    ): Media {
        try {
            $info = $this->images->inspect($file->tmpPath);
        } catch (RuntimeException $e) {
            throw new MediaException($e->getMessage());
        }

        $maxPixels = (int) $this->config->get('media.max_megapixels', 40) * 1_000_000;
        if ($info['width'] * $info['height'] > $maxPixels) {
            throw new MediaException(sprintf('Image trop grande (%d × %d px). Réduisez-la sous %d mégapixels.', $info['width'], $info['height'], $maxPixels / 1_000_000));
        }

        $root = $this->uploadRoot();
        $written = [];

        try {
            $image = $this->images->load($file->tmpPath, $mime);

            // Les GIF animés perdraient leur animation en passant par GD : on les garde tels quels.
            $keepAsIs = $mime === 'image/gif' && $this->isAnimatedGif($file->tmpPath);

            $originalPath = "{$relativeDir}/{$basename}.{$extension}";
            if ($keepAsIs) {
                $file->moveTo($root . '/' . $originalPath);
            } else {
                $original = $this->images->resizeToWidth($image, (int) $this->config->get('media.original_max_width', 2560));
                $this->images->save($original, $root . '/' . $originalPath, $extension, (int) $this->config->get('media.quality.jpeg', 85));
                $image = $original;
            }
            $written[] = $originalPath;

            $variants = [];
            foreach ((array) $this->config->get('media.variants', []) as $name => $width) {
                // Pas de déclinaison plus grande que l'image elle-même (sauf la miniature, toujours utile).
                if ($name !== 'thumb' && imagesx($image) <= (int) $width) {
                    continue;
                }
                $variantPath = "{$relativeDir}/{$basename}-{$name}.webp";
                $this->images->save(
                    $this->images->resizeToWidth($image, (int) $width),
                    $root . '/' . $variantPath,
                    'webp',
                    (int) $this->config->get('media.quality.webp', 80),
                );
                $variants[$name] = $variantPath;
                $written[] = $variantPath;
            }

            return $this->media->create(
                $folderId,
                $originalPath,
                $originalName,
                $mime,
                (int) filesize($root . '/' . $originalPath),
                imagesx($image),
                imagesy($image),
                $altText,
                $variants,
                $userId,
            );
        } catch (Throwable $e) {
            $this->removeFiles($written);
            throw $e instanceof MediaException ? $e : new MediaException('Le traitement de l\'image a échoué : ' . $e->getMessage(), $e);
        }
    }

    private function storeVideo(
        UploadedFile $file,
        string $mime,
        string $extension,
        string $relativeDir,
        string $basename,
        string $originalName,
        ?int $folderId,
        ?int $userId,
        string $altText,
    ): Media {
        $path = "{$relativeDir}/{$basename}.{$extension}";

        try {
            $file->moveTo($this->uploadRoot() . '/' . $path);

            return $this->media->create($folderId, $path, $originalName, $mime, $file->size, null, null, $altText, [], $userId);
        } catch (Throwable $e) {
            $this->removeFiles([$path]);
            throw $e instanceof MediaException ? $e : new MediaException('L\'enregistrement de la vidéo a échoué.', $e);
        }
    }

    /** @param list<string> $relativePaths */
    public function removeFiles(array $relativePaths): void
    {
        $root = $this->uploadRoot();
        foreach ($relativePaths as $relative) {
            $path = $root . '/' . ltrim($relative, '/');
            // Garde-fou : ne jamais supprimer en dehors du dossier des envois.
            $real = realpath($path);
            if ($real !== false && str_starts_with($real, realpath($root) ?: $root) && is_file($real)) {
                @unlink($real);
            }
        }
    }

    private function uploadRoot(): string
    {
        return rtrim((string) $this->config->get('media.upload_path'), '/\\');
    }

    /** Nom affiché : sans chemin, sans caractères de contrôle, 150 caractères maximum. */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim(mb_substr($name, 0, 150));

        return $name !== '' ? $name : 'fichier';
    }

    private function isAnimatedGif(string $path): bool
    {
        $content = (string) file_get_contents($path, false, null, 0, 1024 * 1024);

        return preg_match_all('/\x00\x21\xF9\x04.{4}\x00[\x2C\x21]/s', $content) > 1;
    }
}
