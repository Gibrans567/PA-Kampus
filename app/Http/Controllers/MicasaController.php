<?php

namespace App\Http\Controllers;
use RouterOS\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RouterOS\Query;

class MicasaController extends CentralController
{
    protected function getClientMicasa()
    {
        $config = [
            'host' => '45.149.93.122',
            'user' => 'admin',
            'pass' => 'dhiva1029',
            'port' => 8035,
        ];

        return new Client($config);
    }

    public function EditMicasa(Request $request, $no_hp)
{
    // Validasi input
    $request->validate([
        'name' => 'sometimes|required|string|max:255',
        'profile' => 'nullable|string|max:50',
        'comment' => 'sometimes|required|string|max:255',
    ]);

    try {
        $client = $this->getClientMicasa();

        $checkQuery = (new Query('/ip/hotspot/user/print'))->where('name', $no_hp);
        $existingUsers = $client->query($checkQuery)->read();

        if (empty($existingUsers)) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        $userId = $existingUsers[0]['.id'];
        $password = $existingUsers[0]['password']; // Mengambil password yang ada di data pengguna

        // Cek jika name dan password sama dengan no_hp
        if ($request->has('name')) {
            // Validasi bahwa username dan password lama masih sama (belum pernah diubah)
            if ($no_hp !== $existingUsers[0]['name'] || $no_hp !== $password) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Maaf, Anda telah melakukan update pada username atau password. Username dan password harus sama.'
                ], 400);
            }
        }


        // Melakukan update pada user jika pengecekan valid
        $updateUserQuery = (new Query('/ip/hotspot/user/set'))
            ->equal('.id', $userId);

        if ($request->has('name')) {
            $updateUserQuery->equal('password', $request->input('name'));
        }

        if ($request->has('profile')) {
            $updateUserQuery->equal('profile', $request->input('profile'));
        }

        if ($request->has('comment')) {
            $updateUserQuery->equal('comment', $request->input('comment'));
        }

        $client->query($updateUserQuery)->read();

        // Update database jika username dan password valid
        if ($request->has('name')) {
            DB::table('voucher_lists')->where('name', $no_hp)
                    ->update([
                        'name' => $request->input('name'),    // Update name in the database
                        'password' => $request->input('name'),  // Update password in the database
                    ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'User berhasil diperbarui.'
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Terjadi kesalahan: ' . $e->getMessage()
        ], 500);
    }
}
    public function getUserMicasa()
{
    try {
        // Mendapatkan koneksi client Mikrotik
        $client = $this->getClientMicasa();

        // Mengambil data pengguna hotspot
        $userQuery = new Query('/ip/hotspot/user/print');
        $users = $client->query($userQuery)->read();

        // Mengambil data pengguna aktif
        $activeQuery = new Query('/ip/hotspot/active/print');
        $activeUsers = $client->query($activeQuery)->read();

        // Membuat map pengguna aktif berdasarkan username
        $activeUsersMap = [];
        foreach ($activeUsers as $activeUser) {
            if (isset($activeUser['user'])) {
                $username = $activeUser['user'];
                $activeUsersMap[$username] = $activeUser;
            }
        }

        // Modifikasi struktur data pengguna
        $modifiedUsers = array_map(function ($user) use ($activeUsersMap) {
            $newUser = [];
            foreach ($user as $key => $value) {
                $newKey = str_replace('.id', 'id', $key);
                $newUser[$newKey] = $value;
            }

            // Memastikan password disertakan
            $newUser['password'] = isset($user['password']) ? $user['password'] : '';

            // Menambahkan informasi bytes-in dan bytes-out jika pengguna aktif
            if (isset($user['name']) && isset($activeUsersMap[$user['name']])) {
                $activeUser = $activeUsersMap[$user['name']];
                $newUser['bytes-in'] = isset($activeUser['bytes-in']) ? (int)$activeUser['bytes-in'] : 0;
                $newUser['bytes-out'] = isset($activeUser['bytes-out']) ? (int)$activeUser['bytes-out'] : 0;
                $newUser['is_active'] = true;
            } else {
                $newUser['bytes-in'] = 0;
                $newUser['bytes-out'] = 0;
                $newUser['is_active'] = false;
            }

            return $newUser;
        }, $users);

        // Mengembalikan response dalam format JSON
        return response()->json([
            'total_user' => count($modifiedUsers),
            'users' => $modifiedUsers,
            'active_users' => count($activeUsers)
        ]);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

    public function loginMicasa(Request $request)
{
    try {
        $username = $request->json('username');
        $password = $request->json('password');

        // Validasi input
        if (empty($username) || empty($password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username dan password harus diisi'
            ], 400);
        }

        // Mendapatkan koneksi client Mikrotik
        $client = $this->getClientMicasa();

        // Mencari user dengan username yang dimasukkan
        $userQuery = new Query('/ip/hotspot/user/print');
        $users = $client->query($userQuery)->read();

        // Cari user yang cocok dengan username yang diinput
        $userFound = false;
        $passwordMatch = false;
        $profileUser = 'default';

        foreach ($users as $user) {
            // Pastikan kita mengakses 'name' dan 'password' dengan cara yang sama seperti di getUserMicasa
            if (isset($user['name']) && $user['name'] === $username) {
                $userFound = true;
                // Akses password langsung dari data user seperti di getUserMicasa
                if (isset($user['password']) && $user['password'] === $password) {
                    $passwordMatch = true;
                    $profileUser = isset($user['profile']) ? $user['profile'] : 'default';
                    break;
                }
            }
        }

        // Debug info (opsional, hapus di production)
        $debugInfo = [
            'user_found' => $userFound,
            'password_match' => $passwordMatch
        ];

        // Periksa apakah user ditemukan dan password cocok
        if ($userFound && $passwordMatch) {
            // Login berhasil
            return response()->json([
                'status' => 'success',
                'message' => 'Selamat anda berhasil login',
                'profile' => $profileUser
            ]);
        } else {
            // Login gagal
            return response()->json([
                'status' => 'error',
                'message' => 'Username atau password salah',
                'debug' => $debugInfo  // Hapus ini di production
            ], 401);
        }
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Terjadi kesalahan: ' . $e->getMessage()
        ], 500);
    }
    }

    public function adminEditMicasa(Request $request, $no_hp)
{
    // Validasi input
    $request->validate([
        'name' => 'sometimes|required|string|max:255',
        'profile' => 'nullable|string|max:50',
        'comment' => 'sometimes|required|string|max:255',
    ]);

    try {
        $client = $this->getClientLogin();

        $checkQuery = (new Query('/ip/hotspot/user/print'))->where('name', $no_hp);
        $existingUsers = $client->query($checkQuery)->read();

        if (empty($existingUsers)) {
            return response()->json(['message' => 'User tidak ditemukan.'], 404);
        }

        $userId = $existingUsers[0]['.id'];

        $updateUserQuery = (new Query('/ip/hotspot/user/set'))
            ->equal('.id', $userId);

        if ($request->has('name')) {
            $updateUserQuery->equal('password', $request->input('name'));
        }

        if ($request->has('profile')) {
            $updateUserQuery->equal('profile', $request->input('profile'));
        }

        if ($request->has('comment')) {
            $updateUserQuery->equal('comment', $request->input('comment'));
        }

        $client->query($updateUserQuery)->read();

        DB::table('voucher_lists')->where('name', $no_hp)
                ->update([
                    'name' => $request->input( 'name'),    // Update name in the database
                    'password' => $request->input( 'name'),  // Update profile in the database
                ]);

        return response()->json(['message' => 'User berhasil diperbarui.'], 200);

    } catch (\Exception $e) {
        return response()->json(['error' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
    }
    }
}
