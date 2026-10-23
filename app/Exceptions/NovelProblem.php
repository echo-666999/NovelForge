<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class NovelProblem extends HttpException
{
    public function __construct(public string $errorCode, string $message, public int $status = 409)
    {
        parent::__construct($status, $message);
    }
}
