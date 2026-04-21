<?php

namespace App\Exceptions;

use Exception;

class DuplicateAttendanceException extends Exception
{
    public function __construct(string $message = 'Duplicate attendance entry detected', int $code = 409)
    {
        parent::__construct($message, $code);
    }
}
