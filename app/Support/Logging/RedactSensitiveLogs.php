<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

class RedactSensitiveLogs
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
            message: SensitiveDataRedactor::message($record->message),
            context: SensitiveDataRedactor::context($record->context),
        ));
    }
}
