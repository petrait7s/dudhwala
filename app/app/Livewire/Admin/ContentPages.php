<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\HandlesShopErrors;
use App\Services\AdminCatalogueService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.admin')]
class ContentPages extends Component
{
    use HandlesShopErrors;

    /** @var array<int, array<string, string>> */
    public array $pages = [];

    public function mount(SettingsService $s): void
    {
        $this->pages = array_map(fn ($p) => ['slug' => $p['slug'], 'title_bn' => $p['title_bn'], 'title_en' => $p['title_en'] ?? '', 'body_bn' => $p['body_bn'], 'body_en' => $p['body_en'] ?? ''], array_values($s->get('content.pages')));
    }

    public function add(): void
    {
        $this->pages[] = ['slug' => '', 'title_bn' => '', 'title_en' => '', 'body_bn' => '', 'body_en' => ''];
    }

    public function remove(int $i): void
    {
        unset($this->pages[$i]);
        $this->pages = array_values($this->pages);
    }

    public function save(AdminCatalogueService $svc): void
    {
        $clean = array_map(fn ($p) => ['slug' => trim($p['slug']), 'title_bn' => trim($p['title_bn']), 'title_en' => trim($p['title_en']) ?: null, 'body_bn' => trim($p['body_bn']), 'body_en' => trim($p['body_en']) ?: null], $this->pages);
        $this->attempt(fn () => $svc->saveSettings(Auth::user(), ['content.pages' => $clean]));
    }

    public function render() { return view('livewire.admin.content-pages'); }
}
