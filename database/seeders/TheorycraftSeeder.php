<?php

namespace Database\Seeders;

use App\Models\Character;
use App\Services\Rag\VectorStoreService;
use Illuminate\Database\Seeder;

class TheorycraftSeeder extends Seeder
{
    public function run(VectorStoreService $vectorStore): void
    {
        $knowledge = [
            'furina' => [
                [
                    'category' => 'artifact_priorities',
                    'title' => 'Panduan Prioritas Artefak & Stat Furina',
                    'content' => 'Set terbaik Furina adalah 4-piece Golden Troupe (GT) yang memberi hingga +70% Elemental Skill DMG saat off-field. Main stat: Sands HP% (atau ER jika solo Hydro <180% ER), Goblet Hydro DMG% atau HP% (keduanya sebanding, HP% lebih konsisten jika fanfare tinggi), Circlet CRIT Rate / CRIT DMG. Substat: ER (hingga target) > CRIT Rate = CRIT DMG > HP% > Flat HP. Target rasio CRIT: 70% CRIT Rate : 140%+ CRIT DMG.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'weapons_ranking',
                    'title' => 'Peringkat Senjata Furina',
                    'content' => 'Top 1: Splendor of Tranquil Waters (Signature 5★). Top 2: Primordial Jade Cutter / Key of Khaj-Nisut / Festering Desire (Event 4★ Terbaik). Opsi F2P & Aksesibel: Fleuve Cendre Ferryman (Pipa Pancing Fontaine, memberi 45.9% ER + 16% Skill CRIT Rate) dan Favonius Sword untuk menopang kebutuhan energi tim.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'er_breakpoints',
                    'title' => 'Ambang Batas Energy Recharge (ER) Furina',
                    'content' => 'Solo Hydro di Spiral Abyss: 180%–210% ER. Double Hydro (bersama Neuvillette/Yelan/Xingqiu): 140%–160% ER. Jika menggunakan Favonius Sword atau Fleuve Cendre, kebutuhan ER turun 20-30%. Di Overworld, ER tidak terlalu krusial karena Salon Solitaire (Skill E) aktif terus menerus selama 30 detik tanpa memerlukan Burst.',
                    'target_content' => 'abyss',
                ],
            ],
            'raiden' => [
                [
                    'category' => 'artifact_priorities',
                    'title' => 'Panduan Artefak Raiden Shogun (Main DPS & Battery)',
                    'content' => 'Set wajib: 4-piece Emblem of Severed Fate (EoSF), meningkatkan Elemental Burst DMG berdasarkan Energy Recharge hingga maks 75%. Main stat: Sands Energy Recharge / ATK%, Goblet Electro DMG% / ATK%, Circlet CRIT Rate / CRIT DMG. Target rasio CRIT: 60/120+. Target ER: 220%–270%. Jika dipakai sebagai Hyperbloom Trigger, beralih total ke 4pc Gilded Dreams / Flower of Paradise Lost dengan Full EM (Sands, Goblet, Circlet).',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'weapons_ranking',
                    'title' => 'Peringkat Senjata Raiden Shogun',
                    'content' => 'Top 1: Engulfing Lightning (Signature 5★). Alternatif 5★: Staff of Homa, Primordial Jade Winged-Spear. Opsi F2P Terbaik Mutlak: "The Catch" R5 (senjata pancing Inazuma, memberi ER + Burst DMG & CRIT). Opsi Hyperbloom: Dragon\'s Bane (Full EM).',
                    'target_content' => 'universal',
                ],
            ],
            'neuvillette' => [
                [
                    'category' => 'artifact_priorities',
                    'title' => 'Panduan Artefak Neuvillette Hydro Hypercarry',
                    'content' => 'Set terbaik mutlak: 4-piece Marechaussee Hunter (MH), memberi +36% CRIT Rate saat HP naik/turun dari Charged Attack. Main stat: Sands HP%, Goblet Hydro DMG% (atau HP% jika bersama Furina), Circlet CRIT DMG. Jangan overcap CRIT Rate (cukup 50–64% base CRIT Rate karena set MH memberi +36%). Target HP: 35.000–40.000+ HP.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'weapons_ranking',
                    'title' => 'Peringkat Senjata Neuvillette',
                    'content' => 'Top 1: Tome of the Eternal Flow (Signature 5★). Top 2: Sacrificial Jade (Battle Pass). Opsi F2P Terbaik: Prototype Amber R5 (Craftable Liyue, memberi HP% masif, regenerasi energi 18 flat, dan heal untuk seluruh tim yang memicu Fanfare Furina).',
                    'target_content' => 'universal',
                ],
            ],
        ];

        foreach ($knowledge as $slug => $chunks) {
            $char = Character::where('slug', $slug)->first();
            if ($char) {
                foreach ($chunks as $chunk) {
                    $vectorStore->storeKnowledge(
                        $char,
                        $chunk['category'],
                        $chunk['title'],
                        $chunk['content'],
                        $chunk['target_content'],
                        '7.0'
                    );
                }
            }
        }
    }
}
