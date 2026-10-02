<?php

namespace Tests\Unit;

use App\Import\ApplicationRowMapper;
use App\Import\ImportFileException;
use App\Import\XlsxWorksheetReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ApplicationRowMapperTest extends TestCase
{
    private array $temporaryFiles = [];

    public function test_it_prepares_rows_without_losing_nulls_zero_or_phone_expressions(): void
    {
        $mapper = new ApplicationRowMapper;
        $rows = iterator_to_array($mapper->rows($this->fixture('applications.xlsx')));

        $this->assertCount(6, $rows);
        $this->assertSame($rows[2]['external_id'], $rows[7]['external_id']);
        $this->assertSame('  Ada  ', $rows[2]['first_name']);
        $this->assertNull($rows[3]['first_name']);
        $this->assertNull($rows[2]['email']);
        $this->assertSame('0.00', $rows[2]['budget_uah']);
        $this->assertSame('12.34', $rows[3]['budget_uah']);
        $this->assertNull($rows[4]['budget_uah']);
        $this->assertSame('2025-01-02 03:04:05', $rows[2]['created_at']);
        $this->assertSame('2025-01-03 04:05:06', $rows[2]['next_contact_at']);
        $this->assertNull($rows[7]['next_contact_at']);
        $this->assertSame('+111111111111', $rows[2]['phone']);
        $this->assertSame('+222222222222', $rows[3]['phone']);
        $this->assertSame('+222222222222', $rows[4]['phone']);
        $this->assertSame('=++333333333333', $rows[5]['phone']);
        $this->assertSame('=+12345abc6789', $rows[6]['phone']);
        $this->assertSame('123', $rows[7]['phone']);
        $this->assertSame([
            'phone_double_plus' => 1,
            'phone_unusual_formula' => 1,
            'phone_numeric' => 1,
        ], $mapper->warnings()['counts']);
        $this->assertSame([5], $mapper->warnings()['sample_rows']['phone_double_plus']);
        $this->assertSame([6], $mapper->warnings()['sample_rows']['phone_unusual_formula']);
    }

    public function test_it_uses_the_1904_date_system_when_declared_by_the_workbook(): void
    {
        $mapper = new ApplicationRowMapper;
        $rows = iterator_to_array($mapper->rows($this->fixture('date_1904.xlsx')));

        $this->assertSame('2025-01-02 03:04:05', $rows[2]['created_at']);
        $this->assertSame('2025-01-03 04:05:06', $rows[2]['next_contact_at']);
    }

    public function test_it_rejects_wrong_headers(): void
    {
        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Row 1, column external_id');

        iterator_to_array((new ApplicationRowMapper)->rows($this->fixture('wrong_headers.xlsx')));
    }

    public function test_it_rejects_a_shared_formula_with_a_conflicting_cache(): void
    {
        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Row 4, column phone: shared formula cache does not match');

        iterator_to_array((new ApplicationRowMapper)->rows($this->fixture('shared_cache_mismatch.xlsx')));
    }

    public function test_it_rejects_money_that_would_require_rounding(): void
    {
        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Row 2, column budget_uah');

        iterator_to_array((new ApplicationRowMapper)->rows($this->fixture('bad_budget.xlsx')));
    }

    public function test_it_rejects_text_that_exceeds_the_schema(): void
    {
        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Row 2, column external_id');

        iterator_to_array((new ApplicationRowMapper)->rows($this->fixture('long_external_id.xlsx')));
    }

    public function test_warning_examples_are_bounded(): void
    {
        $mapper = new ApplicationRowMapper;
        $count = 0;

        foreach ($mapper->rows($this->fixture('many_warnings.xlsx')) as $row) {
            $count++;
        }

        $this->assertSame(8, $count);
        $this->assertSame(8, $mapper->warnings()['counts']['phone_numeric']);
        $this->assertSame([2, 3, 4, 5, 6], $mapper->warnings()['sample_rows']['phone_numeric']);
    }

    public function test_it_rejects_truncated_worksheet_after_several_rows(): void
    {
        $this->assertMalformedWorksheetIsRejected(false);
    }

    public function test_it_rejects_truncated_worksheet_after_the_last_row(): void
    {
        $this->assertMalformedWorksheetIsRejected(true);
    }

    public function test_it_checks_mysql_datetime_boundaries_for_date_cells(): void
    {
        foreach ([
            ['1000-01-01T00:00:00', '1000-01-01 00:00:00'],
            ['9999-12-31T23:59:59', '9999-12-31 23:59:59'],
        ] as [$input, $expected]) {
            $path = $this->withCreatedAtCell('d', $input);
            $rows = iterator_to_array((new ApplicationRowMapper)->rows($path));

            $this->assertSame($expected, $rows[2]['created_at']);
        }

        foreach (['0999-12-31T23:59:59', '0000-01-01T00:00:00', '10000-01-01T00:00:00'] as $input) {
            $path = $this->withCreatedAtCell('d', $input);

            try {
                iterator_to_array((new ApplicationRowMapper)->rows($path));
                $this->fail('An out-of-range date cell was accepted.');
            } catch (ImportFileException $exception) {
                $this->assertStringContainsString('Row 2, column created_at', $exception->getMessage());
            }
        }
    }

    public function test_it_checks_mysql_datetime_boundaries_for_excel_serials(): void
    {
        foreach ([
            ['0', '1899-12-31 00:00:00', false],
            ['2958465.999988426', '9999-12-31 23:59:59', false],
            ['2958465', '9999-12-31 00:00:00', false],
            ['2957003.999988426', '9999-12-31 23:59:59', true],
        ] as [$input, $expected, $date1904]) {
            $path = $this->withCreatedAtCell('n', $input, $date1904);
            $rows = iterator_to_array((new ApplicationRowMapper)->rows($path));

            $this->assertSame($expected, $rows[2]['created_at']);
        }

        foreach (['-1', '2958466'] as $input) {
            $path = $this->withCreatedAtCell('n', $input);

            try {
                iterator_to_array((new ApplicationRowMapper)->rows($path));
                $this->fail('An out-of-range Excel serial was accepted.');
            } catch (ImportFileException $exception) {
                $this->assertStringContainsString('Row 2, column created_at', $exception->getMessage());
            }
        }
    }

    public function test_it_rejects_excel_serials_that_round_into_year_10000(): void
    {
        $accepted = [];

        foreach ([
            ['2958465.999999', false],
            ['2957003.999999', true],
        ] as [$serial, $date1904]) {
            $path = $this->withCreatedAtCell('n', $serial, $date1904);

            try {
                iterator_to_array((new ApplicationRowMapper)->rows($path));
                $accepted[] = $serial;
            } catch (ImportFileException $exception) {
                $this->assertSame('Row 2, column created_at: date is outside the MySQL DATETIME range.', $exception->getMessage());
            }
        }

        $this->assertSame([], $accepted, 'Excel dates rounded into year 10000 were accepted.');
    }

    public function test_excel_serial_60_is_rejected_only_in_the_1900_date_system(): void
    {
        $path1904 = $this->withCreatedAtCell('n', '60', true);
        $rows = iterator_to_array((new ApplicationRowMapper)->rows($path1904));
        $this->assertSame('1904-03-01 00:00:00', $rows[2]['created_at']);

        $path1900 = $this->withCreatedAtCell('n', '60');
        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Row 2, column created_at: Excel serial 60 is not a real calendar date.');

        iterator_to_array((new ApplicationRowMapper)->rows($path1900));
    }

    public function test_it_accepts_all_date1904_boolean_representations(): void
    {
        foreach (['1', 'true', '0', 'false', null] as $attribute) {
            $path = $this->modifiedFixture('date_1904.xlsx', static function (string $part, string $contents) use ($attribute): string {
                if ($part !== 'xl/workbook.xml') {
                    return $contents;
                }

                return str_replace('date1904="1"', $attribute === null ? '' : 'date1904="'.$attribute.'"', $contents);
            });
            $rows = iterator_to_array((new ApplicationRowMapper)->rows($path));

            $this->assertSame(
                in_array($attribute, ['1', 'true'], true) ? '2025-01-02 03:04:05' : '2021-01-01 03:04:05',
                $rows[2]['created_at']
            );
        }
    }

    public function test_it_resets_warnings_when_the_mapper_is_reused(): void
    {
        $mapper = new ApplicationRowMapper;
        iterator_to_array($mapper->rows($this->fixture('many_warnings.xlsx')));
        $this->assertSame(8, $mapper->warnings()['counts']['phone_numeric']);

        iterator_to_array($mapper->rows($this->fixture('date_1904.xlsx')));
        $this->assertSame([], $mapper->warnings()['counts']);
        $this->assertSame([], $mapper->warnings()['sample_rows']);
    }

    public function test_it_rejects_a_shared_formula_reference_without_substituting_the_base(): void
    {
        $path = $this->modifiedFixture('applications.xlsx', static function (string $part, string $contents): string {
            if ($part !== 'xl/worksheets/sheet1.xml') {
                return $contents;
            }

            return str_replace('ref="E3:E4">+222222222222</ns0:f>', 'ref="E3:E4">A3</ns0:f>', $contents);
        });

        $this->expectException(ImportFileException::class);
        $this->expectExceptionMessage('Row 4, column phone: shared formula cannot be expanded');

        iterator_to_array((new ApplicationRowMapper)->rows($path));
    }

    private function assertMalformedWorksheetIsRejected(bool $afterLastRow): void
    {
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:A80"/><sheetData>';

        for ($number = 1; $number <= ($afterLastRow ? 80 : 40); $number++) {
            $xml .= '<row r="'.$number.'"><c r="A'.$number.'" t="inlineStr"><is><t>'.str_repeat('x', 80).'</t></is></c></row>';
        }

        if ($afterLastRow) {
            $xml .= '</sheetData></worksheet>'.str_repeat(' ', 10000).'<broken';
        }

        $path = $this->modifiedFixture('applications.xlsx', static fn (string $part, string $contents): string => $part === 'xl/worksheets/sheet1.xml' ? $xml : $contents);
        $read = 0;
        $previousErrorState = libxml_use_internal_errors($afterLastRow);

        try {
            try {
                foreach ((new XlsxWorksheetReader)->rows($path) as $row) {
                    $read++;
                }

                $this->fail('Incomplete worksheet XML was accepted.');
            } catch (ImportFileException $exception) {
                $this->assertSame('Invalid or incomplete XLSX worksheet XML.', $exception->getMessage());
                $this->assertGreaterThan(1, $read);
            }

            $this->assertSame($afterLastRow, libxml_use_internal_errors());
            $this->assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors($previousErrorState);
        }
    }

    private function withCreatedAtCell(string $type, string $value, bool $date1904 = false): string
    {
        return $this->modifiedFixture('applications.xlsx', static function (string $part, string $contents) use ($type, $value, $date1904): string {
            if ($part === 'xl/workbook.xml' && $date1904) {
                return str_replace('<workbookPr/>', '<workbookPr date1904="1"/>', $contents);
            }

            if ($part !== 'xl/worksheets/sheet1.xml') {
                return $contents;
            }

            return preg_replace(
                '/<ns0:c r="B2"[^>]*>.*?<\/ns0:c>/',
                '<ns0:c r="B2" t="'.$type.'"><ns0:v>'.$value.'</ns0:v></ns0:c>',
                $contents,
                1
            );
        });
    }

    private function modifiedFixture(string $fixture, callable $modify): string
    {
        $source = new ZipArchive;
        $target = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        $this->temporaryFiles[] = $path;

        $this->assertTrue($source->open($this->fixture($fixture)));
        $this->assertTrue($target->open($path, ZipArchive::OVERWRITE));

        try {
            for ($index = 0; $index < $source->numFiles; $index++) {
                $part = $source->getNameIndex($index);
                $contents = $source->getFromIndex($index);
                $this->assertTrue($target->addFromString($part, $modify($part, $contents)));
            }
        } finally {
            $source->close();
            $target->close();
        }

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }

        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__).'/Fixtures/'.$name;
    }
}
