<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Services\AuditService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Re-runnable. Outlets matched by code and updated; nothing deleted. */
class SetupOwnerData extends Command
{
    protected $signature = 'dudhwala:setup {--file= : path to owner setup JSON} {--allow-placeholders : accept REPLACE_WITH_* values (testing only)}';
    protected $description = 'Apply outlets, hours and dispatch/collection outlets from the owner JSON';

    public function handle(SettingsService $settings, AuditService $audit): int
    {
        $path = $this->option('file');
        if (! $path || ! is_file($path)) { $this->error('Usage: dudhwala:setup --file=owner-setup.json'); return self::FAILURE; }
        $data = json_decode(file_get_contents($path), true);
        if (! is_array($data)) { $this->error('Invalid JSON'); return self::FAILURE; }

        $v = Validator::make($data, [
            'outlets' => 'required|array|min:1',
            'outlets.*.code' => ['required', 'regex:/^[A-Z0-9-]{2,32}$/'],
            'outlets.*.nameBn' => 'required|string',
            'outlets.*.nameEn' => 'nullable|string',
            'outlets.*.addressLine' => 'required|string|max:255',
            'outlets.*.phone' => 'nullable|string|max:20',
            'outlets.*.status' => 'nullable|in:ACTIVE,PAUSED,CLOSED',
            'outlets.*.hours' => 'nullable|array|max:7',
            'outlets.*.hours.*.weekday' => 'required|integer|between:0,6',
            'outlets.*.hours.*.opens' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'outlets.*.hours.*.closes' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'dispatchOutletCode' => 'nullable|string',
            'collectionOutletCode' => 'nullable|string',
        ]);
        if ($v->fails()) { foreach ($v->errors()->all() as $e) $this->error($e); return self::FAILURE; }

        if (! $this->option('allow-placeholders') && str_contains(json_encode($data), 'REPLACE_WITH')) {
            $this->error('File still contains REPLACE_WITH_* placeholders. Fill in real values.');
            return self::FAILURE;
        }

        $codes = array_column($data['outlets'], 'code');
        foreach (['dispatchOutletCode', 'collectionOutletCode'] as $k) {
            if (! empty($data[$k]) && ! in_array($data[$k], $codes, true)) { $this->error("Unknown outlet code in $k"); return self::FAILURE; }
        }
        foreach ($data['outlets'] as $o) foreach ($o['hours'] ?? [] as $h) {
            if ($h['opens'] >= $h['closes']) { $this->error("Bad hours for {$o['code']}"); return self::FAILURE; }
        }

        DB::transaction(function () use ($data, $settings, $audit) {
            $ids = [];
            foreach ($data['outlets'] as $o) {
                $row = Outlet::updateOrCreate(['code' => $o['code']], [
                    'name_bn' => $o['nameBn'], 'name_en' => $o['nameEn'] ?? null, 'address_line' => $o['addressLine'],
                    'phone' => $o['phone'] ?? null, 'status' => $o['status'] ?? 'ACTIVE',
                ]);
                $ids[$o['code']] = $row->id;
                if (isset($o['hours'])) {
                    $row->hours()->delete();
                    foreach ($o['hours'] as $h) $row->hours()->create(['weekday' => $h['weekday'], 'opens_at' => $h['opens'], 'closes_at' => $h['closes']]);
                }
            }
            if (! empty($data['dispatchOutletCode'])) $settings->set('fulfilment.dispatch_outlet_id', $ids[$data['dispatchOutletCode']]);
            if (! empty($data['collectionOutletCode'])) $settings->set('bulk.collection_outlet_id', $ids[$data['collectionOutletCode']]);
            $audit->log('setup.applied', 'setting', 'setup', null, ['outlets' => array_keys($ids)], null, 'CLI');
        });
        $this->info('Setup applied: '.count($data['outlets']).' outlet(s).');
        return self::SUCCESS;
    }
}
