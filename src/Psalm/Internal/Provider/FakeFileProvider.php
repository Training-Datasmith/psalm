<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Override;
use function microtime;
use function str_starts_with;
/**
 * @internal
 */
final class Fake_File_Provider extends File_Provider
{
    /**
     * @var array<string, string>
     */
    public array $fake_files = [];
    /**
     * @var array<string, int>
     */
    public array $fake_file_times = [];
    /**
     * @var array<string, true>
     */
    public array $fake_directories = [];
    #[Override]
    public function file_exists(string $file_path): bool
    {
        return isset($this->fake_files[$file_path]) || parent::file_exists($file_path);
    }
    #[Override]
    public function is_directory(string $file_path): bool
    {
        return isset($this->fake_directories[$file_path]) || parent::is_directory($file_path);
    }
    /** @psalm-external-mutation-free */
    #[Override]
    public function get_contents(string $file_path, bool $go_to_source = false): string
    {
        if (!$go_to_source && isset($this->temp_files[$file_path])) {
            return $this->temp_files[$file_path]['content'];
        }
        return $this->fake_files[$file_path] ?? parent::get_contents($file_path);
    }
    #[Override]
    public function set_contents(string $file_path, string $file_contents): void
    {
        $this->fake_files[$file_path] = $file_contents;
    }
    #[Override]
    public function set_open_contents(string $file_path, ?string $file_contents = null): void
    {
        if (isset($this->fake_files[$file_path])) {
            $this->fake_files[$file_path] = $file_contents ?? $this->get_contents($file_path, true);
        }
    }
    #[Override]
    public function get_modified_time(string $file_path): int
    {
        return $this->fake_file_times[$file_path] ?? parent::get_modified_time($file_path);
    }
    public function register_file(string $file_path, string $file_contents): void
    {
        $this->fake_files[$file_path] = $file_contents;
        $this->fake_file_times[$file_path] = (int) microtime(true);
    }
    public function delete_file(string $file_path): void
    {
        unset($this->fake_files[$file_path]);
        unset($this->fake_file_times[$file_path]);
    }
    /**
     * @param array<string> $file_extensions
     * @param null|callable(string):bool $filter
     * @return list<string>
     */
    #[Override]
    public function get_files_in_dir(string $dir_path, array $file_extensions, ?callable $filter = null): array
    {
        $file_paths = parent::get_files_in_dir($dir_path, $file_extensions, $filter);
        foreach ($this->fake_files as $file_path => $_) {
            if (str_starts_with($file_path, $dir_path)) {
                $file_paths[] = $file_path;
            }
        }
        return $file_paths;
    }
}