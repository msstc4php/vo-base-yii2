<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Unit\Web;

use LogicException;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Yii2\Internal\TypeLabel;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Level;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Priority;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Web\ProfileController;
use MSSTC4PHP\ValueObject\Yii2\Tests\Support\YiiApplication;
use MSSTC4PHP\ValueObject\Yii2\Web\ValueObjectActionParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use yii\BaseYii;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

#[CoversTrait(ValueObjectActionParams::class)]
#[CoversClass(TypeLabel::class)]
final class ValueObjectActionParamsTest extends TestCase
{
    use YiiApplication;

    public function testValidValueReachesTheAction(): void
    {
        $result = $this->runOn('view', ['email' => 'user@example.com']);

        self::assertInstanceOf(EmailAddress::class, $result);
        self::assertSame('user@example.com', (string) $result);
    }

    public function testStandaloneActionIsBoundTheSameWay(): void
    {
        $result = $this->runOn('standalone', ['email' => 'user@example.com']);

        self::assertInstanceOf(EmailAddress::class, $result);
        $this->expectException(NotFoundHttpException::class);
        $this->runOn('standalone', ['email' => 'nope']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidEmail(): iterable
    {
        yield 'malformed' => ['nope'];
        yield 'array' => [['x']];
        yield 'empty string' => [''];
        yield 'null' => [null];
        yield 'integer' => [5];
    }

    #[DataProvider('invalidEmail')]
    public function testInvalidInputIsNotFound(mixed $value): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->runOn('view', ['email' => $value]);
    }

    public function testNotFoundChainsTheValueObjectsException(): void
    {
        try {
            $this->runOn('view', ['email' => 'nope']);
            self::fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException $notFound) {
            self::assertInstanceOf(InvalidValueObjectException::class, $notFound->getPrevious());
        }
    }

    public function testNotFoundMessageNamesNoClass(): void
    {
        try {
            $this->runOn('view', ['email' => 'nope']);
            self::fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException $notFound) {
            self::assertStringContainsString('email address', $notFound->getMessage());
            self::assertStringNotContainsString('EmailAddress', $notFound->getMessage());
            self::assertStringNotContainsString('\\', $notFound->getMessage());
        }
    }

    public function testBackedEnumIsConverted(): void
    {
        self::assertSame(Priority::High, $this->runOn('priority', ['priority' => '2']));
        $this->expectException(NotFoundHttpException::class);
        $this->runOn('priority', ['priority' => 'bogus']);
    }

    public function testStringBackedEnumIsConverted(): void
    {
        self::assertSame(Level::High, $this->runOn('level', ['level' => 'high']));
        $this->expectException(NotFoundHttpException::class);
        $this->runOn('level', ['level' => 'bogus']);
    }

    public function testAbsentUnbindableParametersAreProgrammerErrors(): void
    {
        foreach (['absent-composite', 'absent-trusted'] as $action) {
            try {
                $this->runOn($action, []);
                self::fail('Expected LogicException for ' . $action);
            } catch (LogicException $error) {
                self::assertStringContainsString('cannot be typed', $error->getMessage());
            }
        }
    }

    public function testEveryMissingParameterIsListed(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Missing required parameters: name, email');
        $this->runOn('mixed', []);
    }

    public function testNullableAbsentIsNull(): void
    {
        self::assertNull($this->runOn('optional', []));
        self::assertNull($this->runOn('optional-priority', []));
    }

    public function testDefaultIsUsedWhenAbsent(): void
    {
        self::assertSame(Priority::Low, $this->runOn('defaulted-priority', []));
        self::assertSame(Priority::High, $this->runOn('defaulted-priority', ['priority' => '2']));
    }

    public function testNullableEmptyAndNullAreNull(): void
    {
        self::assertNull($this->runOn('optional', ['email' => '']));
        self::assertNull($this->runOn('optional', ['email' => null]));
        self::assertNull($this->runOn('optional-priority', ['priority' => '']));
    }

    public function testNullableStillValidatesContent(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->runOn('optional', ['email' => 'nope']);
    }

    public function testNonNullableAbsentIsABadRequest(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Missing required parameters: email');
        $this->runOn('view', []);
    }

    public function testInstanceIsPassedThroughUntouched(): void
    {
        $email = EmailAddress::from('user@example.com');

        self::assertSame($email, $this->runOn('view', ['email' => $email]));
        self::assertSame(Priority::Low, $this->runOn('priority', ['priority' => Priority::Low]));
    }

    public function testUnionTypeIsLeftAlone(): void
    {
        self::assertSame('nope', $this->runOn('union', ['id' => 'nope']));
    }

    public function testBuiltinParametersAreUnchanged(): void
    {
        self::assertSame('ann1', $this->runOn('plain', ['name' => 'ann']));
        self::assertSame('ann7', $this->runOn('plain', ['name' => 'ann', 'page' => '7']));
    }

    public function testParametersAfterUnconvertedOnesAreStillBound(): void
    {
        self::assertSame('ann-user@example.com', $this->runOn('mixed', ['name' => 'ann', 'email' => 'user@example.com']));
        self::assertSame('annHighuser@example.com', $this->runOn('mixed', ['name' => 'ann', 'priority' => '2', 'email' => 'user@example.com']));
    }

    public function testTrustedSourceOnlyClassIsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('$label cannot be typed');
        $this->expectExceptionMessage('own constraints');
        $this->runOn('trusted', ['label' => 'x']);
    }

    public function testCompositeIsAProgrammerError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('composite');
        $this->runOn('composite', ['range' => '1-5']);
    }

    public function testCompositeInstanceStillPassesThrough(): void
    {
        $range = new IntRange(1, 5);

        self::assertSame($range, $this->runOn('composite', ['range' => $range]));
    }

    public function testServicesAreStillInjected(): void
    {
        $service = new stdClass();
        BaseYii::$container->set(stdClass::class, static fn (): stdClass => $service);
        try {
            self::assertSame($service, $this->runOn('injected', ['email' => 'user@example.com']));
        } finally {
            BaseYii::$container->clear(stdClass::class);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function runOn(string $action, array $params): mixed
    {
        return new ProfileController('profile', $this->application)->runAction($action, $params);
    }
}
