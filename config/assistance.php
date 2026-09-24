<?php

return [
    // All provider access is server-side. This switch disables both features.
    'enabled' => env('AI_ENABLED', false),
    'classification_model' => env('AI_CLASSIFICATION_MODEL', 'typesafe/jev-1.13'),
    'suggestion_model' => env('AI_SUGGESTION_MODEL', 'google/gemini-3.5-flash-lite'),
    // OpenRouter reasoning effort for chat-completion features; null uses the provider default.
    'suggestion_reasoning' => env('AI_SUGGESTION_REASONING', 'minimal'),
    // System One (classification) has no reasoning parameter; kept for symmetry with the admin page.
    'classification_reasoning' => null,
    'classification_confidence' => 0.9,
    'cache_seconds' => 86400,
    // Guest generation has its own bounded budget, shared across installs on an IP.
    'week_planning' => [
        'ip' => (int) env('AI_WEEK_PLANS_PER_IP', 20),
        'global' => (int) env('AI_WEEK_PLANS_GLOBAL', 200),
    ],
    // Dinner pictures through OpenRouter's image endpoint. Generation stops for
    // everyone once the day's recorded provider cost (USD) reaches the budget.
    'images' => [
        'model' => env('AI_IMAGE_MODEL', 'black-forest-labs/flux.2-klein-4b'),
        'daily_budget' => (float) env('AI_IMAGE_DAILY_BUDGET', 2.0),
        // Recorded when the provider response carries no cost.
        'estimated_cost' => (float) env('AI_IMAGE_ESTIMATED_COST', 0.015),
    ],
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
        'images' => [
            'user' => (int) env('AI_IMAGES_PER_USER', 10),
            'household' => (int) env('AI_IMAGES_PER_HOUSEHOLD', 20),
            'global' => (int) env('AI_IMAGES_GLOBAL', 500),
        ],
    ],
];
