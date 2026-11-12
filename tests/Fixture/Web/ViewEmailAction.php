<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Fixture\Web;

use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use yii\base\Action;

final class ViewEmailAction extends Action
{
    public function run(EmailAddress $email): EmailAddress
    {
        return $email;
    }
}
