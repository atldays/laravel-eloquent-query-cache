<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Test\Models\Comment;
use Atldays\QueryCache\Test\Models\Post;
use Atldays\QueryCache\Test\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

class MorphToTest extends BaseTestCase
{
    /**
     * @dataProvider strictModeContextProvider
     */
    #[DataProvider('strictModeContextProvider')]
    public function test_morph_to_relation_inherits_cache_configuration()
    {
        $post = factory(Post::class)->create();
        $comment = Comment::query()->create([
            'body' => 'Test comment',
            'commentable_id' => $post->id,
            'commentable_type' => Post::class,
        ]);

        $relation = $comment->commentable()
            ->cacheFor(now()->addHours(1))
            ->cacheTags(['commentable'])
            ->cachePrefix('morph-test')
            ->withPlainKey();

        $resolved = $relation->first();
        $cacheKey = $relation->getQuery()->getQuery()->getCacheKey('get');
        $cache = $this->getCacheWithTags($cacheKey, ['commentable']);

        $this->assertInstanceOf(Post::class, $resolved);
        $this->assertSame($post->id, $resolved->id);
        $this->assertNotNull($cache);

        if ($this->driverSupportsTags()) {
            $this->assertNull(Cache::get($cacheKey));
        }
    }
}
