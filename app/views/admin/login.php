<section class="narrow">
    <h2>Anmelden</h2>
    <?php if (($error ?? null) !== null): ?>
        <p class="error-message"><?= e($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/admin/login">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>
            Benutzername
            <input type="text" name="username" required autofocus autocomplete="username">
        </label>
        <label>
            Passwort
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button type="submit">Anmelden</button>
    </form>
</section>
