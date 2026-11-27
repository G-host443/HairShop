<?php

declare(strict_types=1);

namespace App\Services\Media;

use GdImage;
use RuntimeException;

/**
 * Traitement d'images avec GD.
 *
 * Toute image est décodée puis ré-encodée : les métadonnées (EXIF, GPS du téléphone…) disparaissent
 * et un fichier piégé (contenu caché derrière une image) ne survit pas à l'opération.
 */
final class ImageProcessor
{
    /**
     * @return array{width: int, height: int, mime: string}
     */
    public function inspect(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('Ce fichier n\'est pas une image lisible.');
        }

        return ['width' => (int) $info[0], 'height' => (int) $info[1], 'mime' => (string) $info['mime']];
    }

    public function load(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => false,
        };

        if (!$image instanceof GdImage) {
            throw new RuntimeException('Impossible de lire cette image (fichier corrompu ou format non pris en charge).');
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $mime === 'image/jpeg' ? $this->applyExifOrientation($image, $path) : $image;
    }

    /**
     * Redimensionne sans agrandir, en conservant les proportions et la transparence.
     */
    public function resizeToWidth(GdImage $image, int $maxWidth): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= $maxWidth) {
            return $image;
        }

        $newHeight = max(1, (int) round($height * $maxWidth / $width));
        $resized = imagecreatetruecolor($maxWidth, $newHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);

        return $resized;
    }

    public function save(GdImage $image, string $path, string $format, int $quality): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Impossible de créer le dossier {$directory}.");
        }

        $ok = match ($format) {
            'webp' => imagewebp($image, $path, $quality),
            'jpg' => imagejpeg($this->flattenOnWhite($image), $path, $quality),
            'png' => imagepng($image, $path, 6),
            'gif' => imagegif($image, $path),
            'avif' => function_exists('imageavif') && imageavif($image, $path, $quality),
            default => throw new RuntimeException("Format de sortie inconnu : {$format}"),
        };

        if (!$ok) {
            throw new RuntimeException("Échec de l'enregistrement de l'image {$path}.");
        }
    }

    /**
     * Les photos de téléphone sont souvent enregistrées couchées, avec une indication de rotation dans l'EXIF.
     */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            // Orientations miroir (rares) : retournement puis rotation éventuelle.
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
            $rotated = match ($orientation) {
                4 => imagerotate($rotated, 180, 0),
                5 => imagerotate($rotated, 90, 0),
                7 => imagerotate($rotated, -90, 0),
                default => $rotated,
            };
        }

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /** Le JPEG ne gère pas la transparence : on pose l'image sur un fond blanc. */
    private function flattenOnWhite(GdImage $image): GdImage
    {
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $flat;
    }
}
