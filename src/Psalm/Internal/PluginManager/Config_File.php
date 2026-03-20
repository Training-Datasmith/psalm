<?php

declare (strict_types=1);
namespace Psalm\Internal\Plugin_Manager;

use Dom_Document;
use Dom_Element;
use Psalm\Config;
use RuntimeException;
use function assert;
use function file_get_contents;
use function file_put_contents;
use function sprintf;
use function strpos;
use function substr;
/**
 * @internal
 */
final class Config_File
{
    private string $path;
    private ?string $psalm_header = null;
    private ?int $psalm_tag_end_pos = null;
    public function __construct(private readonly string $current_dir, ?string $explicit_path)
    {
        if ($explicit_path) {
            $this->path = $explicit_path;
        } else {
            $path = Config::locate_config_file($current_dir);
            if (!$path) {
                throw new RuntimeException('Cannot find Psalm config');
            }
            $this->path = $path;
        }
    }
    public function get_config(): Config
    {
        return Config::load_from_xml_file($this->path, $this->current_dir);
    }
    public function remove_plugin(string $plugin_class): void
    {
        $config_xml = $this->read_xml();
        /** @var DOMElement */
        $psalm_root = $config_xml->get_elements_by_tag_name('psalm')[0];
        $plugins_elements = $psalm_root->get_elements_by_tag_name('plugins');
        if (!$plugins_elements->length) {
            // no plugins, nothing to remove
            return;
        }
        /** @var DOMElement */
        $plugins_element = $plugins_elements->item(0);
        $plugin_elements = $plugins_element->get_elements_by_tag_name('pluginClass');
        foreach ($plugin_elements as $plugin_element) {
            if ($plugin_element->get_attribute('class') === $plugin_class) {
                $plugins_element->remove_child($plugin_element);
                break;
            }
        }
        if (!$plugin_elements->length) {
            // avoid breaking old psalm binaries, whose schema did not allow empty plugins
            $psalm_root->remove_child($plugins_element);
        }
        $this->save_xml($config_xml);
    }
    public function add_plugin(string $plugin_class): void
    {
        $config_xml = $this->read_xml();
        /** @var DOMElement */
        $psalm_root = $config_xml->get_elements_by_tag_name('psalm')->item(0);
        $plugins_elements = $psalm_root->get_elements_by_tag_name('plugins');
        if (!$plugins_elements->length) {
            $plugins_element = $config_xml->create_element('plugins');
            if ($plugins_element) {
                $psalm_root->append_child($plugins_element);
            }
        } else {
            /** @var DOMElement */
            $plugins_element = $plugins_elements->item(0);
        }
        $plugin_class_element = $config_xml->create_element('pluginClass');
        if ($plugin_class_element) {
            $plugin_class_element->set_attribute('xmlns', Config::CONFIG_NAMESPACE);
            $plugin_class_element->set_attribute('class', $plugin_class);
            if ($plugins_element) {
                $plugins_element->append_child($plugin_class_element);
            }
        }
        $this->save_xml($config_xml);
    }
    private function read_xml(): Dom_Document
    {
        $doc = new Dom_Document();
        $file_contents = file_get_contents($this->path);
        assert($file_contents !== false);
        if (($tag_start = strpos($file_contents, '<psalm')) !== false) {
            $tag_end = strpos($file_contents, '>', $tag_start + 1);
            if ($tag_end !== false) {
                $this->psalm_tag_end_pos = $tag_end;
                $this->psalm_header = substr($file_contents, 0, $tag_end);
            }
        }
        assert($file_contents !== '');
        $doc->load_xml($file_contents);
        return $doc;
    }
    private function save_xml(Dom_Document $config_xml): void
    {
        $new_file_contents = $config_xml->save_xml($config_xml);
        if (($tag_start = strpos($new_file_contents, '<psalm')) !== false) {
            $tag_end = strpos($new_file_contents, '>', $tag_start + 1);
            if ($tag_end !== false && $new_file_contents[$tag_end - 1] !== '/' && $this->psalm_tag_end_pos && $this->psalm_header) {
                $new_file_contents = $this->psalm_header . substr($new_file_contents, $tag_end);
            }
        }
        $result = file_put_contents($this->path, $new_file_contents);
        if ($result === false) {
            throw new RuntimeException(sprintf('Unable to save xml to %s', $this->path));
        }
    }
}