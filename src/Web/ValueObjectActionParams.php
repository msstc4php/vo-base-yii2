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
        foreach ($method->getParameters() as $parameter) {
            $class = self::valueObjectClassOf($parameter);
            if ($class === null) {
                continue;
            }

            $name = $parameter->getName();
            if (!\array_key_exists($name, $params)) {
                // Yii would look for a service of that type and fail with a 500 for a value object.
                if ($parameter->isDefaultValueAvailable()) {
                    $params[$name] = $parameter->getDefaultValue();
                } elseif (!$parameter->allowsNull()) {
                    throw new BadRequestHttpException(BaseYii::t('yii', 'Missing required parameters: {params}', ['params' => $name]));
                }

                continue;
            }

            $params[$name] = self::bindValueObject($parameter, $class, $params[$name]);
        }

        return $params;
    }

    /**
     * @return class-string|null the class of a single named, non-builtin parameter type the mapper supports
     */
    private static function valueObjectClassOf(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();

        return class_exists($class) && self::actionParamMapper()->supports($class) ? $class : null;
    }

    /**
     * @param class-string $class
     */
    private static function bindValueObject(ReflectionParameter $parameter, string $class, mixed $value): mixed
    {
        if ($value instanceof $class) {
            return $value;
        }

        $mapper = self::actionParamMapper();
        $name = $parameter->getName();
        if ($mapper->isComposite($class) || !$mapper->supportsUntrusted($class)) {
            throw new LogicException(\sprintf('Action parameter $%s cannot be typed %s: %s. Type it as a scalar-backed value object or a backed enum, or take a string and convert it yourself.', $name, $class, $mapper->supportsUntrusted($class) ? 'a composite cannot be read from a single route or query parameter' : 'its payload carries its own constraints, so a request would choose them'));
        }

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

    private static function actionParamMapper(): ValueObjectMapper
    {
        return BaseYii::$container->get(ValueObjectMapper::class);
    }
}
