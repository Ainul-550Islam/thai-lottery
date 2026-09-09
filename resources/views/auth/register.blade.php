@extends('layouts.app')

@section('title', 'Player Registration')

@section('content')
<div class="min-h-[75vh] flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8 bg-slate-900/90 border border-slate-800 rounded-2xl p-8 shadow-2xl backdrop-blur">
        <div class="text-center">
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-amber-500 to-emerald-500 mx-auto flex items-center justify-center font-black text-2xl text-slate-950 shadow-lg mb-4">
                TL
            </div>
            <h2 class="text-3xl font-extrabold tracking-tight text-white">
                Create Player Account
            </h2>
            <p class="mt-2 text-sm text-slate-400">
                Join Thailand's premier digital lottery platform with instant payouts.
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

        <form class="mt-8 space-y-5" action="{{ route('register.attempt', [], false) }}" method="POST">
            @csrf
            <div class="space-y-4">
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-300 mb-1">Full Name</label>
                    <input id="name" name="name" type="text" autocomplete="name" required value="{{ old('name') }}" class="appearance-none relative block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm" placeholder="Somchai Jaidee">
                </div>

                <div>
                    <label for="username" class="block text-sm font-medium text-slate-300 mb-1">Username</label>
                    <input id="username" name="username" type="text" autocomplete="username" required value="{{ old('username') }}" class="appearance-none relative block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm" placeholder="somchai99">
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-300 mb-1">Email Address</label>
                    <input id="email" name="email" type="email" autocomplete="email" required value="{{ old('email') }}" class="appearance-none relative block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm" placeholder="somchai@example.com">
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-slate-300 mb-1">Mobile Phone (Optional)</label>
                    <input id="phone" name="phone" type="tel" autocomplete="tel" value="{{ old('phone') }}" class="appearance-none relative block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm" placeholder="+66 81 234 5678">
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-slate-300 mb-1">Password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required class="appearance-none relative block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm" placeholder="Min. 8 characters">
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-300 mb-1">Confirm Password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="appearance-none relative block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm" placeholder="••••••••">
                </div>

                <div>
                    <label for="referral_code" class="block text-sm font-medium text-slate-300 mb-1">Agent Referral Code (Optional)</label>
                    <input id="referral_code" name="referral_code" type="text" value="{{ old('referral_code', request('ref')) }}" class="appearance-none relative block w-full px-4 py-3 border border-slate-700 bg-slate-950 text-slate-100 placeholder-slate-500 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent sm:text-sm uppercase" placeholder="AG123456">
                </div>
            </div>

            <div>
                <button type="submit" class="group relative w-full flex justify-center py-3.5 px-4 border border-transparent text-sm font-bold rounded-xl text-slate-950 bg-gradient-to-r from-amber-400 via-emerald-400 to-emerald-500 hover:from-amber-500 hover:to-emerald-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-lg hover:shadow-emerald-500/20 transition duration-200">
                    Complete Registration & Play
                </button>
            </div>

            <div class="text-center text-sm text-slate-400">
                Already registered?
                <a href="{{ route('login') }}" class="font-semibold text-emerald-400 hover:text-emerald-300 ml-1">Sign in here</a>
            </div>
        </form>
    </div>
</div>
@endsection
