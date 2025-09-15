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
        //Konfigurasi yang didapat masih manual dengan cara mengambil nya dari Mikrotik CHR
        $config = [
            'host' => '45.149.93.122',
            'user' => 'admin',
            'pass' => 'dhiva1029',
            'port' => 8035,
        ];

        return new Client($config);
    }

        /**
 * @OA\Get(
 *     path="/mikrotik/get-user-micasa",
 *     summary="Get daftar semua user Micasa",
 *     tags={"Micasa"},
 *     @OA\Response(
 *         response=200,
 *         description="Daftar user Micasa",
 *         @OA\JsonContent(
 *             @OA\Property(
 *                 property="users",
 *                 type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="username", type="string", example="08123456789"),
 *                     @OA\Property(property="profile", type="string", example="default"),
 *                     @OA\Property(property="uptime", type="string", example="1h23m")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function getUserMicasa()
{
    try {
        $client = $this->getClientMicasa();

        $userQuery = new Query('/ip/hotspot/user/print');
        $users = $client->query($userQuery)->read();

        $activeQuery = new Query('/ip/hotspot/active/print');
        $activeUsers = $client->query($activeQuery)->read();

        $activeUsersMap = [];
        foreach ($activeUsers as $activeUser) {
            if (isset($activeUser['user'])) {
                $username = $activeUser['user'];
                $activeUsersMap[$username] = $activeUser;
            }
        }

        $modifiedUsers = array_map(function ($user) use ($activeUsersMap) {
            $newUser = [];
            foreach ($user as $key => $value) {
                $newKey = str_replace('.id', 'id', $key);
                $newUser[$newKey] = $value;
            }

            $newUser['password'] = isset($user['password']) ? $user['password'] : '';

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

        return response()->json([
            'total_user' => count($modifiedUsers),
            'users' => $modifiedUsers,
            'active_users' => count($activeUsers)
        ]);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

      /**
 * @OA\Get(
 *     path="/mikrotik/get-active-micasa",
 *     summary="Get daftar active user Micasa",
 *     tags={"Micasa"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Response(
 *         response=200,
 *         description="Daftar active user",
 *         @OA\JsonContent(
 *             @OA\Property(property="total_active_users", type="integer", example=5),
 *             @OA\Property(
 *                 property="active_users",
 *                 type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="user", type="string", example="08123456789"),
 *                     @OA\Property(property="bytes-in", type="integer", example=2048),
 *                     @OA\Property(property="bytes-out", type="integer", example=4096),
 *                     @OA\Property(property="uptime", type="string", example="2h15m30s")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function getActiveUsersMicasa()
{
    try {
        $client = $this->getClientLogin();

        $activeQuery = new Query('/ip/hotspot/active/print');
        $activeUsers = $client->query($activeQuery)->read();

        $modifiedActiveUsers = array_map(function ($activeUser) {
            $newUser = [];
            foreach ($activeUser as $key => $value) {
                $newKey = str_replace('.id', 'id', $key);
                $newUser[$newKey] = $value;
            }

            $newUser['bytes-in'] = isset($activeUser['bytes-in']) ? (int)$activeUser['bytes-in'] : 0;
            $newUser['bytes-out'] = isset($activeUser['bytes-out']) ? (int)$activeUser['bytes-out'] : 0;
            $newUser['uptime'] = isset($activeUser['uptime']) ? $activeUser['uptime'] : '';
            $newUser['user'] = isset($activeUser['user']) ? $activeUser['user'] : '';
            $newUser['address'] = isset($activeUser['address']) ? $activeUser['address'] : '';
            $newUser['mac-address'] = isset($activeUser['mac-address']) ? $activeUser['mac-address'] : '';
            $newUser['login-by'] = isset($activeUser['login-by']) ? $activeUser['login-by'] : '';

            return $newUser;
        }, $activeUsers);

        usort($modifiedActiveUsers, function ($a, $b) {
            return strcmp($a['user'], $b['user']);
        });

        return response()->json([
            'total_active_users' => count($modifiedActiveUsers),
            'active_users' => $modifiedActiveUsers
        ]);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/mikrotik/hotspot-micasa/{no_hp}",
 *     summary="Edit hotspot Micasa tanpa login",
 *     tags={"Micasa"},
 *     @OA\Parameter(
 *         name="no_hp",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string"),
 *         description="Nomor HP user hotspot"
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="password", type="string", example="123456"),
 *             @OA\Property(property="profile", type="string", example="default"),
 *             @OA\Property(property="comment", type="string", example="User reguler hotspot")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Berhasil edit hotspot",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Hotspot berhasil diperbarui.")
 *         )
 *     ),
 *     @OA\Response(response=400, description="Input tidak valid"),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function EditMicasa(Request $request, $no_hp)
{
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
        $password = $existingUsers[0]['password'];
        if ($request->has('name')) {
            if ($no_hp !== $existingUsers[0]['name'] || $no_hp !== $password) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Maaf, Anda telah melakukan update pada username atau password. Username dan password harus sama.'
                ], 400);
            }
        }


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

        if ($request->has('name')) {
            DB::table('voucher_lists')->where('name', $no_hp)
                    ->update([
                        'name' => $request->input('name'),
                        'password' => $request->input('name'),
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

    /**
 * @OA\Post(
 *     path="/mikrotik/login-micasa",
 *     summary="Login user hotspot Micasa",
 *     tags={"Micasa"},
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="username", type="string", example="08123456789"),
 *             @OA\Property(property="password", type="string", example="08123456789")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Login berhasil",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="string", example="success"),
 *             @OA\Property(property="message", type="string", example="Selamat anda berhasil login"),
 *             @OA\Property(property="profile", type="string", example="default")
 *         )
 *     ),
 *     @OA\Response(response=401, description="Username atau password salah"),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function loginMicasa(Request $request)
{
    try {
        $username = $request->json('username');
        $password = $request->json('password');

        if (empty($username) || empty($password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Username dan password harus diisi'
            ], 400);
        }

        $client = $this->getClientMicasa();

        $userQuery = new Query('/ip/hotspot/user/print');
        $users = $client->query($userQuery)->read();

        $userFound = false;
        $passwordMatch = false;
        $profileUser = 'default';

        foreach ($users as $user) {
            if (isset($user['name']) && $user['name'] === $username) {
                $userFound = true;
                if (isset($user['password']) && $user['password'] === $password) {
                    $passwordMatch = true;
                    $profileUser = isset($user['profile']) ? $user['profile'] : 'default';
                    break;
                }
            }
        }

        $debugInfo = [
            'user_found' => $userFound,
            'password_match' => $passwordMatch
        ];

        if ($userFound && $passwordMatch) {
            return response()->json([
                'status' => 'success',
                'message' => 'Selamat anda berhasil login',
                'profile' => $profileUser
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => 'Username atau password salah',
                'debug' => $debugInfo
            ], 401);
        }
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Terjadi kesalahan: ' . $e->getMessage()
        ], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/mikrotik/edit-admin-micasa/{no_hp}",
 *     summary="Edit data admin Micasa",
 *     tags={"Micasa"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Parameter(
 *         name="no_hp",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string"),
 *         description="Nomor HP sebagai username user hotspot"
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="name", type="string", example="08123456789"),
 *             @OA\Property(property="profile", type="string", example="default"),
 *             @OA\Property(property="comment", type="string", example="Admin hotspot Micasa")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Berhasil update user",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="User berhasil diperbarui.")
 *         )
 *     ),
 *     @OA\Response(response=404, description="User tidak ditemukan"),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function adminEditMicasa(Request $request, $no_hp)
{
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

        // DB::table('voucher_lists')->where('name', $no_hp)
        //         ->update([
        //             'name' => $request->input( 'name'),
        //             'password' => $request->input( 'name'),
        //         ]);

        return response()->json(['message' => 'User berhasil diperbarui.'], 200);

    } catch (\Exception $e) {
        return response()->json(['error' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Delete(
 *     path="/mikrotik/delete-mac-cookie",
 *     summary="Hapus semua MAC cookies pada Micasa",
 *     tags={"Micasa"},
 *     @OA\Response(
 *         response=200,
 *         description="Berhasil hapus MAC cookies",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Semua MAC cookies berhasil dihapus.")
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function getActiveUsersAndCleanCookiesMicasa()
{
    try {
        $client = $this->getClientMicasa();

        $activeQuery = new Query('/ip/hotspot/active/print');
        $activeUsers = $client->query($activeQuery)->read();

        $modifiedActiveUsers = array_map(function ($activeUser) {
            $newUser = [];
            foreach ($activeUser as $key => $value) {
                $newKey = str_replace('.id', 'id', $key);
                $newUser[$newKey] = $value;
            }

            $newUser['bytes-in'] = isset($activeUser['bytes-in']) ? (int)$activeUser['bytes-in'] : 0;
            $newUser['bytes-out'] = isset($activeUser['bytes-out']) ? (int)$activeUser['bytes-out'] : 0;
            $newUser['uptime'] = isset($activeUser['uptime']) ? $activeUser['uptime'] : '';
            $newUser['user'] = isset($activeUser['user']) ? $activeUser['user'] : '';
            $newUser['address'] = isset($activeUser['address']) ? $activeUser['address'] : '';
            $newUser['mac-address'] = isset($activeUser['mac-address']) ? $activeUser['mac-address'] : '';
            $newUser['login-by'] = isset($activeUser['login-by']) ? $activeUser['login-by'] : '';

            return $newUser;
        }, $activeUsers);

        $activeMacAddresses = [];
        foreach ($activeUsers as $activeUser) {
            if (isset($activeUser['mac-address'])) {
                $activeMacAddresses[] = $activeUser['mac-address'];
            }
        }

        $cookieQuery = new Query('/ip/hotspot/cookie/print');
        $cookies = $client->query($cookieQuery)->read();

        $deletedCookiesCount = 0;
        $remainingCookiesCount = 0;

        foreach ($cookies as $cookie) {
            if (isset($cookie['mac-address'])) {
                $macAddress = $cookie['mac-address'];

                if (!in_array($macAddress, $activeMacAddresses)) {
                    if (isset($cookie['.id'])) {
                        $removeQuery = new Query('/ip/hotspot/cookie/remove');
                        $removeQuery->equal('.id', $cookie['.id']);
                        $client->query($removeQuery)->read();
                        $deletedCookiesCount++;
                    }
                } else {
                    $remainingCookiesCount++;
                }
            }
        }

        // ========== BAGIAN BARU: MEMBERSIHKAN HOSTS ========== //

        $leasesQuery = new Query('/ip/dhcp-server/lease/print');
        $leases = $client->query($leasesQuery)->read();

        $leasesMacAddresses = [];
        foreach ($leases as $lease) {
            if (isset($lease['mac-address']) && !empty($lease['mac-address'])) {
                $leasesMacAddresses[] = $lease['mac-address'];
            }
        }

        $hostsQuery = new Query('/ip/hotspot/host/print');
        $hosts = $client->query($hostsQuery)->read();

        $deletedHostsCount = 0;
        $remainingHostsCount = 0;
        $deletedIdleHostsCount = 0;
        $deletedNotInDhcpCount = 0;

        $fiveDaysInSeconds = 5 * 24 * 60 * 60;

        foreach ($hosts as $host) {
            if (isset($host['mac-address'])) {
                $hostMacAddress = $host['mac-address'];
                $shouldDelete = false;
                $deleteReason = '';

                if (!in_array($hostMacAddress, $leasesMacAddresses)) {
                    $shouldDelete = true;
                    $deleteReason = 'not_in_dhcp_leases';
                    $deletedNotInDhcpCount++;
                }

                if (isset($host['idle-time']) && !empty($host['idle-time'])) {
                    $idleTime = $host['idle-time'];

                    $idleSeconds = $this->parseRouterOSTime($idleTime);

                    if ($idleSeconds > $fiveDaysInSeconds) {
                        if (!$shouldDelete) {
                            $deletedIdleHostsCount++;
                        }
                        $shouldDelete = true;
                        $deleteReason = ($deleteReason === 'not_in_dhcp_leases') ? 'both_reasons' : 'idle_timeout_exceeded';
                    }
                }

                if ($shouldDelete && isset($host['.id'])) {
                    $removeHostQuery = new Query('/ip/hotspot/host/remove');
                    $removeHostQuery->equal('.id', $host['.id']);
                    $client->query($removeHostQuery)->read();
                    $deletedHostsCount++;
                } else {
                    $remainingHostsCount++;
                }
            }
        }

        return response()->json([
            'status' => 'success',
            'total_active_users' => count($modifiedActiveUsers),
            'active_users' => $modifiedActiveUsers,
            'cookies_info' => [
                'total_cookies_before' => count($cookies),
                'cookies_deleted' => $deletedCookiesCount,
                'cookies_remaining' => $remainingCookiesCount
            ],
            'hosts_info' => [
                'total_hosts_before' => count($hosts),
                'total_dhcp_leases' => count($leases),
                'hosts_deleted' => $deletedHostsCount,
                'hosts_deleted_not_in_dhcp' => $deletedNotInDhcpCount,
                'hosts_deleted_by_idle_timeout' => $deletedIdleHostsCount,
                'hosts_remaining' => $remainingHostsCount
            ]
        ]);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

    private function parseRouterOSTime($timeString)
    {
        $seconds = 0;

        $patterns = [
            '/(\d+)w/' => 604800,
            '/(\d+)d/' => 86400,
            '/(\d+)h/' => 3600,
            '/(\d+)m/' => 60,
            '/(\d+)s/' => 1
        ];

        foreach ($patterns as $pattern => $multiplier) {
            if (preg_match($pattern, $timeString, $matches)) {
                $seconds += intval($matches[1]) * $multiplier;
            }
        }

        return $seconds;
    }

    /**
 * @OA\Delete(
 *     path="/delete-active-user-by-username/{id}",
 *     summary="Hapus active user Micasa berdasarkan ID",
 *     tags={"Micasa"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Parameter(
 *         name="id",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string"),
 *         description="ID dari active user yang ingin dihapus"
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="User berhasil dihapus",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Active user has been removed.")
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function deleteActiveUserByIdMicasa($id)
{
    try {
        $client = $this->getClientLogin();

        $deleteQuery = new Query('/ip/hotspot/active/remove');
        $deleteQuery->equal('.id', $id);
        $client->query($deleteQuery)->read();

        return response()->json([
            'message' => "Active user has been removed."
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage()
        ], 500);
    }
    }

}
