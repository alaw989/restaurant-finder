<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UptimeCanary extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'uptime:canary';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check application health and log uptime status';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $status = 'ok';
        $checks = [];

        // Database connectivity check
        try {
            DB::connection()->getPdo();
            $checks['database'] = 'ok';
            $this->info('✓ Database: connected');
        } catch (\Exception $e) {
            $status = 'critical';
            $checks['database'] = 'failed: '.$e->getMessage();
            $this->error('✗ Database: disconnected');
        }

        // External API health checks (optional - best-effort)
        $apiEndpoints = [
            'BizData' => config('services.bizdata.url'),
            'Overpass' => config('services.overpass.url'),
        ];

        foreach ($apiEndpoints as $name => $url) {
            if (!$url) {
                $checks["api_{$name}"] = 'skipped (no url)';
                continue;
            }

            try {
                $response = Http::timeout(5)->get($url);
                if ($response->successful()) {
                    $checks["api_{$name}"] = 'ok';
                    $this->info("✓ {$name} API: reachable");
                } else {
                    $status = $status === 'ok' ? 'degraded' : $status;
                    $checks["api_{$name}"] = "failed: {$response->status()}";
                    $this->warn("⚠ {$name} API: returned {$response->status()}");
                }
            } catch (\Exception $e) {
                $status = $status === 'ok' ? 'degraded' : $status;
                $checks["api_{$name}"] = "failed: {$e->getMessage()}";
                $this->warn("⚠ {$name} API: unreachable");
            }
        }

        // Log the overall status
        Log::info('Uptime canary check', [
            'status' => $status,
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->info("Overall status: {$status}");

        return $status === 'ok' ? Command::SUCCESS : Command::FAILURE;
    }
}
