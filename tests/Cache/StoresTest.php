<?php

namespace BitApps\WPKit\Tests\Cache;

use BitApps\WPKit\Cache\Stores\FileStore;
use BitApps\WPKit\Cache\Stores\TransientStore;
use BitApps\WPKit\Cache\Stores\WpObjectCacheStore;
use BitApps\WPKit\Tests\TestCase;

final class StoresTest extends TestCase
{
    /**
     * @var string
     */
    private $fileStoreDirectory;

    protected function tearDown(): void
    {
        if ($this->fileStoreDirectory !== null && is_dir($this->fileStoreDirectory)) {
            $this->removeDirectory($this->fileStoreDirectory);
        }

        parent::tearDown();
    }

    public function testTransientStorePutGetRoundTrip(): void
    {
        $store = new TransientStore();

        $this->assertTrue($store->put('k', 'v', 60));
        $this->assertSame('v', $store->get('k'));
        $this->assertNull($store->get('missing'));
    }

    public function testTransientStoreExpiresWhenCurrentTimeAdvancesPastTtl(): void
    {
        \WpKitTestState::$currentTime = '2024-01-01 00:00:00';
        $store                        = new TransientStore();

        $store->put('k', 'v', 60);
        $this->assertSame('v', $store->get('k'));

        \WpKitTestState::$currentTime = '2024-01-01 00:01:01';

        $this->assertNull($store->get('k'));
    }

    public function testTransientStoreForget(): void
    {
        $store = new TransientStore();
        $store->put('k', 'v', 60);

        $this->assertTrue($store->forget('k'));
        $this->assertNull($store->get('k'));
    }

    public function testTransientStoreFlushIsDocumentedNoOp(): void
    {
        $store = new TransientStore();
        $store->put('k', 'v', 60);

        $this->assertFalse($store->flush());
        $this->assertSame('v', $store->get('k'), 'flush() must not silently clear transients it cannot enumerate');
    }

    public function testTransientStoreIncrement(): void
    {
        $store = new TransientStore();
        $store->put('n', 1, 60);

        $this->assertSame(3, $store->increment('n', 2));
        $this->assertSame(1, $store->decrement('n', 2));
    }

    public function testTransientStorePrefixIsolatesKeys(): void
    {
        $a = new TransientStore('a_');
        $b = new TransientStore('b_');

        $a->put('k', 'from-a', 60);
        $b->put('k', 'from-b', 60);

        $this->assertSame('from-a', $a->get('k'));
        $this->assertSame('from-b', $b->get('k'));
    }

    public function testTransientStoreAddRespectsExistingKey(): void
    {
        $store = new TransientStore();
        $store->put('k', 'original', 60);

        $this->assertFalse($store->add('k', 'replacement', 60));
        $this->assertSame('original', $store->get('k'));
    }

    public function testTransientStoreForeverRoundTrip(): void
    {
        \WpKitTestState::$currentTime = '2024-01-01 00:00:00';
        $store                        = new TransientStore();

        $this->assertTrue($store->forever('k', 'v'));

        \WpKitTestState::$currentTime = '2030-01-01 00:00:00';

        $this->assertSame('v', $store->get('k'));
    }

    public function testObjectCacheStorePutGetRoundTrip(): void
    {
        $store = new WpObjectCacheStore('test-group');

        $this->assertTrue($store->put('k', 'v', 60));
        $this->assertSame('v', $store->get('k'));
        $this->assertNull($store->get('missing'));
    }

    public function testObjectCacheStoreForget(): void
    {
        $store = new WpObjectCacheStore('test-group');
        $store->put('k', 'v', 60);

        $this->assertTrue($store->forget('k'));
        $this->assertNull($store->get('k'));
    }

    public function testObjectCacheStoreFlush(): void
    {
        $store = new WpObjectCacheStore('test-group');
        $store->put('k', 'v', 60);

        $this->assertTrue($store->flush());
        $this->assertNull($store->get('k'));
    }

    public function testObjectCacheStoreIncrement(): void
    {
        $store = new WpObjectCacheStore('test-group');
        $store->put('n', 1, 60);

        $this->assertSame(3, $store->increment('n', 2));
        $this->assertSame(1, $store->decrement('n', 2));
    }

    public function testObjectCacheStoreGroupIsolatesKeys(): void
    {
        $a = new WpObjectCacheStore('group-a');
        $b = new WpObjectCacheStore('group-b');

        $a->put('k', 'from-a', 60);
        $b->put('k', 'from-b', 60);

        $this->assertSame('from-a', $a->get('k'));
        $this->assertSame('from-b', $b->get('k'));
    }

