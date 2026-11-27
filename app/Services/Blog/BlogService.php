<?php

declare(strict_types=1);

namespace App\Services\Blog;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Validation\ValidationException;
use App\Helpers\HtmlSanitizer;
use App\Helpers\Slug;
use App\Repositories\BlogRepository;
use App\Repositories\MediaRepository;
use App\Services\Audit\AuditLogger;
use DateTimeImmutable;

/**
 * Articles du blog : validation, nettoyage du contenu riche, tags, brouillon / publication / programmation.
 */
final class BlogService
{
    public const PUBLICATION_CHOICES = ['draft' => 'Brouillon', 'now' => 'Publier maintenant', 'schedule' => 'Programmer'];

    private const MAX_CONTENT = 100_000;
    private const MAX_TAGS = 10;

    public function __construct(
        private readonly Database $db,
        private readonly BlogRepository $blog,
        private readonly MediaRepository $media,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Crée ou met à jour un article.
     *
     * @param array<string, mixed>|null $post  article existant (null = création)
     * @param array<string, mixed>      $input
     * @return int identifiant de l'article
     * @throws ValidationException
     */
    public function save(?array $post, array $input, ?int $userId, ?Request $request = null): int
    {
        [$data, $tags] = $this->validate($post, $input);

        $id = $this->db->transaction(function () use ($post, $data, $tags, $userId): int {
            if ($post === null) {
                $id = $this->blog->create($data + ['author_id' => $userId]);
            } else {
                $id = (int) $post['id'];
                $this->blog->update($id, $data);
            }
            $this->blog->setPostTags($id, array_map(fn (array $t): int => $this->blog->tagId($t['name'], $t['slug']), $tags));

            return $id;
        });

        $this->audit->log($post === null ? 'blog.post_created' : 'blog.post_updated', $userId, $request, 'blog_post', $id, ['status' => $data['status']]);

        return $id;
    }

    /** Publie les articles programmés arrivés à échéance (tâche planifiée). @return list<array{id: int, title: string}> */
    public function publishScheduled(): array
    {
        return $this->blog->publishDue($this->clock->now()->format(DATE_ATOM));
    }

    /** Durée de lecture estimée (200 mots par minute, 1 minute au moins). */
    public static function readingMinutes(string $html): int
    {
        $words = preg_match_all('/[\p{L}\p{N}]+/u', HtmlSanitizer::toText($html));

        return max(1, (int) ceil(((int) $words) / 200));
    }

    /** Résumé affiché : le chapeau saisi, sinon le début du texte. */
    public static function excerpt(array $post, int $length = 180): string
    {
        $excerpt = trim((string) ($post['excerpt'] ?? ''));
        if ($excerpt !== '') {
            return $excerpt;
        }
        // Premier paragraphe non vide (un intertitre collé au texte suivant se lirait mal), sinon tout le texte.
        $content = (string) ($post['content'] ?? '');
        $source = $content;
        if (preg_match_all('#<p>(.*?)</p>#s', $content, $paragraphs)) {
            foreach ($paragraphs[1] as $paragraph) {
                if (trim(HtmlSanitizer::toText($paragraph)) !== '') {
                    $source = $paragraph;
                    break;
                }
            }
        }
        $text = preg_replace('/\s+/u', ' ', HtmlSanitizer::toText($source)) ?? '';

        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<array{name: string, slug: string}>}
     * @throws ValidationException
     */
    private function validate(?array $post, array $input): array
    {
        $errors = [];

        $title = trim(preg_replace('/\s+/u', ' ', (string) ($input['title'] ?? '')) ?? '');
        if ($title === '' || mb_strlen($title) > 150) {
            $errors['title'] = 'Indiquez un titre de 150 caractères au plus.';
        }

        $slug = Slug::make(trim((string) ($input['slug'] ?? '')) !== '' ? (string) $input['slug'] : $title, 100);
        if ($this->blog->slugExists($slug, $post !== null ? (int) $post['id'] : null)) {
            $errors['slug'] = "L'adresse « /blog/{$slug} » est déjà utilisée par un autre article.";
        }

        $excerpt = trim((string) ($input['excerpt'] ?? ''));
        if (mb_strlen($excerpt) > 300) {
            $errors['excerpt'] = 'Le chapeau ne doit pas dépasser 300 caractères.';
        }

        $content = HtmlSanitizer::clean((string) ($input['content'] ?? ''));
        if (mb_strlen($content) > self::MAX_CONTENT) {
            $errors['content'] = 'L\'article est trop long.';
        }

        $cover = filter_var($input['cover_media_id'] ?? '', FILTER_VALIDATE_INT);
        if ($cover !== false && !($this->media->find($cover)?->isImage() ?? false)) {
            $errors['cover_media_id'] = 'Image de couverture introuvable.';
        }

        $category = filter_var($input['category_id'] ?? '', FILTER_VALIDATE_INT);
        if ($category !== false && $this->blog->findCategory($category) === null) {
            $errors['category_id'] = 'Catégorie introuvable.';
        }

        $tags = [];
        foreach (preg_split('/[,;]/', (string) ($input['tags'] ?? '')) ?: [] as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 40) {
                $errors['tags'] = "Tag « {$name} » trop long (40 caractères au plus).";
                break;
            }
            $tagSlug = Slug::make($name, 60);
            $tags[$tagSlug] = ['name' => $name, 'slug' => $tagSlug];
        }
        if (count($tags) > self::MAX_TAGS) {
            $errors['tags'] = 'Pas plus de ' . self::MAX_TAGS . ' tags par article.';
        }

        $seoTitle = trim((string) ($input['seo_title'] ?? ''));
        $seoDescription = trim((string) ($input['seo_description'] ?? ''));
        if (mb_strlen($seoTitle) > 70) {
            $errors['seo_title'] = 'Le titre pour Google ne doit pas dépasser 70 caractères.';
        }
        if (mb_strlen($seoDescription) > 300) {
            $errors['seo_description'] = 'La description pour Google ne doit pas dépasser 300 caractères.';
        }

        // Publication
        $choice = (string) ($input['publication'] ?? 'draft');
        $now = $this->clock->now();
        $status = 'draft';
        $publishedAt = $post['published_at'] ?? null;
        if ($choice === 'now') {
            $status = 'published';
            // Un article déjà en ligne garde sa date ; un article programmé publié tout de suite prend la date du jour.
            $publishedAt = ($post !== null && $post['status'] === 'published' && $publishedAt !== null) ? $publishedAt : $now->format(DATE_ATOM);
        } elseif ($choice === 'schedule') {
            $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i', trim((string) ($input['publish_date'] ?? '')) . ' ' . trim((string) ($input['publish_time'] ?? '')), $this->clock->zone());
            if ($at === false) {
                $errors['publish_date'] = 'Indiquez la date et l\'heure de publication.';
            } elseif ($at <= $now) {
                $errors['publish_date'] = 'La date de publication programmée doit être dans le futur (sinon, choisissez « Publier maintenant »).';
            } else {
                $status = 'scheduled';
                $publishedAt = $at->format(DATE_ATOM);
            }
        } elseif ($choice !== 'draft') {
            $errors['publication'] = 'Choix de publication invalide.';
        }
        if ($status !== 'draft' && trim(HtmlSanitizer::toText($content)) === '' && !str_contains($content, '<img')) {
            $errors['content'] = 'Un article publié ou programmé doit avoir un contenu.';
        }
        if ($status === 'draft') {
            $publishedAt = null;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [[
            'category_id' => $category !== false ? $category : null,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $excerpt !== '' ? $excerpt : null,
            'content' => $content,
            'cover_media_id' => $cover !== false ? $cover : null,
            'status' => $status,
            'published_at' => $publishedAt,
            'seo_title' => $seoTitle !== '' ? $seoTitle : null,
            'seo_description' => $seoDescription !== '' ? $seoDescription : null,
        ], array_values($tags)];
    }
}
