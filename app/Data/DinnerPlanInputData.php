<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\AfterOrEqual;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\References\FieldReference;

class DinnerPlanInputData extends Data
{
    public function __construct(
        #[Max(255)]
        public string $name,
        #[Date]
        public ?string $startDate = null,
        #[Date, AfterOrEqual(new FieldReference('startDate'))]
        public ?string $endDate = null,
    ) {}
}
