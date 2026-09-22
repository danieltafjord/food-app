<?php

namespace App\Enums;

/** OpenRouter `reasoning.effort` levels. A null setting means the provider default. */
enum ReasoningEffort: string
{
    case None = 'none';
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case XHigh = 'xhigh';
    case Max = 'max';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $effort) => $effort->value, self::cases());
    }
}
