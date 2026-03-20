<?php

declare (strict_types=1);
namespace Psalm\Internal\Plugin_Manager;

use RuntimeException;
use function array_merge;
use function assert;
use function file_get_contents;
use function is_array;
use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
/**
 * @internal
 */
final class Composer_Lock
{
    /** @param string[] $file_names */
    public function __construct(private readonly array $file_names)
    {
    }
    /**
     * @psalm-assert-if-true array{
     *      name: string,
     *      extra: array{psalm: array{pluginClass: string}}
     * } $package
     * @psalm-pure
     */
    public function is_plugin(mixed $package): bool
    {
        return is_array($package) && isset($package['name'], $package['extra']['psalm']['pluginClass']) && is_string($package['name']) && is_array($package['extra']) && is_array($package['extra']['psalm']) && is_string($package['extra']['psalm']['pluginClass']);
    }
    /**
     * @return array<string,string> [packageName => pluginClass, ...]
     */
    public function get_plugins(): array
    {
        $plugin_packages = $this->get_all_plugin_packages();
        $ret = [];
        foreach ($plugin_packages as $package) {
            $ret[$package['name']] = $package['extra']['psalm']['pluginClass'];
        }
        return $ret;
    }
    private function read(string $file_name): array
    {
        $file_contents = file_get_contents($file_name);
        assert($file_contents !== false);
        $contents = json_decode($file_contents, true);
        if ($error = json_last_error()) {
            throw new RuntimeException(json_last_error_msg(), $error);
        }
        if (!is_array($contents)) {
            throw new RuntimeException('Malformed ' . $file_name . ', expecting JSON-encoded object');
        }
        return $contents;
    }
    /**
     * @return list<array{name:string,extra:array{psalm:array{pluginClass:string}}}>
     */
    private function get_all_plugin_packages(): array
    {
        $packages = $this->get_all_packages();
        $ret = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($packages as $package) {
            if ($this->is_plugin($package)) {
                $ret[] = $package;
            }
        }
        return $ret;
    }
    private function get_all_packages(): array
    {
        $packages = [];
        foreach ($this->file_names as $file_name) {
            $composer_lock_contents = $this->read($file_name);
            if (!isset($composer_lock_contents['packages']) || !is_array($composer_lock_contents['packages'])) {
                throw new RuntimeException('packages section is missing or not an array');
            }
            if (!isset($composer_lock_contents['packages-dev']) || !is_array($composer_lock_contents['packages-dev'])) {
                throw new RuntimeException('packages-dev section is missing or not an array');
            }
            $packages = array_merge($packages, array_merge($composer_lock_contents['packages'], $composer_lock_contents['packages-dev']));
        }
        return $packages;
    }
}