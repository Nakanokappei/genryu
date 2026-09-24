<?php

namespace App\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** A list screen paged by rows per page (UI "rows per page"), kept in the URL. */
abstract class PagedList extends Component
{
    use WithPagination;

    public const ROWS_PER_PAGE = [10, 25, 50, 100];

    #[Url]
    public int $rowsPerPage = 10;

    /** Back to the first page when the page size changes. */
    public function updatedRowsPerPage(): void
    {
        $this->resetPage();
    }

    /** The chosen size, or 10 when the URL carries an unknown one. */
    protected function rowsPerPage(): int
    {
        return in_array($this->rowsPerPage, self::ROWS_PER_PAGE, true) ? $this->rowsPerPage : 10;
    }
}
