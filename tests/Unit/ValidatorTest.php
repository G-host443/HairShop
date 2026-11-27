<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validation\ValidationException;
use App\Core\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testReturnsOnlyDeclaredAndTrimmedFields(): void
    {
        $data = Validator::make(
            ['email' => '  garance@example.fr ', 'role' => 'admin', 'is_admin' => '1'],
            ['email' => 'required|email', 'role' => 'in:admin,staff'],
        )->validate();

        self::assertSame(['email' => 'garance@example.fr', 'role' => 'admin'], $data);
    }

    public function testCollectsFrenchMessagesWithLabels(): void
    {
        $errors = Validator::make(
            ['email' => 'pas-un-email', 'name' => ''],
            ['email' => 'required|email', 'name' => 'required'],
            ['email' => 'adresse e-mail', 'name' => 'nom'],
        )->errors();

        self::assertSame('Le champ « adresse e-mail » doit être une adresse e-mail valide.', $errors['email'][0]);
        self::assertSame('Le champ « nom » est obligatoire.', $errors['name'][0]);
    }

    public function testOptionalEmptyFieldsSkipOtherRules(): void
    {
        self::assertFalse(Validator::make(['phone' => ''], ['phone' => 'nullable|min:10'])->fails());
    }

    public function testLengthAndConfirmation(): void
    {
        $errors = Validator::make(
            ['password' => 'abc', 'code' => 'abcdef', 'password_confirmation' => 'abd'],
            ['password' => 'required|min:4', 'code' => 'max:5'],
        )->errors();
        self::assertArrayHasKey('password', $errors);
        self::assertArrayHasKey('code', $errors);

        self::assertTrue(Validator::make(['p' => 'secret', 'p_confirmation' => 'autre'], ['p' => 'confirmed'])->fails());
        self::assertFalse(Validator::make(['p' => 'secret', 'p_confirmation' => 'secret'], ['p' => 'confirmed'])->fails());
    }

    public function testNumericRules(): void
    {
        self::assertTrue(Validator::make(['n' => '4.5'], ['n' => 'integer'])->fails());
        self::assertFalse(Validator::make(['n' => '42'], ['n' => 'integer|between:1,100'])->fails());
        self::assertTrue(Validator::make(['n' => '420'], ['n' => 'between:1,100'])->fails());
    }

    public function testRegexContainingPipeIsKeptWhole(): void
    {
        self::assertSame(['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], Validator::split('required|regex:/^([01]\d|2[0-3]):[0-5]\d$/'));
        self::assertFalse(Validator::make(['t' => '14:30'], ['t' => 'required|regex:/^([01]\d|2[0-3]):[0-5]\d$/'])->fails());
        self::assertTrue(Validator::make(['t' => '25:00'], ['t' => 'required|regex:/^([01]\d|2[0-3]):[0-5]\d$/'])->fails());
    }

    public function testValidateThrowsWithErrors(): void
    {
        try {
            Validator::make([], ['email' => 'required'])->validate();
            self::fail('Une ValidationException était attendue.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
        }
    }
}
