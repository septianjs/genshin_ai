<!DOCTYPE html>
<html lang="id" class="dark">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Avalon</title>

<!-- Konfigurasi Tailwind harus tersedia sebelum CDN dimuat -->
<script>
    window.tailwind = window.tailwind || {};

    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    sans: ['Plus Jakarta Sans', 'sans-serif'],
                    cinzel: ['Cinzel', 'serif']
                },
                colors: {
                    teyvat: {
                        bg: '#080b12',
                        card: '#111722',
                        card2: '#151c29',
                        border: '#202a3a',
                        gold: '#e5c158',
                        goldHover: '#f5d67b'
                    }
                }
            }
        }
    };
</script>

<script src="https://cdn.tailwindcss.com"></script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<link
    href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
    rel="stylesheet"
>

@vite(['resources/css/app.css', 'resources/js/app.js'])

<!-- Alpine dimuat setelah entry point aplikasi -->
<script
    defer
    src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"
></script>

</head>


<body

    class="
        h-dvh
        w-full
        overflow-hidden
        text-slate-100
        font-sans
        flex
        flex-col
    "

    x-data="genshinApp()"

    x-init="initApp()"

    data-genshin-api-base="{{
        rtrim(
            config(
                'services.genshin.third_party_url',
                'https://genshin.jmp.blue'
            ),
            '/'
        )
    }}"

