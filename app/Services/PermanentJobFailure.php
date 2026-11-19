<?php

declare(strict_types=1);

namespace App\Services;

/** Error que no se arregla reintentando (datos inválidos, destinatario inexistente…). */
final class PermanentJobFailure extends \RuntimeException
{
}
