// Web push opt-in (CLAUDE.md section 9): ONLY via the explicit bell
// button, never on page load. Categories + optional team filter.

(() => {
    const bell = document.querySelector('#push-bell');
    const dialog = document.querySelector('#push-dialog');
    if (!bell || !dialog || !('serviceWorker' in navigator)) {
        if (bell) {
            bell.hidden = !('Notification' in window);
        }
        return;
    }

    const form = document.querySelector('#push-form');
    const feedback = document.querySelector('#push-feedback');
    const unsubscribeButton = document.querySelector('#push-unsubscribe');
    const appData = JSON.parse(document.querySelector('#app-data')?.textContent ?? '{}');

    const teamSelect = document.querySelector('#push-teams');
    if (teamSelect) {
        for (const team of (appData.teams ?? []).filter((t) => t.aktiv)) {
            teamSelect.add(new Option(`${team.name} (${team.bereich})`, String(team.id)));
        }
    }

    const base64ToUint8 = (base64) => {
        const padded = base64.padEnd(base64.length + ((4 - (base64.length % 4)) % 4), '=')
            .replaceAll('-', '+').replaceAll('_', '/');
        return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
    };

    bell.addEventListener('click', async () => {
        navigator.sendBeacon?.('/api/stat', new Blob(
            [JSON.stringify({ metrik: 'push_abo_dialog' })],
            { type: 'application/json' },
        ));
        feedback.textContent = '';
        const registration = await navigator.serviceWorker.ready;
        unsubscribeButton.hidden = (await registration.pushManager.getSubscription()) === null;
        dialog.showModal();
    });
    document.querySelector('#push-cancel').addEventListener('click', () => dialog.close());

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        feedback.className = '';
        feedback.textContent = 'Richte Benachrichtigungen ein …';

        try {
            const kategorien = [...form.querySelectorAll('input[name="kategorien"]:checked')].map((el) => el.value);
            if (kategorien.length === 0) {
                throw new Error('Bitte mindestens eine Kategorie wählen.');
            }

            if (await Notification.requestPermission() !== 'granted') {
                throw new Error('Benachrichtigungen wurden im Browser nicht erlaubt.');
            }

            const vapidResponse = await fetch('/api/push/vapid');
            const { public_key: publicKey } = await vapidResponse.json();

            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64ToUint8(publicKey),
            });

            const result = await VK.post('/api/push/subscribe', {
                ...subscription.toJSON(),
                kategorien,
                team_ids: teamSelect ? [...teamSelect.selectedOptions].map((o) => Number(o.value)) : [],
            });
            if (!result.ok) {
                throw new Error(VK.fehlerText(result.data));
            }

            feedback.className = 'flash';
            feedback.textContent = 'Benachrichtigungen sind aktiv.';
            unsubscribeButton.hidden = false;
        } catch (error) {
            feedback.className = 'error-message';
            feedback.textContent = error.message;
        }
    });

    unsubscribeButton.addEventListener('click', async () => {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
            await VK.post('/api/push/unsubscribe', { endpoint: subscription.endpoint }).catch(() => null);
            await subscription.unsubscribe();
        }
        feedback.className = 'flash';
        feedback.textContent = 'Benachrichtigungen abbestellt.';
        unsubscribeButton.hidden = true;
    });
})();
