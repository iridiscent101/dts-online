import './copy-button.js';
import './timeline-reveal.js';

const motionAllowed = () => !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Short enter/leave motion for rows added or removed by the user, so the change is visible.
function animateIn(element) {
    if (!element || !motionAllowed()) return;
    element.animate([{ opacity: 0, transform: 'translateY(-4px)' }, { opacity: 1, transform: 'none' }], { duration: 200, easing: 'ease-out' });
}

function animateOut(element) {
    if (!element) return Promise.resolve();
    if (!motionAllowed()) {
        element.remove();
        return Promise.resolve();
    }
    element.inert = true;
    element.style.overflow = 'hidden';
    const collapsed = { height: '0px', opacity: 0, marginTop: '0px', marginBottom: '0px', paddingTop: '0px', paddingBottom: '0px' };
    return element.animate([{ height: `${element.offsetHeight}px`, opacity: 1 }, collapsed], { duration: 200, easing: 'ease-in' })
        .finished.catch(() => {}).then(() => element.remove());
}

function toggleRevealed(element, show) {
    if (!motionAllowed()) {
        element.hidden = !show;
        return;
    }
    element.getAnimations().forEach((animation) => animation.cancel());
    element.hidden = false;
    const frames = [{ height: '0px', opacity: 0 }, { height: `${element.scrollHeight}px`, opacity: 1 }];
    element.style.overflow = 'hidden';
    const animation = element.animate(show ? frames : frames.reverse(), { duration: 200, easing: 'ease-out' });
    animation.finished.then(() => {
        element.style.overflow = '';
        element.hidden = !show;
    }).catch(() => {});
}

// Orchid's default viewport disables pinch zoom; allow staff to magnify text.
document.querySelector('meta[name="viewport"]')?.setAttribute('content', 'width=device-width, initial-scale=1');

function setSidebarCollapsed(collapsed) {
    document.documentElement.classList.toggle('dts-sidebar-collapsed', collapsed);
    const toggle = document.querySelector('.aside .dts-sidebar-toggle');
    if (!toggle) return;
    toggle.setAttribute('aria-expanded', String(!collapsed));
    const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    toggle.setAttribute('aria-label', label);
    toggle.title = label;
}

function initializeSidebar() {
    const sidebar = document.querySelector('.aside');
    const template = sidebar?.querySelector('#dts-sidebar-toggle-template');
    if (!sidebar || !template) return;
    document.documentElement.classList.remove('dts-sidebar-ready');
    if (!sidebar.querySelector('.dts-sidebar-toggle')) sidebar.prepend(template.content.cloneNode(true));
    sidebar.querySelectorAll('.nav-link, .profile-container > a, .header-brand').forEach((link) => {
        const label = link.textContent.trim().replace(/\s+/g, ' ');
        if (label) {
            link.setAttribute('aria-label', label);
            link.title = label;
        }
    });
    let collapsed = document.documentElement.classList.contains('dts-sidebar-collapsed');
    try { collapsed = localStorage.getItem('dts.sidebar.collapsed') === 'true'; } catch { /* Storage may be disabled. */ }
    setSidebarCollapsed(collapsed);
    requestAnimationFrame(() => document.documentElement.classList.add('dts-sidebar-ready'));
}

document.addEventListener('click', (event) => {
    if (!event.target.closest('.dts-sidebar-toggle')) return;
    const collapsed = !document.documentElement.classList.contains('dts-sidebar-collapsed');
    setSidebarCollapsed(collapsed);
    try { localStorage.setItem('dts.sidebar.collapsed', String(collapsed)); } catch { /* Keep the in-page preference. */ }
});
document.addEventListener('turbo:load', initializeSidebar);
initializeSidebar();

let notificationPoll;
function initializeNotificationBell() {
    clearInterval(notificationPoll);
    const bell = document.querySelector('[data-dts-notification]');
    if (!bell) return;

    const badge = bell.querySelector('[data-dts-notification-badge]');
    let previousCount = null;
    const renderCount = (count) => {
        badge.textContent = count > 9 ? '9+' : count > 0 ? String(count) : '';
        badge.hidden = count < 1;
        if (previousCount !== null && count > previousCount && motionAllowed()) {
            badge.animate([{ transform: 'scale(.6)' }, { transform: 'scale(1.25)' }, { transform: 'scale(1)' }], { duration: 400, easing: 'ease-out' });
        }
        previousCount = count;
    };

    renderCount(Number(bell.dataset.dtsNotificationCount));
    notificationPoll = setInterval(async () => {
        try {
            const response = await fetch(bell.dataset.dtsNotificationUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf_token"]').content,
                    'Accept': 'application/json',
                },
            });
            if (response.ok) renderCount(Number((await response.json()).total));
        } catch { /* Keep the last count until the next poll. */ }
    }, Number(bell.dataset.dtsNotificationInterval || 60) * 1000);
}
document.addEventListener('turbo:load', initializeNotificationBell);

// Row buttons show only an icon; their hidden label becomes a hover tooltip.
function labelRowActions() {
    document.querySelectorAll('.dts-row-action:not([title])').forEach((button) => {
        const label = button.textContent.trim();
        if (label) button.title = label;
    });
}
document.addEventListener('turbo:load', labelRowActions);
document.addEventListener('turbo:frame-load', labelRowActions);
labelRowActions();
initializeNotificationBell();

// Toasts from page scripts, through Orchid's toast controller (vendor/platform/partials/toast), so they look and time
// out like the server's. One for a page that is about to reload waits in sessionStorage until the next page loads.
function dtsToast(message, type = 'success') {
    const wrapper = document.querySelector('.toast-wrapper[data-controller~="toast"]');
    const controller = wrapper && window.application?.getControllerForElementAndIdentifier(wrapper, 'toast');
    if (!controller) return false;
    const text = document.createElement('span');
    text.textContent = message;
    controller.toast(text.innerHTML, type);
    return true;
}

function dtsToastAfterReload(message, type = 'success') {
    try { sessionStorage.setItem('dts-toast', JSON.stringify({ message, type })); } catch { /* The page still reloads. */ }
}

document.addEventListener('turbo:load', () => {
    let pending = null;
    try {
        pending = JSON.parse(sessionStorage.getItem('dts-toast') || 'null');
        sessionStorage.removeItem('dts-toast');
    } catch { /* Storage blocked: nothing waiting. */ }
    // The toast controller may connect a moment after the page loads.
    if (pending && !dtsToast(pending.message, pending.type)) setTimeout(() => dtsToast(pending.message, pending.type), 300);
});

function showDtsDialog({ type = 'confirm', title, message, confirmLabel = 'Continue', value = '', label = 'Your response', danger = false }) {
    let dialog = document.querySelector('#dts-app-dialog');
    if (!dialog) {
        dialog = document.createElement('dialog');
        dialog.id = 'dts-app-dialog';
        dialog.className = 'dts-app-dialog';
        dialog.setAttribute('aria-labelledby', 'dts-dialog-title');
        dialog.setAttribute('aria-describedby', 'dts-dialog-message');
        dialog.innerHTML = `
            <div class="dts-app-dialog-header"><h2 id="dts-dialog-title"></h2></div>
            <div class="dts-app-dialog-content">
                <p id="dts-dialog-message"></p>
                <div class="dts-app-dialog-input" data-dialog-input-wrap>
                    <label for="dts-dialog-input"></label>
                    <input id="dts-dialog-input" type="text" autocomplete="off">
                </div>
            </div>
            <div class="dts-app-dialog-actions">
                <button type="button" class="dts-button" data-dialog-cancel>Cancel</button>
                <button type="button" class="dts-button dts-button-primary" data-dialog-confirm>Continue</button>
            </div>`;
        dialog.addEventListener('click', (event) => {
            if (event.target.closest('[data-dialog-cancel]')) dialog.close('cancel');
            if (event.target.closest('[data-dialog-confirm]')) dialog.close('confirm');
        });
        dialog.querySelector('#dts-dialog-input').addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                dialog.close('confirm');
            }
        });
        document.body.append(dialog);
    }

    if (dialog.open) return Promise.resolve(type === 'prompt' ? null : false);

    dialog.dataset.type = type;
    dialog.dataset.danger = String(danger);
    dialog.returnValue = '';
    dialog.querySelector('#dts-dialog-title').textContent = title;
    dialog.querySelector('#dts-dialog-message').textContent = message;
    const inputWrap = dialog.querySelector('[data-dialog-input-wrap]');
    const input = dialog.querySelector('#dts-dialog-input');
    inputWrap.hidden = type !== 'prompt';
    inputWrap.querySelector('label').textContent = label;
    input.value = value;
    const cancel = dialog.querySelector('[data-dialog-cancel]');
    cancel.hidden = type === 'alert';
    const confirm = dialog.querySelector('[data-dialog-confirm]');
    confirm.textContent = type === 'alert' ? 'OK' : confirmLabel;
    confirm.classList.toggle('dts-button-danger', danger);
    confirm.classList.toggle('dts-button-primary', !danger);

    return new Promise((resolve) => {
        dialog.addEventListener('close', () => {
            if (type === 'prompt') resolve(dialog.returnValue === 'confirm' ? input.value : null);
            else resolve(dialog.returnValue === 'confirm');
        }, { once: true });
        dialog.showModal();
        (type === 'prompt' ? input : type === 'alert' ? confirm : cancel).focus();
    });
}

const dtsDialog = {
    confirm: (message, options = {}) => showDtsDialog({ type: 'confirm', title: 'Confirm action', message, ...options }),
    alert: (message, options = {}) => showDtsDialog({ type: 'alert', title: 'Notice', message, ...options }),
    prompt: (message, options = {}) => showDtsDialog({ type: 'prompt', title: 'Enter information', message, ...options }),
};
window.dtsDialog = dtsDialog;

let workspaceRequest;
let workspaceHistoryRequest;
let workspaceOrigin;
let workspaceDirty = false;
let workspaceSaved = false;

const changedDocumentKey = 'dts:changed-document';

function replayClass(element, className, duration) {
    element.classList.remove(className);
    void element.offsetWidth;
    element.classList.add(className);
    setTimeout(() => element.classList.remove(className), duration);
}

function statusBadges(scopes) {
    const seen = {};
    return scopes.flatMap((scope) => [...scope.querySelectorAll('.dts-record-status:not([data-workflow-source-status])')]).map((badge) => {
        const owner = badge.closest('[data-workspace-office], [data-workspace-route]');
        const office = owner?.dataset.workspaceOffice ?? owner?.dataset.workspaceRoute ?? badge.previousElementSibling?.textContent.trim() ?? '';
        seen[office] = (seen[office] ?? 0) + 1;
        return { badge, key: `${office}#${seen[office]}`, status: badge.textContent.trim() };
    });
}

function statusSnapshot(scopes) {
    return Object.fromEntries(statusBadges(scopes).map(({ key, status }) => [key, status]));
}

function markChangedStatuses(scopes, before) {
    if (!before) return;
    statusBadges(scopes).forEach(({ badge, key, status }) => {
        if (before[key] !== status) replayClass(badge, 'dts-status-changed', 2400);
    });
}

function documentRows(workspaceUrl) {
    return [...new Set([...document.querySelectorAll('[data-workspace-url]')]
        .filter((link) => link.dataset.workspaceUrl === workspaceUrl && !link.closest('[data-document-workspace]'))
        .map((link) => link.closest('tr'))
        .filter(Boolean))];
}

function rememberChangedDocument(workspaceUrl) {
    try {
        sessionStorage.setItem(changedDocumentKey, JSON.stringify({
            url: workspaceUrl,
            statuses: statusSnapshot(documentRows(workspaceUrl)),
            path: location.pathname + location.search,
            scrollY: window.scrollY,
        }));
    } catch { /* Highlighting is optional when session storage is unavailable. */ }
}

function highlightChangedDocument() {
    let changed;
    try {
        changed = JSON.parse(sessionStorage.getItem(changedDocumentKey));
        sessionStorage.removeItem(changedDocumentKey);
    } catch { return; }
    if (!changed?.url) return;

    if (changed.path === location.pathname + location.search && Number.isFinite(changed.scrollY)) window.scrollTo(0, changed.scrollY);
    const rows = documentRows(changed.url);
    rows.forEach((row) => replayClass(row, 'dts-row-changed', 3000));
    markChangedStatuses(rows, changed.statuses);
    rows[0]?.scrollIntoView({ block: 'nearest' });
}

document.addEventListener('turbo:load', highlightChangedDocument);
highlightChangedDocument();

// The server rejects a save made from an out-of-date form (someone else saved the document in between) with a
// validation error on one of these fingerprints.
// Bootstrap "printer" icon for print buttons that scripts create (icon only; the hover text names the action).
const PRINTER_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><path d="M2.5 8a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1"/><path d="M5 1a2 2 0 0 0-2 2v2H2a2 2 0 0 0-2 2v3a2 2 0 0 0 2 2h1v1a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-1h1a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-1V3a2 2 0 0 0-2-2zM4 3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2H4zm1 5a2 2 0 0 0-2 2v1H2a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-1v-1a2 2 0 0 0-2-2zm7 2v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-3a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1"/></svg>';

const STALE_EDIT_FIELDS = ['document.details_fingerprint', 'document.route_fingerprint'];

class StaleEditError extends Error {}

function showStaleEdit(errors, button) {
    const message = document.createElement('p');
    message.textContent = 'This document was changed by someone else while you were editing, so your changes were not saved. '
        + 'Refresh the document to see the latest version, then make your changes again.';
    const refresh = document.createElement('button');
    refresh.type = 'button';
    refresh.className = 'dts-button dts-button-primary';
    refresh.dataset.workspaceStaleRefresh = '';
    refresh.textContent = 'Refresh document';
    errors.replaceChildren(message, refresh);
    errors.classList.add('is-stale-edit');
    button.dataset.staleEdit = 'true';
}

async function postWorkspace(url, data, errors, button) {
    if (button.disabled) return null;
    button.disabled = true;
    const buttonSpinner = button.querySelector('[data-workspace-button-spinner]');
    if (buttonSpinner) buttonSpinner.hidden = false;
    const dialog = button.closest('[data-document-workspace]');
    if (dialog) dialog.dataset.saving = 'true';
    errors.hidden = true;
    errors.textContent = '';
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf_token"]').content,
                'Accept': 'application/json',
            },
            body: data,
        });
        const result = await response.json();
        if (!response.ok) {
            if (STALE_EDIT_FIELDS.some((field) => result.errors?.[field])) throw new StaleEditError();
            throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message || 'Could not save. Please try again.');
        }
        workspaceSaved = true;
        return result;
    } catch (error) {
        if (error instanceof StaleEditError) showStaleEdit(errors, button);
        else errors.textContent = error.message || 'Could not save. Please try again.';
        errors.hidden = false;
        errors.tabIndex = -1;
        errors.focus();
        return null;
    } finally {
        // After a stale-edit conflict, saving stays blocked until the document is refreshed: nothing is retried or
        // sent again over the newer changes.
        button.disabled = button.dataset.staleEdit === 'true';
        if (buttonSpinner) buttonSpinner.hidden = true;
        if (dialog) delete dialog.dataset.saving;
    }
}

