<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExpireTest extends TestCase
{
    public function testWillExpireAtWithin90Hours()
    {
        $dueTime = now()->addHours(80);
        $createdAt = now()->subHours(10);

        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $this->assertEquals($dueTime->format('Y-m-d H:i:s'), $result);
    }

    public function testWillExpireAtWithin24Hours()
    {
        $dueTime = now()->addHours(10);
        $createdAt = now()->subHours(20);

        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $expectedTime = $createdAt->addMinutes(90);
        $this->assertEquals($expectedTime->format('Y-m-d H:i:s'), $result);
    }

    public function testWillExpireAtWithin72Hours()
    {
        $dueTime = now()->addHours(60);
        $createdAt = now()->subHours(40);

        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $expectedTime = $createdAt->addHours(16);
        $this->assertEquals($expectedTime->format('Y-m-d H:i:s'), $result);
    }

    public function testWillExpireAtBeyond72Hours()
    {
        $dueTime = now()->addHours(100);
        $createdAt = now()->subHours(200);

        $result = TeHelper::willExpireAt($dueTime, $createdAt);

        $expectedTime = $dueTime->subHours(48);
        $this->assertEquals($expectedTime->format('Y-m-d H:i:s'), $result);
    }
}
