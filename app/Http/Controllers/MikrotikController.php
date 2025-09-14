<?php

namespace App\Http\Controllers;

use App\Models\AkunKantor;
use App\Models\Menu;
use App\Models\Order;
use Carbon\Carbon;
use ArelAyudhi\DhivaProdevWa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RouterOS\Query;

class MikrotikController extends CentralController
{
    protected function sendwa($no_hp, $login_link)
    {
        $token = 'qeTAbqcqiZ6hooBgdtZ32ftcdney1SKGvDhLvS31A4g';
        $wablast = new DhivaProdevWa\ProdevMessages($token);

        $message = "Halo, berikut adalah informasi login Hotspot Anda:\n\n" .
                "\n\nLink Login: $login_link\n\n" .
                "Pastikan Anda sudah login dan waktu akses Anda juga telah diperpanjang.";
        $blast['phone'][0] = $no_hp;

        $blast['message'][0] = $message;

        $wablast->broadcast->sendInstan($blast);
    }

    public function calculateOrderDetails(array $menu_ids)
    {
        $menus = Menu::whereIn('id', $menu_ids)->get();

        if ($menus->isEmpty()) {
            return null;
        }

        $total_harga = $menus->sum('price');
        $total_expiry_time = $menus->sum('expiry_time');

        return (object)[
            'total_harga' => $total_harga,
            'total_expiry_time' => $total_expiry_time
        ];
    }

