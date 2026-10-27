<?php

namespace App\Console\Commands;

use App\Services\Analysis\AnalysisCoordinator;
use Illuminate\Console\Command;

class AnalyzeCameras extends Command
{
    protected $signature = 'camera-ai:run';

    protected $description = 'Run the optional motion-gated camera labeling coordinator';

    public function handle(AnalysisCoordinator $coordinator): int
    {
        $directory = storage_path('app/private/bootstrap');
        if (! is_dir($directory)) {
            mkdir($directory, 0770, true);
        }
        $lock = fopen($directory.'/analysis.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Another analysis coordinator is already running.');

            return self::FAILURE;
        }
        try {
            $coordinator->run();
        } finally {
            fclose($lock);
        }

        return self::SUCCESS;
    }
}
