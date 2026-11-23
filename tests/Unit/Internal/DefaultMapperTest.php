<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Unit\Internal;

use LogicException;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use MSSTC4PHP\ValueObject\Yii2\Internal\DefaultMapper;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\SizeCodec;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use yii\BaseYii;

#[CoversClass(DefaultMapper::class)]
final class DefaultMapperTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        BaseYii::$container->clear(ValueObjectMapper::class);
    }

    public function testWithoutADefinitionOnePlainMapperIsShared(): void
    {
        self::assertSame(DefaultMapper::get(), DefaultMapper::get());
    }

    public function testTheContainersDefinitionIsUsed(): void
    {
        $mapper = new ValueObjectMapper([new SizeCodec()]);
        BaseYii::$container->setSingleton(ValueObjectMapper::class, static fn (): ValueObjectMapper => $mapper);

        self::assertSame($mapper, DefaultMapper::get());
    }

    public function testADefinitionOfAnythingElseIsAConfigurationError(): void
    {
        BaseYii::$container->set(ValueObjectMapper::class, static fn (): stdClass => new stdClass());

        $this->expectExceptionObject(new LogicException('The container defines ' . ValueObjectMapper::class . ' as stdClass.'));

        DefaultMapper::get();
    }
}
