<?php

return [
    // All provider access is server-side. This switch disables both features.
    'enabled' => env('AI_ENABLED', false),
    'classification_model' => env('AI_CLASSIFICATION_MODEL', 'typesafe/jev-1.13'),
    'suggestion_model' => env('AI_SUGGESTION_MODEL', 'google/gemini-3.5-flash-lite'),
    'classification_confidence' => 0.9,
    'cache_seconds' => 86400,
    'limits' => [
        'categorization' => [
            'user' => (int) env('AI_CLASSIFICATIONS_PER_USER', 100),
            'household' => (int) env('AI_CLASSIFICATIONS_PER_HOUSEHOLD', 300),
            'global' => (int) env('AI_CLASSIFICATIONS_GLOBAL', 5000),
        ],
        'suggestions' => [
            'user' => (int) env('AI_SUGGESTIONS_PER_USER', 20),
            'household' => (int) env('AI_SUGGESTIONS_PER_HOUSEHOLD', 60),
            'global' => (int) env('AI_SUGGESTIONS_GLOBAL', 1000),
        ],
    ],
];
