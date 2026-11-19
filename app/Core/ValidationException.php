<?php

declare(strict_types=1);

namespace App\Core;

/** Errores de validación por campo; los controladores los devuelven al formulario. */
final class ValidationException extends HttpException
{
    /** @param array<string,list<string>> $errors */
    public function __construct(array $errors, string $message = 'Revisa los datos del formulario.')
    {
        parent::__construct(422, $message, $errors);
    }

    public static function single(string $field, string $message): self
    {
        return new self([$field => [$message]], $message);
    }
}
