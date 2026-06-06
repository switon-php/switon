# Switon DB Package Tests

Test suite for the Switon DB package, organized by test type and speed.

## Test Structure

```
tests/
├── Unit/          # Fast unit tests (no external dependencies)
├── Integration/   # Slower integration tests (requires database)
└── Fixtures/      # Test helper classes and mocks
```

## Running Tests

Run from the package root:

```bash
# Run all tests
vendor/bin/phpunit --configuration tests/phpunit.xml.dist

# Run specific test suite
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --testsuite=unit
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --testsuite=integration
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --testsuite=all

# Run specific test method
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --filter testMethodName

# Run with PHPUnit options
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --coverage-html tests/coverage
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --stop-on-failure
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --verbose
```

**Note**: Install dev dependencies first so `vendor/bin/phpunit` is available.

## Database Configuration

SQLite integration tests always run. MySQL and PostgreSQL integration tests support two modes:

- Set an environment variable to use a specific test database URI.
- Do nothing and let the tests try common local defaults, then skip automatically if the database is unavailable.

Use these variables when you want explicit test targets:

```bash
export DB_TEST_URI="mysql://root:password@localhost:3306/switon_test?charset=utf8mb4"
export PG_TEST_URI="pgsql://postgres:password@localhost:5432/switon_test"
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --testsuite=integration
```

`DB_TEST_URI` and `MYSQL_TEST_URI` are accepted for MySQL. `PG_TEST_URI` and `PGSQL_TEST_URI` are accepted for
PostgreSQL.

If no environment variable is set, MySQL and PostgreSQL tests try common local credentials against the `switon_test`
database and skip when no matching server is available.

## Test Quality Standards

### Code Quality

- **Strict Types**: All test code uses `declare(strict_types=1)`
- **No Warnings**: Clean execution with zero warnings
- **Descriptive Names**: Test methods clearly describe what they test
- **Proper Assertions**: Use specific assertions

### Test Structure

- **Arrange-Act-Assert**: Clear test phases
- **One Assertion Per Test**: Focus on single behavior
- **Descriptive Messages**: Helpful failure messages
- **Proper Cleanup**: Tests clean up after themselves

## Troubleshooting

For debugging failing tests:

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --filter testMethodName
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --verbose
```

## Contributing

When adding new tests:

- Use `Unit/` for isolated logic tests
- Use `Integration/` for tests requiring database
- Follow naming convention: `Db[Component]Test.php`
- Use appropriate namespace: `Tests\Unit` or `Tests\Integration`

---

*See main README.md for package overview and documentation.*