async function refreshWorkspace(dialog, preserveRouting = false) {
    workspaceHistoryRequest?.abort();
    const shell = dialog.querySelector('[data-workspace-shell]');
    const activeTab = shell.querySelector('[data-workspace-tab][aria-selected="true"]')?.dataset.workspaceTab;
    const selectedRoute = shell.querySelector('[data-workspace-route][aria-pressed="true"]')?.dataset.workspaceRoute;
    const historyMode = shell.querySelector('[data-workspace-history-mode][aria-pressed="true"]')?.dataset.workspaceHistoryMode;
    const routing = shell.querySelector('[data-workspace-routing]');
    const routingDraft = preserveRouting && routing ? {
        source: routing.querySelector('[data-workflow-source-office]').value,
        action: routing.querySelector('[data-workflow-action]').value,
        remarks: routing.querySelector('[data-shared-remarks] textarea').value,
        releasedTo: routing.querySelector('[data-release-recipient] input').value,
        destinations: [...routing.querySelectorAll('[data-destination-row]')].map((row) => ({
            office: row.querySelector('[data-destination-select]').value,
            remarks: row.querySelector('[data-destination-remarks]').value,
        })),
        dirty: routing.dataset.dirty === 'true',
    } : null;
    const response = await fetch(shell.dataset.workspaceUrl, { headers: { 'Accept': 'text/html' } });
    if (!response.ok) throw new Error('Saved, but could not refresh the workspace. Reopen this document to see the update.');
    const content = dialog.querySelector('[data-workspace-content]');
    const statusesBefore = statusSnapshot([content]);
    content.innerHTML = await response.text();
    markChangedStatuses([content], statusesBefore);
    if (activeTab) dialog.querySelector(`[data-workspace-tab="${activeTab}"]`)?.click();
    if (selectedRoute) {
        const route = [...dialog.querySelectorAll('[data-workspace-route]')].find((item) => item.dataset.workspaceRoute === selectedRoute);
        route?.click();
        if (route && historyMode === 'all') dialog.querySelector('[data-workspace-history-mode="all"]')?.click();
    }
    if (routingDraft) {
        const updated = dialog.querySelector('[data-workspace-routing]');
        if (updated) {
            const workflow = updated.querySelector('dts-workflow-routing');
            const source = updated.querySelector('[data-workflow-source-office]');
            source.value = routingDraft.source;
            source.dispatchEvent(new Event('change', { bubbles: true }));
            const action = updated.querySelector('[data-workflow-action]');
            action.value = routingDraft.action;
            action.dispatchEvent(new Event('change', { bubbles: true }));
            while (workflow.destinationRows().length < routingDraft.destinations.length) workflow.addDestination();
            workflow.destinationRows().forEach((row, index) => {
                row.querySelector('[data-destination-select]').value = routingDraft.destinations[index]?.office || '';
                row.querySelector('[data-destination-remarks]').value = routingDraft.destinations[index]?.remarks || '';
            });
            updated.querySelector('[data-shared-remarks] textarea').value = routingDraft.remarks;
            updated.querySelector('[data-release-recipient] input').value = routingDraft.releasedTo;
            updated.dataset.dirty = String(routingDraft.dirty);
        }
    }
    workspaceDirty = Boolean(routingDraft?.dirty);
}

function filterWorkspaceHistory(dialog, office, mode) {
    const selectedRoute = [...dialog.querySelectorAll('[data-workspace-route]')].find((route) => route.dataset.workspaceRoute === office);
    dialog.querySelectorAll('[data-workspace-route]').forEach((route) => route.setAttribute('aria-pressed', String(route === selectedRoute)));
    dialog.querySelectorAll('[data-workspace-history-mode]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.workspaceHistoryMode === mode));
        if (button.dataset.workspaceHistoryMode === 'selected') button.disabled = !office;
    });
    const label = dialog.querySelector('[data-workspace-history-label]');
    const isRestricted = mode === 'selected' && selectedRoute?.dataset.workspaceRouteHistoryAccess === 'false';
    label.hidden = mode !== 'selected';
    if (mode === 'selected') label.textContent = isRestricted ? `History unavailable for ${office}` : `Showing activity for ${office}`;
    if (isRestricted) {
        workspaceHistoryRequest?.abort();
        const list = dialog.querySelector('[data-workspace-history-list]');
        list.replaceChildren();
        const notice = document.createElement('p');
        notice.className = 'dts-workspace-empty';
        notice.textContent = "You don't have permission to view this office's history or remarks.";
        list.append(notice);
        return;
    }
    loadWorkspaceHistory(dialog, 1);
}

async function loadWorkspaceHistory(dialog, page, append = false) {
    workspaceHistoryRequest?.abort();
    const request = new AbortController();
    workspaceHistoryRequest = request;
    const list = dialog.querySelector('[data-workspace-history-list]');
    const loading = dialog.querySelector('[data-workspace-history-loading]');
    const error = dialog.querySelector('[data-workspace-history-error]');
    const more = list.querySelector('[data-workspace-history-more]');
    const selected = dialog.querySelector('[data-workspace-history-mode="selected"][aria-pressed="true"]');
    const office = selected ? dialog.querySelector('[data-workspace-route][aria-pressed="true"]')?.dataset.workspaceRoute : null;
    const url = new URL(dialog.querySelector('[data-workspace-shell]').dataset.workspaceUrl, location.href);
    url.searchParams.set('history_page', String(page));
    if (office) url.searchParams.set('office', office);
    if (!append) list.replaceChildren();
    if (more) {
        more.disabled = true;
        setSubmitBusy(more, true);
    }
    // While "Load older activity" shows its own spinner, the panel-wide loading line is not needed.
    loading.hidden = append;
    error.hidden = true;
    list.setAttribute('aria-busy', 'true');

    try {
        const response = await fetch(url, { headers: { 'Accept': 'text/html' }, signal: request.signal });
        if (!response.ok) throw new Error('Could not load activity.');
        const html = await response.text();
        if (request.signal.aborted || !list.isConnected) return;
        if (append) {
            const previousCount = list.querySelectorAll('[data-workspace-history-event]').length;
            const keepFocus = more?.contains(document.activeElement);
            list.querySelector('[data-workspace-history-footer]')?.remove();
            list.insertAdjacentHTML('beforeend', html);
            // An older page that starts on the same day as the last shown entry repeats that day's heading.
            let lastDay = null;
            list.querySelectorAll('[data-history-day]').forEach((heading) => {
                if (heading.dataset.historyDay === lastDay) heading.remove();
                else lastDay = heading.dataset.historyDay;
            });
            // The button is gone, so move focus to the first newly loaded entry instead of the page top.
            if (keepFocus) list.querySelectorAll('[data-workspace-history-event]')[previousCount]?.focus();
        } else list.innerHTML = html;
    } catch {
        if (!request.signal.aborted && error.isConnected) error.hidden = false;
    } finally {
        if (workspaceHistoryRequest === request) {
            if (loading.isConnected) loading.hidden = true;
            if (list.isConnected) list.removeAttribute('aria-busy');
            if (more?.isConnected) {
                more.disabled = false;
                setSubmitBusy(more, false);
            }
        }
    }
}

document.addEventListener('click', async (event) => {
    const row = event.target.closest('#document-registry tbody tr');
    const link = event.target.closest('[data-workspace-url]:not([data-workspace-shell])')
        ?? (!event.target.closest('a, button, input, select, textarea, label, summary') && row?.querySelector('.dts-record-link [data-workspace-url]'));
    if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const dialog = document.querySelector('[data-document-workspace]');
    if (!dialog) return;
    event.preventDefault();

    workspaceRequest?.abort();
    workspaceHistoryRequest?.abort();
    workspaceRequest = new AbortController();
    workspaceOrigin = link;
    workspaceDirty = false;
    const content = dialog.querySelector('[data-workspace-content]');
    content.innerHTML = '<div class="dts-workspace-loading" role="status"><span class="dts-registry-spinner" aria-hidden="true"></span> Loading document…</div>';
    if (!dialog.open) dialog.showModal();

    try {
        const response = await fetch(link.dataset.workspaceUrl, {
            headers: { 'Accept': 'text/html' },
            signal: workspaceRequest.signal,
        });
        if (!response.ok) throw new Error('Document could not be loaded');
        content.innerHTML = await response.text();
        if (link.matches('[data-workspace-edit-open]')) {
            content.querySelector('[data-workspace-edit]')?.click();
        } else if (link.dataset.workspaceOffice) {
            content.querySelector('[data-workspace-tab="routing"]')?.click();
            [...content.querySelectorAll('[data-workspace-route]')]
                .find((route) => route.dataset.workspaceRoute === link.dataset.workspaceOffice)
                ?.click();
            // Queue action icons: the chosen action is preselected, so staff only add offices or remarks and save.
            if (link.dataset.workspaceAction) {
                content.querySelector(`[data-action-choice="${link.dataset.workspaceAction}"]`)?.click();
                const next = link.dataset.workspaceAction === 'forwarded'
                    ? content.querySelector('[data-destination-select]:not(:disabled)')
                    : content.querySelector('[data-workspace-routing-save]');
                next?.scrollIntoView({ block: 'center' });
                next?.focus();
            }
        } else {
            content.querySelector('[data-workspace-tab]')?.focus();
        }
        if (link.matches('[data-workspace-auto-open]') && link.dataset.workspaceReturnUrl) {
            history.replaceState({}, '', link.dataset.workspaceReturnUrl);
        }
    } catch (error) {
        if (error.name === 'AbortError') return;
        content.innerHTML = '<div class="dts-workspace-loading" role="alert"><p>Could not load this document.</p><button type="button" class="dts-button" data-workspace-retry>Retry</button></div>';
        content.querySelector('[data-workspace-retry]').addEventListener('click', () => link.click(), { once: true });
    }
});

// Locked document details (already processed or finished): unlocking asks why, frees the fields and keeps the
// reason in a hidden field. The server refuses changed details without a reason and keeps it in the history.
async function unlockDocumentDetails(form) {
    const reason = (await dtsDialog.prompt(
        'These details are locked because the document has already been processed. Why do they need a correction? The reason is saved in the history with your changes.',
        { title: 'Unlock document details', label: 'Reason for the correction', confirmLabel: 'Unlock details' },
    ))?.trim();
    if (!reason) return false;
    form.querySelectorAll('[data-locked-fields] [readonly]').forEach((field) => {
        field.readOnly = false;
        field.dataset.relock = '';
    });
    form.querySelectorAll('[data-locked-select]').forEach((field) => { field.disabled = false; });
    form.querySelector('[data-correction-reason]').value = reason;
    const note = form.querySelector('[data-correction-note]');
    note.textContent = `Unlocked for correction. Reason: ${reason}`;
    note.hidden = false;
    const lock = form.querySelector('[data-details-lock]');
    if (lock) lock.hidden = true;
    form.querySelector('[data-locked-fields] [name="document[title]"]')?.focus();
    return true;
}

function relockDocumentDetails(form) {
    form.querySelectorAll('[data-relock]').forEach((field) => {
        field.readOnly = true;
        delete field.dataset.relock;
    });
    form.querySelectorAll('[data-locked-select]').forEach((field) => { field.disabled = true; });
    const reason = form.querySelector('[data-correction-reason]');
    if (reason) reason.value = '';
    const note = form.querySelector('[data-correction-note]');
    if (note) note.hidden = true;
    const lock = form.querySelector('[data-details-lock]');
    if (lock) lock.hidden = false;
}

// Changing the assigned offices needs a reason (DocumentEditScreen::updateRoutes). Reuses the reason already given to
// unlock the details; otherwise asks for one and keeps it in the form's correction-reason field. False if cancelled.
async function ensureRouteChangeReason(form) {
    const routes = form.querySelector('dts-route-editor');
    if (!routes?.routesChanged()) return true;
    let field = form.querySelector('[data-correction-reason]');
    if (field?.value.trim()) return true;
    const reason = (await dtsDialog.prompt(
        'Why are the assigned offices changing? The reason is saved in the history with the change.',
        { title: 'Change assigned offices', label: 'Reason for the change', confirmLabel: 'Save changes' },
    ))?.trim();
    if (!reason) return false;
    if (!field) {
        field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'document[correction_reason]';
        field.dataset.correctionReason = '';
        routes.after(field);
    }
    field.value = reason.slice(0, 500);
    return true;
}

// The full edit page submits normally: hold the submit until the reason for an office change is given.
document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!form.querySelector?.('dts-route-editor') || form.dataset.routeReasonChecked) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (!(await ensureRouteChangeReason(form))) return;
    form.dataset.routeReasonChecked = 'true';
    form.requestSubmit(event.submitter);
    delete form.dataset.routeReasonChecked;
}, true);

// The full edit page has no panel around it.
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-unlock-details]');
    if (!button || button.closest('[data-document-workspace]')) return;
    await unlockDocumentDetails(button.closest('form') ?? document);
});

document.addEventListener('click', async (event) => {
    const dialog = event.target.closest('[data-document-workspace]');
    if (!dialog) return;
    if (event.target.closest('[data-unlock-details]')) {
        await unlockDocumentDetails(dialog.querySelector('[data-workspace-editor]'));
        return;
    }
    if (event.target.closest('[data-workspace-close]')) {
        if (dialog.dataset.saving) return;
        if (workspaceDirty) {
            dtsDialog.confirm('Your changes to this document have not been saved. Discard them?', { title: 'Discard changes', confirmLabel: 'Discard', danger: true }).then((confirmed) => {
                if (confirmed) { workspaceDirty = false; dialog.close(); }
            });
        } else dialog.close();
    }

    const editTrigger = event.target.closest('[data-workspace-edit]');
    if (editTrigger) {
        const editor = dialog.querySelector('[data-workspace-editor]');
        // A finished record has nothing to edit but its details, so the administrator gives the reason first.
        if (editTrigger.hasAttribute('data-workspace-unlock') && !(await unlockDocumentDetails(editor))) return;
        dialog.querySelector('[data-workspace-details-view]').hidden = true;
        editor.hidden = false;
        editTrigger.hidden = true;
        // The editor starts with hidden fields (fingerprint, reference), so focus the first field that can be typed in.
        editor.querySelector('[data-locked-fields] [data-relock], .dts-workspace-fields:not([data-locked-fields]) [name="document[title]"], [data-unlock-details]')?.focus();
    }

    if (event.target.closest('[data-workspace-edit-cancel]')) {
        const editor = dialog.querySelector('[data-workspace-editor]');
        if (editor.querySelector('dts-route-editor')) {
            try {
                await refreshWorkspace(dialog);
                dialog.querySelector('[data-workspace-edit]')?.focus();
            } catch (error) {
                await dtsDialog.alert(error.message, { title: 'Document not refreshed' });
            }
            return;
        }
        editor.querySelectorAll('input, textarea, select').forEach((field) => {
            if (field instanceof HTMLSelectElement) field.selectedIndex = [...field.options].findIndex((option) => option.defaultSelected);
            else field.value = field.defaultValue;
        });
        relockDocumentDetails(editor);
        editor.hidden = true;
        dialog.querySelector('[data-workspace-details-view]').hidden = false;
        const editButton = dialog.querySelector('[data-workspace-edit]');
        editButton.hidden = false;
        editButton.focus();
        workspaceDirty = false;
    }

    const route = event.target.closest('[data-workspace-route]');
    if (route) {
        filterWorkspaceHistory(dialog, route.dataset.workspaceRoute, 'selected');
        const routing = dialog.querySelector('[data-workspace-routing]');
        const source = routing?.querySelector('[data-workflow-source-office]');
        const isAssignedOffice = source && [...source.options].some((option) => option.value === route.dataset.workspaceRoute);
        const actions = routing?.querySelector('dts-workflow-routing')?.actionOptionsByOffice?.[route.dataset.workspaceRoute] || {};
        const canAct = isAssignedOffice && Object.keys(actions).length > 0;
        if (routing) {
            const controls = routing.querySelector('[data-workspace-routing-controls]');
            controls.hidden = !canAct;
            controls.querySelectorAll('input, select, textarea, button').forEach((field) => { field.disabled = !canAct; });
            const notice = routing.querySelector('[data-workspace-route-readonly]');
            notice.hidden = canAct;
            if (!canAct) notice.textContent = route.hasAttribute('data-workspace-route-waiting')
                ? 'This office receives the document/s once the Records Unit receives them.'
                : (isAssignedOffice
                    ? 'No routing action is available for this office.'
                    : "You don't have permission to edit this office's routing information.");
        }
        if (canAct) {
            source.value = route.dataset.workspaceRoute;
            const wasDirty = workspaceDirty;
            const routingWasDirty = routing.dataset.dirty;
            source.dispatchEvent(new Event('change', { bubbles: true }));
            workspaceDirty = wasDirty;
            routing.dataset.dirty = routingWasDirty || 'false';
        }
    }

    const historyMode = event.target.closest('[data-workspace-history-mode]');
    if (historyMode) {
        const office = dialog.querySelector('[data-workspace-route][aria-pressed="true"]')?.dataset.workspaceRoute;
        filterWorkspaceHistory(dialog, office, historyMode.dataset.workspaceHistoryMode);
    }

    // "Take action" on the Details tab: open the routing tab and start at its first choice.
    if (event.target.closest('[data-workspace-take-action]')) {
        dialog.querySelector('[data-workspace-tab="routing"]')?.click();
        const routing = dialog.querySelector('[data-workspace-routing]');
        const firstChoice = [...(routing?.querySelectorAll('[data-workflow-source-office], [data-action-choice]') ?? [])]
            .find((field) => !field.disabled && field.type !== 'hidden' && field.offsetParent !== null);
        firstChoice?.scrollIntoView({ block: 'center' });
        firstChoice?.focus();
        return;
    }

    const moreHistory = event.target.closest('[data-workspace-history-more]');
    if (moreHistory) loadWorkspaceHistory(dialog, Number(moreHistory.dataset.historyPage), true);

    const tab = event.target.closest('[data-workspace-tab]');
    if (!tab) return;
    dialog.querySelectorAll('[data-workspace-tab]').forEach((button) => {
        const selected = button === tab;
        button.setAttribute('aria-selected', String(selected));
        button.tabIndex = selected ? 0 : -1;
    });
    dialog.querySelectorAll('[data-workspace-panel]').forEach((panel) => {
        panel.hidden = panel.dataset.workspacePanel !== tab.dataset.workspaceTab;
    });
});

