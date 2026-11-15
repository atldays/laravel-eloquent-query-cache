# Contributing

Thanks for contributing to `atldays/laravel-eloquent-query-cache`.

This package is maintained as an open-source fork of the original project, with a focus on modern Laravel compatibility, reliable test coverage, and a clean contributor experience.

## Before You Start

Before opening an issue or pull request:

- check whether the behavior already exists in the package
- check whether there is already an open issue or pull request for the same topic
- make sure the change is useful beyond a single private use case

## Development Workflow

Local development requires PHP 8.4.1+ for the current PHPUnit and Testbench versions. CI overrides these development dependencies to test Laravel 12 and PHP 8.2/8.3; the package's runtime requirements remain those declared in `require`.

CI saves XML and HTML coverage reports for both cache drivers as GitHub Actions artifacts for each matrix combination. The artifacts are retained for 14 days; open `coverage/array/index.html` or `coverage/file/index.html` after downloading an artifact to view the reports.

### Code style

This project uses [Laravel Pint](https://laravel.com/docs/pint) for formatting.

Run:

```bash
vendor/bin/pint --test
```

To apply formatting:

```bash
vendor/bin/pint
```

### Tests

All changes should be covered by tests when behavior changes.

Run the test suite with:

```bash
vendor/bin/phpunit
composer test:security
```

Run the full suite with both supported test cache drivers:

```bash
CACHE_DRIVER=array vendor/bin/phpunit
CACHE_DRIVER=file vendor/bin/phpunit
composer validate --strict --no-check-lock
```

CI covers PHP 8.2, 8.3, 8.4, and 8.5 with Laravel 12 and 13, using both `prefer-lowest` and `prefer-stable`. Laravel 13 is excluded on PHP 8.2. This produces 14 dependency combinations, each tested with both cache drivers. Use the development dependency overrides in `.github/workflows/ci.yml` when reproducing an older PHP/Laravel combination.

Projection and scalar tests compare native Laravel results and types and count actual SQL executions on both cache misses and hits. Keep coverage for reusable builders, expressions, identical SQL with different projection arguments, casts/accessors, callbacks, cache errors, invalidation, and disabled cache modes. The operation table and callback contract are documented in README; contributor commands belong in this file.

If you use the same Docker-based workflow as this repository:

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD:/app" -w /app composer:2 sh -lc 'vendor/bin/pint --test'
docker run --rm -u $(id -u):$(id -g) -v "$PWD:/app" -w /app composer:2 sh -lc 'composer test'
```

### PostgreSQL integration tests

The regular SQLite matrix verifies cache bypass policy for locking queries. The `postgres` group additionally checks real `exists()` and `pluck()` lock contention using two PostgreSQL connections, including `FOR UPDATE SKIP LOCKED`, exact `NUMERIC`/`DECIMAL` values, native booleans, and database errors for aggregates with forbidden row locks.

CI runs the whole group in separate PostgreSQL 17 jobs on PHP 8.5 for Laravel 12 and 13. Mark additional PostgreSQL test classes with `#[Group('postgres')]`; a class-name filter would miss other classes. Skipped tests or an empty test suite fail these jobs. JUnit reports are saved as GitHub Actions artifacts for 14 days, including on test failures.

These tests require `pdo_pgsql` and an explicitly configured test database. They create and remove uniquely named tables and are skipped when `QUERY_CACHE_PGSQL_DSN` is not set.

```bash
QUERY_CACHE_PGSQL_DSN='pgsql:host=127.0.0.1;dbname=query_cache_test' \
QUERY_CACHE_PGSQL_USER=postgres \
QUERY_CACHE_PGSQL_PASSWORD=postgres \
vendor/bin/phpunit --group postgres --fail-on-skipped --fail-on-empty-test-suite
```

### Consumer verification

Before a release, install a copy or archive of the prepared package into a separate temporary Composer project. Exercise `value()`, both forms of `pluck()`, and `count`/`sum`/`avg`/`min`/`max` using only the public cache API. Check SQL counts, null results, scalar types, observer/tag invalidation, and bypass behavior against an existing cache entry. Verify the minimum supported PHP/Laravel combination and the current combination. The consumer should use its own database, cache, and vendor directory.

### Git hooks

This repository includes git hooks for local quality checks.

- `pre-commit` runs Pint on staged PHP files
- `commit-msg` validates Conventional Commit messages
- `pre-push` runs the test suite

If hooks are enabled locally, they help catch most issues before CI does.

## Commit Messages

All commits must follow the [Conventional Commits](https://www.conventionalcommits.org/) specification.

Format:

```text
<type>(optional-scope): <description>
```

Examples:

```text
feat(cache): add MorphTo query cache support
fix(ci): align cache config for file driver tests
test(cache): add MorphTo coverage and clean up test suite
docs(readme): document fork-specific behavior
```

Allowed commit types:

- `build`
- `chore`
- `ci`
- `docs`
- `feat`
- `fix`
- `perf`
- `refactor`
- `revert`
- `style`
- `test`

## Pull Request Guidelines

Please keep pull requests focused and easy to review.

- prefer one change set per pull request
- include tests for bug fixes and new behavior
- update `README.md` or other docs when public behavior changes
- keep commit history clean and meaningful
- avoid unrelated refactors in the same pull request

## Compatibility Expectations

This package aims to stay compatible with the Laravel and PHP versions declared in `composer.json`.

When contributing:

- avoid changes that silently break supported Laravel versions
- prefer backward-compatible improvements when possible
- add or update tests when framework-specific behavior changes

## Security

If you discover a security issue, please do not publish sensitive details in a public issue right away. Contact the maintainers privately first.