    /**
 * @OA\Get(
 *     path="/api/mikrotik/get-Hotspot-by-phone/{no_hp}",
 *     summary="Ambil data user hotspot berdasarkan nomor HP",
 *     tags={"Hotspot"},
 *     security={{"bearerAuth": {}},{"X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Parameter(
 *         name="no_hp",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string"),
 *         description="Nomor HP pengguna",
 *         example="08123456789"
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Data user berhasil ditemukan",
 *         @OA\JsonContent(
 *             @OA\Property(property="user", type="object")
 *         )
 *     ),
 *     @OA\Response(response=404, description="User tidak ditemukan"),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function getHotspotUserByPhoneNumber($no_hp)
{
    try {
        $client = $this->getClientLogin();

        $query = new Query('/ip/hotspot/user/print');
        $query->where('name', $no_hp);

        $users = $client->query($query)->read();

        if (empty($users)) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $user = $users[0];

        $modifiedUser = [];
        foreach ($user as $key => $value) {
            $newKey = str_replace('.id', 'id', $key);
            $modifiedUser[$newKey] = $value;
        }

        $profileName = $user['profile'] ?? null;
        $comment = $user['comment'] ?? 'No comment';

        $link = null;
        if ($profileName) {
            $link = DB::table('user_profile_link')
                ->where('name', $profileName)
                ->value('link');
        }

        $today = Carbon::today()->format('Y-m-d');
        $startDateTime = $today . ' 00:00:00';
        $endDateTime = $today . ' 23:59:59';

        $bytesLog = DB::table('user_bytes_log')
            ->where('user_name', $no_hp)
            ->whereBetween('timestamp', [$startDateTime, $endDateTime])
            ->select(
                DB::raw('SUM(bytes_in) as total_bytes_in'),
                DB::raw('SUM(bytes_out) as total_bytes_out')
            )
            ->first();

        $bytesIn = $bytesLog?->total_bytes_in ?? 0;
        $bytesOut = $bytesLog?->total_bytes_out ?? 0;
        $totalBytes = $bytesIn + $bytesOut;

        $modifiedUser['link'] = $link ?? 'No link found';
        $modifiedUser['comment'] = $comment;
        $modifiedUser['bytes_in'] = $bytesIn;
        $modifiedUser['bytes_out'] = $bytesOut;
        $modifiedUser['total_bytes'] = $totalBytes;

        return response()->json(['user' => $modifiedUser]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Get(
 *     path="/api/mikrotik/get-Hotspot-users/{profile_name}",
 *     summary="Ambil semua user berdasarkan nama profile",
 *     tags={"Hotspot"},
 *     security={{"bearerAuth": {}},{"X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Parameter(
 *         name="profile_name",
 *         in="path",
 *         required=true,
 *         @OA\Schema(type="string"),
 *         description="Nama profile hotspot",
 *         example="customer"
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Data user berhasil diambil",
 *         @OA\JsonContent(
 *             @OA\Property(
 *                 property="users",
 *                 type="array",
 *                 @OA\Items(type="object")
 *             ),
 *             @OA\Property(property="total_bytes_in", type="integer", example=1234567),
 *             @OA\Property(property="total_bytes_out", type="integer", example=7654321)
 *         )
 *     ),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function getHotspotUsersByProfileName($profile_name)
{
    try {
         $client = $this->getClientLogin();

        $query = new Query('/ip/hotspot/user/print');
        $query->where('profile', $profile_name);

        $users = $client->query($query)->read();

        if (empty($users)) {

            return response()->json([
                'users' => [],
                'total_bytes_in' => 0,
                'total_bytes_out' => 0
            ], 200);
        }

        $modifiedUsers = [];

        $totalBytesIn = 0;
        $totalBytesOut = 0;

        foreach ($users as $user) {
            $modifiedUser = [];
            foreach ($user as $key => $value) {
                $newKey = str_replace('.id', 'id', $key);
                $modifiedUser[$newKey] = $value;
            }

            if (isset($user['bytes-in'])) {
                $totalBytesIn += (int)$user['bytes-in'];
            }
            if (isset($user['bytes-out'])) {
                $totalBytesOut += (int)$user['bytes-out'];
            }

            $modifiedUsers[] = $modifiedUser;
        }

        return response()->json([
            'users' => $modifiedUsers,
            'total_bytes_in' => $totalBytesIn,
            'total_bytes_out' => $totalBytesOut
        ], 200);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/api/mikrotik/add-hotspot-login",
 *     summary="Tambah user Hotspot sekaligus login dan extend waktu",
 *     tags={"Hotspot"},
 *     security={{"bearerAuth": {}},{"X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"no_hp","menu_ids"},
 *             @OA\Property(property="no_hp", type="string", example="08123456789"),
 *             @OA\Property(property="name", type="string", example="John Doe"),
 *             @OA\Property(
 *                 property="menu_ids",
 *                 type="array",
 *                 @OA\Items(type="integer", example=1)
 *             ),
 *             @OA\Property(property="profile", type="string", example="customer")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="User berhasil dibuat atau diperpanjang",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="User baru ditambahkan dan login berhasil."),
 *             @OA\Property(property="login_link", type="string", example="http://192.168.51.1/login?username=08123456789&password=08123456789"),
 *             @OA\Property(property="Waktu Defaultnya", type="string", example="6 Jam")
 *         )
 *     ),
 *     @OA\Response(response=400, description="Nomor HP tidak valid"),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function addHotspotUserwithMenu(Request $request)
{
    $request->validate([
        'no_hp' => 'required|string|max:20',
        'name' => 'sometimes|required|string|max:255',
        'menu_ids' => 'required|array',
        'profile' => 'nullable|string|max:50'
    ]);

    $profile = $request->input('profile', 'customer');
    $no_hp = $request->input('no_hp');
    $menu_ids = $request->input('menu_ids');
    $name = $request->input('name', null);

    try {
         $client = $this->getClientLogin();

        $checkQuery = (new Query('/ip/hotspot/user/print'))->where('name', $no_hp);
        $existingUsers = $client->query($checkQuery)->read();

        $expiryExtensionHours = 6;
        $defaultExpiryTime = Carbon::now()->addHours($expiryExtensionHours);

        if (!empty($existingUsers)) {
            $comment = $existingUsers[0]['comment'] ?? '';
            $existingName = $name;
            $expiryTime = null;

            $isInactive = strpos($comment, 'status: inactive') !== false;
            $isActive = strpos($comment, 'status: active') !== false;

            if (strpos($comment, 'Expiry:') !== false) {
                $parts = explode(', ', $comment);
                foreach ($parts as $part) {
                    if (strpos($part, 'Expiry:') === 0) {
                        $expiryTime = Carbon::parse(trim(substr($part, strlen('Expiry: '))));
                    } else {
                        $existingName = $part;
                    }
                }
            }

            if ($isInactive) {
                return response()->json([
                    'message' => 'User ditemukan namun dalam status inactive. Tidak ada perubahan yang dilakukan.'
                ]);
            }

            if ($isActive) {
                if ($expiryTime && $expiryTime->greaterThan(Carbon::now())) {
                    $newExpiryTime = $expiryTime->addHours($expiryExtensionHours);
                } else {
                    $newExpiryTime = $defaultExpiryTime;
                }

                $updatedComment = "status: active, {$existingName}, Expiry: " . $newExpiryTime->format('Y-m-d H:i:s');

                $updateUserQuery = (new Query('/ip/hotspot/user/set'))
                    ->equal('.id', $existingUsers[0]['.id'])
                    ->equal('comment', $updatedComment);

                $client->query($updateUserQuery)->read();

                foreach ($menu_ids as $menu_id) {
                    $existingOrder = Order::where('no_hp', $no_hp)->where('menu_id', $menu_id)->first();

                    if ($existingOrder) {
                        $existingOrder->update([
                            'expiry_at' => $newExpiryTime->format('Y-m-d H:i:s')
                        ]);
                    } else {
                        Order::create([
                            'no_hp' => $no_hp,
                            'menu_id' => $menu_id,
                            'expiry_at' => $newExpiryTime->format('Y-m-d H:i:s')
                        ]);
                    }
                }

                if (!empty($no_hp)) {
                    $loginLink = "http://192.168.51.1/login?username={$no_hp}&password={$no_hp}";
                    $this->sendwa($no_hp, $loginLink);
                } else {
                    return response()->json([
                        'message' => 'Nomor HP tidak valid atau kosong.',
                        'status' => 'failed'
                    ], 400);
                }

                // $hotspotController = app()->make(\App\Http\Controllers\MqttController::class);
                // $hotspotController->getHotspotUsers1();

                return response()->json([
                    'message' => 'User diperpanjang dan login berhasil. Expiry time: ' . $newExpiryTime->format('Y-m-d H:i:s'),
                    'login_link' => $loginLink ?? null,
                    'Waktu Defaultnya' => '6 Jam',
                    'note' => 'Ini Link Login kalo lupa ya, kalo kamu udah login gak usah di pake sama waktu kamu juga udah diextend'
                ]);
            }
        } else {
            $newExpiryTime = $defaultExpiryTime;

            $addUserQuery = (new Query('/ip/hotspot/user/add'))
                ->equal('name', $no_hp)
                ->equal('password', $no_hp)
                ->equal('profile', $profile)
                ->equal('comment', "status: inactive, name: {$name}, Expiry: {$newExpiryTime->format('Y-m-d H:i:s')}");

            $client->query($addUserQuery)->read();

            foreach ($menu_ids as $menu_id) {
                Order::create([
                    'no_hp' => $no_hp,
                    'menu_id' => $menu_id,
                    'expiry_at' => $newExpiryTime->format('Y-m-d H:i:s')
                ]);
            }

            if (!empty($no_hp)) {
                $loginLink = "http://192.168.51.1/login?username={$no_hp}&password={$no_hp}";
                $this->sendwa($no_hp, $loginLink);
            } else {
                return response()->json([
                    'message' => 'Nomor HP tidak valid atau kosong.',
                    'status' => 'failed'
                ], 400);
            }

            // $hotspotController = app()->make(\App\Http\Controllers\MqttController::class);
            // $hotspotController->getHotspotUsers1();

            return response()->json([
                'message' => 'User baru ditambahkan dan login berhasil. Expiry time: ' . $newExpiryTime->format('Y-m-d H:i:s'),
                'login_link' => $loginLink ?? null,
                'Waktu Defaultnya' => '6 Jam',
                'note' => 'Ini Link Login kalo lupa ya, kalo kamu udah login gak usah di pake sama waktu kamu juga udah diextend'
            ]);
        }

    } catch (\Exception $e) {
        return response()->json(['message' => $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/api/mikrotik/add-Hotspot-User",
 *     summary="Tambah user Hotspot sederhana tanpa expiry",
 *     tags={"Hotspot"},
 *     security={{"bearerAuth": {}},{"X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             required={"no_hp"},
 *             @OA\Property(property="no_hp", type="string", example="08123456789"),
 *             @OA\Property(property="profile", type="string", example="customer")
 *         )
 *     ),
 *     @OA\Response(
 *         response=201,
 *         description="User berhasil ditambahkan",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="User baru ditambahkan tanpa expiry time."),
 *             @OA\Property(property="no_hp", type="string", example="08123456789")
 *         )
 *     ),
 *     @OA\Response(response=409, description="User sudah ada"),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function addHotspotUser(Request $request)
{
    $request->validate([
        'no_hp' => 'required|string|max:20',
        'profile' => 'nullable|string|max:50'
    ]);

    $profile = $request->input('profile', 'customer');
    $no_hp = $request->input('no_hp');

    try {
         $client = $this->getClientLogin();

        $checkQuery = (new Query('/ip/hotspot/user/print'))->where('name', $no_hp);
        $existingUsers = $client->query($checkQuery)->read();

        if (!empty($existingUsers)) {
            return response()->json(['message' => 'User sudah ada di MikroTik.'], 409);
        } else {
            $addUserQuery = (new Query('/ip/hotspot/user/add'))
                ->equal('name', $no_hp)
                ->equal('password', $no_hp)
                ->equal('profile', $profile)
                ->equal('disabled', 'false');

            $client->query($addUserQuery)->read();

            return response()->json([
                'message' => 'User baru ditambahkan tanpa expiry time.',
                'no_hp' => $no_hp,
            ], 201);
        }
    } catch (\Exception $e) {
        return response()->json(['message' => $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/api/mikrotik/hotspot-user/{no_hp}",
 *     summary="Edit data user hotspot",
 *     tags={"Hotspot"},
 *     security={{"bearerAuth": {}},{"X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Parameter(
 *         name="no_hp",
 *         in="path",
 *         required=true,
 *         description="Nomor HP yang ingin diupdate",
 *         @OA\Schema(type="string"),
 *         example="08123456789"
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             @OA\Property(property="name", type="string", example="John Doe"),
 *             @OA\Property(property="profile", type="string", example="customer"),
 *             @OA\Property(property="comment", type="string", example="status: active, Expiry: 2025-09-15 18:00:00")
 *         )
 *     ),
 *     @OA\Response(response=200, description="User berhasil diperbarui"),
 *     @OA\Response(response=404, description="User tidak ditemukan"),
 *     @OA\Response(response=409, description="Nama sudah digunakan"),
 *     @OA\Response(response=500, description="Kesalahan server")
 * )
 */
    public function editHotspotUser(Request $request, $no_hp)
{
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

        if ($request->has('name') && $request->input('name') !== $no_hp) {
            $nameCheckQuery = (new Query('/ip/hotspot/user/print'))->where('name', $request->input('name'));
            $nameExists = $client->query($nameCheckQuery)->read();

            if (!empty($nameExists)) {
                return response()->json(['message' => 'Nama sudah digunakan'], 409);
            }

            $nameExistsInDB = DB::table('voucher_lists')
                ->where('name', $request->input('name'))
                ->where('name', '!=', $no_hp)
                ->exists();

            if ($nameExistsInDB) {
                return response()->json(['message' => 'Nama sudah digunakan'], 409);
            }
        }

        $updateUserQuery = (new Query('/ip/hotspot/user/set'))
            ->equal('.id', $userId);

        if ($request->has('name')) {
            $updateUserQuery->equal('name', $request->input('name'));
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

        return response()->json(['message' => 'User berhasil diperbarui.'], 200);

    } catch (\Exception $e) {
        return response()->json(['error' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
    }
    }

    public function updateAllHotspotUsersByPhoneNumber()
{
    try {
         $client = $this->getClientLogin();

        $getActiveUsersQuery = new Query('/ip/hotspot/active/print');
        $activeUsers = $client->query($getActiveUsersQuery)->read();

        if (empty($activeUsers)) {
            return response()->json(['message' => 'Tidak ada pengguna aktif.'], 200);
        }

        $activePhoneNumbers = array_column($activeUsers, 'user');

        foreach ($activePhoneNumbers as $no_hp) {
            $getUserQuery = (new Query('/ip/hotspot/user/print'))->where('name', $no_hp);
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

                $validOrders = Order::where('no_hp', $no_hp)
                    ->where('expiry_at', '>', Carbon::now()->subMinutes(5))
                    ->get();

                if ($validOrders->isEmpty()) {
                    continue;
                }

                $menu_ids = $validOrders->pluck('menu_id')->toArray();

                $orderDetails = $this->calculateOrderDetails($menu_ids);

                if (is_null($orderDetails) || $orderDetails->total_expiry_time <= 0) {
                    continue;
                }

                $newExpiryTime = Carbon::now()->addMinutes($orderDetails->total_expiry_time);

                if (preg_match('/name: ([^,]+)/', $comment, $matches)) {
                    $name = $matches[1];
                } else {
                    $name = $no_hp;
                }

                $updatedComment = "status: active, name: {$name}, Expiry: {$newExpiryTime->format('Y-m-d H:i:s')}";
                $updateUserQuery = (new Query('/ip/hotspot/user/set'))
                    ->equal('.id', $userId)
                    ->equal('comment', $updatedComment);

                $client->query($updateUserQuery)->read();

                foreach ($validOrders as $order) {
                    $order->update([
                        'expiry_at' => $newExpiryTime->format('Y-m-d H:i:s')
                    ]);
                }
            }
        }

        $this->deleteExpiredHotspotUsers();

        return response()->json([
            'message' => 'Komentar dan waktu kadaluarsa semua pengguna yang sesuai berhasil diperbarui.',
        ]);

    } catch (\Exception $e) {
        return response()->json(['message' => $e->getMessage()], 500);
    }
    }

    public function deleteExpiredHotspotUsers()
    {
        $lock = Cache::lock('mikrotik_hotspot_user_operation', 10);

        if ($lock->get()) {
            try {
         $client = $this->getClientLogin();
                $query = new Query('/ip/hotspot/user/print');
                $users = $client->query($query)->read();

                foreach ($users as $user) {
                    if (isset($user['comment']) && preg_match('/Expiry:\s*([\d\/\-:\s]+)/', $user['comment'], $matches)) {
                        try {
                            $expiryTime = null;

                            if (strpos($matches[1], '/') !== false) {
                                $expiryTime = Carbon::createFromFormat('Y/m/d H:i:s', $matches[1])->setTimezone(config('app.timezone'));
                            } elseif (strpos($matches[1], '-') !== false) {
                                $expiryTime = Carbon::createFromFormat('Y-m-d H:i:s', $matches[1])->setTimezone(config('app.timezone'));
                            }

                            if ($expiryTime && Carbon::now()->greaterThanOrEqualTo($expiryTime)) {

                                $deleteQuery = (new Query('/ip/hotspot/user/remove'))->equal('.id', $user['.id']);
                                $client->query($deleteQuery)->read();

                                $activeSessionsQuery = (new Query('/ip/hotspot/active/print'))
                                    ->where('user', $user['name']);
                                $activeSessions = $client->query($activeSessionsQuery)->read();

                                foreach ($activeSessions as $session) {
                                    $terminateSessionQuery = (new Query('/ip/hotspot/active/remove'))
                                        ->equal('.id', $session['.id']);

                                    $client->query($terminateSessionQuery)->read();
                                }
                            }
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }

                // $hotspotController = app()->make(\App\Http\Controllers\MqttController::class);
                // $hotspotController->getHotspotUsers1();

                return response()->json(['message' => 'Expired hotspot users and their active connections deleted successfully']);
            } catch (\Exception $e) {
                return response()->json(['error' => $e->getMessage()], 500);
            } finally {
                $lock->release();
            }
        } else {
            return response()->json(['message' => 'Another hotspot user operation is in progress'], 429);
        }
    }
}
