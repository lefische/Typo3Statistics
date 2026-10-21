# TYPO3 Statistics – Änderungen pro Seitenbaum

Extension für **TYPO3 13.4** mit Multiroot-Setup. Pro Seitenbaum (Site) zeigt sie:

- das **Datum der letzten Änderung** im gesamten Baum (inkl. aller Unterseiten),
- die **Anzahl der Änderungen pro Monat**.

Eine Änderung ist jede Aktion an einem Datensatz (neu, geändert, verschoben, gelöscht), egal ob Seite,
Inhaltselement, News-Datensatz usw. Sie wird über die Seite, auf der der Datensatz liegt, dem richtigen Baum zugeordnet.

## Installation

```bash
composer config repositories.typo3-statistics vcs https://github.com/lefische/Typo3Statistics
composer require lefische/typo3-statistics:dev-main   # bzw. dev-<branchname>
vendor/bin/typo3 cache:flush
```

## Verwendung

```bash
# Alle Sites, letzte 12 Monate (Standard)
vendor/bin/typo3 statistics:changes

# Gesamter aufgezeichneter Zeitraum
vendor/bin/typo3 statistics:changes --months=0

# Ab einem bestimmten Monat, nur zwei Sites
vendor/bin/typo3 statistics:changes --since=2025-01 --site=main --site=shop

# Nur Seiten und Inhaltselemente zählen
vendor/bin/typo3 statistics:changes -t pages,tt_content

# Export für Excel (Semikolon-getrennt, eine Zeile pro Site und Monat)
vendor/bin/typo3 statistics:changes --months=24 --format=csv > aenderungen.csv

# JSON, z. B. für ein Dashboard
vendor/bin/typo3 statistics:changes --format=json
```

| Option                  | Bedeutung                                                                                   |
|-------------------------|---------------------------------------------------------------------------------------------|
| `-m, --months`          | Monate rückwirkend inkl. aktuellem Monat, `0` = alles (Standard: `12`)                      |
| `--since`               | Startmonat `JJJJ-MM` (hat Vorrang vor `--months`)                                           |
| `--source`              | `history` = `sys_history` (Standard) oder `log` = `sys_log`                                  |
| `-s, --site`            | Site-Identifier oder Seiten-UID; eine UID ohne Site wird als eigener Teilbaum ausgewertet   |
| `-t, --table`           | Nur diese Tabellen zählen                                                                   |
| `-x, --exclude-table`   | Diese Tabellen nicht zählen                                                                 |
| `-i, --ignore-field`    | Änderungen, die nur diese Felder betreffen, nicht zählen (Standard: `is_siteroot`; `none` = alles zählen) |
| `--include-inactive-roots` | Auch Seitenbäume auswerten, deren Wurzelseite nicht aktiv ist (siehe unten) |
| `-f, --format`          | `table` (Standard), `csv` oder `json`                                                       |

Beispielausgabe:

```
Übersicht
 -------- ------------------ -------- --------------------------- ------------------------------ ------------
  Site     Wurzelseite        Seiten   Letzte Änderung (Verlauf)   Letzte Änderung (Datensätze)   Änderungen
 -------- ------------------ -------- --------------------------- ------------------------------ ------------
  main     Home [1]           4        20.09.2026 12:00            12.10.2026 07:45               4
  zwei     Site Zwei [10]     4        01.10.2026 12:00            01.10.2026 12:00               3
 -------- ------------------ -------- --------------------------- ------------------------------ ------------

Änderungen pro Monat
 --------- ------ ------ -------
  Monat     main   zwei   Summe
 --------- ------ ------ -------
  2026-08   1      0      1
  2026-09   3      0      3
  2026-10   0      1      1
 --------- ------ ------ -------
```

Der Befehl ist `schedulable`, kann also auch als Scheduler-Task laufen.

### Spalten der Ausgabe

#### Tabelle „Übersicht“ (eine Zeile pro Seitenbaum)

