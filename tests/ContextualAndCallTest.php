<?php

declare(strict_types=1);

namespace Larder\Tests;

use Larder\Container;
use Larder\Exception\ContainerException;
use Larder\Tests\Fixtures\AuditService;
use Larder\Tests\Fixtures\Clock;
use Larder\Tests\Fixtures\FileStore;
use Larder\Tests\Fixtures\Greeter;
use Larder\Tests\Fixtures\Invokable;
use Larder\Tests\Fixtures\Logger;
use Larder\Tests\Fixtures\MemoryLogger;
use Larder\Tests\Fixtures\NullLogger;
use Larder\Tests\Fixtures\ReportService;
use PHPUnit\Framework\TestCase;

final class ContextualAndCallTest extends TestCase
{
    public function testContextualBindingPicksADifferentImplementationPerClass(): void
    {
        $container = new Container();
        $container->bind(Logger::class, NullLogger::class);
        $container->when(ReportService::class)->needs(Logger::class)->give(MemoryLogger::class);

        $this->assertInstanceOf(MemoryLogger::class, $container->get(ReportService::class)->logger);
        $this->assertInstanceOf(NullLogger::class, $container->get(AuditService::class)->logger);
    }

    public function testContextualBindingAcceptsAClosure(): void
    {
        $container = new Container();
        $container->when(ReportService::class)->needs(Logger::class)->give(function (Container $c): Logger {
            $logger = new MemoryLogger();
            $logger->log('created for report');

            return $logger;
        });

        $this->assertSame(['created for report'], $container->get(ReportService::class)->logger->lines);
    }

    public function testContextualBindingCanTargetAParameterName(): void
    {
        $container = new Container();
        $container->bind(Logger::class, NullLogger::class);
        $container->when(FileStore::class)->needs('$path')->giveValue('/var/data');

        $store = $container->get(FileStore::class);

        $this->assertSame('/var/data', $store->path);
        $this->assertInstanceOf(NullLogger::class, $store->logger);
    }

    public function testExplicitParametersBeatContextualBindings(): void
    {
        $container = new Container();
        $container->bind(Logger::class, NullLogger::class);
        $container->when(FileStore::class)->needs('$path')->giveValue('/var/data');

        $store = $container->make(FileStore::class, ['path' => '/tmp/x']);

        $this->assertSame('/tmp/x', $store->path);
    }

    public function testGiveWithoutNeedsIsRejected(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $container->when(ReportService::class)->give(NullLogger::class);
    }

    public function testCallInjectsClosureParameters(): void
    {
        $container = new Container();

        $result = $container->call(fn (Clock $clock, string $greeting = 'hi'): string => $greeting . ' ' . $clock::class);

        $this->assertSame('hi ' . Clock::class, $result);
    }

    public function testCallUsesSuppliedParameters(): void
    {
        $container = new Container();

        $result = $container->call(fn (Clock $clock, int $a, int $b): int => $a + $b, ['a' => 2, 'b' => 5]);

        $this->assertSame(7, $result);
    }

    public function testCallBuildsTheTargetForInstanceMethods(): void
    {
        $container = new Container();

        $this->assertSame('hello Sam!', $container->call([Greeter::class, 'greet'], ['name' => 'Sam']));
    }

    public function testCallUsesAGivenObject(): void
    {
        $container = new Container();

        $this->assertSame('hello you!', $container->call([new Greeter(), 'greet']));
    }

    public function testCallHandlesStaticMethodStrings(): void
    {
        $container = new Container();

        $this->assertSame('HEY', $container->call(Greeter::class . '::shout'));
        $this->assertSame('YO', $container->call(Greeter::class . '::shout', ['text' => 'yo']));
    }

    public function testCallHandlesInvokableObjects(): void
    {
        $container = new Container();

        $this->assertSame(6, $container->call(new Invokable(), ['times' => 3]));
    }

    public function testCallHandlesNamedFunctions(): void
    {
        $container = new Container();

        $this->assertSame(3, $container->call('strlen', ['string' => 'abc']));
    }

    public function testCallFailsWhenAParameterCannotBeResolved(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $container->call(fn (int $mystery): int => $mystery);
    }
}
