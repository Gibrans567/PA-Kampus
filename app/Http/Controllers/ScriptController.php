<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use RouterOS\Exceptions\QueryException;
use RouterOS\Query;

class ScriptController extends CentralController
{
    public function addScriptAndScheduler(Request $request)
{
    $request->validate([
        'script_name' => 'required|string',
        'scheduler_name' => 'required|string',
        'tenant_id' => 'required|string',
    ]);

    $scriptName = $request->input('script_name');
    $schedulerName = $request->input('scheduler_name');
    $interval = $request->input('1m');
    $tenantId = $request->input('tenant_id');

    // Script dengan dynamic tenant ID
    $scriptSource = '
    :local tenantId "' . $tenantId . '"
    :local url1 ("https://dev.awh.co.id/api-netpro/api/delete-voucher-all-tenant?tenant_id=". $tenantId)
    :local response1 [/tool fetch url=$url1 mode=https http-method=post output=user as-value]
    :put ($response1->"data")
    ';

    try {
        $config = $this->getClientLogin();
        $client = $config;

        $addScriptQuery = new Query('/system/script/add');
        $addScriptQuery
            ->equal('name', $scriptName)
            ->equal('source', $scriptSource);
        $client->query($addScriptQuery)->read();

        $addSchedulerQuery = new Query('/system/scheduler/add');
        $addSchedulerQuery
            ->equal('name', $schedulerName)
            ->equal('interval', $interval)
            ->equal('on-event', "/system script run " . $scriptName);
        $client->query($addSchedulerQuery)->read();

        return response()->json(['message' => 'Script dan scheduler berhasil ditambahkan'], 200);
    } catch (ConfigException | ClientException | QueryException $e) {
        return response()->json(['message' => 'Terjadi kesalahan: ' . $e->getMessage()], 500);
    }
    }

