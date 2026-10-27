<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validación en servidor con reglas en texto: 'required|string|max:255'.
 * Devuelve los valores ya normalizados y lanza ValidationException con
 * mensajes en español por campo.
 *
 * Reglas: required, nullable, string, text (multilínea), int, min:N, max:N,
 * email, phone, bool, in:a,b,c, date (local -> UTC 'Y-m-d H:i:s'), day (Y-m-d),
 * color (#RRGGBB), time (HH:MM), password, intlist.
 */
final class Validator
{
    /**
     * @param array<string,mixed> $input
     * @param array<string,string> $labels nombre visible de cada campo
     */
    public function __construct(private readonly array $input, private readonly array $labels = [])
    {
    }

    /**
     * @param array<string,string> $rules
     * @return array<string,mixed>
     */
    public function validate(array $rules): array
    {
        $out = [];
        $errors = [];
        foreach ($rules as $field => $spec) {
            $parts = explode('|', $spec);
            $raw = $this->input[$field] ?? null;
            $label = $this->labels[$field] ?? $field;
            try {
                $out[$field] = $this->apply($label, $raw, $parts);
            } catch (\InvalidArgumentException $e) {
                $errors[$field][] = $e->getMessage();
            }
        }
        if ($errors) {
            throw new ValidationException($errors);
        }
        return $out;
    }

    /** @param list<string> $parts */
    private function apply(string $label, mixed $raw, array $parts): mixed
    {
        $required = in_array('required', $parts, true);
        $isList = in_array('intlist', $parts, true);
        if ($isList) {
            $items = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
            $ints = [];
            foreach ($items as $i) {
                if (!is_scalar($i) || !preg_match('/^\d{1,18}$/', (string)$i)) {
                    throw new \InvalidArgumentException("$label contiene un valor no válido.");
                }
                $ints[] = (int)$i;
            }
            if ($required && $ints === []) {
                throw new \InvalidArgumentException("$label es obligatorio.");
            }
            return array_values(array_unique($ints));
        }
        if (is_array($raw)) {
            throw new \InvalidArgumentException("$label no es válido.");
        }
        if (in_array('bool', $parts, true)) {
            return in_array($raw, ['1', 'on', 'true', 1, true], true);
        }
        $value = $raw === null ? '' : (string)$raw;
        $isText = in_array('text', $parts, true);
        $value = $isText ? rtrim(str_replace("\r\n", "\n", $value)) : trim($value);
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException("$label contiene caracteres no válidos.");
        }
        // Elimina caracteres de control salvo saltos de línea y tabuladores
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException("$label es obligatorio.");
            }
            return null;
        }
        $result = $value;
        foreach ($parts as $p) {
            [$rule, $arg] = array_pad(explode(':', $p, 2), 2, null);
            switch ($rule) {
                case 'int':
                    if (!preg_match('/^-?\d{1,18}$/', $value)) {
                        throw new \InvalidArgumentException("$label debe ser un número entero.");
                    }
                    $result = (int)$value;
                    break;
                case 'min':
                    if (is_int($result) ? $result < (int)$arg : mb_strlen($value) < (int)$arg) {
                        throw new \InvalidArgumentException(is_int($result) ? "$label debe ser como mínimo $arg." : "$label debe tener al menos $arg caracteres.");
                    }
                    break;
                case 'max':
                    if (is_int($result) ? $result > (int)$arg : mb_strlen($value) > (int)$arg) {
                        throw new \InvalidArgumentException(is_int($result) ? "$label debe ser como máximo $arg." : "$label no puede superar $arg caracteres.");
                    }
                    break;
                case 'email':
                    if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || mb_strlen($value) > 254) {
                        throw new \InvalidArgumentException("$label no es un correo electrónico válido.");
                    }
                    $result = $value;
                    break;
                case 'phone':
                    $digits = preg_replace('/[^\d+]/', '', $value);
                    if (!preg_match('/^\+?\d{6,15}$/', (string)$digits)) {
                        throw new \InvalidArgumentException("$label no es un teléfono válido.");
                    }
                    break;
                case 'in':
                    $allowed = explode(',', (string)$arg);
                    if (!in_array($value, $allowed, true)) {
                        throw new \InvalidArgumentException("$label no tiene un valor permitido.");
                    }
                    break;
                case 'date':
                    $dt = Clock::parseLocal($value);
                    if ($dt === null) {
                        throw new \InvalidArgumentException("$label no es una fecha válida (DD/MM/AAAA).");
                    }
                    $result = Clock::toDb($dt);
                    break;
                case 'day':
                    $dt = Clock::parseLocal($value);
                    if ($dt === null) {
                        throw new \InvalidArgumentException("$label no es una fecha válida (DD/MM/AAAA).");
                    }
                    $result = $dt->setTimezone(Clock::local())->format('Y-m-d');
                    break;
                case 'color':
                    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                        throw new \InvalidArgumentException("$label debe ser un color #RRGGBB.");
                    }
                    $result = strtoupper($value);
                    break;
                case 'time':
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                        throw new \InvalidArgumentException("$label debe tener formato HH:MM.");
                    }
                    break;
                case 'decimal':
                    $norm = str_replace(',', '.', $value);
                    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $norm)) {
                        throw new \InvalidArgumentException("$label debe ser un importe válido.");
                    }
                    $result = $norm;
                    break;
                case 'slug':
                    if (!preg_match('/^[a-z0-9_]{2,40}$/', $value)) {
                        throw new \InvalidArgumentException("$label solo admite minúsculas, números y guion bajo.");
                    }
                    break;
                case 'domain':
                    $d = strtolower($value);
                    if (!preg_match('/^(?=.{3,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $d)) {
                        throw new \InvalidArgumentException("$label no es un dominio válido.");
                    }
                    $result = $d;
                    break;
            }
        }
        return $result;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Normaliza un teléfono a dígitos E.164 sin "+". Los números españoles de
     * 9 cifras reciben el prefijo 34.
     */
    public static function normalizePhone(string $phone): string
    {
        $p = trim($phone);
        $plus = str_starts_with($p, '+') || str_starts_with($p, '00');
        $digits = (string)preg_replace('/\D/', '', $p);
        if (str_starts_with($p, '00')) {
            $digits = substr($digits, 2);
        }
        if (!$plus && strlen($digits) === 9 && preg_match('/^[6789]/', $digits)) {
            $digits = '34' . $digits;
        }
        return $digits;
    }

    public static function normalizeTaxId(?string $taxId): ?string
    {
        if ($taxId === null) {
            return null;
        }
        $n = strtoupper((string)preg_replace('/[\s.\-]/', '', $taxId));
        return $n === '' ? null : $n;
    }
}
