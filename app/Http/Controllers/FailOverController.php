<?php

namespace App\Http\Controllers;
use RouterOS\Client;
use RouterOS\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;


class FailOverController extends CentralController
{

    public function getNetwatch()
    {


        try {
            // Inisialisasi koneksi
            $client = $this->getClientLogin();

            // Query untuk mendapatkan daftar Netwatch
            $query = new Query('/tool/netwatch/print');

            // Eksekusi query
            $response = $client->query($query)->read();

            // Cek apakah ada hasil
            if (!empty($response)) {
                return response()->json([
                    'status' => 'success',
                    'data' => $response
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tidak ada data Netwatch'
                ]);
            }

        } catch (\Exception $e) {
            // Tangani error jika koneksi gagal
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghubungi Mikrotik: ' . $e->getMessage()
            ]);
        }
    }

    public function getRoute()
    {


        try {
            // Inisialisasi koneksi
            $client = $this->getClientLogin();

            // Query untuk mendapatkan daftar Netwatch
            $query = new Query('/ip/route/print'); // Perintah untuk route list
            $response = $client->query($query)->read();

            // Cek apakah ada data yang ditemukan
            if (!empty($response)) {
                // Menampilkan hanya 4 kolom yang diperlukan (id, dst-address, gateway, vrf-interface)
                $filteredData = collect($response)->map(function ($item) {
                    return [
                        'id' => $item['.id'] ?? null, // ID route
                        'dst-address' => $item['dst-address'] ?? null, // Alamat tujuan
                        'gateway' => $item['gateway'] ?? null, // Gateway
                        'vrf-interface' => $item['vrf-interface'] ?? null // Interface VRF
                    ];
                });

                return response()->json([
                    'status' => 'success',
                    'data' => $filteredData
                ]);
            } else {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Tidak ada route list yang ditemukan'
                ]);
            }

        } catch (\Exception $e) {
            // Menangani error jika koneksi gagal
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menghubungi Mikrotik: ' . $e->getMessage()
            ]);
        }
    }

    public function addNetwatch(Request $request)
{
    // Ambil nilai dari input form
    $primaryGateway = $request->input('primary_gateway');
    $backupGateway = $request->input('backup_gateway');

    // Validasi input
    if (empty($primaryGateway) || empty($backupGateway)) {
        return response()->json(['error' => 'Primary and backup gateway are required.'], 400);
    }

    try {
        // Inisialisasi koneksi RouterOS API
        $client = $this->getClientLogin();

        // Cek apakah gateway ada dalam daftar route
        $routeQuery = new Query('/ip/route/print');
        $routes = $client->query($routeQuery)->read();

        $primaryExists = false;
        $backupExists = false;

        foreach ($routes as $route) {
            if (isset($route['gateway']) && $route['gateway'] === $primaryGateway) {
                $primaryExists = true;
            }
            if (isset($route['gateway']) && $route['gateway'] === $backupGateway) {
                $backupExists = true;
            }
        }

        // Jika salah satu gateway tidak ditemukan, kembalikan error
        if (!$primaryExists || !$backupExists) {
            return response()->json([
                'error' => 'One or both gateways not found in the route table.',
                'primary_exists' => $primaryExists,
                'backup_exists' => $backupExists
            ], 404);
        }

        // Script untuk kondisi up (primary gateway aktif)
        $upScript = '/ip route set [find gateway=' . $primaryGateway . '] distance=1' . "\n" .
                   '/ip route set [find gateway=' . $backupGateway . '] distance=2';

        // Script untuk kondisi down (primary gateway nonaktif)
        $downScript = '/ip route set [find gateway=' . $primaryGateway . '] distance=2' . "\n" .
                     '/ip route set [find gateway=' . $backupGateway . '] distance=1';

        // 1. Tambahkan Netwatch untuk monitoring ICMP (ping)
        $addIcmpNetwatch = new Query('/tool/netwatch/add');
        $addIcmpNetwatch->equal('host', $primaryGateway);
        $addIcmpNetwatch->equal('type', 'icmp');
        $addIcmpNetwatch->equal('interval', '00:00:10');
        $addIcmpNetwatch->equal('timeout', '500ms');
        $addIcmpNetwatch->equal('up-script', $upScript);
        $addIcmpNetwatch->equal('down-script', $downScript);
        $client->query($addIcmpNetwatch)->read();

        // 2. Tambahkan Netwatch untuk monitoring HTTPS GET
        // $addHttpNetwatch = new Query('/tool/netwatch/add');
        // $addHttpNetwatch->equal('host', $primaryGateway);
        // $addHttpNetwatch->equal('type', 'https-get'); // Sesuai dengan gambar, ini https-get
        // $addHttpNetwatch->equal('port', '443'); // Port default untuk HTTPS
        // $addHttpNetwatch->equal('http-codes', '200,301,302'); // Kode HTTP sukses
        // $addHttpNetwatch->equal('thr-http-time', '2s'); // Threshold HTTP time
        // $addHttpNetwatch->equal('interval', '00:00:10');
        // $addHttpNetwatch->equal('timeout', '2s'); // Timeout lebih lama untuk HTTP GET
        // $addHttpNetwatch->equal('up-script', $upScript);
        // $addHttpNetwatch->equal('down-script', $downScript);
        // $client->query($addHttpNetwatch)->read();

        return response()->json([
            'message' => 'Both ICMP and HTTPS-GET netwatch added successfully!',
            'monitored_gateway' => $primaryGateway,
            'backup_gateway' => $backupGateway,
        ]);

    } catch (\Exception $e) {
        return response()->json(['error' => 'Failed to connect to MikroTik: ' . $e->getMessage()], 500);
    }

    }

    public function getLatestLogs(Request $request)
    {
        try {
            // Inisialisasi koneksi RouterOS API
            $client = $this->getClientLogin(); // Pastikan fungsi ini mengembalikan client yang sudah login

            // Buat query untuk mengambil semua log
            $query = new Query('/log/print');
            $query->equal('.proplist', 'time,message'); // Ambil hanya waktu dan pesan
            $logs = $client->query($query)->read();

            // Ambil 5 log terbaru dari hasil yang didapat
            $latestLogs = array_slice($logs, -5);

            return response()->json([
                'logs' => $latestLogs
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve logs: ' . $e->getMessage()], 500);
        }
    }
}
