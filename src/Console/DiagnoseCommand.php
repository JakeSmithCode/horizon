<?php

namespace Laravel\Horizon\Console;

use Illuminate\Console\Command;
use Laravel\Horizon\Health\HealthCheck;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:diagnose')]
class DiagnoseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:diagnose {--json : Output the diagnostics as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run health checks against the current Horizon installation';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Horizon\Health\HealthCheck  $health
     * @return int
     */
    public function handle(HealthCheck $health)
    {
        $checks = $health->run();

        $exitCode = $this->exitCodeFor($checks);

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'healthy' => $exitCode === 0,
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->output->writeln('');

        foreach ($checks as $check) {
            $this->renderCheck($check);
        }

        $this->output->writeln('');

        return $exitCode;
    }

    /**
     * Determine the process exit code for the given checks.
     *
     * @param  array  $checks
     * @return int
     */
    protected function exitCodeFor($checks)
    {
        $statuses = collect($checks)->pluck('status');

        if ($statuses->contains(HealthCheck::CRITICAL)) {
            return 2;
        }

        return $statuses->contains(HealthCheck::WARNING) ? 1 : 0;
    }

    /**
     * Render a single check result to the console.
     *
     * @param  array  $check
     * @return void
     */
    protected function renderCheck($check)
    {
        $message = $check['name'].': '.$check['message'];

        match ($check['status']) {
            HealthCheck::OK => $this->components->info($message),
            HealthCheck::WARNING => $this->components->warn($message),
            default => $this->components->error($message),
        };
    }
}
