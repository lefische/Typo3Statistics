<?php

declare(strict_types=1);

namespace Lefische\Typo3Statistics\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Ermittelt pro Seitenbaum (Site-Root) die Änderungen pro Monat und das Datum der letzten Änderung.
 *
 * Quellen:
 *  - "history": sys_history (Änderungsverlauf, ein Eintrag pro Insert/Update/Move/Delete eines Datensatzes)
 *  - "log":     sys_log (Backend-Protokoll, type=1 = Datenbankaktionen)
 *
 * Zusätzlich wird aus den tstamp-Feldern aller TCA-Tabellen die letzte Änderung je Baum bestimmt. Das funktioniert
 * auch dann, wenn sys_history/sys_log bereits per Garbage-Collection bereinigt wurden.
 */
final class ChangeStatisticsService
{
    public const SOURCE_HISTORY = 'history';
    public const SOURCE_LOG = 'log';

    private const CHUNK_SIZE = 1000;

    /** sys_log: Datenbankaktionen (SystemLogType::DB) */
    private const LOG_TYPE_DB = 1;
    /** sys_log: insert, update, delete, move, localize */
    private const LOG_ACTIONS = [1, 2, 3, 4, 6];
    /** sys_log: update */
    private const LOG_ACTION_UPDATE = 2;
    /** sys_history: modify */
    private const HISTORY_ACTION_MODIFY = 2;
    /** Technische Felder, die bei der Prüfung "nur ignorierte Felder geändert" nicht mitzählen */
    private const TECHNICAL_FIELDS = ['l10n_diffsource', 'l10n_state'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * Liefert die Wurzeln der Seitenbäume.
     *
     * Ohne Filter: alle konfigurierten Sites (Fallback: Seiten mit is_siteroot=1).
     * Mit Filter: Site-Identifier oder Seiten-UIDs; eine UID, die keine Site ist, wird als eigener Teilbaum ausgewertet.
     * Bäume, deren Wurzelseite nicht aktiv ist (gelöscht, versteckt, außerhalb von Start-/Stoppzeit oder nicht
     * vorhanden), werden weggelassen, außer $includeInactive ist gesetzt.
     *
     * @param list<string> $filter
     * @param array<int, array{rootPageId: int, identifier: string, title: string}>|null $excluded erhält die weggelassenen Bäume
     * @return array<int, array{rootPageId: int, identifier: string, title: string}>
     */
    public function getRoots(array $filter = [], bool $includeInactive = false, ?array &$excluded = null): array
    {
        $roots = $this->getAllRoots();

        if ($filter !== []) {
            $selected = [];
            foreach ($filter as $value) {
                $value = trim($value);
                foreach ($roots as $uid => $root) {
                    if ($root['identifier'] === $value || (string)$uid === $value) {
                        $selected[$uid] = $root;
                        continue 2;
                    }
                }
                if (ctype_digit($value) && (int)$value > 0) {
                    $selected[(int)$value] = ['rootPageId' => (int)$value, 'identifier' => 'page-' . $value, 'title' => ''];
                    continue;
                }
                throw new \InvalidArgumentException(sprintf('Unbekannte Site bzw. Seite "%s"', $value), 1728720001);
            }
            $roots = $selected;
        }

        $pages = $this->fetchRootPages(array_keys($roots));
        $excluded = [];
        foreach ($roots as $uid => $root) {
            $roots[$uid]['title'] = $pages[$uid]['title'] ?? '';
            if (!$includeInactive && !($pages[$uid]['active'] ?? false)) {
                $excluded[$uid] = $roots[$uid];
                unset($roots[$uid]);
            }
        }
        ksort($roots);
        ksort($excluded);
        return $roots;
    }

    /**
     * @return array<int, array{rootPageId: int, identifier: string, title: string}>
     */
    private function getAllRoots(): array
    {
        $roots = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $roots[$site->getRootPageId()] = [
                'rootPageId' => $site->getRootPageId(),
                'identifier' => $site->getIdentifier(),
                'title' => '',
            ];
        }
        if ($roots === []) {
            foreach ($this->fetchSiteRootPages() as $uid) {
                $roots[$uid] = ['rootPageId' => $uid, 'identifier' => 'page-' . $uid, 'title' => ''];
            }
        }
        return $roots;
    }

