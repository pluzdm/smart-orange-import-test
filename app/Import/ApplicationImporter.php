<?php

namespace App\Import;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ApplicationImporter
{
    /** @return array{inserted_count: int, warnings: array{counts: array<string, int>, sample_rows: array<string, list<int>>}} */
    public function import(string $path): array
    {
        $batchSize = $this->batchSize();
        $mapper = new ApplicationRowMapper;
        $insertedCount = 0;

        DB::transaction(function () use ($path, $batchSize, $mapper, &$insertedCount): void {
            $batch = [];

            foreach ($mapper->rows($path) as $row) {
                $batch[] = $row;

                if (count($batch) === $batchSize) {
                    DB::table('applications')->insert($batch);
                    $insertedCount += count($batch);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                DB::table('applications')->insert($batch);
                $insertedCount += count($batch);
            }
        });

        return ['inserted_count' => $insertedCount, 'warnings' => $mapper->warnings()];
    }

    private function batchSize(): int
    {
        $value = config('import.batch_size');

        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            throw new InvalidArgumentException('Import batch size must be an integer from 1 to 1000.');
        }

        $size = (int) $value;

        if ($size < 1 || $size > 1000) {
            throw new InvalidArgumentException('Import batch size must be an integer from 1 to 1000.');
        }

        return $size;
    }
}
