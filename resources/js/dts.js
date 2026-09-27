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
}

document.addEventListener('click', (event) => {
    if (!event.target.closest('.dts-sidebar-toggle')) return;
    const collapsed = !document.documentElement.classList.contains('dts-sidebar-collapsed');
    setSidebarCollapsed(collapsed);
    try { localStorage.setItem('dts.sidebar.collapsed', String(collapsed)); } catch { /* Keep the in-page preference. */ }
});
document.addEventListener('turbo:load', initializeSidebar);
initializeSidebar();

class DocumentRegistration extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.events = new AbortController();
        this.files = [];
        this.fileTransfer = new DataTransfer();
        const options = { signal: this.events.signal };
        this.querySelector('[data-dts-action="review"]').disabled = false;
        this.addEventListener('click', (event) => {
            const action = event.target.closest('[data-dts-action]')?.dataset.dtsAction;
            if (action === 'review') this.review();
            if (action === 'edit') this.showPanel(false);
            const remove = event.target.closest('[data-remove-file]');
            if (remove) {
                const index = Number(remove.dataset.removeFile);
                URL.revokeObjectURL(this.files[index].url);
                this.files.splice(index, 1);
                this.syncFileInput();
                this.renderFiles();
                this.querySelector('#document-files').focus();
            }
        }, options);
        this.addEventListener('change', (event) => {
            if (event.target.id === 'document-files') this.addFiles(event.target);
        }, options);
        this.addEventListener('input', (event) => {
            if (event.target.matches('[data-field]')) event.target.setCustomValidity('');
        }, options);
    }

    disconnectedCallback() {
        this.events?.abort();
        this.events = null;
        this.files?.forEach(({ url }) => URL.revokeObjectURL(url));
    }

    review() {
        const fields = [...this.querySelectorAll('[data-field]')];
        const received = this.querySelector('[data-field="received"]');
        const due = this.querySelector('[data-field="due"]');
        for (const field of fields) {
            field.setCustomValidity('');
            if (field.required && !field.value.trim()) field.setCustomValidity('Please complete this field.');
        }
        if (due.value && received.value && due.value < received.value) {
            due.setCustomValidity('Due date must be on or after the date received.');
        }
        const invalid = fields.find((field) => !field.checkValidity());
        if (invalid) {
            invalid.reportValidity();
            return;
        }

        const values = Object.fromEntries(fields.map((field) => [field.dataset.field, field.value.trim()]));
        this.querySelectorAll('[data-value]').forEach((element) => {
            const key = element.dataset.value;
            let value = values[key] || 'Not specified';
            if (['received', 'due'].includes(key) && values[key]) {
                value = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium' }).format(new Date(`${values[key]}T12:00:00`));
            }
            element.textContent = value;
        });
        this.renderFiles();
        this.showPanel(true);
    }

    showPanel(review) {
        this.querySelector('[data-edit-panel]').hidden = review;
        this.querySelector('[data-review-panel]').hidden = !review;
        this.querySelector('[data-page-heading]').textContent = review ? 'Document details' : 'New document';
        this.querySelectorAll('[data-step]').forEach((step) => {
            if (step.dataset.step === (review ? 'review' : 'edit')) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
        });
        this.querySelector('[data-page-heading]').focus();
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
        this.querySelector('[data-review-file-count]').textContent = `${this.files.length} file${this.files.length === 1 ? '' : 's'}`;
        this.querySelector('[data-no-files]').hidden = this.files.length > 0;
        this.querySelector('[data-review-no-files]').hidden = this.files.length > 0;
    }
}

if (!customElements.get('dts-registration')) customElements.define('dts-registration', DocumentRegistration);

class DocumentFilters extends HTMLElement {
    connectedCallback() {
        if (this.events) return;

        this.events = new AbortController();
        const options = { signal: this.events.signal };
        requestAnimationFrame(() => this.syncColumnControls());

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
        this.addEventListener('change', (event) => {
            const proxy = event.target.closest('[data-column-proxy]');
            if (proxy) this.toggleColumn(proxy);
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
        panel.hidden = isExpanded;
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
        ['search', 'office', 'type', 'status', 'from', 'to', 'page'].forEach((parameter) => url.searchParams.delete(parameter));
        this.navigate(url);
    }

    syncColumnControls() {
        const table = this.closest('turbo-frame')?.querySelector('[data-controller="table"]');
        if (!table) {
            if (!this.isConnected) return;
            requestAnimationFrame(() => this.syncColumnControls());
            return;
        }

        this.querySelectorAll('[data-column-proxy]').forEach((proxy) => {
            const source = table.querySelector(`input[data-column="${proxy.dataset.columnProxy}"]`);
            proxy.checked = source?.checked ?? false;
            proxy.disabled = !source;
        });

        const visibleColumns = 1 + [...this.querySelectorAll('[data-column-proxy]:checked')].length;
        this.querySelector('[data-visible-column-count]').textContent = `${visibleColumns}/9`;
    }

    toggleColumn(proxy) {
        const table = this.closest('turbo-frame')?.querySelector('[data-controller="table"]');
        const source = table?.querySelector(`input[data-column="${proxy.dataset.columnProxy}"]`);

        if (!source) return;
        if (source.checked !== proxy.checked) source.click();
        requestAnimationFrame(() => this.syncColumnControls());
    }

    navigate(url) {
        if (window.Turbo) {
            window.Turbo.visit(url.toString(), { frame: 'document-registry', action: 'advance' });
            return;
        }

        window.location.assign(url);
    }
}

if (!customElements.get('dts-document-filters')) customElements.define('dts-document-filters', DocumentFilters);

document.addEventListener('change', (event) => {
    const pageSize = event.target.closest('[data-page-size]');

    if (!pageSize) return;

    const url = new URL(window.location.href);
    const frame = pageSize.closest('turbo-frame');

    url.searchParams.set('per_page', pageSize.value);
    url.searchParams.delete('page');

    if (window.Turbo) {
        window.Turbo.visit(url.toString(), frame
            ? { frame: frame.id, action: 'advance' }
            : { action: 'advance' });
        return;
    }

    window.location.assign(url);
});

document.addEventListener('click', (event) => {
    const action = event.target.closest('[data-confirm-message]');

    if (!action || window.confirm(action.dataset.confirmMessage)) return;

    event.preventDefault();
    event.stopImmediatePropagation();
});
