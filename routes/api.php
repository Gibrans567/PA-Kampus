<?php

use App\Http\Controllers\ArtisanController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ByteController;
use App\Http\Controllers\CentralController;
use App\Http\Controllers\DHCPController;
use App\Http\Controllers\FailOverController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\HotspotProfileController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\MicasaController;
use App\Http\Controllers\MikrotikController;
use App\Http\Controllers\OpenVPNController;
use App\Http\Controllers\ScriptController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\VoucherController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/mikrotik/update-data', [VoucherController::class, 'UpdateData']);
Route::post('/mikrotik/update-status', [VoucherController::class, 'updateAllHotspotUsersByPhoneNumber']);


Route::post('/mikrotik/run-migrations', [ArtisanController::class, 'runMigrations']);
Route::post('/mikrotik/run-tenant-migrate', [ArtisanController::class, 'runTenantMigrations']);
Route::post('/mikrotik/run-rollback', [ArtisanController::class, 'runrollback']);

Route::post('/login', [AuthController::class, 'login']);
Route::post('/regis', [AuthController::class, 'register']);
Route::get('/Email', [AuthController::class, 'GetEmail']);

Route::post('/mikrotik/upload-file', [FileController::class, 'uploadFileToMikrotik']);
Route::get('/mikrotik/list-file', [FileController::class, 'listFilesOnMikrotik']);
Route::get('/mikrotik/download-file/{fileName}', [FileController::class, 'downloadFileFromMikrotik'])->where('fileName', '.*');
Route::delete('/mikrotik/delete-file/{fileName}', [FileController::class, 'deleteFileOnMikrotik'])->where('fileName', '.*');

Route::post('/mikrotik/add', [MenuController::class, 'addMenu']);
Route::put('/mikrotik/edit/{id}', [MenuController::class, 'editMenu']);
Route::get('/mikrotik/get-all-menu', [MenuController::class, 'getAllMenus']);
Route::get('/mikrotik/get-all-order', [MenuController::class, 'getAllOrders']);

Route::post('/mikrotik/add-hotspot-login', [MikrotikController::class, 'addHotspotUser1']);

Route::post('/mikrotik/terminal-mikrotik', [TerminalController::class, 'executeMikrotikCommand']);
Route::post('/mikrotik/terminal-cmd', [TerminalController::class, 'executeCmdCommand']);

