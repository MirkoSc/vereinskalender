<section class="narrow">
    <h2>Ersten Admin anlegen</h2>
    <p>
        Die Bootstrap-Zugangsdaten aus der Konfiguration sind nur gültig, solange kein
        Admin-Konto existiert. Lege jetzt das echte Admin-Konto an.
    </p>
    <form method="post" action="/admin/setup">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>
            Benutzername
            <input type="text" name="username" value="<?= e($values['username'] ?? '') ?>" required autofocus>
            <?php if (isset($errors['username'])): ?><span class="field-error"><?= e($errors['username']) ?></span><?php endif; ?>
        </label>
        <label>
            Passwort (mind. 12 Zeichen)
            <input type="password" name="password" required autocomplete="new-password">
            <?php if (isset($errors['password'])): ?><span class="field-error"><?= e($errors['password']) ?></span><?php endif; ?>
        </label>
        <label>
            Passwort wiederholen
            <input type="password" name="password_repeat" required autocomplete="new-password">
            <?php if (isset($errors['password_repeat'])): ?><span class="field-error"><?= e($errors['password_repeat']) ?></span><?php endif; ?>
        </label>
        <button type="submit">Admin anlegen</button>
    </form>
</section>
