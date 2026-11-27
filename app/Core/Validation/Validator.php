<?php

declare(strict_types=1);

namespace App\Core\Validation;

/**
 * Validation côté serveur avec messages en français.
 *
 *     $data = Validator::make($input, [
 *         'email'    => 'required|email|max:190',
 *         'password' => 'required|min:12|confirmed',
 *     ], ['email' => 'adresse e-mail'])->validate();
 *
 * Règles : required, nullable, string, email, min:n, max:n (longueur ou valeur), between:a,b,
 * integer, numeric, boolean, in:a,b,c, same:champ, confirmed (champ_confirmation), regex:/…/, date.
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    private bool $ran = false;

    /**
     * @param array<string, mixed>                $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string>               $labels  nom lisible des champs
     */
    private function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels,
    ) {
    }

    /**
     * @param array<string, mixed>                $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string>               $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        $this->run();

        return $this->errors !== [];
    }

    /** @return array<string, list<string>> */
    public function errors(): array
    {
        $this->run();

        return $this->errors;
    }

    /**
     * @return array<string, mixed> uniquement les champs déclarés dans les règles, nettoyés
     * @throws ValidationException
     */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        $validated = [];
        foreach (array_keys($this->rules) as $field) {
            if (array_key_exists($field, $this->data)) {
                $validated[$field] = $this->normalize($this->data[$field]);
            }
        }

        return $validated;
    }

    private function run(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        foreach ($this->rules as $field => $rules) {
            $rules = is_array($rules) ? $rules : self::split($rules);
            $value = $this->normalize($this->data[$field] ?? null);
            $present = !($value === null || $value === '' || $value === []);

            if (!$present) {
                if (in_array('required', $rules, true)) {
                    $this->addError($field, 'Le champ :label est obligatoire.');
                }
                continue; // les autres règles ne s'appliquent pas à une valeur vide
            }

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $message = $this->check($name, $param, $value, $field);
                if ($message !== null) {
                    $this->addError($field, $message);
                    break; // une erreur par champ suffit
                }
            }
        }
    }

    private function check(string $rule, ?string $param, mixed $value, string $field): ?string
    {
        $length = is_string($value) ? mb_strlen($value) : null;

        return match ($rule) {
            'required', 'nullable' => null,
            'string' => is_string($value) ? null : 'Le champ :label est invalide.',
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && mb_strlen((string) $value) <= 190
                ? null : 'Le champ :label doit être une adresse e-mail valide.',
            'min' => is_numeric($value) && !is_string($value)
                ? ($value >= (float) $param ? null : "Le champ :label doit être au moins {$param}.")
                : ($length !== null && $length >= (int) $param ? null : "Le champ :label doit contenir au moins {$param} caractères."),
            'max' => is_numeric($value) && !is_string($value)
                ? ($value <= (float) $param ? null : "Le champ :label ne doit pas dépasser {$param}.")
                : ($length !== null && $length <= (int) $param ? null : "Le champ :label ne doit pas dépasser {$param} caractères."),
            'between' => $this->between($value, (string) $param),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : 'Le champ :label doit être un nombre entier.',
            'numeric' => is_numeric($value) ? null : 'Le champ :label doit être un nombre.',
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'on', 'off', 'true', 'false'], true)
                ? null : 'Le champ :label est invalide.',
            'in' => in_array((string) $value, explode(',', (string) $param), true) ? null : 'La valeur du champ :label est invalide.',
            'same' => $value === $this->normalize($this->data[$param] ?? null)
                ? null : 'Le champ :label doit être identique au champ ' . $this->label((string) $param) . '.',
            'confirmed' => $value === $this->normalize($this->data[$field . '_confirmation'] ?? null)
                ? null : 'La confirmation du champ :label ne correspond pas.',
            'regex' => is_string($value) && preg_match((string) $param, $value) === 1 ? null : 'Le format du champ :label est invalide.',
            'date' => is_string($value) && strtotime($value) !== false ? null : 'Le champ :label doit être une date valide.',
            default => throw new \InvalidArgumentException("Règle de validation inconnue : {$rule}"),
        };
    }

    /**
     * « required|max:5|regex:/^(a|b)$/ » → règles. Une expression régulière peut contenir « | » :
     * tout ce qui suit « regex: » forme une seule règle (à placer en dernier).
     *
     * @return list<string>
     */
    public static function split(string $rules): array
    {
        $position = strpos($rules, 'regex:');
        if ($position === false) {
            return explode('|', $rules);
        }

        $before = rtrim(substr($rules, 0, $position), '|');

        return [...($before !== '' ? explode('|', $before) : []), substr($rules, $position)];
    }

    private function between(mixed $value, string $param): ?string
    {
        [$min, $max] = array_map('floatval', explode(',', $param));
        $size = is_string($value) && !is_numeric($value) ? mb_strlen($value) : (float) $value;

        return $size >= $min && $size <= $max ? null : "Le champ :label doit être compris entre {$min} et {$max}.";
    }

    private function normalize(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = str_replace(':label', $this->label($field), $message);
    }

    private function label(string $field): string
    {
        return '« ' . ($this->labels[$field] ?? str_replace('_', ' ', $field)) . ' »';
    }
}
