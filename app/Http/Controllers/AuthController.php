<?php
namespace App\Http\Controllers;

use App\Http\Controllers\ScriptController;
use App\Models\MikrotikConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 *     title="API Documentation Netpro",
 *     version="1.0.0",
 *     description="Dokumentasi API untuk Netpro"
 * )
 *
 * @OA\Server(
 *     url="http://localhost:8000/api",
 *     description="Local API server"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="bearerAuth",
 *     type="http",
 *     scheme="bearer",
 *     bearerFormat="JWT",
 *     description="Gunakan Bearer Token untuk autentikasi."
 * )
 *
 * @OA\Parameter(
 *     parameter="X-Tenant-ID",
 *     name="X-Tenant-ID",
 *     in="header",
 *     required=true,
 *     description="Encrypted Tenant ID dari hasil login",
 *     @OA\Schema(type="string", example="eyJpdiI6IjE2MjM...")
 * )
 */
class AuthController 
{
    /**
     * @OA\Post(
     *     path="/login",
     *     tags={"Authentication"},
     *     summary="Login Pengguna",
     *     description="Endpoint untuk otentikasi pengguna. Mengembalikan token dan data tenant jika berhasil.",
     *     operationId="login",
     *     @OA\RequestBody(
     *         required=true,
     *         description="Masukkan email dan password",
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *             @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login Berhasil",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Login successful"),
     *             @OA\Property(property="user", type="object"),
     *             @OA\Property(property="tenant", type="object"),
     *             @OA\Property(property="tenant_id", type="object", @OA\Property(property="name", type="string", description="ID Tenant yang terenkripsi")),
     *             @OA\Property(property="token", type="string", example="1|AbcDefGhiJkl..."),
     *             @OA\Property(property="status", type="boolean", description="Menandakan apakah tenant sudah memiliki konfigurasi mikrotik")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Password salah"),
     *     @OA\Response(response=404, description="Email tidak ditemukan"),
     *     @OA\Response(response=422, description="Validation Error")
     * )
     */
    public function login(Request $request)
    {
        // Validasi input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required'
        ]);

        // Jika validasi gagal, kembalikan pesan error
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Cek apakah email terdaftar
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Email tidak ditemukan'
            ], 404);
        }

        // Cek apakah password benar
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Password salah'
            ], 401);
        }

        // Autentikasi user
        Auth::login($user);

        // Cek apakah user memiliki tenant
        $tenant = $user->tenant;

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant not found',
                'errors' => ['tenant' => ['No tenant associated with this user.']]
            ], 500);
        }

        // Enkripsi Tenant ID
        $encryptedTenantData = [
            'name' => Crypt::encryptString($tenant->id),
        ];

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Ambil token tanpa prefix
        $cleanToken = explode('|', $token, 2)[1] ?? $token;

        // Inisialisasi database tenant
        tenancy()->initialize($tenant);

        // Cek apakah mikrotik_config memiliki data
        $hasData = DB::table('mikrotik_config')->count() > 0;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'tenant' => $tenant,
            'tenant_id' => $encryptedTenantData,
            'token' => $cleanToken,
            'status' => $hasData,
        ], 200);
    }

    /**
     * @OA\Post(
     *     path="/register",
     *     tags={"Authentication"},
     *     summary="Registrasi Pengguna Baru",
     *     description="Endpoint untuk registrasi pengguna baru dan membuat tenant.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "password"},
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="email", type="string", example="john@example.com"),
     *             @OA\Property(property="password", type="string", format="password", example="password123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="User berhasil diregistrasi",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User registered successfully"),
     *             @OA\Property(property="user", type="object")
     *         )
     *     ),
     *     @OA\Response(response=409, description="Email atau Tenant sudah digunakan"),
     *     @OA\Response(response=422, description="Validasi gagal")
     * )
     */
    public function register(Request $request, ScriptController $scriptController)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (User::where('email', $request->email)->exists()) {
            return response()->json([
                'message' => 'Email sudah digunakan.'
            ], 409);
        }

        // Generate Tenant ID
        $nameWithUnderscore = Str::slug($request->name, '_');
        $tenantId = "netpro_" . $nameWithUnderscore;

        if (Tenant::where('id', $tenantId)->exists()) {
            return response()->json([
                'message' => 'Tenant sudah digunakan.'
            ], 409);
        }

        $tenant = Tenant::create([
            'id' => $tenantId,
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'admin',
            'tenant_id' => $tenant->id,
        ]);

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user
        ], 201);
    }

    /**
     * @OA\Post(
     *     path="/logout",
     *     tags={"Authentication"},
     *     summary="Logout user",
     *     description="Logout user dengan menghapus semua token yang terkait dan mengakhiri sesi tenant.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     *     @OA\Response(
     *         response=200,
     *         description="Logout berhasil",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Logged out successfully"),
     *             @OA\Property(property="status", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthorized")
     * )
     */
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
            'status' => true
        ]);
    }

    /**
     * @OA\Get(
     *     path="/users",
     *     tags={"User"},
     *     summary="Ambil semua data user",
     *     description="Mengambil semua data user dari database pusat.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     *     @OA\Response(
     *         response=200,
     *         description="Data user berhasil diambil",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Data users berhasil diambil."),
     *             @OA\Property(property="data", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */
    public function GetEmail()
    {
        try {
            $users = User::all();

            return response()->json([
                'success' => true,
                'message' => 'Data users berhasil diambil.',
                'data' => $users,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/user-by-token",
     *     tags={"Authentication"},
     *     summary="Ambil data user berdasarkan token",
     *     description="Mengambil data user dan tenant menggunakan bearer token.",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     *     @OA\Response(
     *         response=200,
     *         description="Berhasil mendapatkan data user",
     *         @OA\JsonContent(
     *             @OA\Property(property="user", type="object"),
     *             @OA\Property(property="tenant", type="object"),
     *             @OA\Property(property="encrypted_tenant_id", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Token tidak valid atau tidak ditemukan"),
     *     @OA\Response(response=404, description="User atau tenant tidak ditemukan")
     * )
     */
    public function getUserByToken(Request $request)
    {
        try {
            $bearerToken = $request->bearerToken();

            if (!$bearerToken) {
                return response()->json(['message' => 'Token tidak ditemukan'], 401);
            }

            $personalAccessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);

            if (!$personalAccessToken) {
                return response()->json(['message' => 'Token tidak valid'], 401);
            }

            $user = $personalAccessToken->tokenable;

            if (!$user) {
                return response()->json(['message' => 'User tidak ditemukan'], 404);
            }

            $tenant = $user->tenant;

            if (!$tenant) {
                return response()->json(['message' => 'Tenant tidak ditemukan'], 500);
            }

            $encryptedTenantData = [
                'id' => Crypt::encryptString($tenant->id),
            ];

            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
                'tenant' => [
                    'id' => $tenant->id,
                ],
                'encrypted_tenant_id' => $encryptedTenantData
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
}
