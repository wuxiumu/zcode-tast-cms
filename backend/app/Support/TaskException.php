<?php

declare(strict_types=1);

namespace App\Support;

/** 业务异常：code 直接透传为接口错误码（见 doc/04） */
final class TaskException extends \RuntimeException
{
    private mixed $data;

    public function __construct(int $code, string $message, mixed $data = null)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
