<?php

namespace App\Http\Controllers;

use App\Models\MikrotikConfig;
use Illuminate\Http\Request;
use RouterOS\Client;

class CentralController
{
    protected function getClient()
    {
        $config = [
            'host' => '45.149.93.122',
            'user' => 'netpro',
            'pass' => 'netpro',
            'port' => 8736,
        ];

        return new Client($config);
    }


    protected function getClientLogin()
    {
        $config = MikrotikConfig::first();

        if (!$config) {
            throw new \Exception('Konfigurasi Mikrotik tidak ditemukan untuk tenant ini!');
        }

        // Initialize RouterOS Client
        return new Client([
            'host' => $config->host,
            'user' => $config->user,
            'pass' => $config->pass,
            'port' => $config->port,
        ]);
    }

    protected function getClientVoucher($mikrotikConfig)
{
    if (!$mikrotikConfig) {
        throw new \Exception('Konfigurasi Mikrotik tidak ditemukan untuk tenant ini!');
    }

    return new Client([
        'host' => $mikrotikConfig->host,
        'user' => $mikrotikConfig->user,
        'pass' => $mikrotikConfig->pass,
        'port' => $mikrotikConfig->port,
    ]);
    }

    /**
     * @OA\Get(
     *     path="/mikrotik-config",
     *     tags={"Mikrotik Config"},
     *     summary="Menampilkan semua konfigurasi Mikrotik",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     *     @OA\Response(
     *         response=200,
     *         description="Daftar konfigurasi Mikrotik",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="host", type="string", example="192.168.88.1"),
     *                 @OA\Property(property="user", type="string", example="admin"),
     *                 @OA\Property(property="pass", type="string", example="password123"),
     *                 @OA\Property(property="port", type="integer", example=8728),
     *                 @OA\Property(property="created_at", type="string", example="2025-09-14T12:00:00Z"),
     *                 @OA\Property(property="updated_at", type="string", example="2025-09-14T12:00:00Z")
     *             )
     *         )
     *     )
     * )
     */
    public function index()
    {
        $configs = MikrotikConfig::all();
        return response()->json($configs);
    }

    /**
     * @OA\Post(
     *     path="/mikrotik-config",
     *     tags={"Mikrotik Config"},
     *     summary="Membuat konfigurasi Mikrotik baru",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"host","user","pass","port"},
     *             @OA\Property(property="host", type="string", example="192.168.88.1"),
     *             @OA\Property(property="user", type="string", example="admin"),
     *             @OA\Property(property="pass", type="string", example="password123"),
     *             @OA\Property(property="port", type="integer", example=8728)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Konfigurasi berhasil dibuat",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Konfigurasi berhasil ditambahkan!"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="host", type="string", example="192.168.88.1"),
     *                 @OA\Property(property="user", type="string", example="admin"),
     *                 @OA\Property(property="pass", type="string", example="password123"),
     *                 @OA\Property(property="port", type="integer", example=8728)
     *             ),
     *             @OA\Property(
     *                 property="script_result",
     *                 type="object",
     *                 description="Hasil eksekusi script Mikrotik"
     *             )
     *         )
     *     )
     * )
     */
    public function store(Request $request)
    {
        $request->validate([
            'host' => 'required|string',
            'user' => 'required|string',
            'pass' => 'required|string',
            'port' => 'required|integer',
        ]);

        $config = MikrotikConfig::create($request->only(['host', 'user', 'pass', 'port']));

        $scriptController = new ScriptController();
        $scriptResult = $scriptController->addScriptAndScheduler($config);

        return response()->json([
            'message' => 'Konfigurasi berhasil ditambahkan!',
            'data' => $config,
            'script_result' => $scriptResult->original
        ], 201);
    }

    /**
     * @OA\Get(
     *     path="/mikrotik-config/{id}",
     *     tags={"Mikrotik Config"},
     *     summary="Menampilkan detail konfigurasi Mikrotik berdasarkan ID",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID konfigurasi Mikrotik",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Detail konfigurasi Mikrotik",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer", example=1),
     *             @OA\Property(property="host", type="string", example="192.168.88.1"),
     *             @OA\Property(property="user", type="string", example="admin"),
     *             @OA\Property(property="pass", type="string", example="password123"),
     *             @OA\Property(property="port", type="integer", example=8728),
     *             @OA\Property(property="created_at", type="string", example="2025-09-14T12:00:00Z"),
     *             @OA\Property(property="updated_at", type="string", example="2025-09-14T12:00:00Z")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Konfigurasi tidak ditemukan")
     * )
     */
    public function show($id)
    {
        $config = MikrotikConfig::find($id);

        if (!$config) {
            return response()->json(['message' => 'Konfigurasi tidak ditemukan!'], 404);
        }

        return response()->json($config);
    }

    /**
     * @OA\Post(
     *     path="/mikrotik-config/{id}",
     *     tags={"Mikrotik Config"},
     *     summary="Update konfigurasi Mikrotik",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID konfigurasi Mikrotik",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="host", type="string", example="192.168.88.1"),
     *             @OA\Property(property="user", type="string", example="admin"),
     *             @OA\Property(property="pass", type="string", example="password123"),
     *             @OA\Property(property="port", type="integer", example=8728)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Konfigurasi berhasil diperbarui",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="message", type="string", example="Konfigurasi berhasil diperbarui!"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="host", type="string", example="192.168.88.1"),
     *                 @OA\Property(property="user", type="string", example="admin"),
     *                 @OA\Property(property="pass", type="string", example="password123"),
     *                 @OA\Property(property="port", type="integer", example=8728)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="Konfigurasi tidak ditemukan")
     * )
     */
    public function update(Request $request, $id)
    {
        $config = MikrotikConfig::find($id);

        if (!$config) {
            return response()->json(['message' => 'Konfigurasi tidak ditemukan!'], 404);
        }

        $request->validate([
            'host' => 'sometimes|required|string',
            'user' => 'sometimes|required|string',
            'pass' => 'sometimes|required|string',
            'port' => 'sometimes|required|integer',
        ]);

        $config->update($request->all());

        return response()->json([
            'message' => 'Konfigurasi berhasil diperbarui!',
            'data' => $config
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/mikrotik-config/{id}",
     *     tags={"Mikrotik Config"},
     *     summary="Hapus konfigurasi Mikrotik",
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID konfigurasi Mikrotik",
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Konfigurasi berhasil dihapus",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Konfigurasi berhasil dihapus!")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Konfigurasi tidak ditemukan")
     * )
     */
    public function destroy($id)
    {
        $config = MikrotikConfig::find($id);

        if (!$config) {
            return response()->json(['message' => 'Konfigurasi tidak ditemukan!'], 404);
        }

        $config->delete();

        return response()->json(['message' => 'Konfigurasi berhasil dihapus!']);
    }

}
