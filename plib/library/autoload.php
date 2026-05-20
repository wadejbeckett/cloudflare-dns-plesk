<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the panel-agnostic core.
 *
 * The extension has no Composer runtime dependencies, so rather than ship a
 * Composer-generated autoloader it registers this tiny loader. It maps the
 * `Noiz\CloudflareDns\` namespace onto this directory.
 *
 * Plesk extension code (the DNS backend handler, controllers, hooks)
 * `require_once`s this file once, before using any `Noiz\CloudflareDns\` class.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Noiz\\CloudflareDns\\';
    $length = strlen($prefix);

    if (strncmp($class, $prefix, $length) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, $length)) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
