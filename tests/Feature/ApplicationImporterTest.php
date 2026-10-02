<?php

namespace Tests\Feature;

use App\Import\ApplicationImporter;
use App\Import\ImportFileException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ZipArchive;

final class ApplicationImporterTest extends MySqlIntegrationTestCase
{
    private array $temporaryFiles = [];

    public function test_it_inserts_multiple_batches_and_a_partial_final_batch(): void
    {
        config(['import.batch_size' => 4]);
        $connection = DB::connection('import_test');
        $connection->enableQueryLog();

        $result = (new ApplicationImporter)->import($this->fixture('applications.xlsx'));

        $this->assertSame(6, $result['inserted_count']);
        $this->assertSame(6, $connection->table('applications')->count());
        $this->assertSame(2, $this->insertQueryCount());
        $this->assertSame([
            'phone_double_plus' => 1,
            'phone_unusual_formula' => 1,
            'phone_numeric' => 1,
        ], $result['warnings']['counts']);
    }

    public function test_it_preserves_duplicates_source_dates_and_nulls(): void
    {
        (new ApplicationImporter)->import($this->fixture('applications.xlsx'));
        $rows = DB::table('applications')->orderBy('id')->get();

        $this->assertSame(6, $rows->count());
        $this->assertSame($rows[0]->external_id, $rows[5]->external_id);
        $this->assertSame('2025-01-02 03:04:05', $rows[0]->created_at);
        $this->assertSame('2025-01-03 04:05:06', $rows[0]->next_contact_at);
        $this->assertSame('  Ada  ', $rows[0]->first_name);
        $this->assertNull($rows[1]->first_name);
        $this->assertNull($rows[0]->email);
        $this->assertNull($rows[2]->budget_uah);
        $this->assertNull($rows[5]->next_contact_at);
        $this->assertSame('0.00', $rows[0]->budget_uah);
    }

    public function test_reimport_appends_rows(): void
    {
        $importer = new ApplicationImporter;
        $first = $importer->import($this->fixture('applications.xlsx'));
        $second = $importer->import($this->fixture('applications.xlsx'));

        $this->assertSame(6, $first['inserted_count']);
        $this->assertSame(6, $second['inserted_count']);
        $this->assertSame(12, DB::table('applications')->count());
    }

    public function test_a_mapping_error_after_the_first_batch_rolls_back_the_import(): void
    {
        config(['import.batch_size' => 2]);
        $path = $this->modifiedFixture(static function (string $part, string $contents): string {
            if ($part !== 'xl/worksheets/sheet1.xml') {
                return $contents;
            }

            return str_replace('<ns0:c r="K7" t="n"><ns0:v>0</ns0:v></ns0:c>', '<ns0:c r="K7" t="n"><ns0:v>1.234</ns0:v></ns0:c>', $contents);
        });
        DB::connection('import_test')->enableQueryLog();

        try {
            (new ApplicationImporter)->import($path);
            $this->fail('An invalid row was accepted.');
        } catch (ImportFileException $exception) {
            $this->assertStringContainsString('Row 7, column budget_uah', $exception->getMessage());
        }

        $this->assertGreaterThan(0, $this->insertQueryCount());
        $this->assertSame(0, DB::table('applications')->count());
    }

    public function test_corrupt_xml_after_the_last_row_rolls_back_the_import(): void
    {
        config(['import.batch_size' => 2]);
        $path = $this->modifiedFixture(static fn (string $part, string $contents): string => $part === 'xl/worksheets/sheet1.xml'
            ? $contents.str_repeat(' ', 10000).'<broken'
            : $contents);
        DB::connection('import_test')->enableQueryLog();

        try {
            (new ApplicationImporter)->import($path);
            $this->fail('Corrupt worksheet XML was accepted.');
        } catch (ImportFileException $exception) {
            $this->assertSame('Invalid or incomplete XLSX worksheet XML.', $exception->getMessage());
        }

        $this->assertGreaterThan(0, $this->insertQueryCount());
        $this->assertSame(0, DB::table('applications')->count());
    }

    public function test_failed_import_leaves_existing_rows_untouched(): void
    {
        config(['import.batch_size' => 2]);
        $importer = new ApplicationImporter;
        $importer->import($this->fixture('applications.xlsx'));
        $before = DB::table('applications')->orderBy('id')->get()->toArray();
        $path = $this->modifiedFixture(static fn (string $part, string $contents): string => $part === 'xl/worksheets/sheet1.xml'
            ? $contents.str_repeat(' ', 10000).'<broken'
            : $contents);

        try {
            $importer->import($path);
            $this->fail('Corrupt worksheet XML was accepted.');
        } catch (ImportFileException $exception) {
            $this->assertSame('Invalid or incomplete XLSX worksheet XML.', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('applications')->orderBy('id')->get()->toArray());
    }

    public function test_it_rejects_invalid_batch_sizes_before_writing(): void
    {
        foreach ([0, -1, 1001, 'abc', 1.5] as $batchSize) {
            config(['import.batch_size' => $batchSize]);

            try {
                (new ApplicationImporter)->import($this->fixture('applications.xlsx'));
                $this->fail('An invalid batch size was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Import batch size must be an integer from 1 to 1000.', $exception->getMessage());
            }
        }

        $this->assertSame(0, DB::table('applications')->count());
    }

    private function insertQueryCount(): int
    {
        return count(array_filter(
            DB::connection('import_test')->getQueryLog(),
            static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'insert into `applications`')
        ));
    }

    private function modifiedFixture(callable $modify): string
    {
        $source = new ZipArchive;
        $target = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'xlsx-import-test-');
        $this->temporaryFiles[] = $path;

        $this->assertTrue($source->open($this->fixture('applications.xlsx')));
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

    private function fixture(string $name): string
    {
        return dirname(__DIR__).'/Fixtures/'.$name;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }

        parent::tearDown();
    }
}