>


    <!-- =========================================================
        HEADER
        ========================================================= -->

    <header>

        <div>

            <!-- LOGO -->

            <div class="flex items-center gap-2.5 min-w-0">

                <div
                    class="
                        w-8
                        h-8
                        rounded-md
                        bg-gradient-to-tr
                        from-teyvat-gold
                        to-amber-600
                        flex
                        items-center
                        justify-center
                        text-slate-950
                        font-bold
                        text-base
                        shadow-lg
                        shadow-amber-500/10
                    "
                >
                    ✦
                </div>


                <div class= "min-w-0">

                    <h1
                        class="
                            font-cinzel
                            font-bold
                            text-xs
                            sm:text-sm
                            text-teyvat-gold
                            tracking-wide
                            whitespace-nowrap
                        "
                    >
                        Avalon
                    </h1>
                </div>

            </div>
        </div>

    </header>


    <!--=========================================================
        APPLICATION
        ========================================================= -->

    <main
        class="
            flex-1
            min-h-0
            w-full
            overflow-hidden
        "
    >

        <section
            class="
                relative
                h-full
                min-h-0
                flex
                flex-col
            "
        >
                
            <!-- CHAT CONTAINER -->
            <div
                id="chatContainer"
                class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden chat-scroll px-3 lg:px-4 py-3"
            >
                <div class="w-full max-w-[760px] mx-auto space-y-3 pb-2">

                    <!-- SAPAAN AWAL: TAMPIL JIKA BELUM ADA PESAN -->
                    <div
                        x-show="chatMessages.length === 0"
                        x-cloak
                        class="py-6"
                    >
                        <div class="flex items-start gap-2">
                            <!-- IKON AI -->
                            <div
                                class="w-6 h-6 mt-0.5 rounded-md bg-teyvat-gold/10 border border-teyvat-gold/25 flex items-center justify-center text-[11px] text-teyvat-gold shrink-0"
                            >
                                ✦
                            </div>

                            <!-- KONTEN SAPAAN -->
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-1.5 mb-1">
                                    <span class="text-[10px] font-bold text-slate-300">
                                        Avalon1
                                    </span>
                                    <span class="text-[10px] text-slate-600">•</span>
                                    <span class="text-[10px] text-slate-500">
                                        Teyvat Assistant
                                    </span>
                                </div>

                                <div class="message-ai">
                                    <p class="mb-2">
                                        Halo Traveler! 👋
                                    </p>

                                    <p>
                                        Sayar, weapon, artifact, talent,
                                        constellation, reaction, team, atau mechanics
                                        Genshin Impact.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DAFTAR PESAN CHAT -->
                    <template
                        x-for="(msg, index) in chatMessages"
                        :key="index"
                    >
                        <div>

                            <!-- PESAN USER -->
                            <template x-if="msg.role === 'user'">
                                <div class="flex justify-end mb-3">
                                    <div class="message-user min-w-0 max-w-[85%]">
                                        <div
                                            class="break-words whitespace-pre-wrap"
                                            x-text="msg.content"
                                        ></div>
                                    </div>
                                </div>
                            </template>

                            <!-- PESAN ASSISTANT -->
                            <template x-if="msg.role === 'assistant'">
                                <div class="flex items-start gap-2 mb-3">

                                        <!-- JAWABAN AI -->
                                        <div
                                            class="message-ai"
                                            x-html="formatAssistantResponse(
                                                msg.content,
                                                msg.sourceQuery || ''
                                            )"
                                        ></div>

                                    </div>
                                </div>
                            </template>

                        </div>
                    </template>

                    <!-- INDIKATOR LOADING -->
                    <template x-if="loadingChat && !chatStreamStarted">
                        <div class="flex items-start gap-2 mb-3">



                            <!-- KONTEN LOADING -->
                            <div class="min-w-0 flex-1">

                                <!-- ANIMASI LOADING -->
                                <div class="message-ai">
                                    <div class="flex items-center gap-1.5 py-1.5">

                                        <span
                                            class="w-1.5 h-1.5 rounded-full bg-teyvat-gold animate-bounce"
                                            style="animation-delay: 0ms"
                                        ></span>

                                        <span
                                            class="w-1.5 h-1.5 rounded-full bg-teyvat-gold animate-bounce"
                                            style="animation-delay: 150ms"
                                        ></span>

                                        <span
                                            class="w-1.5 h-1.5 rounded-full bg-teyvat-gold animate-bounce"
                                            style="animation-delay: 300ms"
                                        ></span>

                                        <span class="ml-1 text-[11px] text-slate-500">
                                            Ava Sedang Menyiapkan jawaban...
                                        </span>

                                    </div>
                                </div>

                            </div>
                        </div>
                    </template>

                </div>
            </div>

           
                    </template>


                    <!-- =================================================
                        LOADING
                        ================================================= -->

                    <template
                        x-if="
                            loadingChat &&
                            !chatStreamStarted
                        "
                    >

                        <div
                            class="
                                flex
                                items-start
                                gap-2
                                mb-2
                            "
                        >

                            <div
                                class="
                                    w-6
                                    h-6
                                    rounded-md
                                    bg-teyvat-gold/10
                                    flex
                                    items-center
                                    justify-center
                                    text-[11px]
                                    text-teyvat-gold
                                    shrink-0
                                "
                            >
                            
                            </div>


                            <div class="message-ai">

                                <div
                                    class="
                                        flex
                                        items-center
                                        gap-1.5
                                        py-1.5
                                    "
                                >

                                    <span
                                        class="
                                            loading-dot
                                            w-1.5
                                            h-1.5
                                            rounded-full
                                            bg-teyvat-gold
                                        "
                                    ></span>

                                    <span
                                        class="
                                            loading-dot
                                            w-1.5
                                            h-1.5
                                            rounded-full
                                            bg-teyvat-gold
                                        "
                                    ></span>

                                    <span
                                        class="
                                            loading-dot
                                            w-1.5
                                            h-1.5
                                            rounded-full
                                            bg-teyvat-gold
                                        "
                                    ></span>


                                    <span
                                        class="
                                            ml-1
                                            text-[11px]
                                            text-slate-500
                                        "
                                    >
                                    </span>

                                </div>

                            </div>

                        </div>

                    </template>

                </div>

            </div>

<!-- BOTTOM CHAT COMPOSER -->
<div
    class="w-full"
    :class="chatMessages.length === 0
        ? 'absolute left-0 right-0 top-1/2 -translate-y-1/2'
        : 'relative mt-auto'"
>
    <div class="chat-composer-inner px-3 sm:px-5 pt-3 pb-3">
        <form
            @submit.prevent="sendMessage()"
            class="chat-composer-form"
        >
            <textarea
                x-model="chatInput"
                @keydown.enter="if (!$event.shiftKey) {
                    $event.preventDefault();
                    if (!loadingChat && chatInput.trim()) sendMessage();
                }"
                placeholder="Build apa hari ini"
                rows="1"
                class="chat-composer-input"
                aria-label="Tulis pesan"
            ></textarea>

            <button
                type="submit"
                class="chat-composer-button"
                :disabled="loadingChat || !chatInput.trim()"
                aria-label="Kirim pesan"
                title="Kirim pesan"
            >
                <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="21"
                    height="21"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M22 2 11 13"></path>
                    <path d="m22 2-7 20-4-9-9-4Z"></path>
                </svg>
            </button>
        </form>
    </div>
</div>
</section>
</main>
</body>
</html>

