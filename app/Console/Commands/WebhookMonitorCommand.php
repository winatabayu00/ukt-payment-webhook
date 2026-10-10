<?php

namespace App\Console\Commands;

use App\Enums\WebhookProcessingStatus;
use App\Models\WebhookReceipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ops visibility for the webhook pipeline.
 *
 * Reports receipt counts per processing_status (optionally scoped to one
 * institution), recent failures with stable failure_reason values, pending
 * queue depth (jobs table), and failed_jobs entries. Read-only: never
 * mutates receipts, jobs, or invoices.
 */
class WebhookMonitorCommand extends Command
{
    protected $signature = 'webhook:monitor'
        .' {--institution= : filter to one institution code}'
        .' {--failures=10 : how many recent failures to list}';

    protected $description = 'Show webhook receipt summary, recent failures, and queue depth';

    public function handle(): int
    {
        $institutionCode = (string) ($this->option('institution') ?? '');
        $failuresLimit = max(0, (int) $this->option('failures'));

        $query = WebhookReceipt::query();

        if ($institutionCode !== '') {
            $institutionId = DB::table('institutions')->where('code', $institutionCode)->value('id');
            if ($institutionId === null) {
                $this->error("Unknown institution code: {$institutionCode}");

                return self::FAILURE;
            }
            $query->where('institution_id', $institutionId);
            $this->info("Scope: institution {$institutionCode}");
        }

        $counts = (clone $query)
            ->select('processing_status', DB::raw('COUNT(*) as total'))
            ->groupBy('processing_status')
            ->pluck('total', 'processing_status')
            ->all();

        // Ensure every known status shows, even at zero.
        $rows = [];
        foreach (WebhookProcessingStatus::cases() as $status) {
            $rows[] = [$status->value, (int) ($counts[$status->value] ?? 0)];
        }

        $this->info('Receipts by processing_status:');
        $this->table(['status', 'count'], $rows);

        $failureRows = (clone $query)
            ->whereIn('processing_status', [
                WebhookProcessingStatus::Rejected->value,
                WebhookProcessingStatus::Failed->value,
            ])
            ->orderByDesc('id')
            ->limit($failuresLimit)
            ->get(['id', 'institution_id', 'event_id', 'event_type', 'invoice_number', 'failure_reason', 'processed_at'])
            ->map(fn ($r) => [
                $r->id,
                $r->institution_id,
                $r->event_id,
                $r->event_type,
                $r->invoice_number,
                $r->failure_reason,
                $r->processed_at,
            ])
            ->all();

        $this->info("Recent failures (latest {$failuresLimit}):");
        if ($failureRows === []) {
            $this->line('  (none)');
        } else {
            $this->table(
                ['receipt', 'institution', 'event_id', 'event_type', 'invoice', 'failure_reason', 'processed_at'],
                $failureRows
            );
        }

        // Queue depth + failed jobs (database driver tables from the stock
        // jobs migration; guard with hasTable so sqlite/pgsql variants stay safe).
        $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        $this->info('Queue:');
        $this->table(
            ['metric', 'count'],
            [
                ['jobs pending', $pending],
                ['failed_jobs', $failedJobs],
            ]
        );

        if (Schema::hasTable('failed_jobs') && $failedJobs > 0) {
            $recent = DB::table('failed_jobs')
                ->orderByDesc('failed_at')
                ->limit(min($failuresLimit, 10))
                ->get(['id', 'connection', 'queue', 'failed_at'])
                ->map(fn ($r) => [$r->id, $r->connection, $r->queue, $r->failed_at])
                ->all();
            $this->info('Recent failed_jobs:');
            $this->table(['id', 'connection', 'queue', 'failed_at'], $recent);
        }

        $failedReceipts = (int) ($counts[WebhookProcessingStatus::Failed->value] ?? 0);
        if ($failedReceipts > 0 || $failedJobs > 0) {
            $this->warn("Attention: {$failedReceipts} failed receipt(s), {$failedJobs} failed job(s). Check logs (webhook.failed) + failed_jobs payloads.");
        } else {
            $this->info('OK: no failed receipts or jobs.');
        }

        return self::SUCCESS;
    }
}
