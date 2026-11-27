<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeding\Seeder;
use App\Helpers\HtmlSanitizer;

/**
 * Contenu de démonstration (avec « db:seed --demo ») pour présenter le site complet :
 * images d'exemple, galerie, articles de blog, FAQ en brouillon, promotion désactivée, avis d'exemple.
 *
 * Règles :
 * - tout est repérable et supprimable d'un coup avec « php bin/console demo:clear » ;
 * - les avis d'exemple restent « à modérer » : de faux avis ne doivent jamais apparaître sur le site ;
 * - la promotion d'exemple est désactivée : aucune fausse remise n'est proposée ;
 * - aucun rendez-vous ni aucune cliente fictive : ils bloqueraient de vrais créneaux.
 */
final class DemoContentSeeder extends Seeder
{
    public const MEDIA_PREFIX = 'demo/';
    public const REVIEW_PREFIX = 'Exemple — ';
    public const PROMOTION_PREFIX = 'Exemple : ';
    public const BLOG_SLUGS = ['entretenir-ses-tresses-6-semaines', 'preparer-ses-cheveux-avant-la-pose', 'choisir-taille-et-longueur'];
    public const BLOG_CATEGORIES = ['entretien' => 'Entretien', 'preparer-sa-pose' => 'Préparer sa pose'];

    /** Teintes des images d'exemple : [fond, mèches] */
    private const PALETTE = [
        [[223, 176, 94], [246, 222, 170]], [[90, 62, 48], [150, 112, 90]], [[128, 98, 23], [196, 160, 80]],
        [[60, 45, 38], [118, 88, 70]], [[180, 120, 80], [230, 186, 140]], [[40, 30, 26], [96, 74, 60]],
        [[150, 95, 60], [210, 160, 110]], [[205, 150, 110], [242, 210, 175]], [[70, 52, 44], [130, 100, 84]],
        [[232, 196, 140], [250, 232, 198]],
    ];

    public function run(): void
    {
        $media = $this->images();
        $this->gallery($media);
        $this->blog($media);
        $this->reviews();
        $this->promotion();
        $this->faq();
    }

    /** @return list<int> identifiants des images d'exemple */
    private function images(): array
    {
        $dir = public_path('uploads/' . rtrim(self::MEDIA_PREFIX, '/'));
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $ids = [];
        foreach (self::PALETTE as $i => [$bg, $strand]) {
            $name = sprintf('exemple-%02d', $i + 1);
            $path = self::MEDIA_PREFIX . $name . '.jpg';
            $existing = $this->db->fetchValue('SELECT id FROM media WHERE disk_path = :p', ['p' => $path]);
            $filesPresent = is_file("{$dir}/{$name}.jpg") && is_file("{$dir}/{$name}-medium.webp");
            if ($existing !== null && $filesPresent) {
                $ids[] = (int) $existing;
                continue;
            }

            // Fichiers dessinés (ou redessinés s'ils ont disparu du disque alors que la fiche existe).
            [$width, $height] = $i % 3 === 0 ? [1200, 1500] : [1200, 1200];
            $image = self::drawBraids($width, $height, $bg, $strand, $i);
            imagejpeg($image, "{$dir}/{$name}.jpg", 85);
            $variants = [];
            foreach (['thumb' => 400, 'medium' => 1024, 'large' => 1600] as $variant => $max) {
                $scaled = imagescale($image, min($max, $width));
                imagewebp($scaled, "{$dir}/{$name}-{$variant}.webp", 80);
                $variants[$variant] = self::MEDIA_PREFIX . "{$name}-{$variant}.webp";
            }
            if ($existing !== null) {
                $ids[] = (int) $existing;
                continue;
            }

            $ids[] = (int) $this->db->fetchValue(
                "INSERT INTO media (disk_path, original_name, mime_type, size_bytes, width, height, alt_text, variants)
                 VALUES (:p, :n, 'image/jpeg', :size, :w, :h, :alt, CAST(:v AS jsonb)) RETURNING id",
                [
                    'p' => $path, 'n' => $name . '.jpg', 'size' => (int) filesize("{$dir}/{$name}.jpg"), 'w' => $width, 'h' => $height,
                    'alt' => 'Image de démonstration (motif de tresses) — à remplacer par une vraie photo',
                    'v' => json_encode($variants, JSON_THROW_ON_ERROR),
                ],
            );
        }

        return $ids;
    }

