<?php

declare(strict_types=1);

namespace App\Services\Payout;

use App\DTOs\Payout\PayoutStatementData;
use App\Enums\AuditAction;
use App\Enums\PayoutDocumentStatus;
use App\Enums\PayoutStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PayoutDocumentException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\PayoutDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds payout STATEMENTS: deterministic document data read off completed
 * payout/claim/tax records — a paper ledger only, NEVER a balance mutation.
 *
 * THE NON-MUTATION CONTRACT, OUT LOUD
 * -----------------------------------
 * Nothing in this service writes any money-bearing row other than the
 * statement itself. Payout faces, wallets, ledgers, transfers: untouched.
 * The statement service composes PAPER.
 *
 * DETERMINISM
 * -----------
 * generate(payout) derives the document key from the payout reference —
 * one payout, one statement, forever. Re-runs join the existing document
 * (Draft stays mutable while its basis may still settle; Generated+ is
 * frozen). The statement numbers derive from the same anchor — a replay
 * re-derives the same number, never minting a new one.
 *
 * THE SOURCES, AND THE PROOF
 * --------------------------
 *   gross  → payout.metadata.tax.gross when a tax lane exists (the gross
 *            is pre-fold), else the payout's own amount when no lane
 *            folds anything
 *   tax    → payout.metadata.tax.tax_amount, or '0.00'
 *   net    → the payout's CURRENT amount (what actually settled)
 *   refs   → claim lane reference and batch lane key when present
 *
 * The structural identity (gross = tax + net) is RE-PROVEN at composition
 * with bcmath: when source rows disagree, statement building stops and
 * PayoutDocumentException::moneyInconsistent names the broken equation —
 * the paper may never notarize a ledger lie.
 *
 * ELIGIBILITY
 * -----------
 * Completed payouts only. Anything else throws payoutNotSettled — the
 * statement is a completion document by definition.
 */
class PayoutStatementService
{
    /**
     * Compose (or idempotently re-serve) the statement for a completed
     * payout. Lifecycle stays Draft until issue() is called — the job and
     * consoles decide when paper ships.
     *
     * @return array{document: PayoutDocument, created: bool, data: PayoutStatementData}
     *
     * @throws PayoutDocumentException
     */
    public function generate(Payout $payout): array
    {
        if (DB::transactionLevel() > 0) {
            throw new PayoutDocumentException(
                'Payout statement generation owns its transaction boundary.',
                PayoutDocumentException::CODE_STATE_FORBIDS,
                ['transaction_level' => DB::transactionLevel()],
            );
        }

        return DB::transaction(function () use ($payout): array {
            /** @var Payout|null $locked */
            $locked = Payout::query()->lockForUpdate()->find((int) $payout->getKey());

            if (! $locked instanceof Payout) {
                throw PayoutDocumentException::moneyOperandMissing(
                    (string) $payout->reference_number,
                    'payout row',
                );
            }

            if ($locked->status !== PayoutStatus::Completed) {
                throw PayoutDocumentException::payoutNotSettled(
                    (string) $locked->reference_number,
                    $locked->status->value,
                );
            }

            // IDEMPOTENCY BY DOCUMENT KEY: existing statement re-serves.
            $key = PayoutStatementData::deriveDocumentKey((string) $locked->reference_number);

            $existing = PayoutDocument::query()->lockForUpdate()->where('document_key', $key)->first();

            if ($existing instanceof PayoutDocument) {
                return [
                    'document' => $existing,
                    'created' => false,
                    'data' => $this->dataFromDocument($existing),
                ];
            }

            $data = $this->compose($locked);

            $document = new PayoutDocument();
            $document->fill([
                'document_key' => $data->documentKey(),
                'statement_number' => $this->deriveStatementNumber($data->documentKey()),
                'payout_id' => (int) $locked->getKey(),
                'claimant_user_id' => $data->claimantUserId,
                'payout_reference' => $data->payoutReference,
                'claim_reference' => $data->claimReference,
                'batch_reference' => $data->batchReference,
                'gross_amount' => bcadd($data->grossAmount, '0.00', 2),
                'tax_amount' => bcadd($data->taxAmount, '0.00', 2),
                'net_amount' => bcadd($data->netAmount, '0.00', 2),
                'currency' => $data->currency->value,
                'completed_at' => $data->completedAt,
                'generated_at' => null,
                'metadata' => ['context' => $data->context],
            ]);
            $document->status = PayoutDocumentStatus::Draft;
            $document->save();

            $this->recordAudit($locked, sprintf(
                'Payout statement %s generated for payout [%s]: gross %s, tax %s, net %s %s.',
                $document->statement_number,
                $data->payoutReference,
                bcadd($data->grossAmount, '0.00', 2),
                bcadd($data->taxAmount, '0.00', 2),
                bcadd($data->netAmount, '0.00', 2),
                $data->currency->value,
            ), RiskLevel::Low);

            return ['document' => $document, 'created' => true, 'data' => $data];
        });
    }

