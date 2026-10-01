<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportApplicationsRequest;
use App\Import\ApplicationImporter;
use App\Import\ImportFileException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use PDOException;

final class ImportController extends Controller
{
    public function create(): View
    {
        return view('imports.create');
    }

    public function store(ImportApplicationsRequest $request, ApplicationImporter $importer): RedirectResponse
    {
        $startedAt = hrtime(true);

        try {
            $result = $importer->import($request->file('file')->getRealPath());
        } catch (ImportFileException $exception) {
            return redirect()->route('imports.create')->with('import_error', $exception->getMessage());
        } catch (PDOException $exception) {
            Log::error('Application import database failure.', [
                'sqlstate' => $exception->errorInfo[0] ?? null,
                'driver_code' => $exception->errorInfo[1] ?? null,
            ]);

            return redirect()->route('imports.create')->with('import_error', 'The database could not save this import. No rows were added.');
        }

        return redirect()->route('imports.create')->with('import_result', [
            'inserted_count' => $result['inserted_count'],
            'duration_seconds' => round((hrtime(true) - $startedAt) / 1_000_000_000, 2),
            'warnings' => $result['warnings']['counts'],
        ]);
    }
}
