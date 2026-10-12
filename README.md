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

## So wird gezählt

- **Seitenbäume**: alle Sites aus `config/sites/*` (Fallback: Seiten mit *Als Anfang der Webseite benutzen*).
  Liegt eine Site innerhalb eines anderen Baums, zählen ihre Seiten nur zur inneren Site.
  Übersetzte Seiten und gelöschte Seiten werden mitberücksichtigt.
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

In [`Resources/Private/Sql/change_statistics.sql`](Resources/Private/Sql/change_statistics.sql) liegen drei
Abfragen für MySQL ≥ 8.0 / MariaDB ≥ 10.2 (z. B. für phpMyAdmin oder Adminer):

1. Änderungen pro Monat und Baum aus `sys_history` (Seiten + Inhaltselemente),
2. Änderungen pro Monat und Baum aus `sys_log` (alle Tabellen),
3. letzte Änderung je Baum anhand der `tstamp`-Felder.

Die SQL-Variante nimmt als Wurzeln die Seiten mit `is_siteroot = 1`. Änderungen, bei denen nur `is_siteroot`
umgestellt wurde, zählen auch hier nicht (CTE `ignored`, benötigt die JSON-Funktionen von MySQL 8 / MariaDB 10.2.3+).
Die Ausgabe ist Semikolon-getrennt (eine Spalte, deren Name die Überschrift des Blocks ist; erste Datenzeile = Spaltenköpfe), z. B.:

```
root_uid;root_title;monat;aenderungen;letzte_aenderung_im_monat
1;"Home";2026-08;1;2026-08-15 12:00:00
1;"Home";2026-09;3;2026-09-20 12:00:00
```

Direkt als CSV-Datei speichern: `mysql -B datenbank < change_statistics.sql > aenderungen.csv` (jeder Block beginnt mit seiner Überschrift)
