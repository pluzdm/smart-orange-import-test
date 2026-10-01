<?php

namespace App\Import;

use Generator;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

final class XlsxWorksheetReader
{
    public const HEADERS = [
        'external_id', 'created_at', 'first_name', 'last_name', 'phone',
        'email', 'city', 'source', 'utm_campaign', 'product',
        'budget_uah', 'status', 'manager', 'comment', 'next_contact_at',
    ];

    /**
     * @return Generator<int, array{number: int, date1904: bool, cells: array<int, array{type: string, value: ?string, formula: ?string}>}>
     */
    public function rows(string $path): Generator
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open the XLSX file.');
        }

        $previousXmlErrorState = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            [$sheetPath, $date1904] = $this->workbookDetails($zip);
            $sharedStrings = $this->sharedStrings($zip, $path);
            $reader = $this->openPart($zip, $path, $sheetPath);

            try {
                $sharedFormulas = [];
                $previousRow = 0;

                while ($reader->read() && ! ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row')) {
                }

                while ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'row') {
                    $xml = $reader->readOuterXml();

                    if ($xml === '') {
                        throw new RuntimeException('Invalid or incomplete XLSX worksheet XML.');
                    }

                    $row = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);

                    if ($row === false) {
                        throw new RuntimeException('Unable to read an XLSX worksheet row.');
                    }

                    $number = (int) $row->attributes()['r'];

                    if ($number !== $previousRow + 1) {
                        $expectedRow = $previousRow + 1;
                        throw new RuntimeException("Missing or out-of-order XLSX row {$expectedRow}.");
                    }

                    $cells = [];

                    foreach ($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell) {
                        $reference = (string) $cell->attributes()['r'];

                        if (! preg_match('/^([A-Z]+)(\d+)$/', $reference, $match) || (int) $match[2] !== $number) {
                            throw new RuntimeException("Row {$number}: invalid cell reference.");
                        }

                        $index = $this->columnIndex($match[1]);

                        if ($index >= count(self::HEADERS)) {
                            throw new RuntimeException("Row {$number}: unexpected column {$match[1]}.");
                        }

                        $column = self::HEADERS[$index];

                        if (isset($cells[$index])) {
                            throw new RuntimeException("Row {$number}, column {$column}: duplicate cell {$reference}.");
                        }

                        $type = (string) $cell->attributes()['t'];
                        $children = $cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                        $value = isset($children->v) ? (string) $children->v : null;
                        $formula = null;

                        if (isset($children->f)) {
                            $formula = (string) $children->f;
                            $formulaType = (string) $children->f->attributes()['t'];

                            if ($formulaType === 'shared') {
                                $sharedIndex = (string) $children->f->attributes()['si'];

                                if ($sharedIndex === '') {
                                    throw new RuntimeException("Row {$number}, column {$column}: shared formula index is missing.");
                                }

                                if ($formula !== '') {
                                    $sharedFormulas[$sharedIndex] = $formula;
                                } elseif (isset($sharedFormulas[$sharedIndex])) {
                                    if (! preg_match('/^\+[0-9]+$/D', $sharedFormulas[$sharedIndex])) {
                                        throw new RuntimeException("Row {$number}, column {$column}: shared formula cannot be expanded without calculation.");
                                    }

                                    $formula = $sharedFormulas[$sharedIndex];

                                    if ($value !== substr($formula, 1)) {
                                        throw new RuntimeException("Row {$number}, column {$column}: shared formula cache does not match its base expression.");
                                    }
                                } else {
                                    throw new RuntimeException("Row {$number}, column {$column}: shared formula base is missing.");
                                }
                            } elseif ($formula === '') {
                                throw new RuntimeException("Row {$number}, column {$column}: formula text is missing.");
                            }
                        } elseif ($type === 's') {
                            if ($value === null || ! ctype_digit($value) || ! array_key_exists((int) $value, $sharedStrings)) {
                                throw new RuntimeException("Row {$number}, column {$column}: shared string is missing.");
                            }

                            $value = $sharedStrings[(int) $value];
                        } elseif ($type === 'inlineStr') {
                            $value = isset($children->is) ? dom_import_simplexml($children->is)->textContent : null;
                        }

                        $cells[$index] = compact('type', 'value', 'formula');
                    }

                    yield ['number' => $number, 'date1904' => $date1904, 'cells' => $cells];
                    $previousRow = $number;

                    if (! $reader->next('row')) {
                        break;
                    }
                }

                if (libxml_get_errors() !== []) {
                    throw new RuntimeException('Invalid or incomplete XLSX worksheet XML.');
                }
            } finally {
                $reader->close();
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousXmlErrorState);
            $zip->close();
        }
    }

    /** @return array{string, bool} */
    private function workbookDetails(ZipArchive $zip): array
    {
        $workbook = $this->partXml($zip, 'xl/workbook.xml');
        $spreadsheetNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheets = $workbook->children($spreadsheetNamespace)->sheets->children($spreadsheetNamespace)->sheet;

        if (count($sheets) !== 1) {
            throw new RuntimeException('The XLSX file must contain exactly one worksheet.');
        }

        $relationId = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $relationships = $this->partXml($zip, 'xl/_rels/workbook.xml.rels');
        $target = null;

        foreach ($relationships->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $relationship) {
            if ((string) $relationship->attributes()['Id'] === $relationId) {
                $target = (string) $relationship->attributes()['Target'];
                break;
            }
        }

        if ($target === null || str_contains($target, '..') || str_contains($target, '://')) {
            throw new RuntimeException('The XLSX worksheet reference is invalid.');
        }

        $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        $properties = $workbook->children($spreadsheetNamespace)->workbookPr;
        $date1904Value = isset($properties) ? (string) $properties->attributes()['date1904'] : '';

        if (! in_array($date1904Value, ['', '0', '1', 'false', 'true'], true)) {
            throw new RuntimeException('Invalid XLSX date1904 value.');
        }

        $date1904 = in_array($date1904Value, ['1', 'true'], true);

        return [$sheetPath, $date1904];
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip, string $path): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $reader = $this->openPart($zip, $path, 'xl/sharedStrings.xml');
        $strings = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $strings[] = $reader->readString();
                }
            }

            if (libxml_get_errors() !== []) {
                throw new RuntimeException('Invalid or incomplete XLSX shared strings XML.');
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    private function openPart(ZipArchive $zip, string $path, string $part): XMLReader
    {
        if ($zip->locateName($part) === false) {
            throw new RuntimeException("The XLSX part {$part} is missing.");
        }

        $reader = new XMLReader;
        $uri = 'zip://'.realpath($path).'#'.$part;

        if (! $reader->open($uri, null, LIBXML_NONET)) {
            throw new RuntimeException("Unable to read the XLSX part {$part}.");
        }

        return $reader;
    }

    private function partXml(ZipArchive $zip, string $part): SimpleXMLElement
    {
        $xml = $zip->getFromName($part);

        if ($xml === false || ($element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET)) === false) {
            throw new RuntimeException("Unable to read the XLSX part {$part}.");
        }

        return $element;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - ord('A') + 1;
        }

        return $index - 1;
    }
}
