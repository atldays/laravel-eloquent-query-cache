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

If you use the same Docker-based workflow as this repository:

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD:/app" -w /app composer:2 sh -lc 'vendor/bin/pint --test'
docker run --rm -u $(id -u):$(id -g) -v "$PWD:/app" -w /app composer:2 sh -lc 'composer test'
```

### PostgreSQL lock tests

The regular SQLite matrix verifies cache bypass policy for locking queries. `PostgresLockTest` additionally checks real lock contention using two PostgreSQL connections, including `FOR UPDATE SKIP LOCKED`.

CI runs these eight tests in a separate PostgreSQL 17 job on PHP 8.5 for Laravel 12 and 13. Skipped tests or an empty test suite fail this job. JUnit reports are saved as GitHub Actions artifacts for 14 days, including on test failures.

These tests require `pdo_pgsql` and an explicitly configured test database. They create and remove uniquely named tables and are skipped when `QUERY_CACHE_PGSQL_DSN` is not set.

```bash
QUERY_CACHE_PGSQL_DSN='pgsql:host=127.0.0.1;dbname=query_cache_test' \
QUERY_CACHE_PGSQL_USER=postgres \
QUERY_CACHE_PGSQL_PASSWORD=postgres \
vendor/bin/phpunit --filter PostgresLockTest
```

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