| Spalte | Bedeutung |
|---|---|
| **Site** | Identifier der Site aus `config/sites/<identifier>/config.yaml`. Wird mit `--site` eine Seiten-UID ohne eigene Site ausgewertet, steht hier `page-<uid>`. |
| **Wurzelseite** | Titel der Startseite des Baums, in eckigen Klammern ihre UID (`rootPageId`). |
| **Seiten** | Anzahl aller Datensätze in `pages`, die zu diesem Baum gehören – inklusive Wurzelseite, Unterseiten aller Ebenen, Ordnern, versteckten, gelöschten und übersetzten Seiten. Seiten einer darin verschachtelten Site zählen nicht mit. Die Zahl hängt nicht vom Zeitraum ab. |
| **Letzte Änderung (Verlauf)** | Zeitpunkt der jüngsten **gezählten** Änderung im gewählten Zeitraum laut `sys_history` bzw. `sys_log`. Berücksichtigt `--table`, `--exclude-table` und `--ignore-field`. `–` = im Zeitraum keine Änderung. |
| **Letzte Änderung (Datensätze)** | Höchster `tstamp` (Feld „Zuletzt geändert“) aller Datensätze im Baum über alle Tabellen mit TCA – also Seiten, Inhaltselemente, News usw., auch versteckte und gelöschte. Unabhängig vom Zeitraum und davon, ob der Verlauf bereinigt wurde. Berücksichtigt `--table`/`--exclude-table`; stammt der `tstamp` von einer ignorierten Änderung, gilt die letzte echte Änderung bzw. das Erstellungsdatum. |
| **Änderungen** | Summe aller gezählten Änderungen im Zeitraum (= Summe der Spalte dieser Site in der Monatstabelle). |

Warum zwei Spalten „Letzte Änderung“? *Verlauf* zählt echte Bearbeitungsvorgänge, reicht aber nur so weit zurück wie
`sys_history`/`sys_log`. *Datensätze* ist immer vorhanden, kann aber auch durch Vorgänge ohne Verlaufseintrag (z. B.
Importe, Upgrade-Wizards, direkte Datenbank-Updates) gesetzt worden sein. Liegen beide weit auseinander, lohnt ein Blick
in den Verlauf.

#### Tabelle „Änderungen pro Monat“

| Spalte | Bedeutung |
|---|---|
| **Monat** | Kalendermonat im Format `JJJJ-MM` (Zeitzone der TYPO3-Installation). Alle Monate des Zeitraums werden aufgeführt, auch solche ohne Änderung. |
| **&lt;Site-Identifier&gt;** | Je Site eine Spalte: Anzahl der gezählten Änderungen in diesem Monat. |
| **Summe** | Summe über alle angezeigten Sites in diesem Monat. |

Eine **Änderung** ist ein Eintrag im Änderungsverlauf: jedes Speichern eines Datensatzes, Anlegen, Verschieben,
Löschen oder Wiederherstellen zählt einzeln. Wer eine Seite dreimal speichert, erzeugt drei Änderungen. Speichert man
eine Seite mit Übersetzungen, kann TYPO3 zusätzlich Einträge für die Übersetzungen anlegen.

Unter den Tabellen erscheinen ggf. Hinweise, wie viele Änderungen **nicht gezählt** wurden (nur ignorierte Felder wie
`is_siteroot` geändert) und wie viele **keinem Baum zugeordnet** werden konnten (Datensätze auf Root-Ebene wie
`be_users`/`sys_file` oder Datensätze, die inzwischen endgültig gelöscht sind).

#### CSV (`--format=csv`)

Semikolon-getrennt, eine Zeile pro Site **und** Monat (Langformat, ideal für Pivot-Tabellen):

| Spalte | Bedeutung |
|---|---|
| `site` | Site-Identifier (wie Spalte *Site*) |
| `root_page_id` | UID der Wurzelseite |
| `root_title` | Titel der Wurzelseite |
| `month` | Monat `JJJJ-MM`; auch Monate ohne Änderung sind enthalten |
| `changes` | Anzahl der Änderungen dieser Site in diesem Monat |
| `last_change` | wie *Letzte Änderung (Verlauf)* – bezieht sich auf den **gesamten** Zeitraum, nicht auf den Monat, und wiederholt sich daher in jeder Zeile der Site; leer, wenn keine Änderung |
| `last_record_tstamp` | wie *Letzte Änderung (Datensätze)*, ebenfalls je Site wiederholt |

