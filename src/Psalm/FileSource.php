<?php

declare (strict_types=1);
namespace Psalm;

interface File_Source
{
    /**
     * @psalm-mutation-free
     */
    public function get_file_name(): string;
    /**
     * @psalm-mutation-free
     */
    public function get_file_path(): string;
    /**
     * @psalm-mutation-free
     */
    public function get_root_file_name(): string;
    /**
     * @psalm-mutation-free
     */
    public function get_root_file_path(): string;
    /**
     * @psalm-mutation-free
     */
    public function get_aliases(): Aliases;
}