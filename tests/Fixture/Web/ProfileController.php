<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Web;

use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Primitive\BoundedString;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Level;
use MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Priority;
use MSSTC4PHP\ValueObject\Yii2\Web\ValueObjectActionParams;
use Override;
use stdClass;
use yii\web\Controller;

final class ProfileController extends Controller
{
    use ValueObjectActionParams;

    public $enableCsrfValidation = false;

    /**
     * @return array<string, class-string>
     */
    #[Override]
    public function actions(): array
    {
        return ['standalone' => ViewEmailAction::class];
    }

    public function actionView(EmailAddress $email): EmailAddress
    {
        return $email;
    }

    public function actionOptional(?EmailAddress $email): ?EmailAddress
    {
        return $email;
    }

    public function actionPriority(Priority $priority): Priority
    {
        return $priority;
    }

    public function actionDefaultedPriority(Priority $priority = Priority::Low): Priority
    {
        return $priority;
    }

    public function actionOptionalPriority(?Priority $priority): ?Priority
    {
        return $priority;
    }

    public function actionUnion(EmailAddress|string $id): EmailAddress|string
    {
        return $id;
    }

    public function actionTrusted(BoundedString $label): BoundedString
    {
        return $label;
    }

    public function actionComposite(IntRange $range): IntRange
    {
        return $range;
    }

    public function actionLevel(Level $level): Level
    {
        return $level;
    }

    public function actionAbsentComposite(IntRange $range): IntRange
    {
        return $range;
    }

    public function actionAbsentTrusted(?BoundedString $label): ?BoundedString
    {
        return $label;
    }

    public function actionMixed(string $name, ?Priority $priority, EmailAddress $email): string
    {
        return $name . ($priority->name ?? '-') . $email;
    }

    public function actionPlain(string $name, int $page = 1): string
    {
        return $name . $page;
    }

    public function actionInjected(EmailAddress $email, stdClass $service): stdClass
    {
        return $service;
    }
}
