<!-- Issue #38: Overlay über der aktuellen Kalenderansicht - natives
     <dialog class="sheet"> wie die übrigen Dialoge (mobil Bottom-Sheet,
     Desktop Panel), gefüllt von derselben Legende-Komponente wie Startseite
     und /legende (public/js/legende.js füllt jedes [data-legende]). Escape
     schließt nativ; Klick auf den Hintergrund schließt zusätzlich per JS
     (von <dialog> nicht von selbst geboten, hier aber gefordert). -->
<dialog id="legende-dialog" class="sheet legende-sheet" aria-labelledby="legende-dialog-title">
    <h3 id="legende-dialog-title">Legende</h3>
    <div class="legende" data-legende></div>
    <div class="dialog-actions">
        <button type="button" class="linklike" id="legende-dialog-close">Schließen</button>
    </div>
</dialog>
