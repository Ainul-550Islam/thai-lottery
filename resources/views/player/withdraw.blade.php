@extends('layouts.app')

@section('title', 'Request Withdrawal — Thai Lottery')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Withdraw Funds</h1>
        <p class="text-slate-400 text-sm mt-1">Disburse your settled winnings directly to your verified Thai bank account.</p>
    </div>

    <!-- Available Balance Card -->
    <div class="bg-gradient-to-r from-emerald-950/60 to-slate-900 border border-emerald-500/30 rounded-2xl p-5 shadow flex items-center justify-between">
        <div>
            <span class="text-xs text-emerald-400 font-semibold uppercase tracking-wider block">Available to Withdraw</span>
            <div class="text-2xl font-bold font-mono text-white mt-0.5">
                ฿{{ number_format((float) ($wallet->balance ?? 0) - (float) ($wallet->locked_balance ?? 0), 2) }}
            </div>
        </div>
        <div class="text-xs text-slate-400 text-right">
            <div>Min Withdrawal: <span class="text-white font-mono font-bold">฿300.00</span></div>
            <div>Processing Time: <span class="text-emerald-400 font-bold">5-15 mins</span></div>
        </div>
    </div>

    <!-- Withdrawal Form -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
        <form method="POST" action="{{ route('player.withdraw.store') }}" class="space-y-6">
            @csrf

            <!-- Bank Selection -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">Bank Name</label>
                    <select name="bank_name" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-emerald-500">
                        <option value="Bangkok Bank">Bangkok Bank (BBL)</option>
                        <option value="Kasikornbank">Kasikornbank (KBANK)</option>
                        <option value="Siam Commercial Bank">Siam Commercial Bank (SCB)</option>
                        <option value="Krungthai Bank">Krungthai Bank (KTB)</option>
                        <option value="Bank of Ayudhya">Bank of Ayudhya (Krungsri)</option>
                        <option value="TTB Bank">TMBThanachart Bank (TTB)</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">Account Number</label>
                    <input type="text" name="account_number" placeholder="XXX-X-XXXXX-X" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white font-mono text-sm focus:outline-none focus:border-emerald-500">
                </div>
            </div>

            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">Account Holder Name</label>
                <input type="text" name="account_name" value="{{ $user->name ?? '' }}" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-emerald-500">
                <span class="text-[11px] text-slate-500 mt-1 block">Account holder name must match your KYC verified identity.</span>
            </div>

            <!-- Amount -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">Withdrawal Amount (THB)</label>
                <div class="relative rounded-xl shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-slate-500 font-bold">฿</span>
                    </div>
                    <input type="number" name="amount" value="500" min="300" max="200000" step="10" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-8 pr-4 py-3 text-white font-mono font-bold text-lg focus:outline-none focus:border-emerald-500">
                </div>
            </div>

            <button type="submit" class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg transition duration-150">
                Confirm Withdrawal Request
            </button>
        </form>
    </div>
</div>
@endsection
