<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RouterOS\Query;

class HotspotProfileController extends CentralController
{
    /**
     * @OA\Post(
     * path="/mikrotik/set-profile",
     * tags={"Hotspot Profile"},
     * summary="Create or link a hotspot profile",
     * description="Membuat hotspot profile baru atau menambahkan link jika profile sudah ada di Mikrotik.",
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"profile_name","shared_users"},
     * @OA\Property(property="profile_name", type="string", example="Premium-User"),
     * @OA\Property(property="shared_users", type="integer", example=5),
     * @OA\Property(property="rate_limit", type="string", example="2M/2M"),
     * @OA\Property(property="link", type="string", example="https://example.com/package")
     * )
     * ),
     * @OA\Response(response=201, description="Hotspot profile created successfully"),
     * @OA\Response(response=200, description="Profile already exists but link added"),
     * @OA\Response(response=500, description="Server error")
     * )
     */
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
            $client = $this->getClientLogin();

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
                    ->equal('keepalive-timeout', '02:00:00')
                    ->equal('idle-timeout', '12:00:00')
                    ->equal('status-autorefresh', '00:01:00')
                    ->equal('add-mac-cookie', 'yes')
                    ->equal('mac-cookie-timeout', '06:00:00');

                if (!empty($rate_limit)) {
                    $addQuery->equal('rate-limit', $rate_limit);
                }

                $client->query($addQuery)->read();

