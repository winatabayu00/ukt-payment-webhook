<?php

namespace App\Console\Commands;

use App\Enums\WebhookProcessingStatus;
use App\Models\WebhookReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Retention cleanup for the webhook audit trail.
 *
 * Deletes old terminal receipts (processed/duplicate/ignored/rejected)
 * older than the configured window. Failed receipts are kept by default
 * (they may need investigation) unless --include-failed is passed.
 * Never touches money tables (invoices, payment_transactions).
 */
class WebhookPruneCommand extends Command
{
    protected $signature = 'webhook:prune'
        .' {--days= : retention window in days (default config api.webhook_receipt_retention_days)}'
        .' {--include-failed : also prune failed receipts}'
        .' {--dry-run : report only, delete nothing}';

    protected $description = 'Delete old terminal webhook receipts (retention cleanup)';

    public function handle(): int
    {
        $daysOption = $this->option('days');
        $days = $daysOption === null || $daysOption === ''
            ? (int) config('api.webhook_receipt_retention_days', 90)
            : max(1, (int) $daysOption);

        $cutoff = CarbonImmutable::now()->subDays($days);

        $statuses = [
            WebhookProcessingStatus::Processed->value,
            WebhookProcessingStatus::Duplicate->value,
            WebhookProcessingStatus::Ignored->value,
            WebhookProcessingStatus::Rejected->value,
        ];

        if ($this->option('include-failed')) {
            $statuses[] = WebhookProcessingStatus::Failed->value;
        }

        $query = WebhookReceipt::whereIn('processing_status', $statuses)
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$count} receipt(s) older than {$cutoff->toDateTimeString()} would be deleted (statuses: ".implode(',', $statuses).').');

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Pruned {$deleted} webhook receipt(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
