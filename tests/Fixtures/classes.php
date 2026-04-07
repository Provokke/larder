<?php

declare(strict_types=1);

namespace Larder\Tests\Fixtures;

interface Logger
{
    public function log(string $line): void;
}

final class NullLogger implements Logger
{
    public function log(string $line): void
    {
    }
}

final class MemoryLogger implements Logger
{
    public array $lines = [];

    public function log(string $line): void
    {
        $this->lines[] = $line;
    }
}

final class Clock
{
    public static int $created = 0;

    public function __construct()
    {
        self::$created++;
    }
}

final class Mailer
{
    public function __construct(public readonly Logger $logger)
    {
    }
}

final class Notifier
{
    public function __construct(public readonly Mailer $mailer, public readonly Clock $clock)
    {
    }
}

final class WithDefaults
{
    public function __construct(public readonly int $retries = 3, public readonly string $name = 'default')
    {
    }
}

final class WithNullable
{
    public function __construct(public readonly ?Logger $logger = null)
    {
    }
}

final class NeedsScalar
{
    public function __construct(public readonly int $port)
    {
    }
}

final class CircularA
{
    public function __construct(public readonly CircularB $b)
    {
    }
}

final class CircularB
{
    public function __construct(public readonly CircularA $a)
    {
    }
}

abstract class Shape
{
}

final class PrivateConstructor
{
    private function __construct()
    {
    }
}

final class Greeter
{
    public function greet(Clock $clock, string $name = 'you'): string
    {
        return 'hello ' . $name . ($clock instanceof Clock ? '!' : '?');
    }

    public static function shout(string $text = 'hey'): string
    {
        return strtoupper($text);
    }
}

final class Invokable
{
    public function __invoke(Clock $clock, int $times = 1): int
    {
        return $times * 2;
    }
}

final class UnionDependency
{
    public function __construct(public readonly Logger|Clock $dependency)
    {
    }
}

final class VariadicNames
{
    public readonly array $names;

    public function __construct(string ...$names)
    {
        $this->names = $names;
    }
}

final class NoConstructor
{
}

final class ReportService
{
    public function __construct(public readonly Logger $logger)
    {
    }
}

final class AuditService
{
    public function __construct(public readonly Logger $logger)
    {
    }
}

final class FileStore
{
    public function __construct(public readonly string $path, public readonly Logger $logger)
    {
    }
}

interface Plugin
{
    public function name(): string;
}

final class PluginA implements Plugin
{
    public function name(): string
    {
        return 'a';
    }
}

final class PluginB implements Plugin
{
    public function name(): string
    {
        return 'b';
    }
}

enum Suit
{
    case Hearts;
}
