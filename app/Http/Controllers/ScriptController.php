<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use RouterOS\Exceptions\QueryException;
use RouterOS\Query;

class ScriptController extends CentralController
{

    /**
 * @OA\Post(
 *     path="/mikrotik/add-script",
 *     summary="Tambah script dan scheduler",
 *     tags={"Mikrotik"},
 *     security={{"bearerAuth": {}, "X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="user", type="string", example="tenant_123")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Script dan scheduler berhasil ditambahkan",
 *         @OA\JsonContent(
 *             @OA\Property(property="message", type="string", example="Script dan scheduler berhasil ditambahkan")
 *         )
 *     ),
 *     @OA\Response(response=500, description="Terjadi kesalahan pada server")
 * )
 */
    public function addScriptAndScheduler($config)
{
    $identifier = $config->user;
    $scriptName = 'script_' . $identifier;
    $schedulerName = 'scheduler_' . $identifier;
    $tenantIdWithPrefix = 'netpro_' . $identifier;

    $interval = '5m';

    $scriptSource = "
    :local tenantId \"$tenantIdWithPrefix\"
    :local url1 (\"https://netpro.blog/delete-voucher-all-tenant?tenant_id=\" . \$tenantId)
    :local response1 [/tool fetch url=\$url1 mode=https http-method=post output=user as-value]
    :put (\$response1->\"data\")
    ";

    try {
        $client = $this->getClientLogin();

        Log::info("Adding script: " . $scriptName . " with source: " . $scriptSource);

        $addScriptQuery = new Query('/system/script/add');
        $addScriptQuery
            ->equal('name', $scriptName)
            ->equal('source', $scriptSource);
        $client->query($addScriptQuery)->read();

        Log::info("Adding scheduler: " . $schedulerName . " with interval: " . $interval);

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

    /**
 * @OA\Get(
 *     path="/mikrotik/get-info",
 *     summary="Ambil informasi sistem dari Mikrotik",
 *     tags={"Mikrotik"},
 *     security={{"bearerAuth": {}, "X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Response(
 *         response=200,
 *         description="Informasi sistem berhasil diambil",
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="model", type="string", example="RB750Gr3"),
 *             @OA\Property(property="rosVersion", type="string", example="7.13"),
 *             @OA\Property(property="cpuLoad", type="integer", example=30),
 *             @OA\Property(property="time", type="string", example="10:23:45"),
 *             @OA\Property(property="date", type="string", example="2025-09-14"),
 *             @OA\Property(property="freeMemory", type="string", example="120MB"),
 *             @OA\Property(property="usedMemory", type="string", example="380MB"),
 *             @OA\Property(property="freeHdd", type="string", example="200MB"),
 *             @OA\Property(property="totalHdd", type="string", example="512MB"),
 *             @OA\Property(property="totalMemory", type="string", example="500MB"),
 *             @OA\Property(property="upTime", type="string", example="5d 4h 33m")
 *         )
 *     ),
 *     @OA\Response(response=500, description="Terjadi kesalahan pada server")
 * )
 */
    public function getSystemInfo()
{
    try {
        $client = $this->getClientLogin();

        $resourceData = $client->query(new Query('/system/resource/print'))->read();

        if (empty($resourceData)) {
            return response()->json(['error' => 'Resource data kosong!'], 500);
        }

        $buildTime = $resourceData[0]['build-time'] ?? 'Unknown Build Time';

        $dateTime = $this->parseBuildTime($buildTime);

        $currentTime = $dateTime->format('H:i:s');
        $currentDate = $dateTime->format('Y-m-d');

        $response = [
            'model' => $resourceData[0]['board-name'] ?? 'Unknown Model',
            'rosVersion' => $resourceData[0]['version'] ?? 'Unknown ROS Version',
            'cpuLoad' => $resourceData[0]['cpu-load'] ?? 'Unknown CPU Load',
            'time' => $currentTime,
            'date' => $currentDate,
            'freeMemory' => $resourceData[0]['free-memory'] ?? 'Unknown Used Memory',
            'usedMemory' => isset($resourceData[0]['total-memory'], $resourceData[0]['free-memory'])
                   ? strval($resourceData[0]['total-memory'] - $resourceData[0]['free-memory'])
                   : 'Unknown Used Memory',
            'freeHdd' => $resourceData[0]['free-hdd-space'] ?? 'Unknown Free HDD',
            'totalHdd' => $resourceData[0]['total-hdd-space'] ?? 'Unknown Total HDD',
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
        $formats = [
            'M/d/Y H:i:s',
            'Y-m-d H:i:s',
            'd/m/Y H:i:s',
        ];

        foreach ($formats as $format) {
            $dateTime = \DateTime::createFromFormat($format, $buildTime);
            if ($dateTime !== false) {
                return $dateTime;
            }
        }

        return new \DateTime();
    }

    /**
 * @OA\Post(
 *     path="/add-bandwidth-manager",
 *     summary="Tambah konfigurasi bandwidth manager",
 *     tags={"Bandwidth Manager"},
 *     security={{"bearerAuth": {}, "X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="pcq_rate_download", type="string", example="50M"),
 *             @OA\Property(property="pcq_rate_upload", type="string", example="25M"),
 *             @OA\Property(property="max_limit_download", type="string", example="50M"),
 *             @OA\Property(property="max_limit_upload", type="string", example="25M"),
 *             @OA\Property(property="total_bandwith", type="string", example="75M")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Konfigurasi bandwidth manager berhasil ditambahkan"
 *     ),
 *     @OA\Response(response=500, description="Terjadi kesalahan pada server")
 * )
 */
    public function addBandwidthManager(Request $request)
{
    try {
        $client = $this->getClientLogin();
        $results = [];

        $pcqRateDownload = $request->input('pcq_rate_download', '50M');
        $pcqRateUpload = $request->input('pcq_rate_upload', '25M');
        $maxLimitDownload = $request->input('max_limit_download', '50M');
        $maxLimitUpload = $request->input('max_limit_upload', '25M');
        $TotalBandwith = $request->input('total_bandwith', '75M');

        $dhcpClientQuery = new Query('/ip/dhcp-client/print');
        $dhcpClients = $client->query($dhcpClientQuery)->read();
        $outInterfaceDownload = '';
        $outInterfaceUpload = '';

        if (!empty($dhcpClients)) {
            foreach ($dhcpClients as $dhcpClient) {
                if (isset($dhcpClient['interface'])) {
                    $outInterfaceDownload = $dhcpClient['interface'];
                    $outInterfaceUpload = $dhcpClient['interface'];
                    break;
                }
            }
        }

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

        $results['queue_download'] = $client->query($downloadQueueQuery)->read();
        $results['queue_upload'] = $client->query($uploadQueueQuery)->read();

        $connectionMarkQuery = new Query('/ip/firewall/mangle/add');
        $connectionMarkQuery->equal('chain', 'prerouting');
        $connectionMarkQuery->equal('action', 'mark-connection');
        $connectionMarkQuery->equal('new-connection-mark', 'WAN_conn');
        $connectionMarkQuery->equal('in-interface', 'ether1');
        $connectionMarkQuery->equal('comment', 'Mark WAN connections');
        $results['mangle_connection_mark'] = $client->query($connectionMarkQuery)->read();

        $downloadMarkQuery = new Query('/ip/firewall/mangle/add');
        $downloadMarkQuery->equal('chain', 'forward');
        $downloadMarkQuery->equal('connection-mark', 'WAN_conn');
        $downloadMarkQuery->equal('action', 'mark-packet');
        $downloadMarkQuery->equal('new-packet-mark', 'download-traffic');
        $downloadMarkQuery->equal('in-interface', 'ether1');
        $downloadMarkQuery->equal('out-interface', $outInterfaceDownload);
        $downloadMarkQuery->equal('passthrough', 'yes');
        $downloadMarkQuery->equal('comment', 'Mark download traffic');
        $results['mangle_download_mark'] = $client->query($downloadMarkQuery)->read();

        $uploadMarkQuery = new Query('/ip/firewall/mangle/add');
        $uploadMarkQuery->equal('chain', 'forward');
        $uploadMarkQuery->equal('connection-mark', 'WAN_conn');
        $uploadMarkQuery->equal('action', 'mark-packet');
        $uploadMarkQuery->equal('new-packet-mark', 'upload-traffic');
        $uploadMarkQuery->equal('out-interface', $outInterfaceUpload);
        $uploadMarkQuery->equal('passthrough', 'yes');
        $uploadMarkQuery->equal('comment', 'Mark upload traffic');
        $results['mangle_upload_mark'] = $client->query($uploadMarkQuery)->read();

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

    /**
 * @OA\Get(
 *     path="/get-bandwidth-manager",
 *     summary="Ambil konfigurasi bandwidth manager",
 *     tags={"Bandwidth Manager"},
 *     security={{"bearerAuth": {}, "X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Response(
 *         response=200,
 *         description="Konfigurasi bandwidth manager berhasil diambil"
 *     ),
 *     @OA\Response(response=500, description="Terjadi kesalahan pada server")
 * )
 */
    public function getBandwidthManager(Request $request)
{
    try {
        $client = $this->getClientLogin();

        $typeQuery = new Query('/queue/type/print');
        $mangleQuery = new Query('/ip/firewall/mangle/print');
        $treeQuery = new Query('/queue/tree/print');

        $typeResult = $client->query($typeQuery)->read();
        $mangleResult = $client->query($mangleQuery)->read();
        $treeResult = $client->query($treeQuery)->read();

        foreach ($typeResult as &$type) {
            if (isset($type['pcq-rate'])) {
                $type['pcq-rate'] = $this->formatToMegabits($type['pcq-rate']);
            }
        }

        foreach ($treeResult as &$tree) {
            if (isset($tree['max-limit'])) {
                $tree['max-limit'] = $this->formatToMegabits($tree['max-limit']);
            }
        }

        $results = [
            'type' => $typeResult,
            'mangle' => $mangleResult,
            'tree' => $treeResult
        ];

        return response()->json([
            'message' => 'Bandwidth manager configuration berhasil diambil',
            'results' => $results
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
    }

    private function formatToMegabits($value)
    {
        if (substr($value, -1) === 'M') {
            return $value;
        }

        if (preg_match('/^(\d+)([kK]|[mM]|[gG])?/', $value, $matches)) {
            $number = (int) $matches[1];
            $unit = isset($matches[2]) ? strtoupper($matches[2]) : '';

            switch ($unit) {
                case 'K':
                    return round($number / 1000, 2) . 'M';
                case 'G':
                    return ($number * 1000) . 'M';
                case 'M':
                    return $number . 'M';
                default:
                    return round($number / 1000000, 2) . 'M';
            }
        }
        return $value . 'M';
    }

    /**
 * @OA\Post(
 *     path="/bandwidth-manager/edit-type/{name}",
 *     summary="Edit konfigurasi queue type",
 *     tags={"Bandwidth Manager"},
 *     security={{"bearerAuth": {}, "X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Parameter(
 *         name="name",
 *         in="path",
 *         required=true,
 *         description="Nama queue type yang akan diubah",
 *         @OA\Schema(type="string")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="pcq_rate", type="string", example="60M")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Queue type berhasil diperbarui"
 *     ),
 *     @OA\Response(response=404, description="Queue type tidak ditemukan"),
 *     @OA\Response(response=500, description="Terjadi kesalahan pada server")
 * )
 */
    public function editQueueType(Request $request, $name)
{
    try {
        $pcqRate = $request->input('pcq_rate');

        if (empty($pcqRate)) {
            return response()->json(['error' => 'Parameter pcq_rate is required'], 400);
        }

        $findQueueQuery = new Query('/queue/type/print');
        $findQueueQuery->where('name', $name);
        $queueData = $this->getClientLogin()->query($findQueueQuery)->read();

        if (empty($queueData)) {
            return response()->json(['error' => 'Queue type not found: ' . $name], 404);
        }

        $queueTypeQuery = new Query('/queue/type/set');
        $queueTypeQuery->equal('.id', $queueData[0]['.id']);
        $queueTypeQuery->equal('pcq-rate', $pcqRate);
        $queueTypeQuery->equal('pcq-limit', '50KiB');
        $queueTypeQuery->equal('pcq-total-limit', '2000KiB');
        $result = $this->getClientLogin()->query($queueTypeQuery)->read();

        return response()->json([
            'message' => 'Queue type configuration berhasil diperbarui',
            'result' => $result
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/bandwidth-manager/edit-tree/{name}",
 *     summary="Edit konfigurasi queue tree",
 *     tags={"Bandwidth Manager"},
 *     security={{"bearerAuth": {}, "X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Parameter(
 *         name="name",
 *         in="path",
 *         required=true,
 *         description="Nama queue tree yang akan diubah",
 *         @OA\Schema(type="string")
 *     ),
 *     @OA\RequestBody(
 *         required=true,
 *         @OA\JsonContent(
 *             type="object",
 *             @OA\Property(property="max_limit", type="string", example="70M")
 *         )
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Queue tree berhasil diperbarui"
 *     ),
 *     @OA\Response(response=404, description="Queue tree tidak ditemukan"),
 *     @OA\Response(response=500, description="Terjadi kesalahan pada server")
 * )
 */
    public function editQueueTree(Request $request, $name)
{
    try {
        $maxLimit = $request->input('max_limit');

        if (empty($maxLimit)) {
            return response()->json(['error' => 'Parameter max_limit is required'], 400);
        }

        $findTreeQuery = new Query('/queue/tree/print');
        $findTreeQuery->where('name', $name);
        $treeData = $this->getClientLogin()->query($findTreeQuery)->read();

        if (empty($treeData)) {
            return response()->json(['error' => 'Queue tree not found: ' . $name], 404);
        }

        $queueTreeQuery = new Query('/queue/tree/set');
        $queueTreeQuery->equal('.id', $treeData[0]['.id']);
        $queueTreeQuery->equal('max-limit', $maxLimit);
        $result = $this->getClientLogin()->query($queueTreeQuery)->read();

        return response()->json([
            'message' => 'Queue tree configuration berhasil diperbarui',
            'result' => $result
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
}

    /**
 * @OA\Delete(
 *     path="/bandwidth-manager/delete/{name}",
 *     summary="Hapus bandwidth manager berdasarkan nama",
 *     tags={"Bandwidth Manager"},
 *     security={{"bearerAuth": {}, "X-Tenant-ID": {}}},
 *     @OA\Parameter(ref="#/components/parameters/X-Tenant-ID"),
 *     @OA\Parameter(
 *         name="name",
 *         in="path",
 *         required=true,
 *         description="Nama item yang akan dihapus",
 *         @OA\Schema(type="string")
 *     ),
 *     @OA\Response(
 *         response=200,
 *         description="Berhasil dihapus"
 *     ),
 *     @OA\Response(response=404, description="Item tidak ditemukan"),
 *     @OA\Response(response=500, description="Terjadi kesalahan pada server")
 * )
 */
    public function deleteBandwidthManager(Request $request, $name)
{
    try {
        $results = [];
        $itemDeleted = false;

        $findQueueQuery = new Query('/queue/type/print');
        $findQueueQuery->where('name', $name);
        $queueData = $this->getClientLogin()->query($findQueueQuery)->read();

        if (!empty($queueData)) {
            $queueDeleteQuery = new Query('/queue/type/remove');
            $queueDeleteQuery->equal('.id', $queueData[0]['.id']);
            $results['queue_delete'] = $this->getClientLogin()->query($queueDeleteQuery)->read();
            $itemDeleted = true;

            return response()->json([
                'message' => 'Queue type berhasil dihapus',
                'results' => $results
            ]);
        }

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

        return response()->json([
            'message' => 'Bandwidth manager dengan nama "' . $name . '" tidak ditemukan',
            'results' => $results
        ], 404);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Exception: ' . $e->getMessage()], 500);
    }
    }

    /**
 * @OA\Post(
 *     path="/fixed-masquarade",
 *     summary="Perbaiki NAT yang bermasalah",
 *     tags={"Mikrotik"},
 *     @OA\Response(
 *         response=200,
 *         description="Berhasil memperbaiki rule NAT"
 *     ),
 *     @OA\Response(response=500, description="Terjadi kesalahan pada server")
 * )
 */
    public function fixNatInterfaceWithExtraction(Request $request)
    {
        try {
            $client = $this->getClient();

            $interfaceQuery = new Query('/interface/print');
            $interfaces = $client->query($interfaceQuery)->read();

            $availableInterfaces = [];
            foreach ($interfaces as $interface) {
                if (isset($interface['name'])) {
                    $availableInterfaces[$interface['name']] =  $interface['name'] ;
                }
            }

            $natQuery = new Query('/ip/firewall/nat/print');
            $natQuery->where('action', 'masquerade');
            $natRules = $client->query($natQuery)->read();

            $updatedRules = [];
            $noChanges = true;

            foreach ($natRules as $rule) {
                if ((isset($rule['out-interface']) && $rule['out-interface'] === 'unknown') ||
                    (isset($rule['invalid']) && $rule['invalid'] === 'true')) {

                    $ruleId = $rule['.id'];
                    $comment = isset($rule['comment']) ? $rule['comment'] : '';

                    $clientName = null;
                    if (!empty($comment)) {
                        if (strpos($comment, 'Masquerade_') !== false) {
                            $parts = explode('Masquerade_', $comment);
                            if (isset($parts[1])) {
                                $clientName = trim(explode(' ', $parts[1])[0]);
                            }
                        }
                        else if (strpos($comment, 'ovpn-') !== false) {
                            $parts = explode('ovpn-', $comment);
                            if (isset($parts[1])) {
                                $clientName = 'ovpn-' . trim(explode(' ', $parts[1])[0]);
                            }
                        }
                    }

                    if ($clientName === null && isset($rule['out-interface'])) {
                        $oldInterface = $rule['out-interface'];
                        if (strpos($oldInterface, '<') !== false && strpos($oldInterface, '>') !== false) {
                            $clientName = str_replace(['<', '>'], '', $oldInterface);
                        }
                    }

                    $newInterface = null;
                    if ($clientName !== null) {
                        foreach ($availableInterfaces as $ifName => $formattedName) {
                            if ($ifName === $clientName || strpos($ifName, $clientName) !== false) {
                                $newInterface = $formattedName;
                                break;
                            }
                        }
                    }

                    if ($newInterface === null && isset($rule['name'])) {
                        $ruleName = $rule['name'];
                        foreach ($availableInterfaces as $ifName => $formattedName) {
                            if (strpos($ruleName, str_replace('ovpn-', '', $ifName)) !== false) {
                                $newInterface = $formattedName;
                                break;
                            }
                        }
                    }

                    if ($newInterface !== null) {
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