    /**
     * @param array<int, array{rootPageId: int, identifier: string, title: string}> $roots
     * @param list<string> $onlyTables leer = alle Tabellen
     * @param list<string> $excludeTables
     * @param list<string> $ignoreFields Änderungen, die ausschließlich diese Felder betreffen, werden nicht gezählt
     * @return array{
     *     roots: array<int, array{rootPageId: int, identifier: string, title: string, pages: int, total: int, lastChange: ?int, lastRecordTstamp: ?int, months: array<string, int>}>,
     *     unassigned: int,
     *     months: list<string>
     * }
     */
    public function calculate(array $roots, string $source, int $since, array $onlyTables = [], array $excludeTables = [], array $ignoreFields = []): array
    {
        $ignoredChanges = $this->findIgnoredChanges($ignoreFields, $onlyTables, $excludeTables);

        // Alle Sites als Baumgrenzen verwenden, damit eine verschachtelte Site auch dann nicht zum äußeren Baum zählt,
        // wenn sie selbst nicht ausgewählt ist.
        $pageToRoot = $this->buildPageToRootMap(array_values(array_unique([...array_keys($roots), ...array_keys($this->getAllRoots())])));

        $result = [];
        foreach ($roots as $uid => $root) {
            $result[$uid] = $root + ['pages' => 0, 'total' => 0, 'lastChange' => null, 'lastRecordTstamp' => null, 'months' => []];
        }
        foreach ($pageToRoot as $rootUid) {
            if (isset($result[$rootUid])) {
                $result[$rootUid]['pages']++;
            }
        }

        $events = $source === self::SOURCE_LOG
            ? $this->collectFromLog($since, $onlyTables, $excludeTables, $ignoredChanges)
            : $this->collectFromHistory($since, $onlyTables, $excludeTables, $ignoredChanges);

        $unassigned = 0;
        $allMonths = [];
        foreach ($this->resolvePages($events) as [$pageUid, $months, $last]) {
            $rootUid = $pageToRoot[$pageUid] ?? null;
            if ($rootUid === null) {
                $unassigned += array_sum($months);
                continue;
            }
            if (!isset($result[$rootUid])) {
                // gehört zu einer nicht ausgewählten Site
                continue;
            }
            foreach ($months as $month => $count) {
                $result[$rootUid]['months'][$month] = ($result[$rootUid]['months'][$month] ?? 0) + $count;
                $result[$rootUid]['total'] += $count;
                $allMonths[$month] = true;
            }
            $result[$rootUid]['lastChange'] = max($result[$rootUid]['lastChange'] ?? 0, $last);
        }

        foreach ($this->collectRecordTimestamps($onlyTables, $excludeTables, $ignoredChanges) as $pageUid => $tstamp) {
            $rootUid = $pageToRoot[$pageUid] ?? null;
            if ($rootUid !== null && isset($result[$rootUid])) {
                $result[$rootUid]['lastRecordTstamp'] = max($result[$rootUid]['lastRecordTstamp'] ?? 0, $tstamp);
            }
        }

        foreach ($result as &$root) {
            ksort($root['months']);
        }
        unset($root);

        return [
            'roots' => $result,
            'unassigned' => $unassigned,
            'ignored' => $this->countIgnored($ignoredChanges, $since),
            'months' => $this->monthRange($since, array_keys($allMonths)),
        ];
    }

