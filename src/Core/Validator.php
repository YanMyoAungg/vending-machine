<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, list<string>> $rules
     *
     * @return array<string, mixed>
     */
    public function validate(array $data, array $rules): array
    {
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $label = $this->label($field);
            $isNumeric = in_array('numeric', $fieldRules, true) || in_array('integer', $fieldRules, true);

            if (in_array('nullable', $fieldRules, true) && ($value === null || $value === '')) {
                $validated[$field] = $value;
                continue;
            }

            $required = in_array('required', $fieldRules, true);

            if (!$required && ($value === null || $value === '')) {
                continue;
            }

            if ($required && ($value === null || $value === '')) {
                $errors[$field] = ["{$label} is required."];
                continue;
            }

            $fieldErrors = [];

            foreach ($fieldRules as $rule) {
                if ($rule === 'nullable' || $rule === 'required') {
                    continue;
                }

                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
                $message = $this->checkRule($name, $parameter, $value, $label, $isNumeric);

                if ($message !== null) {
                    $fieldErrors[] = $message;
                }
            }

            if ($fieldErrors !== []) {
                $errors[$field] = $fieldErrors;
                continue;
            }

            $validated[$field] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $validated;
    }

    private function checkRule(
        string $name,
        ?string $parameter,
        mixed $value,
        string $label,
        bool $numericField
    ): ?string {
        return match ($name) {
            'required' => $value === null || $value === '' ? "{$label} is required." : null,
            'string' => !is_scalar($value) ? "{$label} must be text." : null,
            'integer' => filter_var($value, FILTER_VALIDATE_INT) === false ? "{$label} must be a whole number." : null,
            'numeric' => !is_numeric($value) ? "{$label} must be a number." : null,
            'boolean' => !$this->isBoolean($value) ? "{$label} must be true or false." : null,
            'email' => filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false
                ? "{$label} must be a valid email address."
                : null,
            'min' => $this->checkMin($value, (float) $parameter, $label, $numericField),
            'max' => $this->checkMax($value, (float) $parameter, $label, $numericField),
            'in' => !in_array((string) $value, explode(',', (string) $parameter), true)
                ? "{$label} must be one of: " . str_replace(',', ', ', (string) $parameter) . '.'
                : null,
            'regex' => preg_match((string) $parameter, (string) $value) !== 1
                ? "{$label} has an invalid format."
                : null,
            'decimal' => !preg_match('/^\d+(?:\.\d{1,' . (int) $parameter . '})?$/', (string) $value)
                ? "{$label} may have at most {$parameter} decimal places."
                : null,
            default => throw new \RuntimeException("Unknown validation rule {$name}"),
        };
    }

    private function checkMin(mixed $value, float $minimum, string $label, bool $numericField): ?string
    {
        if ($numericField) {
            return (float) $value < $minimum ? "{$label} must be at least {$this->number($minimum)}." : null;
        }

        return mb_strlen((string) $value) < $minimum
            ? "{$label} must be at least " . (int) $minimum . ' characters.'
            : null;
    }

    private function checkMax(mixed $value, float $maximum, string $label, bool $numericField): ?string
    {
        if ($numericField) {
            return (float) $value > $maximum ? "{$label} must not be greater than {$this->number($maximum)}." : null;
        }

        return mb_strlen((string) $value) > $maximum
            ? "{$label} must not be longer than " . (int) $maximum . ' characters.'
            : null;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function isBoolean(mixed $value): bool
    {
        return is_bool($value)
            || in_array((string) $value, ['0', '1', 'true', 'false', 'on', 'off'], true);
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
