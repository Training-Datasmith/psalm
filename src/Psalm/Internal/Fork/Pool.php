<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Amp\Cancellation;
use Amp\Future;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\Context_Factory;
use Amp\Parallel\Ipc\Ipc_Hub;
use Amp\Parallel\Ipc\Local_Ipc_Hub;
use Amp\Parallel\Worker\Context_Worker_Factory;
use Amp\Parallel\Worker\Context_Worker_Pool;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\Worker_Pool;
use AssertionError;
use Closure;
use Override;
use Psalm\Progress\Progress;
use Revolt\Event_Loop;
use function Amp\Future\await;
use function array_map;
use function count;
use function gc_collect_cycles;
use const PHP_EOL;
/**
 * Adapted with relatively few changes from
 * https://github.com/etsy/phan/blob/1ccbe7a43a6151ca7c0759d6c53e2c3686994e53/src/Phan/ForkPool.php
 *
 * Authors: https://github.com/morria, https://github.com/TysonAndre
 *
 * Fork off to n-processes and divide up tasks between
 * each process.
 *
 * @internal
 */
final class Pool
{
    private readonly Worker_Pool $pool;
    public function __serialize(): array
    {
        return [];
    }
    /**
     * @param int<2, max> $threads
     */
    public function __construct(public readonly int $threads, private readonly float $time_limit, private readonly Progress $progress)
    {
        $this->pool = new Context_Worker_Pool($threads, new Context_Worker_Factory(contextFactory: new class implements Context_Factory
        {
            public function __construct(private readonly int $child_connect_timeout = 5, private readonly Ipc_Hub $ipc_hub = new Local_Ipc_Hub())
            {
            }
            #[Override]
            public function start(string|array $script, ?Cancellation $cancellation = null): Context
            {
                return Fork_Context::start($script, $this->ipc_hub, $cancellation, $this->child_connect_timeout);
            }
        }));
    }
    /**
     * @template TResult
     * @param array<string> $process_task_data_iterator
     * An array of task data items to be divided up among the
     * workers. The size of this is the number of forked processes.
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint
     * @param class-string<Task<TResult, void, void>> $main_task A task to execute on each task data.
     *                                                           It must return an array (to be gathered).
     *
     * @param Closure(TResult $data):void $task_done_closure A closure to execute when a task is done
     */
    public function run(array $process_task_data_iterator, string $main_task, ?Closure $task_done_closure = null): void
    {
        $total = count($process_task_data_iterator);
        $this->progress->debug("Processing " . $total . " tasks..." . PHP_EOL);
        $cnt = 0;
        $results = [];
        foreach ($process_task_data_iterator as $file) {
            $results[] = $f = $this->pool->submit(new $main_task($file))->get_future();
            if ($task_done_closure) {
                $f->map($task_done_closure);
            }
            $id = Event_Loop::repeat($this->time_limit, function () use ($file): void {
                static $seconds = 0.0;
                /** @psalm-suppress MixedAssignment, MixedOperand */
                $seconds += $this->time_limit;
                $this->progress->write(PHP_EOL . "Processing {$file} is taking {$seconds} seconds..." . PHP_EOL);
            });
            $f->finally(static function () use ($id): void {
                Event_Loop::cancel($id);
            });
            $f->map(function () use (&$cnt, $total): void {
                $cnt++;
                if (!($cnt % 10)) {
                    $percent = (int) ($cnt * 100 / $total);
                    $this->progress->debug("Processing tasks: {$cnt}/{$total} ({$percent}%)..." . PHP_EOL);
                }
            });
        }
        await($results);
    }
    /**
     * @template T
     * @param Task<T, void, void> $task
     * @return array<int, Future<T>>
     */
    public function run_all(Task $task): array
    {
        if ($this->pool->get_idle_worker_count() !== $this->pool->get_worker_count()) {
            throw new AssertionError("Some workers are busy!");
        }
        gc_collect_cycles();
        $workers = [];
        for ($x = 0; $x < $this->threads; $x++) {
            $workers[] = $this->pool->get_worker();
        }
        return array_map(fn(Worker $w): Future => $w->submit($task)->get_future(), $workers);
    }
}