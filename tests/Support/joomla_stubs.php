<?php

/**
 * The Joomla classes more than one unit test needs, declared once.
 *
 * Joomla is not in vendor/, and PHPUnit loads every test file into one process,
 * so two test files each declaring the same stub is a fatal redeclaration. A test
 * that needs one of these requires this file; a stub only one test uses stays in
 * that test.
 */

declare(strict_types=1);

namespace Joomla\CMS {
    final class Factory
    {
        public static mixed $application = null;

        public static function getApplication(): object
        {
            if (self::$application === null) {
                throw new \RuntimeException('No application');
            }

            return self::$application;
        }
    }
}

namespace Joomla\CMS\Language {
    final class Text
    {
        public static bool $fail = false;

        /**
         * Translations a test loaded. An unknown key comes back as itself, as in Joomla.
         *
         * @var array<string, string>
         */
        public static array $strings = [];

        public static function sprintf(string $string, mixed ...$args): string
        {
            if (self::$fail) {
                throw new \ValueError('Unknown format specifier');
            }

            return \sprintf(self::$strings[$string] ?? $string, ...$args);
        }

        public static function _(string $string): string
        {
            if (self::$fail) {
                throw new \ValueError('Unknown format specifier');
            }

            return self::$strings[$string] ?? $string;
        }
    }
}

namespace Joomla\CMS\User {
    class User
    {
        /** @var list<array{string, ?string}> */
        public array $asked = [];

        /**
         * @param  list<string>  $granted  Actions granted on com_ecskccustom.
         */
        public function __construct(private readonly array $granted = [])
        {
        }

        public function authorise($action, $assetname = null)
        {
            $this->asked[] = [$action, $assetname];

            return $assetname === 'com_ecskccustom' && \in_array($action, $this->granted, true);
        }
    }
}

namespace Joomla\CMS\Router {
    final class Route
    {
        /**
         * The non-SEF URL as given, escaped when `$xhtml` asks for it, as Joomla's
         * admin router leaves an `index.php?option=...` URL.
         */
        public static function _($url, $xhtml = true): string
        {
            return $xhtml ? \htmlspecialchars((string) $url, ENT_COMPAT, 'UTF-8') : (string) $url;
        }
    }
}

namespace Joomla\CMS\Extension {
    class MVCComponent
    {
        public function __construct(mixed $dispatcherFactory)
        {
        }
    }
}

namespace Joomla\CMS\MVC\Model {
    class ListModel
    {
        /** @var array<string, mixed> */
        private array $state = [];

        public function __construct($config = [], $factory = null)
        {
        }

        public function setState($property, $value = null)
        {
            return $this->state[$property] = $value;
        }

        public function getState($property = null, $default = null)
        {
            return $this->state[$property] ?? $default;
        }

        protected function populateState($ordering = null, $direction = null)
        {
        }
    }
}

namespace {
    if (!\defined('_JEXEC')) {
        \define('_JEXEC', 1);
    }
}
