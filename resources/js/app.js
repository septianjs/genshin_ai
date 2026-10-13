import './bootstrap';

function genshinApp() {

    return {

        /* =================================================
         * DATA
         * ================================================= */

        characters: [],
        weaponCatalog: [],
        artifactCatalog: [],

        genshinApiBase: '',
        sessionToken: '',

        chatInput: '',
        loadingChat: false,
        chatStreamStarted: false,
        chatCharacter: null,

        chatMessages: [
            {
                role: 'assistant',
                content:

                    'Saya Genshin AI Assistant. Tanyakan apa saja mengenai karakter, weapon, artifact, talent, constellation, reaction, team, atau mechanics Genshin Impact.',
                isGreeting: true,
                sourceQuery: ''
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
                ('session_' + Math.random().toString(36).substring(2, 12));

            localStorage.setItem('genshin_session', this.sessionToken);

            await Promise.all([
                this.loadCharacters(),
                this.loadEntityCatalogs(),
                this.loadHistory()
            ]);

            this.scrollChat();
        },
        /* =================================================
         * LOAD CHARACTERS
         * ================================================= */

                
/* =================================================
 * LOAD CHAT HISTORY
 * ================================================= */

    async loadHistory() {

        try {

            const response = await fetch(
                '/api/chat/history/' + encodeURIComponent(this.sessionToken),
                {
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const json = await response.json();

            if (
                json.success &&
                Array.isArray(json.messages) &&
                json.messages.length > 0
            ) {

                this.chatMessages = json.messages.map(message => ({
                    ...message,
                    isGreeting: false,
                    sourceQuery: ''
                }));

                console.debug(
                    '[CHAT] Riwayat berhasil dimuat:',
                    this.chatMessages.length,
                    'pesan'
                );

            } else {

                console.debug('[CHAT] Belum ada riwayat percakapan.');

            }

        } catch (error) {

            console.error(
                '[CHAT] Gagal memuat riwayat percakapan:',
                error
            );

        }

    },
        async loadCharacters() {

            try {

                const response = await fetch('/api/characters', {
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const json = await response.json();

                if (json.success && Array.isArray(json.data)) {

                    this.characters = json.data.map(character => ({
                        ...character,
                        icon_url: this.characterIconUrl(character)
                    }));

                    console.debug('[UI] Characters loaded:', this.characters.length);

                }

            } catch (error) {

                console.error('[UI] Gagal memuat karakter:', error);

            }

        },


        /* =================================================
         * LOAD WEAPONS + ARTIFACTS
         * ================================================= */

        async loadEntityCatalogs() {

            const loadCatalog = async (entity, property) => {

                if (!this.genshinApiBase) {
                    return;
                }

                try {

                    const response = await fetch(
                        `${this.genshinApiBase}/${entity}/all`,
                        { headers: { 'Accept': 'application/json' } }
                    );

                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    const data = await response.json();

                    if (!Array.isArray(data)) {
                        return;
                    }

                    this[property] = data.filter(item =>
                        item &&
                        typeof item.id === 'string' &&
                        typeof item.name === 'string'
                    );

                    console.debug(`[UI] ${entity} loaded:`, this[property].length);

                } catch (error) {

                    console.error(`[UI] Gagal memuat ${entity}:`, error);

                }

            };

            await Promise.all([
                loadCatalog('weapons', 'weaponCatalog'),
                loadCatalog('artifacts', 'artifactCatalog')
            ]);

        },


        /* =================================================
         * ESCAPE HTML
         * ================================================= */

        escapeHtml(value) {

            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');

        },


        /* =================================================
         * NORMALIZE ENTITY
         * ================================================= */

        normalizeEntityName(value) {

            return String(value ?? '')
                .toLowerCase()
                .replace(/&(?:amp|lt|gt|quot|#0*39);/g, ' ')
                .replace(/[^a-z0-9]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();

        },


        /* =================================================
         * WORD BOUNDARY
         * ================================================= */

        entityNameMatches(normalizedContent, normalizedName) {

            if (!normalizedName || !normalizedContent) {
                return false;
            }

            const escaped = normalizedName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

            const regex = new RegExp(`(^|\\s)${escaped}(?=\\s|$)`, 'i');

            return regex.test(normalizedContent);

        },


        /* =================================================
         * FIND ENTITIES
         * ================================================= */

        findMentionedEntities(entities, content) {

            if (!Array.isArray(entities) || !content) {
                return [];
            }

            const normalizedContent = this.normalizeEntityName(content);

            if (!normalizedContent) {
                return [];
            }

            const found = [];
            const seen = new Set();

            for (const entity of entities) {

                if (!entity || !entity.name) {
                    continue;
                }

                const candidates = [entity.name, entity.slug]
                    .filter(Boolean)
                    .map(value => this.normalizeEntityName(value))
                    .filter(Boolean);

                const matched = candidates.some(candidate =>
                    this.entityNameMatches(normalizedContent, candidate)
                );

                if (!matched) {
                    continue;
                }

                const uniqueKey = String(
                    entity.id || entity.slug || entity.name
                ).toLowerCase();

                if (seen.has(uniqueKey)) {
                    continue;
                }

                seen.add(uniqueKey);

                found.push(entity);

            }

            return found;

        },


        /* =================================================
         * DEDUP CHARACTER
         * ================================================= */

        deduplicateCharacters(characters) {

            if (!Array.isArray(characters)) {
                return [];
            }

            const result = [];
            const seenNames = new Set();

            for (const character of characters) {

                if (!character) {
                    continue;
                }

                const normalizedName = this.normalizeEntityName(character.name);

                if (seenNames.has(normalizedName)) {
                    continue;
                }

                seenNames.add(normalizedName);

                result.push(character);

            }

            return result;

        },


        /* =================================================
         * CHARACTER IMAGE
         * ================================================= */

        characterIconUrl(character) {

            if (character?.icon_url) {
                return character.icon_url;
            }

            if (!this.genshinApiBase || !character?.slug) {
                return '';
            }

            return `${this.genshinApiBase}/characters/${encodeURIComponent(character.slug)}/icon-big`;

        },


        /* =================================================
         * ENTITY IMAGE
         * ================================================= */

        entityAssetUrl(type, entity) {

            if (!this.genshinApiBase || !entity?.id) {
                return '';
            }

            const id = encodeURIComponent(entity.id);

            if (type === 'weapon') {
                return `${this.genshinApiBase}/weapons/${id}/icon`;
            }

            if (type === 'artifact') {
                return `${this.genshinApiBase}/artifacts/${id}/flower-of-life`;
            }

            return '';

        },


        /* =================================================
         * IMAGE TAG
         *
         * Jika gambar gagal dimuat, <img> dihapus sehingga
         * kotak latar di belakangnya tampil sebagai
         * placeholder (bukan ikon gambar rusak).
         * ================================================= */

        renderImageTag(src, alt) {

            return `
                <img
                    src="${this.escapeHtml(src)}"
                    alt="${this.escapeHtml(alt)}"
                    loading="lazy"
                    decoding="async"
                    onerror="this.remove()"
                >
            `;

        },


        /* =================================================
         * CHARACTER CARD
         * ================================================= */

        renderCharacterCard(character) {

            if (!character) {
                return '';
            }

            const image = this.characterIconUrl(character);

            return `

                <div class="character-card">

                    <div class="character-card-image">

                        ${
                            image
                                ? this.renderImageTag(image, character.name)
                                : `
                                    <div class="w-full h-full flex items-center justify-center text-center text-[10px] text-slate-600">
                                        No image
                                    </div>
                                `
                        }

                    </div>


                    <div class="min-w-0">

                        <div class="flex flex-wrap gap-1 mb-1">

                            ${
                                character.vision
                                    ? `
                                        <span class="px-1.5 py-0.5 rounded bg-blue-950/40 border border-blue-800/40 text-blue-300 text-[10px] font-bold">
                                            ${this.escapeHtml(character.vision)}
                                        </span>
                                    `
                                    : ''
                            }

                            ${
                                character.rarity
                                    ? `
                                        <span class="px-1.5 py-0.5 rounded bg-slate-900 border border-slate-800 text-teyvat-gold text-[10px] font-bold">
                                            ${this.escapeHtml(character.rarity)}★
                                        </span>
                                    `
                                    : ''
                            }

                        </div>


                        <div class="text-[13px] font-bold text-slate-100 font-cinzel truncate">
                            ${this.escapeHtml(character.name)}
                        </div>


                        <div class="mt-0.5 text-[11px] text-slate-500">
                            ${this.escapeHtml(character.weapon_type || '')}
                        </div>

                    </div>

                </div>

            `;

        },


        /* =================================================
         * ENTITY GRID
         * ================================================= */

        renderEntityGrid(entities, type) {

            if (!Array.isArray(entities) || !entities.length) {
                return '';
            }

            const limited = entities.slice(0, 4);

            const title =
                type === 'weapon'
                    ? 'Weapon'
                    : type === 'artifact'
                        ? 'Artifact'
                        : 'Team';

            let body = '';

            if (type === 'character') {

                body = `

                    <div class="team-grid">

                        ${limited.map(character => {

                            const image = this.characterIconUrl(character);

                            return `

                                <div class="team-card">

                                    <div class="team-image">
                                        ${image ? this.renderImageTag(image, character.name) : ''}
                                    </div>

                                    <div class="team-name">
                                        ${this.escapeHtml(character.name)}
                                    </div>

                                </div>

                            `;

                        }).join('')}

                    </div>

                `;

            } else {

                body = `

                    <div class="entity-grid">

                        ${limited.map(entity => {

                            const image = this.entityAssetUrl(type, entity);

                            const detail =
                                type === 'weapon'
                                    ? [
                                        entity.type,
                                        entity.subStat,
                                        entity.rarity ? `${entity.rarity}★` : ''
                                    ].filter(Boolean).join(' · ')
                                    : [
                                        'Artifact Set',
                                        entity.max_rarity ? `${entity.max_rarity}★` : ''
                                    ].filter(Boolean).join(' · ');

                            return `

                                <div class="entity-card">

                                    <div class="entity-image">
                                        ${image ? this.renderImageTag(image, entity.name) : ''}
                                    </div>

                                    <div class="entity-name">
                                        ${this.escapeHtml(entity.name)}
                                    </div>

                                    <div class="entity-detail">
                                        ${this.escapeHtml(detail)}
                                    </div>

                                </div>

                            `;

                        }).join('')}

                    </div>

                `;

            }

            return `

                <div class="assistant-card">

                    <div class="assistant-card-header">

                        <span class="text-[11px]">✦</span>

                        <span class="assistant-card-title">
                            ${title}
                        </span>

                    </div>

                    ${body}

                </div>

            `;

        },


        /* =================================================
         * TALENT CARD
         * ================================================= */

        renderTalentCard(content) {

            const lower = content.toLowerCase();

            if (
                !lower.includes('talent') &&
                !lower.includes('normal attack') &&
                !lower.includes('elemental skill') &&
                !lower.includes('elemental burst') &&
                !lower.includes('prioritas skill')
            ) {
                return '';
            }

            const talents = [];

            if (lower.includes('normal attack')) {
                talents.push('Normal Attack');
            }

            if (lower.includes('elemental skill')) {
                talents.push('Elemental Skill');
            }

            if (lower.includes('elemental burst')) {
                talents.push('Elemental Burst');
            }

            if (!talents.length) {
                return '';
            }

            return `

                <div class="assistant-card">

                    <div class="assistant-card-header">

                        <span>⚔️</span>

                        <span class="assistant-card-title">Talent</span>

                    </div>


                    <div class="talent-list">

                        ${talents.map(talent => `

                            <div class="talent-row">

                                <span class="talent-name">
                                    ${this.escapeHtml(talent)}
                                </span>

                                <span class="talent-rank">
                                    Discussed
                                </span>

                            </div>

                        `).join('')}

                    </div>

                </div>

            `;

        },


        /* =================================================
         * STATS CARD
         * ================================================= */

        renderStatsCard(content) {

            const lower = content.toLowerCase();

            const stats = [];

            const possibleStats = [
                ['CRIT Rate', 'crit rate'],
                ['CRIT DMG', 'crit dmg'],
                ['Elemental Mastery', 'elemental mastery'],
                ['ATK%', 'atk%'],
                ['HP%', 'hp%'],
                ['Energy Recharge', 'energy recharge'],
                ['Hydro DMG', 'hydro dmg'],
                ['Pyro DMG', 'pyro dmg'],
                ['Cryo DMG', 'cryo dmg'],
                ['Electro DMG', 'electro dmg'],
                ['Dendro DMG', 'dendro dmg'],
                ['Anemo DMG', 'anemo dmg'],
                ['Geo DMG', 'geo dmg']
            ];

            possibleStats.forEach(([label, key]) => {

                if (lower.includes(key)) {
                    stats.push(label);
                }

            });

            if (!stats.length) {
                return '';
            }

            return `

                <div class="assistant-card">

                    <div class="assistant-card-header">

                        <span>📊</span>

                        <span class="assistant-card-title">Stats</span>

                    </div>


                    <div class="stats-grid">

                        ${stats.slice(0, 8).map(stat => `

                            <div class="stat-chip">
                                ${this.escapeHtml(stat)}
                            </div>

                        `).join('')}

                    </div>

                </div>

            `;

        },


        /* =================================================
         * GREETING
         * ================================================= */

        isGreetingMessage(text) {

            const normalized = this.normalizeEntityName(text);

            if (!normalized) {
                return true;
            }

            return (
                normalized.includes('genshin ai assistant')
                &&
                normalized.includes('tanyakan apa saja')
            );

        },


        /* =================================================
         * FORMAT ASSISTANT RESPONSE
         * ================================================= */

        formatAssistantResponse(text, sourceQuery = '') {

            if (!text) {
                return '';
            }

            const rawText = String(text);

            const queryText = String(sourceQuery || '').trim();

            /*
             * Greeting / reset tidak menghasilkan
             * character card dari isi jawaban.
             */

            if (this.isGreetingMessage(rawText) || !queryText) {

                return `

                    <div class="ai-text">
                        ${this.formatPlainText(rawText)}
                    </div>

                `;

            }


            /* =================================================
             * TEXT FORMAT
             * ================================================= */

            let safe = this.escapeHtml(rawText);

            safe = safe.replace(
                /\*\*(.*?)\*\*/g,
                '<strong>$1</strong>'
            );

            safe = safe.replace(
                /`([^`]+)`/g,
                '<span class="ai-highlight">$1</span>'
            );

            safe = safe.replace(
                /\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g,
                '<a href="$2" target="_blank" rel="noopener noreferrer" class="ai-link">$1</a>'
            );

            const lines = safe.split(/\r?\n/);

            let html = '';
            let listOpen = false;

            const closeList = () => {

                if (listOpen) {
                    html += '</ul>';
                    listOpen = false;
                }

            };

            lines.forEach(rawLine => {

                const line = rawLine.trim();

                if (!line) {
                    closeList();
                    return;
                }

                const heading = line.match(/^#{1,4}\s+(.+)$/);

                if (heading) {

                    closeList();

                    html += `

                        <div class="mt-2 mb-1">

                            <div class="text-[11px] uppercase tracking-widest text-teyvat-gold font-bold">
                                ${heading[1]}
                            </div>

                        </div>

                    `;

                    return;

                }

                if (/^[-*•]\s+/.test(line)) {

                    if (!listOpen) {
                        html += '<ul>';
                        listOpen = true;
                    }

                    html += `<li>${line.replace(/^[-*•]\s+/, '')}</li>`;

                    return;

                }

                closeList();

                html += `<p>${line}</p>`;

            });

            closeList();


            /* =================================================
             * QUERY ENTITY
             *
             * Karakter utama selalu dipilih dari
             * pertanyaan user terlebih dahulu.
             * ================================================= */

            const queryCharacters = this.deduplicateCharacters(
                this.findMentionedEntities(this.characters, queryText)
            );

            const answerCharacters = this.deduplicateCharacters(
                this.findMentionedEntities(this.characters, rawText)
            );

            const characterMap = new Map();

            [...queryCharacters, ...answerCharacters].forEach(character => {

                const key = String(
                    character.id || character.slug || character.name || ''
                ).toLowerCase();

                if (key && !characterMap.has(key)) {
                    characterMap.set(key, character);
                }

            });

            const mentionedCharacters = [...characterMap.values()];

            /*
             * Karakter utama HARUS berasal dari query.
             */

            const mainCharacter = queryCharacters[0] || null;

            const queryLower = queryText.toLowerCase();

            const wantsBuildOrTeam =
                /\b(build|team|tim|komposisi|party|squad)\b/i.test(queryLower);

            const wantsWeapon =
                /\b(build|weapon|senjata|sword|bow|polearm|catalyst|claymore)\b/i.test(queryLower);

            const wantsArtifact =
                /\b(build|artifact|artefak|set)\b/i.test(queryLower);

            /*
             * Karakter lain hanya menjadi team
             * jika query memang meminta build/team.
             */

            const teamCharacters = mentionedCharacters.filter(character => {

                if (!mainCharacter) {
                    return true;
                }

                const mainKey = String(
                    mainCharacter.id || mainCharacter.slug || mainCharacter.name
                ).toLowerCase();

                const currentKey = String(
                    character.id || character.slug || character.name
                ).toLowerCase();

                return mainKey !== currentKey;

            });

            const teamCard =
                wantsBuildOrTeam && teamCharacters.length
                    ? this.renderEntityGrid(teamCharacters, 'character')
                    : '';

            /*
             * Weapon hanya ditampilkan jika
             * query relevan dengan weapon/build.
             */

            const mentionedWeapons =
                wantsWeapon
                    ? this.findMentionedEntities(this.weaponCatalog, rawText)
                    : [];

            /*
             * Artifact hanya ditampilkan jika
             * query relevan dengan artifact/build.
             */

            const mentionedArtifacts =
                wantsArtifact
                    ? this.findMentionedEntities(this.artifactCatalog, rawText)
                    : [];

            const characterCard =
                mainCharacter
                    ? this.renderCharacterCard(mainCharacter)
                    : '';

            const weaponCard =
                mentionedWeapons.length
                    ? this.renderEntityGrid(mentionedWeapons, 'weapon')
                    : '';

            const artifactCard =
                mentionedArtifacts.length
                    ? this.renderEntityGrid(mentionedArtifacts, 'artifact')
                    : '';

            const talentCard = this.renderTalentCard(rawText);

            const statsCard = this.renderStatsCard(rawText);

            let richCards = '';

            if (characterCard) {
                richCards += characterCard;
            }

            if (teamCard || talentCard) {

                richCards += `

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-1.5 mt-1.5">
                        ${teamCard}
                        ${talentCard}
                    </div>

                `;

            }

            if (weaponCard || artifactCard) {

                richCards += `

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-1.5 mt-1.5">
                        ${weaponCard}
                        ${artifactCard}
                    </div>

                `;

            }

            if (statsCard) {

                richCards += `

                    <div class="mt-1.5">
                        ${statsCard}
                    </div>

                `;

            }

            return `

                <div class="ai-text">

                    ${richCards}

                    <div class="mt-1">
                        ${html}
                    </div>

                </div>

            `;

        },


        /* =================================================
         * PLAIN TEXT
         * ================================================= */

        formatPlainText(text) {

            return this.escapeHtml(text).replace(/\r?\n/g, '<br>');

        },


        /* =================================================
         * QUICK PROMPT
         * ================================================= */

        sendQuickPrompt(text) {

            this.chatInput = text;

            this.sendMessage();

        },


        /* =================================================
         * SEND MESSAGE
         * ================================================= */

        async sendMessage() {

            const msg = this.chatInput.trim();

            if (!msg || this.loadingChat) {
                return;
            }

            /*
             * USER MESSAGE
             */

            this.chatMessages.push({
                role: 'user',
                content: msg
            });

            this.chatInput = '';

            this.loadingChat = true;

            this.chatStreamStarted = false;

            /*
             * ASSISTANT PLACEHOLDER
             *
             * Pertanyaan asli disimpan di sourceQuery.
             */

            this.chatMessages.push({
                role: 'assistant',
                content: '',
                sourceQuery: msg
            });

            const assistantMessage =
                this.chatMessages[this.chatMessages.length - 1];

            this.scrollChat();

            let activeChatReader = null;

            try {

                const response = await fetch('/api/chat/stream', {

                    method: 'POST',

                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json, text/event-stream'
                    },

                    body: JSON.stringify({

                        session_token: this.sessionToken,

                        message: msg,

                        ...(this.chatCharacter
                            ? { character: this.chatCharacter }
                            : {})

                    })

                });

                if (!response.ok) {

                    const errorText = await response.text();

                    console.error('[CHAT] HTTP error:', response.status, errorText);

                    throw new Error('Server mengembalikan HTTP ' + response.status);

                }

                if (!response.body) {

                    throw new Error('Browser tidak menyediakan response stream.');

                }

                const reader = response.body.getReader();

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

                    let event;

                    try {

                        event = JSON.parse(dataText);

                    } catch (parseError) {

                        console.error('[CHAT] Invalid SSE event:', dataText);

                        return false;

                    }

                    if (event.type === 'error') {

                        throw new Error(event.message || 'Chat stream gagal.');

                    }

                    /*
                     * Build result lama sengaja diabaikan.
                     */

                    if (event.type === 'build_result') {

                        console.debug('[CHAT] build_result ignored.');

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


                /* =================================================
                 * READ SSE
                 * ================================================= */

                while (true) {

                    const { value, done } = await reader.read();

                    if (done) {
                        break;
                    }

                    buffer += decoder.decode(value, { stream: true });

                    let separatorMatch;

                    while ((separatorMatch = eventSeparator.exec(buffer)) !== null) {

                        const position = separatorMatch.index;

                        const separatorLength = separatorMatch[0].length;

                        const eventText = buffer.slice(0, position);

                        buffer = buffer.slice(position + separatorLength);

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

                /*
                 * EMPTY RESPONSE
                 */

                if (assistantMessage.content.trim() === '') {

                    assistantMessage.content = '⚠️ AI tidak mengembalikan jawaban.';

                }

            } catch (error) {

                console.error('[CHAT] Error:', error);

                if (activeChatReader) {

                    try {

                        await activeChatReader.cancel();

                    } catch (cancelError) {

                        console.debug('[CHAT] Reader cancellation failed:', cancelError);

                    }

                }

                const errorMessage =
                    '⚠️ Terjadi kendala saat menghubungi AI. ' +
                    error.message;

                assistantMessage.content =
                    assistantMessage.content
                        ? assistantMessage.content + '\n\n' + errorMessage
                        : errorMessage;

            } finally {

                this.loadingChat = false;

                this.scrollChat();

            }

        },


        /* =================================================
         * RESET
         * ================================================= */
        clearChat() {

            // Buat sesi baru tanpa menghapus riwayat lama di database.
            this.sessionToken =
                'session_' + Math.random().toString(36).substring(2, 12);

            localStorage.setItem('genshin_session', this.sessionToken);

            this.chatMessages = [
                {
                    role: 'assistant',
                    content:
                        'Percakapan baru telah dimulai.\n\n' +
                        'Silakan tanyakan sesuatu tentang Genshin Impact.',
                    isGreeting: true,
                    sourceQuery: ''
                }
            ];

            this.chatInput = '';
            this.chatStreamStarted = false;

            this.$nextTick(() => {
                this.scrollChat();
            });

        },
        /* =================================================
         * SCROLL
         * ================================================= */

        scrollChat() {

            this.$nextTick(() => {

                const container = document.getElementById('chatContainer');

                if (container) {
                    container.scrollTop = container.scrollHeight;
                }

            });

        }

    };

}

window.genshinApp = genshinApp;