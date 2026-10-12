-- ---------------------------------------------------------------------------------------------------------------------
-- Änderungsstatistik pro Seitenbaum – reine SQL-Variante (MySQL >= 8.0 / MariaDB >= 10.2, wegen WITH RECURSIVE)
--
-- Wurzeln der Seitenbäume sind hier alle Seiten mit "Als Anfang der Webseite benutzen" (is_siteroot = 1).
-- Liegt eine Site innerhalb eines anderen Baums, gehören ihre Seiten zur inneren Site.
-- Übersetzte Seiten werden über l10n_parent dem Baum der Standardsprache zugeordnet.
-- ---------------------------------------------------------------------------------------------------------------------


-- 1) Änderungen pro Monat und Seitenbaum aus dem Änderungsverlauf (sys_history)
--    Berücksichtigt Änderungen an Seiten und Inhaltselementen. Weitere Tabellen (z. B. tx_news_domain_model_news)
--    lassen sich analog per LEFT JOIN + CASE ergänzen.
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
changes AS (
    SELECT h.tstamp,
           CASE h.tablename
               WHEN 'pages' THEN h.recuid
               WHEN 'tt_content' THEN c.pid
           END AS page_uid
    FROM sys_history h
    LEFT JOIN tt_content c ON h.tablename = 'tt_content' AND c.uid = h.recuid
    WHERE h.tablename IN ('pages', 'tt_content')
      AND h.tstamp >= UNIX_TIMESTAMP(DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01'))  -- letzte 12 Monate
)
SELECT r.uid                                            AS root_uid,
       r.title                                          AS root_title,
       DATE_FORMAT(FROM_UNIXTIME(ch.tstamp), '%Y-%m')   AS monat,
       COUNT(*)                                         AS aenderungen,
       FROM_UNIXTIME(MAX(ch.tstamp))                    AS letzte_aenderung_im_monat
FROM changes ch
JOIN page_root pr ON pr.uid = ch.page_uid
JOIN pages r ON r.uid = pr.root_uid
GROUP BY r.uid, r.title, monat
ORDER BY r.uid, monat;


-- 2) Dasselbe aus dem Backend-Protokoll (sys_log) – hier für ALLE Tabellen, da sys_log die Seite (event_pid) mitführt.
--    type = 1: Datenbankaktion; action 1 = neu, 2 = geändert, 3 = gelöscht, 4 = verschoben, 6 = übersetzt
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
)
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
GROUP BY r.uid, r.title, monat
ORDER BY r.uid, monat;


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
stamps AS (
    SELECT uid AS page_uid, tstamp FROM pages
    UNION ALL
    SELECT pid, MAX(tstamp) FROM tt_content GROUP BY pid
)
SELECT r.uid                        AS root_uid,
       r.title                      AS root_title,
       COUNT(DISTINCT pr.uid)       AS seiten,
       FROM_UNIXTIME(MAX(s.tstamp)) AS letzte_aenderung
FROM stamps s
JOIN page_root pr ON pr.uid = s.page_uid
JOIN pages r ON r.uid = pr.root_uid
GROUP BY r.uid, r.title
ORDER BY letzte_aenderung DESC;
