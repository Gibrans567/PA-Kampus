<?php

namespace App\Http\Controllers;

use App\Models\AkunKantor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RouterOS\Query;

class ByteController extends CentralController
{
    public function getHotspotUsers()
{
    try {
        // Pastikan client berhasil dibuat
        $client = $this->getClientLogin();
        if (!$client) {
            return response()->json(['error' => 'Gagal terhubung ke MikroTik'], 500);
        }

        // Get all hotspot users
        $userQuery = new Query('/ip/hotspot/user/print');
        $users = $client->query($userQuery)->read();

        // Get active hotspot connections
        $activeQuery = new Query('/ip/hotspot/active/print');
        $activeUsers = $client->query($activeQuery)->read();

        // Get hotspot profiles (which contain shared-users setting)
        $profileQuery = new Query('/ip/hotspot/profile/print');
        $profiles = $client->query($profileQuery)->read();

        // Ambil nilai dari session atau gunakan default 0
        $totalBytesIn = session()->get('total_bytes_in', 0);
        $totalBytesOut = session()->get('total_bytes_out', 0);

        // Create a map of profiles by name for quick lookup
        $profileMap = [];
        foreach ($profiles as $profile) {
            if (isset($profile['name'])) {
                $profileMap[$profile['name']] = $profile;
            }
        }

        // Count active users per profile
        $activeUsersByProfile = [];
        foreach ($activeUsers as $activeUser) {
            $server = isset($activeUser['server']) ? $activeUser['server'] : 'default';
            if (!isset($activeUsersByProfile[$server])) {
                $activeUsersByProfile[$server] = 0;
            }
            $activeUsersByProfile[$server]++;
        }

        // Calculate shared users by comparing active connections with profile limits
        $totalActiveUsers = count($activeUsers);
        $devicesOnSharedAccounts = 0;
        $totalSharedUsers = 0;

        foreach ($profileMap as $profileName => $profile) {
            if (isset($activeUsersByProfile[$profileName]) && isset($profile['shared-users'])) {
                $activeUsersCount = $activeUsersByProfile[$profileName];
                $sharedUsersLimit = (int)$profile['shared-users'];

                // If shared-users is greater than 1, this profile allows sharing
                if ($sharedUsersLimit > 1) {
                    // Count currently active users on this profile that are considered "shared"
                    $devicesOnSharedAccounts += $activeUsersCount;
                    $totalSharedUsers += min($activeUsersCount, $sharedUsersLimit);
                }
            }
        }

        // Create active users map for data enrichment
        $activeUsersMap = [];
        $activeUserCounts = [];
        foreach ($activeUsers as $activeUser) {
            if (isset($activeUser['user'])) {
                $username = $activeUser['user'];
                if (!isset($activeUserCounts[$username])) {
                    $activeUserCounts[$username] = 1;
                } else {
                    $activeUserCounts[$username]++;
                }
                $activeUsersMap[$username] = $activeUser;
            }
        }

        // Get profile shared-users limits
        $profileSharedLimits = [];
        foreach ($profiles as $profile) {
            if (isset($profile['name']) && isset($profile['shared-users'])) {
                $profileSharedLimits[$profile['name']] = (int)$profile['shared-users'];
            }
        }

        // Default shared limit if not found in any profile
        $defaultSharedLimit = 1;
        foreach ($profileSharedLimits as $limit) {
            if ($limit > $defaultSharedLimit) {
                $defaultSharedLimit = $limit;
            }
        }

        // Reset totals untuk kalkulasi ulang yang akurat
        $currentTotalBytesIn = 0;
        $currentTotalBytesOut = 0;

        $modifiedUsers = array_map(function ($user) use (
            &$currentTotalBytesIn,
            &$currentTotalBytesOut,
            $activeUsersMap,
            $activeUserCounts,
            $profileMap,
            $profileSharedLimits,
            $defaultSharedLimit
        ) {
            // Pastikan user memiliki nama
            if (!isset($user['name'])) {
                return null;
            }

            $newUser = [];
            foreach ($user as $key => $value) {
                $newKey = str_replace('.id', 'id', $key);
                $newUser[$newKey] = $value;
            }

            // Get the user's profile
            $userProfile = isset($user['profile']) ? $user['profile'] : 'default';

            // Cari profile yang cocok dengan normalisasi nama
            $matchedProfile = null;
            foreach ($profileMap as $profileName => $profile) {
                // Compare case-insensitive and ignoring spaces
                $normalizedUserProfile = strtolower(str_replace(' ', '', $userProfile));
                $normalizedProfileName = strtolower(str_replace(' ', '', $profileName));

                if ($normalizedUserProfile == $normalizedProfileName) {
                    $matchedProfile = $profileName;
                    break;
                }
            }

            // Get the shared user limit
            $sharedLimit = $defaultSharedLimit; // Default ke nilai yang ditemukan sebelumnya
            if ($matchedProfile && isset($profileMap[$matchedProfile]['shared-users'])) {
                $sharedLimit = (int)$profileMap[$matchedProfile]['shared-users'];
            }

            // Set data berdasarkan status aktif user
            if (isset($activeUsersMap[$user['name']])) {
                $activeUser = $activeUsersMap[$user['name']];
                $newUser['bytes-in'] = isset($activeUser['bytes-in']) ? (int)$activeUser['bytes-in'] : 0;
                $newUser['bytes-out'] = isset($activeUser['bytes-out']) ? (int)$activeUser['bytes-out'] : 0;

                // Add device count as a ratio of active devices to shared limit
                $deviceCount = $activeUserCounts[$user['name']] ?? 0;
                $newUser['device-count'] = $deviceCount . '/' . $sharedLimit;

                // Add bytes to total
                $currentTotalBytesIn += $newUser['bytes-in'];
                $currentTotalBytesOut += $newUser['bytes-out'];
            } else {
                try {
                    $existingUser = DB::table('user_bytes_log')
                        ->where('user_name', $user['name'])
                        ->orderBy('timestamp', 'desc')
                        ->first();

                    if ($existingUser) {
                        $newUser['bytes-in'] = (int)$existingUser->bytes_in;
                        $newUser['bytes-out'] = (int)$existingUser->bytes_out;

                        // Add bytes to total
                        $currentTotalBytesIn += $newUser['bytes-in'];
                        $currentTotalBytesOut += $newUser['bytes-out'];
                    } else {
                        $newUser['bytes-in'] = 0;
                        $newUser['bytes-out'] = 0;
                    }
                } catch (\Exception $dbException) {
                    // Log database error, but continue processing
                    Log::error('Database error: ' . $dbException->getMessage());
                    $newUser['bytes-in'] = 0;
                    $newUser['bytes-out'] = 0;
                }

                $newUser['device-count'] = '0/' . $sharedLimit;
            }

            return $newUser;
        }, $users);

        // Filter out null values from the result
        $modifiedUsers = array_filter($modifiedUsers);

        // Update session dengan nilai terbaru
        session()->put('total_bytes_in', $currentTotalBytesIn);
        session()->put('total_bytes_out', $currentTotalBytesOut);

        $totalBytes = $currentTotalBytesIn + $currentTotalBytesOut;

        return response()->json([
            'total_user' => count($modifiedUsers),
            'users' => array_values($modifiedUsers), // Reset array keys
            'total_bytes_in' => $currentTotalBytesIn,
            'total_bytes_out' => $currentTotalBytesOut,
            'total_bytes' => $totalBytes,
            'active_users' => $totalActiveUsers,
            'shared_users' => $totalSharedUsers,
            'devices_on_shared_accounts' => $devicesOnSharedAccounts
        ]);
    } catch (\Exception $e) {
        Log::error('Hotspot Users Error: ' . $e->getMessage());
        return response()->json([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    }
}

    public function deleteHotspotUserByPhoneNumber($no_hp)
{
    try {

        // Get the client for login (Mikrotik)
        $client = $this->getClientLogin();

        // Query to find user by phone number in Mikrotik
        $query = new Query('/ip/hotspot/user/print');
        $query->where('name', $no_hp);

        // Fetch the user data from MikroTik
        $users = $client->query($query)->read();

        if (empty($users)) {
            // User not found in Mikrotik but may still exist in the database
            $deletedFromDB = DB::table('voucher_lists')->where('name', $no_hp)->delete();

            if ($deletedFromDB) {
                return response()->json(['message' => 'User not found in Mikrotik, but deleted from database.']);
            } else {
                return response()->json(['message' => 'User not found in both MikroTik and database.'], 404);
            }
        }

        $user = $users[0];

        // Query for active sessions associated with the user
        $activeSessionsQuery = (new Query('/ip/hotspot/active/print'))
            ->where('user', $user['name']);

        // Fetch active sessions for the user
        $activeSessions = $client->query($activeSessionsQuery)->read();

        // Terminate all active sessions for the user
        foreach ($activeSessions as $session) {
            $terminateSessionQuery = (new Query('/ip/hotspot/active/remove'))
                ->equal('.id', $session['.id']);

            $client->query($terminateSessionQuery)->read();
        }

        // Delete the hotspot user from MikroTik
        $deleteQuery = (new Query('/ip/hotspot/user/remove'))->equal('.id', $user['.id']);
        $client->query($deleteQuery)->read();

        // Delete the associated records from the voucher_lists table by matching no_hp/username
        DB::table('voucher_lists')->where('name', $no_hp)->delete();

        return response()->json(['message' => 'Hotspot user deleted successfully']);

    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }


    public function getHotspotProfile(Request $request)
{
    try {
          $client = $this->getClientLogin();

        $profileQuery = new Query('/ip/hotspot/user/profile/print');
        $profiles = $client->query($profileQuery)->read();

        $userQuery = new Query('/ip/hotspot/user/print');
        $users = $client->query($userQuery)->read();
        if (!empty($profiles)) {
            $result = [];

            foreach ($profiles as $profile) {
                $profileName = $profile['name'];
                $result[] = [
                    'profile_name' => $profileName,
                    'shared-users' => $profile['shared-users'] ?? 'Not set',
                    'rate_limit' => $profile['rate-limit'] ?? 'Not set',
                ];
            }

            return response()->json(['profiles' => $result], 200);
        } else {
            return response()->json(['message' => 'No profiles found'], 404);
        }
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

    public function getHotspotUsersByDateRangeWithLoginCheck(Request $request)
{
    try {
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');

        if (!$startDate || !$endDate) {
            return response()->json(['error' => 'Tanggal awal dan akhir harus disediakan'], 400);
        }

        $startDate = $startDate . ' 00:00:00';
        $endDate = $endDate . ' 23:59:59';

        $users = DB::table('user_bytes_log')
            ->select(
                'user_name',
                'role',
                DB::raw('SUM(bytes_in) as total_bytes_in'),
                DB::raw('SUM(bytes_out) as total_bytes_out'),
                DB::raw('(SUM(bytes_in) + SUM(bytes_out)) as total_user_bytes')
            )
            ->whereBetween('timestamp', [$startDate, $endDate])
            ->groupBy('user_name', 'role')
            ->orderBy(DB::raw('SUM(bytes_in) + SUM(bytes_out)'), 'desc')
            ->get();

        $totalBytesIn = DB::table('user_bytes_log')
            ->whereBetween('timestamp', [$startDate, $endDate])
            ->sum('bytes_in');

        $totalBytesOut = DB::table('user_bytes_log')
            ->whereBetween('timestamp', [$startDate, $endDate])
            ->sum('bytes_out');

        $totalBytes = $totalBytesIn + $totalBytesOut;

        return response()->json([
            'users' => $users,
            'total_bytes_in' => $totalBytesIn,
            'total_bytes_out' => $totalBytesOut,
            'total_bytes' => $totalBytes,
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

    public function getHotspotUsersByDateRange1(Request $request)
{
    try {
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');

        if (!$startDate || !$endDate) {
            return response()->json(['error' => 'Tanggal awal dan akhir harus disediakan'], 400);
        }

        $startDate = $startDate . ' 00:00:00';
        $endDate = $endDate . ' 23:59:59';


        $logs = DB::table('user_bytes_log')
            ->select(
                DB::raw('DATE(timestamp) as date'),
                DB::raw('SUM(bytes_in) as total_bytes_in'),
                DB::raw('SUM(bytes_out) as total_bytes_out'),
                DB::raw('(SUM(bytes_in) + SUM(bytes_out)) as total_bytes')
            )
            ->whereBetween('timestamp', [$startDate, $endDate])
            ->groupBy(DB::raw('DATE(timestamp)'))
            ->orderBy(DB::raw('DATE(timestamp)'), 'asc')
            ->get();


        $uniqueRoles = DB::table('user_bytes_log')
            ->select('role')
            ->whereBetween('timestamp', [$startDate, $endDate])
            ->distinct()
            ->pluck('role');

        foreach ($logs as $log) {
            $largestUser = DB::table('user_bytes_log')
                ->select(
                    'user_name',
                    'role',
                    DB::raw('(bytes_in + bytes_out) as total_user_bytes')
                )
                ->whereDate('timestamp', $log->date)
                ->orderBy('total_user_bytes', 'desc')
                ->first();

            if ($largestUser && $log->total_bytes > 0) {
                $largestUserPercentage = round(($largestUser->total_user_bytes / $log->total_bytes) * 100);
            } else {
                $largestUserPercentage = 0;
            }

                $log->largest_user = [
                'user_name' => $largestUser->user_name,
                'role' => $largestUser->role,
                'percentage' => $largestUserPercentage . "%"
            ];

            $users = DB::table('user_bytes_log')
                ->select(
                    'user_name',
                    'role',
                    DB::raw('SUM(bytes_in) as total_bytes_in'),
                    DB::raw('SUM(bytes_out) as total_bytes_out'),
                    DB::raw('(SUM(bytes_in) + SUM(bytes_out)) as total_user_bytes')
                )
                ->whereDate('timestamp', $log->date)
                ->groupBy('user_name', 'role')
                ->orderBy('total_user_bytes', 'desc')
                ->get();

            $log->all_users = $users;
        }

        $totalBytesIn = $logs->sum('total_bytes_in');
        $totalBytesOut = $logs->sum('total_bytes_out');
        $totalBytes = $totalBytesIn + $totalBytesOut;

        return response()->json([
            'details' => $logs,
            'total_bytes_in' => $totalBytesIn,
            'total_bytes_out' => $totalBytesOut,
            'total_bytes' => $totalBytes,
            'unique_roles' => $uniqueRoles,
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

    public function getHotspotUsersByUniqueRole(Request $request)
{
    try {
        $role = $request->input('role');
        $startDate = $request->input('startDate');
        $endDate = $request->input('endDate');

        if (!$startDate || !$endDate) {
            return response()->json(['error' => 'Parameter harus lengkap'], 400);
        }

        $startDate = $startDate . ' 00:00:00';
        $endDate = $endDate . ' 23:59:59';

        $dbTable = 'user_bytes_log';
        $columnName = 'user_name';

        $query = DB::table($dbTable)
            ->select(
                DB::raw('DATE(timestamp) as date'),
                DB::raw('SUM(bytes_in) as total_bytes_in'),
                DB::raw('SUM(bytes_out) as total_bytes_out'),
                DB::raw('(SUM(bytes_in) + SUM(bytes_out)) as total_bytes')
            )
            ->whereBetween('timestamp', [$startDate, $endDate]);

        if ($role !== "All") {
            $query->where('role', $role);
        }

        $logs = $query->groupBy(DB::raw('DATE(timestamp)'))
            ->orderBy(DB::raw('DATE(timestamp)'), 'asc')
            ->get();

        $totalBytesIn = $logs->sum('total_bytes_in');
        $totalBytesOut = $logs->sum('total_bytes_out');
        $totalBytes = $totalBytesIn + $totalBytesOut;

        foreach ($logs as $log) {
            $largestUserQuery = DB::table($dbTable)
                ->select($columnName, DB::raw('(bytes_in + bytes_out) as total_user_bytes'))
                ->whereDate('timestamp', $log->date);

            if ($role !== "All") {
                $largestUserQuery->where('role', $role);
            }

            $largestUser = $largestUserQuery->orderBy('total_user_bytes', 'desc')->first();

            $largestUserPercentage = ($largestUser && $log->total_bytes > 0) ? round(($largestUser->total_user_bytes / $log->total_bytes) * 100) : 0;

            $log->largest_user = [
                $columnName => $largestUser->$columnName ?? null,
                'percentage' => $largestUserPercentage . "%"
            ];

            $usersQuery = DB::table($dbTable)
                ->select($columnName, DB::raw('SUM(bytes_in) as total_bytes_in'), DB::raw('SUM(bytes_out) as total_bytes_out'), DB::raw('(SUM(bytes_in) + SUM(bytes_out)) as total_user_bytes'))
                ->whereDate('timestamp', $log->date)
                ->groupBy($columnName)
                ->orderBy('total_user_bytes', 'desc');

            if ($role !== "All") {
                $usersQuery->where('role', $role);
            }

            $users = $usersQuery->get();

            $log->all_users = $users;
        }

        // Tambahan: total user unik dalam rentang waktu
        $uniqueUsersQuery = DB::table($dbTable)
            ->select($columnName)
            ->distinct()
            ->whereBetween('timestamp', [$startDate, $endDate]);

        if ($role !== "All") {
            $uniqueUsersQuery->where('role', $role);
        }

        $totalUniqueUsers = $uniqueUsersQuery->count();

        $this->logApiUsageBytes();

        return response()->json([
            'details' => $logs,
            'total_bytes_in' => $totalBytesIn,
            'total_bytes_out' => $totalBytesOut,
            'total_bytes' => $totalBytes,
            'total_users' => $totalUniqueUsers, // Tambahan
            'role' => $role,
            'dbTable' => $dbTable
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }



    public function logApiUsageBytes()
{
    try {

        $client = $this->getClientLogin();

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

            // Initialize bytes-in and bytes-out
            $newUser['bytes-in'] = 0;
            $newUser['bytes-out'] = 0;

            // Check if the user is active
            if (isset($activeUsersMap[$user['name']])) {
                $activeUser = $activeUsersMap[$user['name']];
                $newUser['bytes-in'] = isset($activeUser['bytes-in']) ? (int)$activeUser['bytes-in'] : 0;
                $newUser['bytes-out'] = isset($activeUser['bytes-out']) ? (int)$activeUser['bytes-out'] : 0;
            } else {
                // Check if there's an existing log for the user
                $existingUser = DB::table('user_bytes_log')
                    ->where('user_name', $user['name'])
                    ->orderBy('timestamp', 'desc')
                    ->first();

                if ($existingUser) {
                    $newUser['bytes-in'] = (int)$existingUser->bytes_in;
                    $newUser['bytes-out'] = (int)$existingUser->bytes_out;
                }
            }

            // Get the previous log to compare with the current one
            $lastLog = DB::table('user_bytes_log')
                ->where('user_name', $newUser['name'])
                ->orderBy('timestamp', 'desc')
                ->first();

            // Calculate the difference in bytes (only if the new value is larger)
            $bytesInDifference = 0;
            $bytesOutDifference = 0;

            if ($lastLog) {
                // Only calculate the difference if the new value is greater than the previous one
                $bytesInDifference = max(0, $newUser['bytes-in'] - $lastLog->bytes_in); // Only positive change
                $bytesOutDifference = max(0, $newUser['bytes-out'] - $lastLog->bytes_out); // Only positive change
            } else {
                // If no previous log, insert the first log without calculating a difference
                $bytesInDifference = $newUser['bytes-in'];
                $bytesOutDifference = $newUser['bytes-out'];
            }

            // Update total bytes
            $totalBytesIn += $bytesInDifference;
            $totalBytesOut += $bytesOutDifference;

            // Only insert if there's a positive change in bytes
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

        // Update session values
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





}
