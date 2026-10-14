<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class SystemBackupException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, private readonly array $context = [], ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function because(string $message, array $context = [], ?Throwable $previous = null): self
    {
        return new self($message, $context, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }
}
