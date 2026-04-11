# Contributing

Thanks for contributing to `atldays/laravel-eloquent-query-cache`.

This package is maintained as an open-source fork of the original project, with a focus on modern Laravel compatibility, reliable test coverage, and a clean contributor experience.

## Before You Start

Before opening an issue or pull request:

- check whether the behavior already exists in the package
- check whether there is already an open issue or pull request for the same topic
- make sure the change is useful beyond a single private use case

## Development Workflow

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
```

If you use the same Docker-based workflow as this repository:

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD:/app" -w /app composer:2 sh -lc 'vendor/bin/pint --test'
docker run --rm -u $(id -u):$(id -g) -v "$PWD:/app" -w /app composer:2 sh -lc 'composer test'
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
