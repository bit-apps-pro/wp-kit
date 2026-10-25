<?php

namespace BitApps\WPKit\Tests\Settings;

use BitApps\WPKit\Settings\SettingField;
use BitApps\WPKit\Settings\SettingsRepository;
use BitApps\WPKit\Settings\SettingsSchema;
use BitApps\WPKit\Tests\TestCase;
use InvalidArgumentException;
use WpKitTestState;

final class SettingsRepositoryTest extends TestCase
{
    private function repo(): SettingsRepository
    {
        $schema = (new SettingsSchema())->add(
            SettingField::bool('logging_enabled', true),
            SettingField::int('retention', 30)
        );

        return new SettingsRepository('demo_settings', $schema);
    }

    public function testReturnsDefaultWhenUnset(): void
    {
        $this->assertSame(30, $this->repo()->get('retention'));
    }

    public function testSetSaveReload(): void
    {
        $repo = $this->repo();
        $repo->set('retention', '45')->set('logging_enabled', '0')->save();
        $this->assertSame(45, WpKitTestState::$options['demo_settings']['retention']);
        $fresh = $this->repo();
        $this->assertSame(45, $fresh->get('retention'));
        $this->assertFalse($fresh->get('logging_enabled'));
    }

    public function testDeclaredNullDefaultWinsOverCallerFallback(): void
    {
        $schema = (new SettingsSchema())->add(SettingField::string('label', null));
        $repo   = new SettingsRepository('demo_settings', $schema);

        $this->assertNull($repo->get('label', 'fallback'));
        $this->assertSame('fallback', $repo->get('undeclared', 'fallback'));
    }

    public function testUnknownKeyRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo()->set('nope', 1);
    }
}
