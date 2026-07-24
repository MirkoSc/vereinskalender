// Public calendar (Issue #37: merged Platzbelegung + Spielplan) built on
// FullCalendar. Four Darstellungen (Tag/Woche/Monat/Liste) via
// VKKalenderAnsicht; team- and venue color render simultaneously as two dots
// on every event (Issue #39) - pure frontend, the API always delivers both
// color fields.

(() => {
    const appData = JSON.parse(document.querySelector('#app-data').textContent);

    const activeTeams = appData.teams.filter((t) => t.aktiv);
    // Bereich-Name je Team (Issue #27: appData.bereiche statt appData.teams[].bereich)
    const bereichName = (bereichId) => appData.bereiche.find((b) => b.id === bereichId)?.name ?? `Bereich #${bereichId}`;
    const teamBereichName = (team) => (team.bereich_id !== null ? bereichName(team.bereich_id) : '–');

    const beacon = (metrik) => navigator.sendBeacon?.(
        '/api/stat',
        new Blob([JSON.stringify({ metrik })], { type: 'application/json' }),
    );

    // ---- filter controls (Issue #8: Filter-Button + Panel/Bottom-Sheet,
    // Chips nur für Abweichungen vom Default, URL teilbar; Issue #82: jeder
    // Filter im Sheet ist selbst eine Chip-Gruppe statt eines <select> -
    // ein Tap wählt/wechselt/löscht direkt, analog den Arten-Chips (Issue
    // #63). window.VKFilter.erzeugeChipRow/aktualisiereChipRow (filter.js)
    // sind die geteilte DOM-Logik dafür, Optionen und Wiring bleiben hier je
    // Filter.) ----

    // Issue #86: Team/Bereich/Spielstätte/Platz sind Mehrfachauswahl-Filter
    // wie die Arten (Issue #63) - kommaseparierte Liste, ''=alle. Jede
    // <key>SingleLabel-Funktion löst dafür EINEN Token auf; mehrfachLabel()
    // joint mehrere zu einem Chip-Text ("Team: E2, Herren I").
    const teamSingleLabel = (wert) => activeTeams.find((t) => String(t.id) === wert)?.name ?? wert;
    const bereichSingleLabel = (wert) => bereichName(Number(wert));
    const venueSingleLabel = (wert) => {
        if (wert === 'heim') return 'Nur Heim';
        if (wert === 'auswaerts') return 'Nur Auswärts';
        if (wert === 'spielfrei') return 'Nur Spielfrei';
        return appData.venues.find((v) => String(v.id) === wert)?.name ?? `Ort #${wert}`;
    };
    const pitchSingleLabel = (id) => appData.pitches.find((p) => String(p.id) === id)?.name ?? `Platz #${id}`;
    const mehrfachLabel = (wert, singleLabel) => String(wert ?? '').split(',').filter((w) => w !== '')
        .map(singleLabel).join(', ');

    // Issue #63: Labels der Sportheim-Termin-Arten kommen aus appData
    // (PublicController::stammdaten() reicht das PHP-Enum durch) - kein
    // zweiter Pflegeort im Frontend, offline identisch verfügbar.
    const vermietungArten = appData.vermietungArten ?? [];
    const artName = (wert) => vermietungArten.find((a) => a.wert === wert)?.label ?? wert;
    const artHinweis = (wert) => vermietungArten.find((a) => a.wert === wert)?.hinweis
        ?? 'Sportheim belegt';

    // Platzfilter gilt in jeder Ansicht (Issue #6/#11/#37) - in den
    // Ressourcen-Views (Tag/Woche, breit) reduziert er die Platz-Spalten,
    // sonst filtert er die Termine direkt (applyPitchFilter).
    const filterDefinitionen = [
        { key: 'team', default: '', label: (wert) => `Team: ${mehrfachLabel(wert, teamSingleLabel)}` },
        { key: 'bereich', default: '', label: (wert) => `Bereich: ${mehrfachLabel(wert, bereichSingleLabel)}` },
        { key: 'venue', default: '', label: (wert) => `Ort: ${mehrfachLabel(wert, venueSingleLabel)}` },
        { key: 'pitch', default: '', label: (wert) => `Platz: ${mehrfachLabel(wert, pitchSingleLabel)}` },
        { key: 'manuell', default: '', label: (wert) => (wert === 'nur' ? 'Nur manuelle Termine' : 'Ohne manuelle Termine') },
        { key: 'vermietung', default: '', label: (wert) => (wert === 'nur' ? 'Nur Sportheim-Termine' : 'Ohne Sportheim-Termine') },
        // Issue #63: schränkt NUR die Sportheim-Termine auf einzelne Arten
        // ein (kommaseparierte Mehrfachauswahl). Eigener Filter statt weiterer
        // Stufen von `vermietung`, damit dessen alte Werte ''/'ohne'/'nur'
        // ihre Bedeutung behalten und geteilte Alt-Links unverändert wirken.
        { key: 'art', default: '', label: (wert) => `Sportheim-Termine: ${mehrfachLabel(wert, artName)}` },
        // Issue #56: dreistufiger Termintyp-Filter, rein clientseitig wie
        // manuell/vermietung - /api/events kennt ihn nicht (s. baueEventsParams).
        { key: 'typ', default: '', label: (wert) => (wert === 'spiel' ? 'Nur Spiele' : 'Nur Trainings') },
    ];

    const urlParams = new URLSearchParams(window.location.search);
    const filters = window.VKFilter.leseFilterAusUrl(urlParams, filterDefinitionen);
    // Issue #27: alte geteilte Links trugen den Bereich als einzelnen
    // Enum-String (G/F/E/D/C/Herren) statt der numerischen bereich_id -
    // einmalig beim Laden auf die ID normalisieren, damit die Bereich-Chips
    // UND clientseitiger Offline-Filter (der die ID vergleicht) den Link
    // weiter verstehen. Issue #86: bereich ist jetzt eine kommaseparierte
    // Mehrfachauswahl - pro Token auflösen statt den ganzen Wert als einen
    // Enum-String zu behandeln, sonst würde z. B. "1,2" fälschlich als
    // unbekanntes Kürzel verworfen.
    if (filters.bereich !== '') {
        filters.bereich = filters.bereich.split(',').filter((w) => w !== '').map((token) => {
            if (/^\d+$/.test(token)) {
                return token;
            }
            const legacy = appData.bereiche.find((b) => b.kuerzel === token);
            return legacy ? String(legacy.id) : null;
        }).filter((token) => token !== null).join(',');
    }
    if (!urlParams.has('pitch')) {
        // vor Issue #8 wurde der Platzfilter nur in localStorage gehalten;
        // ohne URL-Wert bleibt das bisherige Verhalten erhalten. Issue #37:
        // neuer Key `kalender_platz` statt des seitenspezifischen
        // `belegung_platz` - der Alt-Key bleibt eine Version als Lesefallback
        // bestehen (Migrationskonvention, CLAUDE.md Abschnitt 9).
        filters.pitch = localStorage.getItem('kalender_platz') ?? localStorage.getItem('belegung_platz') ?? '';
    }

    const filterDialog = document.querySelector('#filter-dialog');
    const filterChips = document.querySelector('#filter-chips');
    const filterBadge = document.querySelector('#filter-badge');
    // Issue #56: Hinweis statt stumm leerer Ansicht, wenn die aktiven Filter
    // (egal welche Kombination - z. B. "Nur Spiele" + "Nur manuelle") kein
    // einziges Ergebnis liefern. Bleibt aus, wenn ein Zeitraum ganz ohne
    // Filter leer ist (spielfreie Zeit ist kein Filter-Effekt).
    const kalenderLeerHinweis = document.querySelector('#kalender-leer-hinweis');
    const aktualisiereLeerHinweis = (gefilterteEvents) => {
        if (!kalenderLeerHinweis) {
            return;
        }
        const abweichungen = window.VKFilter.aktiveAbweichungen(filters, filterDefinitionen);
        if (gefilterteEvents.length > 0 || abweichungen.length === 0) {
            kalenderLeerHinweis.hidden = true;
            return;
        }
        kalenderLeerHinweis.textContent = `Keine Termine für die aktiven Filter (${abweichungen.map((a) => a.text).join(', ')}).`;
        kalenderLeerHinweis.hidden = false;
    };

    const aktualisiereUrl = () => {
        const query = window.VKFilter.schreibeUrlParams(filters, filterDefinitionen).toString();
        history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : ''));
    };

    // Issue #82/#86: eine Chip-Gruppe je Filter; `mehrfach` unterscheidet die
    // Mehrfachauswahl-Filter (Team/Bereich/Spielstätte/Platz, Issue #86, und
    // die Arten, Issue #63 - mehrere gleichzeitig aktiv, kommasepariert) von
    // den übrigen Zustands-Filtern (Termintyp/Manuell/Sportheim-Termine:
    // genau ein oder kein Chip aktiv). Die Liste treibt
    // aktualisiereChipGruppen() - den aria-pressed-Abgleich nach jeder
    // Filteränderung, egal ob per Chip-Klick, Chip-Entfernen in der
    // Aktive-Filter-Zeile oder #filter-reset.
    const artRow = document.querySelector('#filter-art-row');
    const chipGruppen = [
        { key: 'team', container: document.querySelector('#filter-team-chips'), mehrfach: true },
        { key: 'bereich', container: document.querySelector('#filter-bereich-chips'), mehrfach: true },
        { key: 'venue', container: document.querySelector('#filter-venue-chips'), mehrfach: true },
        { key: 'pitch', container: document.querySelector('#filter-pitch-chips'), mehrfach: true },
        { key: 'typ', container: document.querySelector('#filter-typ-chips') },
        { key: 'manuell', container: document.querySelector('#filter-manuell-chips') },
        { key: 'vermietung', container: document.querySelector('#filter-vermietung-chips') },
        { key: 'art', container: document.querySelector('#filter-art-chips'), mehrfach: true },
    ];
    const aktualisiereChipGruppen = () => {
        for (const gruppe of chipGruppen) {
            if (!gruppe.container) {
                continue;
            }
            if (gruppe.mehrfach) {
                const aktiv = filters[gruppe.key].split(',').filter((a) => a !== '');
                window.VKFilter.aktualisiereChipRow(gruppe.container, (wert) => aktiv.includes(wert));
            } else {
                window.VKFilter.aktualisiereChipRow(gruppe.container, (wert) => wert === filters[gruppe.key]);
            }
        }
        // "Ohne Sportheim-Termine" hat bereits alle ausgeblendet - eine
        // Art-Auswahl wäre dort gegenstandslos.
        if (artRow) {
            artRow.hidden = filters.vermietung === 'ohne';
        }
    };

    const renderFilterUi = () => {
        const abweichungen = window.VKFilter.aktiveAbweichungen(filters, filterDefinitionen);
        aktualisiereChipGruppen();
        filterChips.replaceChildren();
        for (const chip of abweichungen) {
            const li = document.createElement('li');
            li.className = 'chip';
            const text = document.createElement('span');
            text.textContent = chip.text;
            const entfernen = document.createElement('button');
            entfernen.type = 'button';
            entfernen.className = 'chip-remove';
            entfernen.setAttribute('aria-label', `Filter „${chip.text}" entfernen`);
            entfernen.textContent = '×';
            entfernen.addEventListener('click', () => setzeFilter(chip.key, ''));
            li.append(text, entfernen);
            filterChips.append(li);
        }
        filterBadge.textContent = String(abweichungen.length);
        filterBadge.hidden = abweichungen.length === 0;
        aktualisiereUrl();
    };

    const onFilterChange = () => {
        beacon('filternutzung');
        localStorage.setItem('kalender_platz', filters.pitch);
        renderFilterUi();
        // Issue #57: unbedingt - die Spaltenliste hängt am Platzfilter, nicht
        // an der Breite (s. aktuelleRessourcen). Der frühere Breiten-Vorbehalt
        // konnte eine veraltete Liste stehen lassen, sobald die Breite sich
        // zwischen zwei Filterwechseln geändert hatte.
        calendar.refetchResources();
        if (modus === 'liste') {
            listeFilterGeaendert();
            return;
        }
        calendar.refetchEvents();
    };

    const setzeFilter = (key, wert) => {
        filters[key] = wert;
        onFilterChange();
    };
    // Einfachauswahl-Filter (Termintyp/Manuell/Sportheim-Termine): ein Klick
    // auf den bereits aktiven Chip setzt den Filter zurück auf den Default
    // (''), ein Klick auf einen anderen ersetzt die Auswahl - kein
    // Extra-"Alle"-Chip nötig.
    const setzeEinfachFilter = (key, wert) => setzeFilter(key, filters[key] === wert ? '' : wert);
    // Mehrfachauswahl-Filter (Team/Bereich/Spielstätte/Platz, Issue #86, und
    // die Arten, Issue #63): ein Klick schaltet den Wert in der komma-
    // separierten Liste um. `kanonischeReihenfolge` sortiert das Ergebnis in
    // einer festen Reihenfolge (unabhängig von der Klick-Reihenfolge), damit
    // derselbe Auswahl-Zustand immer denselben teilbaren Link ergibt.
    const gewaehlteWerte = (key) => filters[key].split(',').filter((w) => w !== '');
    const toggleMehrfachFilter = (key, wert, kanonischeReihenfolge) => {
        const aktiv = gewaehlteWerte(key);
        const neu = aktiv.includes(wert) ? aktiv.filter((w) => w !== wert) : [...aktiv, wert];
        setzeFilter(key, kanonischeReihenfolge.filter((w) => neu.includes(w)).join(','));
    };

    const teamWerte = activeTeams.map((t) => String(t.id));
    window.VKFilter.erzeugeChipRow(
        document.querySelector('#filter-team-chips'),
        activeTeams.map((t) => ({ wert: String(t.id), label: `${t.name} (${teamBereichName(t)})` })),
        (wert) => toggleMehrfachFilter('team', wert, teamWerte),
    );
    const bereichWerte = appData.bereiche.map((b) => String(b.id));
    window.VKFilter.erzeugeChipRow(
        document.querySelector('#filter-bereich-chips'),
        appData.bereiche.map((b) => ({ wert: String(b.id), label: b.name })),
        (wert) => toggleMehrfachFilter('bereich', wert, bereichWerte),
    );
    const venueWerte = ['heim', 'auswaerts', 'spielfrei', ...appData.venues.map((v) => String(v.id))];
    window.VKFilter.erzeugeChipRow(
        document.querySelector('#filter-venue-chips'),
        [
            { wert: 'heim', label: 'Nur Heim' },
            { wert: 'auswaerts', label: 'Nur Auswärts' },
            { wert: 'spielfrei', label: 'Nur Spielfrei' },
            ...appData.venues.map((v) => ({ wert: String(v.id), label: v.name })),
        ],
        (wert) => toggleMehrfachFilter('venue', wert, venueWerte),
    );

    // Issue #56: zusätzlich zur generischen 'filternutzung' (onFilterChange)
    // je gewählter Stufe eine eigene Metrik - analog den Ansicht-Metriken -
    // damit das Dashboard "Nur Spiele" von "Nur Trainings" unterscheiden kann.
    // Kein Beacon beim Zurücksetzen auf "Alle" (kein Feature-"Nutzen").
    window.VKFilter.erzeugeChipRow(
        document.querySelector('#filter-typ-chips'),
        [
            { wert: 'spiel', label: 'Nur Spiele' },
            { wert: 'training', label: 'Nur Trainings' },
        ],
        (wert) => {
            if (filters.typ !== wert) {
                beacon(wert === 'spiel' ? 'filter_typ_spiel' : 'filter_typ_training');
            }
            setzeEinfachFilter('typ', wert);
        },
    );

    // Issue #12: manuell erfasste Spiele (Freundschaftsspiele, Turniere)
    // ein-/ausblenden bzw. isoliert anzeigen; rein clientseitig wie der
    // Platzfilter, das API-Feld "manuell" trägt das Kalenderteam.
    window.VKFilter.erzeugeChipRow(
        document.querySelector('#filter-manuell-chips'),
        [
            { wert: 'ohne', label: 'Ohne manuelle Termine' },
            { wert: 'nur', label: 'Nur manuelle Termine' },
        ],
        (wert) => setzeEinfachFilter('manuell', wert),
    );

    // Issue #36: Sportheim-Termine ein-/ausblenden bzw. isoliert anzeigen;
    // rein clientseitig wie der Manuell-Filter, funktioniert dadurch auch
    // offline (das Feld ist im Bundle enthalten).
    window.VKFilter.erzeugeChipRow(
        document.querySelector('#filter-vermietung-chips'),
        [
            { wert: 'ohne', label: 'Ohne Sportheim-Termine' },
            { wert: 'nur', label: 'Nur Sportheim-Termine' },
        ],
        (wert) => setzeEinfachFilter('vermietung', wert),
    );

    // Issue #63: Art-Chips (Mehrfachauswahl) zu den Sportheim-Terminen. Die
    // Reihe wird aus appData gerendert, damit eine neue Art im PHP-Enum ohne
    // Frontend-Änderung erscheint.
    const artWerte = vermietungArten.map((a) => a.wert);
    window.VKFilter.erzeugeChipRow(
        document.querySelector('#filter-art-chips'),
        vermietungArten.map((art) => ({ wert: art.wert, label: art.label })),
        (wert) => toggleMehrfachFilter('art', wert, artWerte),
    );

    // Platzfilter (Issue #6/#11/#37: immer sichtbar; Issue #86: Mehrfach-
    // auswahl wie Team/Bereich/Spielstätte) - ein zwischenzeitlich entfernter/
    // deaktivierter Platz würde sonst keinen Chip mehr treffen, während
    // filters.pitch noch die veraltete id trägt, und dadurch still jeden
    // Termin herausfiltern.
    const gueltigePitchWerte = gewaehlteWerte('pitch').filter((w) => appData.pitches.some((p) => String(p.id) === w));
    filters.pitch = gueltigePitchWerte.join(',');
    const pitchWerte = appData.pitches.map((p) => String(p.id));
    window.VKFilter.erzeugeChipRow(
        document.querySelector('#filter-pitch-chips'),
        appData.pitches.map((p) => ({ wert: String(p.id), label: `${p.name} (${p.venue_name})` })),
        (wert) => {
            beacon('platzauswahl');
            toggleMehrfachFilter('pitch', wert, pitchWerte);
        },
    );

    document.querySelector('#filter-button').addEventListener('click', () => filterDialog.showModal());
    document.querySelector('#filter-close').addEventListener('click', () => filterDialog.close());
    document.querySelector('#filter-reset').addEventListener('click', () => {
        for (const def of filterDefinitionen) {
            filters[def.key] = def.default;
        }
        onFilterChange();
    });

    renderFilterUi();

    // ---- pitch grouping (Issue #6/#11/#37: Platzfilter-Chips immer
    // sichtbar, unabhängig von Ansicht/Breite - Aufbau weiter oben bei den
    // übrigen Filter-Chips) ----
    // Ab der Desktop-Sidebar-Schwelle (~1100px) zeigen Tag/Woche eigene
    // Platz-Spalten (Ressourcen-Views) - dort reduziert eine Einzelplatz-Wahl
    // die Spalten (s. aktuelleRessourcen() weiter unten) statt die Termine zu
    // filtern. In jeder anderen Kombination (Monat, Liste, schmale Tag/
    // Woche) ersetzt "Alle Plätze" (Hintergrundfarbe+Kürzel-Präfix) bzw. ein
    // gefilterter Einzelplatz die fehlenden Spalten. Client-side only:
    // /api/events hat keinen Platzfilter, jedes Event trägt pitch_id/
    // pitch_farbe/pitch_name/pitch_kuerzel bereits im Events-Feed.
    // Issue #57: LIVE gelesen, nicht mehr einmalig gesnapshottet. Der alte
    // Snapshot überlebte das Ziehen der Fensterbreite über die Schwelle -
    // FullCalendar re-renderte, aber View-Wahl, Ressourcen-Spalten und die
    // Platzfarb-Regel blieben auf dem Wert vom Seitenaufruf stehen.
    const breitMedia = window.matchMedia('(min-width: 1100px)');
    const istBreit = () => breitMedia.matches;
    const pitchGruppierungAktiv = () => window.VKKalenderPitch.pitchGruppierungAktiv(
        window.VKKalenderAnsicht.hatResourceSpalten(modus, istBreit()),
        filters.pitch,
    );

    // Issue #57: die eine Stelle, die "Darstellung × Breite × Platzfilter"
    // auswertet - IMMER frisch beim Rendern eines Termins aufgerufen, nie
    // vorberechnet (s. Kommentar bei eventContent/eventDidMount).
    const platzFarbDarstellung = () => window.VKKalenderPitch.platzFarbDarstellung(
        modus,
        window.VKKalenderAnsicht.hatResourceSpalten(modus, istBreit()),
        filters.pitch,
    );

    // Synthetische Ressourcen-Spalte für Spiele ohne pitch_id (Issue #37:
    // Auswärtsspiele, seltene Heimspiele mit offenem Platz) - geteilte
    // Konstante zwischen aktuelleRessourcen() und toFcEvent(), da Events ohne
    // bekannte resourceId in Ressourcen-Views sonst lautlos verschwinden.
    const RESOURCE_AUSWAERTS_ID = 'auswaerts';
    // Issue #36: eigene synthetische Spalte für Vermietungen (kein Platz,
    // sondern das Sportheim) - analog RESOURCE_AUSWAERTS_ID.
    const RESOURCE_SPORTHEIM_ID = 'sportheim';
    // Issue #65: Spielfrei-Termine haben ebenfalls keine pitch_id, dürfen
    // aber nie in der Auswärts-Spalte landen - eigene synthetische Spalte,
    // analog RESOURCE_SPORTHEIM_ID.
    const RESOURCE_SPIELFREI_ID = 'spielfrei';

    // ---- calendar ----

    // Sperrungen behalten ihre Art-Farbe (gesperrt/eingeschränkt). Sie hängt
    // AUSSCHLIESSLICH am Termin selbst, nie an Darstellung/Breite/Filter -
    // deshalb darf sie als einzige Farbe im Event-Datensatz mitgeliefert
    // werden (toFcEvent) und kann beim Darstellungswechsel nicht veralten.
    const sperrungColor = (props) => (
        // same CSS custom properties as app.css, not a second literal (Issue #1)
        props.art === 'gesperrt' ? 'var(--color-danger)' : 'var(--color-warning)'
    );

    // Die Platzfarbe am Termin (Issue #6/#11). Rein aus den Event-Props -
    // OB und WIE sie erscheint, entscheidet platzFarbDarstellung() beim
    // Rendern. Sperrungen (eigene Art-Farbe) und Vermietungen (kein Platz,
    // eigener Look über .ev-vermietung in app.css) haben nie eine.
    const platzFarbe = (props) => (
        props.typ === 'sperrung' || props.typ === 'vermietung'
            ? null
            : window.VKKalenderPitch.pitchEventFarbe(props)
    );

    // "Alle Plätze" (Issue #6/#11): Farbe allein reicht nicht (Farbe nie
    // einziges Signal) - Platz-Kürzel bzw. "Auswärts" als Text vor den Titel.
    // Issue #58: eigenes Element statt in den Titel-String verwoben, damit
    // CSS Präfix und Titel unabhängig voneinander beschneiden kann
    // (Kürzungsreihenfolge, s. eventContent/app.css).
    const eventPraefix = (props) => (
        pitchGruppierungAktiv() ? window.VKKalenderPitch.pitchEventPraefix(props) : null
    );

    // Issue #58: der vollständige, unbeschnittene Text fürs title-/
    // aria-label - unabhängig davon, was CSS am Ende per Ellipsis kürzt oder
    // bei extremer Enge ganz weglässt (Farbe ist nie das einzige Signal,
    // CLAUDE.md Abschnitt 8).
    const eventVollerTitel = (props) => {
        const praefix = eventPraefix(props);
        return praefix ? `${praefix}: ${props.titel}` : props.titel;
    };

    // Spielstätten-Name für den Titel/Tooltip des zweiten Farbpunkts (Issue
    // #39): Spiele tragen venue_name bereits im Payload; Belegungen nicht
    // (dort ist die Spielstätte immer eine eigene, per venue_id bekannte
    // Heimspielstätte) - Fallback über appData.venues. Ohne venue_id (nie
    // bei Belegungen, nur bei Auswärtsspielen) greift die Auswärtsfarbe
    // bereits serverseitig (EventSerializer); hier nur noch das Label dazu.
    const venueName = (props) => {
        if (props.spielfrei) {
            return 'Spielfrei';
        }
        if (props.venue_id === null) {
            return 'Auswärts';
        }
        return props.venue_name ?? appData.venues.find((v) => v.id === props.venue_id)?.name ?? 'Spielstätte';
    };

    // Ein Farbpunkt - Form macht die Bedeutung (app.css, Legende Issue #38),
    // das Label wiederholt sie für Screenreader und Tooltip.
    const punkt = (klasse, farbe, label) => {
        const el = document.createElement('span');
        el.className = `ev-punkt ${klasse}`;
        el.style.backgroundColor = farbe;
        el.setAttribute('role', 'img');
        el.setAttribute('aria-label', label);
        el.title = label;
        return el;
    };

    // Zwei Farbpunkte (Team + Spielstätte) statt des früheren Umschalters
    // (Issue #39): fest in dieser Reihenfolge, an jedem Termin gleichzeitig
    // sichtbar, unabhängig von Ansicht/Breite - auch in der Terminliste
    // (Issue #40 galt nur für den alten Ein-Farbe-Modus). Sperrungen haben
    // kein Team (indikatorFarben liefert dafür null) und bleiben unverändert
    // bei ihrer Art-Farbe. Reihenfolge ist an die künftige Legende (Issue
    // #34) gebunden - hier nicht ändern, ohne dort mitzuziehen. Im Monat
    // kommt ein dritter Punkt für den Platz dazu (Issue #57, s. u.).
    const eventPunkte = (props) => {
        const farben = window.VKKalenderFarbe.indikatorFarben(props);
        if (!farben) {
            return null;
        }
        const punkte = document.createElement('span');
        punkte.className = 'ev-punkte';

        // Issue #36: Vermietungen haben kein Team - nur der Spielstätten-Punkt
        if (farben.team !== null) {
            punkte.append(punkt('ev-punkt-team', farben.team, `Team: ${props.team_name ?? ''}`));
        }

        punkte.append(punkt('ev-punkt-venue', farben.venue, `Spielstätte: ${venueName(props)}`));

        // Issue #57: dritter Punkt NUR im Monat - dort rendert FullCalendar
        // zeitgebundene Termine als Dot-Events ohne Block-Fläche, ein
        // Hintergrund in Platzfarbe käme also nie an (verifiziert:
        // computedBg rgba(0,0,0,0)). Das Text-Präfix mit dem Platz-Kürzel
        // bleibt davon unberührt - Farbe ist nie das einzige Signal
        // (CLAUDE.md Abschnitt 8). Form wie der Spielstätten-Punkt
        // (Quadrat), passend zur Legende (Issue #38).
        const platz = platzFarbe(props);
        if (platz !== null && platzFarbDarstellung() === 'punkt') {
            const label = window.VKKalenderPitch.pitchEventPraefix(props);
            punkte.append(punkt('ev-punkt-pitch', platz, `Platz: ${label ?? 'offen'}`));
        }

        return punkte;
    };

    // Eigener eventContent statt der FullCalendar-Standarddarstellung: nur
    // so lassen sich die zwei Farbpunkte VOR den Titel setzen, in Grid- UND
    // Listenansichten gleichermaßen (Issue #39). Titel/Zeit behalten
    // FullCalendars eigene Klassennamen (fc-event-time/fc-event-title) statt
    // eigener - so greifen bestehende Theme-Regeln unverändert weiter (z. B.
    // die kursive Sperrungs-Beschriftung auf Hintergrund-Events). arg.timeText
    // kommt bereits fertig formatiert von FullCalendar (leer bei Sperrungen/
    // Background-Events); in der Terminliste zeigt FullCalendar die Uhrzeit
    // schon in einer eigenen Spalte, daher hier ausgelassen.
    const eventContent = (arg) => {
        const props = arg.event.extendedProps;
        const wrapper = document.createElement('div');
        wrapper.className = 'ev-inhalt fc-event-main-frame';

        if (arg.timeText && arg.view.type !== 'listNachlade') {
            const zeit = document.createElement('div');
            zeit.className = 'fc-event-time';
            zeit.textContent = arg.timeText;
            wrapper.append(zeit);
        }

        const punkte = eventPunkte(props);
        if (punkte) {
            wrapper.append(punkte);
        }

        // Issue #57: Titel/Präfix hier aus den Props ableiten, NICHT aus
        // arg.event.title - das käme aus dem Event-Datensatz und trüge damit
        // das Platz-Präfix aus der Darstellung, unter der zuletzt GEFETCHT
        // wurde (s. Kommentar bei toFcEvent).
        //
        // Issue #58: Präfix und Titel als getrennte Kind-Elemente statt eines
        // einzelnen Strings - app.css beschneidet sie darüber unabhängig
        // voneinander (Kürzungsreihenfolge: Farbpunkte immer sichtbar, dann
        // schrumpft der Titel per Ellipsis fast allein bis er bei 0 ankommt,
        // erst danach folgt der Präfix, s. flex-shrink-Werte in app.css).
        // Das Wrapper-Element trägt title/aria-label mit dem vollen Text,
        // damit nichts verloren geht, egal was CSS am Ende kürzt.
        const titelWrapper = document.createElement('span');
        titelWrapper.className = 'fc-event-title ev-titel';
        const vollerTitel = eventVollerTitel(props);
        titelWrapper.title = vollerTitel;
        titelWrapper.setAttribute('aria-label', vollerTitel);

        const praefix = eventPraefix(props);
        if (praefix) {
            const praefixEl = document.createElement('span');
            praefixEl.className = 'ev-praefix';
            praefixEl.textContent = `${praefix}:`;
            titelWrapper.append(praefixEl);
        }

        const titelText = document.createElement('span');
        titelText.className = 'ev-titel-text';
        titelText.textContent = props.titel || ' ';
        titelWrapper.append(titelText);

        wrapper.append(titelWrapper);

        // Issue #36: dezenter Hinweis am Termin, wenn sein Platz zu einem
        // gerade belegten Sportheim gehört (voller Hinweis im Detail-Dialog).
        // Issue #63: Wortlaut je Art - "Sportheim vermietet" wäre beim Putzen
        // schlicht falsch. Bei mehreren Überschneidungen unterschiedlicher
        // Arten nennt der Kurztext sie alle.
        const ueberschneidende = window.VKVermietungHinweis.findeUeberschneidende(vermietungenAktuell, props);
        if (ueberschneidende.length > 0) {
            const texte = [...new Set(ueberschneidende.map((v) => artHinweis(v.art ?? 'vermietung')))];
            const hinweis = document.createElement('span');
            hinweis.className = 'ev-vermietung-hinweis';
            hinweis.textContent = '🏠';
            hinweis.setAttribute('role', 'img');
            hinweis.setAttribute('aria-label', texte.join(', '));
            hinweis.title = `${texte.join(', ')} - Nutzung ggf. eingeschränkt`;
            wrapper.append(hinweis);
        }

        return { domNodes: [wrapper] };
    };

    // Der Termin-HINTERGRUND in Platzfarbe (Issue #6/#11) - beim MOUNT des
    // Termin-Elements gesetzt, nicht im Event-Datensatz (Issue #57).
    //
    // Root Cause von Issue #57: Farbe und Titel wurden in toFcEvent()
    // eingebacken, also zum FETCH-Zeitpunkt aus dem damaligen modus/der
    // damaligen Breite berechnet. setzeModus() wechselt aber nur die View -
    // und FullCalendar fetcht mit lazyFetching ausschließlich nach, wenn die
    // neue Range über die geladene HINAUSGEHT (Vendor-Bundle:
    // `t.start<e.fetchRange.start||t.end>e.fetchRange.end`). Jeder Wechsel in
    // eine ENGERE Range (Monat→Woche, Monat→Tag, Woche→Tag, Liste→alles)
    // benutzte die gecachten Event-Objekte weiter und zeigte damit die Farbe
    // der vorherigen Darstellung - reproduziert: Monat→Woche ließ auf Desktop
    // `background-color: rgb(26,127,55)` in der Ressourcen-Wochenansicht
    // stehen, wo die Spalte den Platz bereits trägt. Ein Reload half nur,
    // weil er frisch fetchte.
    //
    // eventDidMount feuert dagegen bei JEDEM Rendern des Termin-Elements -
    // auch beim Wechsel auf gecachte Events, nach Nachladen und nach
    // Filterwechsel. Die Entscheidung fällt damit immer auf dem aktuellen
    // Stand von Darstellung × Breite × Platzfilter.
    const eventDidMount = (info) => {
        if (platzFarbDarstellung() !== 'hintergrund') {
            return;
        }
        const farbe = platzFarbe(info.event.extendedProps);
        if (farbe === null) {
            return;
        }
        info.el.style.backgroundColor = farbe;
        info.el.style.borderColor = farbe;
    };

    // Ressourcen-Spalte je Event: der zugeordnete Platz, sonst die
    // synthetische "Auswärts"-Spalte (Issue #37) - ohne bekannte resourceId
    // würde FullCalendar das Event in Ressourcen-Views lautlos verwerfen.
    //
    // Issue #57: Was von Darstellung/Breite/Platzfilter abhängt (Platzfarbe,
    // Platz-Präfix im Titel), gehört NICHT hierher - der Datensatz überlebt
    // den Darstellungswechsel, die Entscheidung darf das nicht. `title` bleibt
    // der rohe Titel (FullCalendar nutzt ihn intern u. a. zum Sortieren), die
    // Anzeige baut eventContent daraus frisch.
    const toFcEvent = (e) => ({
        id: e.id,
        title: e.titel,
        start: e.start,
        end: e.ende,
        // Issue #78: Spielfrei ist ein Tages-Fakt - der Feed liefert es als
        // Ganztags-Termin (allDay + Tagesdatum statt Uhrzeit); FullCalendar
        // rendert es damit im All-Day-Slot (Tag/Woche) bzw. ohne Uhrzeit
        // (Liste), am richtigen Tag. Fehlendes Feld => zeitgebunden.
        allDay: e.allDay === true,
        // Issue #36: Vermietungen haben keine pitch_id - eigene Spalte statt
        // der "Auswärts"-Spalte, die sonst für Termine ohne Platz greift.
        // Issue #65: Spielfrei-Termine ebenso - auch sie haben keine pitch_id,
        // dürfen aber nie als Auswärtsspiel erscheinen; VOR der pitch_id-
        // Prüfung abgefragt, weil ein Spielfrei-Termin per Definition ohnehin
        // nie eine pitch_id trägt.
        resourceId: e.typ === 'vermietung'
            ? RESOURCE_SPORTHEIM_ID
            : e.spielfrei
                ? RESOURCE_SPIELFREI_ID
                : (e.pitch_id !== null ? String(e.pitch_id) : RESOURCE_AUSWAERTS_ID),
        color: e.typ === 'sperrung' ? sperrungColor(e) : undefined,
        display: e.typ === 'sperrung' ? 'background' : 'auto',
        // Issue #63: zusätzlich die Art als Klasse, damit CSS Sportheim-
        // Termine bei Bedarf je Art differenzieren kann (der gedeckte
        // Grund-Look hängt weiter am typ).
        classNames: [
            `ev-${e.typ}`,
            e.typ === 'vermietung' ? `ev-art-${e.art ?? 'vermietung'}` : '',
            e.status === 'abgesagt' ? 'ev-abgesagt' : '',
        ].filter(Boolean),
        extendedProps: e,
    });

    // Platzauswahl (Issue #6/#11/#37, Mehrfachauswahl seit Issue #86): in den
    // Ressourcen-Views (Tag/Woche, breit) reduziert aktuelleRessourcen()
    // bereits die SPALTEN auf die gewählten Plätze - ein zusätzlicher
    // Event-Filter wäre dort wirkungslos. In jeder anderen Ansicht (Monat,
    // Liste, schmale Tag/Woche) filtert er die Termine direkt. Filtert
    // Belegungen, Sperrungen und Spiele auf einen der gewählten Plätze -
    // Auswärtsspiele haben nie eine pitch_id und fallen dabei automatisch
    // heraus.
    const applyPitchFilter = (events) => {
        const gewaehlt = gewaehlteWerte('pitch');
        return !window.VKKalenderAnsicht.hatResourceSpalten(modus, istBreit()) && gewaehlt.length > 0
            ? events.filter((e) => gewaehlt.includes(String(e.pitch_id)))
            : events;
    };

    // Alle clientseitigen Filter zusammen anwenden (auch im Offline-Pfad, da
    // fetchEventsRange auch dort schon fertige Event-Objekte liefert).
    const applyClientFilters = (events) => window.VKKalenderEvents.typFilterAnwenden(
        window.VKKalenderEvents.artFilterAnwenden(
            window.VKKalenderEvents.vermietungFilterAnwenden(
                window.VKKalenderEvents.manuellFilterAnwenden(applyPitchFilter(events), filters.manuell),
                filters.vermietung,
            ),
            filters.art,
        ),
        filters.typ,
    );

    // Issue #36: die zuletzt geladenen Vermietungen (immer aus dem GEFILTERTEN
    // Event-Set, damit ein ausgeblendeter Vermietungs-Filter auch den
    // 🏠-Indikator/Detail-Hinweis konsequent abschaltet), für den
    // 🏠-Indikator am Termin und den Hinweis im Detail-Dialog. Deckt nur das
    // gerade sichtbare Fenster ab - kein Anspruch auf Vollständigkeit
    // außerhalb davon.
    let vermietungenAktuell = [];
    const merkeVermietungen = (events) => {
        vermietungenAktuell = events.filter((e) => e.typ === 'vermietung');
        return events;
    };

    // Ein Bereich [von, bis) laden - per Fetch oder, offline, aus dem
    // IndexedDB-Bundle mit dem kompletten Datenbestand (CLAUDE.md Abschnitt 8,
    // Issue #25: kein 7-Tage-Fenster mehr - Trainings-Slots werden clientseitig
    // aus den Regeln expandiert, VKOfflineEvents). Wird sowohl vom normalen
    // Grid-Fetch als auch batchweise von der Terminliste genutzt.
    const fetchEventsRange = async (von, bis, params) => {
        const p = new URLSearchParams(params);
        p.set('von', von);
        p.set('bis', bis);
        try {
            const response = await fetch(`/api/events?${p}`);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const daten = await response.json();
            // `naechster` (Issue #52): Datum des nächsten Termins nach `bis`,
            // null = danach kommt nichts mehr. `vorheriger` (Issue #81) ist
            // das Spiegelbild davon für die Vergangenheit. Beide tragen nur
            // die Abbruchbedingung der Terminliste; die Grid-Ansichten
            // ignorieren die Felder.
            return {
                events: daten.events,
                naechster: daten.naechster ?? null,
                vorheriger: daten.vorheriger ?? null,
            };
        } catch (error) {
            const bundle = await window.VKOffline?.load();
            if (!bundle) {
                throw error;
            }
            window.VKOffline.showBanner(bundle);
            // Issue #37: kein typ-Filter mehr nötig - das Bundle liefert
            // bereits alle Termintypen, wie der Online-Feed (typ=''). Issue
            // #86: team/bereich/venue sind Mehrfachauswahl - Spiegelbild von
            // EventFeedService::events() ($matchesTeams/$matchesVenue), damit
            // offline und online dasselbe Ergebnis liefern.
            const bundleEvents = window.VKOfflineEvents.eventsAusBundle(bundle, von, bis)
                .filter((e) => {
                    const gewaehlt = gewaehlteWerte('team');
                    if (gewaehlt.length === 0) {
                        return true;
                    }
                    // multi-team bookings match when ANY selected team matches
                    return (e.team_ids ?? [e.team_id]).some((id) => gewaehlt.includes(String(id)));
                })
                .filter((e) => {
                    const gewaehlt = gewaehlteWerte('bereich');
                    if (gewaehlt.length === 0) {
                        return true;
                    }
                    return (e.team_ids ?? [e.team_id]).some(
                        (id) => gewaehlt.includes(String(appData.teams.find((t) => t.id === id)?.bereich_id)),
                    );
                })
                .filter((e) => {
                    const gewaehlt = gewaehlteWerte('venue');
                    if (gewaehlt.length === 0) {
                        return true;
                    }
                    return gewaehlt.some((token) => {
                        if (token === 'heim') {
                            return e.venue_id !== null;
                        }
                        if (token === 'auswaerts') {
                            // Issue #65: ein Bye hat ebenfalls venue_id null,
                            // zählt aber nicht als Auswärtsspiel.
                            return !e.spielfrei && e.venue_id === null;
                        }
                        if (token === 'spielfrei') {
                            return e.spielfrei === true;
                        }
                        return String(e.venue_id) === token;
                    });
                });
            // Issue #52/#81: dieselbe Auskunft wie online, aus dem kompletten
            // Bundle berechnet - offline gibt es dadurch KEIN abweichendes
            // Abbruchverhalten in beide Richtungen. Wie serverseitig ohne
            // Team-/Bereichs-/Venue-Filter (untere/obere Schranke, s.
            // offline-events.js).
            return {
                events: bundleEvents,
                naechster: window.VKOfflineEvents.naechsterTermin(bundle, bis),
                vorheriger: window.VKOfflineEvents.vorherigerTermin(bundle, von),
            };
        }
    };

    // ---- Terminliste-Nachladen (Issue #31: kompletter Neuansatz) ----
    // Vorherige Versuche (Issue #4/#24) haben jeden weiteren Batch per
    // `calendar.changeView('listNachlade', { start, end })` geladen, um das
    // sichtbare Fenster der FullCalendar-Listenansicht wachsen zu lassen.
    // Zwei Probleme daran (Issue #31):
    // 1) Ein View-WECHSEL in die Liste (Button-Klick, oder - mobil - das
    //    Zurückwechseln von einer anderen Ansicht) ließ FullCalendars
    //    `events`-Callback einmal mit dem FALSCHEN (alten) View-Kontext
    //    laufen - Details und Beleg beim `events`-Handler weiter unten. Das
    //    setzte den Nachlade-Zustand dauerhaft zurück, jeder spätere
    //    Scroll-Trigger brach sofort ab - "PC lädt gar nicht mehr nach".
    // 2) Selbst wenn das Nachladen auslöste (mobiler Kaltstart direkt in die
    //    Liste), erzwang jeder changeView-Aufruf einen kompletten Neuaufbau
    //    der gesamten Listen-DOM statt eines reinen Anhängens - das erklärt
    //    die spürbare Verzögerung und das "unsichtbare" Nachladen
    //    (Layout/Paint hinkt hinterher, bis ein manueller Scroll einen
    //    Reflow erzwingt).
    //
    // Neuer Ansatz: `changeView()` wird für das Nachladen nie mehr benutzt.
    // Die Listenansicht bekommt EINMALIG eine großzügige statische Range
    // (heute..LISTE_HORIZONT_JAHRE), die FullCalendar nie wieder ändert.
    // Das Laden weiterer Batches ist reine Anwendungslogik: eigener
    // von/bis-Fortschritt (listeGeladenBis), Merge in den Cache, danach
    // `calendar.refetchEvents()` - das ruft nur die events-Callback erneut
    // auf (liest aus dem Cache), ohne die View-Range oder das Scroll-DOM
    // anzufassen. Neue Termine hängen sich damit unterhalb des bereits
    // gerenderten Bereichs an, die Scrollposition bleibt stabil.
    const LIST_BATCH_TAGE = 31;
    const LISTE_HORIZONT_JAHRE = 15; // FC-Anzeigefenster; Abbruch der Ladekette regelt weiter listeErschoepft
    const listeLadeIndikator = document.querySelector('#liste-lade-indikator');
    const listeErschoepftHinweis = document.querySelector('#liste-erschoepft-hinweis');
    const listeSentinel = document.querySelector('#liste-sentinel');
    // Issue #81: Schalter "Vergangenheit anzeigen" + Nachlade-UI oberhalb der
    // Liste (Sentinel VOR #kalender im DOM, damit Scrollen an den oberen
    // Rand der Seite ihn erreicht - #kalender selbst ist FullCalendar-
    // verwaltet, ein Kind-Sentinel würde jeden Re-Render nicht überleben).
    const listeVergangenheitLeiste = document.querySelector('#liste-vergangenheit-leiste');
    const listeVergangenheitToggle = document.querySelector('#liste-vergangenheit-toggle');
    const listeVergangenheitLadeIndikator = document.querySelector('#liste-vergangenheit-lade-indikator');
    const listeVergangenheitErschoepftHinweis = document.querySelector('#liste-vergangenheit-erschoepft-hinweis');
    const listeVergangenheitSentinel = document.querySelector('#liste-vergangenheit-sentinel');
    const LISTE_VERGANGENHEIT_KEY = 'kalender_liste_vergangenheit';
    // Issue #53: Zeitraum-Anzeige neben der Überschrift "Kalender" statt in
    // FullCalendars eigener Toolbar - s. Kommentar bei listeTitelAktualisieren
    // und aktualisiereGridZeitraum weiter unten.
    const zeitraumEl = document.querySelector('#kalender-zeitraum');

    let listeEvents = [];
    let listeGeladenBis = null; // ISO-Datum, bis zu dem bereits vom Server geladen wurde
    // Issue #52: Datum des nächsten Termins hinter listeGeladenBis, wie vom
    // letzten Batch gemeldet (null = Bestand erschöpft). Vor dem ersten
    // Batch noch unbekannt - undefined, damit es nie versehentlich als
    // "erschöpft" gelesen wird.
    let listeNaechster;
    let listeErschoepft = false;
    let listeAktiv = false; // true solange die Liste die aktuell aktive View ist
    let listeLaedt = false;
    // Issue #81: Schalter-Zustand (localStorage, Default "aus") und Fortschritt
    // der Vergangenheits-Ladekette - Spiegelbild von listeGeladenBis/
    // listeNaechster/listeErschoepft/listeLaedt, nur rückwärts. Bleibt beim
    // Zurücksetzen wegen eines Filterwechsels (listeZuruecksetzen) erhalten,
    // der Schalter selbst ist keine Filter-Einstellung.
    let listeVergangenheitAktiv = localStorage.getItem(LISTE_VERGANGENHEIT_KEY) === '1';
    let listeVergangenheitGeladenAb = null;
    let listeVergangenheitVorheriger;
    let listeVergangenheitErschoepft = false;
    let listeVergangenheitLaedt = false;
    // Generation-Zähler (bei jedem listeZuruecksetzen erhöht): schützt vor
    // einer veralteten Hintergrund-Ladekette, die nach schnellem Wechsel
    // weg von und zurück zur Liste (oder einem Filterwechsel mitten im
    // Laden) auf einen bereits verworfenen Cache weiterschreiben würde.
    let listeGeneration = 0;

    // "Heute" ist der oberste Tag der Terminliste (Issue #81). Vor Issue #81
    // startete die Liste bewusst am Wochenanfang statt bei "heute" (Issue
    // #26: sonst fehlten bereits vergangene Tage der laufenden Woche) - diese
    // Absicht bleibt über den Schalter "Vergangenheit anzeigen" gewahrt
    // (sichtbareListenEvents weiter unten), s. CLAUDE.md Abschnitt 8.
    const listeStart = () => window.VKNachlade.mitternacht(new Date());

    // Die FullCalendar-View-Range (s. Kommentar bei `views` weiter unten)
    // reicht IMMER bis weit in die Vergangenheit zurück, unabhängig vom
    // Schalter - sonst würden bereits geladene Vergangenheits-Termine beim
    // Rendern von FullCalendar verworfen, sobald der Schalter einmal
    // eingeschaltet war. Was TATSÄCHLICH sichtbar ist, entscheidet allein
    // sichtbareListenEvents() anhand von listeVergangenheitAktiv - dieselbe
    // Trennung "Datensatz vs. Darstellung" wie bei platzFarbDarstellung
    // (Issue #57).
    const listeHorizontStart = () => {
        const start = new Date();
        start.setFullYear(start.getFullYear() - LISTE_HORIZONT_JAHRE);
        start.setHours(0, 0, 0, 0);
        return start;
    };

    const listeHorizontEnde = () => {
        const ende = new Date();
        ende.setFullYear(ende.getFullYear() + LISTE_HORIZONT_JAHRE);
        return window.VKNachlade.toIsoDate(ende);
    };

    // Der Hinweis "Keine früheren Termine" ergibt nur Sinn, solange der
    // Schalter an ist - sonst bliebe er nach einem Ausschalten unverändert
    // stehen, direkt unter der jetzt wieder unmarkierten Checkbox.
    const listeVergangenheitErschoepftHinweisAktualisieren = () => {
        if (listeVergangenheitErschoepftHinweis) {
            listeVergangenheitErschoepftHinweis.hidden = !(listeVergangenheitAktiv && listeVergangenheitErschoepft);
        }
    };

    const listeZuruecksetzen = () => {
        listeEvents = [];
        listeGeladenBis = null;
        listeNaechster = undefined;
        listeErschoepft = false;
        listeVergangenheitGeladenAb = null;
        listeVergangenheitVorheriger = undefined;
        listeVergangenheitErschoepft = false;
        listeGeneration += 1;
        if (listeErschoepftHinweis) {
            listeErschoepftHinweis.hidden = true;
        }
        listeVergangenheitErschoepftHinweisAktualisieren();
    };

    const listeIndikatorSetzen = (aktiv) => {
        listeLaedt = aktiv;
        if (listeLadeIndikator) {
            listeLadeIndikator.hidden = !aktiv;
        }
    };

    // Die View-Range ist absichtlich auf einen statischen 15-Jahres-Horizont
    // fixiert (s. o.), FullCalendar selbst rendert seit Issue #53 gar keinen
    // Titel mehr (headerToolbar hat keinen center-Slot) - die Zeitraum-Anzeige
    // ist ein eigenes Element (#kalender-zeitraum) neben der Überschrift, das
    // FullCalendar nie berührt. Für die Liste wird es hier manuell auf den
    // tatsächlich geladenen Bereich gesetzt, nach jedem Batch neu (rAF, damit
    // der Batch-Merge/Re-Render zuerst durch ist).
    //
    // Root Cause des ursprünglichen Issue-#53-Bugs (Teil A): diese Funktion
    // schrieb vorher direkt in FullCalendars eigenes `.fc-toolbar-title`
    // (per `textContent`) - ein von Preact verwaltetes Element. Preact wusste
    // dadurch nichts von unserem extern eingefügten Text-Knoten; beim
    // nächsten View-Wechsel rendert Preact seinen eigenen (korrekten) Titel
    // NEBEN diesen Knoten statt ihn zu ersetzen (per DOM-Dump verifiziert:
    // zwei Text-Kindknoten im selben <h2>, sichtbar als „20. Juli 2026 – 19.
    // Nov. 202720 – 26. Juli 2026"). Ein eigenes, FullCalendar-fremdes
    // Element kann diesen Konflikt strukturell nicht mehr haben.
    const listeTitelAktualisieren = () => {
        requestAnimationFrame(() => {
            // Issue #37: mit dem jederzeit erreichbaren Umschalter kann ein
            // noch laufender Hintergrund-Batch (listeLadeKette) erst NACH
            // einem Wechsel weg von der Liste auflösen - `listeAktiv` allein
            // reicht als Schutz nicht (wird teils erst im selben Tick wie
            // dieses rAF gesetzt); die tatsächliche FC-View ist zum Zeitpunkt
            // des rAF-Feuerns bereits verlässlich aktuell.
            if (!zeitraumEl || !listeAktiv || calendar.view.type !== 'listNachlade') {
                return;
            }
            // Issue #81: mit aktivem Schalter beginnt der tatsächlich
            // geladene/gezeigte Bereich am ältesten Vergangenheits-Batch,
            // nicht mehr an "heute".
            const vonIso = listeVergangenheitAktiv && listeVergangenheitGeladenAb
                ? listeVergangenheitGeladenAb
                : window.VKNachlade.toIsoDate(listeStart());
            const von = new Date(`${vonIso}T00:00:00`);
            const bisIso = listeGeladenBis ?? window.VKNachlade.toIsoDate(listeStart());
            const bis = new Date(`${bisIso}T00:00:00`);
            zeitraumEl.textContent = window.VKKalenderTitel.zeitraumText('liste', von, bis, isMobile);
        });
    };

    // Grid-Darstellungen (Tag/Woche/Monat): die Zeitraum-Anzeige wird aus
    // `datesSet` gespeist, NICHT aus dem `events`-Callback - dessen
    // `info.start`/`info.end` ist der gepolsterte Render-Bereich (bei Monat
    // z. B. bereits Ende Juni statt 1. Juli, s. PR-Beschreibung), und
    // `calendar.view.type` ist dort laut Issue-#31-Kommentar oben noch
    // veraltet. `datesSet` feuert zuverlässig NACH dem eigentlichen
    // View-Wechsel; `info.view.currentStart`/`currentEnd` liefern exakt die
    // logischen Grenzen der Darstellung (verifiziert per Probe-Kalender:
    // Monat = 1.–31. Juli, nicht der gepolsterte 6-Wochen-Grid-Bereich).
    // `modus` statt `info.view.type` als Quelle, da es zum Zeitpunkt des
    // datesSet-Feuerns bereits sicher aktuell ist (in setzeModus() synchron
    // VOR calendar.changeView() gesetzt) und ohnehin die einzige App-weite
    // Quelle für die aktive Darstellung ist (s. Kommentar bei setzeModus).
    const aktualisiereGridZeitraum = (currentStart, currentEnd) => {
        if (!zeitraumEl || modus === 'liste') {
            return;
        }
        // currentEnd ist EXKLUSIV (Mitternacht des Folgetags) - für die
        // Anzeige zählt der letzte tatsächlich sichtbare Tag.
        const bisInklusive = new Date(currentEnd);
        bisInklusive.setDate(bisInklusive.getDate() - 1);
        zeitraumEl.textContent = window.VKKalenderTitel.zeitraumText(modus, currentStart, bisInklusive, isMobile);
    };

    // Einen Batch [von, bisGrenze] laden und in den Cache mergen (No-Op,
    // falls bereits bis dorthin geladen wurde). Der Ladeindikator wird SOFORT
    // beim Aufruf sichtbar (Issue #31, Akzeptanzkriterium "sofort sichtbar") -
    // nicht erst nachdem der Fetch fertig ist.
    const ladeEinenBatch = async (params, von, bisGrenze) => {
        if (bisGrenze <= von) {
            return;
        }
        listeIndikatorSetzen(true);
        try {
            const { events, naechster } = await fetchEventsRange(von, bisGrenze, params);
            listeEvents = window.VKNachlade.mergeEvents(listeEvents, events);
            listeGeladenBis = bisGrenze;
            listeNaechster = naechster;
            if (window.VKNachlade.istErschoepft(naechster)) {
                listeErschoepft = true;
                if (listeErschoepftHinweis) {
                    listeErschoepftHinweis.hidden = false;
                }
            }
        } finally {
            listeIndikatorSetzen(false);
        }
    };

    // Nächster Ladeschritt laut Server-Auskunft: {von, bis} oder null, wenn
    // hinter dem geladenen Bereich nachweislich nichts mehr liegt. Überspringt
    // Terminlücken in einem Schritt (Issue #52).
    const listeNaechsterSchritt = () => window.VKNachlade.naechsteLadeGrenzen(
        listeGeladenBis ?? window.VKNachlade.toIsoDate(listeStart()),
        listeNaechster,
        LIST_BATCH_TAGE,
    );

    // Einen von listeNaechsterSchritt() vorgegebenen Batch laden; liefert
    // false, wenn es nichts mehr zu laden gibt.
    const ladeNaechstenBatch = async (params) => {
        const schritt = listeNaechsterSchritt();
        if (schritt === null) {
            return false;
        }
        await ladeEinenBatch(params, schritt.von, schritt.bis);

        return true;
    };

    const listeNeuRendern = () => {
        calendar.refetchEvents();
        listeTitelAktualisieren();
    };

    // ---- Issue #81: Vergangenheit per Schalter nach oben nachladen ----
    // Spiegelbild von listeIndikatorSetzen/ladeEinenBatch/listeNaechsterSchritt/
    // ladeNaechstenBatch oben - `von` wächst rückwärts statt `bis` vorwärts,
    // `vorheriger` (EventFeedService::vorherigerTermin) ist das Gegenstück zu
    // `naechster`. Läuft nur, solange listeVergangenheitAktiv (Schalter an).
    const listeVergangenheitIndikatorSetzen = (aktiv) => {
        listeVergangenheitLaedt = aktiv;
        if (listeVergangenheitLadeIndikator) {
            listeVergangenheitLadeIndikator.hidden = !aktiv;
        }
    };

    const ladeEinenVergangenheitsBatch = async (params, von, bisGrenze) => {
        if (bisGrenze <= von) {
            return;
        }
        listeVergangenheitIndikatorSetzen(true);
        try {
            const { events, vorheriger } = await fetchEventsRange(von, bisGrenze, params);
            listeEvents = window.VKNachlade.mergeEvents(listeEvents, events);
            listeVergangenheitGeladenAb = von;
            listeVergangenheitVorheriger = vorheriger;
            if (window.VKNachlade.istErschoepft(vorheriger)) {
                listeVergangenheitErschoepft = true;
                listeVergangenheitErschoepftHinweisAktualisieren();
            }
        } finally {
            listeVergangenheitIndikatorSetzen(false);
        }
    };

    // Vor dem allerersten Vergangenheits-Batch ist listeVergangenheitVorheriger
    // noch unbekannt (undefined) - vorherigeLadeGrenzen liefert dafür bereits
    // den richtigen ersten Rückwärts-Schritt (s. nachlade.js), eine eigene
    // Sonderbehandlung wie bei listeLadeKette/naechsterMonatEnde ist hier
    // nicht nötig.
    const listeVergangenheitNaechsterSchritt = () => window.VKNachlade.vorherigeLadeGrenzen(
        listeVergangenheitGeladenAb ?? window.VKNachlade.toIsoDate(listeStart()),
        listeVergangenheitVorheriger,
        LIST_BATCH_TAGE,
    );

    const ladeVergangenheitsNaechstenBatch = async (params) => {
        const schritt = listeVergangenheitNaechsterSchritt();
        if (schritt === null) {
            return false;
        }
        await ladeEinenVergangenheitsBatch(params, schritt.von, schritt.bis);

        return true;
    };

    // Neue Vergangenheits-Termine werden OBERHALB der aktuellen Scrollposition
    // eingefügt (anders als das Nachladen nach unten) - ohne Korrektur würde
    // der Viewport sichtbar nach unten springen. scrollAnkerZiel (nachlade.js)
    // gleicht das aus.
    //
    // Ein fester Frame-Vorsprung (requestAnimationFrame) reicht dafür NICHT
    // und ist zudem unzuverlässig: refetchEvents() liest hier zwar synchron
    // aus dem Cache, aber fetchEventsRange() selbst wartet zuvor auf einen
    // echten Netzwerk-Request (ladeEinenVergangenheitsBatch/-Kette) - je nach
    // Latenz kann das deutlich länger dauern als ein paar Frames, und
    // FullCalendars eigenes (Preact-basiertes) Rendering patcht das DOM erst
    // danach; ein zu früh gemessenes rAF sah reproduzierbar noch die ALTE
    // Höhe. rAF selbst feuert außerdem NIE in einem nicht sichtbaren/nicht
    // fokussierten Tab (verifiziert: ein isoliert registriertes rAF blieb in
    // genau dieser Automatisierungs-Umgebung dauerhaft aus) - genau der Fall,
    // wenn der Nutzer während der Hintergrund-Ladekette (Desktop, s.
    // listeVergangenheitLadeKette) den Tab wechselt. Ein MutationObserver auf
    // #kalender wartet stattdessen auf die TATSÄCHLICHE DOM-Änderung,
    // unabhängig davon, wie lange sie braucht UND unabhängig von der
    // Tab-Sichtbarkeit; `scrollHeight` direkt im Callback zu lesen erzwingt
    // bereits synchron ein aktuelles Layout, ein zusätzliches rAF ist dafür
    // nicht nötig.
    const listeVergangenheitNeuRendern = () => {
        const vorherigeHoehe = document.documentElement.scrollHeight;
        const vorherigerScrollY = window.scrollY;
        const kalenderEl = document.querySelector('#kalender');
        if (kalenderEl) {
            const observer = new MutationObserver(() => {
                observer.disconnect();
                const neueHoehe = document.documentElement.scrollHeight;
                window.scrollTo(0, window.VKNachlade.scrollAnkerZiel(vorherigeHoehe, neueHoehe, vorherigerScrollY));
            });
            observer.observe(kalenderEl, { childList: true, subtree: true });
        }
        calendar.refetchEvents();
        listeTitelAktualisieren();
    };

    // Lädt Vergangenheits-Batches, solange der Schalter an ist - auf
    // Desktop-Breiten läuft die Kette bis zur Erschöpfung im Hintergrund
    // weiter (analog listeLadeKette), mobil bricht sie nach einem Batch ab
    // und wartet auf den Scroll-Trigger (listeVergangenheitWeiterLaden).
    const listeVergangenheitLadeKette = async (params) => {
        const generation = listeGeneration;
        const nochAktuell = () => generation === listeGeneration;
        try {
            while (listeVergangenheitAktiv && nochAktuell()) {
                if (!await ladeVergangenheitsNaechstenBatch(params)) {
                    return;
                }
                if (!nochAktuell()) {
                    return;
                }
                listeVergangenheitNeuRendern();
                if (isMobile) {
                    return;
                }
            }
        } catch (error) {
            console.error('Terminliste: Vergangenheit laden fehlgeschlagen', error);
        }
    };

    // Scroll an den oberen Rand (mobil): mindestens einen weiteren
    // Vergangenheits-Batch nachladen, analog listeWeiterLaden.
    const listeVergangenheitWeiterLaden = async () => {
        if (!listeVergangenheitAktiv || calendar.view.type !== 'listNachlade'
            || listeVergangenheitErschoepft || listeVergangenheitLaedt) {
            return;
        }
        const params = window.VKKalenderEvents.baueEventsParams(filters);
        try {
            let batchWarLeer;
            do {
                const vorLaenge = listeEvents.length;
                if (!await ladeVergangenheitsNaechstenBatch(params)) {
                    break;
                }
                batchWarLeer = listeEvents.length === vorLaenge;
            } while (listeVergangenheitAktiv
                && window.VKNachlade.sollAutomatischWeiterladen(batchWarLeer, listeVergangenheitErschoepft));
            listeVergangenheitNeuRendern();
        } catch (error) {
            console.error('Terminliste: Vergangenheit nachladen fehlgeschlagen', error);
        }
    };

    // Lädt den ersten Batch (mind. kompletter nächster Monat, Issue #4) und
    // rendert ihn sofort; auf Desktop-Breiten (kein Scroll-Nachladen nötig,
    // Issue #31) läuft die Ladekette danach im Hintergrund weiter, bis die
    // API mehrfach leer bleibt - der Nutzer bekommt eine vollständige Liste
    // ohne selbst nachladen zu müssen. Mobil bricht die Kette nach dem
    // ersten Batch ab; weitere Batches lädt listeWeiterLaden() per Scroll.
    // Netzwerkfehler brechen die Kette ab, ohne bereits geladene Termine zu
    // verwerfen (fetchEventsRange versucht selbst schon den Offline-Bundle-
    // Fallback, s. dort) - hier bleibt nur noch das Loggen für den seltenen
    // Fall, dass auch das fehlschlägt.
    const listeLadeKette = async (params) => {
        const generation = listeGeneration;
        const nochAktuell = () => generation === listeGeneration;
        try {
            if (!nochAktuell()) {
                return;
            }
            await ladeEinenBatch(
                params,
                window.VKNachlade.toIsoDate(listeStart()),
                window.VKNachlade.naechsterMonatEnde(new Date()),
            );
            if (!nochAktuell()) {
                return;
            }
            listeNeuRendern();
            if (isMobile) {
                return;
            }
            while (listeAktiv && !listeErschoepft && nochAktuell()) {
                if (!await ladeNaechstenBatch(params)) {
                    return;
                }
                if (!nochAktuell()) {
                    return;
                }
                listeNeuRendern();
            }
        } catch (error) {
            console.error('Terminliste: Laden fehlgeschlagen', error);
        }
    };

    // Scroll ans Listenende (mobil): mindestens einen weiteren Batch
    // nachladen und direkt unterhalb anhängen (refetchEvents ändert nur die
    // Event-Quelle, nicht die View-Range/das Scroll-DOM - Issue #31). War ein
    // Batch leer, hängt sich nichts unterhalb des Sentinels an - ein
    // IntersectionObserver feuert dann nicht erneut (Issue #46), deshalb
    // hier selbst weiterladen, bis wieder Termine gefunden werden oder die
    // Kette wirklich erschöpft ist (sollAutomatischWeiterladen).
    const listeWeiterLaden = async () => {
        if (!listeAktiv || calendar.view.type !== 'listNachlade' || listeErschoepft || listeLaedt) {
            return;
        }
        const params = window.VKKalenderEvents.baueEventsParams(filters);
        try {
            let batchWarLeer;
            do {
                const vorLaenge = listeEvents.length;
                if (!await ladeNaechstenBatch(params)) {
                    break;
                }
                batchWarLeer = listeEvents.length === vorLaenge;
            } while (listeAktiv && window.VKNachlade.sollAutomatischWeiterladen(batchWarLeer, listeErschoepft));
            listeNeuRendern();
        } catch (error) {
            console.error('Terminliste: Nachladen fehlgeschlagen', error);
        }
    };

    // Filterwechsel während die Liste aktiv ist: Cache verwerfen und die
    // Ladekette(n) neu starten (die View-Range bleibt unverändert - sie ist
    // statisch, s.o.). Issue #81: der Vergangenheits-Schalter bleibt dabei in
    // seinem gewählten Zustand - ist er an, startet auch seine Ladekette neu.
    const listeFilterGeaendert = () => {
        listeZuruecksetzen();
        listeNeuRendern();
        const params = window.VKKalenderEvents.baueEventsParams(filters);
        listeLadeKette(params);
        if (listeVergangenheitAktiv) {
            listeVergangenheitLadeKette(params);
        }
    };

    // IntersectionObserver statt Scroll-Event-Heuristik (Issue #24): ein
    // Sentinel-Element am Listenende statt window.scrollY/scrollHeight - das
    // funktioniert unabhängig davon, ob überhaupt eine Scrollbar existiert.
    const listeSentinelObserver = listeSentinel
        ? new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                listeWeiterLaden();
            }
        })
        : null;
    if (listeSentinelObserver && listeSentinel) {
        listeSentinelObserver.observe(listeSentinel);
    }

    // Issue #81: Sentinel VOR #kalender im DOM - Scrollen an den oberen Rand
    // löst das Nachladen weiterer Vergangenheits-Batches aus, analog dem
    // Sentinel am Listenende. Feuert harmlos auch außerhalb der Liste
    // (listeVergangenheitWeiterLaden prüft calendar.view.type selbst).
    const listeVergangenheitSentinelObserver = listeVergangenheitSentinel
        ? new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                listeVergangenheitWeiterLaden();
            }
        })
        : null;
    if (listeVergangenheitSentinelObserver && listeVergangenheitSentinel) {
        listeVergangenheitSentinelObserver.observe(listeVergangenheitSentinel);
    }

    // Schalter "Vergangenheit anzeigen" (Issue #81): Zustand in localStorage
    // gemerkt (Default "aus"), Nutzung in usage_stat gezählt. Ein
    // Zurückschalten auf "aus" verwirft den Vergangenheits-Cache NICHT -
    // erneutes Einschalten in derselben Sitzung zeigt ihn sofort wieder,
    // ohne neu zu laden (sichtbareListenEvents blendet ihn nur clientseitig
    // aus/ein).
    if (listeVergangenheitToggle) {
        listeVergangenheitToggle.checked = listeVergangenheitAktiv;
        listeVergangenheitToggle.addEventListener('change', () => {
            listeVergangenheitAktiv = listeVergangenheitToggle.checked;
            localStorage.setItem(LISTE_VERGANGENHEIT_KEY, listeVergangenheitAktiv ? '1' : '0');
            listeVergangenheitErschoepftHinweisAktualisieren();
            // Ein bereits gefüllter Cache (voriges Einschalten in derselben
            // Sitzung) erscheint/verschwindet dadurch OBERHALB der aktuellen
            // Scrollposition - dieselbe Anker-Korrektur wie beim Nachladen
            // selbst nötig, in beide Richtungen.
            listeVergangenheitNeuRendern();
            if (listeVergangenheitAktiv) {
                beacon('liste_vergangenheit');
                listeVergangenheitLadeKette(window.VKKalenderEvents.baueEventsParams(filters));
            }
        });
    }

    const isMobile = window.matchMedia('(max-width: 767px)').matches;
    // Issue #37: vier Darstellungen statt zweier Seiten - zuletzt gewählte
    // Ansicht wird gemerkt (localStorage), Default Woche (mobil: Tag, da eine
    // 7-Spalten-Woche auf schmalen Displays praktisch unlesbar ist - Liste
    // bleibt einen Tap entfernt, im PR begründet). Ungültige/veraltete Werte
    // fallen auf den Default zurück (normalisiereModus).
    let modus = window.VKKalenderAnsicht.normalisiereModus(
        localStorage.getItem('kalender_ansicht'),
        isMobile ? 'tag' : 'woche',
    );

    // Alle Plätze + eine synthetische "Auswärts"-Spalte für Spiele ohne
    // pitch_id (Issue #37); bei gewählten Plätzen (Issue #86: Mehrfachauswahl)
    // nur deren Spalten (bzw. die Auswärts-Spalte, falls "Auswärts" selbst
    // gewählt wäre - aktuell nicht wählbar, die Chip-Reihe listet nur echte
    // Plätze).
    //
    // Issue #57: bewusst NICHT mehr an die Breite gekoppelt (vorher: schmal =
    // leere Liste). FullCalendar cacht das Ergebnis; wurde es schmal als leer
    // geliefert, verwarf eine später aktivierte Ressourcen-View sämtliche
    // Events lautlos (unbekannte resourceId) - ein zweiter Fehlermodus derselben
    // Wurzel wie die Farben: eine breitenabhängige Entscheidung, einmal
    // eingefroren. Ansichten ohne Spalten ignorieren die Liste ohnehin, die
    // Kosten sind ein Array aus dem bereits geladenen appData.
    const aktuelleRessourcen = () => {
        const alle = [
            ...appData.pitches.map((p) => ({ id: String(p.id), title: `${p.name} (${p.venue_name})` })),
            { id: RESOURCE_AUSWAERTS_ID, title: 'Auswärts' },
            { id: RESOURCE_SPORTHEIM_ID, title: 'Sportheim' },
            { id: RESOURCE_SPIELFREI_ID, title: 'Spielfrei' },
        ];
        const gewaehlt = gewaehlteWerte('pitch');
        return gewaehlt.length > 0 ? alle.filter((r) => gewaehlt.includes(r.id)) : alle;
    };

    // Aktiv-Markierung der vier Umschalter-Buttons: customButtons bekommen
    // sie nicht automatisch wie FullCalendars eigene View-Buttons.
    const aktualisiereModusButtons = () => {
        for (const m of window.VKKalenderAnsicht.MODI) {
            document.querySelector(`.fc-ansicht${m}-button`)?.classList.toggle('fc-button-active', m === modus);
        }
        // Issue #81: der Schalter "Vergangenheit anzeigen" ergibt nur in der
        // Terminliste selbst einen Sinn.
        if (listeVergangenheitLeiste) {
            listeVergangenheitLeiste.hidden = modus !== 'liste';
        }
    };

    // Modus wechseln (Issue #37): eigener State statt FullCalendars
    // view.type - der `events`-Callback feuert für eine neu aktivierte View,
    // BEVOR calendar.view.type den Wechsel widerspiegelt (s. Kommentar bei
    // `events` weiter unten); Rendering-Logik (Gruppierung, Farbe, Titel)
    // darf sich darauf also nie verlassen und liest stattdessen `modus`.
    // Persistiert die Wahl, zählt sie in usage_stat und wechselt erst danach
    // die FullCalendar-View.
    const setzeModus = (neu) => {
        if (neu === modus) {
            return;
        }
        modus = neu;
        localStorage.setItem('kalender_ansicht', modus);
        beacon(window.VKKalenderAnsicht.statMetrik(modus));
        aktualisiereModusButtons();
        calendar.changeView(window.VKKalenderAnsicht.fcViewName(modus, istBreit()));
    };

    const ansichtButton = (m, text) => ({ text, hint: text, click: () => setzeModus(m) });

    const calendar = new FullCalendar.Calendar(document.querySelector('#kalender'), {
        schedulerLicenseKey: 'GPL-My-Project-Is-Open-Source',
        locale: 'de',
        firstDay: 1,
        height: 'auto',
        // Issue #78: All-Day-Zeile aktiv, damit Spielfrei-Termine (Tages-Fakt,
        // allDay) in Tag/Woche unter der Kopfzeile statt im Stundenraster
        // erscheinen. Sonst tragen nur Byes Ganztags-Events; die Zeile bleibt
        // meist leer und wird per CSS kompakt gehalten.
        allDaySlot: true,
        allDayText: 'ganztägig',
        slotMinTime: '07:00:00',
        slotMaxTime: '23:00:00',
        nowIndicator: true,
        // Issue #6/#37: Platz-Spalten (Ressourcen-Views) nur ab der Desktop-
        // Sidebar-Schwelle (~1100px) in Tag/Woche; darunter bzw. in Monat/
        // Liste ersetzt die Platz-Auswahl (Dropdown) die Spalten.
        initialView: window.VKKalenderAnsicht.fcViewName(modus, istBreit()),
        customButtons: {
            ansichttag: ansichtButton('tag', 'Tag'),
            ansichtwoche: ansichtButton('woche', 'Woche'),
            ansichtmonat: ansichtButton('monat', 'Monat'),
            ansichtliste: ansichtButton('liste', 'Liste'),
        },
        // Issue #53: kein center-Slot mehr - die Zeitraum-Anzeige lebt jetzt
        // neben der Überschrift "Kalender" (#kalender-zeitraum), nicht mehr
        // in FullCalendars eigener Toolbar (spart auf schmalen Viewports
        // Platz, s. Teil B, und beseitigt den Preact/textContent-Konflikt
        // aus Teil A - s. Kommentar bei listeTitelAktualisieren).
        headerToolbar: {
            left: 'prev,next today',
            right: 'ansichttag,ansichtwoche,ansichtmonat,ansichtliste',
        },
        // Issue #3: "Heute" als Icon/Kurzform ohne Schriftzug, aber weiterhin
        // mit vollem Text für Hover-Titel und Screenreader (buttonHints).
        buttonText: { today: '●' },
        buttonHints: { today: 'Heute' },
        // Issue #4/#31: eigene View statt "listWeek" - das sichtbare Fenster
        // ist absichtlich EINMALIG auf einen großzügigen Horizont fixiert
        // (LISTE_HORIZONT_JAHRE) und wird danach nie mehr per changeView()
        // verändert; das Nachladen weiterer Batches passiert rein in der
        // Anwendungslogik (listeLadeKette/listeWeiterLaden), s. Kommentar
        // oben bei den State-Variablen.
        views: {
            listNachlade: {
                type: 'list',
                // Issue #81: die technische FC-Range reicht IMMER von einem
                // fernen Vergangenheits- bis zum Zukunfts-Horizont (nicht nur
                // von "heute"), sonst würde FullCalendar bereits geladene
                // Vergangenheits-Termine verwerfen, sobald der Schalter
                // einmal aktiv war. Was TATSÄCHLICH angezeigt wird, filtert
                // allein sichtbareListenEvents() im `events`-Callback unten -
                // die Range selbst bleibt weiterhin statisch, s. Kommentar
                // oben bei den State-Variablen.
                visibleRange: { start: listeHorizontStart(), end: `${listeHorizontEnde()}T00:00:00` },
                // FullCalendar zeigt den Wochentag neben dem Datum nur, wenn
                // listDaySideFormat explizit gesetzt ist (Default liefert
                // keinen sideText).
                listDaySideFormat: { weekday: 'long' },
            },
        },
        resources: (info, success) => success(aktuelleRessourcen()),
        // Issue #31, Desktop-Ursache: `events` feuert für eine neu aktivierte
        // View, BEVOR `datesSet`/`calendar.view.type` den Wechsel
        // widerspiegeln - eine frühere Version, die anhand eines separat
        // mitgeführten "aktueller View-Typ"-Flags unterschied, griff deshalb
        // bei jedem Wechsel IN die Liste (Button-Klick oder Zurückwechseln,
        // reproduzierbar auch mobil) für genau diesen ersten Aufruf daneben:
        // sie fiel in den Grid-Zweig, der `listeAktiv=false` setzte und
        // dadurch jeden späteren Scroll-Trigger blockierte - "PC lädt gar
        // nicht mehr nach". FullCalendar unterstützt zudem keine view-eigene
        // `events`-Option (versucht, von FC stillschweigend ignoriert). Die
        // Erkennung nutzt stattdessen `info.start/end` selbst: die sind zum
        // Zeitpunkt des Aufrufs bereits korrekt (bestätigt per Netzwerk-Log),
        // und die Listen-Range (heute..LISTE_HORIZONT_JAHRE) ist so
        // großzügig, dass keine Grid-Ansicht sie je zufällig anfragt.
        events: async (info, success, failure) => {
            const params = window.VKKalenderEvents.baueEventsParams(filters);
            const istListenFetch = info.startStr.slice(0, 10) === window.VKNachlade.toIsoDate(listeHorizontStart())
                && info.endStr.slice(0, 10) === listeHorizontEnde();

            if (istListenFetch) {
                if (!listeAktiv) {
                    listeZuruecksetzen();
                    listeAktiv = true;
                    listeLadeKette(params);
                    if (listeVergangenheitAktiv) {
                        listeVergangenheitLadeKette(params);
                    }
                }
                // Issue #81: "heute" ist der oberste Tag, solange der
                // Schalter aus ist - auch bereits gecachte Vergangenheit
                // (z. B. nach einem Aus-/Einschalten in derselben Sitzung)
                // bleibt dann ausgeblendet.
                const sichtbar = merkeVermietungen(window.VKNachlade.sichtbareListenEvents(
                    applyClientFilters(listeEvents),
                    window.VKNachlade.toIsoDate(listeStart()),
                    listeVergangenheitAktiv,
                ));
                aktualisiereLeerHinweis(sichtbar);
                success(sichtbar.map(toFcEvent));
                listeTitelAktualisieren();
                return;
            }
            listeAktiv = false;

            try {
                const von = info.startStr.slice(0, 10);
                const bis = info.endStr.slice(0, 10);
                // Grid-Ansichten zeigen ein festes Fenster - `naechster`
                // (Issue #52) braucht nur die nachladende Terminliste.
                const { events } = await fetchEventsRange(von, bis, params);
                const gefiltert = merkeVermietungen(applyClientFilters(events));
                aktualisiereLeerHinweis(gefiltert);
                success(gefiltert.map(toFcEvent));
            } catch (error) {
                failure(error);
            }
        },
        eventContent,
        eventDidMount,
        eventClick: (info) => showDetail(info.event.extendedProps),
        // FullCalendar's buttonHints only sets the hover title, not
        // aria-label; the icon-only "Heute" button (Issue #3) needs an
        // explicit one. datesSet fires on every toolbar re-render (nav,
        // view switch), so re-apply it there too - auch die Aktiv-Markierung
        // der vier Ansichts-Buttons lebt hier aus demselben Grund.
        datesSet: (info) => {
            document.querySelector('.fc-today-button')?.setAttribute('aria-label', 'Heute');
            aktualisiereModusButtons();
            aktualisiereGridZeitraum(info.view.currentStart, info.view.currentEnd);
        },
    });
    calendar.render();

    // Issue #57: Breite live nachziehen. Tag/Woche wechseln beim Über- bzw.
    // Unterschreiten der Schwelle zwischen Ressourcen- und normaler
    // Zeitraster-View; damit ändert sich auch, ob der Hintergrund die
    // Platzfarbe trägt (Spalten vs. Farbe). Monat/Liste kennen keine Spalten -
    // dort ist der View-Name breitenunabhängig und changeView() bliebe ein
    // No-op, deshalb der Vergleich statt eines bedingungslosen Aufrufs.
    breitMedia.addEventListener('change', () => {
        const ziel = window.VKKalenderAnsicht.fcViewName(modus, istBreit());
        if (ziel === calendar.view.type) {
            return;
        }
        calendar.changeView(ziel);
    });

    aktualisiereModusButtons();
    beacon(window.VKKalenderAnsicht.statMetrik(modus));

    // ---- detail dialog ----

    const detailDialog = document.querySelector('#detail-dialog');
    const detailContent = document.querySelector('#detail-content');
    const detailActions = document.querySelector('#detail-actions');
    // Issue #68: "Schließen" ist Teil derselben Button-Leiste wie die
    // typspezifischen Aktionen; showDetail() leert #detail-actions bei
    // jedem Aufruf und hängt den Button (dieselbe Referenz, derselbe
    // Listener) am Ende jedes Zweigs wieder an - so bleibt er für alle
    // Termintypen die letzte Aktion in der Leiste.
    const detailClose = document.querySelector('#detail-close');
    detailClose.addEventListener('click', () => detailDialog.close());

    const zeile = (label, wert) => {
        const p = document.createElement('p');
        const strong = document.createElement('strong');
        strong.textContent = `${label}: `;
        p.append(strong, document.createTextNode(wert));
        return p;
    };

    const formatDatum = (iso) => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });

    // Maps-Link (Issue #5): Platz-Adresse falls zugeordnet, sonst ort_text;
    // reines Link-Ziel, kein Embed/API-Key, öffnet in neuem Tab
    const mapsAdresse = (props) => props.pitch_adresse ?? props.venue_adresse ?? props.ort_text ?? null;

    const mapsLink = (props) => {
        const adresse = mapsAdresse(props);
        if (adresse === null || adresse === '') {
            return null;
        }
        const a = document.createElement('a');
        a.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(adresse)}`;
        a.target = '_blank';
        a.rel = 'noopener';
        a.className = 'maps-link';
        a.textContent = `📍 In Google Maps öffnen`;
        const p = document.createElement('p');
        p.append(a);
        return p;
    };

    const showDetail = (props) => {
        detailContent.replaceChildren();
        detailActions.replaceChildren();

        const title = document.createElement('h3');
        title.textContent = props.titel;
        detailContent.append(title);

        const start = new Date(props.start);
        const ende = new Date(props.ende);
        const datum = start.toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: '2-digit', year: 'numeric' });
        detailContent.append(zeile('Termin', `${datum}, ${start.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })}–${ende.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' })} Uhr`));

        // Issue #36: voller Hinweis für Trainings/Spiele auf einem Platz
        // eines gerade vermieteten Sportheims - blockiert nichts, ist reine
        // Information (CLAUDE.md Abschnitt 4/9).
        if (props.typ === 'belegung' || props.typ === 'spiel') {
            for (const v of window.VKVermietungHinweis.findeUeberschneidende(vermietungenAktuell, props)) {
                const hinweis = document.createElement('p');
                hinweis.className = 'warning-message';
                hinweis.textContent = `⚠ ${artHinweis(v.art ?? 'vermietung')}: ${v.anlass} (${v.raum_text}), Nutzung ggf. eingeschränkt.`;
                detailContent.append(hinweis);
            }
        }

        if (props.typ === 'belegung') {
            detailContent.append(zeile((props.team_ids ?? []).length > 1 ? 'Teams' : 'Team', props.team_name));
            detailContent.append(zeile('Platz', props.pitch_name ?? '–'));
            const belegungMapsLink = mapsLink(props);
            if (belegungMapsLink !== null) {
                detailContent.append(belegungMapsLink);
            }
            const tage = ['', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
            const serie = (props.wochentage ?? []).map((w) => tage[w]).join('+');
            detailContent.append(zeile('Serie', `${serie}, ${formatDatum(props.gueltig_ab)} bis ${formatDatum(props.gueltig_bis)}`));

            // public edit path (CLAUDE.md section 6): every visitor with a
            // name may edit; the scope dialog asks what to change
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'button';
            editButton.textContent = 'Bearbeiten';
            editButton.addEventListener('click', () => {
                detailDialog.close();
                openEdit(props);
            });

            const ausfallButton = document.createElement('button');
            ausfallButton.type = 'button';
            ausfallButton.className = 'button';
            ausfallButton.textContent = 'Ausfall eintragen';
            ausfallButton.addEventListener('click', () => {
                detailDialog.close();
                openAusfallDialog(props.slot_id, props.start.slice(0, 10));
            });

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'button danger';
            deleteButton.textContent = 'Belegung löschen (alle Termine)';
            deleteButton.addEventListener('click', async () => {
                if (!confirm('Diese wiederkehrende Belegung komplett löschen?')) {
                    return;
                }
                const result = await VK.post(`/api/slots/${props.slot_id}/loeschen`).catch(() => null);
                if (result?.ok) {
                    detailDialog.close();
                    calendar.refetchEvents();
                } else if (result) {
                    alert(VK.fehlerText(result.data));
                }
            });

            detailActions.append(editButton, ausfallButton, deleteButton);
        } else if (props.typ === 'spiel') {
            detailContent.append(zeile('Gegner', props.gegner));
            detailContent.append(zeile(
                'Heim/Auswärts',
                props.spielfrei ? 'Spielfrei' : (props.heimspiel ? 'Heimspiel' : 'Auswärtsspiel'),
            ));
            detailContent.append(zeile('Ort', props.venue_name ?? props.ort_text ?? '–'));
            const spielMapsLink = mapsLink(props);
            if (spielMapsLink !== null) {
                detailContent.append(spielMapsLink);
            }
            if (props.status === 'abgesagt') {
                detailContent.append(zeile('Status', 'ABGESAGT'));
            }

            if (props.manuell) {
                // Issue #12: kein Import-Datenpunkt, sondern manuell erfasst
                // - Farbe allein ist nie das Signal (CLAUDE.md Abschnitt 8)
                detailContent.append(zeile('Quelle', 'Manuell eingetragen'));

                const editButton = document.createElement('button');
                editButton.type = 'button';
                editButton.className = 'button';
                editButton.textContent = 'Bearbeiten';
                editButton.addEventListener('click', () => {
                    detailDialog.close();
                    openMatchDialog(props);
                });

                const deleteButton = document.createElement('button');
                deleteButton.type = 'button';
                deleteButton.className = 'button danger';
                deleteButton.textContent = 'Spiel löschen';
                deleteButton.addEventListener('click', async () => {
                    if (!confirm('Dieses Spiel endgültig löschen?')) {
                        return;
                    }
                    const result = await VK.post(`/api/spiele/${props.match_id}/loeschen`).catch(() => null);
                    if (result?.ok) {
                        detailDialog.close();
                        calendar.refetchEvents();
                    } else if (result) {
                        alert(VK.fehlerText(result.data));
                    }
                });

                detailActions.append(editButton, deleteButton);
            } else if (props.heimspiel) {
                // the pitch is not part of the ICS: manual assignment, saved
                // as an event with the editor's name (CLAUDE.md section 7)
                const label = document.createElement('label');
                label.textContent = 'Platz-Zuordnung';
                const select = document.createElement('select');
                select.add(new Option('– automatisch (Regel/Standard-Platz) –', ''));
                for (const pitch of appData.pitches) {
                    select.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
                }
                select.value = props.pitch_id !== null ? String(props.pitch_id) : '';
                label.append(select);
                detailContent.append(label);

                const saveButton = document.createElement('button');
                saveButton.type = 'button';
                saveButton.className = 'button';
                saveButton.textContent = 'Platz speichern';
                saveButton.addEventListener('click', async () => {
                    const result = await VK.post(`/api/spiele/${props.match_id}/platz`, { pitch_id: select.value }).catch(() => null);
                    if (result?.ok) {
                        detailDialog.close();
                        calendar.refetchEvents();
                    } else if (result) {
                        alert(VK.fehlerText(result.data));
                    }
                });
                detailActions.append(saveButton);
            }
        } else if (props.typ === 'sperrung') {
            detailContent.append(zeile('Platz', props.pitch_name ?? '–'));
            const sperrungMapsLink = mapsLink(props);
            if (sperrungMapsLink !== null) {
                detailContent.append(sperrungMapsLink);
            }
            detailContent.append(zeile('Art', props.art === 'gesperrt' ? 'Gesperrt' : 'Eingeschränkt'));
            detailContent.append(zeile('Grund', props.grund));

            // Issue #64: öffentlich bearbeitbar (Ebene 2) wie ein manuelles
            // Spiel/eine Vermietung - props trägt bereits den vollen,
            // ungeclippten Zeitraum (EventSerializer::sperrung()).
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'button';
            editButton.textContent = 'Bearbeiten';
            editButton.addEventListener('click', () => {
                detailDialog.close();
                openRestrictionDialog({
                    id: props.restriction_id,
                    pitch_id: props.pitch_id,
                    art: props.art,
                    grund: props.grund,
                    von: props.start,
                    bis: props.ende,
                });
            });

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'button danger';
            deleteButton.textContent = 'Sperrung löschen';
            deleteButton.addEventListener('click', async () => {
                if (!confirm('Diese Sperrung/Einschränkung löschen?')) {
                    return;
                }
                const result = await VK.post(`/api/sperrungen/${props.restriction_id}/loeschen`).catch(() => null);
                if (result?.ok) {
                    detailDialog.close();
                    calendar.refetchEvents();
                } else if (result) {
                    alert(VK.fehlerText(result.data));
                }
            });
            detailActions.append(editButton, deleteButton);
        } else if (props.typ === 'vermietung') {
            detailContent.append(zeile('Art', artName(props.art ?? 'vermietung')));
            detailContent.append(zeile('Sportheim', props.sportheim_name));
            detailContent.append(zeile('Räume', props.raum_text));
            if (props.kontakt) {
                detailContent.append(zeile('Kontakt', props.kontakt));
            }
            if (props.bemerkung) {
                detailContent.append(zeile('Bemerkung', props.bemerkung));
            }

            // öffentlich bearbeitbar/löschbar wie ein manuelles Spiel
            // (CLAUDE.md Abschnitt 6/3): Sportheim-Termine blockieren nie -
            // in KEINER Art -, daher kein Konflikt-/Warnungs-Check im Dialog.
            const editButton = document.createElement('button');
            editButton.type = 'button';
            editButton.className = 'button';
            editButton.textContent = 'Bearbeiten';
            editButton.addEventListener('click', () => {
                detailDialog.close();
                openVermietungDialog(props);
            });

            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'button danger';
            deleteButton.textContent = 'Sportheim-Termin löschen';
            deleteButton.addEventListener('click', async () => {
                if (!confirm('Diesen Sportheim-Termin endgültig löschen?')) {
                    return;
                }
                const result = await VK.post(`/api/vermietungen/${props.vermietung_id}/loeschen`).catch(() => null);
                if (result?.ok) {
                    detailDialog.close();
                    calendar.refetchEvents();
                } else if (result) {
                    alert(VK.fehlerText(result.data));
                }
            });

            detailActions.append(editButton, deleteButton);
        }

        // Issue #68: "Schließen" gehört für jeden Termintyp ans Ende
        // derselben Leiste; replaceChildren() oben hat den Button (falls
        // vom letzten Aufruf noch angehängt) bereits entfernt, append()
        // hängt dieselbe Node - inkl. Listener - wieder an.
        detailActions.append(detailClose);

        detailDialog.showModal();
    };

    // ---- Konflikt-Anzeige (Issue #9, Issue #12: von Booking- UND
    // Match-Formular genutzt) ----
    // Eine Serie (oder ein anderer wiederholter Verursacher) wird als eine
    // Zeile mit Anzahl + nächstem Termin dargestellt, aufklappbar für die
    // Einzeltermine; initial max. 5 Gruppen, Rest per "weitere anzeigen".
    // Der Server liefert die Gruppen bereits fertig aggregiert
    // (ConflictGrouper::group()).
    const INITIAL_KONFLIKT_GRUPPEN = 5;

    const konfliktZeile = (gruppe) => {
        const li = document.createElement('li');
        const text = document.createElement('span');
        text.textContent = window.VKKonflikte.gruppenBeschriftung(gruppe);
        li.append(text);

        if (gruppe.anzahl > 1) {
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'linklike konflikt-toggle';
            toggle.textContent = 'Termine anzeigen';
            const termine = document.createElement('ul');
            termine.className = 'konflikt-termine';
            termine.hidden = true;
            for (const termin of gruppe.termine) {
                const eintrag = document.createElement('li');
                eintrag.textContent = `${window.VKKonflikte.formatDatum(termin.datum)}, ${termin.von}–${termin.bis} Uhr`;
                termine.append(eintrag);
            }
            toggle.addEventListener('click', () => {
                termine.hidden = !termine.hidden;
                toggle.textContent = termine.hidden ? 'Termine anzeigen' : 'Termine verbergen';
            });
            li.append(' ', toggle, termine);
        }

        return li;
    };

    const renderKonfliktGruppen = (feedback, gruppen, { warnung = false } = {}) => {
        feedback.innerHTML = '';
        feedback.className = warnung ? 'warning-message' : 'error-message';
        if (gruppen.length === 0) {
            return;
        }

        const { sichtbar, rest } = window.VKKonflikte.sichtbareGruppen(gruppen, INITIAL_KONFLIKT_GRUPPEN);
        const liste = document.createElement('ul');
        liste.className = 'konflikt-liste';
        for (const gruppe of sichtbar) {
            liste.append(konfliktZeile(gruppe));
        }
        feedback.append(liste);

        if (rest.length > 0) {
            const weitereButton = document.createElement('button');
            weitereButton.type = 'button';
            weitereButton.className = 'linklike';
            weitereButton.textContent = `${rest.length} weitere anzeigen`;
            weitereButton.addEventListener('click', () => {
                for (const gruppe of rest) {
                    liste.append(konfliktZeile(gruppe));
                }
                weitereButton.remove();
            });
            feedback.append(weitereButton);
        }

        if (warnung) {
            const hinweis = document.createElement('p');
            hinweis.textContent = 'Trotzdem speichern?';
            feedback.append(hinweis);
        }
    };

    // ---- match dialog (Issue #12: manuell erfasste Spiele) ----
    // Das Bearbeiten/Löschen eines manuellen Spiels mit Platz wird auch aus
    // der Platz-Detailansicht heraus angeboten. Das Anlegen läuft seit
    // Issue #37 über das gemeinsame "+ Eintragen"-Sheet (#entry-dialog, s.
    // unten) statt eines eigenen Toolbar-Buttons.
    const matchDialog = document.querySelector('#match-dialog');
    const matchForm = document.querySelector('#match-form');
    const matchFeedback = document.querySelector('#match-feedback');
    const matchSubmit = matchForm.querySelector('button[type="submit"]');
    const matchTitle = document.querySelector('#match-title');
    const matchStatusFeld = document.querySelector('#match-status-feld');

    const matchTeamSelect = document.querySelector('#match-team');
    for (const team of activeTeams) {
        matchTeamSelect.add(new Option(`${team.name} (${teamBereichName(team)})`, String(team.id)));
    }
    const matchPitchSelect = document.querySelector('#match-pitch');
    for (const pitch of appData.pitches) {
        matchPitchSelect.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
    }

    let matchWarnungenBestaetigt = false;

    const openMatchDialog = (props) => {
        matchForm.reset();
        matchFeedback.textContent = '';
        matchFeedback.className = '';
        matchSubmit.textContent = 'Speichern';
        matchWarnungenBestaetigt = false;

        const isEdit = props !== null;
        matchTitle.textContent = isEdit ? 'Spiel bearbeiten' : 'Spiel eintragen';
        matchStatusFeld.hidden = !isEdit;

        matchForm.elements.match_id.value = isEdit ? String(props.match_id) : '';
        matchForm.elements.team_id.value = isEdit ? String(props.team_id) : '';
        matchForm.elements.datum.value = isEdit ? props.start.slice(0, 10) : '';
        matchForm.elements.anstoss.value = isEdit ? props.start.slice(11, 16) : '';
        // vorbelegt mit der effektiven Dauer (explizit oder Anstoß+2 Std.);
        // wer die Automatik zurückwill, muss das Feld manuell leeren
        matchForm.elements.ende.value = isEdit ? props.ende.slice(11, 16) : '';
        matchForm.elements.gegner.value = isEdit ? props.gegner : '';
        matchForm.elements.pitch_id.value = isEdit && props.pitch_id !== null ? String(props.pitch_id) : '';
        matchForm.elements.ort_text.value = isEdit ? (props.ort_text ?? '') : '';
        matchForm.elements.status.value = isEdit ? props.status : 'geplant';

        matchDialog.showModal();
    };

    document.querySelector('#match-cancel').addEventListener('click', () => matchDialog.close());

    matchForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(matchForm));
        const matchId = data.match_id;
        const url = matchId === '' ? '/api/spiele' : `/api/spiele/${matchId}`;

        try {
            if (!matchWarnungenBestaetigt) {
                const check = await VK.post('/api/spiele/pruefen', data);
                if (!check.ok) {
                    matchFeedback.className = 'error-message';
                    matchFeedback.textContent = VK.fehlerText(check.data);
                    return;
                }
                if (check.data.konflikte.length > 0) {
                    renderKonfliktGruppen(matchFeedback, check.data.konflikte);
                    return;
                }
                if (check.data.warnungen.length > 0) {
                    // 'eingeschraenkt' bzw. Überschneidungen: erlaubt, aber
                    // der Dialog muss erst warnen (CLAUDE.md Abschnitt 4)
                    renderKonfliktGruppen(matchFeedback, check.data.warnungen, { warnung: true });
                    matchSubmit.textContent = 'Trotzdem speichern';
                    matchWarnungenBestaetigt = true;
                    return;
                }
            }

            const result = await VK.post(url, data);
            if (result.ok) {
                matchDialog.close();
                calendar.refetchEvents();
            } else {
                matchFeedback.className = 'error-message';
                matchFeedback.textContent = VK.fehlerText(result.data);
                matchSubmit.textContent = 'Speichern';
                matchWarnungenBestaetigt = false;
            }
        } catch {
            // name dialog cancelled
        }
    });

    // ---- Vermietung dialog (Issue #36) ----
    // Anlegen/Bearbeiten/Löschen öffentlich (Ebene 2), analog dem manuellen
    // Spiel-Dialog - aber ohne Konflikt-/Warnungs-Check: eine Vermietung
    // blockiert nie Trainings/Spiele (BookingService behandelt sie nur als
    // Hinweis), daher speichert der Dialog direkt.
    const vermietungDialog = document.querySelector('#vermietung-dialog');
    const vermietungForm = document.querySelector('#vermietung-form');
    const vermietungFeedback = document.querySelector('#vermietung-feedback');
    const vermietungTitle = document.querySelector('#vermietung-title');
    const vermietungSportheimSelect = document.querySelector('#vermietung-sportheim');
    const vermietungRaeumeContainer = document.querySelector('#vermietung-raeume');

    for (const sportheim of appData.sportheime) {
        vermietungSportheimSelect.add(new Option(sportheim.name, String(sportheim.id)));
    }

    // Räume gehören zu genau einem Sportheim (Issue #36) - die Checkbox-Liste
    // wird bei jeder Sportheim-Auswahl neu aufgebaut.
    const renderVermietungRaeume = (sportheimId, checkedIds = []) => {
        vermietungRaeumeContainer.replaceChildren();
        const raeume = appData.sportheimRaeume.filter((r) => String(r.sportheim_id) === String(sportheimId));
        for (const raum of raeume) {
            const label = document.createElement('label');
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.name = 'raum_ids[]';
            box.value = String(raum.id);
            box.checked = checkedIds.includes(raum.id);
            label.append(box, ` ${raum.name}`);
            vermietungRaeumeContainer.append(label);
        }
    };
    vermietungSportheimSelect.addEventListener('change', () => renderVermietungRaeume(vermietungSportheimSelect.value));

    const openVermietungDialog = (props) => {
        vermietungForm.reset();
        vermietungFeedback.textContent = '';
        vermietungFeedback.className = '';

        const isEdit = props !== null;
        vermietungTitle.textContent = isEdit ? 'Sportheim-Termin bearbeiten' : 'Sportheim-Termin eintragen';

        vermietungForm.elements.vermietung_id.value = isEdit ? String(props.vermietung_id) : '';
        // Issue #63: Default 'vermietung' - der mit Abstand häufigste Fall
        vermietungForm.elements.art.value = isEdit ? (props.art ?? 'vermietung') : 'vermietung';
        vermietungForm.elements.sportheim_id.value = isEdit ? String(props.sportheim_id) : '';
        renderVermietungRaeume(vermietungForm.elements.sportheim_id.value, isEdit ? props.raum_ids : []);
        // datetime-local erwartet 'YYYY-MM-DDTHH:MM', start/ende liefern das
        // bereits als Präfix (Sekunden abgeschnitten)
        vermietungForm.elements.von.value = isEdit ? props.start.slice(0, 16) : '';
        vermietungForm.elements.bis.value = isEdit ? props.ende.slice(0, 16) : '';
        vermietungForm.elements.titel.value = isEdit ? props.anlass : '';
        vermietungForm.elements.kontakt.value = isEdit ? (props.kontakt ?? '') : '';
        vermietungForm.elements.bemerkung.value = isEdit ? (props.bemerkung ?? '') : '';

        vermietungDialog.showModal();
    };

    document.querySelector('#vermietung-cancel').addEventListener('click', () => vermietungDialog.close());

    const collectVermietungData = () => {
        const data = {};
        for (const [key, value] of new FormData(vermietungForm)) {
            if (key.endsWith('[]')) {
                (data[key.slice(0, -2)] ??= []).push(value);
            } else {
                data[key] = value;
            }
        }
        return data;
    };

    vermietungForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = collectVermietungData();
        const vermietungId = data.vermietung_id;
        const url = vermietungId === '' ? '/api/vermietungen' : `/api/vermietungen/${vermietungId}`;

        try {
            const result = await VK.post(url, data);
            if (result.ok) {
                vermietungDialog.close();
                calendar.refetchEvents();
            } else {
                vermietungFeedback.className = 'error-message';
                vermietungFeedback.textContent = VK.fehlerText(result.data);
            }
        } catch {
            // name dialog cancelled
        }
    });

    // ---- restriction dialog (Issue #64) ----
    // Bearbeiten öffentlich (Ebene 2) wie ein manuelles Spiel/eine
    // Vermietung; Anlegen bleibt der Verfügbarkeitsansicht vorbehalten
    // (CLAUDE.md Abschnitt 8: das "+ Eintragen"-Sheet kennt nur Belegung/
    // Spiel/Vermietung), daher hier kein "new"-Aufruf mit props === null.
    const restrictionDialog = document.querySelector('#restriction-dialog');
    const restrictionForm = document.querySelector('#restriction-form');
    const restrictionFeedback = document.querySelector('#restriction-feedback');
    const restrictionTitle = document.querySelector('#restriction-title');
    const restrictionSubmit = document.querySelector('#restriction-submit');
    const restrictionCancel = document.querySelector('#restriction-cancel');
    const restrictionPitchSelect = document.querySelector('#restriction-pitch');
    for (const pitch of appData.pitches) {
        restrictionPitchSelect.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
    }

    const openRestrictionDialog = (props) => {
        restrictionForm.reset();
        restrictionFeedback.textContent = '';
        restrictionFeedback.className = '';
        restrictionCancel.textContent = 'Abbrechen';
        restrictionTitle.textContent = 'Sperrung/Einschränkung bearbeiten';
        restrictionSubmit.textContent = 'Speichern';

        restrictionForm.elements.restriction_id.value = String(props.id);
        restrictionForm.elements.pitch_id.value = String(props.pitch_id);
        restrictionForm.elements.art.value = props.art;
        // datetime-local erwartet 'YYYY-MM-DDTHH:MM'
        restrictionForm.elements.von.value = props.von.slice(0, 16);
        restrictionForm.elements.bis.value = props.bis.slice(0, 16);
        restrictionForm.elements.grund.value = props.grund;

        restrictionDialog.showModal();
    };

    restrictionCancel.addEventListener('click', () => restrictionDialog.close());

    // wie beim Anlegen kein Konflikt-/Warnungs-Check (eine Sperrung blockiert
    // nie sich selbst); betrifft die Änderung bereits bestehende Termine,
    // liefert der Server das als reinen Hinweis zurück, der Dialog bleibt
    // dafür offen statt sofort zu schließen.
    restrictionForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(restrictionForm));

        try {
            const result = await VK.post(`/api/sperrungen/${data.restriction_id}`, data);
            if (result.ok) {
                calendar.refetchEvents();
                const betroffene = result.data.betroffene ?? [];
                if (betroffene.length > 0) {
                    restrictionFeedback.className = 'warning-message';
                    const intro = document.createElement('p');
                    intro.textContent = 'Hinweis: folgende bereits bestehende Termine sind jetzt betroffen (sie bleiben wie gespeichert):';
                    const liste = document.createElement('ul');
                    liste.className = 'konflikt-liste';
                    for (const text of betroffene) {
                        const li = document.createElement('li');
                        li.textContent = text;
                        liste.append(li);
                    }
                    restrictionFeedback.replaceChildren(intro, liste);
                    restrictionCancel.textContent = 'Schließen';
                    return;
                }
                restrictionDialog.close();
            } else {
                restrictionFeedback.className = 'error-message';
                restrictionFeedback.textContent = VK.fehlerText(result.data);
            }
        } catch {
            // name dialog cancelled
        }
    });

    // ---- booking + exception dialogs ----
    // Issue #37: nicht mehr nur in der Platzbelegung - showDetail() öffnet
    // openEdit()/openAusfallDialog() für jedes Belegungs-Event, unabhängig
    // von der Ansicht, daher müssen diese Dialoge immer verdrahtet sein.

    const bookingDialog = document.querySelector('#booking-dialog');
    const bookingForm = document.querySelector('#booking-form');
    const bookingFeedback = document.querySelector('#booking-feedback');
    const bookingSubmit = bookingForm.querySelector('button[type="submit"]');
    const bookingTitle = document.querySelector('#booking-title');
    const wochentageFeld = document.querySelector('#booking-wochentage-feld');
    const gueltigFeld = document.querySelector('#booking-gueltig-feld');
    const datumFeld = document.querySelector('#booking-datum-feld');
    let warnungenBestaetigt = false;
    let bookingScope = ''; // '' = neue Belegung, sonst edit_scope

    const bookingTeams = document.querySelector('#booking-teams');
    for (const team of activeTeams) {
        const label = document.createElement('label');
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.name = 'team_ids[]';
        box.value = String(team.id);
        label.append(box, ` ${team.name} (${teamBereichName(team)})`);
        bookingTeams.append(label);
    }
    const bookingPitchSelect = document.querySelector('#booking-pitch');
    for (const pitch of appData.pitches) {
        bookingPitchSelect.add(new Option(`${pitch.name} (${pitch.venue_name})`, String(pitch.id)));
    }

    const bookingTitles = {
        '': 'Belegung eintragen',
        alle: 'Alle Termine der Serie bearbeiten',
        nachfolgende: 'Diesen und folgende Termine bearbeiten',
        einzeln: 'Einzelnen Termin bearbeiten',
    };

    const openBookingDialog = (scope, slotId, datum, prefill) => {
        bookingForm.reset();
        bookingFeedback.textContent = '';
        bookingFeedback.className = '';
        bookingSubmit.textContent = 'Speichern';
        warnungenBestaetigt = false;
        bookingScope = scope;
        bookingTitle.textContent = bookingTitles[scope];

        bookingForm.elements.edit_scope.value = scope;
        bookingForm.elements.slot_id.value = slotId !== null ? String(slotId) : '';
        bookingForm.elements.datum.value = datum ?? '';

        for (const box of bookingForm.querySelectorAll('input[name="team_ids[]"]')) {
            box.checked = (prefill.team_ids ?? []).includes(Number(box.value));
        }
        for (const box of bookingForm.querySelectorAll('input[name="wochentage[]"]')) {
            box.checked = (prefill.wochentage ?? []).includes(Number(box.value));
        }
        if (prefill.pitch_id !== undefined && prefill.pitch_id !== null) {
            bookingForm.elements.pitch_id.value = String(prefill.pitch_id);
        }
        for (const feld of ['beginn', 'ende', 'gueltig_ab', 'gueltig_bis', 'datum_neu']) {
            if (prefill[feld]) {
                bookingForm.elements[feld].value = prefill[feld];
            }
        }

        // 'einzeln' edits one concrete date instead of weekdays + validity
        const einzeln = scope === 'einzeln';
        wochentageFeld.hidden = einzeln;
        gueltigFeld.hidden = einzeln;
        datumFeld.hidden = !einzeln;
        bookingForm.elements.gueltig_ab.required = !einzeln;
        bookingForm.elements.gueltig_bis.required = !einzeln;
        bookingForm.elements.datum_neu.required = einzeln;
        // 'nachfolgende' starts at the clicked occurrence, the server pins it
        bookingForm.elements.gueltig_ab.readOnly = scope === 'nachfolgende';

        bookingDialog.showModal();
    };

    document.querySelector('#booking-cancel').addEventListener('click', () => bookingDialog.close());

    // ---- edit scope choice (alle / nachfolgende / einzeln) ----

    const scopeDialog = document.querySelector('#scope-dialog');
    let scopeProps = null;
    document.querySelector('#scope-cancel').addEventListener('click', () => scopeDialog.close());
    for (const button of scopeDialog.querySelectorAll('button[data-scope]')) {
        button.addEventListener('click', () => {
            scopeDialog.close();
            if (scopeProps) {
                startEdit(scopeProps, button.dataset.scope);
            }
        });
    }

    const startEdit = (props, scope) => {
        const datum = props.start.slice(0, 10);
        openBookingDialog(scope, props.slot_id, datum, {
            team_ids: props.team_ids,
            pitch_id: props.pitch_id,
            wochentage: props.wochentage,
            beginn: props.start.slice(11, 16),
            ende: props.ende.slice(11, 16),
            gueltig_ab: scope === 'nachfolgende' ? datum : props.gueltig_ab,
            gueltig_bis: props.gueltig_bis,
            datum_neu: datum,
        });
    };

    const openEdit = (props) => {
        if (props.gueltig_ab === props.gueltig_bis) {
            // one-day booking: no series, nothing to ask
            startEdit(props, 'alle');
            return;
        }
        scopeProps = props;
        scopeDialog.showModal();
    };

    // checkbox groups need arrays; Object.fromEntries would drop them
    const collectBookingData = () => {
        const data = {};
        for (const [key, value] of new FormData(bookingForm)) {
            if (key.endsWith('[]')) {
                (data[key.slice(0, -2)] ??= []).push(value);
            } else {
                data[key] = value;
            }
        }
        return data;
    };

    bookingForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const data = collectBookingData();
        const url = bookingScope === '' ? '/api/slots' : `/api/slots/${data.slot_id}`;

        try {
            if (!warnungenBestaetigt) {
                const check = await VK.post('/api/slots/pruefen', data);
                if (!check.ok) {
                    bookingFeedback.className = 'error-message';
                    bookingFeedback.textContent = VK.fehlerText(check.data);
                    return;
                }
                if (check.data.konflikte.length > 0) {
                    renderKonfliktGruppen(bookingFeedback, check.data.konflikte);
                    return;
                }
                if (check.data.warnungen.length > 0) {
                    // 'eingeschraenkt': booking allowed, but the dialog must
                    // show the warning first (CLAUDE.md section 4)
                    renderKonfliktGruppen(bookingFeedback, check.data.warnungen, { warnung: true });
                    bookingSubmit.textContent = 'Trotzdem speichern';
                    warnungenBestaetigt = true;
                    return;
                }
            }

            const result = await VK.post(url, data);
            if (result.ok) {
                bookingDialog.close();
                calendar.refetchEvents();
            } else {
                bookingFeedback.className = 'error-message';
                bookingFeedback.textContent = VK.fehlerText(result.data);
                bookingSubmit.textContent = 'Speichern';
                warnungenBestaetigt = false;
            }
        } catch {
            // name dialog cancelled
        }
    });

    const ausfallDialog = document.querySelector('#ausfall-dialog');
    const ausfallForm = document.querySelector('#ausfall-form');
    const ausfallFeedback = document.querySelector('#ausfall-feedback');
    document.querySelector('#ausfall-cancel').addEventListener('click', () => ausfallDialog.close());

    const openAusfallDialog = (slotId, datum) => {
        ausfallForm.reset();
        ausfallFeedback.textContent = '';
        ausfallForm.elements.slot_id.value = String(slotId);
        ausfallForm.elements.datum.value = datum;
        ausfallDialog.showModal();
    };

    ausfallForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const slotId = ausfallForm.elements.slot_id.value;
        const data = Object.fromEntries(new FormData(ausfallForm));

        try {
            const result = await VK.post(`/api/slots/${slotId}/ausfall`, data);
            if (result.ok) {
                ausfallDialog.close();
                calendar.refetchEvents();
            } else {
                ausfallFeedback.className = 'error-message';
                ausfallFeedback.textContent = VK.fehlerText(result.data);
            }
        } catch {
            // name dialog cancelled
        }
    });

    // ---- "+ Eintragen"-Sheet (Issue #37) ----
    // Ein gemeinsamer Toolbar-Button statt der früheren zwei ("Belegung
    // eintragen" / "Spiel eintragen") öffnet ein kleines Auswahl-Sheet, das
    // die bestehenden Dialoge öffnet - erst hier verdrahtet, weil sowohl
    // openBookingDialog als auch openMatchDialog erst oben definiert werden.
    const entryDialog = document.querySelector('#entry-dialog');
    document.querySelector('#new-entry').addEventListener('click', () => entryDialog.showModal());
    document.querySelector('#entry-cancel').addEventListener('click', () => entryDialog.close());
    document.querySelector('#entry-booking').addEventListener('click', () => {
        entryDialog.close();
        openBookingDialog('', null, null, {});
    });
    document.querySelector('#entry-match').addEventListener('click', () => {
        entryDialog.close();
        openMatchDialog(null);
    });
    document.querySelector('#entry-vermietung').addEventListener('click', () => {
        entryDialog.close();
        openVermietungDialog(null);
    });
})();
