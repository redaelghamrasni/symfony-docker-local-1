<?php

namespace App\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Last line of defence before anything is written: strips personal data and
 * secrets from log context.
 *
 * This site handles Canadian customer data (PIPEDA, Québec Law 25) and Stripe
 * payloads, and logs are shipped to a collector and retained. The rule applied
 * here is "redact by key name", which is blunt on purpose: it catches a field
 * that someone adds later without thinking about it, which is exactly when
 * personal data leaks into logs.
 *
 * Emails are partially masked rather than removed, because "is this the same
 * customer as the previous event?" is a question worth being able to answer.
 *
 * This is not a reason to log personal data and rely on the scrubber — prefer
 * ids in context in the first place.
 */
#[AsMonologProcessor(priority: -100)] // run last, after context is assembled
final class RedactionProcessor
{
    /** Values replaced outright. */
    private const SECRET_KEYS = [
        'password', 'plainpassword', 'currentpassword', 'newpassword',
        'token', 'jwt', 'bearer', 'authorization', 'apikey', 'api_key',
        'secret', 'client_secret', 'clientsecret', 'stripe_secret',
        'card', 'cardnumber', 'card_number', 'cvc', 'cvv', 'iban',
    ];

    /** Values masked but kept recognisable. */
    private const EMAIL_KEYS = ['email', 'customeremail', 'customer_email', 'payeremail', 'payer_email'];

    /** Free-text address fields: dropped, the order id identifies the address. */
    private const ADDRESS_KEYS = [
        'street', 'street1', 'shippingstreet', 'billingstreet',
        'address', 'shipping_address', 'billing_address', 'phone', 'customerphone',
    ];

    private const REDACTED = '[redacted]';

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($record->context === []) {
            return $record;
        }

        return $record->with(context: $this->scrub($record->context));
    }

    private function scrub(array $data, int $depth = 0): array
    {
        // Guard against a deeply nested or self-referential payload (a Stripe
        // object graph, for instance) turning logging into a hot loop.
        if ($depth > 6) {
            return ['[truncated]'];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            $normalised = is_string($key) ? strtolower(str_replace('-', '_', $key)) : '';

            if (in_array($normalised, self::SECRET_KEYS, true)) {
                $clean[$key] = self::REDACTED;
                continue;
            }

            if (in_array($normalised, self::EMAIL_KEYS, true)) {
                $clean[$key] = is_string($value) ? $this->maskEmail($value) : self::REDACTED;
                continue;
            }

            if (in_array($normalised, self::ADDRESS_KEYS, true)) {
                $clean[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->scrub($value, $depth + 1);
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /** a.customer@example.com -> a***@example.com */
    private function maskEmail(string $email): string
    {
        $at = strpos($email, '@');

        if ($at === false || $at === 0) {
            return self::REDACTED;
        }

        return substr($email, 0, 1) . '***' . substr($email, $at);
    }
}
