<?php

namespace App\Http\Exceptions;

use RuntimeException;

class ReservationConflictException extends RuntimeException
{
    public function __construct(
        public readonly array $conflicts,
        public readonly bool $canOverride,
    ) {
        parent::__construct('Satu atau lebih pegawai memiliki jadwal yang beririsan.');
    }
}
