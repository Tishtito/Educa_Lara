<?php

declare(strict_types=1);

namespace App\Domain\Billing\Mpesa;

use RuntimeException;

/** M-Pesa refused or could not be reached. The message is safe to show the person paying. */
class MpesaException extends RuntimeException {}
