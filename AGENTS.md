# AGENTS.md

## Project

Yii2 PHP extension for cache failover. Namespace: `craft\cachecascade`

| Spec | Value |
|------|-------|
| PHP | >= 7.4 |
| Framework | Yii2 ^2.0.45 |
| Linter | [Mago](https://github.com/carthage-software/mago/) |
| Tests | PHPUnit 9.5 |

## Commands

```bash
composer install              # Install dependencies
composer lint                 # Lint all
composer fix                  # Auto-fix
composer test                 # All tests

# Granular
vendor/bin/mago lint src/Foo.php  # Lint one file
vendor/bin/phpunit tests/CascadeCacheTest.php  # One test file
vendor/bin/phpunit tests/CascadeCacheTest.php --filter testGetFromPrimaryCache  # One method
```

## Code Rules

- Strongly type whenever possible
- Follow PSR

### PHPDoc

- Use `/** @inheritdoc */` for parent overrides
- NEVER write redundant docs that repeat method/param names

```php
// WRONG - description repeats the name
/** @var CacheInterface[] $caches The cache instances */

// CORRECT - type is sufficient
/** @var CacheInterface[] $caches */
```

## Testing

### Mocking

```php
$mock = $this->createMock(CacheInterface::class);
$mock->method('get')->willReturn('value');
$mock->method('set')->willThrowException(new \RuntimeException('Failed'));
```

### Exceptions (expectException BEFORE triggering code)

```php
$this->expectException(InvalidConfigException::class);
new CascadeCache(['caches' => []]);
```

## Error Handling

- NEVER swallow exceptions. ALWAYS log or re-throw the exception.
- When re-throwing exceptions, include the original exception.

## Workflow

### Before Committing

1. `composer lint` - MUST pass
2. `composer test` - MUST pass
3. New functionality MUST have tests

### Git

- NEVER commit to `main`, `1.x`, or any canonical semver branches (x.x)

### General

- RTFM before debugging
- Check for MCPs for tools/libraries
- Prefer existing libraries over custom implementations
- Comments ONLY for: non-obvious logic, workarounds, issue refs
- TODO: `// @TODO: description (issue ref)`