    public function getSystemInfo()
{
    try {
        $client = $this->getClientLogin();

        // Mengambil data resource dari perangkat MikroTik
        $resourceData = $client->query(new Query('/system/resource/print'))->read();

        if (empty($resourceData)) {
            return response()->json(['error' => 'Resource data kosong!'], 500);
        }

        // Mendapatkan build-time dari data yang diambil
        $buildTime = $resourceData[0]['build-time'] ?? 'Unknown Build Time';

        // Coba mem-parsing build-time dengan beberapa format
        $dateTime = $this->parseBuildTime($buildTime);

        $currentTime = $dateTime->format('H:i:s');  // Mengambil waktu (jam:menit:detik)
        $currentDate = $dateTime->format('Y-m-d');  // Mengambil tanggal (tahun-bulan-hari)

        // Menyusun response data sesuai dengan format yang diinginkan
        $response = [
            'model' => $resourceData[0]['board-name'] ?? 'Unknown Model', // Menggunakan board-name untuk model
            'rosVersion' => $resourceData[0]['version'] ?? 'Unknown ROS Version',
            'cpuLoad' => $resourceData[0]['cpu-load'] ?? 'Unknown CPU Load', // Menggunakan cpu-load dari data raw
            'time' => $currentTime,  // Menggunakan waktu yang diambil dari build-time
            'date' => $currentDate,  // Menggunakan tanggal yang diambil dari build-time
            'freeMemory' => $resourceData[0]['free-memory'] ?? 'Unknown Used Memory', // Gunakan free-memory atau lainnya sesuai kebutuhan
            'usedMemory' => isset($resourceData[0]['total-memory'], $resourceData[0]['free-memory'])
                   ? strval($resourceData[0]['total-memory'] - $resourceData[0]['free-memory'])
                   : 'Unknown Used Memory',  // Menghitung used memory dan mengubahnya menjadi stringUpd
            'freeHdd' => $resourceData[0]['free-hdd-space'] ?? 'Unknown Free HDD', // Mengambil free-hdd
            'totalHdd' => $resourceData[0]['total-hdd-space'] ?? 'Unknown Total HDD', // Mengambil total-hdd
            'totalMemory' => $resourceData[0]['total-memory'] ?? 'Unknown Total Memory',
            'upTime' => $resourceData[0]['uptime'] ?? 'Unknown UpTime'
        ];

        return response()->json($response);

    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
    }

    private function parseBuildTime($buildTime)
    {
        // Coba format pertama: "Nov/17/2023 11:38:45"
        $formats = [
            'M/d/Y H:i:s',   // Format seperti "Nov/17/2023 11:38:45"
            'Y-m-d H:i:s',   // Format standar: "2025-01-16 08:19:28"
            'd/m/Y H:i:s',   // Format lain: "16/01/2025 08:19:28"
        ];

        foreach ($formats as $format) {
            $dateTime = \DateTime::createFromFormat($format, $buildTime);
            if ($dateTime !== false) {
                return $dateTime; // Jika parsing berhasil, kembalikan objek DateTime
            }
        }

        // Jika tidak ada format yang cocok, kembalikan DateTime dengan waktu saat ini
        return new \DateTime(); // Mengembalikan waktu saat ini jika parsing gagal
    }

    public function addBandwidthManager(Request $request)
{
    try {
        $client = $this->getClientLogin();
        $results = [];

        // Ambil input dari request
        $pcqRateDownload = $request->input('pcq_rate_download', '50M'); // Default 50M
        $pcqRateUpload = $request->input('pcq_rate_upload', '25M'); // Default 25M
        $maxLimitDownload = $request->input('max_limit_download', '50M'); // Default 50M
        $maxLimitUpload = $request->input('max_limit_upload', '25M'); // Default 25M
        $TotalBandwith = $request->input('total_bandwith', '75M'); // Default 25M

        // Step 1: Get DHCP Client Interface
        $dhcpClientQuery = new Query('/ip/dhcp-client/print');
        $dhcpClients = $client->query($dhcpClientQuery)->read();
        $outInterfaceDownload = '';  // Default to empty if no DHCP client found
        $outInterfaceUpload = '';    // Default to empty if no DHCP client found

        // Ambil interface dari DHCP Client jika ada
        if (!empty($dhcpClients)) {
            foreach ($dhcpClients as $dhcpClient) {
                if (isset($dhcpClient['interface'])) {
                    // Asumsikan kita ambil interface yang pertama kali ditemukan
                    $outInterfaceDownload = $dhcpClient['interface'];
                    $outInterfaceUpload = $dhcpClient['interface'];  // Bisa disesuaikan jika ada logika khusus
                    break;
                }
            }
        }

        // Step 2: Create queue types
        $downloadQueueQuery = new Query('/queue/type/add');
        $downloadQueueQuery->equal('name', 'download');
        $downloadQueueQuery->equal('kind', 'pcq');
        $downloadQueueQuery->equal('pcq-rate', $pcqRateDownload);
        $downloadQueueQuery->equal('pcq-classifier', 'dst-address');
        $downloadQueueQuery->equal('pcq-limit', '50KiB');
        $downloadQueueQuery->equal('pcq-total-limit', '2000KiB');
        $downloadQueueQuery->equal('pcq-src-address-mask', '32');
        $downloadQueueQuery->equal('pcq-dst-address-mask', '32');
        $downloadQueueQuery->equal('pcq-src-address6-mask', '128');
        $downloadQueueQuery->equal('pcq-dst-address6-mask', '128');

        $uploadQueueQuery = new Query('/queue/type/add');
        $uploadQueueQuery->equal('name', 'upload');
        $uploadQueueQuery->equal('kind', 'pcq');
        $uploadQueueQuery->equal('pcq-rate', $pcqRateUpload);
        $uploadQueueQuery->equal('pcq-classifier', 'src-address');
        $uploadQueueQuery->equal('pcq-limit', '50KiB');
        $uploadQueueQuery->equal('pcq-total-limit', '2000KiB');
        $uploadQueueQuery->equal('pcq-src-address-mask', '32');
        $uploadQueueQuery->equal('pcq-dst-address-mask', '32');
        $uploadQueueQuery->equal('pcq-src-address6-mask', '128');
        $uploadQueueQuery->equal('pcq-dst-address6-mask', '128');

        // Execute queue type queries
        $results['queue_download'] = $client->query($downloadQueueQuery)->read();
        $results['queue_upload'] = $client->query($uploadQueueQuery)->read();

        // Step 3: Add mangle rules based on the screenshots
        // Mark connection rule
        $connectionMarkQuery = new Query('/ip/firewall/mangle/add');
        $connectionMarkQuery->equal('chain', 'prerouting');
        $connectionMarkQuery->equal('action', 'mark-connection');
        $connectionMarkQuery->equal('new-connection-mark', 'WAN_conn');
        $connectionMarkQuery->equal('in-interface', 'ether1');
        $connectionMarkQuery->equal('comment', 'Mark WAN connections');
        $results['mangle_connection_mark'] = $client->query($connectionMarkQuery)->read();

        // Mark download traffic rule with out-interface from DHCP Client
        $downloadMarkQuery = new Query('/ip/firewall/mangle/add');
        $downloadMarkQuery->equal('chain', 'forward');
        $downloadMarkQuery->equal('connection-mark', 'WAN_conn');
        $downloadMarkQuery->equal('action', 'mark-packet');
        $downloadMarkQuery->equal('new-packet-mark', 'download-traffic');
        $downloadMarkQuery->equal('in-interface', 'ether1');
        $downloadMarkQuery->equal('out-interface', $outInterfaceDownload);  // Menggunakan interface DHCP client
        $downloadMarkQuery->equal('passthrough', 'yes');
        $downloadMarkQuery->equal('comment', 'Mark download traffic');
        $results['mangle_download_mark'] = $client->query($downloadMarkQuery)->read();

        // Mark upload traffic rule with out-interface from DHCP Client
        $uploadMarkQuery = new Query('/ip/firewall/mangle/add');
        $uploadMarkQuery->equal('chain', 'forward');
        $uploadMarkQuery->equal('connection-mark', 'WAN_conn');
        $uploadMarkQuery->equal('action', 'mark-packet');
        $uploadMarkQuery->equal('new-packet-mark', 'upload-traffic');
        $uploadMarkQuery->equal('out-interface', $outInterfaceUpload);  // Menggunakan interface DHCP client
        $uploadMarkQuery->equal('passthrough', 'yes');
        $uploadMarkQuery->equal('comment', 'Mark upload traffic');
        $results['mangle_upload_mark'] = $client->query($uploadMarkQuery)->read();

        // Step 4: Create queue tree
        $totalBandwidthQuery = new Query('/queue/tree/add');
        $totalBandwidthQuery->equal('name', 'Total-Bandwidth');
        $totalBandwidthQuery->equal('parent', 'global');
        $totalBandwidthQuery->equal('max-limit', $TotalBandwith);
        $totalBandwidthQuery->equal('comment', 'Total bandwidth limit');
        $results['queue_tree_total'] = $client->query($totalBandwidthQuery)->read();

        $downloadTreeQuery = new Query('/queue/tree/add');
        $downloadTreeQuery->equal('name', 'Download');
        $downloadTreeQuery->equal('parent', 'Total-Bandwidth');
        $downloadTreeQuery->equal('packet-mark', 'download-traffic');
        $downloadTreeQuery->equal('queue', 'download');
        $downloadTreeQuery->equal('max-limit', $maxLimitDownload);
        $downloadTreeQuery->equal('comment', 'All download traffic');
        $results['queue_tree_download'] = $client->query($downloadTreeQuery)->read();

        $uploadTreeQuery = new Query('/queue/tree/add');
        $uploadTreeQuery->equal('name', 'Upload');
        $uploadTreeQuery->equal('parent', 'Total-Bandwidth');
        $uploadTreeQuery->equal('packet-mark', 'upload-traffic');
        $uploadTreeQuery->equal('queue', 'upload');
        $uploadTreeQuery->equal('max-limit', $maxLimitUpload);
        $uploadTreeQuery->equal('comment', 'All upload traffic');
        $results['queue_tree_upload'] = $client->query($uploadTreeQuery)->read();

        return response()->json([
            'message' => 'Bandwidth manager configuration berhasil ditambahkan',
            'results' => $results
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
    }

    public function getBandwidthManager(Request $request)
{
    try {
        $client = $this->getClientLogin();
        $results = [];

        // Step 1: Get queue types (download and upload)
        $downloadQueueQuery = new Query('/queue/type/print');
        $uploadQueueQuery = new Query('/queue/type/print');

        // Execute queue type queries
        $results['queue_download'] = $client->query($downloadQueueQuery)->read();
        $results['queue_upload'] = $client->query($uploadQueueQuery)->read();

        // Step 2: Get mangle rules (connection mark, download mark, upload mark)
        $connectionMarkQuery = new Query('/ip/firewall/mangle/print');
        $downloadMarkQuery = new Query('/ip/firewall/mangle/print');
        $uploadMarkQuery = new Query('/ip/firewall/mangle/print');

        // Execute mangle rule queries
        $results['mangle_connection_mark'] = $client->query($connectionMarkQuery)->read();
        $results['mangle_download_mark'] = $client->query($downloadMarkQuery)->read();
        $results['mangle_upload_mark'] = $client->query($uploadMarkQuery)->read();

        // Step 3: Get queue trees (total bandwidth, download tree, upload tree)
        $totalBandwidthQuery = new Query('/queue/tree/print');
        $downloadTreeQuery = new Query('/queue/tree/print');
        $uploadTreeQuery = new Query('/queue/tree/print');

        // Execute queue tree queries
        $results['queue_tree_total'] = $client->query($totalBandwidthQuery)->read();
        $results['queue_tree_download'] = $client->query($downloadTreeQuery)->read();
        $results['queue_tree_upload'] = $client->query($uploadTreeQuery)->read();

        return response()->json([
            'message' => 'Bandwidth manager configuration berhasil diambil',
            'results' => $results
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
    }

    public function editBandwidthManager(Request $request, $name)
{
    try {
        // Ambil input dari request
        $pcqRate = $request->input('pcq_rate'); // Input pcq-rate
        $maxLimit = $request->input('max_limit'); // Input max-limit

        // Validasi: Pastikan ada inputan yang diberikan (bisa salah satu atau keduanya)
        if (empty($pcqRate) && empty($maxLimit)) {
            return response()->json(['error' => 'At least one parameter (pcq_rate or max_limit) is required'], 400);
        }

        $results = [];

        // Update queue type berdasarkan parameter pcq-rate jika diberikan
        if (!empty($pcqRate)) {
            // Pertama cari ID dari queue type berdasarkan nama
            $findQueueQuery = new Query('/queue/type/print');
            $findQueueQuery->where('name', $name);
            $queueData = $this->getClientLogin()->query($findQueueQuery)->read();

            if (empty($queueData)) {
                return response()->json(['error' => 'Queue type not found: ' . $name], 404);
            }

            // Gunakan ID yang ditemukan untuk update
            $downloadQueueQuery = new Query('/queue/type/set');
            $downloadQueueQuery->equal('.id', $queueData[0]['.id']);
            $downloadQueueQuery->equal('pcq-rate', $pcqRate);
            $downloadQueueQuery->equal('pcq-limit', '50KiB');
            $downloadQueueQuery->equal('pcq-total-limit', '2000KiB');
            $results['queue_update'] = $this->getClientLogin()->query($downloadQueueQuery)->read();
        }

        // Update queue tree berdasarkan parameter max-limit jika diberikan
        if (!empty($maxLimit)) {
            // Pertama cari ID dari queue tree berdasarkan nama
            $findTreeQuery = new Query('/queue/tree/print');
            $findTreeQuery->where('name', $name);
            $treeData = $this->getClientLogin()->query($findTreeQuery)->read();

            if (empty($treeData)) {
                return response()->json(['error' => 'Queue tree not found: ' . $name], 404);
            }

            // Gunakan ID yang ditemukan untuk update
            $queueTreeQuery = new Query('/queue/tree/set');
            $queueTreeQuery->equal('.id', $treeData[0]['.id']);
            $queueTreeQuery->equal('max-limit', $maxLimit);
            $results['queue_tree_update'] = $this->getClientLogin()->query($queueTreeQuery)->read();
        }

        return response()->json([
            'message' => 'Bandwidth manager configuration berhasil diperbarui',
            'results' => $results
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
    }

    public function deleteBandwidthManager(Request $request, $name)
{
    try {
        $results = [];
        $itemDeleted = false;

        // Find and delete queue type based on name
        $findQueueQuery = new Query('/queue/type/print');
        $findQueueQuery->where('name', $name);
        $queueData = $this->getClientLogin()->query($findQueueQuery)->read();

        if (!empty($queueData)) {
            $queueDeleteQuery = new Query('/queue/type/remove');
            $queueDeleteQuery->equal('.id', $queueData[0]['.id']);
            $results['queue_delete'] = $this->getClientLogin()->query($queueDeleteQuery)->read();
            $itemDeleted = true;

            // Jika sudah menemukan dan menghapus queue type, langsung return
            return response()->json([
                'message' => 'Queue type berhasil dihapus',
                'results' => $results
            ]);
        }

        // Hanya memeriksa queue tree jika queue type tidak ditemukan
        if (!$itemDeleted) {
            $findTreeQuery = new Query('/queue/tree/print');
            $findTreeQuery->where('name', $name);
            $treeData = $this->getClientLogin()->query($findTreeQuery)->read();

            if (!empty($treeData)) {
                $queueTreeDeleteQuery = new Query('/queue/tree/remove');
                $queueTreeDeleteQuery->equal('.id', $treeData[0]['.id']);
                $results['queue_tree_delete'] = $this->getClientLogin()->query($queueTreeDeleteQuery)->read();

                return response()->json([
                    'message' => 'Queue tree berhasil dihapus',
                    'results' => $results
                ]);
            }
        }

        // Jika tidak ada yang ditemukan
        return response()->json([
            'message' => 'Bandwidth manager dengan nama "' . $name . '" tidak ditemukan',
            'results' => $results
        ], 404);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
    }

    public function fixNatInterfaceWithExtraction(Request $request)
    {
        try {
            // Mendapatkan koneksi ke MikroTik
            $client = $this->getClient();

            // 1. Dapatkan daftar semua interface OpenVPN yang tersedia
            $interfaceQuery = new Query('/interface/print');
            $interfaces = $client->query($interfaceQuery)->read();

            // Buat array untuk menyimpan semua interface yang tersedia dengan format nama yang benar
            $availableInterfaces = [];
            foreach ($interfaces as $interface) {
                if (isset($interface['name'])) {
                    // Simpan nama interface dan formatnya dengan tanda kurung
                    $availableInterfaces[$interface['name']] =  $interface['name'] ;
                }
            }

            // 2. Cari semua rule NAT dengan masquerade yang memiliki masalah
            $natQuery = new Query('/ip/firewall/nat/print');
            $natQuery->where('action', 'masquerade');
            $natRules = $client->query($natQuery)->read();

            $updatedRules = [];
            $noChanges = true;

            foreach ($natRules as $rule) {
                // Jika rule memiliki out-interface unknown atau rule invalid
                if ((isset($rule['out-interface']) && $rule['out-interface'] === 'unknown') ||
                    (isset($rule['invalid']) && $rule['invalid'] === 'true')) {

                    $ruleId = $rule['.id'];
                    $comment = isset($rule['comment']) ? $rule['comment'] : '';

                    // Ekstrak nama client dari comment menggunakan explode
                    $clientName = null;
                    if (!empty($comment)) {
                        // Asumsi format comment adalah "...Masquerade_nama_client..." atau sejenisnya
                        if (strpos($comment, 'Masquerade_') !== false) {
                            $parts = explode('Masquerade_', $comment);
                            if (isset($parts[1])) {
                                // Ambil bagian setelah "Masquerade_"
                                $clientName = trim(explode(' ', $parts[1])[0]);
                            }
                        }
                        // Coba ekstrak juga jika formatnya berbeda
                        else if (strpos($comment, 'ovpn-') !== false) {
                            $parts = explode('ovpn-', $comment);
                            if (isset($parts[1])) {
                                $clientName = 'ovpn-' . trim(explode(' ', $parts[1])[0]);
                            }
                        }
                    }

                    // Jika tidak bisa mengekstrak nama dari comment, coba dari out-interface lama
                    if ($clientName === null && isset($rule['out-interface'])) {
                        $oldInterface = $rule['out-interface'];
                        if (strpos($oldInterface, '<') !== false && strpos($oldInterface, '>') !== false) {
                            // Jika format out-interface lama adalah <ovpn-client>
                            $clientName = str_replace(['<', '>'], '', $oldInterface);
                        }
                    }

                    // Cari interface yang cocok berdasarkan nama client
                    $newInterface = null;
                    if ($clientName !== null) {
                        // Cek apakah nama client ada dalam daftar interface tersedia
                        foreach ($availableInterfaces as $ifName => $formattedName) {
                            if ($ifName === $clientName || strpos($ifName, $clientName) !== false) {
                                $newInterface = $formattedName;
                                break;
                            }
                        }
                    }

                    // Jika tidak ada kecocokan, gunakan nama rule untuk menebak interface
                    if ($newInterface === null && isset($rule['name'])) {
                        $ruleName = $rule['name'];
                        foreach ($availableInterfaces as $ifName => $formattedName) {
                            if (strpos($ruleName, str_replace('ovpn-', '', $ifName)) !== false) {
                                $newInterface = $formattedName;
                                break;
                            }
                        }
                    }

                    // Jika interface ditemukan, update rule
                    if ($newInterface !== null) {
                        // Update rule dengan interface yang ditemukan
                        $updateQuery = new Query('/ip/firewall/nat/set');
                        $updateQuery->equal('.id', $ruleId);
                        $updateQuery->equal('out-interface', $newInterface);
                        $client->query($updateQuery)->read();

                        $updatedRules[] = [
                            'rule_id' => $ruleId,
                            'client_name' => $clientName,
                            'new_interface' => $newInterface,
                            'from_comment' => $comment
                        ];

                        $noChanges = false;
                    }
                }
            }

            if ($noChanges) {
                return response()->json([
                    'status' => 'info',
                    'message' => 'Tidak ada rule NAT yang perlu diperbaiki',
                    'available_interfaces' => $availableInterfaces
                ], 200);
            } else {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Berhasil memperbaiki ' . count($updatedRules) . ' rule NAT',
                    'updated_rules' => $updatedRules,
                    'available_interfaces' => $availableInterfaces
                ], 200);
            }

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Terjadi kesalahan: ' . $e->getMessage()
            ], 500);
        }
    }


}



