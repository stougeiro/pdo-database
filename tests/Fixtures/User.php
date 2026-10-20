<?php declare(strict_types=1);

namespace Tests\Fixtures;

use STDW\Pdo\Model\ModelAbstracted;


class User extends ModelAbstracted
{
    protected string $table = 'users';
}
