<?php
namespace verbb\doxter\common\parsers;

use verbb\doxter\Doxter;
use verbb\doxter\models\Shortcode as ShortcodeModel;

use Craft;

use yii\base\Exception;

use closure;

use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class Shortcode extends BaseParser
{
    // Properties
    // =========================================================================

    protected static ?BaseParserInterface $_instance = null;
    protected static string $defaultMethod = 'parse';
    protected array $registeredClasses = [];
    protected array $shortcodes = [];


    // Public Methods
    // =========================================================================

    /**
     * Registers an array of shortcodes with Doxter
     *
     * @param array $shortcodes
     */
    public function registerShortcodes(array $shortcodes): void
    {
        if (count($shortcodes)) {
            foreach ($shortcodes as $shortcode => $callback) {
                $this->registerShortcode($shortcode, $callback);
            }
        }
    }

    /**
     * Registers a new shortcode and its associated callback|class
     *
     * @note
     * Supported shortcode registration syntax
     * shortcode            |   callback
     * ----                 |   --------
     * 'shortcode'          |   'function'
     * 'shortcode:another'  |   function(DoxterShortcode $code) {}
     *                      |   'Namespace\\Class'
     *                      |   'Namespace\\Class@method'
     *
     * @param string $shortcode
     * @param mixed $callback
     *
     * @return void
     */
    public function registerShortcode(string $shortcode, mixed $callback): void
    {
        if (str_contains($shortcode, ':')) {
            $shortcodes = array_filter(array_map('trim', explode(':', $shortcode)));

            foreach ($shortcodes as $code) {
                $this->registerShortcode($code, $callback);
            }
        } else {
            $this->shortcodes[$shortcode] = $callback;
        }
    }

    /**
     * Unregisters the specified shortcode by given name
     *
     * @param string $name
     *
     * @return void
     */
    public function unregisterShortcode(string $name): void
    {
        if ($this->exists($name)) {
            unset($this->shortcodes[$name]);
        }
    }

    public function parse(string $source, array $options = []): mixed
    {
        if (!$this->canBeSafelyParsed($source)) {
            return $source;
        }

        return $this->compile($source);
    }

    /**
     * Compiles the shortcodes in content provided
     *
     * @param string $content
     *
     * @return string
     */
    public function compile(string $content): string
    {
        $pattern = $this->getRegex();

        return preg_replace_callback("/{$pattern}/s", [&$this, 'render'], $content);
    }

    /**
     * @param $matches
     *
     * @return mixed|string
     *
     * @throws Exception
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function render($matches): mixed
    {
        $shortcode = new ShortcodeModel();
        $shortcode->name = $matches[2];
        $shortcode->params = $this->getParameters($matches);
        $shortcode->content = $matches[5];

        $matchedContent = $matches[0];

        if (isset($shortcode->params['verbatim'])) {
            return str_replace(' verbatim', '', $matchedContent);
        }

        $variables = array_merge($shortcode->params, [
            'content' => $shortcode->content,
            'shortcode' => $shortcode,
        ]);

        $tags = Doxter::$plugin->getSettings()->getRegisteredShortcodeTags();

        $template = $tags[$shortcode->name] ?? '';

        if (empty($template)) {
            // Shortcode has not been registered
            // It could be a link: e.g. [title]: https://domain.com
            return $matchedContent;
        }

        if (!Craft::$app->getView()->doesTemplateExist($template)) {
            Doxter::info('Missing template for Shortcode "' . $shortcode->name . '"');

            return $matchedContent;
        }

        return Craft::$app->getView()->renderTemplate($template, $variables);
    }

    /**
     * Strips any shortcodes from content provided
     *
     * @param string $content
     *
     * @return string
     */
    public function strip(string $content): string
    {
        if (empty($this->shortcodes)) {
            return $content;
        }

        $pattern = $this->getRegex();

        return preg_replace_callback("/{$pattern}/s", function($m) {
            if ($m[1] == '[' && $m[6] == ']') {
                return substr($m[0], 1, -1);
            }

            return $m[1] . $m[6];
        }, $content);
    }

    /**
     * Returns the total count of registered shortcodes
     *
     * @return int
     */
    public function getShortcodeCount(): int
    {
        return count($this->shortcodes);
    }

    /**
     * Return true is the given name exist in shortcodes array.
     *
     * @param string $name
     *
     * @return boolean
     */
    public function exists(string $name): bool
    {
        return array_key_exists($name, $this->shortcodes);
    }

    /**
     * Return true is the given content contains the given name shortcode.
     *
     * @param string $content
     * @param string $shortcode
     *
     * @return boolean
     */
    public function contains(string $content, string $shortcode): bool
    {
        if ($this->exists($shortcode)) {
            preg_match_all('/' . $this->getRegex() . '/s', $content, $matches, PREG_SET_ORDER);

            if (empty($matches)) {
                return false;
            }

            foreach ($matches as $match) {
                if ($shortcode === $match[2]) {
                    return true;
                }
            }
        }

        return false;
    }


    // Protected Methods
    // =========================================================================

    /**
     * @return string
     */
    protected function getRegex(): string
    {
        return '\\[(\\[?)([a-z]{3,})(?![\\w-])([^\\]\\/]*(?:\\/(?!\\])[^\\]\\/]*)*?)(?:(\\/)\\]|\\](?:([^\\[]*+(?:\\[(?!\\/\\2\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)';
    }

    /**
     * Parses shortcode string to an attributes array
     *
     * @param string $text
     *
     * @return array
     */
    protected function parseAttributes(string $text): array
    {
        $attributes = [];
        $pattern = '/(\w+)\s*=\s*"([^"]*)"(?:\s|$)|(\w+)\s*=\s*\'([^\']*)\'(?:\s|$)|(\w+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|(\S+)(?:\s|$)/';
        $text = preg_replace("/[\x{00a0}\x{200b}]+/u", " ", $text);

        if (preg_match_all($pattern, $text, $match, PREG_SET_ORDER)) {
            foreach ($match as $m) {
                if (!empty($m[1])) {
                    $attributes[strtolower($m[1])] = stripcslashes($m[2]);
                } else if (!empty($m[3])) {
                    $attributes[strtolower($m[3])] = stripcslashes($m[4]);
                } else if (!empty($m[5])) {
                    $attributes[strtolower($m[5])] = stripcslashes($m[6]);
                } else if (isset($m[7]) and $m[7] != '') {
                    $attributes[] = stripcslashes($m[7]);
                } else if (isset($m[8])) {
                    $attributes[] = stripcslashes($m[8]);
                }
            }
        }

        return $attributes;
    }

    /**
     * Returns parameters found in the shortcodes
     *
     * @param array $matches
     *
     * @return array
     */
    protected function getParameters(array $matches): array
    {
        $params = $this->parseAttributes($matches[3]);

        foreach ($params as $param => $value) {
            // Handles attributes without values ([shortcode attribute])
            if (is_numeric($param) && is_string($value)) {
                $params[$value] = true;

                unset($params[$param]);
            }
        }

        return $params;
    }

    /**
     * Resolves the callback for a given shortcode and returns it
     *
     * @param string $name
     *
     * @return array|closure|string
     */
    protected function getCallback(string $name): array|closure|string
    {
        $callback = $this->shortcodes[$name];

        if (is_string($callback)) {
            $instance = $this->registeredClasses[$callback] ?? null;

            if (str_contains($callback, '@')) {
                $parts = explode('@', $callback);
                $name = $parts[0];
                $method = $parts[1];

                if (!$instance) {
                    $instance = new $name();

                    $this->registeredClasses[$callback] = $instance;
                }

                return [$instance, $method];
            }

            if (class_exists($callback)) {
                if (!$instance) {
                    $instance = new $callback();

                    $this->registeredClasses[$callback] = $instance;
                }

                return [$instance, static::$defaultMethod];
            }
        }

        return $callback;
    }
}
