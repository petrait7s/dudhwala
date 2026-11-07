<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class AuditLogView extends Component
{
    use WithPagination;

    #[Url] public string $q = '';

    public function updating($n): void { $this->resetPage(); }

    public function render()
    {
        $q = trim($this->q);
        $like = '%'.addcslashes($q, '%_\\').'%';
        $logs = AuditLog::with('actor')->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('action', 'like', $like)->orWhere('entity_type', 'like', $like)->orWhere('entity_id', 'like', $like)))
            ->orderByDesc('id')->paginate(40);
        return view('livewire.admin.audit-log', ['logs' => $logs]);
    }
}