document.addEventListener('input', (event) => {
    if (event.target.closest('[data-document-workspace] [data-workspace-editor], [data-document-workspace] [data-workspace-routing]')) workspaceDirty = true;
    const routing = event.target.closest('[data-document-workspace] [data-workspace-routing]');
    if (routing) routing.dataset.dirty = 'true';
});

document.addEventListener('change', (event) => {
    const routing = event.target.closest('[data-document-workspace] [data-workspace-routing]');
    if (routing) { routing.dataset.dirty = 'true'; workspaceDirty = true; }
});

// "Refresh document" after a stale-edit conflict: reload the latest version (new fingerprints, Save enabled again).
document.addEventListener('click', async (event) => {
    const refresh = event.target.closest('[data-workspace-stale-refresh]');
    if (!refresh || refresh.disabled) return;
    const dialog = refresh.closest('[data-document-workspace]');
    refresh.disabled = true;
    setSubmitBusy(refresh, true);
    try {
        await refreshWorkspace(dialog);
        workspaceDirty = false;
        dialog.querySelector('[data-workspace-edit]')?.focus();
    } catch (error) {
        refresh.disabled = false;
        setSubmitBusy(refresh, false);
        await dtsDialog.alert(error.message, { title: 'Document not refreshed' });
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-workspace-details-save]');
    if (!button) return;
    const editor = button.closest('[data-workspace-editor]');
    const invalid = [...editor.querySelectorAll('input, textarea, select')].find((field) => !field.checkValidity());
    if (invalid) { invalid.reportValidity(); invalid.focus(); return; }
    if (!(await ensureRouteChangeReason(editor))) return;
    const fields = [...editor.querySelectorAll('input, textarea, select')];

    const data = new FormData();
    fields.forEach((field) => data.append(field.name, field.value));
    if (!editor.querySelector('[name="document[destinations][]"]')) data.append('details_only', '1');
    const errors = editor.querySelector('[data-workspace-form-errors]');
    const result = await postWorkspace(editor.dataset.saveUrl, data, errors, button);
    if (!result) return;
    dtsToast(result.message || 'Document details were updated.');
    try { await refreshWorkspace(button.closest('[data-document-workspace]'), true); }
    catch (error) { errors.textContent = error.message; errors.hidden = false; }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-workspace-routing-save]');
    if (!button || button.disabled || button.dataset.confirming) return;
    const dialog = button.closest('[data-document-workspace]');
    const routing = button.closest('[data-workspace-routing]');
    const errors = routing.querySelector('[data-workspace-form-errors]');
    const editor = dialog.querySelector('[data-workspace-editor]');
    const hasUnsavedDetails = editor && !editor.hidden && [...editor.querySelectorAll('input, textarea, select')].some((field) =>
        field instanceof HTMLSelectElement
            ? field.value !== [...field.options].find((option) => option.defaultSelected)?.value
            : field.value !== field.defaultValue);
    if (hasUnsavedDetails) {
        errors.textContent = 'Save or cancel your detail edits before routing this document.';
        errors.hidden = false;
        dialog.querySelector('[data-workspace-tab="details"]')?.click();
        editor.querySelector('[name="document[title]"]')?.focus();
        return;
    }

    const fields = [...routing.querySelectorAll('input, select, textarea')].filter((field) => !field.disabled);
    const invalid = fields.find((field) => !field.checkValidity());
    if (invalid) { invalid.reportValidity(); invalid.focus(); return; }
    button.dataset.confirming = 'true';
    const receiving = Boolean(routing.querySelector('dts-workflow-routing[data-receive-only]'));
    // Receiving needs no confirmation; other routing updates still ask before saving.
    const confirmed = receiving || await dtsDialog.confirm('Save this routing update?', { title: 'Save routing update', confirmLabel: 'Save update' });
    delete button.dataset.confirming;
    if (!confirmed) return;

    const data = new FormData();
    fields.forEach((field) => data.append(field.name, field.value));
    const timelineLoading = dialog.querySelector('[data-workspace-history-loading]');
    const historyList = dialog.querySelector('[data-workspace-history-list]');
    timelineLoading.hidden = false;
    historyList.setAttribute('aria-busy', 'true');
    const result = await postWorkspace(routing.dataset.saveUrl, data, errors, button);
    if (!result) {
        timelineLoading.hidden = true;
        historyList.removeAttribute('aria-busy');
        return;
    }
    if (!result.visible) {
        workspaceDirty = false;
        workspaceSaved = false;
        dialog.close();
        dtsToastAfterReload(result.message || 'Routing update saved.');
        window.location.reload();
        return;
    }
    dtsToast(result.message || 'Routing update saved.');
    try { await refreshWorkspace(dialog); }
    catch (error) { errors.textContent = error.message; errors.hidden = false; }
    finally {
        if (timelineLoading.isConnected) timelineLoading.hidden = true;
        if (historyList.isConnected) historyList.removeAttribute('aria-busy');
    }
});

document.addEventListener('cancel', (event) => {
    if (!event.target.matches('[data-document-workspace]')) return;
    if (event.target.dataset.saving) { event.preventDefault(); return; }
    if (!workspaceDirty) return;
    event.preventDefault();
    dtsDialog.confirm('Your changes to this document have not been saved. Discard them?', { title: 'Discard changes', confirmLabel: 'Discard', danger: true }).then((confirmed) => {
        if (confirmed) { workspaceDirty = false; event.target.close(); }
    });
}, true);

document.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && event.target.matches('[data-document-workspace] input')) event.preventDefault();
    const tab = event.target.closest('[data-document-workspace] [data-workspace-tab]');
    if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const tabs = [...tab.parentElement.querySelectorAll('[data-workspace-tab]')];
    const next = event.key === 'Home' ? tabs[0] : event.key === 'End' ? tabs.at(-1) : tabs[(tabs.indexOf(tab) + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length];
    next.focus();
    next.click();
});

document.addEventListener('close', (event) => {
    if (!event.target.matches('[data-document-workspace]')) return;
    workspaceRequest?.abort();
    workspaceHistoryRequest?.abort();
    workspaceDirty = false;
    const savedWorkspaceUrl = workspaceSaved ? workspaceOrigin?.dataset.workspaceUrl : null;
    workspaceSaved = false;
    if (savedWorkspaceUrl) rememberChangedDocument(savedWorkspaceUrl);
    if (workspaceOrigin?.matches('[data-workspace-auto-open]') && workspaceOrigin.dataset.workspaceReturnUrl) {
        window.location.assign(workspaceOrigin.dataset.workspaceReturnUrl);
        return;
    }
    // Reload so the list behind the dialog shows the saved change; the row is highlighted after load.
    if (savedWorkspaceUrl) {
        window.location.reload();
        return;
    }
    if (workspaceOrigin?.isConnected) workspaceOrigin.focus();
}, true);

function openInitialWorkspace() {
    const trigger = document.querySelector('[data-workspace-auto-open]');
    const dialog = document.querySelector('[data-document-workspace]');
    if (trigger && !dialog?.open) trigger.click();
}

document.addEventListener('turbo:load', openInitialWorkspace);
openInitialWorkspace();

document.addEventListener('turbo:before-cache', () => {
    const dialog = document.querySelector('[data-document-workspace]');
    if (dialog?.open) dialog.close();
});

class SearchableChoices extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.events = new AbortController();
        this.input = this.querySelector('[data-searchable]');
        this.panel = this.querySelector('[data-suggestions]');
        // Focusable (but not a Tab stop) so clicking the list's scrollbar keeps focus inside and the list open.
        this.panel.tabIndex = -1;
        const options = { signal: this.events.signal };

        this.input.addEventListener('focus', () => this.show(true), options);
        this.input.addEventListener('click', () => this.show(true), options);
        this.input.addEventListener('input', () => this.show(), options);
        this.input.addEventListener('keydown', (event) => {
            const choices = [...this.panel.querySelectorAll('[data-choice]')];
            if (event.key === 'Escape') {
                // Escape closes only the open list; without this it also closed the dialog around the field
                // (the document window then asked to discard the changes).
                if (!this.panel.hidden) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                this.close();
                return;
            }
            if (this.panel.hidden || choices.length === 0) return;

            const active = choices.findIndex((choice) => choice.getAttribute('aria-selected') === 'true');
            if (event.key === 'Enter') {
                event.preventDefault();
                this.choose(choices[Math.max(active, 0)]);
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const next = (active + (event.key === 'ArrowDown' ? 1 : -1) + choices.length) % choices.length;
                choices[active]?.setAttribute('aria-selected', 'false');
                choices[next].setAttribute('aria-selected', 'true');
                this.input.setAttribute('aria-activedescendant', choices[next].id);
                choices[next].scrollIntoView({ block: 'nearest' });
            }
        }, options);
        this.addEventListener('click', (event) => {
            const choice = event.target.closest('[data-choice]');
            if (choice) this.choose(choice);
        }, options);
        this.addEventListener('focusout', (event) => {
            if (!this.contains(event.relatedTarget)) this.close();
        }, options);
        document.addEventListener('pointerdown', (event) => {
            if (!this.contains(event.target)) this.close();
        }, options);
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
    }

    show(all = false) {
        // A locked (read-only) field offers no choices until it is unlocked.
        if (this.input.readOnly) return;
        const opening = this.panel.hidden;
        this.panel.id = `${this.input.id}-choices`;
        this.input.setAttribute('aria-controls', this.panel.id);
        const values = [...document.getElementById(this.input.dataset.options).options]
            .map((option) => option.value);
        const query = all || values.includes(this.input.value) ? '' : this.input.value.trim().toLocaleLowerCase();
        const matches = values.filter((value) => value.toLocaleLowerCase().includes(query));
        const active = Math.max(matches.indexOf(this.input.value), 0);

        this.panel.replaceChildren(...matches.map((value, index) => {
            const choice = document.createElement('button');
            choice.type = 'button';
            choice.id = `${this.panel.id}-${index}`;
            choice.dataset.choice = value;
            choice.setAttribute('role', 'option');
            choice.setAttribute('aria-selected', String(index === active));
            choice.textContent = value;
            return choice;
        }));
        if (matches.length === 0) {
            const message = document.createElement('p');
            message.textContent = 'No matching choices';
            this.panel.append(message);
        }
        this.panel.hidden = false;
        // Near the bottom of a scrolling panel the list would be cut off, so scroll it into view as it opens.
        if (opening) this.panel.scrollIntoView({ block: 'nearest' });
        this.input.setAttribute('aria-expanded', 'true');
        if (matches.length > 0) this.input.setAttribute('aria-activedescendant', `${this.panel.id}-${active}`);
        else this.input.removeAttribute('aria-activedescendant');
    }

    choose(choice) {
        this.input.value = choice.dataset.choice;
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
        this.input.focus();
        this.close();
    }

    close() {
        this.panel.hidden = true;
        this.input.setAttribute('aria-expanded', 'false');
        this.input.removeAttribute('aria-activedescendant');
    }
}

if (!customElements.get('dts-searchable')) customElements.define('dts-searchable', SearchableChoices);