    public function testObjectCacheStoreAddRespectsExistingKey(): void
    {
        $store = new WpObjectCacheStore('test-group');
        $store->put('k', 'original', 60);

        $this->assertFalse($store->add('k', 'replacement', 60));
        $this->assertSame('original', $store->get('k'));
    }

    public function testObjectCacheStoreForeverRoundTrip(): void
    {
        $store = new WpObjectCacheStore('test-group');

        $this->assertTrue($store->forever('k', 'v'));
        $this->assertSame('v', $store->get('k'));
    }

    public function testFileStorePutGetRoundTrip(): void
    {
        $store = new FileStore($this->makeFileStoreDirectory());

        $this->assertTrue($store->put('k', 'v', 60));
        $this->assertSame('v', $store->get('k'));
        $this->assertNull($store->get('missing'));
    }

    public function testFileStoreExpiresWhenClockAdvancesPastTtl(): void
    {
        $now   = 1000;
        $clock = function () use (&$now) {
            return $now;
        };
        $store = new FileStore($this->makeFileStoreDirectory(), '', $clock);

        $store->put('k', 'v', 60);
        $this->assertSame('v', $store->get('k'));

        $now += 61;

        $this->assertNull($store->get('k'));
    }

    public function testFileStoreForget(): void
    {
        $store = new FileStore($this->makeFileStoreDirectory());
        $store->put('k', 'v', 60);

        $this->assertTrue($store->forget('k'));
        $this->assertNull($store->get('k'));
    }

    public function testFileStoreFlushClearsDirectory(): void
    {
        $store = new FileStore($this->makeFileStoreDirectory());
        $store->put('a', '1', 60);
        $store->put('b', '2', 60);

        $this->assertTrue($store->flush());
        $this->assertNull($store->get('a'));
        $this->assertNull($store->get('b'));
    }

    public function testFileStoreIncrement(): void
    {
        $store = new FileStore($this->makeFileStoreDirectory());
        $store->put('n', 1, 60);

        $this->assertSame(3, $store->increment('n', 2));
        $this->assertSame(1, $store->decrement('n', 2));
    }

    public function testFileStoreCreatesMissingDirectory(): void
    {
        $directory = $this->makeFileStoreDirectory() . '/nested';
        $store     = new FileStore($directory);

        $this->assertTrue(is_dir($directory));
        $this->assertTrue($store->put('k', 'v', 60));
        $this->assertSame('v', $store->get('k'));
    }

    /**
     * A directory WordPress refuses to create (core rejects '../') fails loudly instead of silently disabling the cache.
     */
    public function testFileStoreThrowsWhenItCannotCreateItsDirectory(): void
    {
        $this->expectException(\RuntimeException::class);

        new FileStore($this->makeFileStoreDirectory() . '/../cache');
    }

    public function testFileStoreFlushIsPrefixScoped(): void
    {
        $baseDir = $this->makeFileStoreDirectory();
        $a       = new FileStore($baseDir, 'a_');
        $b       = new FileStore($baseDir, 'b_');

        $a->put('k', 'from-a', 60);
        $b->put('k', 'from-b', 60);

        $this->assertTrue($a->flush());

        $this->assertNull($a->get('k'), 'flushed store must be cleared');
        $this->assertSame('from-b', $b->get('k'), 'sibling prefix on the same directory must survive');
    }

    public function testFileStoreAddRespectsExistingKey(): void
    {
        $store = new FileStore($this->makeFileStoreDirectory());
        $store->put('k', 'original', 60);

        $this->assertFalse($store->add('k', 'replacement', 60));
        $this->assertSame('original', $store->get('k'));
    }

    public function testFileStoreForeverNeverExpires(): void
    {
        $now   = 1000;
        $clock = function () use (&$now) {
            return $now;
        };
        $store = new FileStore($this->makeFileStoreDirectory(), '', $clock);

        $this->assertTrue($store->forever('k', 'v'));

        $now += 315360000;

        $this->assertSame('v', $store->get('k'));
    }

    private function makeFileStoreDirectory(): string
    {
        $this->fileStoreDirectory = sys_get_temp_dir() . '/wpkit-filestore-test-' . uniqid('', true);

        return $this->fileStoreDirectory;
    }

    /**
     * Recursively deletes a directory tree; used to clean up FileStore fixtures, including nested dirs.
     */
    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory . '/*') ?: [] as $path) {
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
