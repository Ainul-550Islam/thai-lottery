@php
    $catalogue = $this->getCatalogue();
    $prizes = $catalogue['prizes'];
    $claim = $catalogue['claim'];
    $ticket = $catalogue['ticket'];
    $calendar = $catalogue['draw_calendar'];
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <!-- Header -->
        <div class="p-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-bold tracking-tight text-gray-950 dark:text-white">
                Government Lottery Office — Official Prize Structure
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                Drawn by {{ $calendar['announced_by'] ?? 'the GLO' }} on the
                {{ collect($calendar['days_of_month'] ?? [1, 16])->join(' and ') }} of each month.
                Amounts and winner counts live in <code class="text-xs">config/glo.php</code>.
            </p>
        </div>

        <!-- Prize Schedule Table -->
        <div class="p-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Prize Schedule (per printed ticket)</h3>

            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 pr-4">Tier</th>
                            <th class="py-2 pr-4">Amount (THB)</th>
                            <th class="py-2 pr-4">Winners</th>
                            <th class="py-2 pr-4">Digits</th>
                            <th class="py-2 pr-4">Match</th>
                            <th class="py-2 pr-4">Withholding</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($prizes as $prize)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $prize['label'] }}</td>
                                <td class="py-2 pr-4 font-mono">{{ number_format((float) $prize['amount'], 2) }}</td>
                                <td class="py-2 pr-4">{{ $prize['winners'] }}</td>
                                <td class="py-2 pr-4">{{ $prize['digits'] }}</td>
                                <td class="py-2 pr-4 font-mono text-xs">{{ $prize['match_mode'] }}</td>
                                <td class="py-2 pr-4">
                                    @if($prize['tax_withheld'])
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400 border border-amber-200 dark:border-amber-800">Yes</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">No</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Claim & Ticket Rules -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="p-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Claim Rules</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Claim window</dt><dd class="font-mono">{{ $claim['window_years'] ?? 2 }} years</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Bank cash limit</dt><dd class="font-mono">{{ $claim['bank_cash_limit'] ?? '20000.00' }} THB</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Withholding tax</dt><dd class="font-mono">{{ ($claim['withholding_tax_rate'] ?? 0.005) * 100 }}%</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Requires original ticket</dt><dd>{{ !empty($claim['require_original_ticket']) ? 'Yes' : 'No' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Requires ID</dt><dd>{{ !empty($claim['require_id']) ? 'Yes' : 'No' }}</dd></div>
                </dl>
            </div>

            <div class="p-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Physical Ticket Rules</h3>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Digits</dt><dd class="font-mono">{{ $ticket['digits'] ?? 6 }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Sold in pairs</dt><dd>{{ !empty($ticket['sold_in_pairs']) ? 'Yes' : 'No' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Pair/set value</dt><dd class="font-mono">{{ $ticket['pair_set_value'] ?? '120.00' }} THB</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Venue</dt><dd>{{ $calendar['venue'] ?? 'GLO Headquarters, Bangkok' }}</dd></div>
                </dl>
            </div>
        </div>
    </div>
</x-filament-panels::page>
