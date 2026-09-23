<?php

namespace Lunar\Tests\Checkout;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Lunar\Checkout\CheckoutServiceProvider;
use Lunar\Core\LunarServiceProvider;
use Lunar\Nestedset\NestedSetServiceProvider;
use Lunar\Shipping\ShippingServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelBlink\BlinkServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

use function Orchestra\Testbench\after_resolving;
use function Orchestra\Testbench\default_migration_path;

class TestCase extends BaseTestCase
{
    private static bool $databasePrepared = false;

    private static bool $storagePrepared = false;

    protected function getPackageProviders($app)
    {
        return [
            LunarServiceProvider::class,
            BlinkServiceProvider::class,
            CheckoutServiceProvider::class,
            // The checkout serves its own Inertia app and start() returns an
            // Inertia location redirect: its provider registers the
            // Request::inertia() macro the response relies on.
            InertiaServiceProvider::class,
            MediaLibraryServiceProvider::class,
            PermissionServiceProvider::class,
            ActivitylogServiceProvider::class,
            NestedSetServiceProvider::class,
            // Installed alongside core: the delivery step derives its
            // countries from the shipping zones when it is present, so the
            // test environment needs its schema.
            ShippingServiceProvider::class,
            // Projected DTOs (addresses, snapshots) are spatie/laravel-data
            // objects; Inertia's prop resolver reads this provider's config
            // when it serializes them.
            LaravelDataServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        Model::preventLazyLoading();

        $this->configureStorage($app);

        match (env('DB_DRIVER', 'sqlite')) {
            'mysql' => $this->configureMysql($app),
            'pgsql' => $this->configurePgsql($app),
            default => $this->configureSqlite($app),
        };
    }

    // Media tests write real files, and media ids restart at 1 in every worker's
    // database, so on a shared root one worker deleting `public/1` can fail
    // another worker's upload mid-write. Give each worker its own root.
    private function configureStorage($app): void
    {
        $root = sys_get_temp_dir().'/lunar-checkout-test-storage-'.$this->workerToken();
        $filesystem = new Filesystem;

        // Once per process, so a previous run's files can't leak into assertions.
        if (! static::$storagePrepared) {
            $filesystem->deleteDirectory($root);
            static::$storagePrepared = true;
        }

        $filesystem->ensureDirectoryExists($root.'/public');
        $filesystem->ensureDirectoryExists($root.'/private');

        $app['config']->set('filesystems.disks.public.root', $root.'/public');
        $app['config']->set('filesystems.disks.local.root', $root.'/private');
    }

    private function configureSqlite($app): void
    {
        // File-backed SQLite per worker; Testbench wipes RefreshDatabase's
        // cache for `:memory:`, forcing a full migrate every test.
        $dbPath = sys_get_temp_dir().'/lunar-checkout-test-'.$this->workerToken().'.sqlite';

        // Start from a clean file once per process so a stale schema left in
        // temp by a previous run can't collide with migrations (mirrors the
        // mysql/pgsql drop-and-recreate below).
        if (! static::$databasePrepared) {
            if (file_exists($dbPath)) {
                unlink($dbPath);
            }
            touch($dbPath);
            static::$databasePrepared = true;
        }

        $app['config']->set('database.connections.testing.database', $dbPath);
    }

    private function configureMysql($app): void
    {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $username = env('DB_USERNAME', 'root');
        $password = env('DB_PASSWORD', '');
        $database = env('DB_DATABASE', 'lunar_checkout_test').'_'.$this->workerToken();

        if (! static::$databasePrepared) {
            $pdo = new \PDO("mysql:host={$host};port={$port}", $username, $password);
            $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
            $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            static::$databasePrepared = true;
        }

        $app['config']->set('database.connections.testing', [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ]);
    }

    private function configurePgsql($app): void
    {
        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '5432');
        $username = env('DB_USERNAME', 'postgres');
        $password = env('DB_PASSWORD', '');
        $database = env('DB_DATABASE', 'lunar_checkout_test').'_'.$this->workerToken();

        if (! static::$databasePrepared) {
            $pdo = new \PDO("pgsql:host={$host};port={$port};dbname=postgres", $username, $password);
            $pdo->exec("DROP DATABASE IF EXISTS \"{$database}\"");
            $pdo->exec("CREATE DATABASE \"{$database}\"");
            static::$databasePrepared = true;
        }

        $app['config']->set('database.connections.testing', [
            'driver' => 'pgsql',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    private function workerToken(): string
    {
        return (string) (env('TEST_TOKEN') ?: getmypid());
    }

    // Register laravel migrations on the migrator instead of running them
    // separately: a standalone migrate commits DDL and resets RefreshDatabase's
    // per-process cache.
    protected function defineDatabaseMigrations(): void
    {
        after_resolving($this->app, 'migrator', static function ($migrator) {
            $migrator->path(default_migration_path());
        });
    }
}
