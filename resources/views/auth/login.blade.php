@extends('layouts.app')

@section('title', 'Player Sign In')

@section('content')
<div class="min-h-[75vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8 bg-slate-900/90 border border-slate-800 rounded-2xl p-8 shadow-2xl backdrop-blur">
        <div class="text-center">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-amber-500 to-emerald-500 mx-auto flex items-center justify-center font-black text-2xl text-slate-950 shadow-lg mb-4">
                TL
            </div>
            <h2 class="text-3xl font-extrabold tracking-tight text-white">
                Player Portal Sign In
            </h2>
            <p class="mt-2 text-sm text-slate-400">
                Secure access to real-time lottery wagering & instant payouts.
            </p>
        </div>

        @if ($errors->any())
            <div class="bg-rose-950/60 border border-rose-600/50 rounded-xl p-4 text-rose-200 text-sm">
                <ul class="list-disc list-inside space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form class="mt-8 space-y-6" action="{{ route('login.attempt') }}" method="POST">
            @csrf
            <div class="space-y-4">
                <div>
                    <label for="login" class="block text-sm font-medium text-slate-300 mb-1">Username or Email</label>
                    <input id="login" name="login" type="text" autocomplete="username" required value="{{ old('login') }}" class="appearance-none relative block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm" placeholder="e.g. player1 or player@example.com">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-300 mb-1">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required class="appearance-none relative block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm" placeholder="••••••••">
                </div>
            </div>

            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <input id="remember" name="remember" type="checkbox" class="h-4 w-4 text-emerald-600 focus:ring-emerald-500 border-slate-700 rounded bg-slate-950">
                    <label for="remember" class="ml-2 block text-sm text-slate-400">
                        Remember me
                    </label>
                </div>
                <div class="text-sm">
                    <span class="text-slate-500">Need help? Contact support</span>
                </div>
            </div>

            <div>
                <button type="submit" class="group relative w-full flex justify-center py-3.5 px-4 border border-transparent text-sm font-bold rounded-xl text-slate-950 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-lg hover:shadow-emerald-500/20 transition duration-200">
                    Sign In to Play
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