class DocumentRegistration extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.events = new AbortController();
        this.files = [];
        this.fileTransfer = new DataTransfer();
        this.draftKey = `dts.registration.draft.${this.dataset.draftUser}`;
        const options = { signal: this.events.signal };
        this.syncRegistrationRoutes();
        if (this.dataset.hasOldInput === 'true') this.saveDraft();
        else this.restoreDraft();
        this.addEventListener('click', (event) => {
            if (event.target.closest('[data-draft-discard]')) this.clearDraft();
            if (event.target.closest('[data-registration-add-route]')) this.addRegistrationRoute();
            const removeRoute = event.target.closest('[data-registration-remove-route]');
            if (removeRoute) this.removeRegistrationRoute(removeRoute);
            const remove = event.target.closest('[data-remove-file]');
            if (remove) {
                const index = Number(remove.dataset.removeFile);
                URL.revokeObjectURL(this.files[index].url);
                this.files.splice(index, 1);
                this.syncFileInput();
                this.renderFiles();
                this.saveDraft();
                this.querySelector('#document-files').focus();
            }
        }, options);
        this.addEventListener('change', (event) => {
            if (event.target.id === 'document-files') this.addFiles(event.target);
            if (event.target.matches('[data-field], [data-registration-office], [data-registration-remarks]')) this.clearFieldError(event.target);
            this.saveDraft();
        }, options);
        this.addEventListener('input', (event) => {
            if (event.target.matches('[data-field], [data-registration-office], [data-registration-remarks]')) {
                this.clearFieldError(event.target);
                this.saveDraft();
            }
        }, options);
        document.addEventListener('submit', (event) => {
            if (!event.target.contains(this)) return;
            if (!this.validateRegistration()) event.preventDefault();
            else this.clearDraft();
        }, options);
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
        this.files?.forEach(({ url }) => URL.revokeObjectURL(url));
    }

    saveDraft() {
        const fields = Object.fromEntries([...this.querySelectorAll('[data-field]')].map((field) => [field.dataset.field, field.value]));
        const routes = this.registrationRoutes().map((row) => ({
            office: row.querySelector('[data-registration-office]').value,
            remarks: row.querySelector('[data-registration-remarks]').value,
        }));
        try {
            sessionStorage.setItem(this.draftKey, JSON.stringify({ fields, routes, hadFiles: this.files.length > 0 }));
            const notice = this.querySelector('[data-draft-notice]');
            notice.textContent = this.files.length > 0
                ? 'Draft saved in this tab. Select attachments again if you leave.'
                : 'Draft saved in this tab.';
            notice.hidden = false;
        } catch { /* Continue without draft storage when the browser disables it. */ }
    }

    restoreDraft() {
        let draft;
        try {
            draft = JSON.parse(sessionStorage.getItem(this.draftKey));
        } catch { return; }
        if (!draft || !Array.isArray(draft.routes)) return;

        this.querySelectorAll('[data-field]').forEach((field) => {
            const value = draft.fields?.[field.dataset.field];
            if (typeof value === 'string') field.value = value;
        });
        const list = this.querySelector('[data-registration-route-list]');
        list.replaceChildren();
        draft.routes.slice(0, 5).forEach((route) => {
            const row = this.querySelector('[data-registration-route-template]').content.firstElementChild.cloneNode(true);
            row.querySelector('[data-registration-office]').value = typeof route.office === 'string' ? route.office : '';
            row.querySelector('[data-registration-remarks]').value = typeof route.remarks === 'string' ? route.remarks : '';
            list.append(row);
        });
        this.syncRegistrationRoutes();
        const notice = this.querySelector('[data-draft-notice]');
        notice.textContent = draft.hadFiles && this.querySelector('#document-files')
            ? 'Your draft was restored. Select your attachments again before registering.'
            : 'Your draft was restored after leaving this page.';
        notice.hidden = false;
    }

    clearDraft() {
        try { sessionStorage.removeItem(this.draftKey); } catch { /* Continue when storage is unavailable. */ }
    }

    clearFieldError(field) {
        field.setCustomValidity('');
        field.removeAttribute('aria-invalid');
        const errorId = `${field.id}-error`;
        field.parentElement.querySelector(`#${CSS.escape(errorId)}`)?.remove();
        const descriptions = (field.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && id !== errorId);
        if (descriptions.length) field.setAttribute('aria-describedby', descriptions.join(' '));
        else field.removeAttribute('aria-describedby');
    }

    showFieldError(field) {
        if (field.checkValidity()) return false;
        const error = document.createElement('p');
        error.id = `${field.id}-error`;
        error.className = 'dts-field-error';
        error.textContent = field.validationMessage;
        field.insertAdjacentElement('afterend', error);
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', [field.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
        return true;
    }

    validateRegistration() {
        const fields = [...this.querySelectorAll('[data-field]')];
        const routes = this.registrationRoutes();
        const routeCountError = this.querySelector('[data-registration-count-error]');
        routeCountError.hidden = routes.length >= 1;
        if (routes.length < 1) {
            this.querySelector('[data-registration-add-route]').focus();
            return false;
        }
        for (const field of fields) {
            this.clearFieldError(field);
            if (field.required && !field.value.trim()) field.setCustomValidity('Please complete this field.');
        }
        let invalid = null;
        for (const field of fields) {
            if (this.showFieldError(field)) invalid ||= field;
        }

        const selectedOffices = new Set();
        for (const row of routes) {
            const office = row.querySelector('[data-registration-office]');
            const remarks = row.querySelector('[data-registration-remarks]');
            this.clearFieldError(office);
            this.clearFieldError(remarks);
            if (!office.value) office.setCustomValidity('Select a destination office.');
            else if (selectedOffices.has(office.value)) office.setCustomValidity('Select each destination office only once.');
            if (this.showFieldError(office)) invalid ||= office;
            if (this.showFieldError(remarks)) invalid ||= remarks;
            selectedOffices.add(office.value);
        }
        if (invalid) {
            invalid.focus();
            return false;
        }

        return true;
    }

    registrationRoutes() {
        return [...this.querySelectorAll('[data-registration-route-list] [data-registration-route]')];
    }

    addRegistrationRoute() {
        if (this.registrationRoutes().length >= 5) return;
        const list = this.querySelector('[data-registration-route-list]');
        const template = this.querySelector('[data-registration-route-template]');
        list.append(template.content.cloneNode(true));
        animateIn(list.lastElementChild);
        this.syncRegistrationRoutes();
        this.saveDraft();
        this.registrationRoutes().at(-1)?.querySelector('[data-registration-office]')?.focus();
    }

    async removeRegistrationRoute(button) {
        await animateOut(button.closest('[data-registration-route]'));
        this.syncRegistrationRoutes();
        this.saveDraft();
    }

    syncRegistrationRoutes() {
        const routes = this.registrationRoutes();
        this.querySelector('[data-registration-add-route]').disabled = routes.length >= 5;
        if (routes.length >= 1) this.querySelector('[data-registration-count-error]').hidden = true;
        routes.forEach((row, index) => {
            const number = index + 1;
            const office = row.querySelector('[data-registration-office]');
            const remarks = row.querySelector('[data-registration-remarks]');
            this.clearFieldError(office);
            this.clearFieldError(remarks);
            const officeLabel = row.querySelector('[data-registration-office-label]');
            const remarksLabel = row.querySelector('[data-registration-remarks-label]');
            const remove = row.querySelector('[data-registration-remove-route]');
            row.querySelector('[data-registration-route-number]').textContent = String(number);
            row.querySelector('[data-registration-route-title]').textContent = `Route ${number}`;
            office.id = `document-route-office-${index}`;
            const choices = row.querySelector('[data-suggestions]');
            choices.id = `${office.id}-choices`;
            choices.replaceChildren();
            office.setAttribute('aria-controls', choices.id);
            officeLabel.htmlFor = office.id;
            officeLabel.textContent = `Destination office ${number}`;
            office.setCustomValidity('');
            remarks.id = `document-route-remarks-${index}`;
            remarks.name = `document[route_remarks][${index}]`;
            remarksLabel.htmlFor = remarks.id;
            remarksLabel.textContent = `Remarks for destination ${number} (optional)`;
            remove.setAttribute('aria-label', `Remove destination ${number}`);
            remove.hidden = routes.length < 2;
        });
    }

    addFiles(input) {
        const errors = [];
        for (const file of input.files) {
            if (!/\.(pdf|jpe?g|png|docx|xlsx?|csv)$/i.test(file.name)) {
                errors.push(`${file.name}: select a PDF, image, Word, Excel, or CSV file.`);
            } else if (!file.size || file.size > 10 * 1024 * 1024) {
                errors.push(`${file.name}: the file must be nonempty and no larger than 10 MB.`);
            } else if (this.files.some((entry) => entry.file.name === file.name && entry.file.size === file.size && entry.file.lastModified === file.lastModified)) {
                errors.push(`${file.name}: already selected.`);
            } else if (this.files.length >= 5) {
                errors.push('A maximum of five attachments is allowed.');
                break;
            } else {
                this.files.push({ file, url: URL.createObjectURL(file) });
            }
        }
        this.syncFileInput();
        this.querySelector('#attachment-error').textContent = errors.join(' ');
        this.renderFiles();
    }

    syncFileInput() {
        this.fileTransfer = new DataTransfer();
        this.files.forEach(({ file }) => this.fileTransfer.items.add(file));
        this.querySelector('#document-files').files = this.fileTransfer.files;
    }

    renderFiles() {
        const template = this.querySelector('[data-file-template]');
        this.querySelectorAll('[data-file-list]').forEach((list) => {
            list.replaceChildren();
            this.files.forEach(({ file, url }, index) => {
                const row = template.content.cloneNode(true);
                const link = row.querySelector('[data-file-link]');
                link.textContent = file.name;
                link.href = url;
                link.setAttribute('aria-label', `Open ${file.name} in a new tab`);
                row.querySelector('[data-file-size]').textContent = file.size < 1024 * 1024
                    ? `${Math.max(1, Math.round(file.size / 1024))} KB`
                    : `${(file.size / (1024 * 1024)).toFixed(1)} MB`;
                const remove = row.querySelector('[data-remove-file]');
                if (list.dataset.fileList === 'review') remove.remove();
                else {
                    remove.dataset.removeFile = index;
                    remove.setAttribute('aria-label', `Remove ${file.name}`);
                    remove.title = `Remove ${file.name}`;
                }
                list.append(row);
            });
        });
        this.querySelector('[data-file-count]').textContent = `${this.files.length} / 5`;
        this.querySelector('[data-no-files]').hidden = this.files.length > 0;
    }

}

if (!customElements.get('dts-registration')) customElements.define('dts-registration', DocumentRegistration);

class DocumentRouteEditor extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.events = new AbortController();
        const options = { signal: this.events.signal };

        this.addEventListener('click', (event) => {
            if (event.target.closest('[data-edit-route-add]')) this.addRoute();
            const remove = event.target.closest('[data-edit-route-remove]');
            if (remove) this.removeRoute(remove);
        }, options);

        this.addEventListener('input', (event) => {
            if (event.target.matches('[data-edit-office]')) this.clearFieldError(event.target);
        }, options);

        this.syncRoutes();
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
    }

    editRoutes() {
        return [...this.querySelectorAll('[data-edit-route-list] [data-edit-route]')];
    }

    // Whether the office list differs from the one the page loaded with (order does not matter to the server).
    routesChanged() {
        const initial = JSON.parse(this.dataset.initialRoutes || '[]').slice().sort();
        const current = [...this.querySelectorAll('[data-edit-route-list] [data-edit-office]')]
            .map((input) => input.value.trim()).filter(Boolean).sort();
        return initial.join('|') !== current.join('|');
    }

    // The server allows five active offices; finished ones (completed, released…) stay on the route on top of those.
    activeRouteCount() {
        return this.editRoutes().filter((row) => !row.hasAttribute('data-edit-route-finished')).length;
    }

    addRoute() {
        if (this.activeRouteCount() >= 5) return;
        const list = this.querySelector('[data-edit-route-list]');
        const template = this.querySelector('[data-edit-route-template]');
        if (!list || !template) return;

        list.append(template.content.cloneNode(true));
        animateIn(list.lastElementChild);
        this.syncRoutes();
        this.editRoutes().at(-1)?.querySelector('[data-edit-office]')?.focus();
    }

    async removeRoute(button) {
        await animateOut(button.closest('[data-edit-route]'));
        this.syncRoutes();
    }

    clearFieldError(field) {
        field.setCustomValidity('');
        field.removeAttribute('aria-invalid');
    }

    syncRoutes() {
        const routes = this.editRoutes();
        const add = this.querySelector('[data-edit-route-add]');
        const countError = this.querySelector('[data-edit-route-count-error]');
        if (add) add.disabled = this.activeRouteCount() >= 5;
        if (countError) countError.hidden = routes.length >= 1;

        routes.forEach((row, index) => {
            const number = index + 1;
            const office = row.querySelector('[data-edit-office]');
            const choices = row.querySelector('[data-suggestions]');
            const officeLabel = row.querySelector('[data-edit-office-label]');
            const title = row.querySelector('[data-edit-route-title]');
            const remove = row.querySelector('[data-edit-route-remove]');

            this.clearFieldError(office);
            row.querySelector('[data-edit-route-number]').textContent = String(number);
            if (title) title.textContent = `Office ${number}`;

            office.id = `document-edit-route-office-${index}`;
            officeLabel.htmlFor = office.id;
            // Locked rows (offices that started work) have a note instead of a suggestion list.
            const note = row.querySelector('[data-edit-route-note]');
            if (note) {
                note.id = `${office.id}-note`;
                office.setAttribute('aria-describedby', note.id);
            }
            if (choices) {
                choices.id = `${office.id}-choices`;
                choices.replaceChildren();
                office.setAttribute('aria-controls', choices.id);
            }
            officeLabel.textContent = `Assigned office ${number}`;

            if (remove) {
                remove.setAttribute('aria-label', `Remove office ${number}`);
                remove.hidden = routes.length < 2;
            }
        });
    }
}

if (!customElements.get('dts-route-editor')) customElements.define('dts-route-editor', DocumentRouteEditor);

class SidebarProfileMenu extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.toggle = this.querySelector('[data-profile-menu-toggle]');
        this.panel = this.querySelector('[data-profile-menu-panel]');
        if (!this.toggle || !this.panel) return;

        this.events = new AbortController();
        const options = { signal: this.events.signal };

        this.toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            this.setOpen(this.panel.hidden);
        }, options);

        this.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !this.panel.hidden) {
                this.setOpen(false);
                this.toggle.focus();
            }
        }, options);

        document.addEventListener('click', (event) => {
            if (!this.contains(event.target)) this.setOpen(false);
        }, options);
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
    }

    setOpen(open) {
        this.panel.hidden = !open;
        this.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
}

if (!customElements.get('dts-profile-menu')) customElements.define('dts-profile-menu', SidebarProfileMenu);

class DocumentAttachmentManager extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.events = new AbortController();
        const options = { signal: this.events.signal };

        this.addEventListener('change', (event) => {
            if (event.target.matches('[data-attachment-files]')) this.updateSelection(event.target);
            if (event.target.matches('[data-attachment-replacement]')) this.replace(event.target);
        }, options);
        this.addEventListener('click', (event) => {
            const add = event.target.closest('[data-attachment-add]');
            const remove = event.target.closest('[data-attachment-delete]');

            if (add) this.add(add);
            if (remove) this.remove(remove);
        }, options);
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
    }

    updateSelection(input) {
        const label = this.querySelector('[data-attachment-selection]');
        const count = input.files.length;

        if (label) label.textContent = count ? `${count} file${count === 1 ? '' : 's'} selected` : 'Choose files';
        this.showStatus('', false);
    }

    add(button) {
        const input = this.querySelector('[data-attachment-files]');

        if (!input?.files.length) {
            this.showStatus('Select at least one file to upload.', true);
            input?.focus();
            return;
        }

        const data = new FormData();
        [...input.files].forEach((file) => data.append('attachments[]', file));
        this.submit(button.dataset.url, data, button);
    }

    async replace(input) {
        const file = input.files[0];

        if (!file) return;
        if (!await dtsDialog.confirm(`Replace ${input.dataset.fileName} with ${file.name}?`, {
            title: 'Replace attachment',
            confirmLabel: 'Replace',
        })) {
            input.value = '';
            return;
        }

        const data = new FormData();
        data.append('replacement', file);
        this.submit(input.dataset.url, data, input.closest('.dts-icon-button'));
    }

    async remove(button) {
        if (!await dtsDialog.confirm(`Delete ${button.dataset.fileName}? This action is recorded in the audit trail.`, {
            title: 'Delete attachment',
            confirmLabel: 'Delete',
            danger: true,
        })) return;

        this.submit(button.dataset.url, new FormData(), button);
    }

    async submit(url, data, control) {
        if (this.busy) return;

        this.busy = true;
        control?.setAttribute('aria-busy', 'true');
        data.append('_token', this.dataset.token);
        this.showStatus('Saving attachment changes...', false);

        try {
            const response = await fetch(url, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const result = await response.json();

            if (!response.ok) {
                const errors = result.errors ? Object.values(result.errors).flat().join(' ') : result.message;
                throw new Error(errors || 'The attachment change could not be completed.');
            }

            this.querySelector('[data-attachment-content]').innerHTML = result.html;
            this.showStatus(result.message, false);
        } catch (error) {
            const message = error.message || 'The attachment change could not be completed.';
            this.showStatus(message, true);
            void dtsDialog.alert(message, { title: 'Attachment not saved' });
        } finally {
            this.busy = false;
            control?.removeAttribute('aria-busy');
        }
    }

    showStatus(message, isError) {
        const status = this.querySelector('[data-attachment-status]');

        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-error', isError);
    }
}

