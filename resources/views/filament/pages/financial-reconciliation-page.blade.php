<x-filament-panels::page>
    @php
        $report = $this->getReport();
    @endphp

    <div class="space-y-6">
        <!-- Status Header Card -->
        <div class="p-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                        Execution: {{ $report->executionId }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Executed at {{ $report->executedAt->toDateTimeString() }} | Initiated by {{ $report->initiatedBy ?? 'System' }}
                    </p>
                </div>
                <div>
                    @if($report->status->value === 'pass')
                        <span class="inline-flex items-center px-4 py-1.5 rounded-full text-sm font-semibold bg-green-50 text-green-700 dark:bg-green-950/50 dark:text-green-400 border border-green-200 dark:border-green-800">
                            PASS (100% Consistent)
                        </span>
                    @elseif($report->status->value === 'warning')
                        <span class="inline-flex items-center px-4 py-1.5 rounded-full text-sm font-semibold bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400 border border-amber-200 dark:border-amber-800">
                            WARNING ({{ $report->anomalyCount }} Anomalies)
                        </span>
                    @else
                        <span class="inline-flex items-center px-4 py-1.5 rounded-full text-sm font-semibold bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-400 border border-red-200 dark:border-red-800">
                            CRITICAL ({{ $report->criticalAnomalyCount }} Critical Anomalies)
                        </span>
                    @endif
                </div>
            </div>
        </div>

        <!-- Period Totals Grid -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Total Confirmed Deposits</span>
                <p class="text-2xl font-bold text-gray-950 dark:text-white mt-1">{{ $report->totalDeposits }} THB</p>
            </div>
            <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Total Bet Purchases</span>
                <p class="text-2xl font-bold text-gray-950 dark:text-white mt-1">{{ $report->totalBetPurchases }} THB</p>
            </div>
            <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Total Prize Payouts</span>
                <p class="text-2xl font-bold text-gray-950 dark:text-white mt-1">{{ $report->totalPrizePayouts }} THB</p>
            </div>
            <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Total Settled Withdrawals</span>
                <p class="text-2xl font-bold text-gray-950 dark:text-white mt-1">{{ $report->totalWithdrawals }} THB</p>
            </div>
        </div>

        <!-- Double-Entry Ledger Summary -->
        <div class="p-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white mb-4">Double-Entry Ledger Integrity</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <span class="text-xs text-gray-500">Total Ledger Debits</span>
                    <p class="text-lg font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $report->totalLedgerDebits }}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">Total Ledger Credits</span>
                    <p class="text-lg font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $report->totalLedgerCredits }}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">Difference (Must be 0.00)</span>
                    <p class="text-lg font-mono font-semibold @if($report->ledgerDifference === '0.00') text-green-600 dark:text-green-400 @else text-red-600 dark:text-red-400 @endif">
                        {{ $report->ledgerDifference }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Discrepancies Table -->
        <div class="p-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white mb-4">
                Discrepancy Audit Log ({{ count($report->discrepancies) }})
            </h3>
            @if(empty($report->discrepancies))
                <div class="text-center py-8 text-sm text-gray-500 dark:text-gray-400">
                    No discrepancies detected. All financial transactions, wallet balances, and ledger postings are in balance.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-left text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900/50">
                            <tr>
                                <th class="px-4 py-2 font-medium text-gray-500">Severity</th>
                                <th class="px-4 py-2 font-medium text-gray-500">Category</th>
                                <th class="px-4 py-2 font-medium text-gray-500">Entity</th>
                                <th class="px-4 py-2 font-medium text-gray-500">Reference</th>
                                <th class="px-4 py-2 font-medium text-gray-500">Expected</th>
                                <th class="px-4 py-2 font-medium text-gray-500">Actual</th>
                                <th class="px-4 py-2 font-medium text-gray-500">Description</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 font-mono text-xs">
                            @foreach($report->discrepancies as $d)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-4 py-2 font-bold @if($d->severity->value === 'critical') text-red-600 @elseif($d->severity->value === 'high') text-amber-600 @else text-blue-600 @endif">
                                        {{ strtoupper($d->severity->value) }}
                                    </td>
                                    <td class="px-4 py-2 font-sans">{{ $d->category->label() }}</td>
                                    <td class="px-4 py-2">{{ class_basename($d->entityType) }} #{{ $d->entityId }}</td>
                                    <td class="px-4 py-2">{{ $d->referenceNumber ?? '-' }}</td>
                                    <td class="px-4 py-2">{{ $d->expectedAmount ?? '-' }}</td>
                                    <td class="px-4 py-2">{{ $d->actualAmount ?? '-' }}</td>
                                    <td class="px-4 py-2 font-sans text-gray-700 dark:text-gray-300">{{ $d->description }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
