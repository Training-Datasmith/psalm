<?php

declare (strict_types=1);
namespace Psalm\Internal;

use Psalm\Progress\Progress;
use function class_exists;
use const PHP_EOL;
/** @internal */
final class Preloader
{
    private static bool $preloaded = false;
    public static function preload(?Progress $progress = null, bool $has_jit = false): void
    {
        if (self::$preloaded) {
            return;
        }
        if ($has_jit) {
            $progress?->write("JIT compilation in progress... ");
        }
        foreach (Preloader_List::CLASSES as $class) {
            class_exists($class);
        }
        if ($has_jit) {
            $progress?->write("Done." . PHP_EOL . PHP_EOL);
        }
        self::$preloaded = true;
    }
}