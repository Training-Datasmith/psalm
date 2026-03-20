<?php

declare (strict_types=1);
namespace Psalm\Internal\Plugin_Manager;

use InvalidArgumentException;
use RuntimeException;
use function array_diff_key;
use function array_flip;
use function array_key_exists;
use function array_search;
use function str_contains;
/**
 * @internal
 */
final class Plugin_List
{
    /** @var ?array<string,string> [pluginClass => packageName] */
    private ?array $all_plugins = null;
    /** @var ?array<string,?string> [pluginClass => ?packageName] */
    private ?array $enabled_plugins = null;
    public function __construct(private readonly ?Config_File $config_file, private readonly Composer_Lock $composer_lock)
    {
    }
    /**
     * @return array<string,?string> [pluginClass => ?packageName, ...]
     */
    public function get_enabled(): array
    {
        if (!$this->enabled_plugins) {
            $this->enabled_plugins = [];
            if ($this->config_file) {
                foreach ($this->config_file->get_config()->get_plugin_classes() as $plugin_entry) {
                    $plugin_class = $plugin_entry['class'];
                    $this->enabled_plugins[$plugin_class] = $this->find_plugin_package($plugin_class);
                }
            }
        }
        return $this->enabled_plugins;
    }
    /**
     * @return array<string,?string> [pluginCLass => ?packageName]
     */
    public function get_available(): array
    {
        return array_diff_key($this->get_all(), $this->get_enabled());
    }
    /**
     * @return array<string,string> [pluginClass => packageName]
     */
    public function get_all(): array
    {
        if (null === $this->all_plugins) {
            $this->all_plugins = array_flip($this->composer_lock->get_plugins());
        }
        return $this->all_plugins;
    }
    public function resolve_plugin_class(string $class_or_package): string
    {
        if (!str_contains($class_or_package, '/')) {
            return $class_or_package;
            // must be a class then
        }
        // pluginClass => ?pluginPackage
        $plugin_classes = $this->get_all();
        $class = array_search($class_or_package, $plugin_classes, true);
        if (false === $class) {
            throw new InvalidArgumentException('Unknown plugin: ' . $class_or_package);
        }
        return $class;
    }
    public function find_plugin_package(string $class): ?string
    {
        // pluginClass => ?pluginPackage
        $plugin_classes = $this->get_all();
        return $plugin_classes[$class] ?? null;
    }
    public function is_enabled(string $class): bool
    {
        return array_key_exists($class, $this->get_enabled());
    }
    public function enable(string $class): void
    {
        if (!$this->config_file) {
            throw new RuntimeException('Cannot find Psalm config');
        }
        $this->config_file->add_plugin($class);
    }
    public function disable(string $class): void
    {
        if (!$this->config_file) {
            throw new RuntimeException('Cannot find Psalm config');
        }
        $this->config_file->remove_plugin($class);
    }
}