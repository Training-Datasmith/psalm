<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Filesystem_Iterator;
use Recursive_Callback_Filter_Iterator;
use Recursive_Directory_Iterator;
use Recursive_Iterator;
use Recursive_Iterator_Iterator;
use UnexpectedValueException;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function in_array;
use function is_dir;
use const DIRECTORY_SEPARATOR;
/**
 * @internal
 */
class File_Provider
{
    /**
     * @var array<string, array{version: ?int, content: string}>
     */
    protected array $temp_files = [];
    /**
     * @var array<string, string>
     */
    protected static array $open_files = [];
    /**
     * @var array<string, string>
     */
    protected array $open_files_paths = [];
    public function get_contents(string $file_path, bool $go_to_source = false): string
    {
        if (!$go_to_source && isset($this->temp_files[$file_path])) {
            return $this->temp_files[$file_path]['content'];
        }
        if (isset(self::$open_files[$file_path])) {
            return self::$open_files[$file_path];
        }
        if (!file_exists($file_path)) {
            throw new UnexpectedValueException('File ' . $file_path . ' should exist to get contents');
        }
        if (is_dir($file_path)) {
            throw new UnexpectedValueException('File ' . $file_path . ' is a directory');
        }
        $file_contents = (string) file_get_contents($file_path);
        self::$open_files[$file_path] = $file_contents;
        return $file_contents;
    }
    public function set_contents(string $file_path, string $file_contents): void
    {
        if (isset(self::$open_files[$file_path])) {
            self::$open_files[$file_path] = $file_contents;
        }
        if (isset($this->temp_files[$file_path])) {
            $this->temp_files[$file_path] = ['version' => null, 'content' => $file_contents];
        }
        file_put_contents($file_path, $file_contents);
    }
    public function set_open_contents(string $file_path, ?string $file_contents = null): void
    {
        if (isset(self::$open_files[$file_path])) {
            self::$open_files[$file_path] = $file_contents ?? $this->get_contents($file_path, true);
        }
    }
    public function get_modified_time(string $file_path): int
    {
        if (!file_exists($file_path)) {
            throw new UnexpectedValueException('File should exist to get modified time');
        }
        return (int) filemtime($file_path);
    }
    public function add_temporary_file_changes(string $file_path, string $new_content, ?int $version = null): void
    {
        if (isset($this->temp_files[$file_path]) && $version !== null && $this->temp_files[$file_path]['version'] !== null && $version < $this->temp_files[$file_path]['version']) {
            return;
        }
        $this->temp_files[$file_path] = ['version' => $version, 'content' => $new_content];
    }
    public function remove_temporary_file_changes(string $file_path): void
    {
        unset($this->temp_files[$file_path]);
    }
    public function get_open_files_path(): array
    {
        return $this->open_files_paths;
    }
    public function open_file(string $file_path): void
    {
        self::$open_files[$file_path] = $this->get_contents($file_path, true);
        $this->open_files_paths[$file_path] = $file_path;
    }
    public function is_open(string $file_path): bool
    {
        return isset($this->temp_files[$file_path]) || isset(self::$open_files[$file_path]);
    }
    public function close_file(string $file_path): void
    {
        unset($this->temp_files[$file_path], self::$open_files[$file_path], $this->open_files_paths[$file_path]);
    }
    public function file_exists(string $file_path): bool
    {
        return file_exists($file_path);
    }
    public function is_directory(string $file_path): bool
    {
        return is_dir($file_path);
    }
    /**
     * @param array<string> $file_extensions
     * @param null|callable(string):bool $filter
     * @return list<string>
     */
    public function get_files_in_dir(string $dir_path, array $file_extensions, ?callable $filter = null): array
    {
        $file_paths = [];
        $iterator = new Recursive_Directory_Iterator($dir_path, Filesystem_Iterator::CURRENT_AS_PATHNAME | Filesystem_Iterator::SKIP_DOTS);
        if ($filter !== null) {
            $iterator = new Recursive_Callback_Filter_Iterator(
                $iterator,
                /** @param mixed $_ */
                static function (string $current, mixed $_, Recursive_Iterator $iterator) use ($filter): bool {
                    if ($iterator->has_children()) {
                        $path = $current . DIRECTORY_SEPARATOR;
                    } else {
                        $path = $current;
                    }
                    return $filter($path);
                }
            );
        }
        /** @var RecursiveDirectoryIterator */
        $iterator = new Recursive_Iterator_Iterator($iterator);
        $iterator->rewind();
        while ($iterator->valid()) {
            $extension = $iterator->get_extension();
            if (in_array($extension, $file_extensions, true)) {
                $file_paths[] = (string) $iterator->get_real_path();
            }
            $iterator->next();
        }
        return $file_paths;
    }
}