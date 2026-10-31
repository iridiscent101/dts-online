// Phone/browser push notifications through Firebase Cloud Messaging (FCM), for clients following a document
// ("Get updates on this phone", resources/js/client-updates.js).
// The Firebase SDK is loaded from Google's CDN only when needed, not bundled from npm:
// the npm package is ~39 MB, and big packages in node_modules break the PHP function on Vercel. The service worker
// (public/firebase-messaging-sw.js) loads Firebase from the same CDN. The Content-Security-Policy allows both
// (App\Http\Middleware\AddSecurityHeaders).
const FIREBASE_VERSION = '13.1.0';
const sdk = (name) => import(/* @vite-ignore */ `https://www.gstatic.com/firebasejs/${FIREBASE_VERSION}/firebase-${name}.js`);

const config = {
    apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
    authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
    projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
    messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
    appId: import.meta.env.VITE_FIREBASE_APP_ID,
};

export function notificationsConfigured() {
    return Boolean(config.apiKey && config.projectId && import.meta.env.VITE_FIREBASE_VAPID_KEY);
}

export async function enableNotifications() {
    if (!notificationsConfigured()) {
        throw new Error('Phone notifications are not set up on this server yet.');
    }
    if (!('serviceWorker' in navigator) || !('Notification' in window)) {
        throw new Error('This browser cannot show notifications. Try Chrome on your phone or computer.');
    }

    const [{ initializeApp, getApps }, { getMessaging, getToken, isSupported, onMessage }] = await Promise.all([sdk('app'), sdk('messaging')]);
    if (!(await isSupported())) {
        throw new Error('This browser cannot show notifications. Try Chrome on your phone or computer.');
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        throw new Error('Notifications are blocked. Allow them for this site in your browser settings, then try again.');
    }

    const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
    const app = getApps()[0] ?? initializeApp(config);
    const messaging = getMessaging(app);
    const token = await getToken(messaging, {
        vapidKey: import.meta.env.VITE_FIREBASE_VAPID_KEY,
        serviceWorkerRegistration: registration,
    });
    if (!token) {
        throw new Error('Could not turn on notifications. Please try again.');
    }

    // While the page is open, the browser does not pop a notification by itself; show it in the page instead.
    onMessage(messaging, (payload) => {
        const title = payload.notification?.title || 'DocuTrackS';
        const body = payload.notification?.body || '';
        window.dispatchEvent(new CustomEvent('dts:push-message', { detail: { title, body } }));
    });

    return token;
}
