<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Services\Catalog\PricingException;
use App\Services\Catalog\PricingService;

/**
 * Calcul serveur du prix et de la durée : chaque choix est vérifié, rien n'est pris du navigateur.
 */
final class PricingServiceTest extends DatabaseTestCase
{
    use CatalogFixtures;

    private PricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pricing = self::$container->get(PricingService::class);
    }

    protected function database(): Database
    {
        return $this->db;
    }

    public function testMatrixQuoteUsesCellPriceAndDuration(): void
    {
        $f = $this->matrixService();

        $quote = $this->pricing->quote($f['service'], $f['variants']['M'], $f['lengths']['mi-dos'], [$this->optionId('Perles')]);

        self::assertSame('Knotless taille M — Mi-dos', $quote->label);
        self::assertSame('110.00', $quote->total());
        self::assertSame(380 + 15, $quote->durationMinutes, 'Durée de la cellule + 15 min de perles.');
    }

    public function testDurationFallsBackToVariantThenService(): void
    {
        $f = $this->matrixService();

        // M / épaules : pas de durée de cellule → durée de la variante M (300).
        self::assertSame(300, $this->pricing->quote($f['service'], $f['variants']['M'], $f['lengths']['epaules'])->durationMinutes);
        // S / classique : ni cellule ni variante → durée de la prestation (240).
        self::assertSame(240, $this->pricing->quote($f['service'], $f['variants']['S'], $f['lengths']['classique'])->durationMinutes);
    }

    public function testQuoteCellHasNoTotal(): void
    {
        $f = $this->matrixService();
        $quote = $this->pricing->quote($f['service'], $f['variants']['S'], $f['lengths']['classique']);

        self::assertTrue($quote->isQuote());
        self::assertNull($quote->total());
    }

    public function testLengthNotOfferedIsRejected(): void
    {
        $f = $this->matrixService();
        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('longueur n\'est pas proposée');

        $this->pricing->quote($f['service'], $f['variants']['S'], $f['lengths']['mi-dos']);
    }

    public function testVariantOfAnotherServiceIsRejected(): void
    {
        $a = $this->matrixService('a-test');
        $b = $this->matrixService('b-test');

        $this->expectException(PricingException::class);
        $this->pricing->quote($a['service'], $b['variants']['M'], $b['lengths']['epaules']);
    }

    public function testInactiveVariantOrServiceIsRejected(): void
    {
        $f = $this->matrixService();
        $this->db->execute('UPDATE service_variants SET is_active = false WHERE id = :id', ['id' => $f['variants']['M']]);

        try {
            $this->pricing->quote($f['service'], $f['variants']['M'], $f['lengths']['epaules']);
            self::fail('Variante désactivée acceptée.');
        } catch (PricingException) {
        }

        $this->db->execute('UPDATE services SET is_active = false WHERE id = :id', ['id' => $f['service']]);
        $this->expectException(PricingException::class);
        $this->pricing->quote($f['service'], $f['variants']['S'], $f['lengths']['epaules']);
    }

    public function testOptionRules(): void
    {
        $f = $this->matrixService();
        $args = [$f['service'], $f['variants']['M'], $f['lengths']['epaules']];

        // Deux finitions alors qu'une seule est permise.
        try {
            $this->pricing->quote(...[...$args, [$this->optionId('Perles'), $this->optionId('Nœuds en finition')]]);
            self::fail('Deux finitions acceptées.');
        } catch (PricingException $e) {
            self::assertStringContainsString('Une seule option', $e->getMessage());
        }

        // Option d'un groupe non rattaché à la catégorie (suppléments).
        try {
            $this->pricing->quote(...[...$args, [$this->optionId('Mèches fournies')]]);
            self::fail('Option non proposée acceptée.');
        } catch (PricingException) {
        }

        // Une finition + un entretien : autorisé, prix « à partir de » propagé.
        $quote = $this->pricing->quote(...[...$args, [$this->optionId('Boucles en finition'), $this->optionId('Défaire braids + shampoing')]]);
        self::assertSame('120.00', $quote->total());
        self::assertTrue($quote->isFromPrice());
    }

    public function testServiceLevelExclusionAndRequiredGroup(): void
    {
        $f = $this->matrixService();
        $finish = (int) $this->db->fetchValue("SELECT id FROM option_groups WHERE code = 'finish'");

        // La prestation exclut les finitions héritées de sa catégorie.
        $this->db->execute('INSERT INTO option_group_assignments (option_group_id, service_id, is_excluded) VALUES (:g, :s, true)', ['g' => $finish, 's' => $f['service']]);
        try {
            $this->pricing->quote($f['service'], $f['variants']['M'], $f['lengths']['epaules'], [$this->optionId('Perles')]);
            self::fail('Option exclue acceptée.');
        } catch (PricingException) {
        }

        // Groupe obligatoire non renseigné.
        $this->db->execute("UPDATE option_groups SET is_required = true WHERE code = 'care'");
        $this->expectException(PricingException::class);
        $this->expectExceptionMessage('Choisissez une option');
        $this->pricing->quote($f['service'], $f['variants']['M'], $f['lengths']['epaules']);
    }

    public function testSimpleServiceRejectsSizeAndLength(): void
    {
        $id = $this->createService('simple-test');

        self::assertSame('50.00', $this->pricing->quote($id, null, null)->total());

        $this->expectException(PricingException::class);
        $this->pricing->quote($id, 1, 1);
    }
}
