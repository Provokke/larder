<?php

declare(strict_types=1);

namespace Larder;

use Closure;
use Larder\Exception\ContainerException;
use Larder\Exception\NotFoundException;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionParameter;

final class Container implements ContainerInterface
{
    private array $bindings = [];
    private array $instances = [];
    private array $aliases = [];
    private array $extenders = [];
    private array $contextual = [];
    private array $tags = [];
    private array $stack = [];
    private readonly Autowirer $autowirer;

    public function __construct()
    {
        $this->autowirer = new Autowirer($this);
        $this->instances[self::class] = $this;
        $this->instances[ContainerInterface::class] = $this;
    }

    public function bind(string $id, Closure|string|null $concrete = null, bool $shared = false): static
    {
        $id = $this->canonical($id);
        unset($this->instances[$id]);
        $this->bindings[$id] = new Binding($concrete, $shared);

        return $this;
    }

    public function singleton(string $id, Closure|string|null $concrete = null): static
    {
        return $this->bind($id, $concrete, true);
    }

    public function instance(string $id, mixed $value): static
    {
        $id = $this->canonical($id);
        unset($this->bindings[$id]);
        $this->instances[$id] = $value;

        return $this;
    }

    public function alias(string $alias, string $id): static
    {
        if ($this->canonical($id) === $alias) {
            throw new ContainerException(sprintf('"%s" cannot be an alias of itself.', $alias));
        }
        $this->aliases[$alias] = $id;

        return $this;
    }

    public function extend(string $id, Closure $decorator): static
    {
        $id = $this->canonical($id);

        if (array_key_exists($id, $this->instances)) {
            $this->instances[$id] = $decorator($this->instances[$id], $this);
        } else {
            $this->extenders[$id][] = $decorator;
        }

        return $this;
    }

    public function when(string $class): ContextualBindingBuilder
    {
        return new ContextualBindingBuilder($this, $class);
    }

    public function addContextual(string $class, string $needs, ContextualRule $rule): static
    {
        $this->contextual[$class][$needs] = $rule;

        return $this;
    }

    public function contextualRule(string $class, ReflectionParameter $parameter): ?ContextualRule
    {
        $rules = $this->contextual[$class] ?? null;
        if ($rules === null) {
            return null;
        }

        if (isset($rules['$' . $parameter->getName()])) {
            return $rules['$' . $parameter->getName()];
        }

        foreach ($this->autowirer->classNames($parameter) as $type) {
            if (isset($rules[$type])) {
                return $rules[$type];
            }
        }

        return null;
    }

    public function tag(array|string $ids, string $tag): static
    {
        foreach ((array) $ids as $id) {
            if (!in_array($id, $this->tags[$tag] ?? [], true)) {
                $this->tags[$tag][] = $id;
            }
        }

        return $this;
    }

    public function tagged(string $tag): array
    {
        return array_map(fn (string $id): mixed => $this->get($id), $this->tags[$tag] ?? []);
    }

    public function register(ServiceProvider $provider): static
    {
        $provider->register($this);

        return $this;
    }

    public function forget(string $id): static
    {
        $id = $this->canonical($id);
        unset($this->bindings[$id], $this->instances[$id], $this->extenders[$id]);

        return $this;
    }

    public function has(string $id): bool
    {
        $id = $this->canonical($id);

        return array_key_exists($id, $this->instances)
            || isset($this->bindings[$id])
            || $this->autowirer->canBuild($id);
    }

    public function get(string $id): mixed
    {
        return $this->resolve($this->canonical($id), [], false);
    }

    public function make(string $id, array $parameters = []): mixed
    {
        return $this->resolve($this->canonical($id), $parameters, true);
    }

    public function call(callable|array|string $callable, array $parameters = []): mixed
    {
        if (is_string($callable) && str_contains($callable, '::')) {
            $callable = explode('::', $callable, 2);
        }

        if (is_array($callable)) {
            [$target, $method] = $callable;
            $reflection = new ReflectionMethod($target, $method);
            $object = null;
            if (!$reflection->isStatic()) {
                $object = is_object($target) ? $target : $this->get($target);
            }
            $class = is_object($target) ? $target::class : $target;
            $arguments = $this->autowirer->arguments($reflection, $parameters, $class);

            return $reflection->invokeArgs($object, $arguments);
        }

        if (is_object($callable) && !$callable instanceof Closure) {
            $reflection = new ReflectionMethod($callable, '__invoke');

            return $reflection->invokeArgs($callable, $this->autowirer->arguments($reflection, $parameters, $callable::class));
        }

        $reflection = new ReflectionFunction($callable);

        return $reflection->invokeArgs($this->autowirer->arguments($reflection, $parameters, null));
    }

    private function canonical(string $id): string
    {
        $seen = [];
        while (isset($this->aliases[$id])) {
            if (isset($seen[$id])) {
                throw new ContainerException(sprintf('Alias loop at "%s".', $id));
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
    }

    private function resolve(string $id, array $parameters, bool $fresh): mixed
    {
        if (!$fresh && array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->stack, true)) {
            throw new ContainerException(sprintf(
                'Circular dependency: %s.',
                implode(' -> ', [...$this->stack, $id]),
            ));
        }

        $this->stack[] = $id;

        try {
            $binding = $this->bindings[$id] ?? null;
            $concrete = $binding?->concrete ?? $id;

            if ($concrete instanceof Closure) {
                $object = $concrete($this, $parameters);
            } elseif ($concrete !== $id) {
                $object = $this->resolve($this->canonical($concrete), $parameters, $fresh);
            } else {
                $object = $this->autowirer->build($id, $parameters);
            }

            foreach ($this->extenders[$id] ?? [] as $decorator) {
                $object = $decorator($object, $this);
            }

            if ($binding !== null && $binding->shared && !$fresh) {
                $this->instances[$id] = $object;
            }

            return $object;
        } finally {
            array_pop($this->stack);
        }
    }
}
