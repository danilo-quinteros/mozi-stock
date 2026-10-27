<?php

test('home sends guests to login', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});
