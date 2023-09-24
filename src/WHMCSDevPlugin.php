<?php //phpcs:disable Generic.Files.LineLength.TooLong

namespace Oblak\Composer\Plugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\Filesystem;
use Composer\Util\ProcessExecutor;
use Symfony\Component\Filesystem\Path;

/**
 * WHMCS Dev Helper plugin
 */
class WHMCSDevPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var string
     */
    private $cwd;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var ProcessExecutor
     */
    private $processExecutor;

    /**
     * {@inheritDoc}
     *
     * @throws \RuntimeException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io       = $io;

        $this->init();
    }

    /**
     * {@inheritDoc}
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return [
        ScriptEvents::POST_CREATE_PROJECT_CMD => 'postCreateProject',
        ];
    }

    /**
     * Prepares the plugin so it's main functionality can be run.
     *
     * @throws \RuntimeException
     * @throws LogicException
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    private function init()
    {
        $this->cwd = getcwd();

        $this->processExecutor = new ProcessExecutor($this->io);
        $this->filesystem      = new Filesystem($this->processExecutor);
    }

    public function postCreateProject()
    {
        $this->io->write([
        '__      __ _  _  __  __   ___  ___ ',
        '\ \    / /| || ||  \/  | / __|/ __|',
        ' \ \/\/ / | __ || |\/| || (__ \__ \'',
        '  \_/\_/  |_||_||_|  |_| \___||___/',
        '   _    ___   ___    ___   _  _    ',
        '  /_\  |   \ |   \  / _ \ | \| |   ',
        ' / _ \ | |) || |) || (_) || .` |   ',
        '/_/ \_\|___/ |___/  \___/ |_|\_|   ',
        '',
        ]);

        $moduleType   = $this->io->select(
            'What type of addon are you creating? (addons)',
            $this->getModuleTypes(),
            'addons',
            false,
            'Type %s is invalid.'
        );
        $moduleName   = $this->io->ask('What is your module name? (MyModule) ', 'MyModule');
        $classPrefix  = ucfirst($moduleName);
        $moduleName   = strtolower($moduleName);
        $vendorPrefix = ucfirst($this->io->ask('What is your vendor prefix? (MyCompany) ', 'MyCompany'));

        $this->io->write("Scaffolding {$moduleType} module: {$moduleName}");

        $this->scaffold($moduleType, $moduleName, $classPrefix, $vendorPrefix);
    }

    private function getModuleTypes(): array
    {
        return [
        'addons' => 'Addon',
        // 'fraud'  => 'Fraud',
        'gateways' => 'Payment Gateway',
        // 'mail'   => 'Mailer',
        // 'notifications' => 'Notification',
        'registrars' => 'Domain Registrar',
        // 'reports' => 'Report',
        // 'security' => 'Security',
        // 'servers' => 'Provisioning',
        // 'support' => 'Support',
        // 'widgets' => 'Widget',
        ];
    }

    private function scaffold(string $moduleType, string $moduleName, string $classPrefix, string $vendorPrefix)
    {
        mkdir("modules/{$moduleType}/{$moduleName}", 0755, true);
        mkdir("modules/{$moduleType}/callback", 0755, true);

        $toRename = [
            'module/name' => "modules/{$moduleType}/{$moduleName}",
            'includes/hooks/name.php' => "includes/hooks/{$moduleName}.php",
            'module/callback/name.php' => "modules/{$moduleType}/callback/{$moduleName}.php",
            "modules/{$moduleType}/{$moduleName}/name.php" => "modules/{$moduleType}/{$moduleName}/{$moduleName}.php",
            "modules/{$moduleType}/{$moduleName}/lib/nameModule.php" => "modules/{$moduleType}/{$moduleName}/lib/{$classPrefix}Module.php",
            "modules/{$moduleType}/{$moduleName}/lib/nameGateway.php" => "modules/{$moduleType}/{$moduleName}/lib/{$classPrefix}Gateway.php",
            "modules/{$moduleType}/{$moduleName}/lib/Utils/name-functions.php" => "modules/{$moduleType}/{$moduleName}/lib/Utils/{$moduleName}-functions.php",
            "module/name.php" => "modules/{$moduleType}/{$moduleName}.php",

        ];

        $this->rename($toRename);

        $this->filesystem->emptyDirectory("module");
        $this->filesystem->rmdir('module');

        if ($moduleType !== 'gateways') {
            $this->filesystem->unlink("modules/{$moduleType}/{$moduleName}/lib/{$classPrefix}Gateway.php");
            $this->filesystem->unlink("modules/{$moduleType}/{$moduleName}.php");
            $this->filesystem->unlink("modules/{$moduleType}/callback/{$moduleName}.php");
            $this->filesystem->rmdir("modules/{$moduleType}/callback");
            $this->filesystem->emptyDirectory("includes");
            $this->filesystem->rmdir("includes");
        }

        if ($moduleType === 'gateways') {
            $this->filesystem->unlink("modules/{$moduleType}/{$moduleName}/{$moduleName}.php");
            $this->filesystem->unlink("modules/gateways/{$moduleName}/hooks.php");
            $this->filesystem->unlink("modules/{$moduleType}/{$moduleName}/lib/{$classPrefix}Module.php");
        }

        $this->replaceStrings(array_values($toRename), $moduleType, $moduleName, $classPrefix, $vendorPrefix);
    }

    private function rename(array $toRename)
    {
        array_walk(
            $toRename,
            fn($new, $old) => $this->filesystem->rename($old, $new)
        );
    }

    private function replaceStrings(array $files, string $moduleType, string $moduleName, string $classPrefix, string $vendorPrefix)
    {
        $files[]      = $files[array_key_first($files)] . '/composer.json';
        $replacements = [
            'MODULETYPE' => $moduleType,
            'MODULENAME' => $moduleName,
            'CLASSPREFIX'  => $classPrefix,
            'VENDORPREFIX' => $vendorPrefix,
        ];

        $files = array_filter(
            $files,
            'is_file'
        );

        array_walk(
            $files,
            fn($file) => $this->replace($file, $replacements)
        );

        $manifest = explode("\n", file_get_contents('.manifest'));

        if ($moduleType === 'gateways') {
            $manifest = array_merge(
                array_filter($manifest),
                [
                    "includes/hooks/{$moduleName}.php",
                    "modules/gateways/callback/{$moduleName}.php",
                    "modules/gateways/{$moduleName}.php",
                    "",
                ]
            );
        }

        file_put_contents('.manifest', strtr(implode("\n", $manifest), $replacements));
    }

    private function replace($file, $replacements)
    {
        $contents = strtr(file_get_contents($file), $replacements);
        file_put_contents($file, $contents);
    }
}
