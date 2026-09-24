<?php

namespace App\Actions\Ai;

class IngredientExclusions
{
    /** Literal phrases; the AI review additionally checks synonyms and translations. @param list<string> $names @param list<string> $excluded */
    public static function matches(array $names, array $excluded): bool
    {
        foreach ($excluded as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu';
            foreach ($names as $name) {
                if (preg_match($pattern, $name) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
