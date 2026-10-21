# Anforderungen: TYPO3 Statistik-Viewer

**Status:** v1.0 (abgestimmt)
**Stand:** 2026-10-14

## 1. Ziel

Ein Werkzeug für das **TYPO3-Team (intern)**, das die aus TYPO3 exportierten Statistiken interaktiv darstellt. Es soll schnell zeigen:

- welche Vereine ihre Seiten aktiv pflegen,
- welche Vereine „eingeschlafen“ sind und vielleicht Unterstützung brauchen,
- wie sich die Redaktionsaktivität insgesamt entwickelt.

## 2. Rahmenbedingungen

| # | Anforderung |
|---|---|
| R1 | Das Tool ist **eine einzige HTML-Datei** (`typo3-statistik.html`). CSS, JavaScript, Icons und Grafiken sind vollständig eingebettet. |
| R2 | Es gibt **keine externen Abhängigkeiten**: kein CDN, keine Webfonts aus dem Netz. Das Tool funktioniert komplett offline. |
| R3 | Die Datei wird **lokal per Doppelklick** geöffnet (`file://`). Ein Webserver ist nicht nötig. |
| R4 | Die CSV-Dateien liegen im selben Verzeichnis. Weil Browser unter `file://` kein automatisches Nachladen erlauben, werden sie **per Drag & Drop oder Dateiauswahl** geladen. Beide Dateien können gleichzeitig ausgewählt werden. |
| R5 | Die Daten werden nur im Browser verarbeitet. Nichts wird hochgeladen oder gespeichert. |
| R6 | Unterstützte Browser: aktuelle Versionen von Chrome, Edge, Firefox und Safari (Desktop). |
| R7 | Die Oberfläche ist auf Deutsch. Datumsformat `TT.MM.JJJJ`, Zahlen im deutschen Format. |

## 3. Eingabedaten

### 3.1 Datei A: Letzte Änderung je Seitenbaum

Dateiname typisch: `last_change_per_tree.csv`

```
3) Letzte Änderung je Seitenbaum – neuester Zeitstempel von Seiten und Inhalten
root_uid;root_title;seiten;letzte_aenderung
1268;"FFW Birkach";36;2026-10-13 19:39:22
```

| Spalte | Typ | Bedeutung |
|---|---|---|
| `root_uid` | Ganzzahl | ID des Seitenbaums (Schlüssel) |
| `root_title` | Text | Name des Vereins/Seitenbaums |
| `seiten` | Ganzzahl | Anzahl Seiten im Baum |
| `letzte_aenderung` | Datum/Zeit | Neuester Zeitstempel aus Seiten und Inhalten |

### 3.2 Datei B: Änderungen pro Monat

Dateiname typisch: `changes_per_month_history.csv`

```
1) Änderungen pro Monat und Seitenbaum – Änderungsverlauf (sys_history), Seiten + Inhalte, letzte 12 Monate
root_uid;root_title;monat;aenderungen;letzte_aenderung_im_monat
49;"Heimatverein Burgebrach";2025-11;228;2025-11-27 21:27:59
```

| Spalte | Typ | Bedeutung |
|---|---|---|
| `root_uid` | Ganzzahl | ID des Seitenbaums |
| `root_title` | Text | Name |
| `monat` | `JJJJ-MM` | Monat |
| `aenderungen` | Ganzzahl | Anzahl Änderungen laut `sys_history` |
| `letzte_aenderung_im_monat` | Datum/Zeit | Letzte Änderung in diesem Monat |

### 3.3 Regeln für das Einlesen

| # | Regel |
|---|---|
| D1 | Die **erste Zeile ist ein Titel** und wird übersprungen. Ihr Text wird als Datenquelle angezeigt. Die zweite Zeile ist die Kopfzeile. |
| D2 | Trennzeichen ist `;`. Texte können in `"` stehen. Leere Zeilen werden ignoriert. Zeichensatz UTF-8, eine BOM wird toleriert. |
| D3 | **Welche Datei welche ist, wird an den Spaltennamen erkannt**, nicht am Dateinamen. |
| D4 | Beide Dateien werden über `root_uid` verknüpft. Als Name gilt `root_title` aus Datei A. |
| D5 | **Fehlende Monate** in Datei B werden als **0 Änderungen** gewertet. |
| D6 | Der Betrachtungszeitraum wird aus Datei B abgeleitet (aktuell 2025-11 bis 2026-10, 12 Monate). |
| D7 | Bäume, die in Datei A stehen, aber nicht in Datei B, hatten im Zeitraum keine Änderung (aktuell 6 Bäume). Sie erscheinen mit 0. |
| D8 | Unplausible Zeilen (falsche Spaltenzahl, ungültiges Datum) werden übersprungen und gezählt. Die Anzahl wird als Hinweis angezeigt. |
| D9 | Es reicht, nur eine Datei zu laden. Ansichten, für die Daten fehlen, zeigen einen Hinweis statt eines Fehlers. |

