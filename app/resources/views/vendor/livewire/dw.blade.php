@if ($paginator->hasPages())
<nav class="pager" role="navigation" aria-label="Pagination">
  <button type="button" class="btn alt" wire:click="previousPage('{{ $paginator->getPageName() }}')" @disabled($paginator->onFirstPage()) aria-label="Previous">‹</button>
  <span>{{ __('admin.page_of', ['p' => $paginator->currentPage(), 'n' => $paginator->lastPage()]) }}</span>
  <button type="button" class="btn alt" wire:click="nextPage('{{ $paginator->getPageName() }}')" @disabled(! $paginator->hasMorePages()) aria-label="Next">›</button>
</nav>
@endif
