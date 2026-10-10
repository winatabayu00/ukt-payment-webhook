<?php

use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\StudentInvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('institution.resolve')->group(function () {
    Route::post('/invoices', [InvoiceController::class, 'store']);
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('/invoices/{invoice}/transactions', [InvoiceController::class, 'transactions']);
    Route::get('/students/{studentNumber}/invoices', [StudentInvoiceController::class, 'index']);
});

Route::post('/webhooks/payments', [PaymentWebhookController::class, 'handle']);
