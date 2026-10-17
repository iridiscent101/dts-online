// Public tracking page: the Refresh button fetches the latest status and swaps the results in place, so the page keeps
// its scroll position. Without JavaScript, or if the fetch fails, the link reloads the page.

async function refreshTrackingResults(link) {
    const results = document.querySelector('[data-tracking-results]');
    if (link.dataset.refreshing) return true;
    if (!results) return false;

    link.dataset.refreshing = 'true';
    link.setAttribute('aria-busy', 'true');
    try {
        // The current address, not the link's: the office filter may have changed since the page loaded.
        const response = await fetch(window.location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
        if (!response.ok) return false;

        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const fresh = page.querySelector('[data-tracking-results]');
        if (!fresh) return false;

        const timeline = results.querySelector('[aria-label="Document movement history"]');
        const timelineScroll = timeline?.scrollTop ?? 0;
        results.replaceChildren(...fresh.childNodes);
        const freshTimeline = results.querySelector('[aria-label="Document movement history"]');
        if (freshTimeline) freshTimeline.scrollTop = timelineScroll;

        document.dispatchEvent(new CustomEvent('dts:tracking-updated'));
        results.querySelector('[data-tracking-refresh]')?.focus({ preventScroll: true });
        const announcer = document.querySelector('[data-tracking-announcer]');
        if (announcer) {
            announcer.textContent = '';
            setTimeout(() => { announcer.textContent = 'Status updated.'; }, 50);
        }
        return true;
    } catch {
        return false;
    } finally {
        delete link.dataset.refreshing;
        link.removeAttribute('aria-busy');
    }
}

document.addEventListener('click', async (event) => {
    const link = event.target.closest('[data-tracking-refresh]');
    if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
    event.preventDefault();
    if (!(await refreshTrackingResults(link))) window.location.assign(link.href);
});
