<?php

declare (strict_types=1);
namespace Psalm\Internal\Execution_Environment;

use RuntimeException;
use function exec;
use function function_exists;
use function sprintf;
/**
 * @author Kitamura Satoshi <with.no.parachute@gmail.com>
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 * @internal
 */
final class System_Command_Executor
{
    /**
     * Execute command.
     *
     * @throws RuntimeException
     * @return string[]
     */
    public function execute(string $command): array
    {
        if (!function_exists('exec')) {
            throw new RuntimeException(sprintf('exec does not exist, failed to execute command: %s', $command));
        }
        exec($command, $result, $return_value);
        if ($return_value === 0) {
            return $result;
        }
        throw new RuntimeException(sprintf('Failed to execute command: %s', $command), $return_value);
    }
}