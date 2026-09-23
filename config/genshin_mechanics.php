<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Genshin Elemental Resonances Matrix (Data Tervalidasi)
    |--------------------------------------------------------------------------
    |
    | Konfigurasi ini memetakan buff resonansi elemen tanpa hardcode di logika
    | service, sehingga mudah diperbarui jika terjadi balance change pada patch.
    |
    */

    'resonances' => [
        'HYDRO' => [
            'name' => 'Soothing Water',
            'required_count' => 2,
            'description' => 'Affected by Pyro for 40% less time. Increases Max HP by 25%.',
            'buffs' => [
                'max_hp_percent' => 25,
                'pyro_duration_reduction' => 40,
            ],
            'stat_influences' => [
                'beneficiary_scalings' => ['HP'],
                'note' => 'Meningkatkan scaling damage karakter berbasis HP dan menurunkan kebutuhan substat HP% pada artefak.',
            ],
        ],
        'PYRO' => [
            'name' => 'Fervent Flames',
            'required_count' => 2,
            'description' => 'Affected by Cryo for 40% less time. Increases ATK by 25%.',
            'buffs' => [
                'atk_percent' => 25,
                'cryo_duration_reduction' => 40,
            ],
            'stat_influences' => [
                'beneficiary_scalings' => ['ATK'],
                'note' => 'Meningkatkan base ATK tim, optimal untuk DPS berbasis Attack.',
            ],
        ],
        'CRYO' => [
            'name' => 'Shattering Ice',
            'required_count' => 2,
            'description' => 'Affected by Electro for 40% less time. Increases CRIT Rate against enemies that are Frozen or affected by Cryo by 15%.',
            'buffs' => [
                'crit_rate_bonus' => 15,
                'electro_duration_reduction' => 40,
            ],
            'stat_influences' => [
                'note' => 'Memberikan +15% CRIT Rate, mencegah overcap CRIT Rate jika dipasangkan dengan 4pc Blizzard Strayer.',
            ],
        ],
        'ELECTRO' => [
            'name' => 'High Voltage',
            'required_count' => 2,
            'description' => 'Affected by Hydro for 40% less time. Superconduct, Overloaded, Electro-Charged, Quicken, Aggravate, or Hyperbloom have a 100% chance to generate an Electro Elemental Particle (CD: 5s).',
            'buffs' => [
                'particle_drop_chance' => 100,
                'particle_cooldown_seconds' => 5,
                'hydro_duration_reduction' => 40,
            ],
            'stat_influences' => [
                'er_reduction_percent' => 15,
                'note' => 'Menghasilkan partikel energi ekstra saat reaksi Electro aktif, menurunkan target ER tim sebesar ~15%.',
            ],
        ],
        'DENDRO' => [
            'name' => 'Sprawling Greenery',
            'required_count' => 2,
            'description' => 'Elemental Mastery increased by 50. After triggering Burning, Quicken, or Bloom: all party members gain 30 EM for 6s. After triggering Aggravate, Spread, Hyperbloom, or Burgeon: all party members gain 20 EM for 6s.',
            'buffs' => [
                'base_em' => 50,
                'reaction_em_tier_1' => 30,
                'reaction_em_tier_2' => 20,
                'max_total_em' => 100,
            ],
            'stat_influences' => [
                'note' => 'Memberikan 50 hingga 100 Elemental Mastery, sangat penting untuk tim berbasis reaksi Dendro.',
            ],
        ],
        'GEO' => [
            'name' => 'Enduring Rock',
            'required_count' => 2,
            'description' => 'Shield strength increased by 15%. Characters protected by a shield have DMG dealt increased by 15%, and dealing DMG to enemies will decrease their Geo RES by 20% for 15s.',
            'buffs' => [
                'shield_strength' => 15,
                'shielded_dmg_bonus' => 15,
                'geo_res_shred' => 20,
            ],
            'stat_influences' => [
                'note' => 'Meningkatkan ketahanan shield dan memberikan +15% DMG dealt serta -20% Geo RES shred.',
            ],
        ],
        'ANEMO' => [
            'name' => 'Impetuous Winds',
            'required_count' => 2,
            'description' => 'Decreases Stamina Consumption by 15%. Increases Movement SPD by 10%. Shortens Skill CD by 5%.',
            'buffs' => [
                'stamina_reduction' => 15,
                'movement_speed' => 10,
                'skill_cd_reduction' => 5,
            ],
            'stat_influences' => [
                'note' => 'Meningkatkan kenyamanan eksplorasi dan mempercepat siklus rotasi skill tim sebesar 5%.',
            ],
        ],
        'UNIQUE' => [
            'name' => 'Protective Canopy',
            'required_unique_elements' => 4,
            'description' => 'All Elemental RES +15%, Physical RES +15%.',
            'buffs' => [
                'all_elemental_res' => 15,
                'physical_res' => 15,
            ],
            'stat_influences' => [
                'note' => 'Meningkatkan ketahanan defensif tim terhadap seluruh jenis elemen dan serangan fisik.',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Genshin Elemental Reactions Matrix
    |--------------------------------------------------------------------------
    */

    'reactions' => [
        'vaporize' => [
            'name' => 'Vaporize',
            'category' => 'amplifying',
            'required_elements' => ['HYDRO', 'PYRO'],
            'forward' => ['trigger' => 'HYDRO', 'base_multiplier' => 2.0],
            'reverse' => ['trigger' => 'PYRO', 'base_multiplier' => 1.5],
            'stat_priority' => 'Membutuhkan 100–250 Elemental Mastery (EM) pada karakter pemicu untuk melipatgandakan multiplier.',
        ],
        'melt' => [
            'name' => 'Melt',
            'category' => 'amplifying',
            'required_elements' => ['PYRO', 'CRYO'],
            'forward' => ['trigger' => 'PYRO', 'base_multiplier' => 2.0],
            'reverse' => ['trigger' => 'CRYO', 'base_multiplier' => 1.5],
            'stat_priority' => 'Membutuhkan keseimbangan antara ATK%, EM, dan rasio CRIT.',
        ],
        'hyperbloom' => [
            'name' => 'Hyperbloom',
            'category' => 'transformative',
            'required_elements' => ['DENDRO', 'HYDRO', 'ELECTRO'],
            'trigger_element' => 'ELECTRO',
            'build_rule' => 'TRIPLE_EM',
            'level_requirement' => 90,
            'stat_priority' => 'Pemicu Electro wajib Full EM (Sands, Goblet, Circlet) dan Level 90. Stat ATK/CRIT tidak memengaruhi damage Hyperbloom.',
        ],
        'burgeon' => [
            'name' => 'Burgeon',
            'category' => 'transformative',
            'required_elements' => ['DENDRO', 'HYDRO', 'PYRO'],
            'trigger_element' => 'PYRO',
            'build_rule' => 'TRIPLE_EM_AND_ER',
            'level_requirement' => 90,
            'stat_priority' => 'Pemicu Pyro wajib Full EM + Energy Recharge memadai untuk rotasi burst.',
        ],
        'swirl' => [
            'name' => 'Swirl',
            'category' => 'transformative',
            'required_elements' => ['ANEMO', ['PYRO', 'HYDRO', 'ELECTRO', 'CRYO']],
            'mandatory_set' => 'Viridescent Venerer (4pc)',
            'debuff' => 'Elemental RES Shred -40%',
            'stat_priority' => 'Karakter Anemo wajib 4pc VV + Full EM untuk mendongkrak buff elemental tim dan debuff musuh.',
        ],
        'quicken_aggravate' => [
            'name' => 'Aggravate',
            'category' => 'additive',
            'required_elements' => ['DENDRO', 'ELECTRO'],
            'trigger_element' => 'ELECTRO',
            'stat_priority' => 'Mendapat nilai ganda dari stat EM, ATK%, dan rasio CRIT.',
        ],
        'quicken_spread' => [
            'name' => 'Spread',
            'category' => 'additive',
            'required_elements' => ['DENDRO', 'ELECTRO'],
            'trigger_element' => 'DENDRO',
            'stat_priority' => 'Mendapat nilai ganda dari stat EM, ATK/HP%, dan rasio CRIT.',
        ],
        'frozen' => [
            'name' => 'Frozen',
            'category' => 'crowd_control',
            'required_elements' => ['HYDRO', 'CRYO'],
            'recommended_set' => 'Blizzard Strayer (4pc)',
            'crit_rate_potential' => 40,
            'stat_priority' => 'Memungkinkan Circlet menggunakan CRIT DMG karena set Blizzard + Cryo Resonance memberi hingga +55% CRIT Rate.',
        ],
        'superconduct' => [
            'name' => 'Superconduct',
            'category' => 'transformative_debuff',
            'required_elements' => ['CRYO', 'ELECTRO'],
            'debuff' => 'Physical RES Shred -40% selama 12 detik',
            'stat_priority' => 'Wajib untuk DPS Fisik (Eula, Razor). Karakter pemicu tidak wajib investasi EM.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Target Content Mode Profiles
    |--------------------------------------------------------------------------
    */

    'content_profiles' => [
        'abyss' => [
            'name' => 'Spiral Abyss (Floor 11–12)',
            'description' => 'Konten endgame dengan batas waktu ketat. Memprioritaskan DPS maksimal dan rotasi burst tepat waktu.',
            'er_tolerance' => 'STRICT',
            'favored_style' => 'METRIC_BURST_ROTATION',
        ],
        'theater' => [
            'name' => 'Imaginarium Theater',
            'description' => 'Konten variasi roster luas. Memprioritaskan fleksibilitas dan sustain mandiri.',
            'er_tolerance' => 'FLEXIBLE_HIGH',
            'favored_style' => 'GENERALIST_FAVONIUS',
        ],
        'overworld' => [
            'name' => 'Overworld & Story Exploration',
            'description' => 'Eksplorasi harian dan quest cerita. Memprioritaskan kenyamanan bermain, mobilitas, dan skill tanpa ketergantungan burst.',
            'er_tolerance' => 'CASUAL',
            'favored_style' => 'SKILL_BASED_QOL',
        ],
        'boss' => [
            'name' => 'Boss Farming & Domains',
            'description' => 'Farming material dan bos mingguan. Memprioritaskan single-target burst nuke.',
            'er_tolerance' => 'MODERATE',
            'favored_style' => 'SINGLE_TARGET_NUKE',
        ],
    ],

];
