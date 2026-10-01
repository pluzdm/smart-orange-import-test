<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upload too large</title>
    <style>
        body { max-width: 680px; margin: 64px auto; padding: 0 20px; color: #17212b; font: 16px/1.5 system-ui, sans-serif; }
        a { color: #176b43; }
    </style>
</head>
<body>
    <h1>Upload too large</h1>
    <p>The request exceeds the server upload limit. Choose an XLSX file no larger than {{ config('import.max_upload_mib') }} MiB.</p>
    <p><a href="{{ route('imports.create') }}">Return to the import form</a></p>
</body>
</html>
