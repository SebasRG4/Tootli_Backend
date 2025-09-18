<?php

namespace App\Services\OrderAssignment\Logger;

use Illuminate\Support\Facades\Log;

class AssignmentLogger
{
    public static function log($message)
    {
        Log::info('[OrderAssignment] ' . $message);
    }
}