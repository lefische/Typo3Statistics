# TYPO3 Statistik

Interaktive Auswertung der TYPO3-Exporte zur Redaktionsaktivität der Vereinsseiten.

## Benutzung

1. `typo3-statistik.html` in den Ordner mit den CSV-Exporten legen.
2. Die HTML-Datei per Doppelklick im Browser öffnen.
3. `last_change_per_tree.csv` und `changes_per_month_history.csv` hineinziehen oder über „Dateien auswählen …“ laden.

Die Seite arbeitet vollständig offline. Die Daten werden nur im Browser verarbeitet.

## Drucken und PDF

Im Bereich **Lage** öffnet „Drucken / als PDF speichern“ den Druckdialog des Browsers; dort als Ziel „Als PDF speichern“
wählen. Die normale Druckfunktion (Strg+P bzw. Cmd+P) funktioniert ebenso – auch aus den anderen Bereichen heraus.
Gedruckt wird immer die Lage, mit allen Gruppen (Brauchen Aufmerksamkeit, Ruhig, Aktiv) aufgeklappt, im hellen
Farbschema auf A4 hochkant. Der Dateiname enthält den Stichtag, z. B. „TYPO3 Statistik – Lage 13.10.2026“. Nach dem
Drucken ist die Ansicht wieder wie vorher. Damit die farbigen Symbole und Balken erscheinen, im Druckdialog ggf.
„Hintergrundgrafiken“ aktivieren.

## Anpassen

Am Anfang des `<script>`-Blocks stehen die Konstanten:

- `INTERNAL_UIDS`: interne Seitenbäume, die standardmäßig ausgeblendet sind
- `STATUS`: Ampel-Schwellen in Tagen
- `HM_BINS`: Farbstufen der Heatmap

Die Anforderungen stehen in [`docs/ANFORDERUNGEN.md`](docs/ANFORDERUNGEN.md).
