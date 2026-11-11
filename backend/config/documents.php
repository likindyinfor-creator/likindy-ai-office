<?php

return [
    'deleted_retention_days' => max(1, (int) env('DOCUMENT_DELETED_RETENTION_DAYS', 30)),
];
