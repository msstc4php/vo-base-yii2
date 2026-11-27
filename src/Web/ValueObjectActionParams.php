<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Web;

use LogicException;
use MSSTC4PHP\ValueObject\Yii2\Internal\ActionParamBinder;
use Override;
use ReflectionMethod;
use yii\base\InlineAction;
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

        return parent::bindActionParams($action, ActionParamBinder::bind($method, $params));
    }
}
