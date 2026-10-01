<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ImportPageTest extends TestCase
{
    private const TEST_DATABASE = 'applications_import_test';

    protected function setUp(): void
    {
        parent::setUp();

        $mysql = config('database.connections.mysql');
        $this->assertSame('mysql', $mysql['driver']);
        $this->assertNotSame(self::TEST_DATABASE, $mysql['database']);

        config([
            'database.connections.import_test' => array_replace($mysql, [
                'database' => self::TEST_DATABASE,
                'url' => null,
            ]),
            'database.default' => 'import_test',
        ]);

        DB::purge('import_test');
        $this->assertTestDatabase();
        Artisan::call('migrate', ['--database' => 'import_test', '--force' => true]);

        $this->assertTestDatabase();
        DB::connection('import_test')->table('applications')->delete();
    }

    public function test_the_form_shows_the_repeat_import_warning_and_csrf_field(): void
    {
        $this->get(route('imports.create'))
            ->assertOk()
            ->assertSee('Re-importing a file adds its records again, including duplicate external IDs.')
            ->assertSee('name="_token"', false)
            ->assertSee('name="file"', false);
    }

    public function test_a_valid_upload_imports_and_redirects_to_the_result(): void
    {
        $response = $this->post(route('imports.store'), [
            'file' => $this->fixtureUpload('applications.xlsx'),
        ]);

        $response->assertRedirect(route('imports.create'));
        $this->assertSame(6, DB::table('applications')->count());

        $this->get(route('imports.create'))
            ->assertOk()
            ->assertSee('Rows added: 6.')
            ->assertSee('Import time:')
            ->assertSee('Numeric phone values retained')
            ->assertSee('Unusual phone formulas retained')
            ->assertSee('Phone formulas with two plus signs retained');
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->post(route('imports.store'), [])
            ->assertRedirect(route('imports.create'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, DB::table('applications')->count());
    }

    public function test_a_non_xlsx_file_is_rejected_even_with_an_xlsx_name(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'invalid-upload-');
        file_put_contents($path, 'This is plain text.');
        $file = new UploadedFile($path, 'not-a-workbook.xlsx', null, UPLOAD_ERR_OK, true);

        try {
            $this->post(route('imports.store'), ['file' => $file])
                ->assertRedirect(route('imports.create'))
                ->assertSessionHasErrors('file');
        } finally {
            unlink($path);
        }

        $this->assertSame(0, DB::table('applications')->count());
    }

    public function test_invalid_xlsx_content_rolls_back_inserted_batches(): void
    {
        config(['import.batch_size' => 2]);

        $this->post(route('imports.store'), [
            'file' => $this->fixtureUpload('shared_cache_mismatch.xlsx'),
        ])->assertRedirect(route('imports.create'))
            ->assertSessionHas('import_error', 'Row 4, column phone: shared formula cache does not match its base expression.');

        $this->assertSame(0, DB::table('applications')->count());
    }

    public function test_a_database_error_has_a_neutral_response_and_sanitized_log(): void
    {
        Schema::connection('import_test')->table('applications', static function (Blueprint $table): void {
            $table->unique('external_id', 'test_external_id_unique');
        });

        try {
            Log::shouldReceive('error')->once()->with('Application import database failure.', [
                'sqlstate' => '23000',
                'driver_code' => 1062,
            ]);

            $this->post(route('imports.store'), [
                'file' => $this->fixtureUpload('applications.xlsx'),
            ])->assertRedirect(route('imports.create'))
                ->assertSessionHas('import_error', 'The database could not save this import. No rows were added.');

            $this->assertSame(0, DB::table('applications')->count());
        } finally {
            Schema::connection('import_test')->table('applications', static function (Blueprint $table): void {
                $table->dropUnique('test_external_id_unique');
            });
        }
    }

    public function test_an_unexpected_error_uses_laravels_standard_error_response(): void
    {
        config(['app.debug' => false, 'import.batch_size' => 0]);

        $this->post(route('imports.store'), [
            'file' => $this->fixtureUpload('applications.xlsx'),
        ])->assertStatus(500)
            ->assertDontSee('Import batch size must be an integer from 1 to 1000.')
            ->assertSessionMissing('import_error');

        $this->assertSame(0, DB::table('applications')->count());
    }

    public function test_the_application_file_limit_is_enforced(): void
    {
        $file = UploadedFile::fake()->create('large.xlsx', 20481, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $this->post(route('imports.store'), ['file' => $file])
            ->assertRedirect(route('imports.create'))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, DB::table('applications')->count());
    }

    public function test_a_request_over_post_max_size_has_a_clear_response(): void
    {
        $this->withServerVariables(['CONTENT_LENGTH' => 24 * 1024 * 1024 + 1])
            ->post(route('imports.store'), [])
            ->assertStatus(413)
            ->assertSee('Upload too large')
            ->assertSee('20 MiB');

        $this->assertSame(0, DB::table('applications')->count());
    }

    private function assertTestDatabase(): void
    {
        $connection = DB::connection('import_test');
        $this->assertSame(self::TEST_DATABASE, $connection->getDatabaseName());
        $this->assertSame(self::TEST_DATABASE, $connection->selectOne('SELECT DATABASE() AS database_name')->database_name);
    }

    private function fixtureUpload(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            file_get_contents(dirname(__DIR__).'/Fixtures/'.$name)
        );
    }

    protected function tearDown(): void
    {
        DB::disconnect('import_test');
        parent::tearDown();
    }
}
