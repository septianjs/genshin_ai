<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genshin Build Karakter - Build karakter anda dengan disini </title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Alpine.js CDN -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        [x-cloak] { display: none !important; }
        .genshin-glass {
            background: rgba(18, 24, 36, 0.75);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(229, 193, 88, 0.2);
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
    </style>
</head>
<body class="bg-teyvat-bg text-slate-100 min-h-screen flex flex-col font-sans" x-data="genshinApp()" x-init="initApp()">

    <!-- Top Navigation Bar -->
    <header class="border-b border-teyvat-border bg-teyvat-card/80 backdrop-blur-md sticky top-0 z-50 px-4 lg:px-8 py-3.5 flex items-center justify-between shadow-xl">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-teyvat-gold to-amber-600 flex items-center justify-center shadow-lg shadow-amber-500/20 text-slate-950 font-bold font-cinzel text-xl">
                ✦
            </div>
            <div>
                <h1 class="text-lg lg:text-xl font-bold font-cinzel text-teyvat-gold tracking-wide">
                    GENSHIN BUILD KARAKTER
                </h1>
                <p class="text-xs text-slate-400">Build Karakter Anda dengan Mudah</p>
            </div>
        </div>
    </header>

    <!-- Main Content Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto p-4 lg:p-6 grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- Left / Center Column: Builder & Recommendation Cards (7 cols) -->
        <div class="lg:col-span-7 flex flex-col space-y-6">

            <!-- Step 1: Character & Constellation Selection -->
            <div class="genshin-glass rounded-2xl p-5 shadow-2xl">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-800">
                    <h2 class="text-base font-bold font-cinzel text-teyvat-gold flex items-center">
                        <span class="w-2 h-2 rounded-full bg-teyvat-gold mr-2"></span>
                        1. PILIH KARAKTER UTAMA & KONSTELASI
                    </h2>
                    <span class="text-xs text-slate-400" x-text="characters.length + ' Karakter Tersedia'"></span>
                </div>

                <!-- Character Dropdown & Search -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Karakter yang ingin di-Build:</label>
                        <select x-model="selectedSlug" @change="onCharacterChange()" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-sm text-slate-100 focus:outline-none focus:border-teyvat-gold transition">
                            <template x-for="char in characters" :key="char.slug">
                                <option :value="char.slug" x-text="char.name + ' (' + char.vision + ' - ' + char.rarity + '★)'"></option>
                            </template>
                        </select>
                    </div>

                    <!-- Target Content Mode Selector -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-300 mb-1.5">Target Konten / Tujuan Build:</label>
                        <select x-model="contentMode" @change="onTeamOrContentChange()" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2.5 text-sm text-slate-100 focus:outline-none focus:border-teyvat-gold transition">
                            <option value="abyss">⚔️ Spiral Abyss (Floor 11-12 / Min-Max)</option>
                            <option value="theater">🎭 Imaginarium Theater (Generalist / Independent)</option>
                            <option value="overworld">🗺️ Overworld & Story (Skill QoL / Eksplorasi)</option>
                            <option value="boss">👑 Boss Farming & Domains (Single Target Nuke)</option>
                        </select>
                    </div>
                </div>

                <!-- Constellation Selector (C0 to C6) -->
                <div>
                    <label class="block text-xs font-semibold text-slate-300 mb-2">Tingkat Konstelasi:</label>
                    <div class="grid grid-cols-7 gap-1.5">
                        <template x-for="c in [0,1,2,3,4,5,6]" :key="c">
                            <button type="button" 
                                    @click="constellation = c; onTeamOrContentChange()"
                                    :class="constellation === c ? 'bg-teyvat-gold text-slate-950 font-bold shadow-lg shadow-amber-500/30' : 'bg-slate-900/80 text-slate-300 border border-slate-800 hover:border-slate-600'"
                                    class="py-2 rounded-lg text-xs font-medium transition text-center">
                                <span x-text="'C' + c"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Step 2: Team Composition Builder (4 Slots) -->
            <div class="genshin-glass rounded-2xl p-5 shadow-2xl">
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-800">
                    <h2 class="text-base font-bold font-cinzel text-teyvat-gold flex items-center">
                        <span class="w-2 h-2 rounded-full bg-teyvat-gold mr-2"></span>
                        2. KOMPOSISI TIM (4 KARAKTER)
                    </h2>
                    <span class="text-xs text-slate-400">Pilih 3 rekan tim untuk buff & reaksi</span>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                    <!-- Slot 1: Active Target Character -->
                    <div class="bg-slate-900/90 border-2 border-teyvat-gold/60 rounded-xl p-3 flex flex-col items-center text-center relative overflow-hidden">
                        <div class="text-[10px] font-bold uppercase tracking-wider text-teyvat-gold mb-1">Target Build</div>
                        <div class="w-14 h-14 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center font-bold text-sm text-slate-200 overflow-hidden mb-1.5">
                            <template x-if="activeCharData?.icon_url">
                                <img :src="activeCharData.icon_url" class="w-full h-full object-cover" :alt="activeCharData?.name">
                            </template>
                            <template x-if="!activeCharData?.icon_url">
                                <span x-text="activeCharData?.name?.substring(0, 2) || '?'"></span>
                            </template>
                        </div>
                        <span class="font-bold text-xs text-slate-100 truncate w-full" x-text="activeCharData?.name || selectedSlug"></span>
                        <span class="text-[10px] text-slate-400 font-mono" x-text="'C' + constellation + ' • ' + (activeCharData?.vision || '')"></span>
                    </div>

                    <!-- Slot 2, 3, 4: Teammates -->
                    <template x-for="(tmSlug, index) in teamSlugs" :key="index">
                        <div class="bg-slate-900/60 border border-slate-800 rounded-xl p-3 flex flex-col items-center justify-between text-center relative">
                            <span class="text-[10px] font-medium text-slate-400 mb-1" x-text="'Slot ' + (index + 2)"></span>
                            <select x-model="teamSlugs[index]" @change="onTeamOrContentChange()" class="w-full bg-slate-950 border border-slate-800 rounded-lg text-[11px] text-slate-200 p-1.5 focus:outline-none">
                                <option value="">(Kosong)</option>
                                <template x-for="char in characters" :key="char.slug">
                                    <option :value="char.slug" x-text="char.name" :selected="char.slug === tmSlug"></option>
                                </template>
                            </select>
                            <span class="text-[9px] text-slate-500 mt-1">Rekan Tim</span>
                        </div>
                    </template>
                </div>

                <!-- Live Elemental Resonances & Reactions Indicators -->
                <div class="bg-slate-950/70 border border-slate-800/80 rounded-xl p-3.5 space-y-2.5">
                    <div>
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Resonansi Elemen Aktif:</span>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-if="activeResonances.length === 0">
                                <span class="text-xs text-slate-500 italic">Pilih minimal 2 elemen sejenis untuk memicu resonansi.</span>
                            </template>
                            <template x-for="res in activeResonances" :key="res.name">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-amber-950/40 border border-teyvat-gold/40 text-teyvat-gold">
                                    ✦ <span class="ml-1" x-text="res.name + ': ' + res.description"></span>
                                </span>
                            </template>
                        </div>
                    </div>

                    <div>
                        <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block mb-1.5">Reaksi Elemen yang Terpicu:</span>
                        <div class="flex flex-wrap gap-1.5">
                            <template x-if="triggeredReactions.length === 0">
                                <span class="text-xs text-slate-500 italic">Pilih rekan tim untuk mendeteksi reaksi elemental.</span>
                            </template>
                            <template x-for="rx in triggeredReactions" :key="rx.reaction">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-blue-950/50 border border-blue-600/40 text-blue-300">
                                    <span x-text="rx.reaction + ' (' + rx.category + ')'"></span>
                                </span>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="mt-4">
                    <button type="button" 
                            @click="generateBuild()"
                            :disabled="loadingBuild"
                            class="w-full py-3 rounded-xl bg-gradient-to-r from-teyvat-gold via-amber-400 to-amber-500 hover:from-amber-400 hover:to-teyvat-gold text-slate-950 font-bold font-cinzel text-sm tracking-wide shadow-xl shadow-amber-500/20 transition transform active:scale-[0.99] flex items-center justify-center space-x-2">
                        <template x-if="!loadingBuild">
                            <span class="flex items-center">
                                ✦ GENERATE REKOMENDASI BUILD AI ✦
                            </span>
                        </template>
                        <template x-if="loadingBuild">
                            <span class="flex items-center">
                                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-slate-950" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                                Menganalisis Mekanik & Menghubungi Nemotron...
                            </span>
                        </template>
                    </button>
                </div>
            </div>

            <!-- Step 3: Visual Build Card Output -->
            <div x-show="buildResult" x-cloak class="genshin-glass rounded-2xl p-6 shadow-2xl border-teyvat-gold/40">
                <div class="flex items-center justify-between pb-4 border-b border-slate-800 mb-4">
                    <div>
                        <span class="text-xs uppercase tracking-wider text-teyvat-gold font-bold">Hasil Rekomendasi Terpadu</span>
                        <h3 class="text-xl font-bold font-cinzel text-slate-100" x-text="(buildResult?.character?.name || '') + ' (C' + (buildResult?.character?.constellation ?? 0) + ')'"></h3>
                    </div>
                    <span class="text-xs px-2.5 py-1 rounded bg-slate-800 border border-slate-700 text-slate-300 font-mono" x-text="buildResult?.model || 'Nemotron 3.5'"></span>
                </div>

                <!-- Structured Mechanics Summary -->
                <template x-if="buildResult?.mechanics?.role_shift">
                    <div class="mb-4 p-3 rounded-xl bg-amber-950/30 border border-amber-500/30 text-xs text-amber-200">
                        <strong>Peran & Pengaruh Konstelasi:</strong> <span x-text="buildResult.mechanics.role_shift"></span>
                    </div>
                </template>

                <!-- Markdown Content rendered with clean styling -->
                <div class="prose prose-invert max-w-none text-xs sm:text-sm text-slate-300 leading-relaxed space-y-3 whitespace-pre-wrap font-sans bg-slate-950/50 p-4 rounded-xl border border-slate-800/80 custom-scroll max-h-[500px] overflow-y-auto" x-text="buildResult?.ai_recommendation">
                </div>
            </div>

        </div>

        <!-- Right Column: Interactive Chatbot Assistant (5 cols) -->
        <div class="lg:col-span-5 flex flex-col h-[750px] genshin-glass rounded-2xl shadow-2xl overflow-hidden">
            <!-- Chatbot Header -->
            <div class="p-4 border-b border-slate-800 bg-slate-900/80 flex items-center justify-between">
                <div class="flex items-center space-x-2.5">
                    <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-teyvat-gold to-amber-600 flex items-center justify-center text-slate-950 font-bold text-xs shadow-md">
                        AI
                    </div>
                    <div>
                        <h3 class="text-sm font-bold text-slate-100 font-cinzel">GENSHIN BUILD ADVISOR</h3>
                        <p class="text-[10px] text-emerald-400 flex items-center">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 mr-1 animate-pulse"></span>
                            Online (Nemotron 3.5 Lightning)
                        </p>
                    </div>
                </div>
                <button @click="clearChat()" class="text-[11px] text-slate-400 hover:text-teyvat-gold transition">
                    Reset Chat
                </button>
            </div>

            <!-- Quick Chips / Prompts -->
            <div class="px-4 py-2.5 bg-slate-950/40 border-b border-slate-800/60 flex items-center space-x-2 overflow-x-auto text-[11px] custom-scroll">
                <span class="text-slate-500 shrink-0 font-medium">Tanya Cepat:</span>
                <button @click="sendQuickPrompt('Alternatif senjata F2P terbaik jika tidak punya senjata bintang 5?')" class="px-2.5 py-1 rounded-full bg-slate-800/80 border border-slate-700 text-slate-300 hover:border-teyvat-gold shrink-0 transition">
                    Senjata F2P?
                </button>
                <button @click="sendQuickPrompt('Berapa target Energy Recharge (ER) minimal untuk rotasi lancar di tim ini?')" class="px-2.5 py-1 rounded-full bg-slate-800/80 border border-slate-700 text-slate-300 hover:border-teyvat-gold shrink-0 transition">
                    Target ER?
                </button>
                <button @click="sendQuickPrompt('Jelaskan urutan rotasi skill dan cara memicu reaksi damage terbesar.')" class="px-2.5 py-1 rounded-full bg-slate-800/80 border border-slate-700 text-slate-300 hover:border-teyvat-gold shrink-0 transition">
                    Rotasi Tim?
                </button>
            </div>

            <!-- Chat Messages Scroll Area -->
            <div class="flex-1 p-4 overflow-y-auto space-y-4 custom-scroll" id="chatContainer">
                <template x-for="(msg, idx) in chatMessages" :key="idx">
                    <div :class="msg.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                        <div :class="msg.role === 'user' ? 'bg-teyvat-gold text-slate-950 font-medium ml-12' : 'bg-slate-900 border border-slate-800 text-slate-200 mr-12'" class="rounded-2xl px-4 py-3 text-xs sm:text-sm shadow-md leading-relaxed whitespace-pre-wrap">
                            <span x-text="msg.content"></span>
                        </div>
                    </div>
                </template>

                <template x-if="loadingChat">
                    <div class="flex justify-start">
                        <div class="bg-slate-900 border border-slate-800 rounded-2xl px-4 py-3 text-xs text-slate-400 flex items-center space-x-2">
                            <div class="w-1.5 h-1.5 rounded-full bg-teyvat-gold animate-bounce"></div>
                            <div class="w-1.5 h-1.5 rounded-full bg-teyvat-gold animate-bounce [animation-delay:0.2s]"></div>
                            <div class="w-1.5 h-1.5 rounded-full bg-teyvat-gold animate-bounce [animation-delay:0.4s]"></div>
                            <span class="ml-2 font-mono text-[11px]">Nemotron sedang menyusun strategi...</span>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Chat Input Form -->
            <div class="p-3.5 border-t border-slate-800 bg-slate-900/90">
                <form @submit.prevent="sendMessage()" class="flex items-center space-x-2">
                    <input type="text" 
                           x-model="chatInput" 
                           placeholder="Ketik pertanyaan (cth: 'bagusan the catch atau favonius buat raiden?')" 
                           class="flex-1 bg-slate-950 border border-slate-700 rounded-xl px-4 py-2.5 text-xs sm:text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:border-teyvat-gold transition">
                    <button type="submit" 
                            :disabled="loadingChat || !chatInput.trim()"
                            class="px-4 py-2.5 rounded-xl bg-teyvat-gold hover:bg-amber-400 disabled:opacity-50 text-slate-950 font-bold font-cinzel text-xs sm:text-sm transition flex items-center justify-center">
                        Kirim
                    </button>
                </form>
            </div>
        </div>

    </main>

    <!-- Footer -->
    <footer class="border-t border-teyvat-border py-4 px-6 text-center text-xs text-slate-500 bg-slate-950">
        <p>Genshin Build AI • Dibangun dengan Laravel 12, RAG Architecture, dan NVIDIA NIM (Nemotron 3.5 Lightning & Nemotron 3 Embed).</p>
    </footer>

    <!-- Alpine.js Application Logic -->
    <script>
        function genshinApp() {
            return {
                characters: [],
                selectedSlug: 'furina',
                constellation: 0,
                contentMode: 'abyss',
                teamSlugs: ['neuvillette', 'kazuha', 'zhongli'],
                activeCharData: null,
                activeResonances: [],
                triggeredReactions: [],
                buildResult: null,
                loadingBuild: false,
                chatMessages: [
                    {
                        role: 'assistant',
                        content: 'Halo Traveler! Saya adalah Genshin Build Advisor. Pilih karakter dan tim di panel sebelah kiri untuk menghasilkan kartu build meta, atau tanyakan apa saja seputar rotasi, ER, artefak, dan senjata di sini!'
                    }
                ],
                chatInput: '',
                loadingChat: false,
                sessionToken: '',

                async initApp() {
                    this.sessionToken = localStorage.getItem('genshin_session') || ('session_' + Math.random().toString(36).substring(2, 12));
                    localStorage.setItem('genshin_session', this.sessionToken);

                    await this.loadCharacters();
                    await this.onCharacterChange();
                },

                async loadCharacters() {
                    try {
                        const res = await fetch('/api/characters');
                        const json = await res.json();
                        if (json.success && json.data) {
                            this.characters = json.data;
                            if (this.characters.length > 0 && !this.characters.some(c => c.slug === this.selectedSlug)) {
                                this.selectedSlug = this.characters[0].slug;
                            }
                        }
                    } catch (e) {
                        console.error('Gagal mengambil daftar karakter:', e);
                    }
                },

                async onCharacterChange() {
                    this.activeCharData = this.characters.find(c => c.slug === this.selectedSlug) || null;
                    await this.onTeamOrContentChange();
                },

                async onTeamOrContentChange() {
                    const currentTeam = [this.selectedSlug, ...this.teamSlugs.filter(s => s && s !== this.selectedSlug)];
                    try {
                        const res = await fetch('/api/team/analyze', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ characters: currentTeam })
                        });
                        const json = await res.json();
                        if (json.success) {
                            this.activeResonances = json.resonances || [];
                            this.triggeredReactions = json.reactions || [];
                        }
                    } catch (e) {
                        console.error('Gagal analisis tim:', e);
                    }
                },

                async generateBuild() {
                    this.loadingBuild = true;
                    try {
                        const payload = {
                            character: this.selectedSlug,
                            constellation: this.constellation,
                            team: this.teamSlugs.filter(s => s && s !== this.selectedSlug),
                            content_mode: this.contentMode,
                        };

                        const res = await fetch('/api/build/recommend', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });

                        const json = await res.json();
                        if (json.success && json.data) {
                            this.buildResult = json.data;
                        } else {
                            alert(json.message || 'Gagal menghasilkan build.');
                        }
                    } catch (e) {
                        console.error('Gagal generate build:', e);
                        alert('Terjadi kesalahan saat memproses rekomendasi.');
                    } finally {
                        this.loadingBuild = false;
                    }
                },

                sendQuickPrompt(text) {
                    this.chatInput = text;
                    this.sendMessage();
                },

                async sendMessage() {
                    const msg = this.chatInput.trim();
                    if (!msg || this.loadingChat) return;

                    this.chatMessages.push({ role: 'user', content: msg });
                    this.chatInput = '';
                    this.loadingChat = true;
                    this.scrollChat();

                    try {
                        const res = await fetch('/api/chat/send', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                session_token: this.sessionToken,
                                message: msg,
                                character: this.selectedSlug
                            })
                        });

                        const json = await res.json();
                        if (json.success && json.message) {
                            this.chatMessages.push({
                                role: 'assistant',
                                content: json.message.content
                            });
                            // Jika ada build data yang relevan, perbarui kartu build otomatis
                            if (json.build_data && !this.buildResult) {
                                this.buildResult = json.build_data;
                            }
                        } else {
                            this.chatMessages.push({
                                role: 'assistant',
                                content: 'Maaf, terjadi kendala komunikasi dengan layanan AI.'
                            });
                        }
                    } catch (e) {
                        console.error('Chat error:', e);
                        this.chatMessages.push({
                            role: 'assistant',
                            content: 'Koneksi ke server gagal. Pastikan aplikasi Laravel sedang berjalan.'
                        });
                    } finally {
                        this.loadingChat = false;
                        this.scrollChat();
                    }
                },

                clearChat() {
                    this.chatMessages = [
                        {
                            role: 'assistant',
                            content: 'Riwayat chat telah direset. Ada yang ingin Anda tanyakan mengenai build karakter atau rotasi tim?'
                        }
                    ];
                },

                scrollChat() {
                    this.$nextTick(() => {
                        const container = document.getElementById('chatContainer');
                        if (container) {
                            container.scrollTop = container.scrollHeight;
                        }
                    });
                }
            }
        }
    </script>
</body>
</html>
