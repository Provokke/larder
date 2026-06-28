<?php

declare(strict_types=1);

namespace Larder\Tests;

use Larder\Container;
use Larder\Exception\ContainerException;
use Larder\Exception\NotFoundException;
use Larder\ServiceProvider;
use Larder\Tests\Fixtures\Clock;
use Larder\Tests\Fixtures\Logger;
use Larder\Tests\Fixtures\MemoryLogger;
use Larder\Tests\Fixtures\NullLogger;
use Larder\Tests\Fixtures\Plugin;
use Larder\Tests\Fixtures\PluginA;
use Larder\Tests\Fixtures\PluginB;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;

final class ContainerTest extends TestCase
{
    protected function setUp(): void
    {
        Clock::$created = 0;
    }

    public function testImplementsPsr11(): void
    {
        $this->assertInstanceOf(ContainerInterface::class, new Container());
    }

    public function testContainerResolvesItself(): void
    {
        $container = new Container();

        $this->assertSame($container, $container->get(Container::class));
        $this->assertSame($container, $container->get(ContainerInterface::class));
    }

    public function testBindWithClosureBuildsAFreshObjectEachTime(): void
    {
        $container = new Container();
        $container->bind('thing', fn (): stdClass => new stdClass());

        $this->assertNotSame($container->get('thing'), $container->get('thing'));
    }

    public function testSingletonReturnsTheSameObject(): void
    {
        $container = new Container();
        $container->singleton('thing', fn (): stdClass => new stdClass());

        $this->assertSame($container->get('thing'), $container->get('thing'));
    }

    public function testClosureReceivesTheContainer(): void
    {
        $container = new Container();
        $container->instance('name', 'larder');
        $container->bind('greeting', fn (Container $c): string => 'hi ' . $c->get('name'));

        $this->assertSame('hi larder', $container->get('greeting'));
    }

    public function testInstanceStoresAnyValue(): void
    {
        $container = new Container();
        $container->instance('answer', 42);
        $container->instance('nothing', null);

        $this->assertSame(42, $container->get('answer'));
        $this->assertNull($container->get('nothing'));
        $this->assertTrue($container->has('nothing'));
    }

    public function testInterfaceBindsToConcreteClassName(): void
    {
        $container = new Container();
        $container->bind(Logger::class, NullLogger::class);

        $this->assertInstanceOf(NullLogger::class, $container->get(Logger::class));
    }

    public function testSingletonWithoutConcreteAutowiresItself(): void
    {
        $container = new Container();
        $container->singleton(Clock::class);

        $this->assertSame($container->get(Clock::class), $container->get(Clock::class));
        $this->assertSame(1, Clock::$created);
    }

    public function testSingletonThroughAnInterfaceSharesTheConcreteBinding(): void
    {
        $container = new Container();
        $container->singleton(NullLogger::class);
        $container->bind(Logger::class, NullLogger::class);

        $this->assertSame($container->get(Logger::class), $container->get(NullLogger::class));
    }

    public function testRebindingDropsTheCachedInstance(): void
    {
        $container = new Container();
        $container->singleton('x', fn (): string => 'first');
        $this->assertSame('first', $container->get('x'));

        $container->singleton('x', fn (): string => 'second');
        $this->assertSame('second', $container->get('x'));
    }

    public function testAliasPointsAtTheSameEntry(): void
    {
        $container = new Container();
        $container->singleton('logger.memory', MemoryLogger::class);
        $container->alias(Logger::class, 'logger.memory');

        $this->assertSame($container->get('logger.memory'), $container->get(Logger::class));
    }

    public function testAliasChainsResolve(): void
    {
        $container = new Container();
        $container->instance('real', 'value');
        $container->alias('middle', 'real');
        $container->alias('outer', 'middle');

        $this->assertSame('value', $container->get('outer'));
    }

    public function testSelfAliasIsRejected(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $container->alias('a', 'a');
    }

    public function testAliasLoopIsRejected(): void
    {
        $container = new Container();
        $container->alias('a', 'b');

        $this->expectException(ContainerException::class);
        $container->alias('b', 'a');
    }

