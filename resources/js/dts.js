import './copy-button.js';

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
                ? 'This office receives the document once the Records Unit confirms the paper copy.'
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
        const firstChoice = [...(routing?.querySelectorAll('[data-workflow-source-office], [data-workflow-action]') ?? [])]
            .find((field) => !field.disabled && field.type !== 'hidden');
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
    const fields = [...editor.querySelectorAll('input, textarea, select')];
    const invalid = fields.find((field) => !field.checkValidity());
    if (invalid) { invalid.reportValidity(); invalid.focus(); return; }

    const data = new FormData();
    fields.forEach((field) => data.append(field.name, field.value));
    if (!editor.querySelector('[name="document[destinations][]"]')) data.append('details_only', '1');
    const errors = editor.querySelector('[data-workspace-form-errors]');
    const result = await postWorkspace(editor.dataset.saveUrl, data, errors, button);
    if (!result) return;
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
    const confirmed = await dtsDialog.confirm('Save this routing update?', { title: 'Save routing update', confirmLabel: 'Save update' });
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
        window.location.reload();
        return;
    }
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
        const received = this.querySelector('[data-field="received"]');
        const due = this.querySelector('[data-field="due"]');

        for (const field of fields) {
            this.clearFieldError(field);
            if (field.required && !field.value.trim()) field.setCustomValidity('Please complete this field.');
        }
        if (due.value && received.value && due.value < received.value) {
            due.setCustomValidity('Due date must be on or after the date received.');
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

    addRoute() {
        if (this.editRoutes().length >= 5) return;
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
        if (add) add.disabled = routes.length >= 5;
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
            if (event.target.matches('[data-workflow-action]')) this.syncDestinationPanel();
            if (event.target.matches('[data-workflow-source-office]')) {
                this.syncActionOptions();
                this.syncDestinationRows();
            }
        }, options);
        this.addEventListener('input', (event) => {
            if (event.target.matches('[data-destination-select]')) this.syncDestinationPanel();
            if (event.target.matches('[data-workflow-action]')) this.syncDestinationPanel();
        }, options);

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
        if (sharedRemarks) sharedRemarks.hidden = requiresDestination;
        if (sharedRemarksInput) {
            sharedRemarksInput.disabled = requiresDestination;
            sharedRemarksInput.required = false;
        }
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

        actionSelect.value = Object.prototype.hasOwnProperty.call(actionOptions, selectedAction)
            ? selectedAction
            : Object.keys(actionOptions).length === 1 ? Object.keys(actionOptions)[0] : '';
        this.syncDestinationPanel();
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
        ['search', 'office', 'type', 'status', 'due', 'priority', 'from', 'to', 'page'].forEach((parameter) => url.searchParams.delete(parameter));
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
            printLink.className = 'dts-button dts-button-primary';
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
        printLink.textContent = selectedCount === 1 ? 'Print label' : 'Print labels';
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
// dashboard's action queue. Confirming makes the backend send the document to every office the client chose, all at once.
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

function markQueueRowReceived(button, text, labelUrl = null) {
    const done = document.createElement('div');
    done.className = 'dts-paper-received-done';
    done.setAttribute('role', 'status');
    const note = document.createElement('span');
    note.textContent = text;
    done.append(note);

    if (labelUrl) {
        const label = document.createElement('a');
        label.href = labelUrl;
        label.target = '_blank';
        label.rel = 'noopener';
        label.dataset.turbo = 'false';
        label.textContent = 'Print label';
        done.append(label);
    }

    button.closest('tr')?.classList.add('is-paper-received');
    button.replaceWith(done);
}

// After Paper received the client's offices have the document; show that without reloading the page.
function markQueueOfficesForwarded(row) {
    row?.querySelectorAll('[data-office-queue-office]').forEach((office) => {
        const badge = office.querySelector('[data-status="waiting-for-paper"]');
        if (!badge) return;
        badge.dataset.status = 'forwarded';
        badge.textContent = 'Forwarded';
        office.setAttribute('aria-label', `View ${office.querySelector('.dts-office-status-name')?.textContent.trim()} workflow, Forwarded`);
    });
}

function paperSentText(offices) {
    return offices.length === 0
        ? 'Paper received · kept at Records Unit'
        : `Paper sent to ${offices.length} ${offices.length === 1 ? 'office' : 'offices'}`;
}

// Dashboard action queue: reveal the rows past the first five in place, then offer the full registry.
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

// One button, a short confirmation that names every office, then the row shows the result.
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
            markQueueRowReceived(button, data.state === 'received' ? 'Already received' : 'No paper needed');
            return;
        }

        const { forward, keepsCopy } = intakeRoutes(data);
        const lines = [
            `${data.document.tracking_number}\n${data.document.subject}`,
            forward.length > 0 ? `Send to ${joinOfficeNames(forward.map((route) => route.office))}?` : 'Keep the document at the Records Unit?',
        ];
        if (keepsCopy && forward.length > 0) lines.push('Records Unit keeps a copy to work on.');

        if (!await dtsDialog.confirm(lines.join('\n\n'), { title: 'Paper received', confirmLabel: 'Paper received' })) return;

        const outcome = await confirmPaperReceipt(lookupUrl, data);
        if (outcome.message) {
            const row = button.closest('tr');
            markQueueRowReceived(button, paperSentText(forward), data.document.label_url);
            markQueueOfficesForwarded(row);
        } else if (outcome.latest) {
            markQueueRowReceived(button, 'Already received');
        } else {
            await dtsDialog.alert(outcome.error, { title: 'Paper not confirmed' });
        }
    } catch {
        await dtsDialog.alert('Could not reach the server. Check the connection and try again.', { title: 'Paper not confirmed' });
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
