<?php

test('home shows the public website in saas mode', function () {
    $this->get(route('home'))->assertOk()->assertSee('MoziStock');
});

test('home sends guests to login in standalone mode', function () {
    config(['mozistock.deployment_mode' => 'standalone']);

    $this->get(route('home'))->assertRedirect(route('login'));
});
