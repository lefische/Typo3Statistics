<?php

declare(strict_types=1);

namespace Lefische\Typo3Statistics\Command;

use Lefische\Typo3Statistics\Service\ChangeStatisticsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * vendor/bin/typo3 statistics:changes [--months=12] [--source=history|log] [--site=...] [--format=table|csv|json]
 */
final class ChangeStatisticsCommand extends Command
{
    private const FORMATS = ['table', 'csv', 'json'];
    private const DEFAULT_IGNORE_FIELDS = ['is_siteroot'];

    public function __construct(
        private readonly ChangeStatisticsService $changeStatisticsService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                'Wertet sys_history (Standard) bzw. sys_log aus und ordnet jede Änderung über die Seite des Datensatzes'
                . ' dem jeweiligen Seitenbaum (Site) zu.' . PHP_EOL
                . 'Ausgegeben werden je Baum die letzte Änderung sowie die Anzahl der Änderungen pro Monat.'
            )
            ->addOption('months', 'm', InputOption::VALUE_REQUIRED, 'Anzahl Monate rückwirkend inkl. aktuellem Monat (0 = alle)', '12')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Startmonat im Format JJJJ-MM (überschreibt --months)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Datenquelle: history (sys_history) oder log (sys_log)', ChangeStatisticsService::SOURCE_HISTORY)
            ->addOption('site', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nur diese Site(s): Identifier oder Seiten-UID (mehrfach möglich)')
            ->addOption('table', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Nur diese Tabelle(n) zählen, z. B. pages, tt_content')
            ->addOption('exclude-table', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Diese Tabelle(n) nicht zählen')
            ->addOption(
                'ignore-field',
                'i',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Änderungen, die nur diese Felder betreffen, nicht zählen (Standard: is_siteroot; "none" = alles zählen)',
            )
            ->addOption('include-inactive-roots', null, InputOption::VALUE_NONE, 'Auch Seitenbäume auswerten, deren Wurzelseite gelöscht, versteckt oder nicht im Veröffentlichungszeitraum ist')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Ausgabe: table, csv oder json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = (string)$input->getOption('format');
        $source = (string)$input->getOption('source');
        if (!in_array($format, self::FORMATS, true)) {
            $io->error('Unbekanntes Format "' . $format . '". Erlaubt: ' . implode(', ', self::FORMATS));
            return Command::INVALID;
        }
        if (!in_array($source, [ChangeStatisticsService::SOURCE_HISTORY, ChangeStatisticsService::SOURCE_LOG], true)) {
            $io->error('Unbekannte Quelle "' . $source . '". Erlaubt: history, log');
            return Command::INVALID;
        }

        try {
            $since = $this->resolveSince($input);
            $excludedRoots = [];
            $roots = $this->changeStatisticsService->getRoots(
                $this->splitList($input->getOption('site')),
                (bool)$input->getOption('include-inactive-roots'),
                $excludedRoots,
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }
        if ($roots === []) {
            $io->warning('Keine Sites bzw. Seitenbäume mit aktiver Wurzelseite gefunden.');
            return Command::SUCCESS;
        }

        $ignoreFields = $this->resolveIgnoreFields($input);
        $statistics = $this->changeStatisticsService->calculate(
            $roots,
            $source,
            $since,
            $this->splitList($input->getOption('table')),
            $this->splitList($input->getOption('exclude-table')),
            $ignoreFields,
        );

        match ($format) {
            'csv' => $this->renderCsv($output, $statistics),
            'json' => $output->writeln((string)json_encode($this->toJson($statistics, $source, $since), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            default => $this->renderTable($io, $statistics, $source, $since, $ignoreFields, $excludedRoots),
        };

        return Command::SUCCESS;
    }

    private function renderTable(SymfonyStyle $io, array $statistics, string $source, int $since, array $ignoreFields, array $excludedRoots): void
    {
        $io->title('Änderungen pro Seitenbaum');
        $io->text(sprintf(
            'Quelle: %s · Zeitraum: %s bis heute',
            $source === ChangeStatisticsService::SOURCE_LOG ? 'sys_log' : 'sys_history',
            $since > 0 ? date('m/Y', $since) : 'Beginn der Aufzeichnung',
        ));

        $rows = [];
        foreach ($statistics['roots'] as $root) {
            $rows[] = [
                $root['identifier'],
                $root['title'] . ' [' . $root['rootPageId'] . ']',
                $root['pages'],
                $this->formatDate($root['lastChange']),
                $this->formatDate($root['lastRecordTstamp']),
                $root['total'],
            ];
        }
        $io->section('Übersicht');
        $io->table(['Site', 'Wurzelseite', 'Seiten', 'Letzte Änderung (Verlauf)', 'Letzte Änderung (Datensätze)', 'Änderungen'], $rows);

        $header = ['Monat'];
        foreach ($statistics['roots'] as $root) {
            $header[] = $root['identifier'];
        }
        $header[] = 'Summe';
        $rows = [];
        foreach ($statistics['months'] as $month) {
            $row = [$month];
            $sum = 0;
            foreach ($statistics['roots'] as $root) {
                $count = $root['months'][$month] ?? 0;
                $row[] = $count;
                $sum += $count;
            }
            $row[] = $sum;
            $rows[] = $row;
        }
        $io->section('Änderungen pro Monat');
        $io->table($header, $rows);

        if ($statistics['ignored'] > 0) {
            $io->note(sprintf(
                '%d Änderung(en) nicht gezählt, weil nur ignorierte Felder geändert wurden (%s).',
                $statistics['ignored'],
                implode(', ', $ignoreFields),
            ));
        }
        if ($excludedRoots !== []) {
            $io->note(sprintf(
                '%d Seitenbaum/-bäume nicht ausgewertet, weil die Wurzelseite nicht aktiv ist: %s (mit --include-inactive-roots einbeziehen).',
                count($excludedRoots),
                implode(', ', array_map(static fn(array $root): string => ($root['title'] !== '' ? $root['title'] : $root['identifier']) . ' [' . $root['rootPageId'] . ']', $excludedRoots)),
            ));
        }
        if ($statistics['unassigned'] > 0) {
            $io->note(sprintf(
                '%d Änderung(en) konnten keinem Seitenbaum zugeordnet werden (z. B. Datensätze auf Root-Ebene wie sys_file,'
                . ' be_users oder bereits endgültig gelöschte Datensätze).',
                $statistics['unassigned'],
            ));
        }
        $io->text('"Letzte Änderung (Datensätze)" = höchster tstamp aller Datensätze im Baum, unabhängig vom gewählten Zeitraum.');
    }

    /**
     * Langformat (eine Zeile pro Site und Monat) – ideal für Pivot-Tabellen in Excel/LibreOffice.
     */
    private function renderCsv(OutputInterface $output, array $statistics): void
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['site', 'root_page_id', 'root_title', 'month', 'changes', 'last_change', 'last_record_tstamp'], ';', '"', '');
        foreach ($statistics['roots'] as $root) {
            foreach ($statistics['months'] as $month) {
                fputcsv($handle, [
                    $root['identifier'],
                    $root['rootPageId'],
                    $root['title'],
                    $month,
                    $root['months'][$month] ?? 0,
                    $this->formatIso($root['lastChange']),
                    $this->formatIso($root['lastRecordTstamp']),
                ], ';', '"', '');
            }
        }
        rewind($handle);
        $output->write((string)stream_get_contents($handle), false, OutputInterface::OUTPUT_RAW);
        fclose($handle);
    }

    private function toJson(array $statistics, string $source, int $since): array
    {
        $sites = [];
        foreach ($statistics['roots'] as $root) {
            $months = [];
            foreach ($statistics['months'] as $month) {
                $months[$month] = $root['months'][$month] ?? 0;
            }
            $sites[] = [
                'site' => $root['identifier'],
                'rootPageId' => $root['rootPageId'],
                'title' => $root['title'],
                'pages' => $root['pages'],
                'lastChange' => $this->formatIso($root['lastChange']),
                'lastRecordTstamp' => $this->formatIso($root['lastRecordTstamp']),
                'total' => $root['total'],
                'months' => $months,
            ];
        }
        return [
            'source' => $source,
            'since' => $since > 0 ? date('Y-m', $since) : null,
            'generated' => date('c'),
            'unassigned' => $statistics['unassigned'],
            'ignored' => $statistics['ignored'],
            'sites' => $sites,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveIgnoreFields(InputInterface $input): array
    {
        $fields = $this->splitList($input->getOption('ignore-field'));
        if ($fields === []) {
            return self::DEFAULT_IGNORE_FIELDS;
        }
        return in_array('none', $fields, true) ? [] : $fields;
    }

    private function resolveSince(InputInterface $input): int
    {
        $sinceOption = $input->getOption('since');
        if ($sinceOption !== null && $sinceOption !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m', (string)$sinceOption);
            if ($date === false) {
                throw new \InvalidArgumentException('--since erwartet das Format JJJJ-MM, z. B. 2025-01');
            }
            return $date->getTimestamp();
        }
        $months = (int)$input->getOption('months');
        if ($months <= 0) {
            return 0;
        }
        return (new \DateTimeImmutable('first day of this month 00:00:00'))
            ->modify('-' . ($months - 1) . ' months')
            ->getTimestamp();
    }

    /**
     * Erlaubt sowohl "-t pages -t tt_content" als auch "-t pages,tt_content".
     *
     * @return list<string>
     */
    private function splitList(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            foreach (explode(',', (string)$value) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $result[] = $part;
                }
            }
        }
        return array_values(array_unique($result));
    }

    private function formatDate(?int $timestamp): string
    {
        return $timestamp ? date('d.m.Y H:i', $timestamp) : '–';
    }

    private function formatIso(?int $timestamp): ?string
    {
        return $timestamp ? date('c', $timestamp) : null;
    }
}
