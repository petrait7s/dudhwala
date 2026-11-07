<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\HandlesShopErrors;
use App\Services\StaffAdminService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Account extends Component
{
    use HandlesShopErrors;

    public string $current = '';
    public string $new = '';

    public function save(StaffAdminService $svc): void
    {
        if ($this->attempt(fn () => $svc->changeOwnPassword(Auth::user(), $this->current, $this->new, session()->getId()))) {
            $this->current = $this->new = '';
        }
    }

    public function render() { return view('livewire.admin.account'); }
}
