<?php

namespace App\Livewire\Admin\Concerns;

use App\Exceptions\ShopException;

trait HandlesShopErrors
{
    public ?string $flash = null;
    public ?string $error = null;
    /** @var string[] field names the last error pointed at */
    public array $badFields = [];

    protected function attempt(callable $f): bool
    {
        $this->flash = $this->error = null;
        $this->badFields = [];
        try {
            $f();
            $this->flash = __('admin.saved');
            return true;
        } catch (ShopException $e) {
            $this->error = __('admin.error', ['msg' => $e->getMessage()]);
            $this->badFields = $e->context['fields'] ?? [];
            return false;
        }
    }
}
