<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Unit\Web;

use MSSTC4PHP\ValueObject\Yii2\Internal\TypeLabel;
use MSSTC4PHP\ValueObject\Yii2\Model\ValueObjectModel;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Web\BodyErrorHandler;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Web\SignupController;
use MSSTC4PHP\ValueObject\Yii2\Tests\Support\YiiApplication;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use MSSTC4PHP\ValueObject\Yii2\Web\ValueObjectActionParams;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\UsesTrait;
use PHPUnit\Framework\TestCase;
use Throwable;
use yii\web\Application;
use yii\web\NotFoundHttpException;
use yii\web\Request;
use yii\web\Response;

#[CoversTrait(ValueObjectActionParams::class)]
#[UsesClass(TypeLabel::class)]
#[UsesTrait(ValueObjectModel::class)]
#[UsesClass(ValueObjectValidator::class)]
final class ValueObjectActionParamsWebTest extends TestCase
{
    use YiiApplication;

    public function testValidRouteValueReachesTheAction(): void
    {
        $response = $this->handle('signup/ann@example.com');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ann@example.com', $response->data);
    }

    public function testInvalidRouteValueIsA404WithoutAClassName(): void
    {
        $app = $this->webApplication();
        try {
            $this->dispatch($app, 'signup/nope');
            self::fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException $notFound) {
            $body = $this->render($app, $notFound);
        }

        self::assertSame(404, $app->getResponse()->getStatusCode());
        self::assertStringContainsString('email address', $body);
        self::assertStringNotContainsString('EmailAddress', $body);
        self::assertStringNotContainsString('MSSTC4PHP', $body);
    }

    public function testRestActionReturningAnInvalidModelIs422(): void
    {
        $response = $this->handle('signup', 'POST', ['email' => 'nope']);

        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray($response->data);
        self::assertIsArray($response->data[0]);
        self::assertSame('email', $response->data[0]['field']);
    }

    public function testRestActionWithAValidModelIs200(): void
    {
        $response = $this->handle('signup', 'POST', ['email' => 'ann@example.com']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ann@example.com', $response->data);
    }

    private function webApplication(): Application
    {
        return new Application([
            'id' => 'vo-base-yii2-web-tests',
            'basePath' => \dirname(__DIR__, 3),
            'controllerMap' => ['signup' => SignupController::class],
            'components' => [
                'request' => ['class' => Request::class, 'cookieValidationKey' => 'test', 'enableCsrfValidation' => false, 'scriptUrl' => '/index.php'],
                'urlManager' => [
                    'enablePrettyUrl' => true,
                    'showScriptName' => false,
                    'rules' => [
                        'POST signup' => 'signup/create',
                        'signup/<email>' => 'signup/view',
                    ],
                ],
                'response' => ['format' => Response::FORMAT_JSON],
                'errorHandler' => ['class' => BodyErrorHandler::class],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function handle(string $path, string $method = 'GET', array $body = []): Response
    {
        return $this->dispatch($this->webApplication(), $path, $method, $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatch(Application $app, string $path, string $method = 'GET', array $body = []): Response
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $request = $app->getRequest();
        $request->setPathInfo($path);
        $request->setBodyParams($body);

        return $app->handleRequest($request);
    }

    private function render(Application $app, Throwable $error): string
    {
        $handler = $app->getErrorHandler();
        self::assertInstanceOf(BodyErrorHandler::class, $handler);
        $response = $app->getResponse();
        $response->setStatusCodeByException($error);
        $response->data = $handler->bodyOf($error);

        return json_encode($response->data, \JSON_THROW_ON_ERROR);
    }
}
