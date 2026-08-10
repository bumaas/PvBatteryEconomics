# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Was das Modul ist

Symcon-Modulbibliothek mit **einem** Modul (`PVBatteryEconomics`, Präfix `PVBAT`, Typ 3 =
reines Gerätemodul ohne Parent). Es liest historische Stundenwerte zweier Zählervariablen
(Netzbezug/Netzeinspeisung) aus dem Archiv, simuliert stundenweise einen Batteriespeicher und
schreibt Wirtschaftlichkeitskennzahlen in Statusvariablen. Rein auswertend — es schaltet nichts
und hat keinen Timer; alles läuft synchron im Button `PVBAT_Calculate($id)`.

## Wichtig: Das Repo liegt im Produktivverzeichnis

`T:\modules\PVBatteryEconomics` ist das laufende Symcon-Modulverzeichnis (`\\nuc\Symcon`).
Eine Änderung an `module.php` ist sofort auf der Produktivinstallation. Nach Änderungen an
`module.json`/`library.json` (nicht bei reinen PHP-Änderungen) die Bibliothek neu einlesen:

```bash
C:/php/php C:/Users/Burkhard/.claude/tools/symcon_rpc.php MC_ReloadModule 51062 '"PVBatteryEconomics"'
```

Keine losen Dateien im Repo-Wurzelverzeichnis anlegen, die dort nicht hingehören.

## Prüfen / „Testen"

Es gibt kein Build-System und keine Unit-Tests, aber einen CI-Workflow
(`.github/workflows/check.yml`: PHP-Syntax, JSON-Validität, Übersetzungen). Vor jedem Commit
dieselben Schritte lokal:

```bash
C:/php/php -l PVBatteryEconomics/module.php          # Syntaxprüfung
C:/php/php tests/check_locale.php                    # Übersetzungs-Vollständigkeit
C:/php/php -r "json_decode(file_get_contents('PVBatteryEconomics/form.json'), false, 512, JSON_THROW_ON_ERROR);"
```

Funktional geprüft wird nur live: Instanz öffnen, Debug aktivieren, „Berechnung starten"
klicken und die `DayValue`/`MonthValue`-Zeilen sowie `Zusammenfassung` gegenlesen.

## Datenfluss in `Calculate()`

Alles hängt an einer Kette von Arrays, die **auf den Unix-Timestamp des Stundenbeginns
geschlüsselt** sind (`$hourlyImport[$ts]`, `$hourlyExport[$ts]`, `$hourlyImportPrice[$ts]`, …):

1. `getArchiveId()` sucht die Archive-Control über deren Modul-GUID `{43192F0B-…}`.
2. `getHourlyEnergyFromAggregates()` holt je Variable `AC_GetAggregatedValues(..., 0, ...)`
   (Aggregationsstufe 0 = stündlich) und baut die Stundenmap.
3. `$hourKeys` = **Schnittmenge** der Stunden beider Zähler, sortiert. Jede Stunde, die nur in
   einer der beiden Reihen existiert, fällt hier stillschweigend heraus — das ist die übliche
   Ursache für „zu kleine" Ergebnisse.
4. `calculateBaseline()` (Summen ohne Batterie) und `simulateBattery()` (Stundenschleife) laufen
   über dieselben `$hourKeys`.
5. `getHourlyImportPrices()` liefert entweder für jede Stunde den festen `PriceImport` oder die
   dynamischen Archivwerte.
6. `calculateEconomics()` bewertet stundenweise, `buildSummary()` und `buildPeriodValues()`
   formatieren, `sendPeriodDebug()` gibt je Monat erst alle Tage, dann den Monatswert aus.

### Fachliche Festlegungen, die man dem Code nicht sofort ansieht

- **Zählerwerte sind bereits Stundenverbräuche.** Es wird kein Zählerstands-Delta gebildet:
  `extractHourlyEnergyKwh()` nimmt `Avg` (Konstante `DEFAULT_AGGREGATE_FIELD = 'Avg'`,
  bewusst nicht konfigurierbar) und rechnet nur mit `CounterUnitToKWh` um; `Max - Min` ist nur
  der Fallback, wenn `Avg` fehlt.
