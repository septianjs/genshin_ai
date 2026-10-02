<!DOCTYPE html>

<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Genshin Build AI - Build Karakter</title>

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- Alpine.js -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<!-- Google Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    sans: ['Plus Jakarta Sans', 'sans-serif'],
                    cinzel: ['Cinzel', 'serif'],
                },

                colors: {
                    teyvat: {
                        bg: '#0a0d14',
                        card: '#121824',
                        border: '#1e293b',
                        gold: '#e5c158',
                        goldHover: '#f5d67b',
                    },

                    element: {
                        pyro: '#ef4444',
                        hydro: '#3b82f6',
                        electro: '#a855f7',
                        dendro: '#22c55e',
                        anemo: '#14b8a6',
                        cryo: '#38bdf8',
                        geo: '#eab308',
                    }
                }
            }
        }
    }
</script>

<style>
    [x-cloak] {
        display: none !important;
    }

    html {
        scroll-behavior: smooth;
    }

    body {
        background:
            radial-gradient(circle at 15% 10%, rgba(229,193,88,0.05), transparent 25%),
            radial-gradient(circle at 85% 20%, rgba(59,130,246,0.04), transparent 25%),
            #0a0d14;
    }

    .genshin-glass {
        background: rgba(18, 24, 36, 0.78);
        backdrop-filter: blur(14px);
        border: 1px solid rgba(229, 193, 88, 0.18);
    }

    .custom-scroll::-webkit-scrollbar {
        width: 6px;
    }

    .custom-scroll::-webkit-scrollbar-track {
        background: #0a0d14;
    }

    .custom-scroll::-webkit-scrollbar-thumb {
        background: #1e293b;
        border-radius: 4px;
    }

    .custom-scroll::-webkit-scrollbar-thumb:hover {
        background: #e5c158;
    }

    .ai-section {
        background: rgba(2, 6, 23, 0.62);
        border: 1px solid rgba(51, 65, 85, 0.8);
        border-radius: 16px;
        padding: 22px;
        margin-bottom: 18px;
    }

    .ai-section:last-child {
        margin-bottom: 0;
    }

    .ai-section-title {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #e5c158;
        font-weight: 800;
        font-family: 'Cinzel', serif;
        font-size: 15px;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        margin-bottom: 12px;
    }

    .ai-content {
        color: #cbd5e1;
        font-size: 15px;
        line-height: 1.85;
    }

    .ai-content p {
        margin-bottom: 8px;
    }

    .ai-content p:last-child {
        margin-bottom: 0;
    }

    .ai-content strong {
        color: #f8fafc;
        font-weight: 700;
    }

    .ai-content ul {
        margin: 8px 0;
        padding-left: 20px;
    }

    .ai-content li {
        margin: 5px 0;
    }

    .ai-content li::marker {
        color: #e5c158;
    }

    .ai-content .ai-highlight {
        display: inline-flex;
        align-items: center;
        background: rgba(229,193,88,0.10);
        border: 1px solid rgba(229,193,88,0.18);
        color: #f5d67b;
        padding: 2px 7px;
        border-radius: 6px;
    }

    .entity-asset-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(118px, 1fr));
        gap: 12px;
        margin-top: 16px;
    }

    .entity-asset-card {
        min-width: 0;
        padding: 12px;
        border: 1px solid rgba(51, 65, 85, 0.8);
        border-radius: 14px;
        background: rgba(15, 23, 42, 0.72);
    }

    .entity-asset-frame {
        width: 100%;
        aspect-ratio: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border: 1px solid rgba(71, 85, 105, 0.65);
        border-radius: 10px;
        background: rgba(2, 6, 23, 0.7);
    }

    .entity-asset-image {
        width: 100%;
        height: 100%;
        object-fit: contain;
    }

    .entity-asset-character .entity-asset-image {
        object-fit: cover;
    }

    .entity-asset-unavailable {
        padding: 8px;
        color: #94a3b8;
        font-size: 11px;
        line-height: 1.4;
        text-align: center;
    }

    .entity-asset-name {
        margin-top: 9px;
        color: #f1f5f9;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.4;
        overflow-wrap: anywhere;
    }

    .entity-asset-detail {
        margin-top: 4px;
        color: #94a3b8;
        font-size: 10px;
        line-height: 1.4;
    }

    .loading-orb {
        animation: pulseOrb 1.8s infinite ease-in-out;
    }

    @keyframes pulseOrb {
        0%, 100% {
            transform: scale(1);
            box-shadow: 0 0 0 rgba(229,193,88,0);
        }

        50% {
            transform: scale(1.08);
            box-shadow: 0 0 35px rgba(229,193,88,0.18);
        }
    }

    .loading-bar {
        animation: loadingBar 2s infinite ease-in-out;
    }

    @keyframes loadingBar {
        0% {
            transform: translateX(-100%);
        }

        50% {
            transform: translateX(0%);
        }

        100% {
            transform: translateX(100%);
        }
    }

    .character-hero {
        background:
            linear-gradient(
                135deg,
                rgba(229,193,88,0.12),
                rgba(15,23,42,0.25) 50%,
                rgba(2,6,23,0.75)
            );
    }
</style>
```

</head>

<body
    class="bg-teyvat-bg text-slate-100 min-h-screen flex flex-col font-sans"
    x-data="genshinApp()"
    x-init="initApp()"
    data-genshin-api-base="{{ rtrim(config('services.genshin.third_party_url', 'https://genshin.jmp.blue'), '/') }}"
>

```
<!-- ========================================================= -->
<!-- GLOBAL AI LOADING OVERLAY -->
<!-- ========================================================= -->

<template x-if="loadingBuild">
    <div
        class="fixed inset-0 z-[100] bg-slate-950/85 backdrop-blur-md flex items-center justify-center p-6"
    >
        <div class="w-full max-w-md text-center">

            <div class="loading-orb mx-auto w-20 h-20 rounded-3xl bg-gradient-to-br from-teyvat-gold to-amber-600 flex items-center justify-center text-slate-950 text-3xl font-bold shadow-2xl">
                ✦
            </div>

            <h2 class="mt-6 text-xl font-bold font-cinzel text-teyvat-gold">
                Menganalisis Build
            </h2>

            <p class="mt-2 text-sm text-slate-400">
                Ava sedang menyusun rekomendasi untuk
                <span
                    class="text-slate-200 font-semibold"
                    x-text="activeCharData?.name || selectedSlug"
                ></span>
            </p>

            <div class="mt-6 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                <div class="loading-bar h-full w-1/2 bg-gradient-to-r from-transparent via-teyvat-gold to-transparent"></div>
            </div>

            <div class="mt-5 grid grid-cols-3 gap-2 text-[10px]">
                <div class="rounded-xl bg-slate-900/80 border border-slate-800 p-3">
                    <div class="text-lg mb-1">⚙️</div>
                    <span class="text-slate-400">Mechanics</span>
                </div>

                <div class="rounded-xl bg-slate-900/80 border border-slate-800 p-3">
                    <div class="text-lg mb-1">🔎</div>
                    <span class="text-slate-400">Character data</span>
                </div>

                <div class="rounded-xl bg-slate-900/80 border border-slate-800 p-3">
                    <div class="text-lg mb-1">🧠</div>
                    <span class="text-slate-400">Nemotron</span>
                </div>
            </div>

            <p class="mt-5 text-[11px] text-slate-600">
                Proses AI dapat membutuhkan beberapa detik.
            </p>
        </div>
    </div>
