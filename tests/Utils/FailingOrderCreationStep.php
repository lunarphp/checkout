<?php

namespace Lunar\Tests\Checkout\Utils;

use Closure;
use Lunar\Core\Enums\StepList;
use Lunar\Core\Facades\Steps;
use Lunar\Core\Models\Order;
use RuntimeException;

class FailingOrderCreationStep
{
    private static string $message = 'order pipeline down';

    public static function register(string $message = 'order pipeline down'): void
    {
        self::$message = $message;

        Steps::for(StepList::OrderCreation)->prepend(self::class);
    }

    public function handle(Order $order, Closure $next): mixed
    {
        throw new RuntimeException(self::$message);
    }
}
