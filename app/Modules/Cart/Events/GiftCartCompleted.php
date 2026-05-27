<?php

declare(strict_types=1);

namespace Modules\Cart\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Cart\Models\Cart;

class GiftCartCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Cart $cart
    ) {}
}
