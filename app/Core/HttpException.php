<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Error controlado que se traduce en una respuesta HTTP con mensaje seguro. */
class HttpException extends RuntimeException
{
    /** @param array<string,list<string>> $errors */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $errors = [],
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status));
    }

    public static function notFound(string $msg = ''): self
    {
        return new self(404, $msg);
    }

    public static function forbidden(string $msg = ''): self
    {
        return new self(403, $msg);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'La petición no es válida.',
            401 => 'Debes iniciar sesión.',
            403 => 'No tienes permiso para realizar esta acción.',
            404 => 'La página o el elemento no existe o no tienes acceso.',
            405 => 'Método no permitido.',
            409 => 'El elemento ha cambiado mientras lo editabas. Recarga la página.',
            413 => 'El archivo o los datos enviados son demasiado grandes.',
            419 => 'La sesión del formulario ha caducado. Vuelve a intentarlo.',
            422 => 'Revisa los datos del formulario.',
            429 => 'Demasiados intentos. Espera unos minutos.',
            default => 'Se ha producido un error interno.',
        };
    }
}