if (!customElements.get('dts-attachment-manager')) customElements.define('dts-attachment-manager', DocumentAttachmentManager);

class WorkflowRouting extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.events = new AbortController();
        this.destinationActions = new Set(JSON.parse(this.dataset.destinationActions || '[]'));
        this.actionOptionsByOffice = JSON.parse(this.dataset.actionOptions || '{}');
        this.activeRoutes = JSON.parse(this.dataset.activeRoutes || '[]');
        const options = { signal: this.events.signal };

        this.addEventListener('click', (event) => {
            const add = event.target.closest('[data-add-destination]');
            const remove = event.target.closest('[data-remove-destination]');

            if (add) this.addDestination();
            if (remove) this.removeDestination(remove);
        }, options);
        this.addEventListener('change', (event) => {
            // A destination picked from the searchable list arrives as a change, not an input.
            if (event.target.matches('[data-workflow-action], [data-destination-select]')) this.syncDestinationPanel();
            if (event.target.matches('[data-workflow-source-office]')) {
                this.syncActionOptions();
                this.syncDestinationRows();
            }
        }, options);
        this.addEventListener('input', (event) => {
            if (event.target.matches('[data-destination-select]')) this.syncDestinationPanel();
            if (event.target.matches('[data-workflow-action]')) this.syncDestinationPanel();
        }, options);

        this.setupActionChoices(options);
        this.syncActionOptions();
        this.syncDestinationPanel();
        this.syncDestinationRows();
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
    }

    addDestination() {
        const template = this.querySelector('[data-destination-template]');
        const list = this.querySelector('[data-destination-list]');

        if (!template || !list || this.destinationRows().length >= this.destinationLimit()) return;

        list.append(template.content.cloneNode(true));
        animateIn(list.lastElementChild);
        this.syncDestinationRows();
        list.querySelector('[data-destination-row]:last-child [data-destination-select]')?.focus();
    }

    async removeDestination(button) {
        const rows = this.destinationRows();

        if (rows.length <= 1) {
            rows[0]?.querySelector('[data-destination-select]')?.focus();
            return;
        }

        await animateOut(button.closest('[data-destination-row]'));
        this.syncDestinationRows();
    }

    syncDestinationPanel() {
        const action = this.querySelector('[data-workflow-action]')?.value;
        const requiresDestination = this.destinationActions.has(action);
        const panel = this.querySelector('[data-destination-panel]');

        if (!panel) return;

        panel.hidden = !requiresDestination;
        panel.querySelectorAll('[data-destination-select]').forEach((select) => {
            select.disabled = !requiresDestination;
            select.required = requiresDestination;
        });
        panel.querySelectorAll('[data-destination-remarks]').forEach((remarks) => {
            remarks.disabled = !requiresDestination;
            remarks.required = false;
        });
        this.syncSourceOfficeOptions();
        this.syncDestinationLimit(panel, requiresDestination);
        const sharedRemarks = this.querySelector('[data-shared-remarks]');
        const sharedRemarksInput = sharedRemarks?.querySelector('textarea');
        // Receive-only offices skip remarks too: the one thing to do is confirm the document arrived.
        if (sharedRemarks) sharedRemarks.hidden = requiresDestination || this.receiveOnly;
        if (sharedRemarksInput) {
            sharedRemarksInput.disabled = requiresDestination || this.receiveOnly;
            sharedRemarksInput.required = false;
        }
        const releaseRecipient = this.querySelector('[data-release-recipient]');
        const releaseRecipientInput = releaseRecipient?.querySelector('input');
        if (releaseRecipient) releaseRecipient.hidden = action !== 'released';
        if (releaseRecipientInput) {
            releaseRecipientInput.disabled = action !== 'released';
            // Optional: the client can type it when signing on the Fast Track page.
            releaseRecipientInput.required = false;
        }
    }

    // Before an office receives its route the backend allows only Receive. The other controls are hidden and the
    // save button becomes one prominent Receive button; after receipt the office's full set of actions appears.
    syncReceiveOnly(actionOptions) {
        this.receiveOnly = Object.keys(actionOptions).length === 1 && Object.hasOwn(actionOptions, 'received');
        this.toggleAttribute('data-receive-only', this.receiveOnly);
        if (this.actionChoices) this.actionChoices.hidden = this.receiveOnly;

        let note = this.querySelector('[data-receive-first]');
        if (this.receiveOnly && !note) {
            note = document.createElement('p');
            note.className = 'dts-receive-first';
            note.dataset.receiveFirst = '';
            note.textContent = 'Receive this document first. Forward, Complete and Release appear after your office receives it.';
            (this.actionChoices ?? this.querySelector('[data-workflow-action]')?.closest('label, .dts-field'))?.before(note);
        }
        if (note) note.hidden = !this.receiveOnly;

        const submit = this.parentElement?.querySelector('[data-workflow-submit-label]')?.closest('button');
        if (!submit) return;
        submit.classList.toggle('dts-button-receive', this.receiveOnly);
        // The document page confirms through these attributes; the workspace reads data-receive-only itself.
        submit.dataset.routingConfirm ??= JSON.stringify([submit.dataset.confirmMessage, submit.dataset.confirmTitle, submit.dataset.confirmLabel]);
        const [message, title, label] = this.receiveOnly
            ? ['Confirm that your office has received this document?', 'Receive document', 'Receive']
            : JSON.parse(submit.dataset.routingConfirm);
        if (message !== undefined && message !== null) Object.assign(submit.dataset, { confirmMessage: message, confirmTitle: title, confirmLabel: label });
    }

    syncActionOptions() {
        const source = this.querySelector('[data-workflow-source-office]');
        const sourceOffice = source?.value || '';
        const status = this.querySelector('[data-workflow-source-status]');
        if (status) {
            status.dataset.status = source?.selectedOptions[0]?.dataset.status || '';
            status.textContent = source?.selectedOptions[0]?.dataset.statusLabel || '';
        }
        const actionSelect = this.querySelector('[data-workflow-action]');
        const actionOptions = this.actionOptionsByOffice[sourceOffice];

        if (!actionSelect || !actionOptions) return;

        const selectedAction = actionSelect.value;
        const placeholder = new Option('Choose an action', '');
        actionSelect.replaceChildren(placeholder, ...Object.entries(actionOptions).map(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;

            return option;
        }));

        // A lone everyday action is preselected; Restore reopens a finished document, so it always takes a click.
        const onlyAction = Object.keys(actionOptions).length === 1 ? Object.keys(actionOptions)[0] : '';
        actionSelect.value = Object.prototype.hasOwnProperty.call(actionOptions, selectedAction)
            ? selectedAction
            : onlyAction === 'restore' ? '' : onlyAction;
        this.renderActionChoices(actionOptions);
        this.syncReceiveOnly(actionOptions);
        this.syncActionChoiceState();
        this.syncDestinationPanel();
        this.syncOfficeRemark(sourceOffice, actionOptions);
    }

    // "Add remark" belongs to a received, active route: the same offices that may forward (DocumentRemarkController).
    syncOfficeRemark(office, actionOptions) {
        const remark = this.parentElement?.querySelector('[data-office-remark]');
        if (!remark) return;
        const allowed = Object.hasOwn(actionOptions, 'forwarded');
        remark.hidden = !allowed;
        remark.dataset.office = office;
        remark.querySelectorAll('[data-office-remark-office]').forEach((name) => { name.textContent = office; });
        if (!allowed) closeOfficeRemark(remark);
    }

    // Fast buttons in place of the action dropdown. The backend decides which actions each office may take; the
    // buttons only order them: Receive first, then Forward, Complete and Release. Restore reopens a finished
    // document, so it sits apart from the everyday actions. The hidden dropdown still carries the chosen action.
    setupActionChoices(options) {
        const actionSelect = this.querySelector('[data-workflow-action]');
        if (!actionSelect) return;

        const field = actionSelect.closest('.dts-field, label');
        this.actionChoices = document.createElement('div');
        this.actionChoices.className = 'dts-action-choices';
        this.actionChoices.innerHTML = `
            <span class="dts-action-choices-label" id="${actionSelect.id || 'workflow-action'}-choices-label">Action</span>
            <div class="dts-action-choice-list" role="group" aria-labelledby="${actionSelect.id || 'workflow-action'}-choices-label" data-action-choice-list></div>
            <p class="dts-action-choice-hint" data-action-choice-hint aria-live="polite"></p>
            <div class="dts-action-restore" data-action-restore hidden>
                <div><strong>Finished document</strong><p>Restore reopens it so an office can work on it again.</p></div>
            </div>`;
        field.before(this.actionChoices);
        field.hidden = true;

        this.addEventListener('click', (event) => {
            const choice = event.target.closest('[data-action-choice]');
            if (!choice || choice.disabled) return;
            actionSelect.value = choice.dataset.actionChoice;
            actionSelect.dispatchEvent(new Event('change', { bubbles: true }));
            this.syncActionChoiceState();
        }, options);
        // The dropdown is hidden, so point an unanswered "Choose an action" at the buttons instead.
        actionSelect.addEventListener('invalid', (event) => {
            event.preventDefault();
            const hint = this.actionChoices.querySelector('[data-action-choice-hint]');
            hint.textContent = 'Choose an action first.';
            hint.classList.add('is-error');
            this.actionChoices.querySelector('[data-action-choice]:not(:disabled)')?.focus();
        }, options);
    }

    renderActionChoices(actionOptions) {
        if (!this.actionChoices) return;

        const everydayOrder = ['received', 'forwarded', 'completed', 'released'];
        const actions = Object.keys(actionOptions);
        const everyday = [
            ...everydayOrder.filter((action) => actions.includes(action)),
            ...actions.filter((action) => !everydayOrder.includes(action) && action !== 'restore'),
        ];
        const button = (action) => {
            const choice = document.createElement('button');
            choice.type = 'button';
            choice.className = `dts-action-choice${action === 'received' ? ' is-receive' : ''}${action === 'restore' ? ' is-restore' : ''}`;
            choice.dataset.actionChoice = action;
            choice.textContent = actionOptions[action];

            return choice;
        };

        const list = this.actionChoices.querySelector('[data-action-choice-list]');
        list.replaceChildren(...everyday.map(button));
        list.hidden = everyday.length === 0;
        this.actionChoices.querySelector('.dts-action-choices-label').hidden = everyday.length === 0;

        const restore = this.actionChoices.querySelector('[data-action-restore]');
        restore.querySelector('[data-action-choice]')?.remove();
        restore.hidden = !actions.includes('restore');
        if (actions.includes('restore')) restore.append(button('restore'));

        this.syncActionChoiceState();
    }

    syncActionChoiceState() {
        if (!this.actionChoices) return;

        const selected = this.querySelector('[data-workflow-action]')?.value || '';
        this.actionChoices.querySelectorAll('[data-action-choice]').forEach((choice) => {
            choice.setAttribute('aria-pressed', String(choice.dataset.actionChoice === selected));
        });
        const hints = {
            received: 'Confirms your office has the document. Choose what happens next after this.',
            forwarded: 'Send the document to one or more offices below.',
            completed: "Your office's part is finished.",
            released: 'Release the document from the Records Unit. This finishes it.',
            restore: 'Reopen this document and choose the office that works on it next.',
        };
        const hint = this.actionChoices.querySelector('[data-action-choice-hint]');
        hint.classList.remove('is-error');
        hint.textContent = hints[selected] || '';

        const submitLabel = this.parentElement?.querySelector('[data-workflow-submit-label]');
        const chosen = this.actionChoices.querySelector(`[data-action-choice="${selected}"]`);
        if (submitLabel) submitLabel.textContent = this.receiveOnly ? 'Receive document' : chosen ? chosen.textContent : 'Save routing update';
    }

    syncDestinationRows() {
        this.destinationRows().forEach((row, index) => {
            const number = index + 1;
            row.querySelector('[data-destination-number]').textContent = String(number);
            const select = row.querySelector('[data-destination-select]');
            const remarks = row.querySelector('[data-destination-remarks]');
            const remarksLabel = row.querySelector('[data-destination-remarks-label]') || row.querySelector('label');
            const remove = row.querySelector('[data-remove-destination]');

            select.id = `workflow-destination-${number}`;
            select.setAttribute('aria-label', `Destination office ${number}`);
            remarks.id = `workflow-destination-remarks-${index}`;
            remarks.name = `workflow[destination_remarks][${index}]`;
            remarksLabel.htmlFor = remarks.id;
            remarksLabel.textContent = `Remarks for destination ${number} (optional)`;
            remove.setAttribute('aria-label', `Remove destination ${number}`);
            remove.hidden = this.destinationRows().length <= 1;
        });
        this.syncDestinationPanel();
    }

    destinationRows() {
        return [...this.querySelectorAll('[data-destination-row]')];
    }

    // The server allows five unfinished office routes in all; the acting office's own route does not count.
    destinationLimit() {
        const sourceOffice = this.querySelector('[data-workflow-source-office]')?.value || '';

        return Math.max(5 - this.activeRoutes.filter((office) => office !== sourceOffice).length, 0);
    }

    syncDestinationLimit(panel, requiresDestination) {
        const limit = this.destinationLimit();
        const count = this.destinationRows().length;
        let note = panel.querySelector('[data-destination-limit]');
        if (!note) {
            note = document.createElement('p');
            note.className = 'dts-destination-limit';
            note.dataset.destinationLimit = '';
            note.setAttribute('aria-live', 'polite');
            panel.querySelector('[data-destination-list]')?.after(note);
        }

        panel.querySelector('[data-add-destination]')?.toggleAttribute('disabled', !requiresDestination || count >= limit);
        const offices = (n) => `${n} ${n === 1 ? 'office' : 'offices'}`;
        note.classList.toggle('is-over', count > limit || limit === 0);
        if (limit === 0) note.textContent = 'This document is already with five offices. One must finish before it can go to another office.';
        else if (count > limit) note.textContent = `Only ${offices(limit)} can be added right now. Remove ${count - limit} ${count - limit === 1 ? 'destination' : 'destinations'}.`;
        else if (count === limit) note.textContent = `That is the most for now: a document can be with five offices at once.`;
        else note.textContent = `You can add ${offices(limit - count)} more.`;

        // Block submitting more destinations than the server will accept.
        this.destinationRows().forEach((row, index) => {
            const select = row.querySelector('[data-destination-select]');
            if (requiresDestination && index >= limit) select.setCustomValidity(`Too many destinations: only ${limit} can be added.`);
        });
    }

    syncSourceOfficeOptions() {
        const sourceOffice = this.querySelector('[data-workflow-source-office]')?.value || '';
        // Restore may send the document back to the acting office itself; other actions must go elsewhere.
        const allowsActingOffice = this.querySelector('[data-workflow-action]')?.value === 'restore';

        this.querySelectorAll('[data-destination-select]').forEach((input) => {
            const isActingOffice = input.value !== '' && input.value === sourceOffice;
            input.setCustomValidity(isActingOffice && !allowsActingOffice ? 'Choose an office other than the acting office.' : '');
        });
    }
}