- **Zeitstempel-Ambiguität:** `mapRowToHourStart()` akzeptiert Archivzeilen, die auf den
  Stundenanfang *oder* auf das Stundenende gestempelt sind (Versuch, dann −3600 s). Beim Ändern
  der Archivlogik diese Toleranz beibehalten.
- **Simulationsreihenfolge je Stunde:** erst Entladen gegen das Defizit, dann Laden aus dem
  Überschuss. Der SoC wird batterieseitig geführt — Entladen teilt durch den Wirkungsgrad,
  Laden multipliziert damit; die Differenz landet in `battery_losses_kwh`. Start-SoC fest 50 %
  (`DEFAULT_INITIAL_SOC_RATIO`), ebenfalls bewusst nicht konfigurierbar.
- **Amortisation basiert nicht auf `SavingEUR`,** sondern auf dem Netto-Vorteil
  (vermiedene Bezugskosten − entgangene Einspeisevergütung), über `calculatePeriodYears()`
  (365,25 Tage) auf ein Jahr normiert. Nicht erreichbar → Variable 0, Zusammenfassung
  „nicht erreichbar".
- **Dynamischer Bezugspreis ist strikt:** fehlt auch nur eine Stunde, bricht
  `getHourlyImportPrices()` mit Exception ab (statt still auf den Festpreis zu fallen) und
  meldet vorher per Debug `ImportPriceCoverage`/`ImportPriceMissingHour` die Abdeckung.
- **Fehlerbehandlung:** `Calculate()` fängt jedes `Throwable`, schreibt „Fehler: …" nach
  `Summary`, setzt `IS_EBASE + 1` und wirft weiter, damit der Fehler auch in der Konsole steht.
  Fehlermeldungen nennen immer konkrete Zahlen (vorhandene vs. erwartete Stunden, erste
  fehlende Stunde) — dieses Muster bei neuen Meldungen fortführen.

## Konventionen in diesem Repo

- **Neue Konfiguration = zwei Stellen:** `RegisterProperty*` in `Create()` **und** ein Element
  mit identischem `name` in `form.json`. Statusvariablen ausschließlich in
  `registerStatusVariables()` (wird aus `Create()` *und* `ApplyChanges()` gerufen).
- **Sprache:** Bezeichner/Idents und Array-Schlüssel englisch. Anwendertexte stehen **englisch**
  in `form.json` und als `$this->Translate('…')` in `module.php` — dieser englische Text ist
  zugleich der Übersetzungsschlüssel; `PVBatteryEconomics/locale.json` liefert das Deutsche
  (echte Umlaute). `sprintf`-Platzhalter gehören mit in den Schlüssel
  (`sprintf($this->Translate('Grid import: %.1f kWh'), …)`). Ausgenommen sind die
  `SendDebug()`-Texte: reine Entwickler-Ausgaben, bewusst deutsch und nicht übersetzt.
  Jede Textänderung sofort mit `tests/check_locale.php` gegenprüfen (0 fehlend **und**
  0 verwaist).
- **Darstellung:** Presentations, keine Profile. `getValuePresentation()` liefert die
  `VARIABLE_PRESENTATION_VALUE_PRESENTATION`-Arrays für kWh- und EUR-Werte (Nachfolger von
  `~Electricity`/`~Euro`); `Summary` nutzt dieselbe Presentation mit `MULTILINE`.
- **Version/Build:** `library.json` im Wurzelverzeichnis pflegen (`build` +1, `date` auf
  `date +%s`), Commit-Subject `1.0 build <NN>: <Beschreibung>`.

## Altlast in Bestandsinstanzen

Ältere Modulversionen legten zusätzlich `ExtraPotential*`-Variablen an
(„Zusatzakku-Potenzial …"). Der heutige Code registriert sie nicht mehr, entfernt sie aber
auch nicht — in Bestandsinstanzen (z. B. #43810) hängen sie als verwaiste Variablen mit
Legacy-Profil unter der Instanz. Wer sie aufräumen will, braucht ein einmaliges
`UnregisterVariable()` in `ApplyChanges()`; das löscht Anwenderdaten und ist bewusst noch
nicht implementiert.