Datumswerte sind im Format ISO 8601 mit Zeitzone, z. B. `2026-09-20T12:00:00+02:00`.

#### JSON (`--format=json`)

| Feld | Bedeutung |
|---|---|
| `source` | `history` oder `log` |
| `since` | Beginn des Zeitraums `JJJJ-MM`, `null` = gesamter Zeitraum |
| `generated` | Erstellungszeitpunkt der Auswertung |
| `unassigned` | Anzahl der keinem Baum zuordenbaren Änderungen |
| `ignored` | Anzahl der nicht gezählten Änderungen (nur ignorierte Felder) |
| `sites[]` | je Site: `site`, `rootPageId`, `title`, `pages`, `lastChange`, `lastRecordTstamp`, `total` (entsprechen den Spalten der Übersicht) und `months` (Objekt `{"JJJJ-MM": Anzahl}`) |

## So wird gezählt

- **Seitenbäume**: alle Sites aus `config/sites/*` (Fallback: Seiten mit *Als Anfang der Webseite benutzen*).
  Liegt eine Site innerhalb eines anderen Baums, zählen ihre Seiten nur zur inneren Site.
  Übersetzte Seiten und gelöschte Seiten werden mitberücksichtigt.
- **Nur aktive Wurzelseiten**: Ausgewertet werden nur Seitenbäume, deren Wurzelseite aktiv ist – also nicht gelöscht,
  nicht versteckt und innerhalb von Start- und Stoppzeit (Stichtag: Zeitpunkt der Auswertung). Die übrigen Bäume
  erscheinen nicht in der Ausgabe; die Tabellenausgabe nennt sie in einem Hinweis. Ihre Seiten werden auch nicht einem
  übergeordneten Baum zugerechnet. Mit `--include-inactive-roots` werden sie trotzdem ausgewertet. Unterseiten werden
  dagegen nicht gefiltert: Versteckte Unterseiten eines aktiven Baums zählen weiter mit.
- **Änderungen pro Monat / Letzte Änderung (Verlauf)**: aus `sys_history` (Änderungsverlauf). Bei Seiten ist die
  Seite der Datensatz selbst, bei allen anderen Tabellen deren `pid`. Alternativ mit `--source=log` aus `sys_log`
  (Backend-Protokoll, Aktionen neu/geändert/gelöscht/verschoben/übersetzt).
- **Letzte Änderung (Datensätze)**: höchster `tstamp` aller Datensätze mit TCA im Baum – unabhängig vom Zeitraum
  und auch dann aussagekräftig, wenn der Verlauf bereits bereinigt wurde.
- **Ignorierte Änderungen**: Wurde an einem Datensatz ausschließlich *Als Anfang der Webseite benutzen*
  (`is_siteroot`) umgestellt, zählt das nicht als Änderung – weder in der Monatsstatistik noch bei der letzten
  Änderung (dort gilt dann die letzte echte Änderung bzw. das Erstellungsdatum). Grundlage sind die geänderten Felder
  in `sys_history`; das gilt auch für `--source=log`. Weitere Felder: `-i is_siteroot -i nav_hide`, alles zählen: `-i none`.
- Änderungen, die keinem Baum zugeordnet werden können (z. B. `be_users`, `sys_file` oder endgültig gelöschte
  Datensätze), werden separat als Hinweis ausgegeben.

> **Wichtig:** `sys_history` und `sys_log` werden häufig vom Scheduler-Task *Tabellen-Müllsammlung*
> (`TableGarbageCollectionTask`) nach einigen Tagen/Monaten gelöscht. Die Monatsstatistik reicht nur so weit zurück,
> wie diese Tabellen Daten enthalten. Für eine lange Historie den Aufbewahrungszeitraum dort hochsetzen.

