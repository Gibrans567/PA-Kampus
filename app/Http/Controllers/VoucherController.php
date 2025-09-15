<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RouterOS\Query;

class VoucherController extends CentralController
{

    /**
 * @OA\Get(
 *     path="/mikrotik/list-akun",
 *     summary="Ambil daftar semua akun hotspot",
 *     tags={"Voucher"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Response(
 *         response=200,
 *         description="Daftar akun hotspot",
 *         @OA\JsonContent(
 *             type="array",
 *             @OA\Items(
 *                 @OA\Property(property="username", type="string", example="ABC12345"),
 *                 @OA\Property(property="password", type="string", example="ABC12345"),
 *                 @OA\Property(property="bytes_in", type="integer", example=10240),
 *                 @OA\Property(property="bytes_out", type="integer", example=20480),
 *                 @OA\Property(property="comment", type="string", example="status: active, expiry: 2025-09-15 18:00:00")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function getHotspotUsers($mikrotikConfig)
{
    try {
        $client = $this->getClientVoucher($mikrotikConfig);

        $hotspotQuery = new Query('/ip/hotspot/user/print');
        $hotspotData = $client->q($hotspotQuery)->read();

        $response = [];
        foreach ($hotspotData as $user) {
            $response[] = [
                'username' => $user['name'] ?? 'Not Available',
                'password' => $user['password'] ?? 'Not Available',
                'bytes_in' => $user['bytes-in'] ?? 0,
                'bytes_out' => $user['bytes-out'] ?? 0,
                'comment' => $user['comment'] ?? 'Not Available',
            ];
        }

        return response()->json($response, 200);

    } catch (\Exception $e) {
        return response()->json(['error' => 'Failed to fetch hotspot users: ' . $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Get(
 *     path="/mikrotik/list-voucher",
 *     summary="Ambil daftar semua voucher",
 *     tags={"Voucher"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Response(
 *         response=200,
 *         description="Daftar voucher",
 *         @OA\JsonContent(
 *             type="array",
 *             @OA\Items(
 *                 @OA\Property(property="name", type="string", example="ABC12345"),
 *                 @OA\Property(property="status", type="string", example="Inactive"),
 *                 @OA\Property(property="profile", type="string", example="Standard"),
 *                 @OA\Property(property="waktu", type="integer", example=2)
 *             )
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function getVoucherLists()
    {
        $vouchers = DB::table('voucher_lists')->get();

        return response()->json($vouchers);
    }

    /**
 * @OA\Post(
 *     path="/mikrotik/update-status",
 *     summary="Perbarui komentar dan waktu kadaluarsa pengguna hotspot",
 *     tags={"Voucher"},
 *     @OA\Response(
 *         response=200,
 *         description="Status voucher berhasil diperbarui",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Komentar dan waktu kadaluarsa semua pengguna yang sesuai berhasil diperbarui, dan status voucher diperbarui menjadi sudah digunakan.")
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function updateAllHotspotUsersByName($mikrotikConfig)
{
    try {
        $client = $this->getClientVoucher($mikrotikConfig);

        $getActiveUsersQuery = new Query('/ip/hotspot/active/print');
        $activeUsers = $client->query($getActiveUsersQuery)->read();

        if (empty($activeUsers)) {
            return response()->json(['message' => 'Tidak ada pengguna aktif.'], 200);
        }

        $activeUsernames = array_column($activeUsers, 'user');

        foreach ($activeUsernames as $username) {
            $getUserQuery = (new Query('/ip/hotspot/user/print'))->where('name', $username);
            $users = $client->query($getUserQuery)->read();

            if (empty($users)) {
                continue;
            }

            foreach ($users as $user) {
                $userId = $user['.id'];
                $comment = $user['comment'] ?? '';

                if (strpos($comment, 'status: active') !== false) {
                    continue;
                }

                $voucher = DB::table('voucher_lists')->where('name', $username)->first();

                if (!$voucher) {
                    Log::warning("Voucher tidak ditemukan untuk username: {$username}");
                    continue;
                }

                $voucher_hours = (int)($voucher->waktu ?? 0);
                $newExpiryTime = Carbon::now()->addHours($voucher_hours);

                if (preg_match('/name: ([^,]+)/', $comment, $matches)) {
                    $name = $matches[1];
                } else {
                    $name = $username;
                }

                $updatedComment = "status: active, name: {$name}, expiry: {$newExpiryTime->format('Y-m-d H:i:s')}";
                $updateUserQuery = (new Query('/ip/hotspot/user/set'))
                    ->equal('.id', $userId)
                    ->equal('comment', $updatedComment);

                $client->query($updateUserQuery)->read();

                $updateStatus = DB::table('voucher_lists')
                    ->where('name', $username)
                    ->update(['status' => 'Online']);

                Log::info("Voucher updated for username: {$username}. Rows affected: {$updateStatus}");
            }
        }

        return response()->json([
            'message' => 'Komentar dan waktu kadaluarsa semua pengguna yang sesuai berhasil diperbarui, dan status voucher diperbarui menjadi sudah digunakan.',
        ]);

    } catch (\Exception $e) {
        Log::error("Error in updateAllHotspotUsersByName: {$e->getMessage()}", [
            'exception' => $e,
        ]);
        return response()->json(['message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/mikrotik/update-data",
 *     summary="Update status voucher berdasarkan data hotspot",
 *     tags={"Voucher"},
 *     @OA\Response(
 *         response=200,
 *         description="Data voucher berhasil diperbarui",
 *         @OA\JsonContent(
 *             type="array",
 *             @OA\Items(
 *                 @OA\Property(property="username", type="string", example="ABC12345"),
 *                 @OA\Property(property="status", type="string", example="Inactive"),
 *                 @OA\Property(property="profile", type="string", example="Standard")
 *             )
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function UpdateData($mikrotikConfig)
{
    try {
        $client = $this->getClientVoucher($mikrotikConfig);

        $hotspotQuery = new Query('/ip/hotspot/user/print');
        $hotspotData = $client->q($hotspotQuery)->read();

        $databaseVouchers = DB::table('voucher_lists')->get();

        $response = [];
        $voucherNamesInHotspot = [];

        foreach ($hotspotData as $user) {
            $username = $user['name'] ?? null;
            $password = $user['password'] ?? null;
            $profile = $user['profile'] ?? null;

            if ($username && $profile !== 'default-trial') {
                $voucherNamesInHotspot[] = $username;

                $dbVoucher = DB::table('voucher_lists')->where('name', $username)->first();

                if ($dbVoucher && $dbVoucher->name === $username) {
                    continue;
                }

                if ($dbVoucher) {
                    DB::table('voucher_lists')
                        ->where('name', $username)
                        ->update(['status' => 'Inactive']);
                }

                $response[] = [
                    'username' => $username,
                    'status' => 'Inactive',
                    'profile' => $profile,
                ];
            }
        }

        foreach ($databaseVouchers as $voucher) {
            if (!in_array($voucher->name, $voucherNamesInHotspot)) {
                DB::table('voucher_lists')
                    ->where('name', $voucher->name)
                    ->update(['status' => 'Already Used']);
            }
        }

        return response()->json($response, 200);

    } catch (\Exception $e) {
        return response()->json(['error' => 'Failed to fetch hotspot users: ' . $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/mikrotik/add-hotspot-login-Annual",
 *     summary="Tambah voucher hotspot baru",
 *     tags={"Voucher"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="voucher_hours", type="integer", example=2),
 *             @OA\Property(property="voucher_count", type="integer", example=5),
 *             @OA\Property(property="profile", type="string", example="Standard")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Voucher berhasil dibuat",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Voucher berhasil dibuat."),
 *             @OA\Property(
 *                 property="generated_vouchers",
 *                 type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="username", type="string", example="ABC12345"),
 *                     @OA\Property(property="password", type="string", example="ABC12345"),
 *                     @OA\Property(property="link_login", type="string", example="https://hotspot.awh.co.id/login?username=ABC12345&password=ABC12345")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(response=400, description="Input tidak valid"),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function AddVoucher(Request $request)
{
    $request->validate([
        'voucher_hours' => 'required|integer|min:1',
        'voucher_count' => 'required|integer|min:1',
        'profile' => 'required|string',
    ]);

    $voucher_hours = $request->input('voucher_hours');
    $voucher_count = $request->input('voucher_count');
    $profile = $request->input('profile');

    try {
        $client = $this->getClientLogin();

        $profileQuery = (new Query('/ip/hotspot/user/profile/print'))
            ->where('name', $profile);

        $profileResult = $client->query($profileQuery)->read();

        if (empty($profileResult)) {
            return response()->json(['message' => "Profile '$profile' tidak ditemukan."], 404);
        }

        $generatedUsernames = [];

        for ($i = 0; $i < $voucher_count; $i++) {
            $username = strtoupper(Str::random(8));
            $password = $username;
            $expiry_time = now()->addMinutes(1)->format('Y-m-d H:i:s');
            $link_login = "https://hotspot.awh.co.id/login?username={$username}&password={$password}";

            $addUserQuery = (new Query('/ip/hotspot/user/add'))
                ->equal('name', $username)
                ->equal('password', $password)
                ->equal('profile', $profile)
                ->equal('comment', "status: inactive, expiry: $expiry_time");

            $client->query($addUserQuery)->read();

            $generatedUsernames[] = [
                'username' => $username,
                'password' => $password,
                'expiry_time' => $expiry_time,
                'waktu' => $voucher_hours,
                'link_login' => $link_login,
            ];

            DB::table('voucher_lists')->insert([
                'name' => $username,
                'waktu' => $voucher_hours,
                'profile' => $profile,
                'password' => $password,
                'status' => 'Inactive',
                'link_login' => $link_login,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Voucher berhasil dibuat.',
            'generated_vouchers' => $generatedUsernames,
            'note' => 'Ini Link Login jika lupa. Jangan dipakai jika sudah login dan waktu sudah di-extend.',
        ]);
    } catch (\Exception $e) {
        return response()->json(['message' => $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/Check-voucher",
 *     tags={"Voucher"},
 *     summary="Check voucher validity",
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"voucher_code"},
 *             @OA\Property(property="voucher_code", type="string", example="ABC123")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Voucher is valid",
 *         @OA\JsonContent(
 *             @OA\Property(property="status", type="string", example="success"),
 *             @OA\Property(property="message", type="string", example="Voucher is valid"),
 *             @OA\Property(
 *                 property="hotspot_users",
 *                 type="array",
 *                 @OA\Items(
 *                     type="object",
 *                     @OA\Property(property="username", type="string", example="user123"),
 *                     @OA\Property(property="status", type="string", example="active")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(
 *         response=400,
 *         description="Invalid voucher"
 *     )
 * )
 */
    public function CheckVoucher(Request $request)
{
    $request->validate([
        'voucher_code' => 'required|string',
    ]);

    $tenants = Tenant::all();

    $voucher = null;
    $mikrotikConfig = null;

    foreach ($tenants as $tenant) {
        tenancy()->initialize($tenant);

        $voucher = DB::table('voucher_lists')->where('name', $request->voucher_code)->first();

        $mikrotikConfig = DB::table('mikrotik_config')->first();

        if ($voucher) {
            break;
        }
    }

    if (!$voucher) {
        return response()->json(['message' => 'Invalid voucher in all tenants'], 400);
    }

    $this->updateAllHotspotUsersByName($mikrotikConfig);
    $hotspotUsers = $this->getHotspotUsers($mikrotikConfig);

    return response()->json([
        'message' => 'Voucher is valid in tenant: ' . $tenant->id,
        'voucher_code' => $voucher->name,
        'mikrotik_config' => $mikrotikConfig,
        'hotspot_users' => $hotspotUsers
    ]);
    }

