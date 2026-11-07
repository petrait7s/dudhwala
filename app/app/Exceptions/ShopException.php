<?php

namespace App\Exceptions;

use RuntimeException;

/** Business-rule failure with a stable machine code (OUT_OF_STOCK, CONFLICT, VALIDATION_FAILED...). */
class ShopException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '', public readonly array $context = [])
    {
        parent::__construct($message ?: $errorCode);
    }
}