</template>


<!-- ========================================================= -->
<!-- TOP NAVIGATION -->
<!-- ========================================================= -->

<header class="border-b border-teyvat-border bg-teyvat-card/80 backdrop-blur-md sticky top-0 z-50 px-4 lg:px-8 py-3.5 flex items-center justify-between shadow-xl">

    <div class="flex items-center space-x-3">

        <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-teyvat-gold to-amber-600 flex items-center justify-center shadow-lg shadow-amber-500/20 text-slate-950 font-bold font-cinzel text-xl">
            ✦
        </div>

        <div>
            <h1 class="text-lg lg:text-xl font-bold font-cinzel text-teyvat-gold tracking-wide">
                GENSHIN BUILD AI
            </h1>

            <p class="text-xs text-slate-400">
                Build karakter dengan AI + data Genshin
            </p>
        </div>

    </div>

    <div class="hidden sm:flex items-center gap-2">

        <span class="px-3 py-1.5 rounded-full text-[10px] font-semibold bg-emerald-950/50 border border-emerald-800/50 text-emerald-400">
            ● AI ONLINE
        </span>

        <span class="px-3 py-1.5 rounded-full text-[10px] font-semibold bg-slate-900 border border-slate-800 text-slate-400">
            Nemotron 3.5
        </span>

    </div>

</header>


<!-- ========================================================= -->
<!-- MAIN -->
<!-- ========================================================= -->

