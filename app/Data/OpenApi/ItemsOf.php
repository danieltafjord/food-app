<?php

namespace App\Data\OpenApi;

use Attribute;
use Spatie\LaravelData\Data;

/**
 * Documents the Data class behind a plain `array` property, for the OpenAPI
 * docs only. (Nested children are plain arrays rather than data collections
 * to avoid double "data" wrapping, so their shape cannot be inferred.)
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ItemsOf
{
    /**
     * @param  class-string<Data>  $dataClass
     */
    public function __construct(public string $dataClass) {}
}