    public function testExtendDecoratesASingletonOnce(): void
    {
        $container = new Container();
        $container->singleton('list', fn (): array => ['a']);
        $container->extend('list', fn (array $list): array => [...$list, 'b']);

        $this->assertSame(['a', 'b'], $container->get('list'));
        $this->assertSame(['a', 'b'], $container->get('list'));
    }

    public function testExtendAppliesToEveryNonSharedResolution(): void
    {
        $container = new Container();
        $container->bind('n', fn (): int => 1);
        $container->extend('n', fn (int $n): int => $n + 10);
        $container->extend('n', fn (int $n): int => $n * 2);

        $this->assertSame(22, $container->get('n'));
    }

    public function testExtendOnAnExistingInstanceDecoratesImmediately(): void
    {
        $container = new Container();
        $container->instance('word', 'a');
        $container->extend('word', fn (string $w): string => $w . 'b');

        $this->assertSame('ab', $container->get('word'));
    }

    public function testMakeAlwaysBuildsAFreshObject(): void
    {
        $container = new Container();
        $container->singleton(Clock::class);

        $shared = $container->get(Clock::class);
        $made = $container->make(Clock::class);

        $this->assertNotSame($shared, $made);
        $this->assertSame($shared, $container->get(Clock::class));
    }

    public function testTaggedReturnsServicesInOrder(): void
    {
        $container = new Container();
        $container->bind('plugin.b', PluginB::class);
        $container->bind('plugin.a', PluginA::class);
        $container->tag(['plugin.b', 'plugin.a'], 'plugins');
        $container->tag('plugin.a', 'plugins');

        $names = array_map(fn (Plugin $p): string => $p->name(), $container->tagged('plugins'));

        $this->assertSame(['b', 'a'], $names);
        $this->assertSame([], $container->tagged('unknown'));
    }

    public function testForgetRemovesBindingsAndInstances(): void
    {
        $container = new Container();
        $container->instance('thing', 1);
        $container->forget('thing');

        $this->assertFalse($container->has('thing'));
    }

    public function testHasCoversBindingsInstancesAndAutowirableClasses(): void
    {
        $container = new Container();
        $container->bind('bound', fn (): int => 1);
        $container->instance('stored', 2);

        $this->assertTrue($container->has('bound'));
        $this->assertTrue($container->has('stored'));
        $this->assertTrue($container->has(Clock::class));
        $this->assertFalse($container->has(Logger::class));
        $this->assertFalse($container->has('nonsense'));
    }

    public function testUnknownIdThrowsPsrNotFound(): void
    {
        $container = new Container();

        try {
            $container->get('missing.service');
            $this->fail('expected an exception');
        } catch (NotFoundExceptionInterface $e) {
            $this->assertInstanceOf(NotFoundException::class, $e);
            $this->assertInstanceOf(ContainerExceptionInterface::class, $e);
            $this->assertStringContainsString('missing.service', $e->getMessage());
        }
    }

    public function testUnboundInterfaceIsNotFound(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->get(Logger::class);
    }

    public function testProviderRegistersServices(): void
    {
        $provider = new class () implements ServiceProvider {
            public function register(Container $container): void
            {
                $container->singleton(Logger::class, MemoryLogger::class);
                $container->instance('app.name', 'larder');
            }
        };

        $container = new Container();
        $returned = $container->register($provider);

        $this->assertSame($container, $returned);
        $this->assertInstanceOf(MemoryLogger::class, $container->get(Logger::class));
        $this->assertSame('larder', $container->get('app.name'));
    }

    public function testClosureFailureLeavesTheContainerUsable(): void
    {
        $container = new Container();
        $container->bind('boom', function (): never {
            throw new \RuntimeException('nope');
        });
        $container->instance('fine', 1);

        try {
            $container->get('boom');
            $this->fail('expected an exception');
        } catch (\RuntimeException) {
        }

        $this->assertSame(1, $container->get('fine'));
        try {
            $container->get('boom');
            $this->fail('expected an exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('nope', $e->getMessage());
        }
    }
}

