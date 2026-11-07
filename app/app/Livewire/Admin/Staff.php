<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\HandlesShopErrors;
use App\Models\User;
use App\Services\StaffAdminService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class Staff extends Component
{
    use HandlesShopErrors;

    public array $new = ['name' => '', 'email' => '', 'role' => 'MANAGER', 'password' => ''];
    public ?string $shownPassword = null;

    public function generate(): void
    {
        $this->new['password'] = Str::password(14, symbols: false);
    }

    public function create(StaffAdminService $svc): void
    {
        $pw = $this->new['password'];
        if ($this->attempt(fn () => $svc->create(Auth::user(), $this->new))) {
            $this->shownPassword = $pw; // shown once so it can be handed over
            $this->new = ['name' => '', 'email' => '', 'role' => 'MANAGER', 'password' => ''];
        }
    }

    public function toggle(int $id, StaffAdminService $svc): void
    {
        $u = User::findOrFail($id);
        $this->shownPassword = null;
        $this->attempt(fn () => $svc->setStatus(Auth::user(), $u, ! $u->isActive()));
    }

    public function resetMfa(int $id, StaffAdminService $svc): void
    {
        $this->shownPassword = null;
        $this->attempt(fn () => $svc->resetMfa(Auth::user(), User::findOrFail($id)));
    }

    public function resetPassword(int $id, StaffAdminService $svc): void
    {
        $pw = Str::password(14, symbols: false);
        if ($this->attempt(fn () => $svc->resetPassword(Auth::user(), User::findOrFail($id), $pw))) $this->shownPassword = $pw;
    }

    public function render()
    {
        return view('livewire.admin.staff', ['staff' => User::orderBy('role')->orderBy('id')->get()]);
    }
}
