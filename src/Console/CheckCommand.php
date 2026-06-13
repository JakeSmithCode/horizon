<?php

namespace Laravel\Horizon\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Events\HorizonStopped;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'horizon:check')]
class CheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'horizon:check {--quiet-when-running : Suppress output when Horizon is running}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify Horizon is running and alert if it has stopped unexpectedly';

    /**
     * The Redis key used to remember that Horizon was last seen running.
     */
    const MARKER = 'status:last-running';

    /**
     * Execute the console command.
     *
     * @param  \Laravel\Horizon\Contracts\MasterSupervisorRepository  $masters
     * @param  \Illuminate\Contracts\Redis\Factory  $redis
     * @return int
     */
    public function handle(MasterSupervisorRepository $masters, RedisFactory $redis)
    {
        $connection = $redis->connection('horizon');

        if (! empty($masters->all())) {
            $connection->setex(self::MARKER, 86400, CarbonImmutable::now()->getTimestamp());

            if (! $this->option('quiet-when-running')) {
                $this->components->info('Horizon is running.');
            }

            return 0;
        }

        // Horizon is not running. Only alert if we previously saw it running, so
        // that an intentionally stopped (or never started) Horizon does not page
        // anyone. The marker is cleared after alerting to fire once per outage.
        if ($connection->del(self::MARKER)) {
            event(new HorizonStopped);

            $this->components->error('Horizon is not running. An alert has been dispatched.');
        } else {
            $this->components->warn('Horizon is not running.');
        }

        return 1;
    }
}
