<?php

declare (strict_types=1);
namespace Psalm\Internal\Plugin_Manager\Command;

use InvalidArgumentException;
use Override;
use Psalm\Internal\Plugin_Manager\Plugin_List_Factory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\Input_Argument;
use Symfony\Component\Console\Input\Input_Interface;
use Symfony\Component\Console\Input\Input_Option;
use Symfony\Component\Console\Output\Output_Interface;
use Symfony\Component\Console\Style\Symfony_Style;
use UnexpectedValueException;
use function assert;
use function getcwd;
use function is_string;
/**
 * @internal
 */
final class Enable_Command extends Command
{
    public function __construct(private readonly Plugin_List_Factory $plugin_list_factory)
    {
        parent::__construct();
    }
    #[Override]
    protected function configure(): void
    {
        $this->set_name('enable')->set_description('Enables a named plugin')->add_argument('pluginName', Input_Argument::REQUIRED, 'Plugin name (fully qualified class name or composer package name)')->add_option('config', 'c', Input_Option::VALUE_REQUIRED, 'Path to Psalm config file')->add_usage('vendor/plugin-package-name [-c path/to/psalm.xml]');
        $this->add_usage('\'Plugin\Class\Name\' [-c path/to/psalm.xml]');
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
        $plugin_name = $input->get_argument('pluginName');
        assert(is_string($plugin_name));
        try {
            $plugin_class = $plugin_list->resolve_plugin_class($plugin_name);
        } catch (InvalidArgumentException) {
            $io->error('Unknown plugin class ' . $plugin_name);
            return 2;
        }
        if ($plugin_list->is_enabled($plugin_class)) {
            $io->note('Plugin already enabled');
            return 3;
        }
        $plugin_list->enable($plugin_class);
        $io->success('Plugin enabled');
        return 0;
    }
}