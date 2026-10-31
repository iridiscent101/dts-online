// "Get updates on this phone" on the tracking and receipt pages (resources/views/partials/client-updates.blade.php):
// asks the browser for permission, gets this phone's Firebase token and links it to the tracking number
// (DocumentPushSubscriptionController). The client then gets a push when the document is routed, released or
// completed. Firebase itself loads only when needed (resources/js/firebase.js).
const storageKey = (tracking) => `dts-updates:${tracking}`;
const remembered = (tracking) => { try { return localStorage.getItem(storageKey(tracking)); } catch { return null; } };
const remember = (tracking, token) => { try { token ? localStorage.setItem(storageKey(tracking), token) : localStorage.removeItem(storageKey(tracking)); } catch { /* Private mode: the button just shows again next time. */ } };

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function send(box, method, token) {
    const response = await fetch(box.dataset.url, {
        method,
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        body: JSON.stringify({ token }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(Object.values(result.errors || {})[0]?.[0] || result.message || 'Could not save. Please try again.');
}

function showState(box, on) {
    box.querySelector('[data-client-updates-off-text]').hidden = on;
    box.querySelector('[data-client-updates-on-text]').hidden = !on;
    box.querySelector('[data-client-updates-enable]').hidden = on;
    box.querySelector('[data-client-updates-disable]').hidden = !on;
}

function showError(box, message) {
    const error = box.querySelector('[data-client-updates-error]');
    error.textContent = message || '';
    error.hidden = !message;
}

async function setUp(box) {
    const supported = 'serviceWorker' in navigator && 'Notification' in window && 'PushManager' in window;
    const firebase = supported ? await import('./firebase.js') : null;
    if (!firebase?.notificationsConfigured()) return;

    const tracking = box.dataset.tracking;
    const on = Notification.permission === 'granted' && Boolean(remembered(tracking));
    showState(box, on);
    box.hidden = false;

    // Firebase tokens change from time to time; save the current one again quietly.
    if (on) {
        firebase.enableNotifications()
            .then((token) => send(box, 'POST', token).then(() => remember(tracking, token)))
            .catch((error) => console.warn('Could not refresh updates for this phone:', error));
    }

    box.querySelector('[data-client-updates-enable]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        showError(box, '');
        try {
            const token = await firebase.enableNotifications();
            await send(box, 'POST', token);
            remember(tracking, token);
            showState(box, true);
        } catch (error) {
            console.error(error);
            showError(box, error.message || 'Could not turn on updates. Please try again.');
        } finally {
            button.disabled = false;
        }
    });

    box.querySelector('[data-client-updates-disable]').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        showError(box, '');
        try {
            await send(box, 'DELETE', remembered(tracking) || '');
            remember(tracking, null);
            showState(box, false);
        } catch (error) {
            showError(box, error.message || 'Could not turn off updates. Please try again.');
        } finally {
            button.disabled = false;
        }
    });
}

document.querySelectorAll('[data-client-updates]').forEach((box) => {
    setUp(box).catch((error) => console.warn('Updates on this phone are unavailable:', error));
});

// While the tracking page is open, a push shows here (the phone does not pop one up for an open page).
window.addEventListener('dts:push-message', (event) => {
    const box = document.querySelector('[data-client-updates]');
    if (!box) return;
    const { title, body } = event.detail || {};
    showError(box, '');
    const note = box.querySelector('[data-client-updates-error]');
    note.classList.replace('text-red-800', 'text-[#216E39]');
    note.setAttribute('role', 'status');
    note.textContent = [title, body].filter(Boolean).join(': ');
    note.hidden = false;
});
