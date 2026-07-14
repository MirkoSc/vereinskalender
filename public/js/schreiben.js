// Public write helper (CLAUDE.md section 6): asks for a name on the first
// write attempt, stores it in localStorage and sends it with every write.
// The CSRF token comes from /api/csrf (the only endpoint starting a session
// for public visitors).

window.VK = (() => {
    let csrfToken = null;

    const getName = () => localStorage.getItem('editor_name') || '';

    const ensureName = () => new Promise((resolve, reject) => {
        const existing = getName();
        if (existing !== '') {
            resolve(existing);
            return;
        }

        const dialog = document.querySelector('#name-dialog');
        const form = dialog.querySelector('#name-form');
        const cancelButton = dialog.querySelector('#name-cancel');

        const cleanup = () => {
            form.removeEventListener('submit', onSubmit);
            cancelButton.removeEventListener('click', onCancel);
        };
        const onSubmit = (event) => {
            event.preventDefault();
            const name = String(new FormData(form).get('editor_name') || '').trim();
            if (name === '') {
                return;
            }
            localStorage.setItem('editor_name', name);
            cleanup();
            dialog.close();
            resolve(name);
        };
        const onCancel = () => {
            cleanup();
            dialog.close();
            reject(new Error('Namenseingabe abgebrochen'));
        };

        form.addEventListener('submit', onSubmit);
        cancelButton.addEventListener('click', onCancel);
        dialog.showModal();
    });

    const csrf = async () => {
        if (csrfToken === null) {
            const response = await fetch('/api/csrf');
            csrfToken = (await response.json()).token;
        }
        return csrfToken;
    };

    // Resolves to { ok, status, data }; rejects only if the user cancels
    // the name dialog. Writing is blocked offline (no offline write queue,
    // CLAUDE.md section 9).
    const post = async (url, data = {}) => {
        if (!navigator.onLine) {
            return {
                ok: false,
                status: 0,
                data: { fehler: { offline: 'Du bist offline – Änderungen sind erst wieder mit Internetverbindung möglich.' } },
            };
        }
        const name = await ensureName();
        const token = await csrf();
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
            body: JSON.stringify({ ...data, editor_name: name }),
        });
        const json = await response.json().catch(() => ({}));
        return { ok: response.ok, status: response.status, data: json };
    };

    const fehlerText = (data) => {
        if (data.konflikte) {
            return data.konflikte.join(' ');
        }
        if (data.fehler) {
            return Object.values(data.fehler).join(' ');
        }
        return 'Unbekannter Fehler.';
    };

    return { getName, ensureName, csrf, post, fehlerText };
})();
