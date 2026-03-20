<?php

declare (strict_types=1);
namespace Psalm\Internal\Plugin_Manager\Command;

use Override;
use Psalm\Internal\Plugin_Manager\Plugin_List_Factory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\Input_Interface;
use Symfony\Component\Console\Input\Input_Option;
use Symfony\Component\Console\Output\Output_Interface;
use Symfony\Component\Console\Style\Symfony_Style;
use UnexpectedValueException;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function getcwd;
use function is_string;
/**
 * @internal
 */
final class Show_Command extends Command
{
    public function __construct(private readonly Plugin_List_Factory $plugin_list_factory)
    {
        parent::__construct();
    }
    #[Override]
    protected function configure(): void
    {
        $this->set_name('show')->set_description('Lists enabled and available plugins')->add_option('config', 'c', Input_Option::VALUE_REQUIRED, 'Path to Psalm config file')->add_usage('[-c path/to/psalm.xml]');
    }
    #[Override]
    protected function execute(Input_Interface $input, Output_Interface $output): int
    {
        $io = new Symfony_Style($input, $output);
        $current_dir = (string) getcwd();
        $config_file_path = $input->get_option('config');
        if ($config_file_path !== null && !is_string($config_file_path)) {
            throw new UnexpectedValueException('Config file path should be a string');
        }
        $plugin_list = ($this->plugin_list_factory)($current_dir, $config_file_path);
        $enabled = $plugin_list->get_enabled();
        $available = $plugin_list->get_available();
        $format_row = static fn(string $class, ?string $package): array => [$package, $class];
        $io->section('Enabled');
        if (count($enabled)) {
            $io->table(['Package', 'Class'], array_map($format_row, array_keys($enabled), array_values($enabled)));
        } else {
            $io->note('No plugins enabled');
        }
        $io->section('Available');
        if (count($available)) {
            $io->table(['Package', 'Class'], array_map($format_row, array_keys($available), array_values($available)));
        } else {
            $io->note('No plugins available');
        }
        return 0;
    }
}