if (!customElements.get('dts-workflow-routing')) customElements.define('dts-workflow-routing', WorkflowRouting);

// Outgoing list: "Send to another office" (orchid/cells/document-row-actions). Adds one more office to an active
// document after the sending office has dispatched an extra paper copy; the original routes are not touched.
function additionalRouteDialog() {
    let dialog = document.querySelector('#dts-additional-route-dialog');
    if (dialog) return dialog;

    dialog = document.createElement('dialog');
    dialog.id = 'dts-additional-route-dialog';
    dialog.className = 'dts-app-dialog dts-additional-route-dialog';
    dialog.setAttribute('aria-labelledby', 'dts-additional-route-title');
    dialog.innerHTML = `
        <div class="dts-app-dialog-header"><h2 id="dts-additional-route-title">Send to another office</h2></div>
        <div class="dts-app-dialog-content dts-additional-route-fields">
            <p class="dts-additional-route-document"><strong data-additional-route-tracking></strong><span data-additional-route-subject></span></p>
            <p class="dts-field-hint">The offices it already went to stay as they are. This adds one more office.</p>
            <div class="dts-field" data-additional-route-source-field>
                <label for="dts-additional-route-source">Sending office</label>
                <select id="dts-additional-route-source" data-additional-route-source></select>
            </div>
            <div class="dts-field">
                <label for="dts-additional-route-office">Office to send it to <span aria-hidden="true">*</span></label>
                <dts-searchable>
                    <input id="dts-additional-route-office" data-additional-route-office data-searchable data-options="additional-route-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="Search offices" autocomplete="off" required>
                    <div data-suggestions role="listbox" hidden></div>
                </dts-searchable>
            </div>
            <div class="dts-field">
                <label for="dts-additional-route-remarks">Remarks <span class="dts-optional">Optional</span></label>
                <textarea id="dts-additional-route-remarks" data-additional-route-remarks rows="3" maxlength="1000" autocomplete="off" placeholder="Instructions for this office"></textarea>
            </div>
            <label class="dts-additional-route-confirm">
                <input type="checkbox" data-additional-route-dispatched>
                <span><strong>I have sent a separate copy of the document/s to this office.</strong> Check this only after the extra copy has left your office.</span>
            </label>
            <p class="dts-workspace-form-errors" data-additional-route-errors role="alert" hidden></p>
        </div>
        <div class="dts-app-dialog-actions">
            <button type="button" class="dts-button" data-additional-route-cancel>Cancel</button>
            <button type="button" class="dts-button dts-button-primary" data-additional-route-save><span class="dts-workspace-spinner tw:motion-safe:animate-spin" data-workspace-button-spinner aria-hidden="true" hidden></span> Send to office</button>
        </div>`;
    dialog.addEventListener('click', (event) => {
        if (event.target.closest('[data-additional-route-cancel]')) dialog.close();
    });
    document.body.append(dialog);

    return dialog;
}

document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-additional-route]');
    if (trigger) {
        const details = JSON.parse(trigger.dataset.additionalRoute);
        const dialog = additionalRouteDialog();
        dialog.details = details;
        dialog.trigger = trigger;
        dialog.querySelector('[data-additional-route-tracking]').textContent = details.tracking;
        dialog.querySelector('[data-additional-route-subject]').textContent = details.subject;
        const source = dialog.querySelector('[data-additional-route-source]');
        source.replaceChildren(...details.sources.map((office) => new Option(office, office)));
        // One sending office needs no choice; it is still sent with the request.
        dialog.querySelector('[data-additional-route-source-field]').hidden = details.sources.length < 2;
        dialog.querySelector('[data-additional-route-office]').value = '';
        dialog.querySelector('[data-additional-route-remarks]').value = '';
        dialog.querySelector('[data-additional-route-dispatched]').checked = false;
        dialog.querySelector('[data-additional-route-errors]').hidden = true;
        dialog.showModal();
        dialog.querySelector('[data-additional-route-office]').focus();
        return;
    }

    const save = event.target.closest('[data-additional-route-save]');
    if (!save) return;
    const dialog = save.closest('dialog');
    const errors = dialog.querySelector('[data-additional-route-errors]');
    const office = dialog.querySelector('[data-additional-route-office]');
    const dispatched = dialog.querySelector('[data-additional-route-dispatched]');
    const source = dialog.querySelector('[data-additional-route-source]').value;
    const offices = [...document.querySelectorAll('#additional-route-offices option')].map((option) => option.value);
    const problem = !offices.includes(office.value.trim()) ? ['Choose an office from the list.', office]
        : office.value.trim() === source || dialog.details.assigned.includes(office.value.trim()) ? ['That office already has this document. Choose a different office.', office]
            : !dispatched.checked ? ['Confirm that the extra copy of the document/s was sent before adding the office.', dispatched]
                : null;
    if (problem) {
        errors.textContent = problem[0];
        errors.hidden = false;
        problem[1].focus();
        return;
    }

    const data = new FormData();
    data.append('source_office', source);
    data.append('destination_office', office.value.trim());
    data.append('remarks', dialog.querySelector('[data-additional-route-remarks]').value.trim());
    data.append('dispatch_confirmed', '1');
    const result = await postWorkspace(dialog.details.url, data, errors, save);
    if (!result) return;

    dialog.close();
    dtsToastAfterReload(result.message);
    window.location.reload();
});

// Outgoing list: "Correct route" (orchid/cells/document-row-actions). Swaps an office the user's office sent the
// document to by mistake for the right one, before that office receives it. A reason is required and kept in history.
function routeCorrectionDialog() {
    let dialog = document.querySelector('#dts-route-correction-dialog');
    if (dialog) return dialog;

    dialog = document.createElement('dialog');
    dialog.id = 'dts-route-correction-dialog';
    dialog.className = 'dts-app-dialog dts-additional-route-dialog';
    dialog.setAttribute('aria-labelledby', 'dts-route-correction-title');
    dialog.innerHTML = `
        <div class="dts-app-dialog-header"><h2 id="dts-route-correction-title">Correct route</h2></div>
        <div class="dts-app-dialog-content dts-additional-route-fields">
            <p class="dts-additional-route-document"><strong data-route-correction-tracking></strong><span data-route-correction-subject></span></p>
            <p class="dts-field-hint">Use this when the document went to the wrong office. You can change an office only before it receives the document.</p>
            <div class="dts-field">
                <label for="dts-route-correction-route">Wrong office <span aria-hidden="true">*</span></label>
                <select id="dts-route-correction-route" data-route-correction-route></select>
            </div>
            <div class="dts-field">
                <label for="dts-route-correction-office">Correct office <span aria-hidden="true">*</span></label>
                <dts-searchable>
                    <input id="dts-route-correction-office" data-route-correction-office data-searchable data-options="additional-route-offices" role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="Search offices" autocomplete="off" required>
                    <div data-suggestions role="listbox" hidden></div>
                </dts-searchable>
            </div>
            <div class="dts-field">
                <label for="dts-route-correction-reason">Reason <span aria-hidden="true">*</span></label>
                <textarea id="dts-route-correction-reason" data-route-correction-reason rows="3" maxlength="500" autocomplete="off" required placeholder="Example: Chose Budget instead of Legal"></textarea>
            </div>
            <p class="dts-workspace-form-errors" data-route-correction-errors role="alert" hidden></p>
        </div>
        <div class="dts-app-dialog-actions">
            <button type="button" class="dts-button" data-route-correction-cancel>Cancel</button>
            <button type="button" class="dts-button dts-button-primary" data-route-correction-save><span class="dts-workspace-spinner tw:motion-safe:animate-spin" data-workspace-button-spinner aria-hidden="true" hidden></span> Correct route</button>
        </div>`;
    dialog.addEventListener('click', (event) => {
        if (event.target.closest('[data-route-correction-cancel]')) dialog.close();
    });
    document.body.append(dialog);

    return dialog;
}

document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-route-correction]');
    if (trigger) {
        const details = JSON.parse(trigger.dataset.routeCorrection);
        const dialog = routeCorrectionDialog();
        dialog.details = details;
        const multipleSources = new Set(details.routes.map((route) => route.source)).size > 1;
        dialog.querySelector('[data-route-correction-tracking]').textContent = details.tracking;
        dialog.querySelector('[data-route-correction-subject]').textContent = details.subject;
        // Each choice is one destination; with several sending offices the label also names who sent it.
        dialog.querySelector('[data-route-correction-route]').replaceChildren(...details.routes.map((route, index) => new Option(
            multipleSources ? `${route.destination} (sent by ${route.source})` : route.destination,
            String(index),
        )));
        const officeInput = dialog.querySelector('[data-route-correction-office]');
        // The Outgoing list and the document window each carry their own office list.
        officeInput.dataset.options = details.list || 'additional-route-offices';
        officeInput.value = '';
        dialog.querySelector('[data-route-correction-reason]').value = '';
        dialog.querySelector('[data-route-correction-errors]').hidden = true;
        dialog.showModal();
        dialog.querySelector(details.routes.length > 1 ? '[data-route-correction-route]' : '[data-route-correction-office]').focus();
        return;
    }

    const save = event.target.closest('[data-route-correction-save]');
    if (!save) return;
    const dialog = save.closest('dialog');
    const errors = dialog.querySelector('[data-route-correction-errors]');
    const office = dialog.querySelector('[data-route-correction-office]');
    const reason = dialog.querySelector('[data-route-correction-reason]');
    const route = dialog.details.routes[Number(dialog.querySelector('[data-route-correction-route]').value)];
    const chosen = office.value.trim();
    const offices = [...document.querySelectorAll(`#${office.dataset.options} option`)].map((option) => option.value);
    const problem = !offices.includes(chosen) ? ['Choose the correct office from the list.', office]
        : chosen === route.source || dialog.details.assigned.includes(chosen) ? ['That office already has this document. Choose a different office.', office]
            : reason.value.trim() === '' ? ['Give the reason for the correction.', reason]
                : null;
    if (problem) {
        errors.textContent = problem[0];
        errors.hidden = false;
        problem[1].focus();
        return;
    }

    const data = new FormData();
    data.append('source_office', route.source);
    data.append('destination_office', route.destination);
    data.append('replacement_office', chosen);
    data.append('correction_reason', reason.value.trim());
    const result = await postWorkspace(dialog.details.url, data, errors, save);
    if (!result) return;

    dialog.close();
    dtsToastAfterReload(result.message);
    window.location.reload();
});

// "Add remark" (orchid/partials/office-remark): a note for the acting office's history, without changing its status.
function closeOfficeRemark(remark) {
    const panel = remark.querySelector('[data-office-remark-panel]');
    if (!panel || panel.hidden) return;
    panel.hidden = true;
    remark.querySelector('[data-office-remark-toggle]')?.setAttribute('aria-expanded', 'false');
    remark.querySelector('[data-office-remark-errors]').hidden = true;
}

document.addEventListener('click', async (event) => {
    const remark = event.target.closest('[data-office-remark]');
    if (!remark) return;

    if (event.target.closest('[data-office-remark-toggle]')) {
        const panel = remark.querySelector('[data-office-remark-panel]');
        if (!panel.hidden) { closeOfficeRemark(remark); return; }
        panel.hidden = false;
        remark.querySelector('[data-office-remark-toggle]').setAttribute('aria-expanded', 'true');
        remark.querySelector('[data-office-remark-text]').focus();
        return;
    }

    if (event.target.closest('[data-office-remark-cancel]')) {
        closeOfficeRemark(remark);
        remark.querySelector('[data-office-remark-toggle]').focus();
        return;
    }

    const save = event.target.closest('[data-office-remark-save]');
    if (!save) return;
    const text = remark.querySelector('[data-office-remark-text]');
    const errors = remark.querySelector('[data-office-remark-errors]');
    if (!text.value.trim()) {
        errors.textContent = 'Enter a remark first.';
        errors.hidden = false;
        text.focus();
        return;
    }

    const office = remark.dataset.office;
    const data = new FormData();
    data.append('office', office);
    data.append('remark', text.value.trim());
    const result = await postWorkspace(remark.dataset.remarkUrl, data, errors, save);
    if (!result) return;

    const dialog = remark.closest('[data-document-workspace]');
    if (!dialog) {
        dtsToastAfterReload(result.message || 'Remark added.');
        window.location.reload();
        return;
    }
    try {
        await refreshWorkspace(dialog, true);
        // Show the new entry where it belongs: that office's history.
        [...dialog.querySelectorAll('[data-workspace-route]')].find((route) => route.dataset.workspaceRoute === office)?.click();
        dtsToast(result.message || 'Remark added.');
    } catch (error) {
        errors.textContent = error.message;
        errors.hidden = false;
    }
});

document.addEventListener('click', (event) => {
    const link = event.target.closest('.dts-document-view [data-workflow-office-link]');
    const source = link?.closest('.dts-document-view')?.querySelector('[data-workflow-source-office]');
    if (!source || source.type === 'hidden') return;

    if ([...source.options].some((option) => option.value === link.dataset.workflowOfficeLink)) {
        source.value = link.dataset.workflowOfficeLink;
        source.dispatchEvent(new Event('change', { bubbles: true }));
    }
});

function setRegistryRequestState(frame, state) {
    if (!frame || frame.dataset.registryRequestState === state) return;

    frame.dataset.registryRequestState = state;
    frame.toggleAttribute('data-registry-loading', state === 'loading');
    frame.querySelectorAll('[data-filter-action], [data-page-size]').forEach((control) => {
        control.disabled = state === 'loading';
    });
    const error = frame.querySelector('[data-registry-request-error]');
    if (error) error.hidden = state !== 'error';

    const live = frame.closest('.dts-registry-page')?.querySelector('[data-registry-live]');
    if (live) live.textContent = state === 'loading' ? 'Updating document results.' : state === 'idle' ? 'Document results updated.' : '';
}

document.addEventListener('turbo:before-fetch-request', (event) => {
    if (event.target?.id !== 'document-registry') return;
    event.target.dataset.registryRetryUrl = event.detail.url.toString();
    setRegistryRequestState(event.target, 'loading');
});

document.addEventListener('turbo:before-fetch-response', (event) => {
    if (event.target?.id === 'document-registry' && event.detail.fetchResponse.serverError) {
        event.target.dataset.registryServerError = 'true';
    }
});

document.addEventListener('turbo:before-frame-render', (event) => {
    if (event.target?.id === 'document-registry' && event.target.dataset.registryServerError === 'true') {
        event.detail.render = () => {};
    }
});

document.addEventListener('turbo:frame-load', (event) => {
    if (event.target?.id !== 'document-registry') return;
    const state = event.target.dataset.registryServerError === 'true' ? 'error' : 'idle';
    delete event.target.dataset.registryServerError;
    setRegistryRequestState(event.target, state);
});

document.addEventListener('turbo:frame-missing', (event) => {
    if (event.target?.id !== 'document-registry' || event.detail.response.status < 500) return;
    event.preventDefault();
    delete event.target.dataset.registryServerError;
    setRegistryRequestState(event.target, 'error');
});

