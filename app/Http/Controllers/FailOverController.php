<?php

namespace App\Http\Controllers;
use RouterOS\Client;
use RouterOS\Query;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;


class FailOverController extends BaseMikrotikController
{

    private function setupRoutingFailover($client, $gatewayMain, $gatewayBackup, $metricMain = 1, $metricBackup = 2, $pingCheck = 'ping')
{
    $query = new Query('/ip/route/add');
    $query->equal('gateway', $gatewayMain)
        ->equal('distance', $metricMain)
        ->equal('check-gateway', $pingCheck);
    $client->query($query);

    $query = new Query('/ip/route/add');
    $query->equal('gateway', $gatewayBackup)
        ->equal('distance', $metricBackup)
        ->equal('check-gateway', $pingCheck);
    $client->query($query);
    }

    private function setupNetwatchFailover($client, $gatewayMain, $gatewayBackup, $interval = '10s', $timeout = '1s')
    {
        $query = new Query('/tool/netwatch/add');
        $query->equal('host', $gatewayMain)
            ->equal('interval', $interval)
            ->equal('timeout', $timeout)
            ->equal('up-script', '/ip route enable [find gateway=' . $gatewayMain . ']')
            ->equal('down-script', '/ip route disable [find gateway=' . $gatewayMain . ']');
        $client->query($query);

        $query = new Query('/tool/netwatch/add');
        $query->equal('host', $gatewayBackup)
            ->equal('interval', $interval)
            ->equal('timeout', $timeout)
            ->equal('up-script', '/ip route enable [find gateway=' . $gatewayBackup . ']')
            ->equal('down-script', '/ip route disable [find gateway=' . $gatewayBackup . ']');
        $client->query($query);
    }

    public function addFailoverData(Request $request)
    {
        $request->validate([
            'gateway_main' => 'required|ip',
            'gateway_backup' => 'required|ip',
            'metric_main' => 'required|integer|min:1',
            'metric_backup' => 'required|integer|min:2',
            'interval' => 'nullable|string|in:5s,10s,20s,30s',
            'timeout' => 'nullable|string|in:1s,2s,3s,5s',
        ]);

        $gatewayMain = $request->input('gateway_main');
        $gatewayBackup = $request->input('gateway_backup');
        $metricMain = $request->input('metric_main', 1);
        $metricBackup = $request->input('metric_backup', 2);
        $interval = $request->input('interval', '10s');
        $timeout = $request->input('timeout', '1s');

        try {
         $client = $this->getClient();

            $this->setupRoutingFailover($client, $gatewayMain, $gatewayBackup, $metricMain, $metricBackup);

            $this->setupNetwatchFailover($client, $gatewayMain, $gatewayBackup, $interval, $timeout);

            // Simpan data failover baru ke database atau tempat penyimpanan lainnya jika diperlukan
            // Misalnya menyimpan data ke tabel `failover_gateways`
            // FailoverGateway::create([
            //     'gateway_main' => $gatewayMain,
            //     'gateway_backup' => $gatewayBackup,
            //     'metric_main' => $metricMain,
            //     'metric_backup' => $metricBackup,
            //     'interval' => $interval,
            //     'timeout' => $timeout
            // ]);

            return response()->json([
                'message' => 'Data failover berhasil ditambahkan.',
                'data' => [
                    'gateway_main' => $gatewayMain,
                    'gateway_backup' => $gatewayBackup,
                    'metric_main' => $metricMain,
                    'metric_backup' => $metricBackup,
                    'interval' => $interval,
                    'timeout' => $timeout,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function getRoute()
    {
         $client = $this->getClient();

        try {
            $query = new Query('/ip/route/print');

            $leases = $client->query($query)->read();

            return response()->json([
                'status' => 'success',
                'leases' => $leases
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch leases: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteFailoverData(Request $request)
{
    $request->validate([
        'gateway_main' => 'required|ip',
        'gateway_backup' => 'required|ip',
    ]);

    $gatewayMain = $request->input('gateway_main');
    $gatewayBackup = $request->input('gateway_backup');

    try {
         $client = $this->getClient();

        $routeMainQuery = (new Query('/ip/route/print'))->where('gateway', $gatewayMain);
        $routeMain = $client->query($routeMainQuery)->read();

        $routeBackupQuery = (new Query('/ip/route/print'))->where('gateway', $gatewayBackup);
        $routeBackup = $client->query($routeBackupQuery)->read();

        if (!empty($routeMain)) {
            $deleteRouteMainQuery = (new Query('/ip/route/remove'))->equal('.id', $routeMain[0]['.id']);
            $client->query($deleteRouteMainQuery)->read();
        }

        if (!empty($routeBackup)) {
            $deleteRouteBackupQuery = (new Query('/ip/route/remove'))->equal('.id', $routeBackup[0]['.id']);
            $client->query($deleteRouteBackupQuery)->read();
        }

        $netwatchMainQuery = (new Query('/tool/netwatch/print'))->where('host', $gatewayMain);
        $netwatchMain = $client->query($netwatchMainQuery)->read();

        $netwatchBackupQuery = (new Query('/tool/netwatch/print'))->where('host', $gatewayBackup);
        $netwatchBackup = $client->query($netwatchBackupQuery)->read();

        if (!empty($netwatchMain)) {
            $deleteNetwatchMainQuery = (new Query('/tool/netwatch/remove'))->equal('.id', $netwatchMain[0]['.id']);
            $client->query($deleteNetwatchMainQuery)->read();
        }

        if (!empty($netwatchBackup)) {
            $deleteNetwatchBackupQuery = (new Query('/tool/netwatch/remove'))->equal('.id', $netwatchBackup[0]['.id']);
            $client->query($deleteNetwatchBackupQuery)->read();
        }

        return response()->json([
            'message' => 'Konfigurasi failover berhasil dihapus.',
            'data' => [
                'gateway_main' => $gatewayMain,
                'gateway_backup' => $gatewayBackup,
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
    }

    public function getNetwatch()
{
    $client = $this->getClient();

    try {
        $query = new Query('/tool/netwatch/print');

        $netwatchData = $client->query($query)->read();

        return response()->json([
            'status' => 'success',
            'netwatch' => $netwatchData
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Failed to fetch netwatch: ' . $e->getMessage(),
        ], 500);
    }
}



}