    /**
     * Draft → Generated: freeze the body (number + key are already
     * deterministic at Draft, so the freeze is purely the lifecycle step).
     */
    public function finalize(PayoutDocument $document): PayoutDocument
    {
        return $this->transition($document, PayoutDocumentStatus::Generated, 'statement finalized (body frozen)');
    }

    /**
     * Generated → Issued: the claimant may now hold the paper. Issued
     * statements are fact — nothing issued is ever edited again.
     */
    public function issue(PayoutDocument $document): PayoutDocument
    {
        $locked = $this->transition($document, PayoutDocumentStatus::Issued, 'statement issued to claimant');

        $locked->issued_at = Carbon::now();
        $locked->save();

        return $locked;
    }

    /**
     * Pre-issuance withdrawal (a composition error discovered early).
     */
    public function cancel(PayoutDocument $document, string $reason): PayoutDocument
    {
        $locked = $this->transition($document, PayoutDocumentStatus::Cancelled, sprintf('cancelled: %s', $reason));

        $locked->cancelled_at = Carbon::now();
        $locked->save();

        return $locked;
    }

    /**
     * Issued → Archived: moves the paper to long-term retention.
     */
    public function archive(PayoutDocument $document): PayoutDocument
    {
        $locked = $this->transition($document, PayoutDocumentStatus::Archived, 'retention window closed online');

        $locked->archived_at = Carbon::now();
        $locked->save();

        return $locked;
    }

