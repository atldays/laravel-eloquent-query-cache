<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Page;
use Atldays\QueryCache\Test\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class FlushCacheOnUpdateTest extends BaseTestCase
{
    #[DataProvider('strictModeContextProvider')]
    public function test_flush_cache_on_create()
    {
        $page = factory(Page::class)->create();
        $storedPage = Page::cacheFor(now()->addHours(1))->first();
        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "pages" limit 1a:0:{}', ['test']);

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->id,
            $storedPage->id
        );

        Page::create([
            'name' => '9GAG',
        ]);

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "pages" limit 1a:0:{}', ['test']);

        $this->assertNull($cache);
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_flush_cache_on_update()
    {
        $page = factory(Page::class)->create();
        $storedPage = Page::cacheFor(now()->addHours(1))->first();
        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "pages" limit 1a:0:{}', ['test']);

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->id,
            $storedPage->id
        );

        $page->update([
            'name' => '9GAG',
        ]);

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "pages" limit 1a:0:{}', ['test']);

        $this->assertNull($cache);
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_flush_cache_on_delete()
    {
        $page = factory(Page::class)->create();
        $storedPage = Page::cacheFor(now()->addHours(1))->first();
        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "pages" limit 1a:0:{}', ['test']);

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->id,
            $storedPage->id
        );

        $page->delete();

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "pages" limit 1a:0:{}', ['test']);

        $this->assertNull($cache);
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_flush_cache_on_force_deletion()
    {
        $page = factory(Page::class)->create();
        $storedPage = Page::cacheFor(now()->addHours(1))->first();
        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "pages" limit 1a:0:{}', ['test']);

        $this->assertNotNull($cache);

        $this->assertEquals(
            $cache->first()->id,
            $storedPage->id
        );

        $page->forceDelete();

        $cache = $this->getCacheWithTags('leqc:sqlitegetselect * from "pages" limit 1a:0:{}', ['test']);

        $this->assertNull($cache);
    }
}
