<?php

namespace App\Tests\Helpers;

use App\Helpers\StringToHandle;
use PHPUnit\Framework\TestCase;

class StringToHandleTest extends TestCase
{
    public function testStringToHandle()
    {
        $nameA = "123 My Challenge!";
        $nameB = "%^&!@#=+\\/-Th%#is IS AMA8zing - 420";

        $expectedA = "123_my_challenge";
        $expectedB = "-this_is_ama8zing_-_420";

        $this->assertEquals($expectedA, StringToHandle::stringToHandle($nameA));
        $this->assertEquals($expectedB, StringToHandle::stringToHandle($nameB));
    }
}
