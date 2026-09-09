@extends('layouts.app')

@section('title', 'My Wagers & Bet History — Thai Lottery')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">My Lottery Bets</h1>
        <p class="text-slate-400 text-sm mt-1">Audit log of all submitted lottery tickets, item selections, and settlement statuses.</p>
    </div>

    <!-- Bets Table Card -->
    <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">Ticket Number</th>
                        <th class="py-3 px-4">Draw</th>
                        <th class="py-3 px-4">Markets & Numbers</th>
                        <th class="py-3 px-4 text-right">Total Stake</th>
                        <th class="py-3 px-4 text-right">Potential Payout</th>
                        <th class="py-3 px-4 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($bets as $bet)
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $bet->ticket->ticket_number ?? 'TKT-'.$bet->id }}
                            </td>
                            <td class="py-3.5 px-4 font-mono text-xs text-slate-400">
                                {{ $bet->draw->draw_number ?? 'DRAW-'.$bet->draw_id }}
                            </td>
                            <td class="py-3.5 px-4">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach($bet->items ?? [] as $item)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-bold bg-slate-800 text-amber-400 border border-slate-700">
                                            {{ $item->number }} <span class="text-[10px] text-slate-400 ml-1">({{ $item->market->value ?? $item->market }})</span>
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-slate-200">
                                ฿{{ number_format((float) ($bet->total_stake ?? 0), 2) }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold text-emerald-400">
                                ฿{{ number_format((float) ($bet->potential_payout ?? 0), 2) }}
                            </td>
                            <td class="py-3.5 px-4 text-center">
                                @php
                                    $statusVal = $bet->status->value ?? (string) $bet->status;
                                @endphp
                                @if($statusVal === 'won')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">WON</span>
                                @elseif($statusVal === 'lost')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-500/10 text-rose-400 border border-rose-500/30">LOST</span>
                                @elseif($statusVal === 'cancelled')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-slate-500/10 text-slate-400 border border-slate-500/30">CANCELLED</span>
                                @else
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30">PENDING</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-slate-500 text-xs">
                                No wagers placed yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($bets, 'links'))
            <div class="pt-6">
                {{ $bets->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
