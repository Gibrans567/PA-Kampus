<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TestSwaggerConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:swagger-config';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Debugs the L5-Swagger configuration and YAML file readability.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Memulai Pengecekan Konfigurasi L5-Swagger...');
        $this->newLine();

        // 1. Memeriksa nilai dari file konfigurasi
        $this->line('1. Mengecek path anotasi dari config("l5-swagger.documentations.default.paths.annotations")...');

        // Menggunakan config helper untuk mendapatkan nilai yang sebenarnya dilihat oleh Laravel
        $annotations_path_config = config('l5-swagger.documentations.default.paths.annotations');

        if (empty($annotations_path_config)) {
            $this->error('HASIL: Konfigurasi path anotasi KOSONG atau tidak ditemukan!');
            return 1;
        }

        $this->comment('Path yang terdeteksi:');
        print_r($annotations_path_config);
        $this->newLine();

        // 2. Memeriksa file yang ditunjuk oleh konfigurasi
        $this->line('2. Mengecek keberadaan dan keterbacaan file swagger.yml...');

        // Kita asumsikan path pertama adalah yang kita tuju
        $swagger_file_path = $annotations_path_config[0] ?? null;

        if (!$swagger_file_path) {
            $this->error('HASIL: Tidak ada path file yang didefinisikan di dalam array anotasi.');
            return 1;
        }

        $this->comment("Mencoba memeriksa file di: " . $swagger_file_path);

        if (File::exists($swagger_file_path)) {
            $this->info('-> File DITEMUKAN.');

            // 3. Membaca isi file
            $this->line('3. Mencoba membaca isi file...');
            $content = File::get($swagger_file_path);

            if (empty(trim($content))) {
                $this->warn('-> PERINGATAN: File berhasil dibaca, tetapi ISINYA KOSONG!');
            } else {
                $this->info('-> File berhasil dibaca dan berisi konten.');
            }
        } else {
            $this->error('-> KESALAHAN FATAL: File TIDAK DITEMUKAN di path tersebut!');
            $this->comment('Pastikan nama file dan lokasinya sudah benar.');
            return 1;
        }

        $this->newLine();
        $this->info('Pengecekan Selesai.');

        return 0;
    }
}
