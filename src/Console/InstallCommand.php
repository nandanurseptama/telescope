<?php

namespace Laravel\Telescope\Console;

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'telescope:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telescope:install {--driver=database : Select driver. Default database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install all of the Telescope resources';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $this->comment('Publishing Telescope Service Provider...');
        $this->callSilent('vendor:publish', ['--tag' => 'telescope-provider']);

        $this->comment('Publishing Telescope Assets...');
        $this->callSilent('vendor:publish', ['--tag' => 'telescope-assets']);

        $this->publishTelescopeConfig();

        $this->publishTelescopeMigrations();

        $this->registerTelescopeServiceProvider();

        $this->info('Telescope scaffolding installed successfully.');
    }

    /**
     * Register the Telescope service provider in the application configuration file.
     *
     * @return void
     */
    protected function registerTelescopeServiceProvider()
    {
        if (
            method_exists(ServiceProvider::class, 'addProviderToBootstrapFile') &&
            ServiceProvider::addProviderToBootstrapFile(\App\Providers\TelescopeServiceProvider::class)
        ) { // @phpstan-ignore-line
            return;
        }

        $namespace = Str::replaceLast('\\', '', $this->laravel->getNamespace());

        $appConfig = file_get_contents(config_path('app.php'));

        if (Str::contains($appConfig, $namespace . '\\Providers\\TelescopeServiceProvider::class')) {
            return;
        }

        $lineEndingCount = [
            "\r\n" => substr_count($appConfig, "\r\n"),
            "\r" => substr_count($appConfig, "\r"),
            "\n" => substr_count($appConfig, "\n"),
        ];

        $eol = array_keys($lineEndingCount, max($lineEndingCount))[0];

        file_put_contents(config_path('app.php'), str_replace(
            "{$namespace}\\Providers\RouteServiceProvider::class," . $eol,
            "{$namespace}\\Providers\RouteServiceProvider::class," . $eol . "        {$namespace}\Providers\TelescopeServiceProvider::class," . $eol,
            $appConfig
        ));

        file_put_contents(app_path('Providers/TelescopeServiceProvider.php'), str_replace(
            "namespace App\Providers;",
            "namespace {$namespace}\Providers;",
            file_get_contents(app_path('Providers/TelescopeServiceProvider.php'))
        ));
    }

    /**
     * Publish telescope config file.
     *
     * if selected driver option is not 'database'
     * will change default driver.
     *
     * @return void
     */
    private function publishTelescopeConfig()
    {
        $this->comment('Publishing Telescope Configuration...');
        $this->callSilent('vendor:publish', ['--tag' => 'telescope-config']);

        $driver = $this->option('driver');

        if (strtolower($driver) === 'database') {
            return;
        }

        $telescopeConfig = file_get_contents(config_path('telescope.php'));

        file_put_contents(config_path('telescope.php'), str_replace(
            "env('TELESCOPE_DRIVER', 'database')",
            "env('TELESCOPE_DRIVER', 'influx')",
            $telescopeConfig
        ));
    }

    /**
     * Publish telescope migrations.
     *
     * if driver option is not 'database' dont publish migrations file
     *
     * @return void
     */
    private function publishTelescopeMigrations()
    {
        $driver = $this->option('driver');

        if (strtolower($driver) !== 'database') {
            return;
        }

        $this->comment('Publishing Telescope Migrations...');
        $this->callSilent('vendor:publish', ['--tag' => 'telescope-migrations']);
    }
}
