<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RouterOS\Client;
use RouterOS\Query;

class OpenVPNController extends CentralController
{

    public function checkInterface(Request $request)
{
    $request->validate([
        'interface_name' => 'required|string'
    ]);

    try {
        $client = $this->getClientLogin();

        $query = new Query('/interface/print');
        $query->where('name', $request->interface_name);

        $interfaces = $client->query($query)->read();

        if (count($interfaces) > 0) {
            return response()->json([
                'exists' => true,
                'details' => "VPN Sudah Ada"
            ]);
        } else {
            return response()->json([
                'exists' => false,
                'details' => "VPN Belum Ada"
            ]);
        }

    } catch (\Exception $e) {
        Log::error('Gagal memeriksa interface: ' . $e->getMessage());

        return response()->json([
            'error' => 'Gagal terhubung ke Mikrotik',
            'message' => $e->getMessage()
        ], 500);
    }
    }

    public function configureVpnServer(Request $request)
{
    $validator = Validator::make($request->all(), [
        'username' => 'required|string|max:255',
        'password' => 'required|string|max:255',
        'pool_name' => 'required|string',
        'port_Nat' => 'required|string',
        'port_address' => 'required|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Data tidak lengkap atau tidak valid',
            'errors' => $validator->errors()
        ], 400);
    }

    // Dapatkan instance client Mikrotik
    $client = $this->getClient();
    if (!$client) {
        return response()->json([
            'message' => 'Koneksi ke Mikrotik gagal',
        ], 500);
    }

    $serverIp = '45.149.93.122';
    $username = $request->input('username');
    $password = $request->input('password');
    $natport = $request->input('port_Nat');
    $poolName = $request->input('pool_name');
    $portAddress = $request->input('port_address');
    $certificate = 'none';
    $ovpnInterface = "ovpn-{$username}";
    $profileName = "{$username}-profile";
    $clientName = "client-{$username}";

