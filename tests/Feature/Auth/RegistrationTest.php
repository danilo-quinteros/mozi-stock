<?php

test('registration screen is unavailable', function () {
    $this->get('/register')->assertNotFound();
});

test('new users cannot self register', function () {
    $response = $this->post('/register', [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
});