Route::middleware(['auth:sanctum', 'tenant','role:admin,pegawai'])->group(function () {
    Route::get('/mikrotik/list-voucher', [VoucherController::class, 'getVoucherLists']);
    Route::post('/mikrotik/add-hotspot-login-Annual', [VoucherController::class, 'AddVoucher']);
    Route::post('/mikrotik/add-Multiple-user', [VoucherController::class, 'adduserBatch']);
    Route::get('/mikrotik/list-akun', [VoucherController::class, 'getHotspotUsers']);

    Route::get('/mikrotik-config', [CentralController::class, 'index']);
    Route::post('/mikrotik-config', [CentralController::class, 'store']);
    Route::get('/mikrotik-config/{id}', [CentralController::class, 'show']);
    Route::post('/mikrotik-config/{id}', [CentralController::class, 'update']);
    Route::delete('/mikrotik-config/{id}', [CentralController::class, 'destroy']);

    Route::get('/mikrotik/get-Hotspot-by-phone/{no_hp}', [MikrotikController::class, 'getHotspotUserByPhoneNumber']);
    Route::get('/mikrotik/get-Hotspot-users/{profile_name}', [MikrotikController::class, 'getHotspotUsersByProfileName']);
    Route::post('/mikrotik/add-Hotspot-User', [MikrotikController::class, 'addHotspotUser']);
    Route::post('/mikrotik/hotspot-user/{no_hp}', [MikrotikController::class, 'editHotspotUser']);

    Route::post('/mikrotik/add-script', [ScriptController::class, 'addScriptAndScheduler']);

    Route::post('/logout', [AuthController::class, 'logout']);


    Route::get('/mikrotik/get-profile/{profile_name}', [HotspotProfileController::class, 'getHotspotProfileByName']);
    Route::post('/mikrotik/hotspot-profile/{profile_name}', [HotspotProfileController::class, 'updateHotspotProfile']);
    Route::post('/mikrotik/set-profile', [HotspotProfileController::class, 'setHotspotProfile']);
    Route::delete('/mikrotik/delete-profile/{profile_name}', [HotspotProfileController::class, 'deleteHotspotProfile']);
    Route::get('/mikrotik/get-profile', [HotspotProfileController::class, 'getHotspotProfile']);


    Route::get('/mikrotik/Dhcp-info/{name}', [DHCPController::class, 'getDhcpServerByName']);
    Route::get('/mikrotik/Network-info', [DHCPController::class, 'getNetworks']);
    Route::get('/mikrotik/Network-info/{gateaway}', [DHCPController::class, 'getNetworksByGateway']);
    Route::get('/mikrotik/Leases-info', [DHCPController::class, 'getLeases']);
    Route::post('/mikrotik/Add-dhcp', [DHCPController::class, 'addOrUpdateDhcp']);
    Route::post('/mikrotik/Add-network', [DHCPController::class, 'addOrUpdateNetwork']);
    Route::post('/mikrotik/Make-lease', [DHCPController::class, 'makeLeaseStatic']);
    Route::delete('/mikrotik/delete-dhcp/{name}', [DHCPController::class, 'deleteDhcpServerByName']);
    Route::delete('/mikrotik/delete-network/{gateway}', [DHCPController::class, 'deleteDhcpNetworkByGateway']);
    Route::delete('/mikrotik/delete-lease/{address}', [DHCPController::class, 'deleteDhcpLeaseAndIpBindingByAddress']);

    Route::post('/mikrotik/get-data-by-date', [ByteController::class, 'getHotspotUsersByDateRange1']);
    Route::post('/mikrotik/get-data-by-date-full', [ByteController::class, 'getHotspotUsersByDateRangeWithLoginCheck']);
    Route::post('/mikrotik/get-data-by-date-role', [ByteController::class, 'getHotspotUsersByUniqueRole']);
    Route::get('/mikrotik/get-data-all-profile', [ByteController::class, 'getHotspotProfile']);
    Route::post('/mikrotik/Update-byte-log', [ByteController::class, 'logApiUsageBytes']);
    Route::get('/mikrotik/get-data-users', [ByteController::class, 'getHotspotUsers']);
    Route::post('/mikrotik/switch', [CentralController::class, 'switchConfig']);
    Route::delete('/mikrotik/deleteExpiredHotspotUsersByPhone/{no_hp}', [ByteController::class, 'deleteHotspotUserByPhoneNumber']);

    Route::get('/mikrotik/get-info', [ScriptController::class, 'getSystemInfo']);

    Route::get('/mikrotik/get-netwatch', [FailOverController::class, 'getNetwatch']);
    Route::get('/mikrotik/get-route', [FailOverController::class, 'getRoute']);
    Route::post('/mikrotik/add-netwatch', [FailOverController::class, 'addNetwatch']);
    Route::get('/mikrotik/get-log', [FailOverController::class, 'getLatestLogs']);

    Route::post('/mikrotik/Check-Vpn', [OpenVPNController::class, 'checkInterface']);

    Route::post('/add-bandwidth-manager', [ScriptController::class, 'addBandwidthManager']);
    Route::get('/get-bandwidth-manager', [ScriptController::class, 'getBandwidthManager']);
    Route::post('/bandwidth-manager/edit-type/{name}', [ScriptController::class, 'editQueueType']);
    Route::post('/bandwidth-manager/edit-tree/{name}', [ScriptController::class, 'editQueueTree']);
    Route::delete('/bandwidth-manager/delete/{name}', [ScriptController::class, 'deleteBandwidthManager']);

    Route::post('/mikrotik/edit-admin-micasa/{no_hp}', [MicasaController::class, 'adminEditMicasa']);
    Route::get('/mikrotik/get-active-micasa', [MicasaController::class, 'getActiveUsersMicasa']);
    Route::delete('/delete-active-user-by-username/{id}', [MicasaController::class, 'deleteActiveUserByIdMicasa']);



});

Route::middleware(['auth:sanctum','role:admin,pegawai'])->group(function () {
    Route::get('/mikrotik/get-profile-user', [AuthController::class, 'getUserByToken']);
});


Route::post('/mikrotik/hotspot-micasa/{no_hp}', [MicasaController::class, 'EditMicasa']);
Route::get('/mikrotik/get-user-micasa', [MicasaController::class, 'getUserMicasa']);
Route::post('/mikrotik/login-micasa', [MicasaController::class, 'loginMicasa']);
Route::delete('/mikrotik/delete-mac-cookie', [MicasaController::class, 'getActiveUsersAndCleanCookiesMicasa']);

Route::post('/configure-vpn-server', [OpenVPNController::class, 'configureVpnServer']);

Route::post('/configure-nat', [OpenVPNController::class, 'configureNat']);

Route::post('/configure-masquarade', [OpenVPNController::class, 'addNatMasqueradeFromCommand']);
Route::post('/fixed-masquarade', [ScriptController::class, 'fixNatInterfaceWithExtraction']);

Route::post('/mikrotik/OpenVPN', [OpenVPNController::class, 'createOpenVpnClient1']);
Route::post('/mikrotik/OpenVPNServer', [OpenVPNController::class, 'configureVpnServer']);
Route::post('/mikrotik/OpenVPNClient', [OpenVPNController::class, 'configureOpenVpnClient']);

Route::post('/mikrotik/Check-Vpn1', [OpenVPNController::class, 'checkVpnStatus']);

Route::post('/Check-voucher', [VoucherController::class, 'LoginVoucher']);
Route::post('/delete-voucher-all-tenant', [VoucherController::class, 'DeleteAlltenant']);