document.addEventListener('turbo:fetch-request-error', (event) => {
    if (event.target?.id !== 'document-registry') return;
    event.preventDefault();
    setRegistryRequestState(event.target, 'error');
});

document.addEventListener('click', (event) => {
    const frame = event.target.closest('#document-registry');
    if (!frame) return;

    if (event.target.closest('[data-registry-retry]')) {
        const url = frame.dataset.registryRetryUrl;
        if (url && window.Turbo) window.Turbo.visit(url, { frame: frame.id, action: 'advance' });
        return;
    }

    if (frame.hasAttribute('data-registry-loading') && event.target.closest('a[href], [data-filter-action]')) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

class DocumentFilters extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.events = new AbortController();
        const options = { signal: this.events.signal };

        this.addEventListener('click', (event) => {
            const toggle = event.target.closest('[data-filter-toggle]');
            if (toggle) {
                this.toggleFilters(toggle);
                return;
            }

            const action = event.target.closest('[data-filter-action]')?.dataset.filterAction;
            if (action === 'apply') this.apply();
            if (action === 'reset') this.reset();
        }, options);
        this.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && event.target.matches('[data-filter]')) {
                event.preventDefault();
                this.apply();
            }
        }, options);
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
    }

    toggleFilters(toggle) {
        const panel = this.querySelector('[data-filter-panel]');
        const isExpanded = toggle.getAttribute('aria-expanded') === 'true';

        toggle.setAttribute('aria-expanded', String(!isExpanded));
        toggleRevealed(panel, !isExpanded);
    }

    apply() {
        const url = new URL(window.location.href);

        this.querySelectorAll('[data-filter]').forEach((field) => {
            const value = field.value.trim();
            if (value) url.searchParams.set(field.dataset.filter, value);
            else url.searchParams.delete(field.dataset.filter);
        });
        url.searchParams.delete('page');
        this.navigate(url);
    }

    reset() {
        const url = new URL(window.location.href);
        ['search', 'office', 'type', 'status', 'from', 'to', 'needs_action', 'page'].forEach((parameter) => url.searchParams.delete(parameter));
        this.navigate(url);
    }

    navigate(url) {
        const frame = this.closest('turbo-frame');
        if (frame?.hasAttribute('data-registry-loading')) return;

        if (window.Turbo) {
            frame.dataset.registryRetryUrl = url.toString();
            setRegistryRequestState(frame, 'loading');
            window.Turbo.visit(url.toString(), { frame: 'document-registry', action: 'advance' });
            return;
        }

        window.location.assign(url);
    }
}

if (!customElements.get('dts-document-filters')) customElements.define('dts-document-filters', DocumentFilters);

// "Columns" menu above a table (partials/column-menu): one checkbox per column the table lets users hide. They mirror
// Orchid's own column checkboxes in the table footer (hidden by CSS), so Orchid still remembers the choice per table.
class ColumnMenu extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.events = new AbortController();
        this.addEventListener('change', (event) => {
            const proxy = event.target.closest('[data-column-proxy]');
            if (proxy) this.toggleColumn(proxy);
        }, { signal: this.events.signal });
        this.build();
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
    }

    table() {
        return this.closest('turbo-frame')?.querySelector('[data-controller="table"]');
    }

    source(column) {
        return this.table()?.querySelector(`input[data-column="${CSS.escape(column)}"]`);
    }

    build() {
        if (!this.isConnected) return;
        const table = this.table();
        if (!table) {
            requestAnimationFrame(() => this.build());
            return;
        }

        const options = this.querySelector('.dts-column-options');
        options.querySelectorAll('label').forEach((label) => label.remove());
        table.querySelectorAll('input[data-column]').forEach((source) => {
            const label = document.createElement('label');
            const proxy = document.createElement('input');
            const text = document.createElement('span');
            proxy.type = 'checkbox';
            proxy.dataset.columnProxy = source.dataset.column;
            text.textContent = table.querySelector(`label[for="${CSS.escape(source.id)}"]`)?.textContent.trim() || source.dataset.column;
            label.append(proxy, text);
            options.append(label);
        });
        // Orchid applies the saved column choice after this runs, so read the checkboxes on the next frame.
        requestAnimationFrame(() => this.sync());
    }

    sync() {
        const proxies = [...this.querySelectorAll('[data-column-proxy]')];
        this.hidden = proxies.length === 0 || !this.table()?.querySelector('tbody tr');
        proxies.forEach((proxy) => {
            proxy.checked = this.source(proxy.dataset.columnProxy)?.checked ?? false;
        });
        this.querySelector('[data-visible-column-count]').textContent = `${proxies.filter((proxy) => proxy.checked).length}/${proxies.length}`;
    }

    toggleColumn(proxy) {
        const source = this.source(proxy.dataset.columnProxy);
        if (source && source.checked !== proxy.checked) source.click();
        requestAnimationFrame(() => this.sync());
    }
}

if (!customElements.get('dts-column-menu')) customElements.define('dts-column-menu', ColumnMenu);

// Inactivity sign-out. The server (LogoutInactiveStaff) signs a session out after the idle limit; this warns two minutes
// earlier and then signs out itself, so nobody meets the limit mid-form. Clicks and typing that send no request of their
// own are reported at most once a minute. Open tabs share the last activity, and one tab signing out signs out the rest.
const IDLE_WARNING_SECONDS = 120;
const IDLE_ACTIVITY_KEY = 'dts-last-server-activity';
const IDLE_SIGNED_OUT_KEY = 'dts-idle-signed-out';

function readIdleStorage(key) {
    try { return Number(localStorage.getItem(key)) || 0; } catch { return 0; }
}

function writeIdleStorage(key, value) {
    try { localStorage.setItem(key, String(value)); } catch { /* Private windows: this tab still times out on its own. */ }
}

class IdleSignOut extends HTMLElement {
    connectedCallback() {
        this.idleMs = Number(this.dataset.idleSeconds) * 1000;
        if (!this.idleMs || this.events) return;

        this.events = new AbortController();
        const options = { signal: this.events.signal, passive: true };
        // Loading this page was a request, so the server has just recorded activity.
        this.recordServerActivity(Date.now());
        this.lastPingAt = Date.now();

        ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach((type) => {
            document.addEventListener(type, () => this.noteActivity(), options);
        });
        window.addEventListener('storage', (event) => {
            if (event.key === IDLE_SIGNED_OUT_KEY && event.newValue) window.location.assign(this.dataset.loginUrl);
        }, options);
        this.timer = setInterval(() => this.check(), 1000);
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
        clearInterval(this.timer);
        this.warning?.remove();
        this.warning = null;
    }

    recordServerActivity(time) {
        this.lastServerActivity = time;
        writeIdleStorage(IDLE_ACTIVITY_KEY, time);
    }

    lastActivity() {
        return Math.max(this.lastServerActivity, readIdleStorage(IDLE_ACTIVITY_KEY));
    }

    noteActivity() {
        // While the warning is open only "Stay signed in" keeps the session, so a stray click does not.
        if (this.warning?.open || this.signingOut) return;
        const now = Date.now();
        if (now - this.lastActivity() >= 60_000 && now - this.lastPingAt >= 60_000) this.keepAlive();
    }