## Ohne Extension: reine SQL-Abfragen

In [`Resources/Private/Sql/`](Resources/Private/Sql/) liegen drei eigenständige SQL-Skripte für
MySQL ≥ 8.0 / MariaDB ≥ 10.2 (z. B. für phpMyAdmin, Adminer oder die Kommandozeile):

| Skript                                                                                       | Inhalt                                                        |
|----------------------------------------------------------------------------------------------|---------------------------------------------------------------|
| [`1_changes_per_month_history.sql`](Resources/Private/Sql/1_changes_per_month_history.sql)   | Änderungen pro Monat und Baum aus `sys_history` (Seiten + Inhaltselemente) |
| [`2_changes_per_month_log.sql`](Resources/Private/Sql/2_changes_per_month_log.sql)           | Änderungen pro Monat und Baum aus `sys_log` (alle Tabellen)   |
| [`3_last_change_per_tree.sql`](Resources/Private/Sql/3_last_change_per_tree.sql)             | letzte Änderung je Baum anhand der `tstamp`-Felder            |

Die SQL-Variante nimmt als Wurzeln die Seiten mit `is_siteroot = 1`, und zwar nur aktive (nicht gelöscht, nicht
versteckt, innerhalb von Start-/Stoppzeit); Bäume mit inaktiver Wurzelseite fehlen in der Ausgabe vollständig. Änderungen, bei denen nur `is_siteroot`
umgestellt wurde, zählen auch hier nicht (CTE `ignored`, benötigt die JSON-Funktionen von MySQL 8 / MariaDB 10.2.3+).
Die Ausgabe ist Semikolon-getrennt: eine einzige Spalte, deren Name die Überschrift des Skripts ist; die erste
Datenzeile enthält die Spaltenköpfe, Titel stehen in Anführungszeichen. Beispiel:

```
root_uid;root_title;monat;aenderungen;letzte_aenderung_im_monat
1;"Home";2026-08;1;2026-08-15 12:00:00
1;"Home";2026-09;3;2026-09-20 12:00:00
```

### Spalten der SQL-Skripte

**`1_changes_per_month_history.sql`** und **`2_changes_per_month_log.sql`** – eine Zeile pro Seitenbaum und Monat,
sortiert nach Wurzelseite und Monat:

| Spalte | Bedeutung |
|---|---|
| `root_uid` | UID der Wurzelseite (aktive Seite mit *Als Anfang der Webseite benutzen*) |
| `root_title` | Titel der Wurzelseite |
| `monat` | Kalendermonat `JJJJ-MM`. **Nur Monate mit mindestens einer Änderung** erscheinen (anders als in der Extension). Zeitraum: die letzten 12 Monate inkl. aktuellem Monat – anpassbar über `INTERVAL 11 MONTH` im Skript. |
| `aenderungen` | Anzahl der Änderungen in diesem Monat. Skript 1: Einträge in `sys_history` für Seiten und Inhaltselemente. Skript 2: Einträge in `sys_log` (Datenbankaktionen neu/geändert/gelöscht/verschoben/übersetzt) für alle Tabellen. |
| `letzte_aenderung_im_monat` | Zeitpunkt der letzten Änderung **innerhalb dieses Monats** (`JJJJ-MM-TT hh:mm:ss`). Die letzte Änderung des Baums insgesamt ist der Wert in der jüngsten Monatszeile. |

Die Zahlen der Skripte 1 und 2 unterscheiden sich in der Regel: `sys_log` enthält Änderungen aller Tabellen,
`sys_history` (in Skript 1) nur Seiten und Inhaltselemente; außerdem werden beide Tabellen unabhängig voneinander bereinigt.

**`3_last_change_per_tree.sql`** – eine Zeile pro Seitenbaum, sortiert nach der letzten Änderung (neueste zuerst):

