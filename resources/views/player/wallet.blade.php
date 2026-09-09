@extends('layouts.app')

@section('title', 'Player Wallet & Ledger — Thai Lottery')

@section('content')
<div class="space-y-8">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Wallet & Accounting Ledger</h1>
        <p class="text-slate-400 text-sm mt-1">Real-time balance, double-entry transaction history, and funds disbursement.</p>
    </div>

    <!-- Balance Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl">
            <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">Available Balance</span>
            <div class="text-3xl font-extrabold font-mono text-amber-400 mt-2">
                ฿{{ number_format((float) ($wallet->balance ?? 0), 2) }}
            </div>
            <span class="text-[11px] text-slate-500 mt-1 block">Unrestricted funds ready for betting</span>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl">
            <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">Total Deposited</span>
            <div class="text-3xl font-extrabold font-mono text-white mt-2">
                ฿{{ number_format((float) ($totalDeposited ?? 0), 2) }}
            </div>
            <span class="text-[11px] text-slate-500 mt-1 block">Lifetime confirmed deposits</span>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl">
            <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">Total Prizes Won</span>
            <div class="text-3xl font-extrabold font-mono text-emerald-400 mt-2">
                ฿{{ number_format((float) ($totalPrizesWon ?? 0), 2) }}
            </div>
            <span class="text-[11px] text-slate-500 mt-1 block">Lifetime settled payouts</span>
        </div>
    </div>

    <!-- Quick Action Buttons -->
    <div class="flex items-center gap-4">
        <a href="{{ route('player.deposit') }}" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow transition text-sm">
            Deposit Funds
        </a>
        <a href="{{ route('player.withdraw') }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold rounded-xl border border-slate-700 transition text-sm">
            Withdraw Funds
        </a>
    </div>

    <!-- Transactions Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <h2 class="text-lg font-bold text-white mb-6">Double-Entry Transaction Journal</h2>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">Transaction Ref</th>
                        <th class="py-3 px-4">Type</th>
                        <th class="py-3 px-4">Description</th>
                        <th class="py-3 px-4 text-right">Amount</th>
                        <th class="py-3 px-4 text-right">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($transactions as $tx)
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $tx->reference_number ?? 'TX-'.$tx->id }}
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2 py-0.5 rounded text-xs font-bold uppercase {{ $tx->type === 'deposit' || $tx->type === 'payout' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400' }}">
                                    {{ $tx->type->value ?? $tx->type }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-xs text-slate-400">
                                {{ $tx->description ?? 'Financial entry' }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold {{ $tx->type === 'deposit' || $tx->type === 'payout' ? 'text-emerald-400' : 'text-slate-200' }}">
                                {{ $tx->type === 'deposit' || $tx->type === 'payout' ? '+' : '-' }}฿{{ number_format((float) $tx->amount, 2) }}
                            </td>
                            <td class="py-3.5 px-4 text-right text-xs text-slate-500 font-mono">
                                {{ $tx->created_at ? $tx->created_at->format('M d, Y H:i') : 'N/A' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-500 text-xs">
                                No financial transactions recorded yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($transactions, 'links'))
            <div class="pt-6">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
