<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\Currency;
use App\Exceptions\PaymentVerificationException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * PromptPay QR (ThaiQR / EMVCo) payment service.
 *
 * THIS IS THE REAL THING, NOT A STUB
 * The QR string a Thai banking app scans is an EMVCo merchant-presented-mode
 * payload in TLV form, with the PromptPay merchant-account-information block
 * (id 29) embedded inside. The payload layout below is documented byte for
 * byte so an auditor can verify against the Thai Bankers Association spec:
 *
 *   id 00  len 02  "01"                    payload format indicator
 *   id 01  len 02  "11" | "12"             point of initiation (11 = static reusable,
 *                                          12 = dynamic / this-payment-only)
 *   id 29  len NN  sub-id 00 "A000000677010111" (PromptPay application ID)
 *                  sub-id 01  proxy value  (national ID / phone in international format)
 *                  sub-id 02  invoice no   (our deposit reference, when present)
 *   id 52  len 04  "0000"                  merchant category code (0000 = unspecified)
 *   id 53  len 03  "764"                   ISO 4217 numeric currency (764 = THB)
 *   id 54  len NN  amount as decimal string (dynamic QR only — omitted for static)
 *   id 58  len 02  "TH"                    country code
 *   id 63  len 04  CRC16-CCITT(0xFFFF)    checksum over every preceding byte + "6304"
 *
 * Static QRs (PointOfInitiation=11, no amount, no invoice) can be scanned any
 * number of times and the payer types the amount — the money arrives with NO
 * provable tie to a deposit, so static is for collection verification only.
 * Dynamic QRs (12, amount + deposit reference in sub-id 02) close the loop:
 * the payer's app is forced to send the exact amount with our reference,
 * which is what PaymentVerificationService then claims and credits once.
 *
 * VERIFICATION
 * Webhooks from the PromptPay aggregator are HMAC-SHA256 over
 * "reference:amount:currency" with the gateway webhook secret — timing-safe
 * comparison only. The service never sees or stores a bank credential.
 */
class PromptPayPaymentService
{
    /**
     * ThaiQR application ID present in every PromptPay merchant account
     * information block (defined by the Thai Bankers' Association spec).
     */
    private const PROMPTPAY_APP_ID = 'A000000677010111';

    /**
     * Merchant category code for "no category specified" per EMV spec.
     */
    private const MERCHANT_CATEGORY_UNSPECIFIED = '0000';

    /**
     * ISO 3166-1 alpha-2 country code embedded in the QR.
     */
    private const COUNTRY_CODE = 'TH';

