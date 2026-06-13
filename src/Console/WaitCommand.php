<?php

namespace Laravel\Horizon\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:wait')]
class WaitCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:wait
                            {--connection= : The queue connection to check (defaults to the default connection)}
                            {--queue=* : The queue(s) to wait on (defaults to the connection default queue)}
                            {--timeout=0 : The maximum number of seconds to wait (0 waits indefinitely)}
                            {--sleep=3 : The number of seconds to sleep between checks}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Wait until the given queues are empty (useful for deploys and CI)';

    /**
     * Execute the console command.
     *
     * @param  \Illuminate\Contracts\Queue\Factory  $queue
     * @return int
     */
    public function handle(QueueFactory $queue)
    {
        $connection = $this->option('connection') ?: config('queue.default');
        $queues = $this->queues($connection);
        $timeout = (int) $this->option('timeout');
        $sleep = max(1, (int) $this->option('sleep'));
        $startedAt = time();

        while (true) {
            $remaining = collect($queues)->sum(
                fn ($name) => $queue->connection($connection)->size($name)
            );

            if ($remaining === 0) {
                $this->components->info('All queues are empty: '.implode(', ', $queues).'.');

                return 0;
            }

            if ($timeout > 0 && (time() - $startedAt) >= $timeout) {
                $this->components->error(
                    "Timed out after {$timeout}s with {$remaining} job(s) still queued."
                );

                return 1;
            }

            sleep($sleep);
        }
    }

    /**
     * Resolve the list of queues to wait on.
     *
     * @param  string  $connection
     * @return array
     */
    protected function queues($connection)
    {
        $queues = collect($this->option('queue'))
            ->flatMap(fn ($queue) => explode(',', $queue))
            ->map(fn ($queue) => trim($queue))
            ->filter()
            ->values();

        if ($queues->isNotEmpty()) {
            return $queues->all();
        }

        return collect(explode(',', (string) config(
            "queue.connections.{$connection}.queue", 'default'
        )))->map(fn ($queue) => trim($queue))->filter()->whenEmpty(
            fn ($collection) => $collection->push('default')
        )->all();
    }
}