    /**
     * Ordnet jeder Seite (inkl. gelöschter Seiten und Übersetzungen) die UID ihres Seitenbaums zu.
     * Liegt eine Site innerhalb eines anderen Baums, gehören ihre Seiten zur inneren Site.
     *
     * @param list<int> $rootPageIds
     * @return array<int, int> page uid => root page uid
     */
    public function buildPageToRootMap(array $rootPageIds): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('uid', 'pid', 'sys_language_uid', 'l10n_parent')
            ->from('pages')
            ->executeQuery();

        $children = [];
        $translations = [];
        while ($row = $result->fetchAssociative()) {
            if ((int)$row['sys_language_uid'] > 0 && (int)$row['l10n_parent'] > 0) {
                $translations[(int)$row['uid']] = (int)$row['l10n_parent'];
            } else {
                $children[(int)$row['pid']][] = (int)$row['uid'];
            }
        }

        $isRoot = array_flip($rootPageIds);
        $map = [];
        foreach ($rootPageIds as $rootUid) {
            $map[$rootUid] = $rootUid;
            $stack = [$rootUid];
            while ($stack !== []) {
                $pageUid = array_pop($stack);
                foreach ($children[$pageUid] ?? [] as $childUid) {
                    if (isset($isRoot[$childUid]) || isset($map[$childUid])) {
                        continue;
                    }
                    $map[$childUid] = $rootUid;
                    $stack[] = $childUid;
                }
            }
        }
        foreach ($translations as $uid => $parentUid) {
            if (isset($map[$parentUid])) {
                $map[$uid] = $map[$parentUid];
            }
        }
        return $map;
    }

    /**
     * @return array<string, array<int, array<int, array{months: array<string, int>, last: int}>>> table => recuid => pid-hint => data
     */
    private function collectFromHistory(int $since, array $onlyTables, array $excludeTables, array $ignoredChanges): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('tablename', 'recuid', 'tstamp', 'actiontype')
            ->from('sys_history')
            ->where($queryBuilder->expr()->gte('tstamp', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)));
        $this->addTableConstraints($queryBuilder, 'tablename', $onlyTables, $excludeTables);

        $events = [];
        $result = $queryBuilder->executeQuery();
        while ($row = $result->fetchAssociative()) {
            if ((int)$row['actiontype'] === self::HISTORY_ACTION_MODIFY
                && isset($ignoredChanges[$row['tablename']][(int)$row['recuid']][(int)$row['tstamp']])
            ) {
                continue;
            }
            $this->addEvent($events, (string)$row['tablename'], (int)$row['recuid'], 0, (int)$row['tstamp']);
        }
        return $events;
    }

    /**
     * @return array<string, array<int, array<int, array{months: array<string, int>, last: int}>>>
     */
    private function collectFromLog(int $since, array $onlyTables, array $excludeTables, array $ignoredChanges): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_log');
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('tablename', 'recuid', 'event_pid', 'tstamp', 'action')
            ->from('sys_log')
            ->where(
                $queryBuilder->expr()->gte('tstamp', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('type', $queryBuilder->createNamedParameter(self::LOG_TYPE_DB, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('error', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('action', $queryBuilder->createNamedParameter(self::LOG_ACTIONS, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->neq('tablename', $queryBuilder->createNamedParameter('')),
            );
        $this->addTableConstraints($queryBuilder, 'tablename', $onlyTables, $excludeTables);

        $events = [];
        $result = $queryBuilder->executeQuery();
        while ($row = $result->fetchAssociative()) {
            // sys_log kennt die geänderten Felder nicht; der passende sys_history-Eintrag (gleicher Datensatz,
            // gleicher Zeitpunkt) verrät, ob nur ignorierte Felder geändert wurden.
            if ((int)$row['action'] === self::LOG_ACTION_UPDATE
                && isset($ignoredChanges[$row['tablename']][(int)$row['recuid']][(int)$row['tstamp']])
            ) {
                continue;
            }
            $this->addEvent($events, (string)$row['tablename'], (int)$row['recuid'], max(0, (int)$row['event_pid']), (int)$row['tstamp']);
        }
        return $events;
    }

    /**
     * Sucht in sys_history alle Änderungen, bei denen ausschließlich ignorierte Felder (z. B. is_siteroot) geändert
     * wurden. Grundlage ist history_data.newRecord, das genau die geänderten Felder enthält.
     *
     * @param list<string> $ignoreFields
     * @return array<string, array<int, array<int, true>>> table => recuid => tstamp => true
     */
    private function findIgnoredChanges(array $ignoreFields, array $onlyTables, array $excludeTables): array
    {
        if ($ignoreFields === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
        $queryBuilder->getRestrictions()->removeAll();
        $fieldConstraints = [];
        foreach ($ignoreFields as $field) {
            $fieldConstraints[] = $queryBuilder->expr()->like(
                'history_data',
                $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards('"' . $field . '"') . '%')
            );
        }
        $queryBuilder
            ->select('tablename', 'recuid', 'tstamp', 'history_data')
            ->from('sys_history')
            ->where(
                $queryBuilder->expr()->eq('actiontype', $queryBuilder->createNamedParameter(self::HISTORY_ACTION_MODIFY, Connection::PARAM_INT)),
                $queryBuilder->expr()->or(...$fieldConstraints),
            );
        $this->addTableConstraints($queryBuilder, 'tablename', $onlyTables, $excludeTables);

        $ignored = [];
        $result = $queryBuilder->executeQuery();
        while ($row = $result->fetchAssociative()) {
            $table = (string)$row['tablename'];
            $data = json_decode((string)$row['history_data'], true);
            if (!is_array($data) || !is_array($data['newRecord'] ?? null)) {
                continue;
            }
            $notIgnoredFields = array_diff(
                array_keys($data['newRecord']),
                $ignoreFields,
                self::TECHNICAL_FIELDS,
                [(string)($GLOBALS['TCA'][$table]['ctrl']['tstamp'] ?? 'tstamp')],
            );
            if ($notIgnoredFields === []) {
                $ignored[$table][(int)$row['recuid']][(int)$row['tstamp']] = true;
            }
        }
        return $ignored;
    }

    private function countIgnored(array $ignoredChanges, int $since): int
    {
        $count = 0;
        foreach ($ignoredChanges as $records) {
            foreach ($records as $timestamps) {
                foreach (array_keys($timestamps) as $tstamp) {
                    if ($tstamp >= $since) {
                        $count++;
                    }
                }
            }
        }
        return $count;
    }

    private function addEvent(array &$events, string $table, int $recordUid, int $pidHint, int $tstamp): void
    {
        $month = date('Y-m', $tstamp);
        $entry = &$events[$table][$recordUid][$pidHint];
        $entry['months'][$month] = ($entry['months'][$month] ?? 0) + 1;
        $entry['last'] = max($entry['last'] ?? 0, $tstamp);
    }

    /**
     * Bestimmt zu jedem Ereignis die Seite, auf der der Datensatz liegt.
     *
     * @return \Generator<array{0: int, 1: array<string, int>, 2: int}> [page uid, months, last tstamp]
     */
    private function resolvePages(array $events): \Generator
    {
        foreach ($events as $table => $records) {
            $lookup = [];
            foreach ($records as $recordUid => $byHint) {
                foreach ($byHint as $pidHint => $data) {
                    if ($table === 'pages') {
                        yield [$recordUid, $data['months'], $data['last']];
                    } elseif ($pidHint > 0) {
                        yield [$pidHint, $data['months'], $data['last']];
                    } else {
                        $lookup[$recordUid] = true;
                    }
                }
            }
            if ($lookup === []) {
                continue;
            }
            $pids = $this->fetchRecordPids($table, array_keys($lookup));
            foreach (array_keys($lookup) as $recordUid) {
                $data = $records[$recordUid][0];
                yield [$pids[$recordUid] ?? 0, $data['months'], $data['last']];
            }
        }
    }

    /**
     * @param list<int> $uids
     * @return array<int, int> record uid => pid
     */
    private function fetchRecordPids(string $table, array $uids): array
    {
        if (!isset($GLOBALS['TCA'][$table])) {
            return [];
        }
        $pids = [];
        foreach (array_chunk($uids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            try {
                $result = $queryBuilder
                    ->select('uid', 'pid')
                    ->from($table)
                    ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                    ->executeQuery();
            } catch (\Throwable) {
                // Tabelle existiert nicht (mehr) oder hat kein pid-Feld
                return [];
            }
            while ($row = $result->fetchAssociative()) {
                $pids[(int)$row['uid']] = (int)$row['pid'];
            }
        }
        return $pids;
    }

    /**
     * Höchster tstamp je Seite über alle TCA-Tabellen (inkl. gelöschter/versteckter Datensätze).
     *
     * @return array<int, int> page uid => tstamp
     */
    private function collectRecordTimestamps(array $onlyTables, array $excludeTables, array $ignoredChanges): array
    {
        $timestamps = [];
        foreach ($GLOBALS['TCA'] ?? [] as $table => $config) {
            $tstampField = (string)($config['ctrl']['tstamp'] ?? '');
            if ($tstampField === '' || (int)($config['ctrl']['rootLevel'] ?? 0) === 1) {
                continue;
            }
            if (($onlyTables !== [] && !in_array($table, $onlyTables, true)) || in_array($table, $excludeTables, true)) {
                continue;
            }
            $pageField = $table === 'pages' ? 'uid' : 'pid';
            // Datensätze mit ignorierten Änderungen separat behandeln, da ihr tstamp durch diese Änderung gesetzt wurde
            $affectedUids = array_keys($ignoredChanges[$table] ?? []);
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder
                ->select($pageField)
                ->addSelectLiteral($queryBuilder->expr()->max($tstampField, 'last_tstamp'))
                ->from($table)
                ->where($queryBuilder->expr()->gt($pageField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->groupBy($pageField);
            if ($affectedUids !== []) {
                $queryBuilder->andWhere($queryBuilder->expr()->notIn('uid', $queryBuilder->createNamedParameter($affectedUids, Connection::PARAM_INT_ARRAY)));
            }
            try {
                $result = $queryBuilder->executeQuery();
            } catch (\Throwable) {
                continue;
            }
            while ($row = $result->fetchAssociative()) {
                $pageUid = (int)$row[$pageField];
                $timestamps[$pageUid] = max($timestamps[$pageUid] ?? 0, (int)$row['last_tstamp']);
            }
            if ($affectedUids !== []) {
                foreach ($this->correctedTimestamps($table, $pageField, $tstampField, $affectedUids, $ignoredChanges[$table]) as $pageUid => $tstamp) {
                    $timestamps[$pageUid] = max($timestamps[$pageUid] ?? 0, $tstamp);
                }
            }
        }
        return $timestamps;
    }

    /**
     * tstamp für Datensätze, deren letzte Änderung ignoriert wird: stattdessen die letzte relevante Änderung laut
     * sys_history, mindestens aber das Erstellungsdatum.
     *
     * @param list<int> $uids
     * @param array<int, array<int, true>> $ignoredByUid
     * @return array<int, int> page uid => tstamp
     */
    private function correctedTimestamps(string $table, string $pageField, string $tstampField, array $uids, array $ignoredByUid): array
    {
        $crdateField = (string)($GLOBALS['TCA'][$table]['ctrl']['crdate'] ?? '');
        $lastRelevant = [];
        foreach (array_chunk($uids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_history');
            $queryBuilder->getRestrictions()->removeAll();
            $result = $queryBuilder
                ->select('recuid', 'tstamp', 'actiontype')
                ->from('sys_history')
                ->where(
                    $queryBuilder->expr()->eq('tablename', $queryBuilder->createNamedParameter($table)),
                    $queryBuilder->expr()->in('recuid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                )
                ->executeQuery();
            while ($row = $result->fetchAssociative()) {
                $uid = (int)$row['recuid'];
                $tstamp = (int)$row['tstamp'];
                if ((int)$row['actiontype'] === self::HISTORY_ACTION_MODIFY && isset($ignoredByUid[$uid][$tstamp])) {
                    continue;
                }
                $lastRelevant[$uid] = max($lastRelevant[$uid] ?? 0, $tstamp);
            }
        }

        $timestamps = [];
        foreach (array_chunk($uids, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $fields = array_values(array_unique(array_filter(['uid', $pageField, $tstampField, $crdateField])));
            try {
                $result = $queryBuilder
                    ->select(...$fields)
                    ->from($table)
                    ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                    ->executeQuery();
            } catch (\Throwable) {
                return $timestamps;
            }
            while ($row = $result->fetchAssociative()) {
                $uid = (int)$row['uid'];
                $pageUid = (int)$row[$pageField];
                if ($pageUid <= 0) {
                    continue;
                }
                $tstamp = (int)$row[$tstampField];
                if (isset($ignoredByUid[$uid][$tstamp])) {
                    // tstamp stammt von einer ignorierten Änderung
                    $tstamp = max($lastRelevant[$uid] ?? 0, $crdateField !== '' ? (int)$row[$crdateField] : 0);
                }
                if ($tstamp > 0) {
                    $timestamps[$pageUid] = max($timestamps[$pageUid] ?? 0, $tstamp);
                }
            }
        }
        return $timestamps;
    }

    private function addTableConstraints(QueryBuilder $queryBuilder, string $field, array $onlyTables, array $excludeTables): void
    {
        if ($onlyTables !== []) {
            $queryBuilder->andWhere($queryBuilder->expr()->in($field, $queryBuilder->createNamedParameter($onlyTables, Connection::PARAM_STR_ARRAY)));
        }
        if ($excludeTables !== []) {
            $queryBuilder->andWhere($queryBuilder->expr()->notIn($field, $queryBuilder->createNamedParameter($excludeTables, Connection::PARAM_STR_ARRAY)));
        }
    }

    /**
     * Alle Monate von $since (bzw. dem ersten Monat mit Änderungen) bis heute, damit auch Monate ohne Änderung erscheinen.
     *
     * @param list<string> $monthsWithChanges
     * @return list<string>
     */
    private function monthRange(int $since, array $monthsWithChanges): array
    {
        sort($monthsWithChanges);
        $start = $since > 0 ? date('Y-m', $since) : ($monthsWithChanges[0] ?? date('Y-m'));
        $current = new \DateTimeImmutable($start . '-01');
        $end = new \DateTimeImmutable(date('Y-m') . '-01');
        $months = [];
        while ($current <= $end) {
            $months[] = $current->format('Y-m');
            $current = $current->modify('+1 month');
        }
        return $months;
    }

    /**
     * @return list<int>
     */
    private function fetchSiteRootPages(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('is_siteroot', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * Titel der Wurzelseiten und ob sie aktiv sind (nicht gelöscht, nicht versteckt, im Veröffentlichungszeitraum).
     *
     * @param list<int> $uids
     * @return array<int, array{title: string, active: bool}>
     */
    private function fetchRootPages(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $result = $queryBuilder
            ->select('uid', 'title', 'deleted', 'hidden', 'starttime', 'endtime')
            ->from('pages')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery();
        $now = time();
        $pages = [];
        while ($row = $result->fetchAssociative()) {
            $pages[(int)$row['uid']] = [
                'title' => (string)$row['title'],
                'active' => !(int)$row['deleted'] && !(int)$row['hidden']
                    && ((int)$row['starttime'] === 0 || (int)$row['starttime'] <= $now)
                    && ((int)$row['endtime'] === 0 || (int)$row['endtime'] > $now),
            ];
        }
        return $pages;
    }
}
