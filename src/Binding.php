<?php

declare(strict_types=1);

namespace Larder;

use Closure;

final class Binding
{
    public function __construct(
        public readonly Closure|string|null $concrete,
        public readonly bool $shared,
    ) {
    }
}
