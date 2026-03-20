<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Composer\Xdebug_Handler\Xdebug_Handler;
use Override;
use function array_merge;
use function array_splice;
use function assert;
use function count;
use function extension_loaded;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function in_array;
use function ini_get;
use function is_int;
use function preg_replace;
use function strlen;
use function strtolower;
/**
 * @internal
 */
final class Psalm_Restarter extends Xdebug_Handler
{
    private const REQUIRED_OPCACHE_SETTINGS = ['enable' => 1, 'enable_cli' => 1, 'validate_timestamps' => 0, 'file_update_protection' => 0, 'max_accelerated_files' => 1000000, 'interned_strings_buffer' => 64, 'optimization_level' => '0x7FFEBFFF', 'preload' => '', 'log_verbosity_level' => 0, 'save_comments' => 1, 'restrict_api' => ''];
    private const JIT_OPCACHE_SETTINGS = ['jit' => 1205, 'jit_buffer_size' => 128 * 1024 * 1024, 'jit_max_root_traces' => 100000, 'jit_max_side_traces' => 100000, 'jit_max_exit_counters' => 100000, 'jit_hot_loop' => 1, 'jit_hot_func' => 1, 'jit_hot_return' => 1, 'jit_hot_side_exit' => 1, 'jit_blacklist_root_trace' => 255, 'jit_blacklist_side_trace' => 255];
    public bool $enable_jit = false;
    private bool $required = false;
    /**
     * @var string[]
     */
    private array $disabled_extensions = [];
    public function disable_extension(string $disabled_extension): void
    {
        $this->disabled_extensions[] = $disabled_extension;
    }
    /** @param list<non-empty-string> $disable_extensions */
    public function disable_extensions(array $disable_extensions): void
    {
        $this->disabled_extensions = array_merge($this->disabled_extensions, $disable_extensions);
    }
    /**
     * No type hint to allow xdebug-handler v1 and v2 usage
     *
     * @param bool $default
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    #[Override]
    protected function requires_restart($default): bool
    {
        foreach ($this->disabled_extensions as $extension) {
            if (extension_loaded($extension)) {
                $this->required = true;
                break;
            }
        }
        if (!extension_loaded('opcache') && !extension_loaded('Zend OPcache')) {
            return true;
        }
        foreach ($this->get_effective_opcache_settings() as $ini_name => $required_value) {
            $value = (string) ini_get("opcache.{$ini_name}");
            if ($ini_name === 'jit_buffer_size') {
                $value = self::to_bytes($value);
            } elseif ($ini_name === 'enable_cli') {
                $value = in_array($value, ['1', 'true', true, 1]) ? 1 : 0;
            } elseif (is_int($required_value)) {
                $value = (int) $value;
            }
            if ($value !== $required_value) {
                return true;
            }
        }
        $required_memory_consumption = $this->get_required_memory_consumption();
        if ((int) ini_get('opcache.memory_consumption') < $required_memory_consumption) {
            return true;
        }
        return $default || $this->required;
    }
    private static function to_bytes(string $value): int
    {
        if (strlen($value) === 0) {
            return 0;
        }
        $unit = strtolower($value[strlen($value) - 1]);
        if (in_array($unit, ['g', 'm', 'k'], true)) {
            $value = (int) $value;
        } else {
            $unit = '';
            $value = (int) $value;
        }
        switch ($unit) {
            case 'g':
                $value *= 1024;
            // no break
            case 'm':
                $value *= 1024;
            // no break
            case 'k':
                $value *= 1024;
        }
        return $value;
    }
    /**
     * No type hint to allow xdebug-handler v1 and v2 usage
     *
     * @param non-empty-list<string> $command
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     */
    #[Override]
    protected function restart(array $command): void
    {
        if ($this->required && $this->tmp_ini) {
            $regex = '/^\s*((?:zend_)?extension\s*=.*(' . implode('|', $this->disabled_extensions) . ').*)$/mi';
            $content = file_get_contents($this->tmp_ini);
            assert($content !== false);
            $content = (string) preg_replace($regex, ';$1', $content);
            file_put_contents($this->tmp_ini, $content);
        }
        $opcache_loaded = extension_loaded('opcache') || extension_loaded('Zend OPcache');
        // executed in the parent process (before restart)
        // if it wasn't loaded then we apparently don't have opcache installed and there's no point trying
        // to tweak it
        $additional_options = $opcache_loaded ? [] : ['-dzend_extension=opcache'];
        foreach ($this->get_effective_opcache_settings() as $key => $value) {
            $additional_options[] = "-dopcache.{$key}={$value}";
        }
        $required_memory_consumption = $this->get_required_memory_consumption();
        if ((int) ini_get('opcache.memory_consumption') < $required_memory_consumption) {
            $additional_options[] = "-dopcache.memory_consumption={$required_memory_consumption}";
        }
        array_splice($command, 1, 0, $additional_options);
        assert(count($command) > 1);
        parent::restart($command);
    }
    /**
     * @return array<string, int|string>
     */
    private function get_effective_opcache_settings(): array
    {
        if ($this->enable_jit) {
            return self::REQUIRED_OPCACHE_SETTINGS + self::JIT_OPCACHE_SETTINGS;
        }
        return self::REQUIRED_OPCACHE_SETTINGS;
    }
    /**
     * @return positive-int
     */
    private function get_required_memory_consumption(): int
    {
        // Reserve for byte-codes
        $result = 256;
        if ($this->enable_jit) {
            $result += self::JIT_OPCACHE_SETTINGS['jit_buffer_size'] / 1024 / 1024;
        }
        if (isset(self::REQUIRED_OPCACHE_SETTINGS['interned_strings_buffer'])) {
            $result += self::REQUIRED_OPCACHE_SETTINGS['interned_strings_buffer'];
        }
        return $result;
    }
}