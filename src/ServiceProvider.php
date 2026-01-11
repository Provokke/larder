<?php

declare(strict_types=1);

namespace Larder;

interface ServiceProvider
{
    public function register(Container $container): void;
}