### 3.4 Stichtag

Die Angabe „Tage seit letzter Änderung“ wird relativ zu einem **Stichtag** berechnet. Als „letzte Änderung“ gilt immer der Wert aus Datei A. Die Zeitstempel in Datei B (`sys_history`) weichen teils um Sekunden ab und werden nur innerhalb der Monate angezeigt. Standard für den Stichtag ist der **neueste Zeitstempel in den Daten** (≈ Exportdatum), damit ältere Exporte richtig bewertet werden. Der Stichtag lässt sich auf „heute“ umstellen.

## 4. Funktionen

### 4.1 Globale Steuerung (immer sichtbar)

| # | Funktion |
|---|---|
| G1 | Bereich zum Laden der CSVs (Drag & Drop + Button). Danach eine kompakte Anzeige der geladenen Dateien mit Zeilenzahl und Option „neu laden“. |
| G2 | **Suchfeld** nach Vereinsname, wirkt auf alle Ansichten. |
| G3 | Schalter **„Interne Seitenbäume anzeigen“**, standardmäßig **aus**. Intern sind per Ausschlussliste: `TYPO3 Serverwartung` (626), `Schulung` (1240), `Beispielseite Vereine` (3002). Die Liste steht als Konstante im Code (über `root_uid`). „TYPO3 Burgebrach“ (2461) ist ein Verein und gehört nicht dazu. |
| G4 | Filter nach Ampelstatus (grün/gelb/rot/grau). |
| G5 | Auswahl des Stichtags (siehe 3.4). |

### 4.2 Ansicht 1: Ampel-Übersicht

Eine Tabelle mit allen Seitenbäumen.

| Spalte | Inhalt |
|---|---|
| Status | Farbiger Punkt plus Text (aus Gründen der Barrierefreiheit nicht nur Farbe) |
| Verein | `root_title` |
| Seiten | `seiten` |
| Letzte Änderung | Datum |
| Tage seit Änderung | Zahl |
| Änderungen (12 Monate) | Summe aus Datei B |
| Aktive Monate | Anzahl Monate mit mehr als 0 Änderungen, z. B. „9 / 12“ |
| Verlauf | **Sparkline** der 12 Monate |

- Alle Spalten sind per Klick auf den Kopf sortierbar. Standard: Tage seit Änderung, aufsteigend.
- Ein Klick auf eine Zeile öffnet die Detailansicht (4.4).
- Über der Tabelle zeigen **Kennzahl-Kacheln**: Anzahl Bäume gesamt, je Ampelfarbe sowie Änderungen gesamt im Zeitraum.

**Ampel-Schwellen** (abgestimmt, als Konstanten im Code anpassbar):

| Status | Tage seit letzter Änderung |
|---|---|
| 🟢 aktiv | ≤ 30 |
| 🟡 ruhig | 31 – 90 |
| 🟠 inaktiv | 91 – 180 |
| 🔴 eingeschlafen | > 180 |

### 4.3 Ansicht 2: Heatmap Verein × Monat

- **Zeilen** sind Vereine, **Spalten** die Monate des Zeitraums. Die Farbintensität zeigt die Zahl der Änderungen.
- Weil die Werte stark streuen (meist 1–50, Ausreißer bis 399), ist die Farbskala **logarithmisch oder in Stufen** eingeteilt (z. B. 0 / 1–5 / 6–20 / 21–50 / 51–150 / > 150). Eine Legende ist sichtbar.
- Monate mit 0 Änderungen sind deutlich von „wenig“ unterscheidbar (z. B. leer oder hellgrau).
- Ein Tooltip zeigt Verein, Monat, Anzahl und letzte Änderung im Monat.
- Sortierbar nach Name, Summe oder letzter Änderung.
- Ein Klick auf einen Vereinsnamen öffnet die Detailansicht.
- Eine Summenzeile am Ende zeigt die Gesamtänderungen pro Monat.

