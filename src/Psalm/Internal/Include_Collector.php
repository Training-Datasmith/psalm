<?php

declare (strict_types=1);
namespace Psalm\Internal;

use function array_diff;
use function array_unique;
use function array_values;
use function get_included_files;
use function preg_grep;
use const PREG_GREP_INVERT;
/**
 * Include collector
 *
 * Used to execute code that may cause file inclusions, and report what files have been included
 * NOTE: dependencies of this class should be kept at minimum, as it's used before autoloader is
 * registered.
 *
 * @internal
 */
final class Include_Collector
{
    /** @var list<string> */
    private array $included_files = [];
    /**
     * @template T
     * @param callable():T $f
     * @return T
     */
    public function run_and_collect(callable $f)
    {
        $before = get_included_files();
        $ret = $f();
        $after = get_included_files();
        $included = array_diff($after, $before);
        $this->included_files = array_values(array_unique([...$this->included_files, ...$included]));
        return $ret;
    }
    /** @return list<string> */
    public function get_included_files(): array
    {
        return $this->included_files;
    }
    /** @return list<string> */
    public function get_filtered_included_files(): array
    {
        return array_values(preg_grep('@^phar://@', $this->get_included_files(), PREG_GREP_INVERT));
    }
}