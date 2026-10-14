@if ($paginator->hasPages())
    <ul class="pagination">
        @if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            @if ($paginator->onFirstPage())
                <li class="page-item disabled"><span class="page-link" aria-label="{{ __('First page') }}">&lt;&lt;</span></li>
            @else
                <li class="page-item"><a class="page-link" href="{{ $paginator->url(1) }}" aria-label="{{ __('First page') }}">&lt;&lt;</a></li>
            @endif
        @endif

        @if ($paginator->onFirstPage())
            <li class="page-item disabled"><span class="page-link" aria-label="{{ __('Previous page') }}">&lt;</span></li>
        @else
            <li class="page-item"><a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="{{ __('Previous page') }}">&lt;</a></li>
        @endif

        @if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li class="page-item disabled"><span class="page-link">{{ $element }}</span></li>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <li class="page-item active"><span class="page-link">{{ $page }}</span></li>
                        @else
                            <li class="page-item"><a class="page-link" href="{{ $url }}">{{ $page }}</a></li>
                        @endif
                    @endforeach
                @endif
            @endforeach
        @endif

        @if ($paginator->hasMorePages())
            <li class="page-item"><a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="{{ __('Next page') }}">&gt;</a></li>
        @else
            <li class="page-item disabled"><span class="page-link" aria-label="{{ __('Next page') }}">&gt;</span></li>
        @endif

        @if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
            @if ($paginator->hasMorePages())
                <li class="page-item"><a class="page-link" href="{{ $paginator->url($paginator->lastPage()) }}" aria-label="{{ __('Last page') }}">&gt;&gt;</a></li>
            @else
                <li class="page-item disabled"><span class="page-link" aria-label="{{ __('Last page') }}">&gt;&gt;</span></li>
            @endif
        @endif
    </ul>
@endif
