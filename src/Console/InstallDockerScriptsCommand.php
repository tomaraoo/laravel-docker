<?php

declare(strict_types=1);

namespace Rft\LaravelDocker\Console;

use Illuminate\Console\Command;
use Rft\LaravelDocker\Composer\ComposerScriptsInjector;

final class InstallDockerScriptsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'docker:install
        {--force : Overwrite existing docker:* scripts in composer.json}
        {--init-env : Create .env.local for local Docker development if it does not exist}';

    /**
     * @var string
     */
    protected $description = 'Register docker:* scripts in composer.json and prepare .env.local for local Docker development';

    public function handle(ComposerScriptsInjector $injector): int
    {
        $basePath = $this->laravel->basePath();
        $composerJsonPath = $basePath.'/composer.json';
        $overwrite = (bool) $this->option('force');

        $result = $injector->inject($composerJsonPath, $overwrite);

        if ($result['updated']) {
            $this->components->info(sprintf(
                'Registered Composer scripts in composer.json: %s',
                implode(', ', $result['added'])
            ));
        } else {
            $this->components->info('All docker:* scripts are already present in composer.json.');
        }

        if ($injector->ensureEnvLocalGitignored($basePath.'/.gitignore')) {
            $this->components->info('Added .env.local to .gitignore.');
        }

        if ((bool) $this->option('init-env')) {
            $this->initializeEnvLocal($basePath);
        }

        return self::SUCCESS;
    }

    private function initializeEnvLocal(string $basePath): void
    {
        $envLocalPath = $basePath.'/.env.local';

        if (is_file($envLocalPath)) {
            $this->components->info('.env.local already exists; keeping existing local credentials.');

            return;
        }

        $sourcePath = null;

        if (is_file($basePath.'/.env')) {
            $sourcePath = $basePath.'/.env';
        } elseif (is_file($basePath.'/.env.example')) {
            $sourcePath = $basePath.'/.env.example';
        }

        if ($sourcePath === null) {
            $this->components->warn('Neither .env nor .env.example was found to seed .env.local.');

            return;
        }

        $contents = (string) file_get_contents($sourcePath);
        $contents = preg_replace('/^DB_CONNECTION=sqlite$/m', 'DB_CONNECTION=mysql', $contents) ?? $contents;
        $contents = preg_replace('/^#\s*DB_HOST=127\.0\.0\.1$/m', 'DB_HOST=127.0.0.1', $contents) ?? $contents;
        $contents = preg_replace('/^#\s*DB_PORT=3306$/m', 'DB_PORT=33060', $contents) ?? $contents;
        $contents = preg_replace('/^#\s*DB_DATABASE=(.+)$/m', 'DB_DATABASE=$1', $contents) ?? $contents;
        $contents = preg_replace('/^#\s*DB_USERNAME=(.+)$/m', 'DB_USERNAME=$1', $contents) ?? $contents;
        $contents = preg_replace('/^#\s*DB_PASSWORD=.*$/m', 'DB_PASSWORD=secret', $contents) ?? $contents;

        if (! str_contains($contents, 'DOCKER_APP_PORT=')) {
            $separator = ($contents !== '' && ! str_ends_with($contents, "\n")) ? PHP_EOL : '';
            $contents .= $separator.PHP_EOL."DOCKER_APP_PORT=8080\n";
        }

        file_put_contents($envLocalPath, $contents);
        $this->components->info(sprintf('Created .env.local from %s.', basename($sourcePath)));
    }
}
