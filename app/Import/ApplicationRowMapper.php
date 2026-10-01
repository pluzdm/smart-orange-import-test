<?php

namespace App\Import;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;

final class ApplicationRowMapper
{
    private const TEXT_LENGTHS = [
        'external_id' => 64,
        'first_name' => 100,
        'last_name' => 100,
        'phone' => 64,
        'email' => 255,
        'city' => 100,
        'source' => 100,
        'utm_campaign' => 255,
        'product' => 255,
        'status' => 100,
        'manager' => 100,
    ];

    private array $warningCounts = [];

    private array $warningRows = [];

    /** @return Generator<int, array<string, ?string>> */
    public function rows(string $path): Generator
    {
        $this->warningCounts = [];
        $this->warningRows = [];
        $headerSeen = false;

        foreach ((new XlsxWorksheetReader)->rows($path) as $row) {
            if (! $headerSeen) {
                $this->checkHeaders($row);
                $headerSeen = true;

                continue;
            }

            yield $row['number'] => $this->mapRow($row);
        }

        if (! $headerSeen) {
            throw new InvalidArgumentException('The XLSX header row is missing.');
        }
    }

    /** @return array{counts: array<string, int>, sample_rows: array<string, list<int>>} */
    public function warnings(): array
    {
        return ['counts' => $this->warningCounts, 'sample_rows' => $this->warningRows];
    }

    private function checkHeaders(array $row): void
    {
        foreach (XlsxWorksheetReader::HEADERS as $index => $expected) {
            $cell = $row['cells'][$index] ?? null;

            if ($cell === null || $cell['formula'] !== null || $cell['value'] !== $expected) {
                throw new InvalidArgumentException("Row 1, column {$expected}: expected XLSX header {$expected}.");
            }
        }
    }

    /** @return array<string, ?string> */
    private function mapRow(array $row): array
    {
        $prepared = [];
        $number = $row['number'];

        foreach (XlsxWorksheetReader::HEADERS as $index => $column) {
            $cell = $row['cells'][$index] ?? ['type' => '', 'value' => null, 'formula' => null];
            $nullable = in_array($column, [
                'first_name', 'email', 'utm_campaign', 'budget_uah',
                'manager', 'comment', 'next_contact_at',
            ], true);

            if ($cell['formula'] === null && ($cell['value'] === null || $cell['value'] === '')) {
                if (! $nullable) {
                    $this->fail($number, $column, 'a value is required');
                }

                $prepared[$column] = null;

                continue;
            }

            $prepared[$column] = match ($column) {
                'created_at', 'next_contact_at' => $this->date($cell, $row['date1904'], $number, $column),
                'budget_uah' => $this->money($cell, $number, $column),
                'phone' => $this->phone($cell, $number),
                default => $this->text($cell, $number, $column),
            };

            if (isset(self::TEXT_LENGTHS[$column]) && mb_strlen($prepared[$column], 'UTF-8') > self::TEXT_LENGTHS[$column]) {
                $this->fail($number, $column, 'the value exceeds the database column length');
            }

            if ($column === 'comment' && strlen($prepared[$column]) > 65535) {
                $this->fail($number, $column, 'the value exceeds the TEXT column size');
            }
        }

        return $prepared;
    }

    private function text(array $cell, int $row, string $column): string
    {
        if ($cell['formula'] !== null || ! in_array($cell['type'], ['s', 'inlineStr', 'str'], true)) {
            $this->fail($row, $column, 'expected a text cell');
        }

        return $cell['value'];
    }

    private function phone(array $cell, int $row): string
    {
        if ($cell['formula'] !== null) {
            $formula = $cell['formula'];

            if (preg_match('/^\+[0-9]+$/D', $formula)) {
                return $formula;
            }

            if (preg_match('/^\+\+[0-9]+$/D', $formula)) {
                $this->warn('phone_double_plus', $row);
            } else {
                $this->warn('phone_unusual_formula', $row);
            }

            return '='.$formula;
        }

        if (! in_array($cell['type'], ['', 'n'], true)) {
            $this->fail($row, 'phone', 'expected a numeric cell or a formula');
        }

        $number = $this->plainNumber($cell['value'], $row, 'phone');
        $this->warn('phone_numeric', $row);

        return $number;
    }