    try {
        // Cek apakah port NAT sudah digunakan
        $response = $client->query(
            (new Query('/ip/firewall/nat/print'))
                ->where('dst-port', $natport)
                ->where('dst-address', $serverIp)
        )->read();

        if (!empty($response)) {
            return response()->json([
                'message' => 'Port NAT ' . $natport . ' sudah dipakai',
            ], 400);
        }

        // Cek apakah pool name sudah digunakan
        $poolNameResponse = $client->query(
            (new Query('/ip/pool/print'))
                ->where('name', $poolName)
        )->read();

        if (!empty($poolNameResponse)) {
            return response()->json([
                'message' => 'Pool name ' . $poolName . ' sudah dipakai',
            ], 400);
        }

        // **Auto-generate IP Private Kelas B (172.16.x.x)**
        $clientIpRange = $this->generateAvailableIpKlasB($client);
        $addressNetwork = $clientIpRange; // address_network sama dengan client_ip_range

        // **Menambahkan 1 ke last octet dari IP pool untuk local address**
        $ipParts = explode('.', $clientIpRange);
        if (count($ipParts) == 4) {
            $ipParts[3] = (int) $ipParts[3] + 1;
            if ($ipParts[3] > 254) {
                $ipParts[3] = 2;
            }
            $localAddress = implode('.', $ipParts);
        } else {
            $localAddress = $clientIpRange;
        }

        // **Eksekusi perintah langsung ke Mikrotik**
        $client->query(
            (new Query('/ip/pool/add'))
                ->equal('name', $poolName)
                ->equal('ranges', $clientIpRange)
        )->read();

        $client->query(
            (new Query('/ppp/profile/add'))
                ->equal('name', $profileName)
                ->equal('local-address', $localAddress)
                ->equal('remote-address', $poolName)
        )->read();

        $client->query(
            (new Query('/ppp/secret/add'))
                ->equal('name', $username)
                ->equal('password', $password)
                ->equal('profile', $profileName)
                ->equal('service', 'ovpn')
        )->read();

        $client->query(
            (new Query('/ip/firewall/nat/add'))
                ->equal('chain', 'dstnat')
                ->equal('protocol', 'tcp')
                ->equal('dst-address', $serverIp)
                ->equal('dst-port', $natport)
                ->equal('action', 'dst-nat')
                ->equal('to-addresses', $addressNetwork)
                ->equal('to-ports', $portAddress)
                ->equal('comment', "Forward_{$username}")
        )->read();

        // **Perintah untuk OpenVPN Client & Masquerade hanya ditampilkan sebagai output**
        $vpnCommands = [
            "/interface ovpn-client add name={$clientName} connect-to={$serverIp} port=1194 protocol=tcp user={$username} password={$password} certificate={$certificate} auth=sha1 cipher=aes256-cbc tls-version=any use-peer-dns=yes",
            "/user add name={$username} password={$password} group=full",
            "/ip firewall nat add chain=srcnat out-interface=<{$ovpnInterface}> action=masquerade comment=Masquerade_{$username}",
        ];

        return response()->json([
            'message' => 'VPN berhasil dikonfigurasi di Mikrotik',
            'client_ip_range' => $clientIpRange,
            'address_network' => $addressNetwork,
            'local_address' => $localAddress,
            'commands' => $vpnCommands
        ]);

    } catch (Exception $e) {
        return response()->json([
            'message' => 'Terjadi kesalahan saat mengkonfigurasi VPN',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Generate IP Private Kelas B yang tersedia
 * Range: 172.16.0.0 - 172.31.255.255
 */
private function generateAvailableIpKlasB($client)
{
    // Mulai dari 172.16.1.1
    $baseOctet2 = 16; // Octet kedua dimulai dari 16
    $baseOctet3 = 1;  // Octet ketiga dimulai dari 1
    $baseOctet4 = 1;  // Octet keempat dimulai dari 1

    $maxOctet2 = 31;  // Maksimal octet kedua untuk kelas B private (172.16.x.x - 172.31.x.x)
    $maxOctet3 = 255; // Maksimal octet ketiga
    $maxOctet4 = 254; // Maksimal octet keempat (hindari .255)

    for ($octet2 = $baseOctet2; $octet2 <= $maxOctet2; $octet2++) {
        for ($octet3 = $baseOctet3; $octet3 <= $maxOctet3; $octet3++) {
            for ($octet4 = $baseOctet4; $octet4 <= $maxOctet4; $octet4++) {
                $candidateIp = "172.{$octet2}.{$octet3}.{$octet4}";

                // Cek apakah IP sudah digunakan di pool
                $ipCheckResponse = $client->query(
                    (new Query('/ip/pool/print'))
                        ->where('ranges', $candidateIp)
                )->read();

                // Jika IP belum digunakan, return IP tersebut
                if (empty($ipCheckResponse)) {
                    return $candidateIp;
                }
            }
            // Reset octet4 untuk loop berikutnya
            $baseOctet4 = 1;
        }
        // Reset octet3 untuk loop berikutnya
        $baseOctet3 = 1;
    }

    // Jika semua IP sudah terpakai (sangat tidak mungkin dengan range sebesar ini)
    throw new Exception('Semua IP dalam range kelas B sudah terpakai');
}

    public function addNatMasqueradeFromCommand(Request $request)
    {
        try {
            // Ambil input 'command' dari request
            $command = $request->input('command');

            if (!$command) {
                throw new Exception('Command parameter is missing.');
            }

            $client = $this->getClient();

            // Hapus "/ip firewall nat add " dari awal string
            $paramString = str_replace("/ip firewall nat add ", "", $command);

            // Pisahkan parameter berdasarkan spasi
            $rawParams = explode(' ', $paramString);

            $params = [];

            foreach ($rawParams as $rawParam) {
                if (trim($rawParam) === '') continue;

                // Pisahkan key dan value berdasarkan tanda '='
                $parts = explode('=', $rawParam, 2);
                if (count($parts) == 2) {
                    $key = $parts[0];
                    $value = $parts[1];

                    // Tangani out-interface agar tetap menyertakan karakter '<' atau '>' jika ada
                    if ($key === 'out-interface') {
                        $params[$key] = (string)$value;
                    } else {
                        $params[$key] = (string)$value;
                    }
                }
            }

            // Debug log untuk melihat parameter yang telah diparse
            Log::info('Parsed parameters (non-regex):', ['params' => $params]);

            // Buat query untuk menambahkan NAT rule
            $query = new Query('/ip/firewall/nat/add');

            // Tambahkan parameter ke query
            foreach ($params as $key => $value) {
                $query->equal($key, $value);
            }

            // Pastikan 'chain' di-set ke 'srcnat' jika belum ada
            if (!isset($params['chain'])) {
                $query->equal('chain', 'srcnat');
            }

            // Pastikan 'action' di-set ke 'masquerade' jika belum ada
            if (!isset($params['action'])) {
                $query->equal('action', 'masquerade');
            }

            // Eksekusi query ke Mikrotik
            $response = $client->query($query)->read();

            // Check for specific error message in the response
            if (isset($response['after']) && isset($response['after']['message']) &&
                $response['after']['message'] == "input does not match any value of interface") {
                return response()->json([
                    'success' => false,
                    'message' => 'Input does not match any value of interface.',
                    'data' => null
                ], 400);  // Using 400 as a bad request error
            }

            // If response is valid
            Log::info('NAT masquerade berhasil ditambahkan', [
                'command' => $command,
                'parsed_params' => $params,
                'response' => $response
            ]);

            return response()->json([
                'success' => true,
                'message' => 'NAT masquerade berhasil ditambahkan',
                'data' => $response
            ]);
        } catch (Exception $e) {
            Log::error('Gagal menambahkan NAT masquerade: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Gagal menambahkan NAT masquerade: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    public function getInterfaceLists()
    {
        $client = $this->getClient();
        try {
            $query = new Query('/interface/list/print');
            $response = $client->query($query)->read();
            return [
                'success' => true,
                'message' => 'Berhasil mendapatkan daftar interface list',
                'data' => $response
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Gagal mendapatkan daftar interface list: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

}
