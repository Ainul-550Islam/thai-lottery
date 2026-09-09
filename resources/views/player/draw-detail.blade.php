@extends('layouts.app')

@section('title', 'Draw Details #' . ($draw->draw_number ?? $draw->id) . ' — Thai Lottery')

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <!-- Back Link & Header -->
    <div class="flex items-center justify-between">
        <a href="{{ route('player.draws') }}" class="text-xs text-emerald-400 font-bold hover:underline flex items-center gap-1">
            &larr; Back to Draws
        </a>
        <span class="font-mono text-xs px-3 py-1 bg-slate-800 text-slate-300 rounded-full">
            Draw #{{ $draw->draw_number ?? $draw->id }}
        </span>
    </div>

    <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-6 mb-6">
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-white font-mono">{{ $draw->draw_number }}</h1>
                <p class="text-xs text-slate-400 mt-1">
                    Scheduled for: <span class="text-slate-200 font-mono">{{ $draw->scheduled_at ? $draw->scheduled_at->format('l, F j, Y — H:i T') : 'N/A' }}</span>
                </p>
            </div>
            <div>
                <span class="px-3.5 py-1.5 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-500/10 text-emerald-400 border border-emerald-500/30">
                    {{ $draw->status->value ?? (string) $draw->status }}
                </span>
            </div>
        </div>

        <!-- Winning Numbers Display -->
        @if($draw->result)
            <div id="live-results-board" class="space-y-6">
                <div class="bg-slate-950 border border-slate-800 rounded-2xl p-6 text-center">
                    <span class="text-xs text-slate-400 uppercase tracking-widest font-bold block mb-2">First Prize (รางวัลที่ 1)</span>
                    <div class="text-4xl sm:text-5xl font-black font-mono text-amber-400 tracking-widest" data-prize="first">
                        {{ $draw->result->first_prize }}
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-4 text-center">
                        <span class="text-[11px] text-slate-400 uppercase font-semibold block mb-1">3-Digit Top</span>
                        <span class="text-2xl font-bold font-mono text-emerald-400" data-prize="3d_top">{{ $draw->result->three_digits_top }}</span>
                    </div>
                    <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-4 text-center">
                        <span class="text-[11px] text-slate-400 uppercase font-semibold block mb-1">3-Digit Tod</span>
                        <span class="text-lg font-bold font-mono text-emerald-400" data-prize="3d_tod">All Permutations</span>
                    </div>
                    <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-4 text-center">
                        <span class="text-[11px] text-slate-400 uppercase font-semibold block mb-1">2-Digit Top</span>
                        <span class="text-2xl font-bold font-mono text-emerald-400" data-prize="2d_top">{{ substr($draw->result->three_digits_top ?? '00', -2) }}</span>
                    </div>
                    <div class="bg-slate-950/80 border border-slate-800 rounded-2xl p-4 text-center">
                        <span class="text-[11px] text-slate-400 uppercase font-semibold block mb-1">2-Digit Bottom</span>
                        <span class="text-2xl font-bold font-mono text-emerald-400" data-prize="2d_bottom">{{ $draw->result->two_digits_bottom }}</span>
                    </div>
                </div>
            </div>
        @else
            <div class="py-12 text-center text-slate-500">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p class="text-sm font-semibold text-slate-400">Results are pending publication.</p>
                <p class="text-xs text-slate-500 mt-1">Official winning numbers are announced immediately following the government draw ceremony.</p>
            </div>
        @endif
    </div>
</div>
@endsection
