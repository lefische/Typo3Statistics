-- ---------------------------------------------------------------------------------------------------------------------
-- Änderungsstatistik pro Seitenbaum – reine SQL-Variante (MySQL >= 8.0 / MariaDB >= 10.2, wegen WITH RECURSIVE)
--
-- Wurzeln der Seitenbäume sind hier alle Seiten mit "Als Anfang der Webseite benutzen" (is_siteroot = 1).
-- Liegt eine Site innerhalb eines anderen Baums, gehören ihre Seiten zur inneren Site.
-- Übersetzte Seiten werden über l10n_parent dem Baum der Standardsprache zugeordnet.
-- Änderungen, bei denen nur "Als Anfang der Webseite benutzen" (is_siteroot) umgestellt wurde, werden nicht gezählt
-- (siehe CTE "ignored"; benötigt die JSON-Funktionen von MySQL >= 8.0 bzw. MariaDB >= 10.2.3).
--
-- Die Abfrage liefert eine einzige Spalte (Spaltenname = Überschrift des Blocks) mit Semikolon-getrennten Werten (erste Zeile = Spaltenköpfe),
-- die sich direkt als CSV speichern bzw. in Excel einfügen lässt. Titel stehen in Anführungszeichen.
-- Auf der Kommandozeile ohne Tabellenrahmen, mit Überschriften:  mysql -B datenbank < 3_last_change_per_tree.sql > letzte_aenderung.csv
-- ---------------------------------------------------------------------------------------------------------------------

-- 3) Letzte Änderung je Seitenbaum anhand der tstamp-Felder von Seiten und Inhaltselementen
--    (funktioniert auch, wenn sys_history/sys_log bereits bereinigt wurden)
WITH RECURSIVE tree AS (
    SELECT uid AS page_uid, uid AS root_uid
    FROM pages
    WHERE is_siteroot = 1 AND sys_language_uid = 0
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
stamps AS (
    -- Stammt der tstamp einer Seite von einer ignorierten Änderung, zählt stattdessen ihre letzte relevante Änderung
    -- laut sys_history, mindestens aber das Erstellungsdatum.
    SELECT p.uid AS page_uid,
           CASE WHEN EXISTS (SELECT 1 FROM ignored i WHERE i.tablename = 'pages' AND i.recuid = p.uid AND i.tstamp = p.tstamp)
                THEN GREATEST(p.crdate, COALESCE((
                         SELECT MAX(h.tstamp)
                         FROM sys_history h
                         WHERE h.tablename = 'pages'
                           AND h.recuid = p.uid
                           AND NOT EXISTS (SELECT 1 FROM ignored i WHERE h.actiontype = 2 AND i.tablename = h.tablename
                                                                     AND i.recuid = h.recuid AND i.tstamp = h.tstamp)
                     ), 0))
                ELSE p.tstamp
           END AS tstamp
    FROM pages p
    UNION ALL
    SELECT pid, MAX(tstamp) FROM tt_content GROUP BY pid
),
result AS (
    SELECT r.uid                        AS root_uid,
           r.title                      AS root_title,
           COUNT(DISTINCT pr.uid)       AS seiten,
           FROM_UNIXTIME(MAX(s.tstamp)) AS letzte_aenderung
    FROM stamps s
    JOIN page_root pr ON pr.uid = s.page_uid
    JOIN pages r ON r.uid = pr.root_uid
    GROUP BY r.uid, r.title
)
SELECT zeile AS `3) Letzte Änderung je Seitenbaum – neuester Zeitstempel von Seiten und Inhalten`
FROM (
    SELECT 0 AS kopf, NULL AS letzte_aenderung, 'root_uid;root_title;seiten;letzte_aenderung' AS zeile
    UNION ALL
    SELECT 1, letzte_aenderung, CONCAT_WS(';', root_uid, CONCAT('"', REPLACE(root_title, '"', '""'), '"'), seiten, letzte_aenderung)
    FROM result
) csv
ORDER BY kopf, letzte_aenderung DESC;
