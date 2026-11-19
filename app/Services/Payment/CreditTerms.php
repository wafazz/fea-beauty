<?php
declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Credit terms (wholesale accounts): pay later, by the due date. Not in the general checkout list (available() is false),
 * so subscriptions and other places cannot use it. Checkout adds it for approved accounts, and checks the credit limit.
 */
final class CreditTerms implements PaymentMethod
{
    public function code(): string { return 'credit_terms'; }

    public function label(): string { return 'Credit terms (pay by due date)'; }

    public function instructions(): string
    {
        return 'Pay by the due date shown on your order. Your business account limit applies to the orders you have not paid yet.';
    }

    public function available(): bool { return false; }
}
