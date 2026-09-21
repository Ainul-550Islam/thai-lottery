@extends('layouts.app')

@section('title', 'Lottery Draw Schedule & Results — Thai Lottery')

@section('content')
<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Thai Lottery Draws & Results</h1>
            <p class="text-slate-400 text-sm mt-1">Official draw schedule, countdowns, and published winning numbers.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('player.bet') }}" class="px-4 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow transition text-sm">
                Place Bet Now
            </a>
        </div>
    </div>

    <!-- Draws Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        @forelse($draws as $draw)
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl flex flex-col justify-between hover:border-slate-700 transition">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <span class="font-mono font-bold text-white text-base">{{ $draw->draw_number }}</span>
                        @php
                            $status = $draw->status->value ?? (string) $draw->status;
                        @endphp
                        @if($status === 'open')
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/30 uppercase animate-pulse">OPEN</span>
                        @elseif($status === 'completed' || $status === 'result_published')
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-slate-800 text-slate-300 border border-slate-700 uppercase">SETTLED</span>
                        @else
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/30 uppercase">{{ $status }}</span>
                        @endif
                    </div>

                    <div class="text-xs text-slate-400 space-y-1.5 mb-6">
                        <div class="flex justify-between">
                            <span>Scheduled:</span>
                            <span class="text-slate-200 font-mono">{{ $draw->scheduled_at ? $draw->scheduled_at->format('M d, Y H:i') : 'N/A' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Closes:</span>
                            <span class="text-slate-200 font-mono">{{ $draw->betting_closes_at ? $draw->betting_closes_at->format('M d, Y H:i') : 'N/A' }}</span>
                        </div>
                    </div>

                    <!-- Winning Numbers if published -->
                    @if($draw->result)
                        <div class="bg-slate-950 border border-slate-800/80 rounded-2xl p-4 mb-4 text-center">
                            <span class="text-[10px] text-slate-400 uppercase font-semibold block mb-1">First Prize (6 Digits)</span>
                            <div class="text-2xl font-black font-mono text-amber-400 tracking-wider">
                                {{ $draw->result->first_prize }}
                            </div>
                            <div class="grid grid-cols-2 gap-2 mt-3 pt-3 border-t border-slate-800 text-xs">
                                <div>
                                    <span class="text-[10px] text-slate-500 block">3D Top</span>
                                    <span class="font-bold font-mono text-emerald-400">{{ $draw->result->three_digits_top }}</span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-slate-500 block">2D Bottom</span>
                                    <span class="font-bold font-mono text-emerald-400">{{ $draw->result->two_digits_bottom }}</span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <a href="{{ route('player.draws.detail', $draw->id) }}" class="block w-full py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold text-xs rounded-xl text-center transition">
                    View Draw Breakdown &rarr;
                </a>
            </div>
        @empty
            <div class="col-span-full py-12 text-center text-slate-500">
                No lottery draws available.
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    @if(method_exists($draws, 'links'))
        <div class="pt-4">
            {{ $draws->links() }}
        </div>
    @endif
</div>
@endsection
