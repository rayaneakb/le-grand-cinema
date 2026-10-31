<?php

namespace App\Tests\Validator;

use App\Validator\RegistrationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

class RegistrationValidatorTest extends TestCase
{
    private function validData(): array
    {
        return [
            'firstName' => 'Élodie',
            'lastName' => "O’Connor-Martin",
            'email' => 'elodie@example.com',
            'password' => 'une phrase facile à retenir',
        ];
    }

    private function validator(): RegistrationValidator
    {
        return new RegistrationValidator(Validation::createValidator());
    }

    public function testNamesWithAccentsAndPasswordWithSpacesAreAccepted(): void
    {
        self::assertSame([], $this->validator()->validate($this->validData()));
    }

    #[DataProvider('passwordLengths')]
    public function testPasswordLengthBoundaries(int $length, bool $accepted): void
    {
        $data = $this->validData();
        $data['password'] = str_repeat('é', $length);

        $errors = $this->validator()->validate($data);

        self::assertSame(!$accepted, isset($errors['password']));
    }

    public static function passwordLengths(): array
    {
        return [[9, false], [10, true], [128, true], [129, false]];
    }

    public function testMissingFieldsAreReported(): void
    {
        $errors = $this->validator()->validate([]);

        self::assertEqualsCanonicalizing(['firstName', 'lastName', 'email', 'password'], array_keys($errors));
    }

    public function testInvalidFieldTypesAreRejectedWithoutException(): void
    {
        $data = ['firstName' => [], 'lastName' => 123, 'email' => false, 'password' => null];
        $errors = $this->validator()->validate($data);

        self::assertEqualsCanonicalizing(array_keys($data), array_keys($errors));
    }

    public function testBlankNamesAndMalformedEmailAreRejected(): void
    {
        $data = $this->validData();
        $data['firstName'] = '   ';
        $data['email'] = 'adresse-sans-domaine';
        $errors = $this->validator()->validate($data);

        self::assertArrayHasKey('firstName', $errors);
        self::assertArrayHasKey('email', $errors);
    }

    public function testOverlongNamesAndEmailAreRejected(): void
    {
        $data = $this->validData();
        $data['lastName'] = str_repeat('a', 101);
        $data['email'] = str_repeat('a', 170) . '@example.com';
        $errors = $this->validator()->validate($data);

        self::assertArrayHasKey('lastName', $errors);
        self::assertArrayHasKey('email', $errors);
    }

    public function testRegistrationCannotSupplyRoles(): void
    {
        $data = $this->validData();
        $data['roles'] = ['ROLE_ADMIN'];

        self::assertArrayHasKey('roles', $this->validator()->validate($data));
    }
}
