<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions')->cascadeOnDelete();
            $table->string('student_number', 64);
            $table->string('semester', 16);
            $table->string('invoice_number', 64);
            $table->decimal('amount', 15, 2);
            $table->timestamp('expires_at');
            $table->string('status', 16)->default('unpaid');
            $table->timestamps();

            $table->unique(['institution_id', 'invoice_number']);
            $table->index(['institution_id', 'student_number']);
            $table->index(['institution_id', 'semester']);
            $table->index(['institution_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