    public function setHotspotProfile(Request $request)
    {
        $request->validate([
            'profile_name' => 'required|string|max:255',
            'shared_users' => 'required|integer|min:1',
            'rate_limit' => 'nullable|string',
            'link' => 'nullable|string',
        ]);

        $profile_name = $request->input('profile_name');
        $shared_users = $request->input('shared_users');
        $rate_limit = $request->input('rate_limit');
        $link = $request->input('link');

        try {
             $client = $this->getClient();

            $checkQuery = (new Query('/ip/hotspot/user/profile/print'))
                ->where('name', $profile_name);

            $existingProfiles = $client->query($checkQuery)->read();

            if (!empty($existingProfiles)) {
                $existingLink = DB::table('user_profile_link')
                    ->where('name', $profile_name)
                    ->exists();

                if (!$existingLink) {
                    DB::table('user_profile_link')->insert([
                        'name' => $profile_name,
                        'link' => $link,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return response()->json([
                        'message' => 'Profile sudah ada, tapi link-nya belum ada. Saya tambahin dulu ya'
                    ], 200);
                }

                return response()->json(['message' => 'Profile dan link sudah ada, tidak ada perubahan yang dilakukan'], 200);
            } else {
                $addQuery = (new Query('/ip/hotspot/user/profile/add'))
                    ->equal('name', $profile_name)
                    ->equal('shared-users', $shared_users)
                    ->equal('keepalive-timeout', 'none');

                if (!empty($rate_limit)) {
                    $addQuery->equal('rate-limit', $rate_limit);
                }

                $client->query($addQuery)->read();

                return response()->json(['message' => 'Hotspot profile created successfully'], 201);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
        }

    /**
 * @OA\Post(
 *     path="/mikrotik/add-Multiple-user",
 *     summary="Tambah banyak user hotspot berdasarkan lantai dan jumlah kamar",
 *     tags={"Voucher"},
 *     security={{"bearerAuth":{}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="lantai", type="integer", example=2),
 *             @OA\Property(property="jumlah_kamar", type="integer", example=10),
 *             @OA\Property(property="profile", type="string", example="Standard")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="User berhasil ditambahkan",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Voucher berhasil dibuat."),
 *             @OA\Property(
 *                 property="generated_vouchers",
 *                 type="array",
 *                 @OA\Items(
 *                     @OA\Property(property="username", type="string", example="kamar201"),
 *                     @OA\Property(property="password", type="string", example="kamar201"),
 *                     @OA\Property(property="link_login", type="string", example="https://hotspot.awh.co.id/login?username=kamar201&password=kamar201")
 *                 )
 *             )
 *         )
 *     ),
 *     @OA\Response(response=400, description="Input tidak valid"),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function adduserBatch(Request $request)
        {
            $request->validate([
                'lantai' => 'required|integer|min:1',
                'jumlah_kamar' => 'required|integer|min:1',
                'profile' => 'required|string',
            ]);

            $lantai = $request->input('lantai');
            $jumlah_kamar = $request->input('jumlah_kamar');
            $profile = $request->input('profile');

            try {
                $client = $this->getClientLogin();

                $profileQuery = (new Query('/ip/hotspot/user/profile/print'))
                    ->where('name', $profile);

                $profileResult = $client->query($profileQuery)->read();

                if (empty($profileResult)) {
                    return response()->json(['message' => "Profile '$profile' tidak ditemukan."], 404);
                }

                $generatedUsernames = [];
                $existingUsernames = [];

                for ($kamar = 1; $kamar <= $jumlah_kamar; $kamar++) {
                    $kamarNumber = ($lantai * 100) + $kamar;
                    $username =   "kamar" . $kamarNumber;
                    $password = $username;
                    $link_login = "https://hotspot.awh.co.id/login?username={$username}&password={$password}";

                    $userQuery = (new Query('/ip/hotspot/user/print'))
                        ->where('name', $username);

                    $userResult = $client->query($userQuery)->read();

                    if (!empty($userResult)) {
                        $existingUsernames[] = $username;
                        continue;
                    }

                    $addUserQuery = (new Query('/ip/hotspot/user/add'))
                        ->equal('name', $username)
                        ->equal('password', $password)
                        ->equal('profile', $profile);

                    $client->query($addUserQuery)->read();

                    $generatedUsernames[] = [
                        'username' => $username,
                        'password' => $password,
                        'link_login' => $link_login,
                    ];
                }

                if (count($existingUsernames) > 0) {
                    return response()->json([
                        'message' => 'Voucher berhasil dibuat, namun beberapa username sudah ada.',
                        'generated_vouchers' => $generatedUsernames,
                        'existing_usernames' => $existingUsernames,
                    ]);
                }

                return response()->json([
                    'message' => 'Voucher berhasil dibuat.',
                    'generated_vouchers' => $generatedUsernames,
                ]);
            } catch (\Exception $e) {
                return response()->json(['message' => $e->getMessage()], 500);
            }
    }

     public function logApiUsageBytesAllTenant($mikrotikConfig)
{
    try {

        $client = $this->getClientVoucher($mikrotikConfig);

        $userQuery = new Query('/ip/hotspot/user/print');
        $users = $client->query($userQuery)->read();

        $activeQuery = new Query('/ip/hotspot/active/print');
        $activeUsers = $client->query($activeQuery)->read();

        $totalBytesIn = session()->get('total_bytes_in', 0);
        $totalBytesOut = session()->get('total_bytes_out', 0);

        $activeUsersMap = [];
        foreach ($activeUsers as $activeUser) {
            $username = $activeUser['user'];
            $activeUsersMap[$username] = $activeUser;
        }

        $modifiedUsers = array_map(function ($user) use (&$totalBytesIn, &$totalBytesOut, $activeUsersMap) {
            $newUser = [];
            foreach ($user as $key => $value) {
                $newKey = str_replace('.id', 'id', $key);
                $newUser[$newKey] = $value;
            }

            $newUser['bytes-in'] = 0;
            $newUser['bytes-out'] = 0;

            if (isset($activeUsersMap[$user['name']])) {
                $activeUser = $activeUsersMap[$user['name']];
                $newUser['bytes-in'] = isset($activeUser['bytes-in']) ? (int)$activeUser['bytes-in'] : 0;
                $newUser['bytes-out'] = isset($activeUser['bytes-out']) ? (int)$activeUser['bytes-out'] : 0;
            } else {
                $existingUser = DB::table('user_bytes_log')
                    ->where('user_name', $user['name'])
                    ->orderBy('timestamp', 'desc')
                    ->first();

                if ($existingUser) {
                    $newUser['bytes-in'] = (int)$existingUser->bytes_in;
                    $newUser['bytes-out'] = (int)$existingUser->bytes_out;
                }
            }

            $lastLog = DB::table('user_bytes_log')
                ->where('user_name', $newUser['name'])
                ->orderBy('timestamp', 'desc')
                ->first();

            $bytesInDifference = 0;
            $bytesOutDifference = 0;

            if ($lastLog) {
                $bytesInDifference = max(0, $newUser['bytes-in'] - $lastLog->bytes_in);
                $bytesOutDifference = max(0, $newUser['bytes-out'] - $lastLog->bytes_out);
            } else {
                $bytesInDifference = $newUser['bytes-in'];
                $bytesOutDifference = $newUser['bytes-out'];
            }

            $totalBytesIn += $bytesInDifference;
            $totalBytesOut += $bytesOutDifference;

            if ($bytesInDifference > 0 || $bytesOutDifference > 0) {
                DB::table('user_bytes_log')->insert([
                    'user_name' => $newUser['name'],
                    'role' => isset($user['profile']) ? $user['profile'] : 'guest',
                    'bytes_in' => $bytesInDifference,
                    'bytes_out' => $bytesOutDifference,
                    'timestamp' => now(),
                ]);
            }

            return $newUser;
        }, $users);

        $totalBytes = $totalBytesIn + $totalBytesOut;

        session()->put('total_bytes_in', $totalBytesIn);
        session()->put('total_bytes_out', $totalBytesOut);

        return response()->json([
            'total_user' => count($modifiedUsers),
            'users' => $modifiedUsers,
            'total_bytes_in' => $totalBytesIn,
            'total_bytes_out' => $totalBytesOut,
            'total_bytes' => $totalBytes,
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/delete-voucher-all-tenant",
 *     summary="Hapus semua voucher kadaluarsa dan perbarui data semua tenant",
 *     tags={"Voucher"},
 *     @OA\RequestBody(
 *         @OA\JsonContent(
 *             @OA\Property(property="tenant_id", type="integer", example=1, description="Opsional. Jika diisi, hanya akan memproses tenant tersebut.")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Berhasil memperbarui dan menghapus data hotspot",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Successfully updated and deleted hotspot users."),
 *             @OA\Property(property="processed_tenant_id", type="string", example="all")
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function DeleteAlltenant(Request $request)
    {
        $tenantId = $request->input('tenant_id');

        $tenants = $tenantId ? Tenant::where('id', $tenantId)->get() : Tenant::all();

        foreach ($tenants as $tenant) {
            tenancy()->initialize($tenant);

            $mikrotikConfig = DB::table('mikrotik_config')->first();
            if (!$mikrotikConfig) continue;

            $this->deleteExpiredHotspotUsers($mikrotikConfig);
            $this->UpdateData($mikrotikConfig);
            $this->updateAllHotspotUsersByName($mikrotikConfig);
            $this->logApiUsageBytesAllTenant($mikrotikConfig);
        }

        return response()->json([
            'message' => 'Successfully updated and deleted hotspot users.',
            'processed_tenant_id' => $tenantId ?? 'all'
        ]);
    }

    public function deleteExpiredHotspotUsers($mikrotikConfig)
{
    try {
        $client = $this->getClientVoucher($mikrotikConfig);

        $getUsersQuery = new Query('/ip/hotspot/user/print');
        $users = $client->query($getUsersQuery)->read();

        foreach ($users as $user) {
            if (isset($user['comment']) && str_contains($user['comment'], 'status: active')) {
                preg_match('/expiry: ([\d\- :]+)/', $user['comment'], $matches);

                if (!empty($matches[1])) {
                    $expiryTime = strtotime($matches[1]);
                    $currentTime = time();


                    if ($currentTime > $expiryTime) {
                        $getActiveUsersQuery = new Query('/ip/hotspot/active/print');
                        $activeUsers = $client->query($getActiveUsersQuery)->read();

                        foreach ($activeUsers as $activeUser) {
                            if ($activeUser['user'] === $user['name']) {
                                $deleteActiveQuery = (new Query('/ip/hotspot/active/remove'))
                                    ->equal('.id', $activeUser['.id']);
                                $client->query($deleteActiveQuery)->read();
                            }
                        }

                        $deleteUserQuery = (new Query('/ip/hotspot/user/remove'))
                            ->equal('.id', $user['.id']);
                        $client->query($deleteUserQuery)->read();
                    }
                }
            }
        }

        return response()->json([
            'message' => 'Expired users deleted successfully from active sessions and users list.'
        ]);

    } catch (\Exception $e) {
        return response()->json(['message' => $e->getMessage()], 500);
    }
    }

}