<main class="flex-1 max-w-screen-2xl w-full mx-auto p-4 lg:p-8 grid grid-cols-1 lg:grid-cols-12 gap-7">


    <!-- ===================================================== -->
    <!-- LEFT -->
    <!-- ===================================================== -->

    <div class="lg:col-span-7 flex flex-col space-y-6">


        <!-- ================================================= -->
        <!-- STEP 1 -->
        <!-- ================================================= -->

        <div class="genshin-glass rounded-2xl p-5 shadow-2xl">

            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-800">

                <h2 class="text-base font-bold font-cinzel text-teyvat-gold flex items-center">

                    <span class="w-2 h-2 rounded-full bg-teyvat-gold mr-2"></span>

                    1. PILIH KARAKTER

                </h2>

                <span
                    class="text-xs text-slate-400"
                    x-text="characters.length + ' Karakter'"
                ></span>

            </div>


            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">

                <!-- Character -->

                <div>

                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">
                        Karakter Utama
                    </label>

                    <div class="flex items-center gap-3 mb-2.5 rounded-xl bg-slate-950/70 border border-slate-800 p-2.5">
                        <div class="relative w-14 h-14 rounded-lg bg-slate-900 border border-slate-700 overflow-hidden shrink-0">
                            <template x-if="activeCharData?.icon_url">
                                <img
                                    :src="activeCharData.icon_url"
                                    :alt="activeCharData.name"
                                    class="w-full h-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                    @load="$el.hidden = false; $el.parentElement.querySelector('[data-character-fallback]').classList.add('hidden')"
                                    @@error="$el.hidden = true; $el.parentElement.querySelector('[data-character-fallback]').classList.remove('hidden')"
                                >
                            </template>
                            <template x-if="!activeCharData?.icon_url">
                                <div class="absolute inset-0 p-1 flex items-center justify-center text-center text-[9px] leading-tight text-slate-400">
                                    Asset unavailable
                                </div>
                            </template>
                            <div data-character-fallback class="hidden absolute inset-0 p-1 flex items-center justify-center text-center text-[9px] leading-tight text-slate-400">
                                Asset unavailable
                            </div>
                        </div>
                        <div class="min-w-0">
                            <div class="font-semibold text-sm text-slate-100 truncate" x-text="activeCharData?.name || 'Pilih karakter'"></div>
                            <div class="text-xs text-slate-400 mt-1">
                                <span x-text="activeCharData?.vision || '-'"></span>
                                <span> · </span>
                                <span x-text="activeCharData?.weapon_type || '-'"></span>
                                <span> · </span>
                                <span x-text="activeCharData?.rarity ? activeCharData.rarity + '★' : '-'"></span>
                            </div>
                        </div>
                    </div>

                    <select
                        x-model="selectedSlug"
                        @change="onCharacterChange()"
                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-sm text-slate-100 focus:outline-none focus:border-teyvat-gold transition"
                    >

                        <template x-for="char in characters" :key="char.slug">

                            <option
                                :value="char.slug"
                                x-text="char.name + ' (' + char.vision + ' - ' + char.rarity + '★)'"
                            ></option>

                        </template>

                    </select>

                </div>


                <!-- Content Mode -->

                <div>

                    <label class="block text-xs font-semibold text-slate-300 mb-1.5">
                        Tujuan Build
                    </label>

                    <select
                        x-model="contentMode"
                        @change="onTeamOrContentChange()"
                        class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-sm text-slate-100 focus:outline-none focus:border-teyvat-gold transition"
                    >

                        <option value="abyss">
                            ⚔️ Spiral Abyss
                        </option>

                        <option value="theater">
                            🎭 Imaginarium Theater
                        </option>

                        <option value="overworld">
                            🗺️ Overworld & Story
                        </option>

                        <option value="boss">
                            👑 Boss & Domain
                        </option>

                    </select>

                </div>

            </div>


            <div class="mb-4">
                <label class="block text-xs font-semibold text-slate-300 mb-1.5" for="preferredBuildRole">
                    Role eksperimen (opsional)
                </label>
                <input
                    id="preferredBuildRole"
                    type="text"
                    x-model="preferredRole"
                    maxlength="80"
                    placeholder="Contoh: Barbara DPS physical, healer jadi driver"
                    class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-sm text-slate-100 placeholder:text-slate-500 focus:outline-none focus:border-teyvat-gold transition"
                >
            </div>

            <!-- Constellation -->

            <div>

                <label class="block text-xs font-semibold text-slate-300 mb-2">
                    Tingkat Konstelasi
                </label>

                <div class="grid grid-cols-7 gap-1.5">

                    <template x-for="c in [0,1,2,3,4,5,6]" :key="c">

                        <button
                            type="button"
                            @click="constellation = c; onTeamOrContentChange()"
                            :class="constellation === c
                                ? 'bg-teyvat-gold text-slate-950 font-bold shadow-lg shadow-amber-500/30'
                                : 'bg-slate-900/80 text-slate-300 border border-slate-800 hover:border-slate-600'"
                            class="py-2 rounded-lg text-xs font-medium transition text-center"
                        >
                            <span x-text="'C' + c"></span>
                        </button>

                    </template>

                </div>

            </div>

        </div>


        <!-- ================================================= -->
        <!-- STEP 2 TEAM -->
        <!-- ================================================= -->

        <div class="genshin-glass rounded-2xl p-5 shadow-2xl">

            <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-800">

                <h2 class="text-base font-bold font-cinzel text-teyvat-gold flex items-center">

                    <span class="w-2 h-2 rounded-full bg-teyvat-gold mr-2"></span>

                    2. KOMPOSISI TIM

                </h2>

                <span class="text-xs text-slate-400">
                    4 Slot
                </span>

            </div>


            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">


                <!-- Target -->

                <div class="bg-slate-900/90 border-2 border-teyvat-gold/60 rounded-xl p-3 flex flex-col items-center text-center relative overflow-hidden">

                    <div class="text-[10px] font-bold uppercase tracking-wider text-teyvat-gold mb-2">
                        Target
                    </div>

                    <div class="relative w-16 h-16 rounded-xl bg-slate-800 border border-slate-700 overflow-hidden mb-2">

                        <template x-if="activeCharData?.icon_url">

                            <img
                                :src="activeCharData.icon_url"
                                class="w-full h-full object-cover"
                                :alt="activeCharData?.name"
                                loading="lazy"
                                decoding="async"
                                @load="$el.hidden = false; $el.parentElement.querySelector('[data-character-fallback]').classList.add('hidden')"
                                @@error="$el.hidden = true; $el.parentElement.querySelector('[data-character-fallback]').classList.remove('hidden')"
                            >

                        </template>

                        <div data-character-fallback class="hidden absolute inset-0 p-1 flex items-center justify-center text-center text-[9px] leading-tight text-slate-400">
                            Asset unavailable
                        </div>

                    </div>

                    <span
                        class="font-bold text-xs text-slate-100 truncate w-full"
                        x-text="activeCharData?.name || selectedSlug"
                    ></span>

                    <span
                        class="text-[10px] text-slate-400 font-mono"
                        x-text="'C' + constellation + ' • ' + (activeCharData?.vision || '')"
                    ></span>

                </div>


                <!-- Teammates -->

                <template x-for="(tmSlug, index) in teamSlugs" :key="index">

                    <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-3 flex flex-col items-center justify-between text-center relative">

                        <span class="text-[10px] font-medium text-slate-400 mb-1">
                            Slot <span x-text="index + 2"></span>
                        </span>

                        <div class="relative w-12 h-12 rounded-xl bg-slate-800 border border-slate-700 overflow-hidden mb-2">
                            <template x-if="characterBySlug(tmSlug)?.icon_url">
                                <img
                                    :src="characterBySlug(tmSlug).icon_url"
                                    :alt="characterBySlug(tmSlug).name"
                                    class="w-full h-full object-cover"
                                    loading="lazy"
                                    decoding="async"
                                    @load="$el.hidden = false; $el.parentElement.querySelector('[data-character-fallback]').classList.add('hidden')"
                                    @@error="$el.hidden = true; $el.parentElement.querySelector('[data-character-fallback]').classList.remove('hidden')"
                                >
                            </template>
                            <div data-character-fallback class="hidden absolute inset-0 p-1 flex items-center justify-center text-center text-[8px] leading-tight text-slate-400">
                                Asset unavailable
                            </div>
                            <template x-if="tmSlug && !characterBySlug(tmSlug)?.icon_url">
                                <div class="absolute inset-0 p-1 flex items-center justify-center text-center text-[8px] leading-tight text-slate-400">
                                    Asset unavailable
                                </div>
                            </template>
                            <template x-if="!tmSlug">
                                <div class="absolute inset-0 flex items-center justify-center text-[9px] text-slate-500">
                                    Kosong
                                </div>
                            </template>
                        </div>

                        <span
                            class="w-full text-[10px] font-semibold text-slate-200 truncate mb-1"
                            x-text="characterBySlug(tmSlug)?.name || 'Slot kosong'"
                        ></span>

                        <select
                            x-model="teamSlugs[index]"
                            @change="onTeamOrContentChange()"
                            class="w-full bg-slate-950 border border-slate-800 rounded-lg text-[11px] text-slate-200 p-1.5 focus:outline-none"
                        >

                            <option value="">
                                (Kosong)
                            </option>

                            <template x-for="char in characters" :key="char.slug">

                                <option
                                    :value="char.slug"
                                    x-text="char.name"
                                ></option>

                            </template>

                        </select>

                        <span class="text-[9px] text-slate-500 mt-1">
                            Rekan Tim
                        </span>

                    </div>

                </template>

            </div>


            <!-- Resonance -->

            <div class="bg-slate-950/70 border border-slate-800/80 rounded-xl p-3.5 space-y-3">

                <div>

                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">
                        ✦ Resonansi Elemen
                    </span>

                    <div class="flex flex-wrap gap-1.5">

                        <template x-if="activeResonances.length === 0">

                            <span class="text-xs text-slate-500 italic">
                                Belum ada resonansi aktif.
                            </span>

                        </template>

                        <template x-for="res in activeResonances" :key="res.name">

                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-amber-950/40 border border-teyvat-gold/40 text-teyvat-gold">

                                ✦

                                <span class="ml-1" x-text="res.name"></span>

                            </span>

                        </template>

                    </div>

                </div>


                <!-- Reactions -->

                <div>

                    <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">
                        ⚡ Reaksi Elemen
                    </span>

                    <div class="flex flex-wrap gap-1.5">

                        <template x-if="triggeredReactions.length === 0">

                            <span class="text-xs text-slate-500 italic">
                                Belum ada reaksi terdeteksi.
                            </span>

                        </template>

                        <template x-for="rx in triggeredReactions" :key="rx.reaction">

                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-medium bg-blue-950/50 border border-blue-600/40 text-blue-300">

                                ⚡

                                <span class="ml-1" x-text="rx.reaction"></span>

                            </span>

                        </template>

                    </div>

                </div>

            </div>


            <!-- Generate -->

            <div class="mt-4 space-y-2">
                <button
                    type="button"
                    @click="generateBuild()"
                    :disabled="loadingBuild"
                    class="w-full py-3 rounded-xl bg-gradient-to-r from-teyvat-gold via-amber-400 to-amber-500 hover:from-amber-400 hover:to-teyvat-gold disabled:opacity-60 text-slate-950 font-bold font-cinzel text-sm tracking-wide shadow-xl shadow-amber-500/20 transition flex items-center justify-center"
                >

                    <template x-if="!loadingBuild">

                        <span>
                            ✦ GENERATE BUILD AI ✦
                        </span>

                    </template>

                    <template x-if="loadingBuild">

                        <span class="flex items-center">

                            <svg
                                class="animate-spin mr-2 h-4 w-4"
                                fill="none"
                                viewBox="0 0 24 24"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                ></circle>

                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8v8H4z"
                                ></path>

                            </svg>

                            AI sedang bekerja...

                        </span>

                    </template>

                </button>

            </div>

        </div>


        <!-- ================================================= -->
        <!-- STEP 3 BUILD RESULT -->
        <!-- ================================================= -->

        <div
            x-show="buildResult"
            x-cloak
            class="genshin-glass rounded-2xl shadow-2xl overflow-hidden border border-teyvat-gold/30"
        >

            <!-- Character Hero -->

            <div class="character-hero p-5 sm:p-6 border-b border-slate-800">

                <div class="flex items-center gap-4">

                    <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-2xl overflow-hidden bg-slate-900 border-2 border-teyvat-gold/50 shadow-xl flex-shrink-0">

                        <template x-if="characterIconUrl(buildResult?.character)">

                            <img
                                :src="characterIconUrl(buildResult.character)"
                                :alt="buildResult.character.name"
                                class="w-full h-full object-cover"
                                loading="lazy"
                                decoding="async"
                                @load="$el.hidden = false; $el.parentElement.querySelector('[data-character-fallback]').classList.add('hidden')"
                                @@error="$el.hidden = true; $el.parentElement.querySelector('[data-character-fallback]').classList.remove('hidden')"
                            >

                        </template>

                        <div data-character-fallback x-show="!characterIconUrl(buildResult?.character)" class="absolute inset-0 p-2 flex items-center justify-center text-center text-xs text-slate-400">
                            Asset unavailable<br>
                            <span x-text="buildResult?.character?.name"></span>
                        </div>

                    </div>


                    <div class="min-w-0 flex-1">

                        <div class="flex flex-wrap items-center gap-2 mb-2">

                            <span class="px-2 py-1 rounded-md bg-teyvat-gold/10 border border-teyvat-gold/30 text-teyvat-gold text-[10px] font-bold uppercase">
                                AI BUILD
                            </span>

                            <span
                                class="px-2 py-1 rounded-md bg-slate-900 border border-slate-700 text-slate-300 text-[10px] font-bold"
                                x-text="'C' + (buildResult?.character?.constellation ?? 0)"
                            ></span>

                            <span
                                class="px-2 py-1 rounded-md bg-slate-900 border border-slate-700 text-slate-300 text-[10px] font-bold"
                                x-text="buildResult?.character?.vision || ''"
                            ></span>

                        </div>

                        <h3
                            class="text-2xl sm:text-3xl font-bold font-cinzel text-slate-100 truncate"
                            x-text="buildResult?.character?.name || 'Character'"
                        ></h3>

                        <p class="text-xs text-slate-400 mt-1">
                            Rekomendasi AI berdasarkan data karakter, mekanik, dan tim.
                        </p>

                    </div>

                </div>


                <!-- Metadata -->

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mt-5">

                    <div class="rounded-xl bg-slate-950/60 border border-slate-800 p-3">

                        <div class="text-[10px] text-slate-500 uppercase">
                            Element
                        </div>

                        <div
                            class="text-sm font-bold text-slate-200 mt-1"
                            x-text="buildResult?.character?.vision || '-'"
                        ></div>

                    </div>

                    <div class="rounded-xl bg-slate-950/60 border border-slate-800 p-3">

                        <div class="text-[10px] text-slate-500 uppercase">
                            Weapon
                        </div>

                        <div
                            class="text-sm font-bold text-slate-200 mt-1"
                            x-text="buildResult?.character?.weapon_type || '-'"
                        ></div>

                    </div>

                    <div class="rounded-xl bg-slate-950/60 border border-slate-800 p-3">

                        <div class="text-[10px] text-slate-500 uppercase">
                            Rarity
                        </div>

                        <div class="text-sm font-bold text-teyvat-gold mt-1">
                            <span x-text="buildResult?.character?.rarity || '-'"></span>★
                        </div>

                    </div>

                    <div class="rounded-xl bg-slate-950/60 border border-slate-800 p-3">

                        <div class="text-[10px] text-slate-500 uppercase">
                            Mode
                        </div>

                        <div
                            class="text-sm font-bold text-slate-200 mt-1 capitalize"
                            x-text="contentMode"
                        ></div>

                    </div>

                </div>

            </div>


            <!-- Constellation Notice -->

            <template x-if="buildResult?.mechanics?.role_shift">

                <div class="mx-5 mt-5 p-4 rounded-xl bg-amber-950/30 border border-amber-500/30">

                    <div class="flex gap-3">

                        <div class="text-xl">
                            ✨
                        </div>

                        <div>

                            <div class="text-xs font-bold text-amber-300 uppercase tracking-wide mb-1">
                                Pengaruh Konstelasi
                            </div>

                            <div
                                class="text-xs text-amber-100 leading-relaxed"
                                x-text="buildResult.mechanics.role_shift"
                            ></div>

                        </div>

                    </div>

                </div>

            </template>


            <!-- AI REPORT -->

            <div class="px-5 sm:px-6 pt-5">
                <section class="rounded-2xl bg-slate-950/55 border border-slate-800 p-4 sm:p-5">
                    <h4 class="text-sm sm:text-base font-bold font-cinzel text-teyvat-gold mb-4">
                        Karakter yang Dipilih untuk Analisis
                    </h4>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <template x-for="member in buildTeamMembers()" :key="member.slug">
                            <div class="rounded-xl bg-slate-900/80 border border-slate-800 p-2.5 text-center min-w-0">
                                <div class="relative mx-auto w-full max-w-24 aspect-square rounded-lg overflow-hidden bg-slate-950 border border-slate-700">
                                    <template x-if="characterIconUrl(member)">
                                        <img
                                            :src="characterIconUrl(member)"
                                            :alt="member.name"
                                            class="w-full h-full object-cover"
                                            loading="lazy"
                                            decoding="async"
                                            @load="$el.hidden = false; $el.parentElement.querySelector('[data-team-fallback]').classList.add('hidden')"
                                            @@error="$el.hidden = true; $el.parentElement.querySelector('[data-team-fallback]').classList.remove('hidden')"
                                        >
                                    </template>
                                    <div data-team-fallback class="hidden absolute inset-0 p-1 flex items-center justify-center text-center text-[9px] leading-tight text-slate-400">
                                        Asset unavailable
                                    </div>
                                    <template x-if="!characterIconUrl(member)">
                                        <div class="absolute inset-0 p-1 flex items-center justify-center text-center text-[9px] leading-tight text-slate-400">
                                            Asset unavailable
                                        </div>
                                    </template>
                                </div>
                                <div class="mt-2 text-xs font-semibold text-slate-100 truncate" x-text="member.name"></div>
                                <div class="mt-1 text-[10px] text-slate-400" x-text="member.vision || ''"></div>
                            </div>
                        </template>
                    </div>
                </section>
            </div>

            <div class="p-5 sm:p-6">

                <div class="flex items-center justify-between mb-4">

                    <div>

                        <div class="text-[10px] uppercase tracking-widest text-teyvat-gold font-bold">
                            AI Analysis
                        </div>

                        <h4 class="text-xl sm:text-2xl font-bold font-cinzel text-slate-100">
                            Build Recommendation
                        </h4>

                    </div>

                    <span
                        class="hidden sm:inline-flex px-2.5 py-1 rounded-lg bg-slate-900 border border-slate-800 text-[10px] text-slate-400 font-mono"
                        x-text="buildResult?.model || 'Nemotron 3.5'"
                    ></span>

                </div>


                <!-- Structured AI output -->

                <div
                    class="custom-scroll max-h-[650px] overflow-y-auto pr-1"
                    x-html="formatAIRecommendation(buildResult?.ai_recommendation || '')"
                ></div>

            </div>


            <!-- Technical info -->

            <div class="px-5 sm:px-6 pb-5">

                <div class="rounded-xl bg-slate-950/60 border border-slate-800 p-3">

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-[10px] text-slate-500">

                        <span>
                            🤖 <strong class="text-slate-400">Model:</strong>
                            <span x-text="buildResult?.model || '-'"></span>
                        </span>

                        <span x-show="buildResult?.tokens_used">
                            🧮 <strong class="text-slate-400">Tokens:</strong>
                            <span x-text="buildResult?.tokens_used || '-'"></span>
                        </span>

                        <span x-show="buildResult?.performance?.total_seconds">
                            ⏱️ <strong class="text-slate-400">Waktu:</strong>
                            <span
                                x-text="Number(buildResult?.performance?.total_seconds || 0).toFixed(1) + 's'"
                            ></span>
                        </span>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- ===================================================== -->
    <!-- RIGHT CHAT -->
    <!-- ===================================================== -->

    <div class="lg:col-span-5 flex flex-col h-[850px] genshin-glass rounded-2xl shadow-2xl overflow-hidden">


        <!-- Chat Header -->

        <div class="p-4 border-b border-slate-800 bg-slate-900/80 flex items-center justify-between">

            <div class="flex items-center space-x-2.5">

                <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-teyvat-gold to-amber-600 flex items-center justify-center text-slate-950 text-xl shadow-md">
                    ✨
                </div>

                <div>

                    <h3 class="text-sm font-bold text-slate-100 font-cinzel">
                        GENSHIN BUILD ADVISOR
                    </h3>

                    <p class="text-[10px] text-emerald-400 flex items-center">

                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1 animate-pulse"></span>

                        Online • Nemotron 3.5 Lightning

                    </p>

                </div>

            </div>

            <button
                @click="clearChat()"
                class="text-[11px] text-slate-400 hover:text-teyvat-gold transition"
            >
                Reset
            </button>

        </div>


        <!-- Quick Prompts -->

        <div class="px-4 py-2.5 bg-slate-950/40 border-b border-slate-800/60 flex items-center space-x-2 overflow-x-auto text-[11px] custom-scroll">

            <span class="text-slate-500 shrink-0 font-medium">
                Tanya:
            </span>

            <button
                @click="sendQuickPrompt('Alternatif senjata F2P terbaik jika tidak punya senjata bintang 5?')"
                class="px-2.5 py-1 rounded-full bg-slate-800/80 border border-slate-700 text-slate-300 hover:border-teyvat-gold shrink-0 transition"
            >
                ⚔️ Senjata F2P
            </button>

            <button
                @click="sendQuickPrompt('Berapa target Energy Recharge ER minimal untuk rotasi lancar di tim ini?')"
                class="px-2.5 py-1 rounded-full bg-slate-800/80 border border-slate-700 text-slate-300 hover:border-teyvat-gold shrink-0 transition"
            >
                ⚡ Target ER
            </button>

            <button
                @click="sendQuickPrompt('Jelaskan urutan rotasi skill dan cara memicu reaksi damage terbesar.')"
                class="px-2.5 py-1 rounded-full bg-slate-800/80 border border-slate-700 text-slate-300 hover:border-teyvat-gold shrink-0 transition"
            >
                🔄 Rotasi
            </button>

        </div>


        <!-- Chat Messages -->

        <div
            class="flex-1 p-5 sm:p-6 overflow-y-auto space-y-5 custom-scroll"
            id="chatContainer"
        >

            <template x-for="(msg, idx) in chatMessages" :key="idx">

                <div
                    :class="msg.role === 'user'
                        ? 'flex justify-end'
                        : 'flex justify-start'"
                >

                    <template x-if="msg.role === 'assistant'">
                        <div class="flex items-start gap-3 w-full">
                            <div class="w-9 h-9 mt-1 rounded-xl bg-teyvat-gold/10 border border-teyvat-gold/30 flex items-center justify-center text-lg shrink-0" aria-hidden="true">
                                🧠
                            </div>
                            <div
                                class="min-w-0 flex-1 text-slate-200"
                                x-html="formatAIRecommendation(msg.content)"
                            ></div>
                        </div>
                    </template>

                    <template x-if="msg.role === 'user'">
                        <div class="bg-teyvat-gold text-slate-950 font-medium ml-12 rounded-2xl px-5 py-4 text-sm sm:text-base shadow-md leading-relaxed whitespace-pre-wrap" x-text="msg.content"></div>
                    </template>

                </div>

            </template>


            <!-- Chat Loading -->

            <template x-if="loadingChat && !chatStreamStarted">

                <div class="flex justify-start">

                    <div class="bg-slate-900 border border-slate-800 rounded-2xl px-5 py-4 text-sm text-slate-400">

                        <div class="flex items-center space-x-2">

                            <div class="w-1.5 h-1.5 rounded-full bg-teyvat-gold animate-bounce"></div>

                            <div class="w-1.5 h-1.5 rounded-full bg-teyvat-gold animate-bounce [animation-delay:0.2s]"></div>

                            <div class="w-1.5 h-1.5 rounded-full bg-teyvat-gold animate-bounce [animation-delay:0.4s]"></div>

                            <span class="ml-2 font-mono text-[11px]">
                                Sedang menyiapkan jawaban...
                            </span>

                        </div>

                        <div class="mt-2 text-[9px] text-slate-600">
                            Mohon tunggu sebentar.
                        </div>

                    </div>

                </div>

            </template>

        </div>


        <!-- Chat Input -->

        <div class="p-3.5 border-t border-slate-800 bg-slate-900/90">

            <form
                @submit.prevent="sendMessage()"
                class="flex items-center space-x-2"
            >

                <input
                    type="text"
                    x-model="chatInput"
                    placeholder="Tanya build, senjata, artefak, ER, rotasi..."
                    class="flex-1 bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-sm sm:text-base text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teyvat-gold transition"
                >

                <button
                    type="submit"
                    :disabled="loadingChat || !chatInput.trim()"
                    class="px-5 py-3 rounded-xl bg-teyvat-gold hover:bg-amber-400 disabled:opacity-50 text-slate-950 font-bold font-cinzel text-sm sm:text-base transition"
                >
                    Kirim
                </button>

            </form>

        </div>

    </div>

