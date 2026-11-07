<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Validator;

/**
 * Typed settings. Missing row => default. Values that the owner has not approved
 * are flagged `approved => false` until saved in the admin.
 */
class SettingsService
{
    /** @return array<string, array{rules: array, default: mixed}> */
    public static function registry(): array
    {
        return [
            'ordering.hours' => ['rules' => ['open_minute' => 'required|integer|min:0|max:1439', 'close_minute' => 'required|integer|min:1|max:1440'],
                'default' => ['open_minute' => 600, 'close_minute' => 1320]], // 10:00-22:00 Asia/Dhaka
            'bulk.rule' => ['rules' => ['min_total_quantity' => 'required|integer|min:1|max:100000', 'min_total_poisha' => 'required|integer|min:100|max:10000000000', 'approved' => 'required|boolean'],
                'default' => ['min_total_quantity' => 20, 'min_total_poisha' => 2000000, 'approved' => false]], // placeholder 20 items / ৳20,000
            'bulk.collection_outlet_id' => ['rules' => ['*' => 'nullable|integer'], 'default' => null],
            'fulfilment.dispatch_outlet_id' => ['rules' => ['*' => 'nullable|integer'], 'default' => null],
            'delivery.fees' => ['rules' => ['IN_CITY' => 'required|integer|min:0|max:1000000', 'OUTER_CITY' => 'required|integer|min:0|max:1000000'],
                'default' => ['IN_CITY' => 0, 'OUTER_CITY' => 0]],
            'payment.numbers' => ['rules' => ['bkash' => 'nullable|string|max:20', 'nagad' => 'nullable|string|max:20'],
                'default' => ['bkash' => null, 'nagad' => null]],
            'bulk.prepaid' => ['rules' => ['*' => 'boolean'], 'default' => true], // old system: showroom orders are paid first
            'content.pages' => ['rules' => [
                '*' => 'array|max:30',
                'v.*.slug' => ['required', 'regex:/^[a-z0-9-]{2,40}$/', 'distinct'],
                'v.*.title_bn' => 'required|string|max:120', 'v.*.title_en' => 'nullable|string|max:120',
                'v.*.body_bn' => 'required|string|max:20000', 'v.*.body_en' => 'nullable|string|max:20000',
            ], 'default' => []],
            // where a customer follows the parcel on the courier's own site; {code} = tracking code, {phone} = customer phone
            'courier.tracking_urls' => ['rules' => [
                'STEADFAST' => ['nullable', 'string', 'max:300', 'regex:/^https?:\/\/\S*\{code\}\S*$/'],
                'PATHAO' => ['nullable', 'string', 'max:300', 'regex:/^https?:\/\/\S*\{code\}\S*$/'],
            ], 'default' => ['STEADFAST' => 'https://steadfast.com.bd/t/{code}', 'PATHAO' => 'https://merchant.pathao.com/tracking?consignment_id={code}&phone={phone}']],
            'site.maintenance' => ['rules' => ['*' => 'boolean'], 'default' => false],
        ];
    }

    public function get(string $key): mixed
    {
        $def = self::registry()[$key] ?? throw new \InvalidArgumentException("Unknown setting: $key");
        $row = Setting::find($key);
        if (! $row) return $def['default'];
        return $this->valid($key, $row->value) ? $row->value : $def['default'];
    }

    public function set(string $key, mixed $value, ?int $actorId = null): void
    {
        $def = self::registry()[$key] ?? throw new \InvalidArgumentException("Unknown setting: $key");
        if (! $this->valid($key, $value)) {
            throw new \InvalidArgumentException("Invalid value for setting: $key");
        }
        if ($key === 'ordering.hours' && $value['open_minute'] >= $value['close_minute']) {
            throw new \InvalidArgumentException('open must be before close');
        }
        Setting::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $actorId, 'updated_at' => now()]);
    }

    private function valid(string $key, mixed $value): bool
    {
        $rules = self::registry()[$key]['rules'];
        if (array_key_exists('*', $rules)) {
            $all = ['v' => $rules['*']];
            foreach ($rules as $k => $r) if (str_starts_with($k, 'v.')) $all[$k] = $r;
            return Validator::make(['v' => $value], $all)->passes();
        }
        return is_array($value) && Validator::make($value, $rules)->passes();
    }
}
