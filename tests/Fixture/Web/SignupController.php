<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Web;

use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\ContactForm;
use MSSTC4PHP\ValueObject\Yii2\Web\ValueObjectActionParams;
use Override;
use yii\rest\Controller;
use yii\web\Request;

final class SignupController extends Controller
{
    use ValueObjectActionParams;

    /**
     * @return array<array{class: class-string, ...}|class-string>
     */
    #[Override]
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        // The rate limiter needs a configured user component.
        unset($behaviors['rateLimiter']);

        return $behaviors;
    }

    public function actionView(EmailAddress $email): string
    {
        return (string) $email;
    }

    public function actionCreate(Request $request): ContactForm|string
    {
        $form = new ContactForm();
        $body = $request->getBodyParams();
        $form->load(\is_array($body) ? $body : [], '');
        if (!$form->validate()) {
            return $form;
        }

        $email = $form->valueObject('email');

        return $email instanceof EmailAddress ? (string) $email : '';
    }
}
