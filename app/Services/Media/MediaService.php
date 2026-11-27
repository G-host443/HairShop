<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Database;
use App\Models\Media;
use App\Repositories\MediaRepository;

/**
 * Opérations sur les médias existants : modification, suppression contrôlée.
 */
final class MediaService
{
    public function __construct(
        private readonly Database $db,
        private readonly MediaRepository $media,
        private readonly MediaUsageFinder $usage,
        private readonly MediaUploader $uploader,
    ) {
    }

    /**
     * Supprime le média et ses fichiers, sauf s'il est encore utilisé.
     *
     * @throws MediaException si le média est utilisé
     */
    public function delete(Media $media): void
    {
        $usages = $this->usage->usages($media->id);
        if ($usages !== []) {
            throw new MediaException('Ce média est encore utilisé : ' . implode(' ; ', $usages) . '. Remplacez-le avant de le supprimer.');
        }

        $this->db->transaction(fn () => $this->media->delete($media->id));

        // Les fichiers ne sont supprimés qu'une fois la suppression en base validée.
        $this->uploader->removeFiles($media->allPaths());
    }
}
