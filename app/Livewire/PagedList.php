<?php

namespace App\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A list screen paged by the user's choice of rows per page (UI: "rows
 * per page", 10 / 25 / 50 / 100, 10 until chosen), kept in the URL so a
 * reload or a shared link shows the same page. The 情報源 and 文書
 * screens extend it.
 */
abstract class PagedList extends Component
{
    use WithPagination;

    public const ROWS_PER_PAGE = [10, 25, 50, 100];

    #[Url]
    public int $rowsPerPage = 10;

    /**
     * A new page size starts again from the first page.
     */
    public function updatedRowsPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * The chosen size, or the default when the URL carries something else.
     */
    protected function rowsPerPage(): int
    {
        return in_array($this->rowsPerPage, self::ROWS_PER_PAGE, true) ? $this->rowsPerPage : 10;
    }
}
