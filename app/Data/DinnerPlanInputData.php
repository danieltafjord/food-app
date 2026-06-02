<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class DinnerPlanInputData extends Data
{
    public function __construct(
        #[Max(255)]
        public string $name,
        #[Date]
        public ?string $startDate = null,
        #[Date]
        public ?string $endDate = null,
    ) {}
}
