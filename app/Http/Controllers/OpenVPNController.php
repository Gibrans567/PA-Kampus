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
        'client_ip_range' => 'required|string',
        'port_Nat' => 'required|string',
        'address_network' => 'required|string',
        'port_address' => 'required|string'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Data tidak lengkap atau tidak valid',
            'errors' => $validator->errors()
        ], 400);
    }

    $serverIp = '45.149.93.122';
    $username = $request->input('username');
    $password = $request->input('password');
    $natport = $request->input('port_Nat');
    $clientIpRange = $request->input('client_ip_range');
    $addressNetwork = $request->input('address_network');
    $portAddress = $request->input('port_address');
    $certificate = 'none';
    $ovpnInterface = "ovpn-{$username}";
    $poolName = "{$username}-pool";
    $profileName = "{$username}-profile";
    $clientName = "client-{$username}";

    // **Menambahkan 1 ke last octet dari IP pool**
    $ipParts = explode('.', $clientIpRange); // Pisahkan IP
    if (count($ipParts) == 4) {
        $ipParts[3] = (int) $ipParts[3] + 1; // Tambah 1 ke angka terakhir
        if ($ipParts[3] > 254) {
            $ipParts[3] = 2; // Hindari overflow
        }
        $localAddress = implode('.', $ipParts); // Gabungkan kembali
    } else {
        $localAddress = $clientIpRange; // Jika error, gunakan IP asli
    }

    $vpnCommands = [
        "/ip pool add name={$poolName} ranges={$clientIpRange}",
        "/ppp profile add name={$profileName} local-address={$localAddress} remote-address={$poolName}",
        "/ppp secret add name={$username} password={$password} profile={$profileName} service=ovpn",
        "/ip firewall nat add chain=dstnat protocol=tcp dst-address={$serverIp} dst-port={$natport} action=dst-nat to-addresses={$addressNetwork} to-ports={$portAddress} comment=Forward_{$username}",
        "/interface ovpn-client add name={$clientName} connect-to={$serverIp} port=1194 protocol=tcp user={$username} password={$password} certificate={$certificate} auth=sha1 cipher=aes256-cbc tls-version=any use-peer-dns=yes",
        "/ip firewall nat add chain=srcnat out-interface=<ovpn-{$username}> action=masquerade comment=Masquerade_{$username}",
    ];

    return response()->json([
        'message' => 'VPN configuration generated successfully',
        'commands' => $vpnCommands
    ]);
    }

    public function configureVpnServer1(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'pool_name' => 'required|string',
            'client_ip_range' => 'required|string',
            'port_Nat' => 'required|string',
            'address_network' => 'required|string',
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
        $clientIpRange = $request->input('client_ip_range');
        $addressNetwork = $request->input('address_network');
        $portAddress = $request->input('port_address');
        $certificate = 'none';
        $ovpnInterface = "ovpn-{$username}";
        $poolName = "{$username}-pool";
        $profileName = "{$username}-profile";
        $clientName = "client-{$username}";

        // **Menambahkan 1 ke last octet dari IP pool**
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

        try {
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
                "/ip firewall nat add chain=srcnat out-interface=<{$ovpnInterface}> action=masquerade comment=Masquerade_{$username}",
            ];

            return response()->json([
                'message' => 'VPN berhasil dikonfigurasi di Mikrotik, tetapi Masquerade dan OpenVPN Client perlu dijalankan manual',
                'commands' => $vpnCommands
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan saat mengkonfigurasi VPN',
                'error' => $e->getMessage()
            ], 500);
        }
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

            // Split parameter berdasarkan tanda '=' untuk memisahkan key dan value
            preg_match_all('/(\S+)=([^\s<>\']+|\'[^\']*\')/', $paramString, $matches);

            // Inisialisasi array untuk parameter
            $params = [];

            // Parse parameter hasil regex
            foreach ($matches[1] as $index => $key) {
                $value = $matches[2][$index]; // Contoh: 'srcnat', 'ovpn-usereko'

                // Untuk out-interface, biarkan nilai seperti yang ada di command (termasuk tanda '<' dan '>')
                if ($key == 'out-interface') {
                    // Pastikan out-interface diubah menjadi string, termasuk tanda '<' dan '>'
                    $params[$key] = (string)$value;
                } else {
                    // Pastikan nilai parameter selain out-interface berupa string
                    if (is_array($value)) {
                        $value = implode(',', $value);  // Convert array to string if necessary
                    }
                    $params[$key] = (string)$value;  // Ensure it's treated as a string
                }
            }


            // Debug log untuk melihat parameter yang telah diparse
            Log::info('Parsed parameters:', ['params' => $params]);

            // Buat query untuk menambahkan NAT rule
            // Buat query untuk menambahkan NAT rule
            $query = new Query('/ip/firewall/nat/add');

            // Tambahkan parameter yang ada ke query
            foreach ($params as $key => $value) {
                // Menambahkan parameter out-interface ke query jika ada
                $query->equal($key, $value);
            }

            // Pastikan parameter 'chain' ada, jika tidak ditambahkan 'srcnat'
            if (!isset($params['chain'])) {
                $query->equal('chain', 'srcnat');
            }

            // Pastikan parameter 'action' ada, jika tidak, tambahkan 'masquerade'
            if (!isset($params['action'])) {
                $params['action'] = 'masquerade';  // Default action if not specified
            } else {
                $query->equal('action', $params['action']);
            }

            // Eksekusi query
            $response = $client->query($query)->read();


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

    public function addMasqueradeRule(Request $request)
{
    // Validasi input
    $validator = Validator::make($request->all(), [
        'username' => 'required|string|max:255',
        'ovpn_interface' => 'required|string', // Interface untuk NAT
    ]);

    if ($validator->fails()) {
        return response()->json([
            'message' => 'Data tidak lengkap atau tidak valid',
            'errors' => $validator->errors()
        ], 400);
    }

    $username = $request->input('username');
    $ovpnInterface = $request->input('ovpn_interface');

    // Mendapatkan koneksi ke Mikrotik
    try {
        $client = $this->getClient(); // Pastikan getClient() mengembalikan koneksi Mikrotik

        if (!$client) {
            return response()->json([
                'message' => 'Gagal terhubung ke Mikrotik',
            ], 500);
        }

        // Menambahkan masquerade rule ke Mikrotik
        $query = new Query("/ip/firewall/nat/add", [
            'chain' => 'srcnat',  // Menambahkan parameter chain yang benar
            'out-interface' => $ovpnInterface,
            'action' => 'masquerade',
            'comment' => "Masquerade_{$username}",
        ]);

        $response = $client->query($query)->read();

        // Menangani respons Mikrotik
        if ($response && isset($response['id'])) {
            return response()->json([
                'success' => true,
                'data' => $response,
                'message' => 'Masquerade configuration added successfully',
            ]);
        } else {
            return response()->json([
                'message' => 'Gagal menambahkan konfigurasi masquerade',
                'errors' => $response,
            ], 500);
        }

    } catch (Exception $e) {
        // Menangani error koneksi atau error lainnya
        return response()->json([
            'message' => 'Terjadi kesalahan saat mencoba menghubungi Mikrotik: ' . $e->getMessage(),
        ], 500);
    }
}

    public function checkVpnStatus()
{
    $client = new Client([
        'host' => '192.168.88.1', // Ganti dengan IP MikroTik
        'user' => 'admin',
        'pass' => 'password',
        'port' => 8728, // API port MikroTik
    ]);

    $query = new Query('/interface ovpn-server server print');
    $response = $client->query($query)->read();

    return response()->json($response);
    }

}
