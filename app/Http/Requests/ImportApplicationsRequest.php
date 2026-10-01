<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportApplicationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:xlsx',
                'max:'.((int) config('import.max_upload_mib') * 1024),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose an XLSX file to import.',
            'file.file' => 'The upload must be a file.',
            'file.mimes' => 'Choose a valid XLSX file.',
            'file.max' => 'The XLSX file must not exceed '.config('import.max_upload_mib').' MiB.',
            'file.uploaded' => 'The file could not be uploaded. It may exceed the server upload limit.',
        ];
    }
}
