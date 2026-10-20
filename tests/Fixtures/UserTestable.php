<?php declare(strict_types=1);

namespace Tests\Fixtures;

use STDW\Contract\Pdo\DriverInterface;


final class UserTestable extends User
{
    public ?DriverInterface $fake = null;

    protected function driver(): DriverInterface
    {
        return $this->fake ?? parent::driver();
    }
}
