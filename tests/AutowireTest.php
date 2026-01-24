<?php

declare(strict_types=1);

namespace Larder\Tests;

use Larder\Container;
use Larder\Exception\ContainerException;
use Larder\Tests\Fixtures\Clock;
use Larder\Tests\Fixtures\CircularA;
use Larder\Tests\Fixtures\Logger;
use Larder\Tests\Fixtures\Mailer;
use Larder\Tests\Fixtures\MemoryLogger;
use Larder\Tests\Fixtures\NeedsScalar;
use Larder\Tests\Fixtures\NoConstructor;
use Larder\Tests\Fixtures\Notifier;
use Larder\Tests\Fixtures\NullLogger;
use Larder\Tests\Fixtures\PrivateConstructor;
use Larder\Tests\Fixtures\Shape;
use Larder\Tests\Fixtures\Suit;
use Larder\Tests\Fixtures\UnionDependency;
use Larder\Tests\Fixtures\VariadicNames;
use Larder\Tests\Fixtures\WithDefaults;
use Larder\Tests\Fixtures\WithNullable;
use PHPUnit\Framework\TestCase;

final class AutowireTest extends TestCase
{
    public function testClassWithoutConstructorIsBuilt(): void
    {
        $this->assertInstanceOf(NoConstructor::class, (new Container())->get(NoConstructor::class));
    }

    public function testUnboundConcreteClassesAreAutowired(): void
    {
        $container = new Container();
        $container->bind(Logger::class, NullLogger::class);

        $mailer = $container->get(Mailer::class);

        $this->assertInstanceOf(Mailer::class, $mailer);
        $this->assertInstanceOf(NullLogger::class, $mailer->logger);
    }

    public function testDependenciesAreResolvedRecursively(): void
    {
        $container = new Container();
        $container->singleton(Logger::class, MemoryLogger::class);

        $notifier = $container->get(Notifier::class);

        $this->assertInstanceOf(Clock::class, $notifier->clock);
        $this->assertSame($container->get(Logger::class), $notifier->mailer->logger);
    }

    public function testNonSharedDependenciesAreBuiltPerConsumer(): void
    {
        $container = new Container();
        $container->bind(Logger::class, NullLogger::class);

        $one = $container->get(Mailer::class);
        $two = $container->get(Mailer::class);

        $this->assertNotSame($one, $two);
        $this->assertNotSame($one->logger, $two->logger);
    }

    public function testScalarDefaultsAreUsed(): void
    {
        $object = (new Container())->get(WithDefaults::class);

        $this->assertSame(3, $object->retries);
        $this->assertSame('default', $object->name);
    }

    public function testMakeOverridesParametersByName(): void
    {
        $object = (new Container())->make(WithDefaults::class, ['retries' => 9]);

        $this->assertSame(9, $object->retries);
        $this->assertSame('default', $object->name);
    }

    public function testMakeOverridesParametersByPosition(): void
    {
        $object = (new Container())->make(WithDefaults::class, [5, 'custom']);

        $this->assertSame(5, $object->retries);
        $this->assertSame('custom', $object->name);
    }

    public function testRequiredScalarWithoutValueFails(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$port of ' . NeedsScalar::class);
        $container->get(NeedsScalar::class);
    }

    public function testRequiredScalarCanBeSuppliedThroughMake(): void
    {
        $this->assertSame(8080, (new Container())->make(NeedsScalar::class, ['port' => 8080])->port);
    }

    public function testNullableDependencyFallsBackToDefaultWhenUnbound(): void
    {
        $this->assertNull((new Container())->get(WithNullable::class)->logger);
    }

    public function testNullableDependencyIsInjectedWhenBound(): void
    {
        $container = new Container();
        $container->bind(Logger::class, NullLogger::class);

        $this->assertInstanceOf(NullLogger::class, $container->get(WithNullable::class)->logger);
    }

    public function testUnionTypesTakeTheFirstResolvableMember(): void
    {
        $object = (new Container())->get(UnionDependency::class);

        $this->assertInstanceOf(Clock::class, $object->dependency);
    }

    public function testVariadicParametersAreLeftEmptyOrSupplied(): void
    {
        $container = new Container();

        $this->assertSame([], $container->get(VariadicNames::class)->names);
        $this->assertSame(['a', 'b'], $container->make(VariadicNames::class, ['names' => ['a', 'b']])->names);
    }

    public function testCircularDependencyIsReportedWithTheChain(): void
    {
        $container = new Container();

        try {
            $container->get(CircularA::class);
            $this->fail('expected an exception');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('Circular dependency', $e->getMessage());
            $this->assertStringContainsString('CircularA -> ' . 'Larder\Tests\Fixtures\CircularB -> Larder\Tests\Fixtures\CircularA', $e->getMessage());
        }
    }

    public function testResolutionStackIsClearedAfterACircularFailure(): void
    {
        $container = new Container();

        for ($i = 0; $i < 2; $i++) {
            try {
                $container->get(CircularA::class);
                $this->fail('expected an exception');
            } catch (ContainerException $e) {
                $this->assertStringContainsString('Circular dependency', $e->getMessage());
            }
        }

        $this->assertInstanceOf(NoConstructor::class, $container->get(NoConstructor::class));
    }

    public function testAbstractClassCannotBeBuilt(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(Shape::class));
        $this->expectException(ContainerException::class);
        $container->get(Shape::class);
    }

    public function testPrivateConstructorCannotBeBuilt(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(PrivateConstructor::class));
        $this->expectException(ContainerException::class);
        $container->get(PrivateConstructor::class);
    }

    public function testEnumsAreNotAutowirable(): void
    {
        $this->assertFalse((new Container())->has(Suit::class));
    }

    public function testBindingAnInterfaceToAnUnresolvableClassFails(): void
    {
        $container = new Container();
        $container->bind(Logger::class, Shape::class);

        $this->expectException(ContainerException::class);
        $container->get(Logger::class);
    }
}
