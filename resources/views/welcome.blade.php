<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenScore Backend API v1.0</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        code, pre { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-4 sm:p-6 antialiased selection:bg-blue-600 selection:text-white">
    <div class="max-w-2xl w-full bg-slate-900/90 border border-slate-800 rounded-3xl p-6 sm:p-10 shadow-2xl space-y-8 backdrop-blur-xl relative overflow-hidden">
        <!-- Glow accents -->
        <div class="absolute -top-24 -right-24 w-60 h-60 bg-blue-600/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -bottom-24 -left-24 w-60 h-60 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none"></div>

        <!-- Header -->
        <div class="flex items-center justify-between border-b border-slate-800/80 pb-6 relative z-10">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-gradient-to-tr from-blue-600 to-indigo-600 rounded-2xl flex items-center justify-center font-black text-white text-xl shadow-lg border border-blue-400/20">
                    OS
                </div>
                <div>
                    <h1 class="text-xl font-black tracking-tight text-white">OpenScore</h1>
                    <p class="text-xs text-slate-400 font-semibold">Automated Credit Scoring & Loan Engine</p>
                </div>
            </div>
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> API Operational
            </span>
        </div>

        <!-- Main Banner -->
        <div class="space-y-3 relative z-10">
            <span class="text-[10px] font-black uppercase tracking-widest bg-blue-950 text-blue-300 border border-blue-800 px-3 py-1 rounded-full inline-block">
                Laravel REST API v1.0
            </span>
            <h2 class="text-2xl sm:text-3xl font-black text-white leading-tight">
                This is a backend API of OpenScore
            </h2>
            <p class="text-sm text-slate-400 leading-relaxed font-medium">
                Powers real-time credit profile assessment, multi-stage admin verification gating, and stage-wise disbursal for both <strong class="text-purple-400">Cash Loans</strong> and <strong class="text-emerald-400">Construction Loans</strong>.
            </p>
        </div>

        <!-- System Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 relative z-10 text-xs">
            <div class="bg-slate-950/80 border border-slate-800/80 p-4 rounded-2xl space-y-1">
                <p class="text-slate-500 font-semibold">Active Engine</p>
                <p class="font-bold text-white">Laravel 13.32.0</p>
            </div>
            <div class="bg-slate-950/80 border border-slate-800/80 p-4 rounded-2xl space-y-1">
                <p class="text-slate-500 font-semibold">Cooldown Policy</p>
                <p class="font-bold text-emerald-400">3-Day Reapply Lock</p>
            </div>
            <div class="bg-slate-950/80 border border-slate-800/80 p-4 rounded-2xl space-y-1">
                <p class="text-slate-500 font-semibold">Partner Hydration</p>
                <p class="font-bold text-blue-400">15 Lending Partners</p>
            </div>
        </div>

        <!-- Quick Links & Actions -->
        <div class="pt-2 flex flex-col sm:flex-row gap-3 relative z-10 text-xs">
            <a href="/setup.php" class="flex-1 py-3.5 px-4 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold rounded-2xl text-center shadow-lg transition-all flex items-center justify-center gap-2">
                <span>⚡ Run Automated Setup (`setup.php`)</span>
            </a>
            <a href="/api/partners" target="_blank" class="py-3.5 px-5 bg-slate-800 hover:bg-slate-700 text-slate-200 font-bold rounded-2xl text-center border border-slate-700 transition-colors">
                View API Partners JSON →
            </a>
        </div>

        <!-- Footer -->
        <div class="border-t border-slate-800/80 pt-4 flex items-center justify-between text-[11px] text-slate-500 relative z-10">
            <span>© {{ date('Y') }} OpenScore. All Rights Reserved.</span>
            <span class="font-mono">Status: 200 OK</span>
        </div>
    </div>
</body>
</html>
