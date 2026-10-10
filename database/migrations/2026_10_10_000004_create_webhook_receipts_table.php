<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->nullable()->constrained('institutions')->nullOnDelete();
            $table->string('event_id', 128)->nullable();
            $table->string('event_type', 64)->nullable();
            $table->string('invoice_number', 64)->nullable();
            $table->boolean('signature_valid')->nullable();
            $table->string('processing_status', 16)->default('received');
            $table->json('payload_redacted')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Every delivery gets its own audit row (including retries and
            // duplicates), so this is a plain index — not a unique key.
            // Idempotency is enforced at the application layer via the
            // (institution_id, event_id) lookup below plus the DB-level
            // unique key on payment_transactions (institution_id,
            // gateway_transaction_id) guarding financial rows.
            $table->index(['institution_id', 'event_id']);
            $table->index(['institution_id', 'processing_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
    }
};
