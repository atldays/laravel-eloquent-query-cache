<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Book;
use Atldays\QueryCache\Test\Models\Kid;
use Atldays\QueryCache\Test\Models\Page;
use Atldays\QueryCache\Test\Models\Post;
use Atldays\QueryCache\Test\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->providedData() && method_exists(Model::class, 'preventAccessingMissingAttributes')) {
            [$strict] = $this->providedData();
            Model::preventAccessingMissingAttributes($strict);
        }

        $this->resetDatabase();
        $this->clearCache();

        $this->loadLaravelMigrations(['--database' => 'sqlite']);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->withFactories(__DIR__.'/database/factories');

        $this->artisan('migrate', ['--database' => 'sqlite']);
    }

    /**
     * {@inheritdoc}
     */
    protected function getPackageProviders($app)
    {
        return [
            LivewireServiceProvider::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => __DIR__.'/database/database.sqlite',
            'prefix' => '',
        ]);

        $app['config']->set(
            'cache.driver',
            getenv('CACHE_DRIVER') ?: env('CACHE_DRIVER', 'array')
        );

        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('auth.providers.posts.model', Post::class);
        $app['config']->set('auth.providers.kids.model', Kid::class);
        $app['config']->set('auth.providers.books.model', Book::class);
        $app['config']->set('auth.providers.pages.model', Page::class);
        $app['config']->set('app.key', 'wslxrEFGWY6GfGhvN9L3wH3KSRJQQpBD');

        $app['config']->set('view.paths', [
            __DIR__.'/views',
        ]);

        $app['config']->set('livewire.view_path', __DIR__.'/views/livewire');
    }

    /**
     * Reset the database.
     *
     * @return void
     */
    protected function resetDatabase()
    {
        file_put_contents(__DIR__.'/database/database.sqlite', '');
    }

    /**
     * Clear the cache.
     *
     * @return void
     */
    protected function clearCache()
    {
        $this->artisan('cache:clear');
    }

    /**
     * Get the cache with tags, if the driver supports it.
     *
     * @return mixed
     */
    protected function getCacheWithTags(string $key, ?array $tags = null)
    {
        return $this->driverSupportsTags()
            ? Cache::tags($tags)->get($key)
            : Cache::get($key);
    }

    public static function strictModeContextProvider(): iterable
    {
        yield [true];
        yield [false];
    }

    /**
     * Check if the current driver supports tags.
     */
    protected function driverSupportsTags(): bool
    {
        return ! in_array(config('cache.driver'), ['file', 'database']);
    }
}
