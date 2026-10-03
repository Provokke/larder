<div align="center">

# larder

A small PSR-11 dependency container for PHP 8.2 that wires up your classes by reading their constructors. Bind what needs binding, let reflection handle the rest.

![License](https://img.shields.io/badge/LICENSE-MIT-blue?style=for-the-badge)  ![Language](https://img.shields.io/badge/PHP%208.2-777BB4?style=for-the-badge&logo=php&logoColor=white)  ![Tests](https://img.shields.io/badge/TESTS-57-brightgreen?style=for-the-badge)

Refer to [LICENSE](LICENSE) for the legal bits.

</div>

## What's in it

- **PSR-11 container**: `Larder\Container` implements `Psr\Container\ContainerInterface`. Its only runtime dependency is `psr/container`, and its exceptions implement the PSR-11 interfaces (`NotFoundException` is a `NotFoundExceptionInterface`).
- **Bindings**: `bind` for a new object every time, `singleton` for a shared one, `instance` for a value you already have. A concrete can be a class name or a closure that receives the container. Bind with no concrete and the id itself gets autowired.
- **Autowiring**: constructor parameters are resolved by type, recursively.
  - Class types come from the container. Union types take the first member that can be resolved.
  - Scalars use their default value, nullable parameters fall back to `null`, variadics are left empty.
  - Anything that cannot be resolved throws a `ContainerException` naming the parameter and the class.
  - Circular dependencies are caught and reported with the whole chain (`A -> B -> A`).
- **Contextual bindings**: `when(ReportService::class)->needs(Logger::class)->give(MemoryLogger::class)`, or `needs('$path')->giveValue('/var/data')` for a parameter by name.
- **Extras**: aliases (with loop detection), `extend` for decorating a service, tags, `make` for a fresh object with parameter overrides, `forget`, and service providers.
- **`call`**: invoke a closure, `[Class, 'method']`, `'Class::method'`, an invokable object or a function name, with its arguments injected.

```php
use Larder\Container;

$container = new Container();
$container->singleton(Logger::class, FileLogger::class);
$container->when(Mailer::class)->needs('$from')->giveValue('hello@example.com');

$mailer = $container->get(Mailer::class);

$container->call(function (Logger $logger, int $count = 3) {
    $logger->log("sending {$count}");
});
```

## 🏗️ Architecture

```
 Container (PSR-11)
  ├─ bindings    id ─▶ Binding(concrete: Closure|class name|null, shared)
  ├─ instances   id ─▶ resolved singleton or stored value
  ├─ aliases     alias ─▶ id
  ├─ extenders   id ─▶ [decorator closures]
  ├─ contextual  class ─▶ [needs ─▶ ContextualRule]
  ├─ tags        tag ─▶ [ids]
  └─ stack       ids currently being resolved (circular detection)
        │
        └─ Autowirer ── ReflectionClass / ReflectionFunction
                         parameter ─▶ explicit ─▶ contextual ─▶ container ─▶ default ─▶ null ─▶ error
```

- `Container` holds all the state and decides what an id means: alias, stored instance, binding, or a class to build.
- `Autowirer` does the reflection work. It builds classes and works out argument lists for methods and callables, using the same rules for both.
- `ContextualBindingBuilder` and `ContextualRule` back the `when()->needs()->give()` syntax. A rule is a service id, a factory closure or a literal value.
- Resolution order for a parameter is: parameters you passed explicitly, a contextual rule, a service from the container, the default value, `null` if the type allows it, otherwise an error.

## 🚀 Quick start

```
composer require provokke/larder
```

To work on it:

```
composer install
vendor/bin/phpunit
```

I ran the suite in Docker on `php:8.2-cli` with Composer.

```php
interface Clock { public function now(): string; }
final class SystemClock implements Clock { public function now(): string { return date('c'); } }
final class Greeter { public function __construct(private Clock $clock) {} }

$container = new Container();
$container->bind(Clock::class, SystemClock::class);

$container->get(Greeter::class);
```

`Greeter` was never registered. The container sees that it needs a `Clock`, finds the binding and builds both.

## 🧪 Testing

57 tests, 90 assertions, all passing on PHP 8.2 with PHPUnit 11:

- `ContainerTest` (25): PSR-11 conformance and exception types, closures, singletons, instances, rebinding, aliases and alias loops, `extend`, `make`, tags, `forget`, `has`, providers, and recovery after a failing factory.
- `AutowireTest` (19): recursive resolution, defaults, nullable and union types, variadics, overrides by name and position, circular chains and cleanup after them, and abstract, private-constructor and enum targets.
- `ContextualAndCallTest` (13): per-class contextual bindings with class names, closures and parameter names, precedence of explicit parameters, and `call` across closures, methods, static strings, invokables and named functions.
