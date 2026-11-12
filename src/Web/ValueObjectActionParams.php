<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Web;

use LogicException;
use MSSTC4PHP\ValueObject\Exception\InvalidValueObjectException;
use MSSTC4PHP\ValueObject\Payload\ValueObjectMapper;
use MSSTC4PHP\ValueObject\Yii2\Internal\TypeLabel;
use Override;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use yii\base\InlineAction;
use yii\BaseYii;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Binds action parameters typed with a scalar-backed value object or a backed enum:
 * `actionView(EmailAddress $email)` receives the value object built from the route or
 * query parameter, and an invalid value is a 404. Without it Yii hands the raw string to
 * the typed parameter and PHP throws a TypeError (500).
 *
 * Inline actions and standalone `run()` actions are bound alike; services are still
 * injected. The mapper comes from `BaseYii::$container`, so custom codecs are configured
 * through container definitions.
 *
 * @phpstan-require-extends Controller
 */
trait ValueObjectActionParams
{
    /**
     * @throws NotFoundHttpException when a value does not convert
     * @throws BadRequestHttpException when a required value object parameter is missing
     * @throws LogicException when a parameter is typed with a class that cannot be bound from a request
     */
    #[Override]
    public function bindActionParams($action, $params)
    {
        $method = $action instanceof InlineAction
            ? new ReflectionMethod($this, $action->actionMethod)
            : new ReflectionMethod($action, 'run');

        return parent::bindActionParams($action, self::withValueObjects($method, $params));
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private static function withValueObjects(ReflectionMethod $method, array $params): array
    {
        $mapper = BaseYii::$container->get(ValueObjectMapper::class);
        $missing = [];
        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $class = self::valueObjectClassOf($parameter, $mapper);
            if ($class === null) {
                if (!\array_key_exists($name, $params) && !$parameter->isDefaultValueAvailable() && self::isMissingForYii($parameter)) {
                    $missing[] = $name;
                }

                continue;
            }

            $present = \array_key_exists($name, $params);
            if (!$present || !$params[$name] instanceof $class) {
                self::assertBindable($name, $class, $mapper);
            }

            if (!$present) {
                // Yii would look for a service of that type and fail with a 500 for a value object.
                if ($parameter->isDefaultValueAvailable()) {
                    $params[$name] = $parameter->getDefaultValue();
                } elseif ($parameter->allowsNull()) {
                    $params[$name] = null;
                } else {
                    $missing[] = $name;
                }

                continue;
            }

            $params[$name] = self::bindValueObject($parameter, $class, $params[$name], $mapper);
        }

        if ($missing !== []) {
            throw new BadRequestHttpException(BaseYii::t('yii', 'Missing required parameters: {params}', ['params' => implode(', ', $missing)]));
        }

        return $params;
    }

    /**
     * Whether Yii reports the absent parameter as missing (a named class type is a service lookup instead).
     */
    private static function isMissingForYii(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return !$type instanceof ReflectionNamedType || $type->isBuiltin();
    }

    /**
     * @return class-string|null the class of a single named, non-builtin parameter type the mapper supports
     */
    private static function valueObjectClassOf(ReflectionParameter $parameter, ValueObjectMapper $mapper): ?string
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();

        return class_exists($class) && $mapper->supports($class) ? $class : null;
    }

    /**
     * @param class-string $class
     *
     * @throws LogicException when a request cannot become a $class
     */
    private static function assertBindable(string $name, string $class, ValueObjectMapper $mapper): void
    {
        if ($mapper->isComposite($class) || !$mapper->supportsUntrusted($class)) {
            throw new LogicException(\sprintf('Action parameter $%s cannot be typed %s: %s. Type it as a scalar-backed value object or a backed enum, or take a string and convert it yourself.', $name, $class, $mapper->supportsUntrusted($class) ? 'a composite cannot be read from a single route or query parameter' : 'its payload carries its own constraints, so a request would choose them'));
        }
    }

    /**
     * @param class-string $class
     */
    private static function bindValueObject(ReflectionParameter $parameter, string $class, mixed $value, ValueObjectMapper $mapper): mixed
    {
        if ($value instanceof $class) {
            return $value;
        }

        $name = $parameter->getName();
        if ($value === null || $value === '') {
            return $parameter->allowsNull() ? null : throw self::invalidParameter($name, $class);
        }

        try {
            return $mapper->fromUntrustedPayload($class, $value);
        } catch (InvalidValueObjectException) {
            throw self::invalidParameter($name, $class);
        }
    }

    /**
     * @param class-string $class
     */
    private static function invalidParameter(string $name, string $class): NotFoundHttpException
    {
        return new NotFoundHttpException(\sprintf('The "%s" parameter is not a valid %s.', $name, TypeLabel::of($class)));
    }
}
