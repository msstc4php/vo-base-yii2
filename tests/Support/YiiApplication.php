<?php

declare(strict_types=1);

namespace MSSTC4PHP\ValueObject\Yii2\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use yii\BaseYii;
use yii\console\Application;
use yii\db\Connection;

/**
 * A console application with an in-memory SQLite connection, created per test.
 */
trait YiiApplication
{
    private Application $application;

    #[Before]
    protected function createApplication(): void
    {
        // PHPUnit owns the error handlers; Yii would otherwise install its own and keep them.
        \defined('YII_ENABLE_ERROR_HANDLER') || \define('YII_ENABLE_ERROR_HANDLER', false);
        // Yii.php is not autoloadable: an application requires it from its entry script.
        require_once \dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';

        $this->application = new Application([
            'id' => 'vo-base-yii2-tests',
            'basePath' => \dirname(__DIR__, 2),
            'components' => [
                'db' => ['class' => Connection::class, 'dsn' => 'sqlite::memory:'],
            ],
        ]);
    }

    #[After]
    protected function destroyApplication(): void
    {
        BaseYii::$app = null;
    }

    protected function db(): Connection
    {
        return $this->application->getDb();
    }
}
