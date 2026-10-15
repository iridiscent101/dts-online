// Copy buttons: <button data-copy-text="..."> with a [data-copy-label] span. Shared by the admin panel and public pages.

async function copyText(text, container) {
    // The Clipboard API only exists on HTTPS or localhost; office LAN installs often run on plain HTTP.
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return;
    }

    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    // Inside a modal <dialog> the page body is inert, so the helper field must live in the dialog.
    container.append(field);
    field.select();
    const copied = document.execCommand('copy');
    field.remove();
    if (!copied) throw new Error('Copy failed');
}

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-text]');
    if (!button) return;
    event.preventDefault();

    const label = button.querySelector('[data-copy-label]');
    try {
        await copyText(button.dataset.copyText, button.closest('dialog') ?? document.body);
        button.dataset.copyState = 'copied';
        if (label) label.textContent = 'Copied';
        button.dataset.tooltip = 'Copied';
    } catch {
        button.dataset.copyState = 'failed';
        if (label) label.textContent = 'Copy failed';
        button.dataset.tooltip = 'Copy failed';
    }

    clearTimeout(button.copyResetTimer);
    button.copyResetTimer = setTimeout(() => {
        delete button.dataset.copyState;
        if (label) label.textContent = 'Copy';
        button.dataset.tooltip = 'Copy';
    }, 2000);
});
