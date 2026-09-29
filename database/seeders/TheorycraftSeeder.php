<?php

namespace Database\Seeders;

use App\Models\Character;
use App\Services\Rag\VectorStoreService;
use Illuminate\Database\Seeder;

class TheorycraftSeeder extends Seeder
{
    public function run(VectorStoreService $vectorStore): void
    {
        $patchVersion = (string) config('services.genshin.target_patch', '7.0');

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
            'arlecchino' => [
                [
                    'category' => 'role_and_reactions',
                    'title' => 'Peran Arlecchino dan Reaksi',
                    'content' => 'Arlecchino adalah DPS on-field Pyro yang mengandalkan Normal Attack setelah memperoleh Bond of Life dari mekanik Blood-Debt Directive. Vaporize bersama Hydro seperti Xingqiu atau Yelan adalah opsi tim umum. Saat bertarung, Arlecchino tidak menerima pemulihan HP dari karakter lain; Elemental Burst miliknya dapat memulihkan HP dirinya.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'weapons_ranking',
                    'title' => 'Pilihan Senjata Arlecchino',
                    'content' => 'Crimson Moon\'s Semblance adalah senjata signature. Alternatif kuat termasuk Staff of Homa dan Primordial Jade Winged-Spear. Opsi 4★ yang dapat dipertimbangkan termasuk Deathmatch; White Tassel adalah opsi F2P yang mudah diakses. Perbandingan akhir bergantung pada refinement, CRIT, dan buff tim.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'artifact_priorities',
                    'title' => 'Artefak dan Prioritas Stat Arlecchino',
                    'content' => '4-piece Fragment of Harmonic Whimsy adalah set spesifik yang direkomendasikan. 4-piece Gladiator\'s Finale merupakan alternatif kuat. Main stat umum: Sands ATK%, Goblet Pyro DMG%, Circlet CRIT Rate atau CRIT DMG. Prioritaskan keseimbangan CRIT dan ATK%; Elemental Mastery juga bernilai pada tim Vaporize. Gunakan substat ER secukupnya agar Burst tersedia saat dibutuhkan.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'team_synergies',
                    'title' => 'Tim Vaporize Arlecchino',
                    'content' => 'Contoh tim Vaporize: Arlecchino, Xingqiu atau Yelan, Bennett, dan Kaedehara Kazuha. Hydro membantu memicu Vaporize, sementara Bennett dan Anemo memberi buff serta dukungan damage. Karena Arlecchino tidak dapat menerima heal dari karakter lain saat bertarung, siapkan Burst Arlecchino untuk pemulihan dirinya dan pertimbangkan perlindungan atau penghindaran serangan.',
                    'target_content' => 'abyss',
                ],
                [
                    'category' => 'rotation',
                    'title' => 'Rotasi Umum Arlecchino',
                    'content' => 'Mulai dengan Elemental Skill Arlecchino untuk menandai musuh, lalu jalankan skill dan Burst karakter pendukung selama jeda. Kembali ke Arlecchino, gunakan Charged Attack untuk memperoleh Bond of Life dari tanda yang sudah matang, kemudian lanjutkan Normal Attack saat infus Pyro aktif. Gunakan Burst Arlecchino bila perlu memulihkan HP atau mengatur ulang Skill. Sesuaikan urutan dengan durasi buff dan situasi pertarungan.',
                    'target_content' => 'abyss',
                ],
            ],
            'diluc' => [
                [
                    'category' => 'role_and_reactions',
                    'title' => 'Peran Diluc dan Reaksi Elemental',
                    'content' => 'Diluc adalah DPS on-field Pyro dengan Claymore. Build standar memanfaatkan Vaporize bersama karakter Hydro; Melt juga dapat dipakai jika komposisi tim mendukung. Manfaatkan Normal Attack di antara penggunaan Elemental Skill agar aplikasi elemen tim tetap berjalan.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'weapons_ranking',
                    'title' => 'Pilihan Senjata Diluc',
                    'content' => 'Pilihan 5★ yang kuat mencakup Beacon of the Reed Sea dan Wolf\'s Gravestone. Serpent Spine adalah opsi 4★ yang kuat bila pasifnya dapat dipertahankan. Untuk tim Vaporize, Rainslasher dapat dimanfaatkan; opsi craftable/F2P termasuk Tidal Shadow atau Prototype Archaic. Pilih berdasarkan substat, refinement, dan buff tim; peringkat pastinya bergantung pada kondisi tersebut.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'artifact_priorities',
                    'title' => 'Artefak dan Prioritas Stat Diluc',
                    'content' => '4-piece Crimson Witch of Flames adalah set standar untuk Diluc yang bermain dengan reaksi Pyro. 4-piece Marechaussee Hunter dapat menjadi alternatif saat bermain bersama Furina dan efek perubahan HP aktif konsisten. Main stat umum: Sands ATK%, Goblet Pyro DMG%, Circlet CRIT Rate atau CRIT DMG. Prioritaskan keseimbangan CRIT, lalu ATK%; Elemental Mastery bernilai untuk tim Vaporize. Jangan mengejar angka benchmark yang kaku tanpa melihat senjata dan komposisi.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'er_breakpoints',
                    'title' => 'Energy Recharge Diluc',
                    'content' => 'Tidak ada satu target ER yang cocok untuk semua tim Diluc. Prioritaskan CRIT dan ATK untuk damage; tambahkan ER secukupnya agar Elemental Burst tersedia sesuai rotasi. Kebutuhannya bergantung pada partikel, penggunaan Burst, dan rekan satu tim.',
                    'target_content' => 'universal',
                ],
                [
                    'category' => 'team_synergies',
                    'title' => 'Sinergi Tim Vaporize Diluc',
                    'content' => 'Contoh tim Vaporize: Diluc, Xingqiu atau Yelan, Bennett, dan Kazuha atau Sucrose. Hydro membantu memicu Vaporize, Bennett memberi buff dan pemulihan, sementara Anemo dapat memberi dukungan elemen. Sesuaikan pilihan dengan karakter yang dimiliki dan kebutuhan survivability.',
                    'target_content' => 'abyss',
                ],
                [
                    'category' => 'rotation',
                    'title' => 'Rotasi Umum Diluc',
                    'content' => 'Aktifkan dukungan Hydro dan buff/heal tim, lakukan setup Anemo bila tersedia, lalu gunakan Burst Diluc dan lanjutkan Normal Attack yang diselingi Elemental Skill sampai semua charge Skill terpakai. Sesuaikan urutan detail dengan durasi buff, cooldown, dan karakter tim.',
                    'target_content' => 'abyss',
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
                        $patchVersion,
                        'local-curated-unreferenced'
                    );
                }
            }
        }
    }
}
