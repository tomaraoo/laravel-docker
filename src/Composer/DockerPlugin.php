<?php

declare(strict_types=1);

namespace Rft\LaravelDocker\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class DockerPlugin implements EventSubscriberInterface, PluginInterface
{
    public function activate(Composer $composer, IOInterface $io): void
    {
        // Event listeners handle script injection after install/update finishes.
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // No cleanup needed on deactivation.
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // Leave root composer.json untouched during active `composer remove` write cycle.
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'registerScripts',
            ScriptEvents::POST_UPDATE_CMD => 'registerScripts',
        ];
    }

    public function registerScripts(Event $event): void
    {
        $rootPackageName = $event->getComposer()->getPackage()->getName();

        if ($rootPackageName === ComposerScriptsInjector::PACKAGE_NAME) {
            return;
        }

        $composerFile = Factory::getComposerFile();
        $composerJsonPath = realpath($composerFile) ?: $composerFile;

        $injector = new ComposerScriptsInjector;
        $result = $injector->inject($composerJsonPath);

        if ($result['updated']) {
            $event->getIO()->write(sprintf(
                '<info>[%s]</info> Registered Composer scripts: %s',
                ComposerScriptsInjector::PACKAGE_NAME,
                implode(', ', $result['added'])
            ));
        }

        $gitignorePath = dirname($composerJsonPath).'/.gitignore';

        if ($injector->ensureEnvLocalGitignored($gitignorePath)) {
            $event->getIO()->write(sprintf(
                '<info>[%s]</info> Added .env.local to .gitignore.',
                ComposerScriptsInjector::PACKAGE_NAME
            ));
        }
    }
}
