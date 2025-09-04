<?php

declare(strict_types=1);

namespace Larder;

use Closure;

final class ContextualBindingBuilder
{
    private ?string $needs = null;

    public function __construct(
        private readonly Container $container,
        private readonly string $class,
    ) {
    }

    public function needs(string $abstractOrParameter): self
    {
        $this->needs = $abstractOrParameter;

        return $this;
    }

    public function give(Closure|string $concrete): Container
    {
        $rule = $concrete instanceof Closure ? ContextualRule::factory($concrete) : ContextualRule::service($concrete);

        return $this->container->addContextual($this->class, $this->requireNeeds(), $rule);
    }

    public function giveValue(mixed $value): Container
    {
        return $this->container->addContextual($this->class, $this->requireNeeds(), ContextualRule::value($value));
    }

    private function requireNeeds(): string
    {
        if ($this->needs === null) {
            throw new Exception\ContainerException('Call needs() before give().');
        }

        return $this->needs;
    }
}
