-- ---------------------------------------------------------------------------------------------------------------------
-- Änderungsstatistik pro Seitenbaum – reine SQL-Variante (MySQL >= 8.0 / MariaDB >= 10.2, wegen WITH RECURSIVE)
--
-- Wurzeln der Seitenbäume sind hier alle Seiten mit "Als Anfang der Webseite benutzen" (is_siteroot = 1).
-- Berücksichtigt werden nur Bäume, deren Wurzelseite aktiv ist (nicht gelöscht, nicht versteckt, Start-/Stoppzeit
-- eingehalten). Bäume mit inaktiver Wurzelseite fehlen in der Ausgabe vollständig.
-- Liegt eine Site innerhalb eines anderen Baums, gehören ihre Seiten zur inneren Site.
-- Übersetzte Seiten werden über l10n_parent dem Baum der Standardsprache zugeordnet.
-- Änderungen, bei denen nur "Als Anfang der Webseite benutzen" (is_siteroot) umgestellt wurde, werden nicht gezählt
-- (siehe CTE "ignored"; benötigt die JSON-Funktionen von MySQL >= 8.0 bzw. MariaDB >= 10.2.3).
--
-- Die Abfrage liefert eine einzige Spalte (Spaltenname = Überschrift des Blocks) mit Semikolon-getrennten Werten (erste Zeile = Spaltenköpfe),
-- die sich direkt als CSV speichern bzw. in Excel einfügen lässt. Titel stehen in Anführungszeichen.
-- Auf der Kommandozeile ohne Tabellenrahmen, mit Überschriften:  mysql -B datenbank < 2_changes_per_month_log.sql > aenderungen_log.csv
-- ---------------------------------------------------------------------------------------------------------------------

-- 2) Änderungen pro Monat und Seitenbaum aus dem Backend-Protokoll (sys_log) – hier für ALLE Tabellen, da sys_log die Seite (event_pid) mitführt.
--    type = 1: Datenbankaktion; action 1 = neu, 2 = geändert, 3 = gelöscht, 4 = verschoben, 6 = übersetzt
WITH RECURSIVE tree AS (
    SELECT uid AS page_uid, uid AS root_uid
    FROM pages
    WHERE is_siteroot = 1 AND sys_language_uid = 0
      -- nur Seitenbäume mit aktiver Wurzelseite (nicht gelöscht, nicht versteckt, im Veröffentlichungszeitraum)
      AND deleted = 0 AND hidden = 0
      AND (starttime = 0 OR starttime <= UNIX_TIMESTAMP())
      AND (endtime = 0 OR endtime > UNIX_TIMESTAMP())
    UNION ALL
    SELECT p.uid, t.root_uid
    FROM pages p
    JOIN tree t ON p.pid = t.page_uid
    WHERE p.sys_language_uid = 0 AND p.is_siteroot = 0
),
page_root AS (
    SELECT p.uid, t.root_uid
    FROM pages p
    JOIN tree t ON t.page_uid = IF(p.sys_language_uid > 0 AND p.l10n_parent > 0, p.l10n_parent, p.uid)
),
ignored AS (
    -- Änderungen, bei denen ausschließlich "Als Anfang der Webseite benutzen" (is_siteroot) umgestellt wurde.
    -- Weitere zu ignorierende Felder als zusätzlichen Pfad '$.feldname' in JSON_REMOVE ergänzen
    -- (und die LIKE-Bedingung entsprechend erweitern).
    SELECT tablename, recuid, tstamp
    FROM sys_history
    WHERE actiontype = 2
      AND history_data LIKE '%"is_siteroot"%'
      AND CASE WHEN JSON_VALID(history_data)
               THEN COALESCE(JSON_LENGTH(JSON_REMOVE(JSON_EXTRACT(history_data, '$.newRecord'),
                        '$.is_siteroot', '$.tstamp', '$.l10n_diffsource', '$.l10n_state')), 1)
               ELSE 1
          END = 0
),
result AS (
    SELECT r.uid                                            AS root_uid,
           r.title                                          AS root_title,
           DATE_FORMAT(FROM_UNIXTIME(l.tstamp), '%Y-%m')    AS monat,
           COUNT(*)                                         AS aenderungen,
           FROM_UNIXTIME(MAX(l.tstamp))                     AS letzte_aenderung_im_monat
    FROM sys_log l
    JOIN page_root pr ON pr.uid = IF(l.tablename = 'pages', l.recuid, l.event_pid)
    JOIN pages r ON r.uid = pr.root_uid
    WHERE l.type = 1
      AND l.error = 0
      AND l.action IN (1, 2, 3, 4, 6)
      AND l.tstamp >= UNIX_TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01'))
      -- sys_log kennt die geänderten Felder nicht: über den passenden sys_history-Eintrag ausschließen
      AND NOT EXISTS (SELECT 1 FROM ignored i WHERE l.action = 2 AND i.tablename = l.tablename
                                                AND i.recuid = l.recuid AND i.tstamp = l.tstamp)
    GROUP BY r.uid, r.title, monat
)
SELECT zeile AS `2) Änderungen pro Monat und Seitenbaum – Backend-Protokoll (sys_log), alle Tabellen, letzte 12 Monate`
FROM (
    SELECT 0 AS kopf, NULL AS root_uid, NULL AS monat, 'root_uid;root_title;monat;aenderungen;letzte_aenderung_im_monat' AS zeile
    UNION ALL
    SELECT 1, root_uid, monat, CONCAT_WS(';', root_uid, CONCAT('"', REPLACE(root_title, '"', '""'), '"'), monat, aenderungen, letzte_aenderung_im_monat)
    FROM result
) csv
ORDER BY kopf, root_uid, monat;