| Spalte | Bedeutung |
|---|---|
| `root_uid` | UID der Wurzelseite |
| `root_title` | Titel der Wurzelseite |
| `seiten` | Anzahl aller Seiten des Baums inkl. Wurzelseite, Ordnern, versteckten, gelöschten und übersetzten Seiten; Unterbäume mit eigenem *Als Anfang der Webseite benutzen* zählen nicht mit |
| `letzte_aenderung` | Höchster `tstamp` aller Seiten und Inhaltselemente des Baums (auch versteckte/gelöschte), ohne zeitliche Einschränkung. Wurde zuletzt nur `is_siteroot` umgestellt, gilt die letzte echte Änderung laut Verlauf bzw. das Erstellungsdatum der Seite. |

Datumswerte werden in der Zeitzone der Datenbankverbindung ausgegeben.

Direkt als CSV-Dateien speichern (erste Zeile ist jeweils die Überschrift):

```bash
mysql -B datenbank < Resources/Private/Sql/1_changes_per_month_history.sql > aenderungen_history.csv
mysql -B datenbank < Resources/Private/Sql/2_changes_per_month_log.sql     > aenderungen_log.csv
mysql -B datenbank < Resources/Private/Sql/3_last_change_per_tree.sql      > letzte_aenderung.csv
```

## Visualisierung: TYPO3 Statistik-Viewer

Im Ordner [`Visualization/`](Visualization/) liegt der **TYPO3 Statistik-Viewer**: eine einzelne HTML-Datei, die die
CSV-Exporte der SQL-Skripte interaktiv darstellt (Ampel-Übersicht, Heatmap Verein × Monat, Detail je Verein,
Gesamtentwicklung). Sie läuft komplett offline im Browser, ohne Webserver und ohne externe Abhängigkeiten.

Der Viewer wird in einem eigenen Repository entwickelt und hier als Kopie mitgeliefert (Stand: Commit `0aab464` vom
14.10.2026; nur Dateiname und Produktname auf „TYPO3 Statistik“ angepasst). Änderungen am Viewer bitte dort vornehmen und
die Dateien anschließend wieder hierher kopieren. Bedienung und Anpassung: [`Visualization/README.md`](Visualization/README.md),
fachliche Anforderungen: [`Visualization/docs/ANFORDERUNGEN.md`](Visualization/docs/ANFORDERUNGEN.md).

### Schnittstelle: die CSV-Dateien

Viewer und Abfragen sind nur über die CSV-Dateien gekoppelt. Der Viewer liest die Ausgabe von **zwei** SQL-Skripten:

| Datei im Viewer | Erzeugt von | Spalten |
|---|---|---|
| Datei A – letzte Änderung je Seitenbaum | `3_last_change_per_tree.sql` | `root_uid;root_title;seiten;letzte_aenderung` |
| Datei B – Änderungen pro Monat | `1_changes_per_month_history.sql` | `root_uid;root_title;monat;aenderungen;letzte_aenderung_im_monat` |

```bash
mysql -B datenbank < Resources/Private/Sql/3_last_change_per_tree.sql      > last_change_per_tree.csv
mysql -B datenbank < Resources/Private/Sql/1_changes_per_month_history.sql > changes_per_month_history.csv
```

Anschließend `Visualization/typo3-statistik.html` im Browser öffnen und beide Dateien hineinziehen.

- Der Viewer erkennt die Dateien **an den Spaltennamen**, nicht am Dateinamen. Die erste Zeile (Überschrift des
  Skripts) überspringt er und zeigt sie als Datenquelle an.
- `2_changes_per_month_log.sql` und die CSV-Ausgabe des Befehls `statistics:changes --format=csv` haben andere
  Spalten und werden vom Viewer **nicht** gelesen.
- **Wer an den Skripten 1 oder 3 Spaltennamen, Reihenfolge, Trennzeichen oder die Überschriftszeile ändert, muss
  den Viewer mit anpassen.** Neue Spalten am Ende oder ein anderer Zeitraum (`INTERVAL 11 MONTH`) sind unkritisch.
- CSV-Dateien werden nicht eingecheckt (`Visualization/.gitignore` schließt `*.csv` aus), da sie Vereinsdaten enthalten.
