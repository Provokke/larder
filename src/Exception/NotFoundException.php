<?php

declare(strict_types=1);

namespace Larder\Exception;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
    public static function forId(string $id): self
    {
        return new self(sprintf('No entry or autowirable class found for "%s".', $id));
    }
}
