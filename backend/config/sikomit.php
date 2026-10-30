<?php

return [
    'documents' => [
        // Disabled until an authorized BPS official approves the combined-document procedure.
        'combined_mode_enabled' => (bool) env('SIKOMIT_COMBINED_DOCUMENTS', false),
        'max_template_bytes' => (int) env('SIKOMIT_MAX_TEMPLATE_BYTES', 15 * 1024 * 1024),
        'max_evidence_bytes' => (int) env('SIKOMIT_MAX_EVIDENCE_BYTES', 10 * 1024 * 1024),
    ],
];
