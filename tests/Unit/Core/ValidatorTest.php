<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\ValidationException;
use App\Core\Validator;
use App\Support\Rules;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_purchase_quantity_must_be_a_positive_integer(): void
    {
        try {
            (new Validator())->validate(['quantity' => '0'], Rules::purchase());
            self::fail('Expected validation failure');
        } catch (ValidationException $exception) {
            self::assertSame(['Quantity must be at least 1.'], $exception->errors()['quantity']);
        }
    }

    public function test_price_rejects_extra_decimals_and_negative_values(): void
    {
        $validator = new Validator();
        $rules = ['price' => Rules::product()['price']];

        foreach (['6.8855', '-1'] as $price) {
            try {
                $validator->validate(['price' => $price], $rules);
                self::fail('Expected validation failure for ' . $price);
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('price', $exception->errors());
            }
        }
    }

    public function test_price_accepts_three_decimals(): void
    {
        $validated = (new Validator())->validate(
            ['price' => '6.885'],
            ['price' => Rules::product()['price']]
        );

        self::assertSame('6.885', $validated['price']);
    }

    public function test_required_email_and_enum_rules(): void
    {
        try {
            (new Validator())->validate(
                ['name' => '', 'email' => 'not-an-email', 'role' => 'owner'],
                Rules::userCreate()
            );
            self::fail('Expected validation failure');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            self::assertSame(['Name is required.'], $errors['name']);
            self::assertSame(['Email must be a valid email address.'], $errors['email']);
            self::assertSame(['Role must be one of: admin, user.'], $errors['role']);
        }
    }

    public function test_nullable_fields_are_skipped(): void
    {
        $rules = ['description' => ['nullable', 'string', 'max:10'], 'name' => ['required']];

        $validated = (new Validator())->validate(['description' => '', 'name' => 'Coke'], $rules);

        self::assertSame('', $validated['description']);
        self::assertSame('Coke', $validated['name']);
    }

    public function test_string_min_and_max_use_length(): void
    {
        try {
            (new Validator())->validate(['name' => 'A'], ['name' => ['required', 'string', 'min:2', 'max:3']]);
            self::fail('Expected validation failure');
        } catch (ValidationException $exception) {
            self::assertSame(['Name must be at least 2 characters.'], $exception->errors()['name']);
        }
    }

    public function test_returns_only_validated_fields(): void
    {
        $validated = (new Validator())->validate(
            ['name' => 'Coke', 'ignored' => 'x'],
            ['name' => ['required']]
        );

        self::assertSame(['name' => 'Coke'], $validated);
    }
}
