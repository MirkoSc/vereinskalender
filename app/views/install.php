<section class="narrow">
    <h2>Vereinskalender installieren</h2>

    <?php if (($fertig ?? false) === true): ?>
        <p class="flash">
            Installation abgeschlossen! Melde dich jetzt mit den Bootstrap-Zugangsdaten an –
            beim ersten Login legst du das echte Admin-Konto an.
        </p>
        <p><a class="button" href="/admin/login">Zum Admin-Login</a></p>
    <?php elseif (($restore ?? false) === true): ?>
        <h3>Backup wird eingespielt …</h3>
        <progress id="restore-bar" max="100" value="0"></progress>
        <p id="restore-status" aria-live="polite">Import läuft …</p>
        <script>
            (async () => {
                const bar = document.querySelector('#restore-bar');
                const status = document.querySelector('#restore-status');
                try {
                    let done = false;
                    while (!done) {
                        const response = await fetch('/install/restore-step', { method: 'POST' });
                        const data = await response.json();
                        if (!response.ok) {
                            throw new Error(data.fehler || `HTTP ${response.status}`);
                        }
                        if (data.fertig) {
                            done = true;
                            bar.value = 100;
                            status.textContent = `Fertig – ${data.migrationen} Migration(en) nachgezogen. Weiter zum Login …`;
                            window.location.href = data.weiter;
                        } else {
                            bar.value = Math.round((data.offset / data.gesamt) * 100);
                            status.textContent = `${data.offset} von ${data.gesamt} Anweisungen importiert …`;
                        }
                    }
                } catch (error) {
                    status.textContent = 'Fehler beim Import: ' + error.message;
                }
            })();
        </script>
    <?php else: ?>
        <p>
            Datenbank-Zugangsdaten eintragen und wählen, ob frisch installiert oder ein
            Backup eingespielt werden soll. Die Bootstrap-Zugangsdaten gelten nur für den
            allerersten Admin-Login.
        </p>
        <?php if (isset($errors['db'])): ?><p class="error-message"><?= e($errors['db']) ?></p><?php endif; ?>
        <form method="post" action="/install" enctype="multipart/form-data">
            <h3>Datenbank</h3>
            <label>Host <input type="text" name="db_host" value="<?= e($values['db_host'] ?? 'localhost') ?>" required></label>
            <label>Port <input type="number" name="db_port" value="<?= e($values['db_port'] ?? '3306') ?>"></label>
            <label>Datenbankname <input type="text" name="db_name" value="<?= e($values['db_name'] ?? '') ?>" required></label>
            <label>Benutzer <input type="text" name="db_user" value="<?= e($values['db_user'] ?? '') ?>" required></label>
            <label>Passwort <input type="password" name="db_password"></label>

            <h3>Bootstrap-Admin</h3>
            <label>Benutzername
                <input type="text" name="admin_username" value="<?= e($values['admin_username'] ?? 'admin') ?>" required>
                <?php if (isset($errors['admin_username'])): ?><span class="field-error"><?= e($errors['admin_username']) ?></span><?php endif; ?>
            </label>
            <label>Passwort (mind. 12 Zeichen)
                <input type="password" name="admin_password" required>
                <?php if (isset($errors['admin_password'])): ?><span class="field-error"><?= e($errors['admin_password']) ?></span><?php endif; ?>
            </label>

            <h3>Modus</h3>
            <label class="checkbox">
                <input type="radio" name="modus" value="frisch" <?= ($values['modus'] ?? 'frisch') !== 'restore' ? 'checked' : '' ?>>
                Frische Installation (alle Migrationen ab 0)
            </label>
            <label class="checkbox">
                <input type="radio" name="modus" value="restore" <?= ($values['modus'] ?? '') === 'restore' ? 'checked' : '' ?>>
                Backup einspielen (ZIP aus dem Admin-Backup)
            </label>
            <label>Backup-ZIP
                <input type="file" name="backup" accept=".zip">
                <?php if (isset($errors['backup'])): ?><span class="field-error"><?= e($errors['backup']) ?></span><?php endif; ?>
            </label>

            <button type="submit" class="button">Installation starten</button>
        </form>
    <?php endif; ?>
</section>
