<?php

// Paths that only a scanner asks for — dotfiles, environment files, logs, dumps, backups — are not found, before any route runs.
it('answers not found for dotfiles and secret-looking files', function (string $path) {
    $this->get($path)->assertNotFound();
})->with([
    '/.env', '/.env.production', '/.git/config', '/.aws/credentials', '/media/.env',
    '/backup.sql', '/storage/logs/laravel.log', '/index.php.bak', '/config.php~', '/app.env',
]);

// Ordinary pages still answer.
it('leaves ordinary paths alone', function () {
    $this->get('/media')->assertOk();
});
