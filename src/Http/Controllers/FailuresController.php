<?php

namespace Laravel\Horizon\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;

class FailuresController extends Controller
{
    /**
     * The maximum number of failed jobs to scan in a single request.
     */
    const MAX_SCAN = 2500;

    /**
     * The maximum number of job IDs retained per group for "retry all".
     */
    const MAX_IDS_PER_GROUP = 250;

    /**
     * The job repository implementation.
     *
     * @var \Laravel\Horizon\Contracts\JobRepository
     */
    public $jobs;

    /**
     * Create a new controller instance.
     *
     * @param  \Laravel\Horizon\Contracts\JobRepository  $jobs
     * @return void
     */
    public function __construct(JobRepository $jobs)
    {
        parent::__construct();

        $this->jobs = $jobs;
    }

    /**
     * Get the recently failed jobs grouped by exception signature.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function groups(Request $request)
    {
        $limit = min((int) ($request->query('limit') ?: 1000), self::MAX_SCAN);

        $groups = [];
        $scanned = 0;
        $afterIndex = -1;

        while ($scanned < $limit) {
            $chunk = $this->jobs->getFailed($afterIndex);

            if ($chunk->isEmpty()) {
                break;
            }

            foreach ($chunk as $job) {
                $this->accumulate($groups, $job);
                $afterIndex = $job->index;
                $scanned++;
            }
        }

        return [
            'scanned' => $scanned,
            'total' => $this->jobs->countFailed(),
            'groups' => collect($groups)
                ->sortByDesc('count')
                ->values()
                ->all(),
        ];
    }

    /**
     * Fold a failed job into the accumulated groups.
     *
     * @param  array  $groups
     * @param  object  $job
     * @return void
     */
    protected function accumulate(array &$groups, $job)
    {
        [$exception, $message] = $this->parseException($job->exception);

        $signature = md5($exception.'|'.$this->normalize($message));

        if (! isset($groups[$signature])) {
            $groups[$signature] = [
                'signature' => $signature,
                'exception' => $exception,
                'message' => Str::limit($message, 300),
                'count' => 0,
                'queues' => [],
                'jobs' => [],
                'ids' => [],
                'sample_id' => $job->id,
                'first_seen' => $job->failed_at,
                'last_seen' => $job->failed_at,
            ];
        }

        $group = &$groups[$signature];

        $group['count']++;

        if (! in_array($job->queue, $group['queues'])) {
            $group['queues'][] = $job->queue;
        }

        if (! in_array($job->name, $group['jobs'])) {
            $group['jobs'][] = $job->name;
        }

        if (count($group['ids']) < self::MAX_IDS_PER_GROUP) {
            $group['ids'][] = $job->id;
        }

        if ($job->failed_at < $group['first_seen']) {
            $group['first_seen'] = $job->failed_at;
        }

        if ($job->failed_at > $group['last_seen']) {
            $group['last_seen'] = $job->failed_at;
        }
    }

    /**
     * Extract the exception class and message from a stringified exception.
     *
     * @param  string|null  $exception
     * @return array{0: string, 1: string}
     */
    protected function parseException($exception)
    {
        $firstLine = strtok((string) $exception, "\n") ?: 'Unknown';

        // Drop the trailing " in /path/to/file.php:123" location suffix.
        $firstLine = preg_replace('/ in [\/\\\\].*$/', '', $firstLine);

        if (str_contains($firstLine, ': ')) {
            [$class, $message] = explode(': ', $firstLine, 2);

            return [trim($class), trim($message)];
        }

        return [trim($firstLine), ''];
    }

    /**
     * Normalize a message so that near-identical failures cluster together.
     *
     * @param  string  $message
     * @return string
     */
    protected function normalize($message)
    {
        return preg_replace([
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '/\d+/',
        ], ['#uuid', '#'], $message);
    }
}
