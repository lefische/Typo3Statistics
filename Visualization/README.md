# Netzfunke Statistik

Interaktive Auswertung der TYPO3-Exporte zur Redaktionsaktivität der Vereinsseiten.

## Benutzung

1. `netzfunke-statistik.html` in den Ordner mit den CSV-Exporten legen.
2. Die HTML-Datei per Doppelklick im Browser öffnen.
3. `last_change_per_tree.csv` und `changes_per_month_history.csv` hineinziehen oder über „Dateien auswählen …“ laden.

Die Seite arbeitet vollständig offline. Die Daten werden nur im Browser verarbeitet.

## Anpassen

Am Anfang des `<script>`-Blocks stehen die Konstanten:

- `INTERNAL_UIDS`: interne Seitenbäume, die standardmäßig ausgeblendet sind
- `STATUS`: Ampel-Schwellen in Tagen
- `HM_BINS`: Farbstufen der Heatmap

Die Anforderungen stehen in [`docs/ANFORDERUNGEN.md`](docs/ANFORDERUNGEN.md).
