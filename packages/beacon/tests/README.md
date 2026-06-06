# Switon Beacon Package Tests

Run from the package root:

```bash
vendor/bin/phpunit --configuration tests/phpunit.xml.dist
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --testsuite=unit
vendor/bin/phpunit --configuration tests/phpunit.xml.dist --testsuite=integration
```

Install dev dependencies first so `vendor/bin/phpunit` is available.
