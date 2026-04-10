<?php

test('guest visiting root is redirected to login', function (): void {
    $this->get('/')->assertRedirect(route('login'));
});
