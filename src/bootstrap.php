<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Rft\LaravelDocker\Support\EnvFileParser;

(static function (): void {
    if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
        return;
    }

    $cliArgs = $_SERVER['argv'] ?? [];

    if (is_array($cliArgs) && array_any(
        $cliArgs,
        static fn (mixed $arg): bool => is_string($arg) && ($arg === '--env' || str_starts_with($arg, '--env='))
    )) {
        return;
    }

    $existingAppEnv = getenv('APP_ENV');

    if (($existingAppEnv !== false && $existingAppEnv !== '')
        || (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] !== '')
        || (isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] !== '')) {
        return;
    }

    if (! class_exists(ClassLoader::class, false)) {
        return;
    }

    $loaders = ClassLoader::getRegisteredLoaders();

    if ($loaders === []) {
        return;
    }

    $vendorDirectory = (string) array_key_first($loaders);
    $projectRoot = dirname($vendorDirectory);
    $envLocalPath = $projectRoot.'/.env.local';

    if (! is_file($envLocalPath)) {
        return;
    }

    $envDefaultPath = $projectRoot.'/.env';

    if (is_file($envDefaultPath)) {
        $defaultVars = new EnvFileParser()->parseFile($envDefaultPath);
        $configuredEnv = ($defaultVars['APP_ENV'] ?? '') |> mb_trim(...) |> mb_strtolower(...);

        if ($configuredEnv !== '' && ! in_array($configuredEnv, ['local', 'dev', 'development'], true)) {
            return;
        }
    }

    $_ENV['APP_ENV'] = 'local';
    $_SERVER['APP_ENV'] = 'local';
    putenv('APP_ENV=local');
})();
