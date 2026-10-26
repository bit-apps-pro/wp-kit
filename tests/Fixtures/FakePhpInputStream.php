<?php

namespace BitApps\WPKit\Tests\Fixtures;

/**
 * Replaces the built-in php:// wrapper so tests can feed php://input and count reads.
 */
final class FakePhpInputStream
{
    public static int $opens = 0;

    /** @var null|resource set by PHP for user stream wrappers */
    public $context;

    private static string $_content = '';

    private static bool $_installed = false;

    private int $_position = 0;

    /**
     * Serves $content as php://input until uninstall().
     */
    public static function install(string $content): void
    {
        self::$_content = $content;
        self::$opens    = 0;

        if (!self::$_installed) {
            stream_wrapper_unregister('php');
            stream_wrapper_register('php', self::class);
            self::$_installed = true;
        }
    }

    /**
     * Restores the built-in php:// wrapper; safe to call when not installed.
     */
    public static function uninstall(): void
    {
        if (self::$_installed) {
            stream_wrapper_restore('php');
            self::$_installed = false;
        }
    }

    /**
     * Opens php://input only; every other php:// stream is refused.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if ($path !== 'php://input') {
            return false;
        }

        ++self::$opens;
        $this->_position = 0;

        return true;
    }

    /**
     * Reads the next chunk of the fake body.
     */
    public function stream_read(int $count): string
    {
        $chunk = substr(self::$_content, $this->_position, $count);
        $this->_position += \strlen($chunk);

        return $chunk;
    }

    /**
     * Reports whether the fake body is exhausted.
     */
    public function stream_eof(): bool
    {
        return $this->_position >= \strlen(self::$_content);
    }

    /**
     * Returns empty stat data; file_get_contents() only needs the call to succeed.
     */
    public function stream_stat(): array
    {
        return [];
    }
}