### 4.4 Ansicht 3: Detail je Verein

Wird aus Ansicht 1 oder 2 geöffnet oder über eine Auswahlliste.

- Kopf: Name, `root_uid`, Seitenanzahl, letzte Änderung, Ampelstatus, Tage seit Änderung.
- **Balkendiagramm** der Änderungen pro Monat (12 Monate, Monate mit 0 sichtbar). Der Tooltip zeigt die letzte Änderung im Monat.
- Kennzahlen: Summe, Durchschnitt pro Monat, aktivster Monat, aktive Monate.
- Vergleichslinie: Median aller Vereine pro Monat (optional ein- und ausblendbar).
- Navigation „vorheriger / nächster Verein“ in der aktuellen Sortierung.

### 4.5 Ansicht 4: Gesamtentwicklung

- **Säulendiagramm:** Summe aller Änderungen pro Monat.
- **Linie:** Anzahl aktiver Vereine pro Monat (mit mehr als 0 Änderungen).
- **Rangliste:** Top 10 nach Änderungen im Zeitraum.
- **Verteilung:** Anzahl Vereine je Ampelstatus.
- Alle Werte berücksichtigen die globalen Filter (G2–G4).

## 5. Bedienung und Darstellung

| # | Anforderung |
|---|---|
| U1 | Die Ansichten werden über **Tabs** gewechselt. Der aktuelle Tab und der gewählte Verein stehen im URL-Hash (`#heatmap`, `#verein/1268`), damit Neuladen und Zurück-Taste funktionieren. Die Daten müssen nach dem Neuladen trotzdem erneut geladen werden. |
| U2 | Für Laptop-Bildschirme ab 1280 px Breite optimiert. Auf Tablets nutzbar; auf dem Smartphone reicht die Ampel-Tabelle. |
| U3 | Diagramme als **eigenes SVG** (keine Fremdbibliothek), damit die Datei klein bleibt (Ziel < 150 KB). |
| U4 | Farbpalette auch bei Farbschwäche unterscheidbar. Der Status ist zusätzlich per Text oder Symbol erkennbar. |
| U5 | Hell- und Dunkelmodus folgen der Systemeinstellung. |
| U6 | Lange Vereinsnamen werden in Heatmap-Zeilen gekürzt, der volle Name erscheint als Tooltip. |

## 6. Nicht im Umfang

- Direkte Anbindung an TYPO3 oder die Datenbank. Die Daten kommen ausschließlich aus den CSV-Exporten.
- Speichern von Verläufen über mehrere Exporte hinweg.
- Benutzerverwaltung oder Login.
- Export als CSV. (Die Ansicht „Lage“ lässt sich inzwischen drucken bzw. als PDF speichern, siehe README.)
- Weitere TYPO3-Abfragen. Es gibt nur diese beiden Exporte.
- Echte CSV-Daten im Repository. Die Vereinsdaten werden nicht eingecheckt.

## 7. Abnahmekriterien

1. Die HTML-Datei öffnet sich per Doppelklick offline. Die beiden aktuellen CSVs lassen sich per Drag & Drop laden.
2. Alle 33 Bäume aus Datei A erscheinen, 30 bei ausgeblendeten internen Bäumen.
3. Die 6 Bäume ohne Einträge in Datei B erscheinen mit 0 Änderungen und korrektem Ampelstatus.
4. Stichprobe JFG Frensdorf: Summe 12 Monate = 750, aktive Monate 11 / 12, Mai 2026 = 0.
5. Heatmap und Diagramme bleiben trotz Ausreißern (399, 358) lesbar.
6. Die Browser-Konsole zeigt keine Netzwerkanfragen und keine Fehler.

## 8. Entscheidungen

| # | Frage | Entscheidung |
|---|---|---|
| 1 | Ampel-Schwellen | 30 / 90 / 180 Tage wie vorgeschlagen |
| 2 | Interne Bäume | 626, 1240, 3002. „TYPO3 Burgebrach“ (2461) ist ein Verein. |
| 3 | Maßgeblicher Zeitstempel für „letzte Änderung“ | Datei A |
| 4 | Weitere Abfragen (z. B. „2)“) | Keine |
| 5 | CSVs als Testdaten im Repository | Nein |
| 6 | Export / Druckansicht | CSV-Export entfällt. Druck/PDF der Ansicht „Lage“ mit allen Gruppen aufgeklappt (nachträglich ergänzt). |
