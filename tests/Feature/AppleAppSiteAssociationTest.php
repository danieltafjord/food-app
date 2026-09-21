<?php

test('apple app site association is public and only enables invitation links', function () {
    $this->get('/.well-known/apple-app-site-association')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeaderMissing('Location')
        ->assertExactJson([
            'applinks' => [
                'details' => [
                    [
                        'appIDs' => ['WZ5F9GQL2A.no.handlelistaapp'],
                        'components' => [
                            ['/' => '/invitations/*'],
                        ],
                    ],
                ],
            ],
        ]);
});
