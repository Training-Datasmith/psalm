<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Amp\Byte_Stream\Stream_Channel;
use Amp\Cancellation;
use Amp\Future;
use Amp\Parallel\Context\Context_Exception;
use Amp\Parallel\Context\Internal\Abstract_Context;
use Amp\Parallel\Context\Internal\Context_Channel;
use Amp\Parallel\Context\Internal\Exit_Failure;
use Amp\Parallel\Context\Internal\Exit_Success;
use Amp\Parallel\Ipc\Ipc_Hub;
use Amp\Serialization\Native_Serializer;
use Amp\Serialization\Serialization_Exception;
use Amp\Timeout_Cancellation;
use Error;
use Override;
use ParseError;
use Revolt\Event_Loop;
use RuntimeException;
use Throwable;
use TypeError;
use function Amp\Parallel\Ipc\connect;
use function count;
use function define;
use function extension_loaded;
use function fprintf;
use function fwrite;
use function is_file;
use function is_string;
use function pcntl_fork;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wifstopped;
use function pcntl_wstopsig;
use function pcntl_wtermsig;
use function posix_get_last_error;
use function posix_kill;
use function posix_strerror;
use function sprintf;
use function trigger_error;
use const E_USER_ERROR;
use const PHP_EOL;
use const STDERR;
use const WNOHANG;
/**
 * @internal
 * @template-covariant TResult
 * @template-covariant TReceive
 * @template TSend
 * @extends AbstractContext<TResult, TReceive, TSend>
 */
final class Fork_Context extends Abstract_Context
{
    private const DEFAULT_START_TIMEOUT = 5;
    /**
     * @param string|non-empty-list<string> $argv Path to PHP script or array with first element as path and
     *     following elements options to the PHP script (e.g.: ['bin/worker.php', 'Option1Value', 'Option2Value']).
     * @param positive-int $childConnectTimeout Number of seconds the child will attempt to connect to the parent
     *      before failing.
     * @throws ContextException If starting the process fails.
     */
    public static function start(string|array $argv, Ipc_Hub $ipc_hub, ?Cancellation $cancellation = null, int $child_connect_timeout = self::DEFAULT_START_TIMEOUT): self
    {
        $serializer = extension_loaded('igbinary') ? new Igbinary_Serializer() : new Native_Serializer();
        $key = $ipc_hub->generate_key();
        // Fork
        if (($pid = pcntl_fork()) < 0) {
            throw new RuntimeException(posix_strerror(posix_get_last_error()));
        }
        // Parent
        if ($pid > 0) {
            try {
                $socket = $ipc_hub->accept($key, $cancellation);
                $ipc_channel = new Stream_Channel($socket, $socket, $serializer);
                $socket = $ipc_hub->accept($key, $cancellation);
                $result_channel = new Stream_Channel($socket, $socket, $serializer);
            } catch (Throwable $exception) {
                $cancellation?->throw_if_requested();
                throw new Context_Exception("Starting the process failed", 0, $exception);
            }
            return new self($pid, $ipc_channel, $result_channel);
        }
        // Child
        define("AMP_CONTEXT", "parallel");
        if (is_string($argv)) {
            $argv = [$argv];
        }
        $connect_cancellation = new Timeout_Cancellation((float) $child_connect_timeout);
        $uri = $ipc_hub->get_uri();
        try {
            $socket = connect($uri, $key, $connect_cancellation);
            $ipc_channel = new Stream_Channel($socket, $socket, $serializer);
            $socket = connect($uri, $key, $connect_cancellation);
            $result_channel = new Stream_Channel($socket, $socket, $serializer);
        } catch (Throwable $exception) {
            trigger_error($exception->get_message(), E_USER_ERROR);
        }
        try {
            if (!isset($argv[0])) {
                throw new Error("No script path given");
            }
            if (!is_file($argv[0])) {
                throw new Error(sprintf("No script found at '%s' (be sure to provide the full path to the script)", $argv[0]));
            }
            try {
                $argc = count($argv);
                $callable = require $argv[0];
            } catch (TypeError $exception) {
                throw new Error(sprintf("Script '%s' did not return a callable function: %s", $argv[0], $exception->get_message()), 0, $exception);
            } catch (ParseError $exception) {
                throw new Error(sprintf("Script '%s' contains a parse error: %s", $argv[0], $exception->get_message()), 0, $exception);
            }
            $return_value = $callable(new Context_Channel($ipc_channel));
            $result = new Exit_Success($return_value instanceof Future ? $return_value->await() : $return_value);
        } catch (Throwable $exception) {
            $result = new Exit_Failure($exception);
        }
        try {
            try {
                $result_channel->send($result);
            } catch (Serialization_Exception $exception) {
                // Serializing the result failed. Send the reason why.
                $result_channel->send(new Exit_Failure($exception));
            }
        } catch (Throwable $exception) {
            fprintf(STDERR, "Could not send result to parent: '%s'; be sure to shutdown the child before ending the parent" . PHP_EOL, $exception->get_message());
        }
        Event_Loop::run();
        fwrite(STDERR, "ERROR IN WORKER: Unreachable!" . PHP_EOL);
        exit(1);
    }
    private ?int $exited = null;
    /**
     * @param StreamChannel<TReceive, TSend> $ipcChannel
     */
    private function __construct(private readonly int $pid, Stream_Channel $ipc_channel, Stream_Channel $result_channel)
    {
        parent::__construct($ipc_channel, $result_channel);
    }
    public function __destruct()
    {
        $this->close();
    }
    #[Override]
    public function receive(?Cancellation $cancellation = null): mixed
    {
        $this->check_exit();
        return parent::receive($cancellation);
    }
    #[Override]
    public function send(mixed $data): void
    {
        $this->check_exit();
        parent::send($data);
    }
    private function check_exit(bool $wait = false): ?int
    {
        if ($this->exited === null) {
            if (pcntl_waitpid($this->pid, $status, $wait ? 0 : WNOHANG) === 0) {
                return null;
            }
            $signal = -1;
            if (pcntl_wifsignaled($status)) {
                $signal = pcntl_wtermsig($status);
            } elseif (pcntl_wifexited($status)) {
                $signal = pcntl_wexitstatus($status) - 128;
            } elseif (pcntl_wifstopped($status)) {
                $signal = pcntl_wstopsig($status);
            }
            $this->exited = $signal;
        }
        if (!$this->we_killed && $this->exited > 0) {
            $signal = $this->exited;
            if ($signal === 11) {
                $signal = "11: THIS IS A PHP BUG, please report this to https://github.com/vimeo/psalm/issues" . " AND to https://github.com/php/php-src/issues";
            } elseif ($signal === 9) {
                $signal = "9: the process was likely killed by the OOM killer, try increasing the swap space " . "or use the arrayCache=\"false\" config to reduce memory usage";
            }
            throw new Context_Exception("Worker exited due to signal {$signal}!");
        }
        return $this->exited;
    }
    private bool $we_killed = false;
    #[Override]
    public function close(): void
    {
        if ($this->check_exit() === null) {
            $this->we_killed = true;
            posix_kill($this->pid, 9);
            $this->check_exit(true);
        }
        parent::close();
    }
    #[Override]
    public function join(?Cancellation $cancellation = null): mixed
    {
        try {
            $data = $this->receive_exit_result($cancellation);
        } finally {
            $this->close();
        }
        return $data->get_result();
    }
}