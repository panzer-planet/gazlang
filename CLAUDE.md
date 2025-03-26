# GazLang Development Guidelines

## Build & Test Commands
```bash
# Install dependencies
composer install

# Run all tests
vendor/bin/phpunit

# Run a specific test file
vendor/bin/phpunit tests/SpecificTest.php

# Run a specific test method
vendor/bin/phpunit --filter=testMethodName tests/SpecificTest.php

# Run interpreter on a file
php bin/gazlang -f examples/example.gaz

# Generate code instead of interpreting
php bin/gazlang -f examples/example.gaz -c
```

## Code Style Guidelines
- **Namespaces**: Use `GazLang\` namespace root with PSR-4 autoloading
- **Classes**: PascalCase (e.g., `Parser`)
- **Methods/Functions**: camelCase (e.g., `getNextToken()`)
- **Properties**: snake_case (e.g., `$current_char`)
- **Constants**: UPPERCASE (e.g., `TOKEN::INTEGER`)
- **Documentation**: PHPDoc for classes and methods with parameter/return types
- **Error Handling**: Throw exceptions with descriptive messages
- **Class Structure**: Properties at top, constructor follows, public methods first