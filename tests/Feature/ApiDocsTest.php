<?php

test('the api docs are publicly viewable', function () {
    $this->get('/docs/api')->assertOk();
});

test('the openapi document covers only the public api with bearer auth', function () {
    $document = $this->getJson('/docs/api.json')->assertOk()->json();

    expect($document['servers'][0]['url'])->toEndWith('/api/public/v1')
        ->and(array_keys($document['paths']))->toContain('/dinners', '/shopping-lists/{shoppingList}/items', '/today')
        ->not->toContain('/sync', '/auth/devices')
        ->and($document['components']['securitySchemes']['http']['scheme'])->toBe('bearer');
});

test('laravel data request bodies and responses are documented', function () {
    $document = $this->getJson('/docs/api.json')->json();

    $body = $document['paths']['/dinners']['post']['requestBody']['content']['application/json']['schema'];
    expect($body['required'])->toBe(['name'])
        ->and($body['properties']['default_servings'])->toMatchArray(['type' => 'integer', 'minimum' => 1, 'maximum' => 99])
        ->and($body['properties']['items']['items']['properties'])->toHaveKey('ingredient_id');

    $response = $document['paths']['/shopping-lists']['get']['responses']['200']['content']['application/json']['schema'];
    expect($response['properties']['data']['type'])->toBe('array')
        ->and($response['properties']['data']['items']['properties']['items']['items']['properties'])->toHaveKey('is_checked');
});
