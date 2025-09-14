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

    /**
     * @OA\Post(
     * path="/mikrotik/Check-Vpn",
     * tags={"OpenVPN"},
     * summary="Cek apakah interface VPN sudah ada di Mikrotik",
     * description="Memeriksa apakah interface dengan nama tertentu sudah ada pada Mikrotik",
     * security={
     * {"bearerAuth": {}, "X-Tenant-ID": {}}
     * },
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"interface_name"},
     * @OA\Property(property="interface_name", type="string", example="ovpn-client1")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="Berhasil mengecek interface",
     * @OA\JsonContent(
     * @OA\Property(property="exists", type="boolean", example=true),
     * @OA\Property(property="details", type="string", example="VPN Sudah Ada")
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="Gagal terhubung ke Mikrotik",
     * @OA\JsonContent(
     * @OA\Property(property="error", type="string", example="Gagal terhubung ke Mikrotik"),
     * @OA\Property(property="message", type="string", example="Connection refused")
     * )
     * )
     * )
     */
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

    /**
     * @OA\Post(
     * path="/configure-vpn-server",
     * tags={"OpenVPN"},
     * summary="Konfigurasi VPN di Mikrotik",
     * description="Membuat konfigurasi OpenVPN baru di Mikrotik",
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"username", "password", "pool_name", "port_Nat", "port_address"},
     * @OA\Property(property="username", type="string", example="vpnuser"),
     * @OA\Property(property="password", type="string", example="securepass"),
     * @OA\Property(property="pool_name", type="string", example="vpn-pool"),
     * @OA\Property(property="port_Nat", type="string", example="1194"),
     * @OA\Property(property="port_address", type="string", example="8729")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="Berhasil mengkonfigurasi VPN",
     * @OA\JsonContent(
     * @OA\Property(property="message", type="string", example="VPN berhasil dikonfigurasi di Mikrotik"),
     * @OA\Property(property="client_ip_range", type="string", example="172.16.1.1"),
     * @OA\Property(property="address_network", type="string", example="172.16.1.1"),
     * @OA\Property(property="local_address", type="string", example="172.16.1.2"),
     * @OA\Property(
     * property="commands",
     * type="array",
     * @OA\Items(type="string", example="/interface ovpn-client add name=client-vpnuser connect-to=45.149.93.122 ...")
     * )
     * )
     * ),
     * @OA\Response(
     * response=400,
     * description="Data tidak valid",
     * @OA\JsonContent(
     * @OA\Property(property="message", type="string", example="Data tidak lengkap atau tidak valid"),
     * @OA\Property(property="errors", type="object")
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="Terjadi kesalahan internal"
     * )
     * )
     */
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

            $poolNameResponse = $client->query(
                (new Query('/ip/pool/print'))
                    ->where('name', $poolName)
            )->read();

            if (!empty($poolNameResponse)) {
                return response()->json([
                    'message' => 'Pool name ' . $poolName . ' sudah dipakai',
                ], 400);
            }

            $clientIpRange = $this->generateAvailableIpKlasB($client);
            $addressNetwork = $clientIpRange;

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

    private function generateAvailableIpKlasB($client)
    {
        $baseOctet2 = 16;
        $baseOctet3 = 1;
        $baseOctet4 = 1;

        $maxOctet2 = 31;
        $maxOctet3 = 255;
        $maxOctet4 = 254;

        for ($octet2 = $baseOctet2; $octet2 <= $maxOctet2; $octet2++) {
            for ($octet3 = $baseOctet3; $octet3 <= $maxOctet3; $octet3++) {
                for ($octet4 = $baseOctet4; $octet4 <= $maxOctet4; $octet4++) {
                    $candidateIp = "172.{$octet2}.{$octet3}.{$octet4}";

                    $ipCheckResponse = $client->query(
                        (new Query('/ip/pool/print'))
                            ->where('ranges', $candidateIp)
                    )->read();

                    if (empty($ipCheckResponse)) {
                        return $candidateIp;
                    }
                }
                $baseOctet4 = 1;
            }
            $baseOctet3 = 1;
        }

        throw new Exception('Semua IP dalam range kelas B sudah terpakai');
    }

    /**
     * @OA\Post(
     * path="/configure-masquarade",
     * tags={"OpenVPN"},
     * summary="Tambah NAT Masquerade di Mikrotik",
     * description="Menambahkan aturan NAT masquerade baru ke Mikrotik menggunakan perintah CLI yang dikirim dari API.",
     * @OA\RequestBody(
     * required=true,
     * @OA\JsonContent(
     * required={"command"},
     * @OA\Property(property="command", type="string", example="/ip firewall nat add chain=srcnat out-interface=ovpn-client1 action=masquerade comment=Masquerade_user1")
     * )
     * ),
     * @OA\Response(
     * response=200,
     * description="NAT masquerade berhasil ditambahkan",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=true),
     * @OA\Property(property="message", type="string", example="NAT masquerade berhasil ditambahkan"),
     * @OA\Property(property="data", type="object")
     * )
     * ),
     * @OA\Response(
     * response=400,
     * description="Input tidak valid",
     * @OA\JsonContent(
     * @OA\Property(property="success", type="boolean", example=false),
     * @OA\Property(property="message", type="string", example="Input does not match any value of interface."),
     * @OA\Property(property="data", type="null")
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="Terjadi kesalahan internal"
     * )
     * )
     */
    public function addNatMasqueradeFromCommand(Request $request)
    {
        try {
            $command = $request->input('command');

            if (!$command) {
                throw new Exception('Command parameter is missing.');
            }

            $client = $this->getClient();

            $paramString = str_replace("/ip firewall nat add ", "", $command);

            $rawParams = explode(' ', $paramString);

            $params = [];

            foreach ($rawParams as $rawParam) {
                if (trim($rawParam) === '') continue;

                $parts = explode('=', $rawParam, 2);
                if (count($parts) == 2) {
                    $key = $parts[0];
                    $value = $parts[1];

                    if ($key === 'out-interface') {
                        $params[$key] = (string)$value;
                    } else {
                        $params[$key] = (string)$value;
                    }
                }
            }

            $query = new Query('/ip/firewall/nat/add');

            foreach ($params as $key => $value) {
                $query->equal($key, $value);
            }

            if (!isset($params['chain'])) {
                $query->equal('chain', 'srcnat');
            }

            if (!isset($params['action'])) {
                $query->equal('action', 'masquerade');
            }

            $response = $client->query($query)->read();

            if (
                isset($response['after']) && isset($response['after']['message']) &&
                $response['after']['message'] == "input does not match any value of interface"
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Input does not match any value of interface.',
                    'data' => null
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'NAT masquerade berhasil ditambahkan',
                'data' => $response
            ]);
        } catch (Exception $e) {

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