                DB::table('user_profile_link')->insert([
                    'name' => $profile_name,
                    'link' => $link,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return response()->json(['message' => 'Hotspot profile created successfully'], 201);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Get(
     * path="/mikrotik/get-profile",
     * tags={"Hotspot Profile"},
     * summary="Get all hotspot profiles",
     * description="Mengambil semua data hotspot profile yang ada di Mikrotik dan link yang tersimpan di database.",
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     * @OA\Response(
     * response=200,
     * description="Profiles retrieved successfully",
     * @OA\JsonContent(
     * @OA\Property(property="profiles", type="array", @OA\Items(
     * @OA\Property(property="profile_name", type="string", example="Premium-User"),
     * @OA\Property(property="shared_users", type="integer", example=5),
     * @OA\Property(property="rate_limit", type="string", example="2M/2M"),
     * @OA\Property(property="link", type="string", example="https://example.com/package")
     * ))
     * )
     * ),
     * @OA\Response(response=404, description="No profiles found")
     * )
     */
    public function getHotspotProfile(Request $request)
    {
        try {
            $client = $this->getClientLogin();
            $query = new Query('/ip/hotspot/user/profile/print');
            $profiles = $client->query($query)->read();

            if (!empty($profiles)) {
                $result = [];

                foreach ($profiles as $profile) {
                    $dbProfile = DB::table('user_profile_link')
                        ->where('name', $profile['name'])
                        ->first();

                    $result[] = [
                        'profile_name' => $profile['name'],
                        'shared_users' => $profile['shared-users'] ?? 'Not set',
                        'rate_limit' => $profile['rate-limit'] ?? 'Not set',
                        'link' => $dbProfile->link ?? 'No link available',
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

    /**
     * @OA\Get(
     * path="/mikrotik/get-profile/{profile_name}",
     * tags={"Hotspot Profile"},
     * summary="Get hotspot profile by name",
     * description="Mengambil data hotspot profile berdasarkan nama.",
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     * @OA\Parameter(
     * name="profile_name",
     * in="path",
     * required=true,
     * description="Nama profile yang ingin dicari",
     * @OA\Schema(type="string")
     * ),
     * @OA\Response(response=200, description="Profile data"),
     * @OA\Response(response=404, description="Profile not found")
     * )
     */
    public function getHotspotProfileByName(Request $request, $profileName)
    {
        try {
            $client = $this->getClientLogin();

            $query = new Query('/ip/hotspot/user/profile/print');
            $query->where('name', $profileName);

            $profiles = $client->query($query)->read();

            if (!empty($profiles)) {
                $profile = $profiles[0];

                $link = DB::table('user_profile_link')
                    ->where('name', $profileName)
                    ->value('link');

                $result = [
                    'profile_name' => $profile['name'],
                    'shared_users' => $profile['shared-users'] ?? 'Not set',
                    'rate_limit' => $profile['rate-limit'] ?? 'Not set',
                    'link' => $link ?? 'No link found',
                ];

                return response()->json($result, 200);
            } else {
                return response()->json(['message' => 'Profile not found'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Delete(
     * path="/mikrotik/delete-profile/{profile_name}",
     * tags={"Hotspot Profile"},
     * summary="Delete hotspot profile",
     * description="Menghapus hotspot profile berdasarkan nama di Mikrotik.",
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     * @OA\Parameter(
     * name="profile_name",
     * in="path",
     * required=true,
     * description="Nama profile yang ingin dihapus",
     * @OA\Schema(type="string")
     * ),
     * @OA\Response(response=200, description="Hotspot profile deleted successfully"),
     * @OA\Response(response=404, description="Profile not found")
     * )
     */
    public function deleteHotspotProfile($profile_name)
    {
        try {
            $client = $this->getClientLogin();

            $checkQuery = (new Query('/ip/hotspot/user/profile/print'))
                ->where('name', $profile_name);

            $profiles = $client->query($checkQuery)->read();

            if (!empty($profiles)) {
                $profile_id = $profiles[0]['.id'];

                $deleteQuery = (new Query('/ip/hotspot/user/profile/remove'))
                    ->equal('.id', $profile_id);

                $client->query($deleteQuery)->read();

                return response()->json(['message' => 'Hotspot profile deleted successfully'], 200);
            } else {
                return response()->json(['message' => 'Profile not found'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Put(
     * path="/mikrotik/hotspot-profile/{profile_name}",
     * tags={"Hotspot Profile"},
     * summary="Update hotspot profile",
     * description="Mengupdate data hotspot profile dan link di database.",
     * security={{"bearerAuth":{}}},
     * @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     * @OA\Parameter(
     * name="profile_name",
     * in="path",
     * required=true,
     * description="Nama profile yang ingin diperbarui",
     * @OA\Schema(type="string")
     * ),
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"link"},
     * @OA\Property(property="link", type="string", example="https://example.com/package"),
     * @OA\Property(property="shared_users", type="integer", example=10),
     * @OA\Property(property="rate_limit", type="string", example="3M/3M")
     * )
     * ),
     * @OA\Response(response=200, description="Hotspot profile and link updated successfully"),
     * @OA\Response(response=404, description="Profile not found")
     * )
     */
    public function updateHotspotProfile(Request $request, $profile_name)
    {
        $request->validate([
            'link' => 'required|url',
            'shared_users' => 'nullable|integer',
            'rate_limit' => 'nullable|string',
        ]);

        $link = $request->input('link');
        $shared_users = $request->input('shared_users');
        $rate_limit = $request->input('rate_limit');

        try {
            $client = $this->getClientLogin();

            $checkQuery = (new Query('/ip/hotspot/user/profile/print'))
                ->where('name', $profile_name);

            $profiles = $client->query($checkQuery)->read();

            if (!empty($profiles)) {
                $profile_id = $profiles[0]['.id'];

                $updateQuery = (new Query('/ip/hotspot/user/profile/set'))
                    ->equal('.id', $profile_id);

                if ($shared_users) {
                    $updateQuery->equal('shared-users', $shared_users);
                }
                if ($rate_limit) {
                    $updateQuery->equal('rate-limit', $rate_limit);
                }

                $client->query($updateQuery)->read();

                $existingProfile = DB::table('user_profile_link')
                    ->where('name', $profile_name)
                    ->first();

                if ($existingProfile) {
                    DB::table('user_profile_link')
                        ->where('name', $profile_name)
                        ->update(['link' => $link]);
                } else {
                    DB::table('user_profile_link')
                        ->insert([
                            'name' => $profile_name,
                            'link' => $link,
                        ]);
                }

                return response()->json(['message' => 'Hotspot profile and link updated successfully'], 200);
            } else {
                return response()->json(['message' => 'Profile not found'], 404);
            }
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
