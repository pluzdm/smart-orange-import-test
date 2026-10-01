<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import applications</title>
    <style>
        body { margin: 0; background: #f5f7f9; color: #17212b; font: 16px/1.5 system-ui, sans-serif; }
        main { max-width: 700px; margin: 64px auto; padding: 0 20px; }
        .card { background: #fff; border: 1px solid #dce2e7; border-radius: 12px; padding: 28px; }
        h1 { margin: 0 0 8px; font-size: 1.8rem; }
        h2 { margin: 24px 0 8px; font-size: 1.15rem; }
        p { margin: 0 0 18px; }
        .notice, .error, .success { border-radius: 8px; padding: 14px 16px; margin: 18px 0; }
        .notice { background: #fff5db; border: 1px solid #e7c674; }
        .error { background: #ffeded; border: 1px solid #e5a4a4; }
        .success { background: #e9f6ed; border: 1px solid #9bcdaa; }
        label { display: block; font-weight: 600; margin-bottom: 8px; }
        input[type="file"] { display: block; width: 100%; box-sizing: border-box; margin-bottom: 18px; }
        button { background: #176b43; color: #fff; border: 0; border-radius: 7px; padding: 11px 22px; font: inherit; cursor: pointer; }
        button:hover, button:focus-visible { background: #0f5031; }
        ul { margin: 8px 0 0; padding-left: 22px; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>Import applications</h1>
        <p>Select an XLSX file with the expected 15 columns. Maximum file size: {{ config('import.max_upload_mib') }} MiB.</p>

        <div class="notice" role="note">
            Re-importing a file adds its records again, including duplicate external IDs.
        </div>

        @if ($errors->any())
            <div class="error" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (session('import_error'))
            <div class="error" role="alert">{{ session('import_error') }}</div>
        @endif

        @if ($result = session('import_result'))
            <div class="success" role="status">
                <strong>Import completed.</strong>
                <div>Rows added: {{ number_format($result['inserted_count']) }}.</div>
                <div>Import time: {{ number_format($result['duration_seconds'], 2) }} seconds.</div>
            </div>

            <h2>Warnings</h2>
            @if ($result['warnings'] === [])
                <p>No warnings.</p>
            @else
                <ul>
                    @foreach ($result['warnings'] as $category => $count)
                        <li>{{ Lang::has("import.warnings.{$category}") ? __("import.warnings.{$category}") : __('import.warnings.other') }}: {{ number_format($count) }}</li>
                    @endforeach
                </ul>
            @endif
        @endif

        <form method="post" action="{{ route('imports.store') }}" enctype="multipart/form-data">
            @csrf
            <label for="file">XLSX file</label>
            <input id="file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            <button type="submit">Import</button>
        </form>
    </div>
</main>
</body>
</html>
