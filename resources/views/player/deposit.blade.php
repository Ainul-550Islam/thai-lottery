@extends('layouts.app')

@section('title', 'Deposit Funds — Thai Lottery')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Deposit Funds</h1>
        <p class="text-slate-400 text-sm mt-1">Instant top-up via PromptPay QR, TrueMoney, and Thai Bank Transfer.</p>
    </div>

    <!-- Deposit Form Card -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
        <form method="POST" action="{{ route('player.deposit.store') }}" class="space-y-6">
            @csrf

            <!-- Gateway Selection -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-3">Select Payment Method</label>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <label class="border border-slate-800 bg-slate-950 p-4 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-emerald-500 transition">
                        <input type="radio" name="gateway" value="promptpay" checked class="text-emerald-500 focus:ring-emerald-500 mb-2">
                        <span class="font-bold text-sm text-white">PromptPay QR</span>
                        <span class="text-[10px] text-emerald-400">Instant (No Fee)</span>
                    </label>
                    <label class="border border-slate-800 bg-slate-950 p-4 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-emerald-500 transition">
                        <input type="radio" name="gateway" value="truemoney" class="text-emerald-500 focus:ring-emerald-500 mb-2">
                        <span class="font-bold text-sm text-white">TrueMoney</span>
                        <span class="text-[10px] text-slate-400">E-Wallet</span>
                    </label>
                    <label class="border border-slate-800 bg-slate-950 p-4 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-emerald-500 transition">
                        <input type="radio" name="gateway" value="bank_transfer" class="text-emerald-500 focus:ring-emerald-500 mb-2">
                        <span class="font-bold text-sm text-white">Bank Transfer</span>
                        <span class="text-[10px] text-slate-400">Manual / Fast</span>
                    </label>
                </div>
            </div>

            <!-- Amount Input -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">Deposit Amount (THB)</label>
                <div class="relative rounded-xl shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-slate-500 font-bold">฿</span>
                    </div>
                    <input type="number" name="amount" value="500" min="100" max="500000" step="50" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-8 pr-4 py-3 text-white font-mono font-bold text-lg focus:outline-none focus:border-emerald-500">
                </div>
            </div>

            <button type="submit" class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg transition duration-150">
                Proceed to Payment
            </button>
        </form>
    </div>
</div>
@endsection
