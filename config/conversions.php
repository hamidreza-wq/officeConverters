<?php

return [
    'max_kb' => (int) env('CONVERSION_MAX_KB', 5120),
    'max_rows' => (int) env('CONVERSION_MAX_ROWS', 20000),
    'max_columns' => (int) env('CONVERSION_MAX_COLUMNS', 100),
    'max_cell_bytes' => 32767,
    'max_uncompressed_bytes' => 32 * 1024 * 1024,
    'max_active' => (int) env('CONVERSION_MAX_ACTIVE', 5),
    'retention_hours' => (int) env('CONVERSION_RETENTION_HOURS', 24),
    'max_pages' => 20,
    'engine_timeout' => 120,
    'local' => [
        'enabled' => (bool) env('CONVERSION_LOCAL_ENGINES', false),
        'python' => env('CONVERSION_PYTHON', base_path(PHP_OS_FAMILY === 'Windows' ? '.venv\\Scripts\\python.exe' : '.venv/bin/python')),
        'libreoffice' => env('CONVERSION_LIBREOFFICE', PHP_OS_FAMILY === 'Windows' ? 'C:\\Program Files\\LibreOffice\\program\\soffice.com' : '/usr/bin/libreoffice'),
        'tesseract' => env('CONVERSION_TESSERACT', PHP_OS_FAMILY === 'Windows' ? getenv('LOCALAPPDATA').'\\Programs\\Tesseract-OCR\\tesseract.exe' : '/usr/bin/tesseract'),
    ],
];
