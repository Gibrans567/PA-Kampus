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
            'currentTime' => $currentTime,  // Menggunakan waktu yang diambil dari build-time
            'currentDate' => $currentDate,  // Menggunakan tanggal yang diambil dari build-time
            'usedMemory' => $resourceData[0]['free-memory'] ?? 'Unknown Used Memory', // Gunakan free-memory atau lainnya sesuai kebutuhan
            'freeMemory' => $resourceData[0]['free-memory'] ?? 'Unknown Free Memory',
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

            // Step 1: Create queue types
            $downloadQueueQuery = new Query('/queue/type/add');
            $downloadQueueQuery->equal('name', 'download');
            $downloadQueueQuery->equal('kind', 'pcq');
            $downloadQueueQuery->equal('pcq-rate', '50M');
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
            $uploadQueueQuery->equal('pcq-rate', '25M');
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

            // Mark download traffic rule (as shown in your screenshots)
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
            $totalBandwidthQuery->equal('max-limit', '50M');
            $totalBandwidthQuery->equal('comment', 'Total bandwidth limit');
            $results['queue_tree_total'] = $client->query($totalBandwidthQuery)->read();

            $downloadTreeQuery = new Query('/queue/tree/add');
            $downloadTreeQuery->equal('name', 'Download');
            $downloadTreeQuery->equal('parent', 'Total-Bandwidth');
            $downloadTreeQuery->equal('packet-mark', 'download-traffic');
            $downloadTreeQuery->equal('queue', 'download');
            $downloadTreeQuery->equal('max-limit', '50M');
            $downloadTreeQuery->equal('comment', 'All download traffic');
            $results['queue_tree_download'] = $client->query($downloadTreeQuery)->read();

            $uploadTreeQuery = new Query('/queue/tree/add');
            $uploadTreeQuery->equal('name', 'Upload');
            $uploadTreeQuery->equal('parent', 'Total-Bandwidth');
            $uploadTreeQuery->equal('packet-mark', 'upload-traffic');
            $uploadTreeQuery->equal('queue', 'upload');
            $uploadTreeQuery->equal('max-limit', '25M');
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
}



