<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class CheckItemInputData extends Data
{
    public function __construct(
        public bool $checked,
    ) {}
}
