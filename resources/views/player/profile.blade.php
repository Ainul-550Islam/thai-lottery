@extends('layouts.app')

@section('title', 'Player Profile & Settings — Thai Lottery')

@section('content')
<div class="max-w-4xl mx-auto space-y-8">
    <!-- Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">Player Profile & Limits</h1>
        <p class="text-slate-400 text-sm mt-1">Manage personal details, KYC identity verification, and responsible gaming limits.</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Sidebar / Summary -->
        <div class="space-y-6">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow text-center">
                <div class="w-20 h-20 bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 rounded-full flex items-center justify-center font-bold text-2xl mx-auto mb-3">
                    {{ strtoupper(substr($user->name ?? 'P', 0, 1)) }}
                </div>
                <h3 class="text-lg font-bold text-white">{{ $user->name ?? 'Player' }}</h3>
                <p class="text-xs text-slate-400 font-mono mt-0.5">{{ $user->email ?? 'player@example.com' }}</p>
            </div>
        </div>

        <!-- Main Settings / Responsible Gaming -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow">
                <h3 class="text-base font-bold text-white mb-4">Personal Details</h3>
                <form method="POST" action="{{ route('player.profile.update') }}" class="space-y-4">
                    @csrf
                    @method('PUT')
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Full Name</label>
                            <input type="text" name="name" value="{{ $user->name ?? '' }}" required
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white text-sm focus:outline-none focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-1">Phone Number</label>
                            <input type="text" name="phone" value="{{ $user->phone ?? '' }}"
                                   class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-white font-mono text-sm focus:outline-none focus:border-emerald-500">
                        </div>
                    </div>
                    <div class="pt-2 text-right">
                        <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl text-xs transition">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
