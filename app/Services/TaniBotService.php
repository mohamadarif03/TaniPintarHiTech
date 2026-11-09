<?php
// app/Services/TaniBotService.php

namespace App\Services;

use App\Models\Lahan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TaniBotService
{
    public function __construct(protected GeminiService $gemini) {}

    public function chat(string $pertanyaan, Lahan $lahan, array $riwayat = []): string
    {
        // 1. Cek apakah ada Custom LLM (Qwen di Colab/Local)
        $customUrl = env('CUSTOM_LLM_URL');
        if (!empty($customUrl)) {
            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'ngrok-skip-browser-warning' => 'true',
                    ])
                    ->post(rtrim($customUrl, '/') . '/chat', [
                        'message' => "Lahan: {$lahan->nama} ({$lahan->komoditas}, Fase: {$lahan->fase_tanam}). Pertanyaan: {$pertanyaan}",
                    ]);

                if ($response->successful() && !empty($response->json('reply'))) {
                    return $response->json('reply');
                }
            } catch (\Exception $e) {
                Log::warning('Custom LLM Offline or Error, fallback to Gemini: ' . $e->getMessage());
            }
        }

        // 2. Fallback otomatis ke Gemini jika custom LLM offline/tidak diset
        $konteks = "
Kamu adalah TaniBot, asisten pertanian AI untuk platform TaniPintar Indonesia.
Kamu sudah mengetahui profil lahan petani ini:
- Nama lahan: {$lahan->nama}
- Komoditas: {$lahan->komoditas}
- Lokasi: {$lahan->kota}, {$lahan->kecamatan}
- Luas: " . ($lahan->luas ?? '?') . " hektar
- Fase tanam saat ini: {$lahan->fase_tanam}
- Status risiko hari ini: {$lahan->status_risiko}

Jawab dalam bahasa Indonesia yang ramah dan mudah dipahami petani.
Jawaban maksimal 200 kata. Jangan pakai istilah teknis yang sulit.
Jika ada rekomendasi tindakan, gunakan poin-poin singkat.
Format jawaban boleh menggunakan markdown dasar (bold, list).
";

        // Bangun riwayat percakapan
        $riwayatTeks = '';
        foreach ($riwayat as $chat) {
            $riwayatTeks .= "Petani: {$chat['pertanyaan']}\n";
            $riwayatTeks .= "TaniBot: {$chat['jawaban']}\n\n";
        }

        $prompt = $konteks . "\n\nRiwayat percakapan:\n" . $riwayatTeks . "\nPetani: " . $pertanyaan . "\nTaniBot:";

        return $this->gemini->generate($prompt);
    }
}

