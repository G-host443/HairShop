<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Config;
use App\Core\Http\UploadedFile;
use DI\Container;
use Tests\Support\Browser;

/**
 * Bibliothèque de médias : import, contrôles de sécurité, déclinaisons, suppression, dossiers.
 * Les fichiers sont écrits dans un dossier temporaire, jamais dans public/uploads.
 */
final class AdminMediaTest extends AdminTestCase
{
    private string $uploadDir;
    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploadDir = sys_get_temp_dir() . '/hsg-uploads-' . bin2hex(random_bytes(4));
        $this->fixtures = sys_get_temp_dir() . '/hsg-fixtures-' . bin2hex(random_bytes(4));
        mkdir($this->uploadDir, 0777, true);
        mkdir($this->fixtures, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ([$this->uploadDir, $this->fixtures] as $dir) {
            $this->removeDirectory($dir);
        }
    }

    private function admin(): Browser
    {
        $uploadDir = $this->uploadDir;

        return $this->loggedIn()->configure(static function (Container $c) use ($uploadDir): void {
            $c->get(Config::class)->set('media.upload_path', $uploadDir);
        });
    }

    private function png(int $width, int $height, string $name = 'photo.png'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 223, 176, 94));
        $path = $this->fixtures . '/' . bin2hex(random_bytes(4)) . '.png';
        imagepng($image, $path);

        return UploadedFile::forTesting($path, $name);
    }

    private function fakeFile(string $content, string $name): UploadedFile
    {
        $path = $this->fixtures . '/' . bin2hex(random_bytes(4)) . '.bin';
        file_put_contents($path, $content);

        return UploadedFile::forTesting($path, $name);
    }

    /** @return array<string, mixed>|null */
    private function lastMedia(): ?array
    {
        return $this->db->fetchOne('SELECT *, variants::text AS variants_json FROM media ORDER BY id DESC LIMIT 1');
    }

    public function testLargeImageIsResizedAndGetsWebpVariants(): void
    {
        $browser = $this->admin();

        $response = $browser->upload('/admin/medias', [$this->png(3000, 2000, 'Knotless mi-dos.png')]);

        self::assertSame(303, $response->status());
        self::assertSame('1 fichier importé.', $browser->flash('success'));

        $media = $this->lastMedia();
        self::assertSame('Knotless mi-dos.png', $media['original_name']);
        self::assertSame('image/png', $media['mime_type']);
        self::assertSame(2560, (int) $media['width'], 'Original limité à 2560 px de large.');
        self::assertSame(1707, (int) $media['height']);
        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{24}\.png$#', $media['disk_path'], 'Nom de fichier aléatoire.');
        self::assertFileExists($this->uploadDir . '/' . $media['disk_path']);

        $variants = json_decode($media['variants_json'], true);
        self::assertEqualsCanonicalizing(['thumb', 'medium', 'large'], array_keys($variants), 'JSONB ne conserve pas l\'ordre des clés.');
        foreach ($variants as $name => $path) {
            $file = $this->uploadDir . '/' . $path;
            self::assertFileExists($file);
            $info = getimagesize($file);
            self::assertSame('image/webp', $info['mime']);
            self::assertSame(['thumb' => 400, 'medium' => 1024, 'large' => 1920][$name], $info[0]);
        }
    }

    public function testSmallImageIsNotUpscaled(): void
    {
        $browser = $this->admin();
        $browser->upload('/admin/medias', [$this->png(300, 300)]);

        $media = $this->lastMedia();
        self::assertSame(300, (int) $media['width']);
        $variants = json_decode($media['variants_json'], true);
        self::assertSame(['thumb'], array_keys($variants), 'Seule la miniature est produite.');
        self::assertSame(300, getimagesize($this->uploadDir . '/' . $variants['thumb'])[0]);
    }

    public function testDisguisedScriptIsRejected(): void
    {
        $browser = $this->admin();

        $browser->upload('/admin/medias', [$this->fakeFile("<?php system(\$_GET['c']); ?>", 'photo.jpg')]);

        self::assertStringContainsString('Format non accepté', (string) $browser->flash('error'));
        self::assertNull($this->lastMedia());
        self::assertSame([], glob($this->uploadDir . '/*/*/*') ?: []);
    }

    public function testSvgIsRejected(): void
    {
        $browser = $this->admin();
        $browser->upload('/admin/medias', [$this->fakeFile('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'logo.svg')]);

        self::assertStringContainsString('Format non accepté', (string) $browser->flash('error'));
        self::assertNull($this->lastMedia());
    }

    public function testCorruptedImageIsRejected(): void
    {
        $browser = $this->admin();
        // En-tête PNG valide suivi de données invalides.
        $browser->upload('/admin/medias', [$this->fakeFile("\x89PNG\r\n\x1a\n" . str_repeat('x', 200), 'casse.png')]);

        self::assertNotNull($browser->flash('error'));
        self::assertNull($this->lastMedia());
    }

    public function testUploadErrorFromPhpIsReported(): void
    {
        $browser = $this->admin();
        $file = UploadedFile::forTesting($this->fixtures . '/absent', 'gros.jpg', UPLOAD_ERR_INI_SIZE);

        $browser->upload('/admin/medias', [$file]);

        self::assertStringContainsString('taille maximale', (string) $browser->flash('error'));
    }

    public function testJsonUploadReturnsCreatedItems(): void
    {
        $browser = $this->admin();

        $response = $browser->upload('/admin/medias', [$this->png(800, 600), $this->fakeFile('texte', 'notes.txt')], [], json: true);
        $body = json_decode($response->content(), true);

        self::assertSame(200, $response->status());
        self::assertCount(1, $body['created']);
        self::assertSame(800, $body['created'][0]['width']);
        self::assertCount(1, $body['errors']);
        self::assertSame('notes.txt', $body['errors'][0]['file']);
    }

    public function testEditAndDeleteRemovesFiles(): void
    {
        $browser = $this->admin();
        $browser->upload('/admin/medias', [$this->png(1200, 800)]);
        $media = $this->lastMedia();
        $id = (int) $media['id'];

        self::assertSame(200, $browser->get("/admin/medias/{$id}")->status());

        $browser->post("/admin/medias/{$id}", ['original_name' => 'Bohemian', 'alt_text' => 'Bohemian taille M', 'folder_id' => '']);
        self::assertSame('Bohemian taille M', $this->db->fetchValue('SELECT alt_text FROM media WHERE id = :id', ['id' => $id]));

        $files = array_merge([$media['disk_path']], array_values(json_decode($media['variants_json'], true)));
        $browser->post("/admin/medias/{$id}/supprimer");

        self::assertNull($this->db->fetchValue('SELECT id FROM media WHERE id = :id', ['id' => $id]));
        foreach ($files as $path) {
            self::assertFileDoesNotExist($this->uploadDir . '/' . $path);
        }
    }

    public function testMediaUsedAsLogoCannotBeDeleted(): void
    {
        $browser = $this->admin();
        $browser->upload('/admin/medias', [$this->png(500, 500)]);
        $id = (int) $this->lastMedia()['id'];
        $this->db->execute("UPDATE site_settings SET value = CAST(:v AS jsonb) WHERE key = 'general.logo_media_id'", ['v' => (string) $id]);

        $browser->post("/admin/medias/{$id}/supprimer");

        self::assertStringContainsString('Logo du site', (string) $browser->flash('error'));
        self::assertNotNull($this->db->fetchValue('SELECT id FROM media WHERE id = :id', ['id' => $id]));
    }

    public function testFoldersCanBeNestedRenamedAndDeleted(): void
    {
        $browser = $this->admin();

        $browser->post('/admin/medias/dossiers', ['name' => 'Réalisations']);
        $parent = (int) $this->db->fetchValue("SELECT id FROM media_folders WHERE name = 'Réalisations'");
        $browser->post('/admin/medias/dossiers', ['name' => 'Knotless', 'parent_id' => (string) $parent]);
        $child = (int) $this->db->fetchValue("SELECT id FROM media_folders WHERE name = 'Knotless'");

        // Doublon refusé (insensible à la casse).
        $browser->post('/admin/medias/dossiers', ['name' => 'réalisations']);
        self::assertArrayHasKey('name', $browser->errors());

        // Un dossier ne peut pas être déplacé dans son propre sous-dossier.
        $browser->post("/admin/medias/dossiers/{$parent}", ['name' => 'Réalisations', 'parent_id' => (string) $child]);
        self::assertArrayHasKey('parent_id', $browser->errors());

        // Import dans le sous-dossier, puis suppression du parent : le média est conservé sans dossier.
        $browser->upload('/admin/medias', [$this->png(400, 400)], ['folder_id' => (string) $child]);
        $mediaId = (int) $this->lastMedia()['id'];
        self::assertSame($child, (int) $this->lastMedia()['folder_id']);

        $browser->post("/admin/medias/dossiers/{$parent}/supprimer");

        self::assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM media_folders'));
        self::assertNull($this->db->fetchValue('SELECT folder_id FROM media WHERE id = :id', ['id' => $mediaId]));
    }

    public function testLibraryListingFiltersAndApi(): void
    {
        $browser = $this->admin();
        $browser->upload('/admin/medias', [$this->png(600, 400, 'fulani.png'), $this->png(600, 400, 'bantu.png')]);

        $page = $browser->get('/admin/medias?q=fulani');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('fulani.png', $page->content());
        self::assertStringNotContainsString('bantu.png', $page->content());

        $api = json_decode($browser->getJson('/api/admin/medias?type=image')->content(), true);
        self::assertSame(2, $api['total']);
        self::assertStringStartsWith('/uploads/', $api['items'][0]['thumb']);
    }

    public function testApiRequiresAuthentication(): void
    {
        $response = $this->browser()->getJson('/api/admin/medias');

        self::assertSame(401, $response->status());
    }

    public function testStaffCannotManageMedia(): void
    {
        self::assertSame(403, $this->loggedIn('staff', 'collab@example.test')->get('/admin/medias')->status());
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