    /**
     * Compose the statement DATA from the source rows, proving the
     * identity equation before anything is asked to trust it.
     *
     * @throws PayoutDocumentException
     */
    private function compose(Payout $payout): PayoutStatementData
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];

        $tax = is_array($metadata['tax'] ?? null) ? $metadata['tax'] : null;
        $claim = is_array($metadata['claim'] ?? null) ? $metadata['claim'] : null;
        $batch = is_array($metadata['batch'] ?? null) ? $metadata['batch'] : null;

        // MONEY SOURCES. Net is what settled — the payout's face TODAY.
        $net = (string) $payout->amount;

        if ($net === '' || preg_match('/^\d+(\.\d{1,2})?$/', $net) !== 1) {
            throw PayoutDocumentException::moneyOperandMissing(
                (string) $payout->reference_number,
                'net_amount',
            );
        }

        if (is_array($tax) && in_array($tax['status'] ?? null, ['applied', 'waived'], true)) {
            // Tax lane stamped: gross is pre-fold, tax is what was
            // withheld (waived keeps an audited zero).
            $gross = (string) ($tax['gross'] ?? '');
            $taxAmount = (string) ($tax['tax_amount'] ?? '0.00');

            if ($gross === '' || preg_match('/^\d+(\.\d{1,2})?$/', $gross) !== 1) {
                throw PayoutDocumentException::moneyOperandMissing(
                    (string) $payout->reference_number,
                    'gross_amount',
                );
            }
        } else {
            $gross = $net;
            $taxAmount = '0.00';
        }

        $claimReference = is_array($claim) ? ($claim['claim_key'] ?? $claim['reference'] ?? null) : null;
        $batchReference = is_array($batch) ? ($batch['batch_key'] ?? null) : null;

        $data = new PayoutStatementData(
            payoutReference: (string) $payout->reference_number,
            claimReference: is_string($claimReference) && $claimReference !== '' ? $claimReference : null,
            batchReference: is_string($batchReference) && $batchReference !== '' ? $batchReference : null,
            grossAmount: $gross,
            taxAmount: bcadd($taxAmount, '0.00', 2),
            netAmount: $net,
            currency: $payout->currency,
            claimantUserId: (int) $payout->user_id,
            completedAt: $payout->processed_at instanceof Carbon
                ? $payout->processed_at->toIso8601String()
                : Carbon::now()->toIso8601String(),
            context: ['source' => 'payout-statement-service'],
        );

        // THE PROOF. The statement's own identity equation must hold before
        // paper may quote it.
        if (! $data->moneyIsConsistent()) {
            throw PayoutDocumentException::moneyInconsistent(
                (string) $payout->reference_number,
                bcadd($gross, '0.00', 2),
                bcadd($taxAmount, '0.00', 2),
                $net,
            );
        }

        return $data;
    }

    /**
     * The document number derives from the key (replays re-derive the same).
     */
    public function deriveStatementNumber(string $documentKey): string
    {
        return 'PST-'.strtoupper(substr($documentKey, 0, 16));
    }

    /**
     * The statement for a payout, without creating it.
     */
    public function documentFor(Payout $payout): ?PayoutDocument
    {
        return PayoutDocument::query()
            ->where('document_key', PayoutStatementData::deriveDocumentKey((string) $payout->reference_number))
            ->first();
    }

    /**
     * Read-side projection of a persisted document back into statement data.
     */
    private function dataFromDocument(PayoutDocument $document): PayoutStatementData
    {
        return new PayoutStatementData(
            payoutReference: (string) $document->payout_reference,
            claimReference: $document->claim_reference,
            batchReference: $document->batch_reference,
            grossAmount: (string) $document->gross_amount,
            taxAmount: (string) $document->tax_amount,
            netAmount: (string) $document->net_amount,
            currency: $document->currency,
            claimantUserId: (int) $document->claimant_user_id,
            completedAt: $document->completed_at->toIso8601String(),
            context: ['source' => 'payout-statement-service', 'respot' => true],
        );
    }

    /**
     * One lawful lifecycle step for a document.
     */
    private function transition(PayoutDocument $document, PayoutDocumentStatus $target, string $note): PayoutDocument
    {
        return DB::transaction(function () use ($document, $target, $note): PayoutDocument {
            /** @var PayoutDocument|null $locked */
            $locked = PayoutDocument::query()->lockForUpdate()->find((int) $document->getKey());

            if (! $locked instanceof PayoutDocument) {
                throw PayoutDocumentException::stateForbids(
                    $document->document_key,
                    'missing',
                    $target->value,
                );
            }

            $current = $locked->status;

            if (! $current->canTransitionTo($target)) {
                throw PayoutDocumentException::stateForbids(
                    $locked->document_key,
                    $current->value,
                    $target->value,
                );
            }

            $locked->status = $target;

            if ($target === PayoutDocumentStatus::Generated) {
                $locked->generated_at = Carbon::now();
            }

            $locked->save();

            $this->recordAudit(null, sprintf(
                'Payout statement %s %s → %s (%s).',
                $locked->statement_number,
                $current->value,
                $target->value,
                $note,
            ), RiskLevel::Low, $locked);

            return $locked;
        });
    }

    private function recordAudit(?Payout $payout, string $description, RiskLevel $riskLevel, ?PayoutDocument $document = null): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => $riskLevel,
            'auditable_type' => $document instanceof PayoutDocument ? PayoutDocument::class : Payout::class,
            'auditable_id' => $document instanceof PayoutDocument ? (int) $document->getKey() : (int) ($payout?->getKey() ?? 0),
            'description' => $description,
            'metadata' => [
                'payout_id' => $document instanceof PayoutDocument ? (int) $document->payout_id : (int) ($payout?->getKey() ?? 0),
                'statement_number' => $document instanceof PayoutDocument ? (string) $document->statement_number : null,
                'lane' => 'payout_statement',
            ],
        ]);

        $log->save();
    }
}