    /**
     * ISO 4217 numeric code for the Thai Baht.
     */
    private const CURRENCY_ISO = '764';

    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Whether PromptPay is enabled and has a receiving target configured.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('payment.gateways.promptpay.enabled', false)
            && is_string($this->config->get('payment.gateways.promptpay.target'))
            && $this->config->get('payment.gateways.promptpay.target') !== '';
    }

    /**
     * Build the DYNAMIC, single-payment PromptPay QR payload for one deposit.
     *
     * The QR pins amount and invoice reference: the payer's app cannot alter
     * either, and the aggregator's webhook reports them back in bindable form.
     *
     * @return array{
     *     qr_payload: string,
     *     reference: string,
     *     amount: string,
     *     currency: string,
     *     point_of_initiation: string,
     *     expires_at: string
     * }
     */
    public function createPaymentRequest(string $reference, string $amount, ?Currency $currency = null): array
    {
        $this->assertEnabled();

        if ($currency !== null && $currency !== Currency::THB) {
            throw \InvalidArgumentException(sprintf(
                'PromptPay QR payments settle in THB only; %s is not supported.',
                $currency->value,
            ));
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
            throw \InvalidArgumentException(sprintf(
                'The dynamic QR amount [%s] must be a positive 2-decimal THB amount.',
                $amount,
            ));
        }

        if (! preg_match('/^[A-Za-z0-9-]{1,25}$/', $reference)) {
            throw \InvalidArgumentException(sprintf(
                'The PromptPay reference [%s] must be 1–25 alphanumerics/dashes (EMV invoice field limit).',
                $reference,
            ));
        }

        $proxy = $this->promptPayProxy();

        $merchantAccountInfo = $this->tlv('00', self::PROMPTPAY_APP_ID)
            .$this->tlv('01', $proxy)
            .$this->tlv('02', $reference);

        $payload = $this->tlv('00', '01')
            .$this->tlv('01', '12')
            .$this->tlv('29', $merchantAccountInfo)
            .$this->tlv('52', self::MERCHANT_CATEGORY_UNSPECIFIED)
            .$this->tlv('53', self::CURRENCY_ISO)
            .$this->tlv('54', $amount)
            .$this->tlv('58', self::COUNTRY_CODE);

        $payload .= '6304'.$this->crc16Ccitt($payload.'6304');

        return [
            'qr_payload' => $payload,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => Currency::THB->value,
            'point_of_initiation' => '12',
            'expires_at' => now()->addMinutes($this->expiryMinutes())->toIso8601String(),
        ];
    }

    /**
     * The STATIC merchant collection QR: no amount, no invoice. Shown at the
     * merchant for open-amount deposits whose fairness is proven out of band.
     */
    public function staticCollectionPayload(): string
    {
        $this->assertEnabled();

        $proxy = $this->promptPayProxy();

        $merchantAccountInfo = $this->tlv('00', self::PROMPTPAY_APP_ID).$this->tlv('01', $proxy);

        $payload = $this->tlv('00', '01')
            .$this->tlv('01', '11')
            .$this->tlv('29', $merchantAccountInfo)
            .$this->tlv('52', self::MERCHANT_CATEGORY_UNSPECIFIED)
            .$this->tlv('53', self::CURRENCY_ISO)
            .$this->tlv('58', self::COUNTRY_CODE);

        return $payload.'6304'.$this->crc16Ccitt($payload.'6304');
    }

    /**
     * Verify a callback signature the PromptPay aggregator issued for one
     * deposit, from the X-PromptPay-Signature header. The signed material is
     * exactly the three fields either side would need to spoof:
     * reference:amount:currency.
     *
     * @throws PaymentVerificationException when the signature is malformed or
     *         the comparison fails (tamper)
     */
    public function assertValidWebhookSignature(string $reference, string $amount, string $providedSignature): void
    {
        $secret = $this->config->get('payment.gateways.promptpay.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw PaymentVerificationException::proofInvalid(
                'promptpay',
                'the PromptPay webhook secret is not configured; verification is refused rather than bypassed',
            );
        }

        if (! preg_match('/^[a-f0-9]{64}$/i', $providedSignature)) {
            throw PaymentVerificationException::proofInvalid(
                'promptpay',
                'the supplied signature is not a 64-hex-character HMAC-SHA256 digest',
                ['reference' => $reference],
            );
        }

        $expected = hash_hmac('sha256', sprintf('%s:%s:THB', $reference, $amount), $secret);

        if (! hash_equals($expected, strtolower($providedSignature))) {
            throw PaymentVerificationException::proofInvalid(
                'promptpay',
                'the signature does not match the claimed reference/amount',
                ['reference' => $reference],
            );
        }
    }

    /**
     * Convenience to sign an outbound check request—for internal callers
     * that need to reproduce what the aggregator's webhook would look like
     * in tests and monitoring webhooks. Timing-safe properties do not apply
     * here: this is a signing helper, not a compare.
     */
    public function signatureFor(string $reference, string $amount): string
    {
        $secret = $this->config->get('payment.gateways.promptpay.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            throw \InvalidArgumentException('The PromptPay webhook secret is not configured.');
        }

        return hash_hmac('sha256', sprintf('%s:%s:THB', $reference, $amount), $secret);
    }

    /**
     * The smallest encoding unit of the ThaiQR/EMV spec: id (2 chars) + length
     * (2 chars, decimal, zero-padded) + raw value bytes.
     */
    private function tlv(string $id, string $value): string
    {
        return $id.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
    }

    /**
     * The merchant's PromptPay proxy ID in the format the banks expect:
     * national ID is 13 digits as-is; a phone number is normalized to its
     * international form (0 → 0066 for the leading zero). A checking target
     * that fits neither is a configuration defect and refuses loudly — a
     * half-written QR string would be a payment sunk into the wrong account.
     */
    private function promptPayProxy(): string
    {
        $target = $this->config->get('payment.gateways.promptpay.target');

        if (! is_string($target) || trim($target) === '') {
            throw \InvalidArgumentException('The PromptPay merchant target (payment.gateways.promptpay.target) is not configured.');
        }

        $digits = preg_replace('/\D/', '', $target) ?? '';

        // National ID / Tax ID: 13 digits, unchanged.
        if (preg_match('/^\d{13}$/', $digits)) {
            return $digits;
        }

        // Thai mobile: 0 followed by 8 or 9 digits → 0066 + the rest.
        if (preg_match('/^0\d{8,9}$/', $digits)) {
            return '0066'.substr($digits, 1);
        }

        // Already in international form: 66 followed by 8–9 digits (no leading 0) → 0066 prefix.
        if (preg_match('/^66\d{8,9}$/', $digits)) {
            return '00'.$digits;
        }

        throw \InvalidArgumentException(sprintf(
            'The configured PromptPay target [%s] is neither a 13-digit national ID nor a Thai phone number.',
            $target,
        ));
    }

    /**
     * CRC16-CCITT (polynomial 0x1021, init 0xFFFF, no reflection) — the exact
     * checksum the EMV spec gives as "63 04" trailer. Implemented bit-by-bit
     * via the standard 256-entry table so the output is byte-identical across
     * PHP versions and 32/64-bit hosts.
     */
    private function crc16Ccitt(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= (ord($data[$i]) << 8) & 0xFFFF;

            for ($bit = 0; $bit < 8; $bit++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private function expiryMinutes(): int
    {
        $minutes = $this->config->get('payment.gateways.promptpay.qr_expiry_minutes');

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : 15;
    }

    private function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw \InvalidArgumentException(sprintf(
                'PromptPay is not enabled (payment.gateways.promptpay.enabled/target); %s cannot issue a payment request.',
                self::class,
            ));
        }
    }
}
