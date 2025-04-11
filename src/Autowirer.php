<?php

declare(strict_types=1);

namespace Larder;

use Larder\Exception\ContainerException;
use Larder\Exception\NotFoundException;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

final class Autowirer
{
    public function __construct(private readonly Container $container)
    {
    }

    public function canBuild(string $class): bool
    {
        return class_exists($class) && (new ReflectionClass($class))->isInstantiable();
    }

    public function build(string $class, array $parameters = []): object
    {
        if (!class_exists($class)) {
            throw NotFoundException::forId($class);
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('"%s" cannot be instantiated.', $class));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        return $reflection->newInstanceArgs($this->arguments($constructor, $parameters, $class));
    }

    public function arguments(ReflectionFunctionAbstract $function, array $parameters, ?string $context): array
    {
        $arguments = [];

        foreach ($function->getParameters() as $index => $parameter) {
            $name = $parameter->getName();

            if ($parameter->isVariadic()) {
                if (array_key_exists($name, $parameters)) {
                    array_push($arguments, ...array_values((array) $parameters[$name]));
                }
                break;
            }

            if (array_key_exists($name, $parameters)) {
                $arguments[] = $parameters[$name];
                continue;
            }

            if (array_key_exists($index, $parameters)) {
                $arguments[] = $parameters[$index];
                continue;
            }

            $arguments[] = $this->resolveParameter($parameter, $context);
        }

        return $arguments;
    }

    private function resolveParameter(ReflectionParameter $parameter, ?string $context): mixed
    {
        if ($context !== null) {
            $rule = $this->container->contextualRule($context, $parameter);
            if ($rule !== null) {
                return $rule->resolve($this->container);
            }
        }

        foreach ($this->classNames($parameter) as $class) {
            if ($this->container->has($class)) {
                return $this->container->get($class);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull() && $parameter->hasType()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Cannot resolve parameter $%s%s.',
            $parameter->getName(),
            $context === null ? '' : sprintf(' of %s', $context),
        ));
    }

    public function classNames(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                if ($member instanceof ReflectionNamedType && !$member->isBuiltin()) {
                    $names[] = $member->getName();
                }
            }

            return $names;
        }

        return [];
    }
}
