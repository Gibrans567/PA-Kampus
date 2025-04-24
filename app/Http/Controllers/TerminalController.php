<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use RouterOS\Query;

class TerminalController extends CentralController
{
    public function executeMikrotikCommand(Request $request)
{
    try {
        $command = $request->input('command');

        $client = $this->getClientLogin();

        $query = new Query($command);

        $response = $client->q($query)->read();

        return response()->json(['result' => $response], 200);

    } catch (\Exception $e) {
        return response()->json(['error' => 'Mikrotik command execution failed: ' . $e->getMessage()], 500);
    }
        }

    public function executeCmdCommand(Request $request)
{
    try {
        $command = $request->input('command');
        $output = [];
        $return_var = 0;

        set_time_limit(30);
        exec($command, $output, $return_var);

        if ($return_var !== 0) {
            return response()->json(['error' => 'Command execution failed: ' . implode("\n", $output)], 500);
        }

        return response()->json(['result' => $output], 200);

    } catch (\Exception $e) {
        return response()->json(['error' => 'CMD command execution failed: ' . $e->getMessage()], 500);
    }
        }

}