</main>


<!-- ========================================================= -->
<!-- FOOTER -->
<!-- ========================================================= -->

<footer class="border-t border-teyvat-border py-4 px-6 text-center text-xs text-slate-500 bg-slate-950">

    <p>
        Genshin Build AI • Laravel 12 • NVIDIA NIM • Nemotron
    </p>

</footer>


<!-- ========================================================= -->
<!-- ALPINE APPLICATION -->
<!-- ========================================================= -->

<script>

    function genshinApp() {

        return {

            /* =================================================
             * STATE
             * ================================================= */

            characters: [],

            weaponCatalog: [],

            artifactCatalog: [],

            genshinApiBase: '',

            selectedSlug: 'furina',

            constellation: 0,

            contentMode: 'abyss',

            preferredRole: '',

            teamSlugs: [
                'neuvillette',
                'kazuha',
                'zhongli'
            ],

            activeCharData: null,

            activeResonances: [],

            triggeredReactions: [],

            buildResult: null,

            loadingBuild: false,

            loadingChat: false,

            chatStreamStarted: false,

            chatInput: '',

            chatCharacter: null,

            sessionToken: '',

            chatMessages: [

                {
                    role: 'assistant',

                    content:
                        'Halo Traveler! 👋\n\n' +
                        'Saya adalah Genshin Build Advisor.\n\n' +
                        'Pilih karakter dan tim untuk membuat rekomendasi build, atau tanyakan tentang senjata, artefak, ER, rotasi, maupun team composition.'
                }

            ],


            /* =================================================
             * INIT
             * ================================================= */

            async initApp() {

                this.genshinApiBase =
                    this.$el.dataset.genshinApiBase || '';

                this.sessionToken =
                    localStorage.getItem('genshin_session')
                    ||
                    (
                        'session_' +
                        Math.random()
                            .toString(36)
                            .substring(2, 12)
                    );

                localStorage.setItem(
                    'genshin_session',
                    this.sessionToken
                );

                await Promise.all([
                    this.loadEntityCatalogs(),
                    this.loadCharacters()
                ]);

                await this.onCharacterChange();

            },

            async loadEntityCatalogs() {

                const loadCatalog = async (entity, property) => {
                    const url = `${this.genshinApiBase}/${entity}/all`;

                    try {
                        const response = await fetch(url, {
                            headers: { 'Accept': 'application/json' }
                        });

                        if (!response.ok) {
                            throw new Error(`HTTP ${response.status}`);
                        }

                        const data = await response.json();
                        if (!Array.isArray(data)) {
                            throw new Error('API returned an invalid entity catalog.');
                        }

                        this[property] = data.filter(item =>
                            item
                            && typeof item.id === 'string'
                            && typeof item.name === 'string'
                        );
                    } catch (error) {
                        console.error(`Gagal mengambil katalog ${entity}:`, error);
                    }
                };

                await Promise.all([
                    loadCatalog('weapons', 'weaponCatalog'),
                    loadCatalog('artifacts', 'artifactCatalog')
                ]);
            },

            characterBySlug(slug) {
                return this.characters.find(character => character.slug === slug) || null;
            },

            buildTeamMembers() {
                if (!this.buildResult?.character) {
                    return [];
                }

                return [
                    this.buildResult.character,
                    ...(this.buildResult.teammates || [])
                ];
            },

            escapeHtml(value) {
                return String(value ?? '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            },

            normalizeEntityName(value) {
                return String(value ?? '')
                    .toLowerCase()
                    .replace(/&(?:amp|lt|gt|quot|#0*39);/g, ' ')
                    .replace(/[^a-z0-9]+/g, ' ')
                    .trim();
            },

            findMentionedEntities(entities, content) {
                const normalizedContent = ` ${this.normalizeEntityName(content)} `;

                return entities.filter(entity => {
                    const searchKeys = [entity.name, entity.slug]
                        .filter(Boolean)
                        .map(value => this.normalizeEntityName(value));

                    return searchKeys.some(normalizedName =>
                        normalizedName
                        && normalizedContent.includes(` ${normalizedName} `)
                    );
                });
            },

            characterIconUrl(character) {
                if (character?.icon_url) {
                    return character.icon_url;
                }

                if (!this.genshinApiBase || !character?.slug) {
                    return '';
                }

                return `${this.genshinApiBase}/characters/${encodeURIComponent(character.slug)}/icon-big`;
            },

            entityAssetUrl(type, entity) {
                if (!this.genshinApiBase || !entity?.id) {
                    return '';
                }

                const id = encodeURIComponent(entity.id);
                const assetPath = type === 'weapon'
                    ? `weapons/${id}/icon`
                    : `artifacts/${id}/flower-of-life`;

                return `${this.genshinApiBase}/${assetPath}`;
            },

            renderEntityAssetCard(entity, type) {
                const name = this.escapeHtml(entity.name);
                const imageUrl = type === 'character'
                    ? this.characterIconUrl(entity)
                    : this.entityAssetUrl(type, entity);
                const rarity = type === 'character'
                    ? entity.rarity
                    : (type === 'weapon' ? entity.rarity : entity.max_rarity);
                const subtitle = type === 'weapon'
                    ? [entity.type || 'Weapon', entity.subStat, rarity ? `${rarity}★` : '']
                        .filter(Boolean)
                        .join(' · ')
                    : type === 'artifact'
                        ? `Artifact Set${rarity ? ` · ${rarity}★` : ''}`
                        : `${entity.vision || ''}${entity.weapon_type ? ` · ${entity.weapon_type}` : ''}`;
                const image = imageUrl
                    ? `<img src="${this.escapeHtml(imageUrl)}" alt="${name}" loading="lazy" decoding="async" class="entity-asset-image" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><div class="entity-asset-unavailable" hidden>Asset unavailable</div>`
                    : '<div class="entity-asset-unavailable">Asset unavailable</div>';

                return `
                    <div class="entity-asset-card">
                        <div class="entity-asset-frame entity-asset-${type}">${image}</div>
                        <div class="entity-asset-name">${name}</div>
                        <div class="entity-asset-detail">${this.escapeHtml(subtitle)}</div>
                    </div>
                `;
            },

            renderSectionEntityAssets(section) {
                const content = `${section.title}\n${section.content.join('\n')}`;
                const title = this.normalizeEntityName(section.title);
                let entities = [];
                let type = '';

                if (title.includes('weapon') || title.includes('senjata')) {
                    entities = this.findMentionedEntities(this.weaponCatalog, content);
                    type = 'weapon';
                } else if (title.includes('artifact') || title.includes('artefak')) {
                    entities = this.findMentionedEntities(this.artifactCatalog, content);
                    type = 'artifact';
                } else if (title.includes('team') || title.includes('tim')) {
                    entities = this.findMentionedEntities(this.characters, content);
                    type = 'character';
                }

                if (!entities.length) {
                    return '';
                }

                return `<div class="entity-asset-grid">${entities
                    .map(entity => this.renderEntityAssetCard(entity, type))
                    .join('')}</div>`;
            },


            /* =================================================
             * LOAD CHARACTERS
             * ================================================= */

            async loadCharacters() {

                try {

                    const res = await fetch(
                        '/api/characters',
                        {
                            headers: {
                                'Accept': 'application/json'
                            }
                        }
                    );

                    if (!res.ok) {

                        throw new Error(
                            'HTTP ' + res.status
                        );

                    }

                    const json = await res.json();

                    if (json.success && json.data) {

                        this.characters = json.data.map(character => ({
                            ...character,
                            icon_url: this.characterIconUrl(character)
                        }));

                        if (
                            this.characters.length > 0
                            &&
                            !this.characters.some(
                                c => c.slug === this.selectedSlug
                            )
                        ) {

                            this.selectedSlug =
                                this.characters[0].slug;

                        }

                    }

                } catch (e) {

                    console.error(
                        'Gagal mengambil daftar karakter:',
                        e
                    );

                }

            },


            /* =================================================
             * CHARACTER CHANGE
             * ================================================= */

            async onCharacterChange() {

                this.activeCharData =
                    this.characters.find(
                        c => c.slug === this.selectedSlug
                    )
                    ||
                    null;

                await this.onTeamOrContentChange();

            },


            /* =================================================
             * TEAM ANALYSIS
             * ================================================= */

            async onTeamOrContentChange() {

                const currentTeam = [
                    this.selectedSlug,
                    ...this.teamSlugs.filter(
                        s =>
                            s &&
                            s !== this.selectedSlug
                    )
                ];

                try {

                    const res = await fetch(
                        '/api/team/analyze',
                        {
                            method: 'POST',

                            headers: {
                                'Content-Type':
                                    'application/json',

                                'Accept':
                                    'application/json'
                            },

                            body: JSON.stringify({
                                characters: currentTeam
                            })
                        }
                    );

                    if (!res.ok) {

                        throw new Error(
                            'HTTP ' + res.status
                        );

                    }

                    const json =
                        await res.json();

                    if (json.success) {

                        this.activeResonances =
                            json.resonances || [];

                        this.triggeredReactions =
                            json.reactions || [];

                    }

                } catch (e) {

                    console.error(
                        'Gagal analisis tim:',
                        e
                    );

                }

            },


            /* =================================================
             * GENERATE BUILD
             * ================================================= */

            async generateBuild() {

                if (this.loadingBuild) {
                    return;
                }

                this.loadingBuild = true;

                /*
                 * Hapus hasil lama agar pengguna tahu
                 * bahwa build baru sedang dibuat.
                 */

                this.buildResult = null;

                try {

                    const payload = {

                        character:
                            this.selectedSlug,

                        constellation:
                            this.constellation,

                        team:
                            this.teamSlugs.filter(
                                s =>
                                    s &&
                                    s !== this.selectedSlug
                            ),

                        content_mode:
                            this.contentMode,

                        preferred_role:
                            this.preferredRole.trim() || null,

                    };


                    console.log(
                        '[BUILD] Mengirim request:',
                        payload
                    );


                    const res = await fetch(
                        '/api/build/recommend',
                        {
                            method: 'POST',

                            headers: {
                                'Content-Type':
                                    'application/json',

                                'Accept':
                                    'application/json'
                            },

                            body:
                                JSON.stringify(payload)
                        }
                    );


                    /*
                     * Tangani HTTP error
                     * sebelum mencoba JSON.
                     */

                    if (!res.ok) {

                        const errorText =
                            await res.text();

                        console.error(
                            '[BUILD] HTTP Error:',
                            res.status,
                            errorText
                        );

                        throw new Error(
                            'Server mengembalikan HTTP ' +
                            res.status
                        );

                    }


                    const json =
                        await res.json();


                    console.log(
                        '[BUILD] Response:',
                        json
                    );


                    if (
                        json.success &&
                        json.data
                    ) {

                        this.buildResult =
                            json.data;


                        /*
                         * Scroll ke hasil.
                         */

                        this.$nextTick(() => {

                            const result =
                                document.querySelector(
                                    '[x-show="buildResult"]'
                                );

                            if (result) {

                                result.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });

                            }

                        });

                    } else {

                        throw new Error(
                            json.message
                            ||
                            'Gagal menghasilkan build.'
                        );

                    }


                } catch (e) {

                    console.error(
                        '[BUILD] Error:',
                        e
                    );

                    alert(
                        'Gagal menghasilkan rekomendasi.\n\n' +
                        e.message
                    );

                } finally {

                    this.loadingBuild = false;

                }

            },


            /* =================================================
             * FORMAT AI RECOMMENDATION
             * ================================================= */

            formatAIRecommendation(text) {

                if (!text) {
                    return '';

                }


                /*
                 * Escape HTML terlebih dahulu.
                 * Ini penting karena kita menggunakan x-html.
                 */

                let safe =
                    String(text)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');


                /*
                 * Markdown sederhana.
                 */

                safe =
                    safe.replace(
                        /\*\*(.*?)\*\*/g,
                        '<strong>$1</strong>'
                    );


                safe =
                    safe.replace(
                        /`([^`]+)`/g,
                        '<span class="ai-highlight">$1</span>'
                    );


                /*
                 * Pecah berdasarkan heading Markdown.
                 */

                const lines =
                    safe.split(/\r?\n/);


                const sections = [];

                let current = {
                    title: 'AI Analysis',
                    content: []
                };

                const flushSection = () => {

                    if (
                        current.content.length > 0
                        ||
                        sections.length === 0
                    ) {

                        sections.push({
                            ...current
                        });

                    }

                };


                for (
                    let i = 0;
                    i < lines.length;
                    i++
                ) {

                    let line =
                        lines[i].trim();


                    /*
                     * Heading:
                     * # Weapon
                     * ## Weapon
                     */

                    const heading =
                        line.match(
                            /^#{1,4}\s+(.+)$/
                        );


                    if (heading) {

                        flushSection();


                        const title =
                            heading[1]
                                .replace(
                                    /[*_]/g,
                                    ''
                                )
                                .trim();


                        current = {

                            title:
                                title ||
                                'AI Analysis',

                            content: []

                        };

                        continue;

                    }


                    /*
                     * Horizontal separator.
                     */

                    if (
                        /^[-_=]{3,}$/.test(line)
                    ) {

                        continue;

                    }


                    /*
                     * Bullet list.
                     */

                    if (
                        /^[-*•]\s+/.test(line)
                    ) {

                        const bullet =
                            line
                                .replace(
                                    /^[-*•]\s+/,
                                    ''
                                );

                        current.content.push(
                            '• ' + bullet
                        );

                        continue;

                    }


                    /*
                     * Numbered list.
                     */

                    if (
                        /^\d+\.\s+/.test(line)
                    ) {

                        current.content.push(
                            line
                        );

                        continue;

                    }


                    if (line !== '') {

                        current.content.push(
                            line
                        );

                    }

                }


                flushSection();


                /*
                 * Convert content tiap section
                 * menjadi HTML.
                 */

                return sections
                    .map(section => {

                        let content =
                            section.content
                                .join('\n');


                        /*
                         * Kelompokkan bullet.
                         */

                        const contentLines =
                            content.split('\n');


                        let html = '';

                        let inList = false;


                        contentLines.forEach(
                            line => {

                                if (
                                    line.startsWith('• ')
                                ) {

                                    if (!inList) {

                                        html +=
                                            '<ul>';

                                        inList = true;

                                    }

                                    html +=
                                        '<li>' +
                                        line.substring(2) +
                                        '</li>';

                                } else {

                                    if (inList) {

                                        html +=
                                            '</ul>';

                                        inList = false;

                                    }


                                    if (
                                        line.trim() !== ''
                                    ) {

                                        html +=
                                            '<p>' +
                                            line +
                                            '</p>';

                                    }

                                }

                            }
                        );


                        if (inList) {
                            html += '</ul>';
                        }


                        return `

                            <section class="ai-section">

                                <div class="ai-section-title">
                                    <span>${section.title}</span>
                                </div>

                                <div class="ai-content">
                                    ${html}
                                </div>

                                ${this.renderSectionEntityAssets(section)}

                            </section>

                        `;

                    })
                    .join('');

            },


            /* =================================================
             * QUICK PROMPT
             * ================================================= */

            sendQuickPrompt(text) {

                this.chatInput = text;

                this.sendMessage();

            },


            /* =================================================
             * CHAT
             * ================================================= */

            async sendMessage() {

                const msg =
                    this.chatInput.trim();


                if (
                    !msg ||
                    this.loadingChat
                ) {

                    return;

                }


                this.chatMessages.push({

                    role: 'user',

                    content: msg

                });


                this.chatInput = '';

                this.loadingChat = true;

                this.chatStreamStarted = false;

                this.chatMessages.push({

                    role: 'assistant',

                    content: ''

                });

                const assistantMessage =
                    this.chatMessages[this.chatMessages.length - 1];

                this.scrollChat();

                let activeChatReader = null;

                try {

                    const res =
                        await fetch(
                            '/api/chat/stream',
                            {
                                method: 'POST',

                                headers: {

                                    'Content-Type':
                                        'application/json',

                                    'Accept':
                                        'application/json, text/event-stream'

                                },

                                body:
                                    JSON.stringify({

                                        session_token:
                                            this.sessionToken,

                                        message:
                                            msg,

                                        ...(this.chatCharacter
                                            ? { character: this.chatCharacter }
                                            : {})

                                    })

                            }
                        );


                    /*
                     * Jangan langsung res.json().
                     * Kalau Laravel mengembalikan HTML error,
                     * res.json() akan melempar error yang tidak jelas.
                     */

                    if (!res.ok) {

                        const errorText =
                            await res.text();

                        console.error(
                            '[CHAT] HTTP Error:',
                            res.status,
                            errorText
                        );

                        throw new Error(
                            'Server mengembalikan HTTP ' +
                            res.status
                        );

                    }

                    if (!res.body) {
                        throw new Error(
                            'Browser tidak menyediakan response stream.'
                        );
                    }

                    const reader = res.body.getReader();
                    activeChatReader = reader;
                    const decoder = new TextDecoder();
                    const eventSeparator = /\r\n\r\n|\n\n|\r\r/;
                    let buffer = '';
                    let streamFinished = false;

                    const processEvent = eventText => {
                        const dataLines = eventText
                            .split(/\r\n|\n|\r/)
                            .filter(line => line.startsWith('data:'))
                            .map(line => line.slice(5).replace(/^\s+/, ''));

                        if (dataLines.length === 0) {
                            return false;
                        }

                        const dataText = dataLines.join('\n');

                        if (dataText === '[DONE]') {
                            return true;
                        }

                        const event = JSON.parse(dataText);

                        if (event.type === 'error') {
                            throw new Error(
                                event.message || 'Chat stream gagal.'
                            );
                        }

                        if (event.type === 'build_result') {
                            console.debug('[CHAT] Ignoring build_result event to keep chatbot state isolated from Generate Build state.');
                            return false;
                        }

                        if (
                            event.type === 'chunk' &&
                            typeof event.content === 'string'
                        ) {
                            assistantMessage.content += event.content;
                            this.chatStreamStarted = true;
                            this.scrollChat();
                        }

                        return false;
                    };

                    while (true) {
                        const { value, done } = await reader.read();

                        if (done) {
                            break;
                        }

                        buffer += decoder.decode(value, { stream: true });
                        let separatorMatch;

                        while ((separatorMatch = eventSeparator.exec(buffer)) !== null) {
                            const separatorPosition = separatorMatch.index;
                            const separatorLength = separatorMatch[0].length;
                            const eventText = buffer.slice(0, separatorPosition);
                            buffer = buffer.slice(separatorPosition + separatorLength);

                            if (processEvent(eventText)) {
                                streamFinished = true;
                                await reader.cancel();
                                break;
                            }
                        }

                        if (streamFinished) {
                            break;
                        }
                    }

                    buffer += decoder.decode();

                    if (!streamFinished && buffer.trim() !== '') {
                        streamFinished = processEvent(buffer);
                    }

                    if (!streamFinished) {
                        throw new Error(
                            'Chat stream berakhir sebelum event DONE diterima.'
                        );
                    }

                    if (assistantMessage.content.trim() === '') {
                        assistantMessage.content =
                            'AI tidak mengembalikan teks.';
                    }

                } catch (e) {

                    console.error(
                        '[CHAT] Error:',
                        e
                    );

                    if (activeChatReader) {
                        try {
                            await activeChatReader.cancel();
                        } catch (cancelError) {
                            console.debug(
                                '[CHAT] Reader cancellation failed:',
                                cancelError
                            );
                        }
                    }


                    const errorMessage =
                        '⚠️ Terjadi kendala saat menghubungi AI. ' +
                        e.message;

                    assistantMessage.content = assistantMessage.content
                        ? assistantMessage.content + '\n\n' + errorMessage
                        : errorMessage;

                } finally {

                    this.loadingChat = false;

                    this.scrollChat();

                }

            },


            /* =================================================
             * CLEAR CHAT
             * ================================================= */

            clearChat() {

                this.chatMessages = [

                    {

                        role:
                            'assistant',

                        content:
                            '🔄 Riwayat chat telah direset.\n\n' +
                            'Silakan tanyakan kembali mengenai build, senjata, artefak, ER, atau rotasi tim.'

                    }

                ];

            },


            /* =================================================
             * SCROLL CHAT
             * ================================================= */

            scrollChat() {

                this.$nextTick(() => {

                    const container =
                        document.getElementById(
                            'chatContainer'
                        );

                    if (container) {

                        container.scrollTop =
                            container.scrollHeight;

                    }

                });

            }

        };

    }

</script>

</body>
</html>
