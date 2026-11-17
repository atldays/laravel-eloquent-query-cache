<?php

declare(strict_types=1);

namespace Atldays\QueryCache\Test;

use Atldays\QueryCache\Query\Builder as CacheBuilder;
use Atldays\QueryCache\Test\Factories\BookFactory;
use Atldays\QueryCache\Test\Factories\PostFactory;
use Atldays\QueryCache\Test\Factories\UserFactory;
use Atldays\QueryCache\Test\Models\Book;
use Atldays\QueryCache\Test\Models\Comment;
use Atldays\QueryCache\Test\Models\Post;
use Atldays\QueryCache\Test\Models\SoftDeletedPost;
use Atldays\QueryCache\Test\Support\RecordingRepository;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as UncachedUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class MorphToConstrainTest extends TestCase
{
    public static function eagerLoadingModes(): iterable
    {
        foreach (self::strictModeContextProvider() as [$strictMode]) {
            foreach (['with', 'load'] as $mode) {
                yield [$strictMode, $mode, []];
                yield [$strictMode, $mode, null];
            }
        }
    }

    #[DataProvider('eagerLoadingModes')]
    public function test_eager_load_preserves_the_user_filter_with_or_without_tags(bool $strictMode, string $mode, ?array $tags): void
    {
        foreach (['Allowed', 'Excluded'] as $name) {
            $this->commentFor(PostFactory::new()->create(['name' => $name]));
        }

        $calls = 0;
        $configure = function (MorphTo $relation) use (&$calls, $tags): void {
            $relation->cacheFor(60)->cachePrefix('filtered');
            if ($tags !== null) {
                $relation->cacheTags($tags);
            }
            $relation->constrain([Post::class => function (Builder $query) use (&$calls): void {
                $calls++;
                $query->where('name', '!=', 'Excluded');
            }]);
        };

        $this->startQueryLog();
        foreach ([1, 0] as $expectedQueries) {
            $comments = $this->loadComments($configure, $mode);

            $this->assertSame(['Allowed', null], $comments->map(fn (Comment $comment) => $comment->commentable?->name)->all());
            $queries = $this->queriesFor('posts');
            $this->assertCount($expectedQueries, $queries);
            $this->assertCount(1, $this->queriesFor('comments'));
            if ($expectedQueries === 1) {
                $this->assertContains('Excluded', $queries[0]['bindings']);
            }
            DB::flushQueryLog();
        }
        $this->assertSame(2, $calls);
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_morph_map_types_keep_independent_constraints_and_the_latest_callback(bool $strictMode): void
    {
        $originalMap = Relation::morphMap();
        Relation::morphMap(['cached-post' => Post::class, 'cached-book' => Book::class], false);

        try {
            foreach (['Allowed', 'Excluded'] as $name) {
                $this->commentFor(PostFactory::new()->create(['name' => $name]));
                $this->commentFor(BookFactory::new()->create(['name' => $name]));
            }
            $calls = [];
            $configure = function (MorphTo $relation) use (&$calls): void {
                $relation->cacheFor(60)->constrain([
                    Post::class => fn () => $this->fail('The replacement callback must take precedence.'),
                    Book::class => function (Builder $query) use (&$calls): void {
                        $calls[] = Book::class;
                        $query->where('name', 'Excluded');
                    },
                ])->constrain([
                    Post::class => function (Builder $query) use (&$calls): void {
                        $calls[] = Post::class;
                        $query->where('name', 'Allowed');
                    },
                ]);

                $queryCount = count(DB::getQueryLog());
                $relation->createModelByType('cached-post');
                $relation->createModelByType('cached-book');
                $relation->createModelByType('cached-post');
                $this->assertSame([], $calls);
                $this->assertCount($queryCount, DB::getQueryLog());
            };

            foreach ([1, 0] as $expectedQueries) {
                $calls = [];
                $this->startQueryLog();
                $comments = $this->loadComments($configure, 'load');
                $this->assertSame(['cached-post', 'cached-book', 'cached-post', 'cached-book'], $comments->pluck('commentable_type')->all());
                $this->assertSame(['Allowed', null, null, 'Excluded'], $comments->map(fn (Comment $comment) => $comment->commentable?->name)->all());
                $this->assertSame([Post::class, Book::class], $calls);
                $this->assertCount($expectedQueries, $this->queriesFor('posts'));
                $this->assertCount($expectedQueries, $this->queriesFor('books'));
            }
        } finally {
            Relation::morphMap($originalMap, false);
        }
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_cache_defaults_and_type_overrides_use_separate_stores_and_keys(bool $strictMode): void
    {
        $this->commentFor(PostFactory::new()->create());
        $this->commentFor(BookFactory::new()->create());
        $store = Cache::store();
        $overrideStore = config('cache.default') === 'array' ? Cache::store('file') : Cache::store('array');
        $overrideStore->flush();
        $overrideTtl = now()->addMinutes(5);
        $queries = [];
        $configure = function (MorphTo $relation) use ($store, $overrideStore, $overrideTtl, &$queries): void {
            $relation->cacheFor(60)->cacheDriver($store)->cacheTags(['defaults'])->cachePrefix('inherited')->withPlainKey()
                ->constrain([
                    Post::class => function (Builder $query) use ($overrideStore, $overrideTtl, &$queries): void {
                        $query->cacheFor($overrideTtl)->cacheDriver($overrideStore)->cacheTags(['posts-only'])->cachePrefix('overridden')->withPlainKey(false);
                        $queries[Post::class] = $query->getQuery();
                    },
                    Book::class => function (Builder $query) use (&$queries): void {
                        $queries[Book::class] = $query->getQuery();
                    },
                ]);
        };

        foreach ([1, 0] as $expectedQueries) {
            $this->startQueryLog();
            $comments = $this->loadComments($configure);
            $this->assertInstanceOf(Post::class, $comments[0]->commentable);
            $this->assertInstanceOf(Book::class, $comments[1]->commentable);
            $this->assertSame($overrideTtl, $queries[Post::class]->getCacheFor());
            $this->assertSame($overrideStore, $queries[Post::class]->getCacheDriver());
            $this->assertSame(['posts-only'], $queries[Post::class]->getCacheTags());
            $this->assertMatchesRegularExpression('/^overridden:[a-f0-9]{64}$/', $queries[Post::class]->getCacheKey());
            $this->assertSame(60, $queries[Book::class]->getCacheFor());
            $this->assertSame($store, $queries[Book::class]->getCacheDriver());
            $this->assertSame(['defaults'], $queries[Book::class]->getCacheTags());
            $this->assertStringStartsWith('inherited:sqlitegetselect ', $queries[Book::class]->getCacheKey());

            foreach ($queries as $query) {
                $key = $query->getCacheKey();
                $this->assertNotNull($query->getCache()->get($key));
                $otherStore = $query->getCacheDriver() === $store ? $overrideStore : $store;
                $this->assertNull($otherStore->get($key));
            }
            $this->assertCount($expectedQueries, $this->queriesFor('posts'));
            $this->assertCount($expectedQueries, $this->queriesFor('books'));
        }
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_type_scope_and_before_query_preserve_order_and_can_bypass_warm_cache(bool $strictMode): void
    {
        $post = PostFactory::new()->create(['name' => 'Original']);
        $this->commentFor($post);
        $store = new RecordingRepository(Cache::store()->getStore());

        foreach (['warm', 'hit', 'dontCache', 'beforeQuery'] as $mode) {
            $events = [];
            $store->keys = $store->writes = [];
            if ($mode === 'dontCache' || $mode === 'beforeQuery') {
                DB::table('posts')->where('id', $post->id)->update(['name' => $mode]);
            }
            $this->startQueryLog();
            $comments = $this->loadComments(function (MorphTo $relation) use ($store, $mode, &$events): void {
                $relation->cacheFor(60)->cacheDriver($store)->cachePrefix('scope')->constrain([
                    Post::class => function (Builder $query) use ($mode, &$events): void {
                        $events[] = 'constrain';
                        if ($mode === 'dontCache') {
                            $query->dontCache();
                        }
                        $query->withGlobalScope('named', function (Builder $query) use (&$events): void {
                            $events[] = 'scope';
                            $query->whereNotNull('name');
                        });
                        $query->getQuery()->beforeQuery(function (CacheBuilder $query) use ($mode, &$events): void {
                            $events[] = 'beforeQuery';
                            if ($mode === 'beforeQuery') {
                                $query->dontCache();
                            }
                        });
                    },
                ]);
            });
            $this->assertSame(['constrain', 'scope', 'beforeQuery'], $events);
            $this->assertCount($mode === 'hit' ? 0 : 1, $this->queriesFor('posts'));
            $this->assertSame(in_array($mode, ['warm', 'hit'], true) ? 'Original' : $mode, $comments[0]->commentable->name);
            $this->assertCount(in_array($mode, ['warm', 'hit'], true) ? 1 : 0, $store->keys);
            $this->assertCount($mode === 'warm' ? 1 : 0, $store->writes);
            if ($mode !== 'hit') {
                $this->assertStringContainsString('"name" is not null', $this->queriesFor('posts')[0]['query']);
            }
        }
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_projections_nested_relations_counts_and_soft_deletes_keep_laravel_semantics(bool $strictMode): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->softDeletes();
        });
        $author = UserFactory::new()->create();
        $post = SoftDeletedPost::query()->create(['name' => 'Deleted', 'user_id' => $author->id]);
        $post->delete();
        $this->commentFor($post);

        $configure = function (bool $includeTrashed) {
            return function (MorphTo $relation) use ($includeTrashed): void {
                $relation->cacheFor(60)
                    ->morphWith([SoftDeletedPost::class => ['author:id,name']])
                    ->morphWithCount([SoftDeletedPost::class => ['author']])
                    ->constrain([
                        SoftDeletedPost::class => fn (Builder $query) => $query->addSelect(DB::raw('posts.name as projected_name')),
                    ]);

                if ($includeTrashed) {
                    $relation->withTrashed();
                }
            };
        };

        $this->assertNull($this->loadComments($configure(false))[0]->commentable);
        foreach ([1, 0] as $expectedQueries) {
            $this->startQueryLog();
            $resolved = $this->loadComments($configure(true))[0]->commentable;
            $this->assertInstanceOf(SoftDeletedPost::class, $resolved);
            $this->assertSame($post->id, $resolved->getKey());
            $this->assertSame('Deleted', $resolved->name);
            $this->assertSame('Deleted', $resolved->projected_name);
            $this->assertTrue($resolved->trashed());
            $this->assertSame(1, $resolved->author_count);
            $this->assertTrue($resolved->relationLoaded('author'));
            $this->assertSame($author->id, $resolved->author->id);
            $this->assertSame(['id', 'name'], array_keys($resolved->author->getAttributes()));
            $this->assertCount($expectedQueries, $this->queriesFor('posts'));
        }
    }

    #[DataProvider('strictModeContextProvider')]
    public function test_different_projections_and_filters_are_cached_independently(bool $strictMode): void
    {
        $this->commentFor(PostFactory::new()->create(['name' => 'Allowed']));
        $this->commentFor(PostFactory::new()->create(['name' => 'Excluded']));

        foreach ([1, 0] as $expectedQueries) {
            foreach (['matched' => ['Allowed', ['id', 'name']], 'different columns' => ['Allowed', ['id']], 'different filter' => ['Excluded', ['id']]] as [$name, $columns]) {
                $this->startQueryLog();
                $comments = $this->loadComments(function (MorphTo $relation) use ($name, $columns): void {
                    $relation->cacheFor(60)->constrain([
                        Post::class => fn (Builder $query) => $query->select($columns)->where('name', $name),
                    ]);
                });
                $index = $name === 'Allowed' ? 0 : 1;
                $this->assertSame($columns, array_keys($comments[$index]->commentable->getAttributes()));
                $this->assertNull($comments[1 - $index]->commentable);
                $this->assertCount($expectedQueries, $this->queriesFor('posts'));
            }
        }
    }

    public function test_cacheable_relation_can_eager_load_a_model_without_query_cache(): void
    {
        $user = UserFactory::new()->create();
        $this->commentFor(UncachedUser::query()->findOrFail($user->id));

        $comments = $this->loadComments(function (MorphTo $relation): void {
            $relation->cacheFor(60)->constrain([
                UncachedUser::class => fn (Builder $query) => $query->select('id')->whereNotNull('name'),
            ]);
        });

        $this->assertSame(['id' => $user->id], $comments[0]->commentable->getAttributes());
    }

    private function commentFor(Model $model): void
    {
        Comment::query()->create([
            'body' => 'Test comment',
            'commentable_id' => $model->getKey(),
            'commentable_type' => $model->getMorphClass(),
        ]);
    }

    /** @return Collection<int, Comment> */
    private function loadComments(Closure $configure, string $mode = 'with'): Collection
    {
        $query = Comment::query()->dontCache()->orderBy('id');
        $relations = ['commentable' => $configure];

        return $mode === 'with' ? $query->with($relations)->get() : $query->get()->load($relations);
    }

    private function startQueryLog(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
    }

    private function queriesFor(string $table): array
    {
        return array_values(array_filter(DB::getQueryLog(), fn (array $entry) => str_contains($entry['query'], 'from "'.$table.'"')));
    }
}
