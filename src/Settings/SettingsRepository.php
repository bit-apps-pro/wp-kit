<?php

namespace BitApps\WPKit\Settings;

use InvalidArgumentException;

/**
 * Typed get/set/save access to a single wp_options row, backed by a SettingsSchema.
 */
final class SettingsRepository
{
    /**
     * @var array<string,mixed>
     */
    private array $values = [];

    public function __construct(
        private string $optionName,
        private SettingsSchema $schema,
        private bool $autoload = true
    ) {
        $this->reload();
    }

    /**
     * Return the cast value for a key, or its field/explicit default if unset.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        if (\array_key_exists($key, $this->values)) {
            return $this->values[$key];
        }

        $field = $this->schema->field($key);
        if ($field === null) {
            return $default;
        }

        // A declared field's own default wins, even when it is null.
        return $field->default();
    }

    /**
     * Cast and store a value for a known key; throws for keys not in the schema.
     *
     * @param mixed $value
     */
    public function set(string $key, $value): self
    {
        $field = $this->schema->field($key);

        if ($field === null) {
            throw new InvalidArgumentException("Unknown setting key: {$key}");
        }

        $this->values[$key] = $field->cast($value);

        return $this;
    }

    /**
     * Set multiple values at once.
     *
     * @param array<string,mixed> $values
     */
    public function fill(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    /**
     * All current values, keyed by field key.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Check whether a key is registered in the schema.
     */
    public function has(string $key): bool
    {
        return $this->schema->has($key);
    }

    /**
     * Persist the current values to the wp_options row.
     */
    public function save(): bool
    {
        return update_option($this->optionName, $this->values, $this->autoload ? 'yes' : 'no');
    }

    /**
     * Re-read the wp_options row, merging stored values over schema defaults.
     */
    public function reload(): void
    {
        $stored = (array) get_option($this->optionName, []);

        $this->values = array_merge($this->schema->defaults(), array_intersect_key($stored, $this->schema->fields()));
    }
}
