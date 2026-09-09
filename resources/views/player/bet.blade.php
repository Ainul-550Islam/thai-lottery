@extends('layouts.app')

@section('title', 'Place Lottery Bet — Thai Lottery')

@section('content')
<div class="max-w-6xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Place Lottery Wager</h1>
            <p class="text-slate-400 text-sm mt-1">Select market, enter lottery digits, and build your multi-item bet slip.</p>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-slate-400">Active Draw:</span>
            <span class="font-mono text-xs font-bold px-3 py-1 bg-emerald-950 text-emerald-400 border border-emerald-800 rounded-full">
                {{ $openDraw->draw_number ?? 'DRAW-20260916' }}
            </span>
            <input type="hidden" id="active-draw-id" value="{{ $openDraw->id ?? 1 }}">
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Keypad / Market Selection Column (2 Cols) -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
                <!-- Market Type Selector -->
                <div class="mb-6">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">Select Market</label>
                    <select id="market-select" class="w-full bg-slate-950 border border-slate-800 rounded-2xl px-4 py-3 text-white font-semibold text-sm focus:outline-none focus:border-emerald-500">
                        <option value="three_digits_top">3-Digit Top (3 ตัวบน) — Multiplier: 900x</option>
                        <option value="three_digits_tod">3-Digit Tod (3 ตัวโต๊ด) — Multiplier: 120x</option>
                        <option value="two_digits_top">2-Digit Top (2 ตัวบน) — Multiplier: 90x</option>
                        <option value="two_digits_bottom">2-Digit Bottom (2 ตัวล่าง) — Multiplier: 90x</option>
                        <option value="run_top">Run Top (วิ่งบน) — Multiplier: 3.2x</option>
                        <option value="run_bottom">Run Bottom (วิ่งล่าง) — Multiplier: 4.2x</option>
                    </select>
                </div>

                <!-- Number Input Field -->
                <div class="mb-6">
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">Enter Digits</label>
                    <input type="text" id="selected-number-input" maxlength="3" placeholder="---"
                           class="w-full bg-slate-950 border border-slate-800 rounded-2xl px-4 py-4 text-center font-mono font-black text-3xl text-amber-400 tracking-widest focus:outline-none focus:border-emerald-500">
                </div>

                <!-- Interactive Keypad -->
                <div id="number-keypad" class="grid grid-cols-3 gap-3 mb-6">
                    @for($i = 1; $i <= 9; $i++)
                        <button type="button" data-key="{{ $i }}" class="py-4 bg-slate-950 hover:bg-slate-800 border border-slate-800 text-white font-mono font-bold text-xl rounded-2xl transition active:scale-95">
                            {{ $i }}
                        </button>
                    @endfor
                    <button type="button" data-key="clear" class="py-4 bg-slate-950 hover:bg-rose-950/40 border border-slate-800 text-rose-400 font-bold text-sm rounded-2xl transition">
                        CLR
                    </button>
                    <button type="button" data-key="0" class="py-4 bg-slate-950 hover:bg-slate-800 border border-slate-800 text-white font-mono font-bold text-xl rounded-2xl transition active:scale-95">
                        0
                    </button>
                    <button type="button" data-key="backspace" class="py-4 bg-slate-950 hover:bg-slate-800 border border-slate-800 text-slate-400 font-bold text-sm rounded-2xl transition">
                        DEL
                    </button>
                </div>

                <!-- Stake & Add Button -->
                <div class="flex items-center gap-3">
                    <div class="w-1/3">
                        <label class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1">Stake (THB)</label>
                        <input type="number" id="stake-amount-input" value="10" min="1" step="1"
                               class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2.5 text-center font-mono font-bold text-white text-sm focus:outline-none focus:border-emerald-500">
                    </div>
                    <div class="w-2/3">
                        <label class="text-[11px] font-bold text-transparent block mb-1">Action</label>
                        <button type="button" id="btn-add-to-slip" class="w-full py-2.5 bg-slate-800 hover:bg-slate-700 text-emerald-400 border border-emerald-500/30 font-bold text-sm rounded-xl transition">
                            + Add to Bet Slip
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dynamic Bet Slip Column (1 Col) -->
        <div class="space-y-6">
            <div id="bet-slip-container" class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl flex flex-col justify-between h-full min-h-[420px]">
                <div>
                    <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-4">
                        <h2 class="text-base font-bold text-white">Bet Slip Basket</h2>
                        <span class="text-xs bg-slate-800 text-slate-400 px-2 py-0.5 rounded-full font-mono font-semibold">Atomic Batch</span>
                    </div>

                    <!-- Items Container -->
                    <div id="bet-items-list" class="space-y-2.5 max-h-80 overflow-y-auto pr-1">
                        <!-- Populated by bet-slip.js -->
                    </div>
                </div>

                <div class="pt-6 border-t border-slate-800 space-y-4">
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between">
                            <span class="text-slate-400">Total Stake:</span>
                            <span class="font-mono font-bold text-white text-sm">฿<span id="total-stake-amount">0.00</span></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-slate-400">Max Potential Payout:</span>
                            <span class="font-mono font-bold text-emerald-400 text-sm">฿<span id="total-payout-amount">0.00</span></span>
                        </div>
                    </div>

                    <button type="button" id="btn-place-bet" disabled
                            class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-500 disabled:bg-slate-800 disabled:text-slate-500 text-white font-bold rounded-xl shadow-lg transition duration-150 text-sm">
                        Confirm & Place Bets
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
