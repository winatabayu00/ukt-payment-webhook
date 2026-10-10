<?php

namespace App\Enums;

enum PaymentEventType: string
{
    case Success = 'payment.success';
    case Expired = 'payment.expired';
}
