# PVBatteryEconomics (Symcon)

Symcon Modul zur wirtschaftlichen Bewertung eines Batteriespeichers auf Basis von stündlich aggregierten Zählerdaten.

Das Modul vergleicht:
- Szenario ohne Batterie
- Szenario mit Batterie

und berechnet daraus u. a. Kosten, Ersparnis und Amortisation.

## Funktionen

- Simulation mit stündlichen Werten für:
  - Netzbezug
  - Netzeinspeisung
- Wirtschaftlichkeitskennzahlen:
  - Kosten ohne Batterie
  - Kosten mit Batterie
  - Ersparnis
  - Amortisationszeit
  - Äquivalente Vollzyklen
- Zusätzliche Batterie-Kennwerte:
  - In Batterie geladen (kWh)
  - Aus Batterie an Last abgegeben (kWh)
  - Batterieverluste (kWh)
- Debug-Ausgaben für:
  - Tageswerte (`DailyValues`)
  - Monatswerte (`MonthlyValues`)

## Vereinfachte Bedienung

Für Endanwender wurden technische Optionen entfernt:
- kein auswählbares Aggregat-Feld
- kein manueller Start-Ladezustand

Interne Festwerte:
- Aggregat-Feld: `Avg`
- Start-Ladezustand: `50%` der konfigurierten Batteriekapazität

## Voraussetzungen

- Symcon ab Version 8.0
- Zwei geeignete Zählervariablen:
  - Netzbezug
  - Netzeinspeisung
- Vorhandene und sinnvolle Archiv-Aggregation

## Installation

1. Repository nach `Symcon/modules` kopieren oder klonen.
2. In Symcon: `Kerninstanzen -> Module` neu laden.
3. Instanz `PV Battery Economics` anlegen.

## Konfiguration

- Zeitraum (`StartDate`, `EndDate`)
- Datenquellen:
  - Zähler Netzbezug
  - Zähler Netzeinspeisung
  - Umrechnungsfaktor auf kWh
- Preise:
  - Bezugspreis (EUR/kWh)
  - Einspeisevergütung (EUR/kWh)
- Batterieparameter:
  - Kapazität
  - Lade-/Entladeleistung
  - Wirkungsgrade
  - Investition
- Plausibilitätsgrenze für niedrigen Netzbezug

## Bedienung

1. Konfiguration setzen.
2. Button `Berechnung starten` ausführen.
3. Ergebnisse in den Variablen und in `Zusammenfassung` prüfen.

## Debugging

Im Symcon-Debug der Instanz erscheinen JSON-Ausgaben:

- `DailyValues` mit Schlüsseln je Tag (`YYYY-MM-DD`)
- `MonthlyValues` mit Schlüsseln je Monat (`YYYY-MM`)

Je Periode enthalten:
- `baseline_import_kwh`
- `baseline_export_kwh`
- `sim_import_kwh`
- `sim_export_kwh`
- `baseline_cost_eur`
- `sim_cost_eur`
- `saving_eur`

## Hinweise

- Wenn Ergebnisse unplausibel klein wirken, ist meist die Einheit/Archiv-Aggregation die Ursache.
- Für belastbare Aussagen empfiehlt sich ein vollständiges Jahr als Zeitraum.
