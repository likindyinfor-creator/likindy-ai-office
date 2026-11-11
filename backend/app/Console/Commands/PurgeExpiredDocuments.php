<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentRetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeExpiredDocuments extends Command
{
    protected $signature = 'documents:purge-expired {--dry-run : Report eligible documents without deleting them}';

    protected $description = 'Permanently purge soft-deleted documents past their retention period';

    public function handle(DocumentRetentionService $retention): int
    {
        $cutoff = $retention->cutoff();
        $query = Document::onlyTrashed()->where('deleted_at', '<=', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} document(s) eligible for purge; no data was deleted.");

            return self::SUCCESS;
        }

        $purged = 0;
        $query->select('id')->orderBy('deleted_at')->chunkById(100, function ($documents) use (
            $retention,
            &$purged
        ): void {
            foreach ($documents as $document) {
                if ($retention->purge($document->getKey())) {
                    $purged++;
                }
            }
        });

        $this->info("Purged {$purged} expired document(s).");
        Log::info('Expired documents purged.', ['count' => $purged, 'cutoff' => $cutoff->toIso8601String()]);

        return self::SUCCESS;
    }
}