    /** Motif abstrait évoquant des tresses, avec la mention « démonstration ». */
    private static function drawBraids(int $width, int $height, array $bg, array $strand, int $seed): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            $t = $y / $height;
            $color = imagecolorallocate($image, (int) ($bg[0] * (1 - .25 * $t)), (int) ($bg[1] * (1 - .25 * $t)), (int) ($bg[2] * (1 - .25 * $t)));
            imageline($image, 0, $y, $width, $y, $color);
        }
        $light = imagecolorallocatealpha($image, $strand[0], $strand[1], $strand[2], 40);
        $columns = 6 + $seed % 3;
        $step = (int) ($width / ($columns + 1));
        for ($c = 1; $c <= $columns; $c++) {
            $x = $c * $step;
            for ($y = -40; $y < $height + 40; $y += 46) {
                $offset = intdiv($y + 46, 46) % 2 === 0 ? -9 : 9;
                imagefilledellipse($image, $x + $offset, $y, (int) ($step * .42), 70, $light);
            }
        }
        $label = imagecolorallocatealpha($image, 255, 255, 255, 30);
        imagestring($image, 5, 24, $height - 40, 'Photo de demonstration', $label);

        return $image;
    }

    /** @param list<int> $media */
    private function gallery(array $media): void
    {
        $items = [
            ['Knotless mi-dos', 'knotless', 'mi-dos, perles', true, 'Pose d\'environ 6 h, tresses fines et légères à la racine.'],
            ['Box twist épaules', 'box-twist', 'épaules', true, null],
            ['Bohemian longueur taille', 'bohemian', 'taille, bouclé', true, 'Mèches bouclées laissées libres le long des tresses.'],
            ['Fulani avec perles', 'fulanis', 'perles', false, null],
            ['Knotless twist', 'knotless-twist', 'mi-dos', false, null],
            ['Nattes collées', 'nattes-collees-s', 'nattes', false, null],
            ['Faux locs', 'faux-locs-classiques', 'locs', false, null],
            ['Coiffure enfant', 'enfants-nattes-collees', 'enfant', false, 'Nattes protectrices pour une petite fille, avec perles colorées.'],
        ];
        foreach ($items as $i => [$title, $serviceSlug, $tags, $featured, $description]) {
            if ($this->db->fetchValue('SELECT 1 FROM gallery_items WHERE media_id = :m', ['m' => $media[$i]]) !== null) {
                continue;
            }
            $service = $serviceSlug !== null ? $this->db->fetchOne('SELECT id, category_id FROM services WHERE slug = :s', ['s' => $serviceSlug]) : null;
            $this->db->execute(
                'INSERT INTO gallery_items (media_id, after_media_id, title, description, category_id, service_id, tags, is_featured, is_published, position)
                 VALUES (:m, :a, :t, :d, :c, :s, CAST(:tags AS text[]), :f, true, :p)',
                [
                    'm' => $media[$i], 'a' => $i === 2 ? $media[9] : null, 't' => $title, 'd' => $description,
                    'c' => $service['category_id'] ?? null, 's' => $service['id'] ?? null,
                    'tags' => '{' . implode(',', array_map(static fn (string $t): string => '"' . trim($t) . '"', explode(',', $tags))) . '}',
                    'f' => $featured, 'p' => $i,
                ],
            );
        }
    }

    /** @param list<int> $media */
    private function blog(array $media): void
    {
        foreach (self::BLOG_CATEGORIES as $slug => $name) {
            $this->db->execute(
                'INSERT INTO blog_categories (name, slug, position) VALUES (:n, :s, (SELECT COALESCE(max(position), -1) + 1 FROM blog_categories)) ON CONFLICT (slug) DO NOTHING',
                ['n' => $name, 's' => $slug],
            );
        }

        $posts = [
            [self::BLOG_SLUGS[0], 'Entretenir ses tresses : la routine des six semaines', 'entretien', 6,
                'Quelques gestes simples suffisent pour garder des tresses nettes et un cuir chevelu en bonne santé jusqu\'à la dépose.',
                '<p>Des tresses bien entretenues tiennent plus longtemps et protègent réellement vos cheveux. Voici une routine simple, semaine après semaine.</p>'
                . '<h2>Chaque soir</h2><ul><li>Protégez vos tresses avec un foulard ou un bonnet en satin.</li><li>Rassemblez-les en chignon lâche pour éviter les frottements.</li></ul>'
                . '<h2>Chaque semaine</h2><p>Hydratez le cuir chevelu avec un spray léger, deux à trois fois par semaine. <strong>Évitez les huiles lourdes</strong> : elles s\'accumulent à la racine.</p>'
                . '<h2>Toutes les deux semaines</h2><p>Un shampoing doux, dilué et appliqué directement sur le cuir chevelu, suffit. Séchez soigneusement les longueurs pour éviter les odeurs.</p>'
                . '<blockquote>Au-delà de six à huit semaines, les racines s\'abîment : prévoyez la dépose à temps.</blockquote>'],
            [self::BLOG_SLUGS[1], 'Préparer ses cheveux avant la pose', 'preparer-sa-pose', 3,
                'Des cheveux propres, démêlés et bien hydratés : la pose est plus rapide, plus confortable et tient mieux.',
                '<p>Une bonne préparation change tout : la pose est plus rapide, plus confortable, et le résultat dure plus longtemps.</p>'
                . '<h2>La veille</h2><ul><li>Lavez vos cheveux et appliquez un soin hydratant.</li><li>Démêlez soigneusement, des pointes vers les racines.</li><li>Séchez complètement : des cheveux humides sous les tresses favorisent les odeurs.</li></ul>'
                . '<h2>Le jour du rendez-vous</h2><p>Venez sans produit gras ni huile sur le cuir chevelu, et prévoyez de quoi patienter : une pose de tresses dure plusieurs heures.</p>'
                . '<p>Une question sur votre type de cheveux ? <a href="/contact">Écrivez-moi</a> avant de réserver.</p>'],
            [self::BLOG_SLUGS[2], 'Quelle taille et quelle longueur choisir ?', 'preparer-sa-pose', 1,
                'Fines ou épaisses, aux épaules ou au bas du dos : comment choisir selon votre style, votre budget et le temps de pose.',
                '<p>Le prix et la durée d\'une coiffure dépendent de deux choix : la <strong>taille</strong> des tresses et leur <strong>longueur</strong>.</p>'
                . '<h2>La taille</h2><ul><li><strong>S</strong> : tresses fines, très naturelles, mais une pose plus longue.</li><li><strong>M</strong> : le bon équilibre entre finesse et durée.</li><li><strong>L et XL</strong> : tresses épaisses, rapides à poser, au rendu graphique.</li></ul>'
                . '<h2>La longueur</h2><p>Des épaules au coccyx, plus la longueur augmente, plus il faut de mèches et de temps. Les très grandes longueurs se font sur devis.</p>'
                . '<p>Le sélecteur de la <a href="/prestations">page des prestations</a> affiche le prix et la durée pour chaque combinaison.</p>'],
        ];

        foreach ($posts as $i => [$slug, $title, $category, $daysAgo, $excerpt, $content]) {
            $this->db->execute(
                "INSERT INTO blog_posts (category_id, title, slug, excerpt, content, cover_media_id, status, published_at)
                 VALUES ((SELECT id FROM blog_categories WHERE slug = :c), :t, :s, :e, :content, :cover, 'published', now() - make_interval(days => :d))
                 ON CONFLICT (slug) DO NOTHING",
                ['c' => $category, 't' => $title, 's' => $slug, 'e' => $excerpt, 'content' => HtmlSanitizer::clean($content), 'cover' => $media[$i + 4] ?? null, 'd' => $daysAgo],
            );
        }
    }

    private function reviews(): void
    {
        $reviews = [
            ['Awa D.', 5, 'Coiffure magnifique et très soignée, je recommande.'],
            ['Fatou K.', 5, 'Des knotless légères qui ont tenu plus de six semaines.'],
            ['Mariam S.', 4, 'Très bon moment, résultat superbe ; la pose est longue mais ça vaut le coup.'],
        ];
        foreach ($reviews as [$author, $rating, $comment]) {
            $name = self::REVIEW_PREFIX . $author;
            if ($this->db->fetchValue('SELECT 1 FROM reviews WHERE author_name = :a', ['a' => $name]) !== null) {
                continue;
            }
            $this->db->execute(
                "INSERT INTO reviews (author_name, rating, comment, status) VALUES (:a, :r, :c, 'pending')",
                ['a' => $name, 'r' => $rating, 'c' => '(Avis d\'exemple pour découvrir la modération : à refuser ou supprimer.) ' . $comment],
            );
        }
    }

    private function promotion(): void
    {
        $name = self::PROMOTION_PREFIX . '−10 % sur les knotless';
        if ($this->db->fetchValue('SELECT 1 FROM promotions WHERE name = :n', ['n' => $name]) !== null) {
            return;
        }
        $id = (int) $this->db->fetchValue(
            "INSERT INTO promotions (name, description, discount_type, value, starts_at, ends_at, is_active)
             VALUES (:n, 'Promotion d''exemple, désactivée : modifiez-la ou supprimez-la.', 'percent', 10, date_trunc('day', now()), date_trunc('day', now()) + interval '31 days', false)
             RETURNING id",
            ['n' => $name],
        );
        $this->db->execute("INSERT INTO promotion_services (promotion_id, service_id) SELECT :p, id FROM services WHERE slug IN ('knotless', 'knotless-twist')", ['p' => $id]);
    }

    /** FAQ : contenu proposé dans la page « Questions fréquentes », laissée en brouillon. */
    private function faq(): void
    {
        $page = $this->db->fetchOne("SELECT id, status FROM pages WHERE slug = 'faq'");
        if ($page === null || $this->db->fetchValue('SELECT 1 FROM page_sections WHERE page_id = :p', ['p' => $page['id']]) !== null) {
            return;
        }
        $text = "## Combien de temps dure une pose ?\nDe 3 à 8 heures selon la taille et la longueur : la durée estimée s'affiche avant de réserver.\n\n"
            . "## Les mèches sont-elles fournies ?\nÀ préciser selon votre fonctionnement (fournies, ou à apporter).\n\n"
            . "## Comment préparer mes cheveux ?\nCheveux propres, démêlés et bien secs, sans produit gras. Voir l'article [Préparer ses cheveux avant la pose](/blog/preparer-ses-cheveux-avant-la-pose).\n\n"
            . "## Puis-je annuler mon rendez-vous ?\nOui, en ligne avec le lien reçu par e-mail, dans le délai indiqué dans les infos pratiques.";
        $this->db->execute(
            "INSERT INTO page_sections (page_id, type, variant, variant_template, position, draft_content)
             VALUES (:p, 'rich_text', 'prose', 'afro-natural', 0, CAST(:c AS jsonb))",
            ['p' => $page['id'], 'c' => json_encode(['title' => '', 'text' => $text, 'anchor' => null, 'nav_label' => ''], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        );
        $this->db->execute('UPDATE pages SET has_unpublished_changes = true WHERE id = :id', ['id' => $page['id']]);
    }
}
