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

        // Step 1: Create queue types
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

        // Step 2: Add mangle rules based on the screenshots

        // Mark connection rule
        $connectionMarkQuery = new Query('/ip/firewall/mangle/add');
        $connectionMarkQuery->equal('chain', 'prerouting');
        $connectionMarkQuery->equal('action', 'mark-connection');
        $connectionMarkQuery->equal('new-connection-mark', 'WAN_conn');
        $connectionMarkQuery->equal('in-interface', 'ether1');
        $connectionMarkQuery->equal('comment', 'Mark WAN connections');
        $results['mangle_connection_mark'] = $client->query($connectionMarkQuery)->read();

        // Mark download traffic rule
        $downloadMarkQuery = new Query('/ip/firewall/mangle/add');
        $downloadMarkQuery->equal('chain', 'forward');
        $downloadMarkQuery->equal('connection-mark', 'WAN_conn');
        $downloadMarkQuery->equal('action', 'mark-packet');
        $downloadMarkQuery->equal('new-packet-mark', 'download-traffic');
        $downloadMarkQuery->equal('in-interface', 'ether1');
        $downloadMarkQuery->equal('passthrough', 'yes');
        $downloadMarkQuery->equal('comment', 'Mark download traffic');
        $results['mangle_download_mark'] = $client->query($downloadMarkQuery)->read();

        // Mark upload traffic rule
        $uploadMarkQuery = new Query('/ip/firewall/mangle/add');
        $uploadMarkQuery->equal('chain', 'forward');
        $uploadMarkQuery->equal('connection-mark', 'WAN_conn');
        $uploadMarkQuery->equal('action', 'mark-packet');
        $uploadMarkQuery->equal('new-packet-mark', 'upload-traffic');
        $uploadMarkQuery->equal('out-interface', 'ether1');
        $uploadMarkQuery->equal('passthrough', 'yes');
        $uploadMarkQuery->equal('comment', 'Mark upload traffic');
        $results['mangle_upload_mark'] = $client->query($uploadMarkQuery)->read();

        // Step 3: Create queue tree
        $totalBandwidthQuery = new Query('/queue/tree/add');
        $totalBandwidthQuery->equal('name', 'Total-Bandwidth');
        $totalBandwidthQuery->equal('parent', 'global');
        $totalBandwidthQuery->equal('max-limit', $maxLimitDownload);
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
            $downloadQueueQuery = new Query('/queue/type/set');
            $downloadQueueQuery->equal('name', $name);  // Nama queue (download, upload, dsb)
            $downloadQueueQuery->equal('pcq-rate', $pcqRate);  // Update pcq-rate
            $downloadQueueQuery->equal('pcq-limit', '50KiB');
            $downloadQueueQuery->equal('pcq-total-limit', '2000KiB');
            $results['queue_update'] = $this->getClientLogin()->query($downloadQueueQuery)->read();
        }

        // Update queue tree berdasarkan parameter max-limit jika diberikan
        if (!empty($maxLimit)) {
            $queueTreeQuery = new Query('/queue/tree/set');
            $queueTreeQuery->equal('name', $name);  // Nama queue tree (Total-Bandwidth, Download, Upload, dsb)
            $queueTreeQuery->equal('max-limit', $maxLimit);  // Update max-limit
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

        // Hapus queue type berdasarkan nama
        $queueDeleteQuery = new Query('/queue/type/remove');
        $queueDeleteQuery->equal('name', $name);  // Nama queue (download, upload, dsb)
        $results['queue_delete'] = $this->getClientLogin()->query($queueDeleteQuery)->read();

        // Hapus queue tree berdasarkan nama
        $queueTreeDeleteQuery = new Query('/queue/tree/remove');
        $queueTreeDeleteQuery->equal('name', $name);  // Nama queue tree (Total-Bandwidth, Download, Upload, dsb)
        $results['queue_tree_delete'] = $this->getClientLogin()->query($queueTreeDeleteQuery)->read();

        return response()->json([
            'message' => 'Bandwidth manager configuration berhasil dihapus',
            'results' => $results
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
    }


}



