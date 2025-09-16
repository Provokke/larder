<?php

declare(strict_types=1);

namespace Larder;

use Closure;

final class ContextualRule
{
    private function __construct(
        private readonly string $kind,
        private readonly mixed $payload,
    ) {
    }

    public static function service(string $id): self
    {
        return new self('service', $id);
    }

    public static function factory(Closure $factory): self
    {
        return new self('factory', $factory);
    }

    public static function value(mixed $value): self
    {
        return new self('value', $value);
    }

    public function resolve(Container $container): mixed
    {
        return match ($this->kind) {
            'service' => $container->get($this->payload),
            'factory' => ($this->payload)($container),
            default => $this->payload,
        };
    }
}
