<?php

declare(strict_types=1);

namespace App\Sections;

use App\Core\Validation\ValidationException;
use App\Repositories\MediaRepository;

/**
 * Formulaire d'édition d'une section, généré depuis SectionType::fields() :
 * champs affichés, valeurs par défaut d'une nouvelle section, validation et normalisation de la saisie.
 *
 * Le contenu enregistré ne contient que les clés déclarées : aucune donnée inattendue n'entre en base.
 */
final class SectionForm
{
    private const TEXT_MAX = 200;
    private const TEXTAREA_MAX = 2000;
    private const NAV_LABEL_MAX = 40;

    public function __construct(private readonly MediaRepository $media)
    {
    }

    /**
     * Champs du formulaire. Une section avec ancre reçoit aussi « libellé dans le menu ».
     *
     * @return array<string, array<string, mixed>>
     */
    public function fields(SectionType $type): array
    {
        $fields = [];
        foreach ($type->fields() as $name => $field) {
            $fields[$name] = $field;
            if ($field['type'] === 'anchor') {
                $fields['nav_label'] = [
                    'label' => 'Libellé dans le menu',
                    'type' => 'text',
                    'max' => self::NAV_LABEL_MAX,
                    'help' => 'Avec une ancre, la section apparaît dans le menu de l\'accueil. Par défaut : son titre.',
                ];
            }
        }

        return $fields;
    }

    /**
     * Contenu d'une nouvelle section : valeurs par défaut, et le nom du type comme titre provisoire.
     *
     * @return array<string, mixed>
     */
    public function defaults(SectionType $type): array
    {
        $content = [];
        foreach ($this->fields($type) as $name => $field) {
            if (array_key_exists('default', $field)) {
                $content[$name] = $field['default'];
            } elseif ($field['type'] === 'bool') {
                $content[$name] = false;
            }
        }
        if (isset($this->fields($type)['title'])) {
            $content['title'] = $type->label();
        }

        return $content;
    }

    /**
     * Valide la saisie du formulaire et renvoie le contenu à enregistrer.
     *
     * @param array<string, mixed> $input
     * @param list<string>         $takenAnchors ancres déjà utilisées par les autres sections de la page
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(SectionType $type, array $input, array $takenAnchors = []): array
    {
        $content = [];
        $errors = [];

        foreach ($this->fields($type) as $name => $field) {
            $raw = $input[$name] ?? null;
            $label = $field['label'];

            switch ($field['type']) {
                case 'bool':
                    $content[$name] = in_array($raw, ['1', 1, true, 'on'], true);
                    break;

                case 'int':
                    $min = (int) ($field['min'] ?? 1);
                    $max = (int) ($field['max'] ?? 100);
                    $value = filter_var(is_scalar($raw) ? trim((string) $raw) : '', FILTER_VALIDATE_INT);
                    if ($value === false || $value < $min || $value > $max) {
                        $errors[$name] = "« {$label} » doit être un nombre entre {$min} et {$max}.";
                        break;
                    }
                    $content[$name] = $value;
                    break;

                case 'link':
                    $linkLabel = self::text($raw['label'] ?? null);
                    $target = self::text($raw['target'] ?? null);
                    if ($linkLabel === '' && $target === '') {
                        $content[$name] = null;
                        break;
                    }
                    if ($linkLabel === '' || $target === '') {
                        $errors[$name] = "« {$label} » : indiquez le texte du bouton et sa destination, ou laissez les deux vides.";
                    } elseif (mb_strlen($linkLabel) > 60) {
                        $errors[$name] = "« {$label} » : le texte du bouton ne doit pas dépasser 60 caractères.";
                    } elseif (!Content::isSafeTarget($target) || mb_strlen($target) > 500) {
                        $errors[$name] = "« {$label} » : destination invalide. Exemples : #tarifs, /contact, https://…";
                    } else {
                        $content[$name] = ['label' => $linkLabel, 'target' => $target];
                    }
                    break;

                case 'media':
                    $id = filter_var(is_scalar($raw) ? $raw : '', FILTER_VALIDATE_INT);
                    if ($id === false) {
                        $content[$name] = null;
                        break;
                    }
                    $media = $this->media->find($id);
                    if ($media === null || !$media->isImage()) {
                        $errors[$name] = "« {$label} » : image introuvable dans la bibliothèque.";
                        break;
                    }
                    $content[$name] = $id;
                    break;

                case 'anchor':
                    $anchor = mb_strtolower(self::text($raw));
                    if ($anchor === '') {
                        $content[$name] = null;
                        break;
                    }
                    if (preg_match('/^[a-z][a-z0-9-]{0,40}$/', $anchor) !== 1) {
                        $errors[$name] = "« {$label} » : lettres minuscules sans accents, chiffres et tirets, en commençant par une lettre (ex. tarifs).";
                    } elseif (in_array($anchor, $takenAnchors, true)) {
                        $errors[$name] = "L'ancre « {$anchor} » est déjà utilisée par une autre section de la page.";
                    } else {
                        $content[$name] = $anchor;
                    }
                    break;

                default: // text, textarea, richtext
                    $multiline = $field['type'] !== 'text';
                    $value = self::text($raw, $multiline);
                    $max = (int) ($field['max'] ?? ($multiline ? self::TEXTAREA_MAX : self::TEXT_MAX));
                    if (mb_strlen($value) > $max) {
                        $errors[$name] = "« {$label} » ne doit pas dépasser {$max} caractères.";
                        break;
                    }
                    $content[$name] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $content;
    }

    /** Texte nettoyé : caractères de contrôle retirés, sauts de ligne normalisés (multiligne seulement). */
    private static function text(mixed $value, bool $multiline = false): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return trim($value);
    }
}
