<?php

test('credentialed frontend requests receive an explicit cors origin', function (): void {
    $response = $this->call('OPTIONS', '/api/v1/clinics', server: [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
    ]);

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('*');
});

test('sanctum csrf cookie endpoint remains available to the frontend', function (): void {
    $response = $this->withHeader('Origin', 'http://localhost:5173')
        ->get('/sanctum/csrf-cookie');

    $response->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true')
        ->assertCookie('XSRF-TOKEN');

    expect(config('sanctum.stateful'))->toContain('localhost:5173');
});
