<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value site settings, editable from the dashboard.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    /** Every key the dashboard can edit, with its default. */
    public const DEFAULTS = [
        'contact_email' => 'hello@tradewithcuso.com',
        'contact_phone' => '',
        'whatsapp_number' => '',
        'telegram_url' => '',
        'instagram_url' => '',
        'twitter_url' => '',
        'bank_name' => '',
        'bank_account_number' => '',
        'bank_account_name' => '',
    ];

    /** Safe for anyone to read. Bank details only go to a trader with a bill. */
    public const PUBLIC_KEYS = [
        'contact_email',
        'contact_phone',
        'whatsapp_number',
        'telegram_url',
        'instagram_url',
        'twitter_url',
    ];

    /** @return array<string, string> */
    public static function values(): array
    {
        $stored = static::query()->pluck('value', 'key')->all();

        return collect(self::DEFAULTS)
            ->map(fn ($default, $key) => (string) ($stored[$key] ?? $default))
            ->all();
    }

    /** @param  array<string, mixed>  $values */
    public static function store(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => trim((string) $value)]);
        }
    }

    /** @return array{bank_name: string, account_number: string, account_name: string}|null */
    public static function bankDetails(): ?array
    {
        $values = static::values();

        if (blank($values['bank_name']) || blank($values['bank_account_number']) || blank($values['bank_account_name'])) {
            return null;
        }

        return [
            'bank_name' => $values['bank_name'],
            'account_number' => $values['bank_account_number'],
            'account_name' => $values['bank_account_name'],
        ];
    }
}
