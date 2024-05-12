<?php

namespace Ordinal\VttTools;

use Garden\Cli\Application\CliApplication;
use Garden\Cli\Args;
use Garden\Cli\Schema\CommandSchema;
use Garden\Cli\Schema\OptSchema;
use Garden\Cli\StreamLogger;
use Garden\Cli\TaskLogger;

/**
 * @psalm-type _DataRow = list<string>
 */
class ParseAnydiceExport extends CliApplication
{
    private const FORMAT_CSV = 'csv';
    private const FORMAT_MARKDOWN = 'markdown';
    private const FORMATS = [self::FORMAT_CSV, self::FORMAT_MARKDOWN];
    private const DESCRIPTION = 'Parse output from anydice.com';
    private const OPT_FILE = 'file';
    private const OPT_OUTPUT = 'output';

    protected function configureCli(): void
    {
        parent::configureCli();
        $this->addOpt(new OptSchema(
            self::OPT_OUTPUT . ':o', 'Output format - ' . implode(', ', self::FORMATS), false, 'string'
        ))
            ->addOpt(new OptSchema(self::OPT_FILE . ':f', 'File to parse', true, 'string'))
            ->description(self::DESCRIPTION);
    }

    protected function dispatchInternal(Args $args, CommandSchema $schema, bool $throw = true): void
    {
        $this->run($args->getOpts());
    }

    private function run(array $opts): void
    {
        $log = self::getLogger();
        $file = $opts['file'] ?? null;
        if (!is_file($file)) {
            $log->error("File '$file' not found");

            return;
        }
        $format = $opts[self::OPT_OUTPUT] ?? self::FORMAT_CSV;
        if (!in_array($format, self::FORMATS, true)) {
            $log->error('Unknown format "' . $format . '"');

            return;
        }

        $log->beginInfo('Parsing', [self::OPT_FILE => $file]);
        $file_as_string = str_replace("#,%\n", '', file_get_contents($file));
        $chunks = explode("\n\n", trim($file_as_string));

        $header = ['Roll'];
        $rows = [];
        foreach ($chunks as $chunk) {
            $chunk_lines = explode("\n", $chunk);
            $title_line = str_getcsv(array_shift($chunk_lines));
            $this_row = [$title_line[0]];
            foreach ($chunk_lines as $n => $chunk_line) {
                $chunk_line_parsed = str_getcsv($chunk_line);
                $this_row[] = round((float)$chunk_line_parsed[1]);
                if (count($header) > count($chunk_lines)) {
                    continue;
                }
                $header[$n + 1] = $chunk_line_parsed[0];
            }
            $rows[] = $this_row;
        }

        switch ($format) {
            case self::FORMAT_CSV:
                self::outputCsv($header, $rows);
                break;
            case self::FORMAT_MARKDOWN:
                self::outputMarkdown($header, $rows);
                break;
        }

        $log->end('Finished');
    }

    private static function getLogger(): TaskLogger
    {
        $fmt = new StreamLogger(STDERR);
        $fmt->setLineFormat('[{time}] {level}: {message}');
        $fmt->setLevelFormat('strtoupper');
        $fmt->setTimeFormat(function ($ts) {
            return date('c', $ts);
        });
        $log = new TaskLogger($fmt);

        return $log;
    }

    private static function outputCsv(array $header, array $rows): void
    {
        fputcsv(STDOUT, $header);
        foreach ($rows as $row) {
            fputcsv(STDOUT, $row);
        }
    }

    private static function outputMarkdown(array $header, array $rows): void
    {
        $widths = self::getMarkdownColumnWidths($header, $rows);
        $header_row = self::makeMarkdownRow($header, $widths);
        $table = [$header_row, self::makeMarkdownTableSeparator($widths)];
        $table = array_merge($table, array_map(static fn($row) => self::makeMarkdownRow($row, $widths), $rows));
        echo implode("\n", $table);
    }

    /**
     * @param _DataRow $header
     * @param _DataRow $rows
     * @return list<int>
     */
    private static function getMarkdownColumnWidths(array $header, array $rows): array
    {
        $widths = array_fill(0, count($header), 0);
        foreach (array_merge([$header], $rows) as $row) {
            foreach ($row as $n => $column) {
                $length = strlen(trim($column));
                if ($length <= $widths[$n]) {
                    continue;
                }
                $widths[$n] = $length;
            }
        }

        return $widths;
    }

    /**
     * @param _DataRow $row
     */
    private static function makeMarkdownRow(array $row, array $widths): string
    {
        $row = array_pad($row, count($widths), '');
        foreach ($row as $n => &$column) {
            $column = str_pad(trim($column), $widths[$n++]);
        }
        return '| ' . implode(' | ', $row) . ' |';
    }

    /**
     * @param list<int> $widths
     */
    private static function makeMarkdownTableSeparator(array $widths): string
    {
        return array_reduce($widths, static fn(string $carry, int $width) => $carry . str_repeat('-', $width + 2) . '|', '|');
    }
}
