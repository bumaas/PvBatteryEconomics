# Vorstellung: PVBatteryEconomics (IP-Symcon Modul)

Hallo zusammen,

ich möchte mein neues Modul **PVBatteryEconomics** vorstellen.

Damit lässt sich auf Basis eurer stündlichen Zählerdaten abschätzen, wie sich ein Batteriespeicher wirtschaftlich auswirkt.

## Was macht das Modul?

Das Modul vergleicht zwei Szenarien:
- ohne Batterie
- mit Batterie

Ausgegeben werden unter anderem:
- Netzbezug / Einspeisung
- Kosten ohne Batterie
- Kosten mit Batterie
- Ersparnis
- Amortisationszeit
- äquivalente Vollzyklen

Zusätzlich gibt es Batterie-Kennwerte:
- in Batterie geladen (kWh)
- aus Batterie an Last abgegeben (kWh)
- Batterieverluste (kWh)

## Fokus auf einfache Bedienung

Ich habe die Oberfläche bewusst vereinfacht, damit sie auch für weniger technische Anwender gut nutzbar ist.

Daher wurden zwei technische Eingaben entfernt:
- Aggregiertes Feld
- Start-Ladezustand

Diese sind jetzt intern fest definiert:
- Aggregation: `Avg`
- Start-SOC: `50%` der Batteriekapazität

## Debug-Transparenz

Für Analyse und Plausibilitätscheck schreibt das Modul im Debug:
- `DailyValues` (Tagessummen)
- `MonthlyValues` (Monatssummen)

jeweils inkl. Import/Export, Kosten und Ersparnis.

## Voraussetzungen

- IP-Symcon 8.0+
- stündlich aggregierte Zählerdaten für:
  - Netzbezug
  - Netzeinspeisung

## Feedback

Ich freue mich über Rückmeldungen, Verbesserungsvorschläge und reale Vergleichswerte aus euren Anlagen.