    async keepAlive() {
        this.lastPingAt = Date.now();
        try {
            const response = await fetch(this.dataset.keepAliveUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf_token"]')?.content ?? '', 'Accept': 'application/json' },
            });
            if (response.status === 401 || response.status === 419) window.location.assign(this.dataset.loginUrl);
            else if (response.ok) this.recordServerActivity(Date.now());
            return response.ok;
        } catch {
            return false;
        }
    }

    check() {
        if (this.signingOut) return;
        const remaining = this.lastActivity() + this.idleMs - Date.now();
        if (remaining <= 0) this.signOut();
        else if (remaining <= IDLE_WARNING_SECONDS * 1000) this.showWarning(remaining);
        else if (this.warning?.open) this.warning.close();
    }

    showWarning(remaining) {
        if (!this.warning) {
            this.warning = document.createElement('dialog');
            this.warning.className = 'dts-app-dialog dts-idle-dialog';
            this.warning.setAttribute('aria-labelledby', 'dts-idle-title');
            this.warning.setAttribute('aria-describedby', 'dts-idle-message');
            this.warning.innerHTML = `
                <div class="dts-app-dialog-header"><h2 id="dts-idle-title">Are you still there?</h2></div>
                <div class="dts-app-dialog-content">
                    <p id="dts-idle-message">There has been no activity for a while. For your security you will be signed out in <strong class="dts-idle-countdown" data-idle-countdown></strong>. Changes you have not saved will be lost.</p>
                </div>
                <div class="dts-app-dialog-actions">
                    <button type="button" class="dts-button" data-idle-sign-out>Sign out now</button>
                    <button type="button" class="dts-button dts-button-primary" data-idle-stay>Stay signed in</button>
                </div>`;
            this.warning.addEventListener('click', async (event) => {
                if (event.target.closest('[data-idle-sign-out]')) this.signOut();
                if (event.target.closest('[data-idle-stay]')) this.stay();
            });
            // Escape means "I am here", not "close and sign me out later".
            this.warning.addEventListener('cancel', (event) => {
                event.preventDefault();
                this.stay();
            });
            document.body.append(this.warning);
        }

        const seconds = Math.ceil(remaining / 1000);
        this.warning.querySelector('[data-idle-countdown]').textContent =
            `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
        if (!this.warning.open) {
            this.warning.showModal();
            this.warning.querySelector('[data-idle-stay]').focus();
        }
    }

    async stay() {
        this.warning?.close();
        if (!(await this.keepAlive())) this.check();
    }

    signOut() {
        if (this.signingOut) return;
        this.signingOut = true;
        writeIdleStorage(IDLE_SIGNED_OUT_KEY, Date.now());

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = this.dataset.signOutUrl;
        form.dataset.turbo = 'false';
        form.hidden = true;
        const token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_token';
        token.value = document.querySelector('meta[name="csrf_token"]')?.content ?? '';
        form.append(token);
        document.body.append(form);
        form.submit();
    }
}

if (!customElements.get('dts-idle-sign-out')) customElements.define('dts-idle-sign-out', IdleSignOut);

document.addEventListener('change', (event) => {
    const pageSize = event.target.closest('[data-page-size]');

    if (!pageSize) return;

    const url = new URL(window.location.href);
    const frame = pageSize.closest('turbo-frame');

    if (frame?.hasAttribute('data-registry-loading')) return;

    url.searchParams.set('per_page', pageSize.value);
    url.searchParams.delete('page');

    if (window.Turbo) {
        if (frame) {
            frame.dataset.registryRetryUrl = url.toString();
            setRegistryRequestState(frame, 'loading');
        }
        window.Turbo.visit(url.toString(), frame
            ? { frame: frame.id, action: 'advance' }
            : { action: 'advance' });
        return;
    }

    window.location.assign(url);
});

function updateTableSelection(table) {
    if (!table) return;

    const checkboxes = [...table.querySelectorAll('[data-table-row-select]:not(:disabled)')];
    const selectedCount = checkboxes.filter((checkbox) => checkbox.checked).length;
    const headerCheckbox = table.querySelector('[data-table-select-all]');
    if (headerCheckbox) {
        headerCheckbox.disabled = checkboxes.length === 0;
        headerCheckbox.checked = checkboxes.length > 0 && selectedCount === checkboxes.length;
        headerCheckbox.indeterminate = selectedCount > 0 && selectedCount < checkboxes.length;
    }

    let summary = table.querySelector(':scope > [data-table-selection-summary]');

    if (selectedCount === 0) {
        summary?.remove();
        return;
    }

    if (!summary) {
        summary = document.createElement('div');
        summary.className = 'dts-table-selection-summary';
        summary.dataset.tableSelectionSummary = '';
        summary.innerHTML = '<span data-selected-count role="status" aria-live="polite"></span><div class="dts-table-selection-actions"><button type="button" data-clear-table-selection>Clear selection</button></div>';
        table.prepend(summary);
    }

    summary.querySelector('[data-selected-count]').textContent = `${selectedCount} selected on this page`;

    const bulkLabelUrl = table.closest('[data-bulk-label-url]')?.dataset.bulkLabelUrl;

    if (bulkLabelUrl) {
        let printLink = summary.querySelector('[data-print-selected-labels]');

        if (!printLink) {
            printLink = document.createElement('a');
            printLink.className = 'dts-button dts-button-primary dts-icon-only';
            printLink.innerHTML = PRINTER_ICON;
            printLink.dataset.printSelectedLabels = '';
            printLink.dataset.turbo = 'false';
            printLink.target = '_blank';
            printLink.rel = 'noopener';
            summary.querySelector('.dts-table-selection-actions').prepend(printLink);
        }

        const url = new URL(bulkLabelUrl, window.location.href);
        checkboxes.filter((checkbox) => checkbox.checked).forEach((checkbox) => {
            url.searchParams.append('ids[]', checkbox.value);
        });
        printLink.href = url.toString();
        const printText = selectedCount === 1 ? 'Print label' : `Print ${selectedCount} labels`;
        printLink.dataset.tooltip = printText;
        printLink.setAttribute('aria-label', printText);
    }

    // Documents and the admin lists (users, roles, offices, schools) both put "Delete selected" here.
    const documentDeleteUrl = table.closest('[data-bulk-delete-url]')?.dataset.bulkDeleteUrl;
    const adminFrame = table.closest('[data-admin-bulk-delete-url]');
    if ((documentDeleteUrl || adminFrame) && !summary.querySelector('[data-document-bulk-delete], [data-admin-bulk-delete]')) {
        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'dts-button dts-button-danger';
        if (documentDeleteUrl) {
            deleteButton.dataset.documentBulkDelete = '';
            deleteButton.dataset.url = documentDeleteUrl;
        } else {
            deleteButton.dataset.adminBulkDelete = '';
            deleteButton.dataset.deleteKind = adminFrame.dataset.adminBulkDeleteKind;
            deleteButton.dataset.url = adminFrame.dataset.adminBulkDeleteUrl;
        }
        deleteButton.textContent = 'Delete selected';
        summary.querySelector('.dts-table-selection-actions').insertBefore(deleteButton, summary.querySelector('[data-clear-table-selection]'));
    }
}

function syncTableSelections(root = document) {
    root.querySelectorAll('[data-controller="table"]').forEach(updateTableSelection);
}

document.addEventListener('turbo:load', () => syncTableSelections());
document.addEventListener('turbo:frame-load', (event) => syncTableSelections(event.target));
syncTableSelections();

// On phones each row is shown as "label | value" lines; the labels come from the column headings.
function labelTableCells(root = document) {
    root.querySelectorAll('[data-controller="table"] .table, table.dts-table').forEach((table) => {
        const labels = [...table.querySelectorAll('thead th')].map((th) => th.textContent.replace(/\s+/g, ' ').trim());

        table.querySelectorAll('tbody tr').forEach((row) => {
            [...row.children].forEach((cell, index) => {
                if (labels[index] && !cell.matches(':only-child')) cell.dataset.label = labels[index];
            });
        });
    });
}

document.addEventListener('turbo:load', () => labelTableCells());

// Browsers offer earlier entries under text fields (subjects, remarks, senders…), which staff took for saved data. Fields
// that chose their own autocomplete (the sign-in email, the password) keep it. Focus covers rows added later from templates.
const autocompleteFields = 'input:not([type="hidden"], [type="checkbox"], [type="radio"], [type="file"], [type="submit"], [type="button"]):not([autocomplete]), textarea:not([autocomplete])';

function disableFieldHistory(root = document) {
    root.querySelectorAll(autocompleteFields).forEach((field) => field.setAttribute('autocomplete', 'off'));
}

document.addEventListener('turbo:load', () => disableFieldHistory());
document.addEventListener('focusin', (event) => {
    if (event.target.matches?.(autocompleteFields)) event.target.setAttribute('autocomplete', 'off');
}, true);
document.addEventListener('turbo:frame-load', (event) => labelTableCells(event.target));
labelTableCells();

document.addEventListener('change', (event) => {
    const selectAll = event.target.closest('[data-table-select-all]');
    const rowSelect = event.target.closest('[data-table-row-select]');

    if (selectAll) {
        const table = selectAll.closest('[data-controller="table"]');

        table?.querySelectorAll('[data-table-row-select]:not(:disabled)').forEach((checkbox) => {
            checkbox.checked = selectAll.checked;
        });
        updateTableSelection(table);
        return;
    }

    if (rowSelect) {
        updateTableSelection(rowSelect.closest('[data-controller="table"]'));
    }
});

// The row checkbox is small; a tap anywhere in its cell toggles it.
document.addEventListener('click', (event) => {
    const cell = event.target.closest('td[data-column="select"]');
    if (!cell || event.target.closest('input')) return;

    const checkbox = cell.querySelector('[data-table-row-select]:not(:disabled)');
    if (!checkbox) return;

    checkbox.checked = !checkbox.checked;
    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
});

document.addEventListener('click', (event) => {
    const clearSelection = event.target.closest('[data-clear-table-selection]');

    if (!clearSelection) return;

    const table = clearSelection.closest('[data-controller="table"]');
    table?.querySelectorAll('[data-table-row-select]').forEach((checkbox) => {
        checkbox.checked = false;
    });
    updateTableSelection(table);
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-admin-bulk-delete], [data-document-bulk-delete]');
    if (!button || button.disabled) return;

    const selected = [...button.closest('turbo-frame').querySelectorAll('[data-table-row-select]:checked')];
    const kind = button.matches('[data-document-bulk-delete]') ? 'document' : button.dataset.deleteKind;
    const message = {
        document: `Remove ${selected.length} selected document(s) from active records? Their routing history and attachments will be kept.`,
        user: `Delete ${selected.length} selected user account(s)? Document records will remain, but user attribution may be cleared. This cannot be undone.`,
        role: `Delete ${selected.length} selected role(s)? Users assigned these roles will lose their permissions. This cannot be undone.`,
        office: `Delete ${selected.length} selected unused office(s)? This cannot be undone.`,
        school: `Delete ${selected.length} selected school(s)? They will no longer appear in origin suggestions. Existing documents will retain their recorded origins. This cannot be undone.`,
    }[kind];
    if (selected.length === 0) return;
    if (!await dtsDialog.confirm(message, { title: 'Delete selected', confirmLabel: 'Delete', danger: true })) return;

    const token = document.querySelector('meta[name="csrf_token"]')?.content;
    if (!token) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = button.dataset.url;
    form.dataset.turbo = 'false';
    form.hidden = true;

    for (const [name, value] of [['_token', token], ...selected.map((checkbox) => ['selected[]', checkbox.value])]) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.append(input);
    }

    document.body.append(form);
    form.submit();
});

// Every confirmation uses the DTS dialog, including Orchid's ->confirm() buttons (data-button-confirm),
// which would otherwise open Orchid's own "Are you sure?" modal.
document.addEventListener('click', async (event) => {
    const action = event.target.closest('[data-confirm-message], [data-button-confirm]');

    if (!action) return;
    const isOrchidButton = !('confirmMessage' in action.dataset);
    if (action.dataset.dtsConfirmed === 'true') {
        delete action.dataset.dtsConfirmed;
        // Orchid's confirm action listens on the button itself; stopping the click here keeps its modal
        // closed while the browser still submits the form.
        if (isOrchidButton) event.stopImmediatePropagation();
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    const isDelete = action.matches('.dts-row-action-delete, .dts-button-danger');
    const confirmed = await dtsDialog.confirm(action.dataset.confirmMessage ?? action.dataset.buttonConfirm, {
        title: action.dataset.confirmTitle || (isDelete ? 'Confirm delete' : 'Confirm action'),
        confirmLabel: action.dataset.confirmLabel || (isDelete ? 'Delete' : 'Continue'),
        danger: action.dataset.confirmDanger === 'true' || isDelete,
    });
    if (confirmed && action.isConnected) {
        action.dataset.dtsConfirmed = 'true';
        action.click();
    }
}, true);

function setSubmitBusy(button, busy) {
    if (!button) return;
    button.classList.toggle('dts-is-busy', busy);
    if (busy) button.setAttribute('aria-busy', 'true');
    else button.removeAttribute('aria-busy');
}

// Turbo already disables the submit button while a form is sending; show that it is working.
document.addEventListener('turbo:submit-start', (event) => setSubmitBusy(event.detail.formSubmission.submitter, true));
document.addEventListener('turbo:submit-end', (event) => setSubmitBusy(event.detail.formSubmission.submitter, false));

// Forms that bypass Turbo: block a second submit and show progress until the next page loads.
window.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;
    const method = (event.submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'get').toLowerCase();
    const target = event.submitter?.getAttribute('formtarget') || form.getAttribute('target');
    if (method === 'get' || target === '_blank') return;
    if (form.dataset.dtsSubmitting) {
        event.preventDefault();
        return;
    }
    form.dataset.dtsSubmitting = 'true';
    setSubmitBusy(event.submitter, true);
    // Release the form if the page stays put, e.g. after a file download.
    setTimeout(() => {
        delete form.dataset.dtsSubmitting;
        setSubmitBusy(event.submitter, false);
    }, 10000);
});

window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    document.querySelectorAll('form[data-dts-submitting]').forEach((form) => delete form.dataset.dtsSubmitting);
    document.querySelectorAll('.dts-is-busy').forEach((button) => setSubmitBusy(button, false));
});

// Records Unit paper intake: rows waiting for the client's paper copy get a "Paper received" button in the
// dashboard's action queue. Older submissions name offices and are sent to them at once; counter-intake ones name
// none, so Records chooses the offices afterwards.
function joinOfficeNames(offices) {
    return offices.length <= 1 ? (offices[0] || '') : `${offices.slice(0, -1).join(', ')} and ${offices.at(-1)}`;
}

// The client may also have listed the Records Unit; it keeps a copy rather than being "sent" the document.
function intakeRoutes(data) {
    return {
        forward: data.document.routes.filter((route) => route.office !== data.source_office),
        keepsCopy: data.document.routes.some((route) => route.office === data.source_office),
    };
}

async function lookupIntake(lookupUrl, tracking) {
    const url = new URL(lookupUrl, window.location.href);
    url.searchParams.set('tracking', tracking);
    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (response.status === 404) return null;
    if (!response.ok) throw new Error('Lookup failed.');

    return response.json();
}

// Resolves to { message } on success, { latest } when someone else already confirmed it, or { error }.
async function confirmPaperReceipt(lookupUrl, data) {
    const body = new FormData();
    body.append('workflow[action]', 'received');
    body.append('workflow[source_office]', data.source_office);

    try {
        const response = await fetch(data.document.confirm_url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf_token"]')?.content || '',
            },
            body,
        });

        if (response.ok) return { message: (await response.json()).message };

        const latest = await lookupIntake(lookupUrl, data.document.tracking_number).catch(() => null);
        if (latest && latest.state !== 'awaiting') return { latest };

        const failure = await response.json().catch(() => ({}));
        return { error: Object.values(failure.errors || {})[0]?.[0] || failure.message || 'Could not confirm receipt. Try again.' };
    } catch {
        return { error: 'Could not save. Check the connection and try again.' };
    }
}

function paperSentText(offices) {
    return offices.length === 0
        ? 'Document/s received.'
        : `Document/s sent to ${offices.length} ${offices.length === 1 ? 'office' : 'offices'}`;
}

// Dashboard action queue: reveal the rows past the first five in place, then offer the next dashboard page.
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-office-queue-more]');
    if (!button) return;

    const queue = button.closest('.dts-office-queue');
    const extraRows = [...queue.querySelectorAll('[data-office-queue-extra]')];
    extraRows.forEach((row) => { row.hidden = false; });
    queue.querySelector('[data-office-queue-all]')?.removeAttribute('hidden');
    button.remove();
    extraRows[0]?.querySelector('a, button')?.focus();
});

// One button, no confirmation: the document/s are received and the queue reloads with the row's next actions.
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-paper-received]');
    if (!button || button.disabled) return;

    const lookupUrl = button.dataset.lookupUrl;
    button.disabled = true;
    setSubmitBusy(button, true);

    try {
        const data = await lookupIntake(lookupUrl, button.dataset.paperReceived);

        if (!data) {
            await dtsDialog.alert('This document could not be found. Refresh the page and try again.', { title: 'Document not found' });
            return;
        }

        if (data.state !== 'awaiting') {
            dtsToastAfterReload(data.state === 'received' ? 'Already received.' : 'Nothing to receive.');
            window.location.reload();
            return;
        }

        // Receiving happens straight away, with no confirmation step. The queue then reloads, so the row shows its
        // next action buttons (send, complete, print label…) or leaves the queue once the offices have it.
        const { forward } = intakeRoutes(data);

        const outcome = await confirmPaperReceipt(lookupUrl, data);
        if (outcome.message || outcome.latest) {
            dtsToastAfterReload(outcome.message ? paperSentText(forward) : 'Already received.');
            window.location.reload();
            return;
        }
        await dtsDialog.alert(outcome.error, { title: 'Receipt not confirmed' });
    } catch {
        await dtsDialog.alert('Could not reach the server. Check the connection and try again.', { title: 'Receipt not confirmed' });
    } finally {
        if (button.isConnected) {
            button.disabled = false;
            setSubmitBusy(button, false);
        }
    }
});

// Login "Show password" button (partials/password-input).
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-password-toggle]');
    if (!toggle) return;
    const input = document.getElementById(toggle.getAttribute('aria-controls'));
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(show));
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

// Icon-only row buttons from Orchid layouts (Edit, Delete) carry their label as hidden text; show it as hover text.
['pointerover', 'focusin'].forEach((type) => document.addEventListener(type, (event) => {
    const button = event.target.closest?.('.dts-row-action:not([data-tooltip])');
    const label = button?.textContent.trim();
    if (label) button.dataset.tooltip = label;
}));

// Action queue Receive icon: receives every route of the user's offices still waiting on that document, then reloads.
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-receive-routes]');
    if (!button || button.disabled) return;

    const offices = JSON.parse(button.dataset.receiveRoutes || '[]');
    if (offices.length === 0) return;
    button.disabled = true;
    setSubmitBusy(button, true);
    const failed = [];
    for (const office of offices) {
        const body = new FormData();
        body.append('workflow[action]', 'received');
        body.append('workflow[source_office]', office);
        try {
            const response = await fetch(button.dataset.receiveUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf_token"]')?.content || '' },
                body,
            });
            if (!response.ok) {
                const failure = await response.json().catch(() => ({}));
                failed.push(`${office}: ${Object.values(failure.errors || {})[0]?.[0] || failure.message || 'Could not receive.'}`);
            }
        } catch {
            failed.push(`${office}: Could not reach the server.`);
        }
    }

    if (failed.length > 0) {
        await dtsDialog.alert(['Some routes were not received:', ...failed].join('\n\n'), { title: 'Receive' });
    }
    window.location.reload();
});

// Sticky pagers: the dashboard queue pager (orchid/dashboard) and the registry tables' footer stay at the bottom of
// the window while their table is on screen. With bottom: -1px a pager is fully visible only when it sits in its own
// place under the table, so anything less means it is pinned, which adds the solid backdrop (is-stuck) so it does not
// blend into the rows.
function watchStickyPagers() {
    if (!('IntersectionObserver' in window)) return;
    document.querySelectorAll('.dts-queue-pager, [data-controller="table"] > footer').forEach((pager) => {
        if (pager.dataset.stickyWatched) return;
        pager.dataset.stickyWatched = 'true';
        new IntersectionObserver(([entry]) => {
            pager.classList.toggle('is-stuck', entry.intersectionRatio < 1 && entry.boundingClientRect.top > 0);
        }, { threshold: [1] }).observe(pager);
    });
}
document.addEventListener('turbo:load', watchStickyPagers);
document.addEventListener('turbo:frame-load', watchStickyPagers);
watchStickyPagers();

// Dashboard queue "Rows per page": the dashboard sits inside Orchid's screen form, so the choice changes the address
// instead of submitting a form; it starts again from page one and lands on the queue heading.
document.addEventListener('change', (event) => {
    const select = event.target.closest('[data-queue-page-size]');
    if (!select) return;
    const url = new URL(select.dataset.queueUrl, window.location.origin);
    if (select.value !== '25') url.searchParams.set('queue_per_page', select.value);
    url.hash = 'office-queue-title';
    window.location.assign(url);
});

// Wide tables (registry tables and the dashboard queue): a sticky horizontal scrollbar that mirrors the table's own
// scroll, so moving sideways never needs a trip to the end of the table. It sits just above the table's sticky pager
// (whose height sets the offset) and is hidden when the table fits. The two scroll positions are kept in step.
function attachStickyScrollbars() {
    document.querySelectorAll('[data-controller="table"] .table-responsive, .dts-table-scroll').forEach((scroller) => {
        if (scroller.dataset.stickyScrollbar) return;
        scroller.dataset.stickyScrollbar = 'true';

        const bar = document.createElement('div');
        bar.className = 'dts-sticky-scrollbar';
        bar.setAttribute('aria-hidden', 'true');
        bar.tabIndex = -1;
        const track = document.createElement('div');
        bar.append(track);
        // Outside the dashboard's rounded frame, so the frame's clipping does not hold it in place.
        (scroller.closest('.dts-table-frame') ?? scroller).after(bar);

        const pager = scroller.closest('[data-controller="table"]')?.querySelector(':scope > footer')
            ?? scroller.closest('.dts-office-queue')?.querySelector('.dts-queue-pager');
        const size = () => {
            const overflows = scroller.scrollWidth > scroller.clientWidth + 1;
            bar.hidden = !overflows;
            scroller.classList.toggle('has-sticky-scrollbar', overflows);
            track.style.width = `${scroller.scrollWidth}px`;
            bar.style.setProperty('--dts-sticky-scrollbar-offset', `${(pager?.offsetHeight ?? 0) - 1}px`);
            bar.scrollLeft = scroller.scrollLeft;
        };

        // Copy only when they differ: the copy's own scroll event then finds them equal, so they never loop.
        const follow = (from, to) => () => {
            if (Math.abs(to.scrollLeft - from.scrollLeft) > 1) to.scrollLeft = from.scrollLeft;
        };
        bar.addEventListener('scroll', follow(bar, scroller), { passive: true });
        scroller.addEventListener('scroll', follow(scroller, bar), { passive: true });

        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(size);
            observer.observe(scroller);
            if (scroller.firstElementChild) observer.observe(scroller.firstElementChild);
            if (pager) observer.observe(pager);
        }
        size();
    });
}
document.addEventListener('turbo:load', attachStickyScrollbars);
document.addEventListener('turbo:frame-load', attachStickyScrollbars);
attachStickyScrollbars();