    private function money(array $cell, int $row, string $column): string
    {
        if ($cell['formula'] !== null || ! in_array($cell['type'], ['', 'n'], true)) {
            $this->fail($row, $column, 'expected a numeric cell');
        }

        $number = $this->plainNumber($cell['value'], $row, $column);
        [$integer, $fraction] = array_pad(explode('.', ltrim($number, '-'), 2), 2, '');

        if (strlen($integer) > 10 || strlen($fraction) > 2) {
            $this->fail($row, $column, 'the value does not fit DECIMAL(12,2) without rounding');
        }

        return (str_starts_with($number, '-') ? '-' : '').$integer.'.'.str_pad($fraction, 2, '0');
    }

    private function plainNumber(?string $raw, int $row, string $column): string
    {
        if ($raw === null || ! preg_match('/^([+-]?)(\d+)(?:\.(\d+))?(?:[Ee]([+-]?\d+))?$/D', $raw, $match)) {
            $this->fail($row, $column, 'invalid numeric value');
        }

        $sign = $match[1] === '-' ? '-' : '';
        $integer = $match[2];
        $fraction = $match[3] ?? '';
        $exponent = isset($match[4]) ? (int) $match[4] : 0;

        if (abs($exponent) > 100 || strlen($integer.$fraction) > 100) {
            $this->fail($row, $column, 'numeric value is too large');
        }

        $digits = $integer.$fraction;
        $point = strlen($integer) + $exponent;

        if ($point <= 0) {
            $integer = '0';
            $fraction = str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $integer = $digits.str_repeat('0', $point - strlen($digits));
            $fraction = '';
        } else {
            $integer = substr($digits, 0, $point);
            $fraction = substr($digits, $point);
        }

        $integer = ltrim($integer, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        if ($integer === '0' && $fraction === '') {
            $sign = '';
        }

        return $sign.$integer.($fraction !== '' ? '.'.$fraction : '');
    }

    private function date(array $cell, bool $date1904, int $row, string $column): string
    {
        if ($cell['formula'] !== null) {
            $this->fail($row, $column, 'date formulas are not supported');
        }

        if ($cell['type'] === 'd') {
            $value = str_replace('T', ' ', $cell['value']);

            if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
                $this->fail($row, $column, 'expected a date and time without a timezone');
            }

            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

            if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
                $this->fail($row, $column, 'invalid date and time');
            }

            $result = $value;
        } else {
            if (! in_array($cell['type'], ['', 'n'], true) || ! is_numeric($cell['value'])) {
                $this->fail($row, $column, 'expected an Excel date serial');
            }

            $serial = (float) $cell['value'];

            if (! is_finite($serial) || $serial < 0 || $serial >= 2958466) {
                $this->fail($row, $column, 'Excel date serial is out of range');
            }

            $days = (int) floor($serial);

            if (! $date1904 && $days === 60) {
                $this->fail($row, $column, 'Excel serial 60 is not a real calendar date');
            }

            $seconds = (int) round(($serial - $days) * 86400);
            $base = $date1904 ? '1904-01-01' : ($days < 60 ? '1899-12-31' : '1899-12-30');
            $date = new DateTimeImmutable($base.' 00:00:00', new DateTimeZone('UTC'));
            $date = $date->add(new DateInterval("P{$days}D"))->add(new DateInterval("PT{$seconds}S"));
            $result = $date->format('Y-m-d H:i:s');
        }

        if ($result < '1000-01-01 00:00:00' || $result > '9999-12-31 23:59:59') {
            $this->fail($row, $column, 'date is outside the MySQL DATETIME range');
        }

        return $result;
    }

    private function warn(string $category, int $row): void
    {
        $this->warningCounts[$category] = ($this->warningCounts[$category] ?? 0) + 1;

        if (count($this->warningRows[$category] ?? []) < 5) {
            $this->warningRows[$category][] = $row;
        }
    }

    private function fail(int $row, string $column, string $reason): never
    {
        throw new InvalidArgumentException("Row {$row}, column {$column}: {$reason}.");
    }
}
