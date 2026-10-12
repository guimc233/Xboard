<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\TrafficAttributionService;
use App\Services\DeviceStateService;
use App\Services\ReportIngestionService;
use App\Models\ServerMachine;
use App\Models\ServerMachineBinding;
use App\WebSocket\NodeWorker;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    /**
     * server handshake api
     */
    public function handshake(Request $request): JsonResponse
    {
        $websocket = ['enabled' => false];

        if ((bool) admin_setting('server_ws_enable', 1) && Cache::has(NodeWorker::HEARTBEAT_CACHE_KEY)) {
            $customUrl = trim((string) admin_setting('server_ws_url', ''));

            if ($customUrl !== '') {
                $wsUrl = rtrim($customUrl, '/');
            } else {
                $wsScheme = $request->isSecure() ? 'wss' : 'ws';
                $wsUrl = "{$wsScheme}://{$request->getHttpHost()}/ws";
            }

            $websocket = [
                'enabled' => true,
                'ws_url' => $wsUrl,
            ];
        }

        return response()->json([
            'websocket' => $websocket
        ]);
    }

    /**
     * node report api - merge traffic + alive + status + metrics
     */
    public function report(Request $request): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        $machine = $request->attributes->get('machine_info');
        $instanceId = (string) $request->input('agent_instance_id', 'legacy');
        $sourceId = DeviceStateService::sourceId($machine?->id, $instanceId);
        $reportId = $request->input('report_id');
        $sequence = max(0, (int) $request->input('sequence', 0));
        $ingestion = app(ReportIngestionService::class);

        if (!$ingestion->claim((int) $node->id, $sourceId, is_string($reportId) ? $reportId : null)) {
            return response()->json([
                'data' => true,
                'duplicate' => true,
                'accepted_sequence' => $sequence,
            ]);
        }

        try {
            ServerService::touchNode($node, $sourceId);

            $traffic = $request->input('traffic');
            if (is_array($traffic) && !empty($traffic)) {
                ServerService::processTraffic($node, $traffic, $sourceId);
            }

            $profileTraffic = $request->input('profile_traffic');
            if (is_array($profileTraffic) && !empty($profileTraffic)) {
                app(TrafficAttributionService::class)->recordProfileTraffic($node, $profileTraffic);
            }

            if ($request->exists('alive')) {
                ServerService::processAlive($node->id, is_array($request->input('alive')) ? $request->input('alive') : [], $sourceId);
            }

            if ($request->exists('online')) {
                ServerService::processOnline($node, is_array($request->input('online')) ? $request->input('online') : [], $sourceId);
            }

            $status = $request->input('status');
            if (is_array($status) && !empty($status)) {
                ServerService::processStatus($node, $status, $sourceId);
            }

            $metrics = $request->input('metrics');
            if (is_array($metrics) && !empty($metrics)) {
                ServerService::updateMetrics($node, $metrics, $sourceId);
            }

            if ($machine) {
                $capabilities = $request->input('capabilities');
                $machine->forceFill([
                    'agent_version' => $request->input('agent_version', $machine->agent_version),
                    'kernel_type' => $request->filled('kernel_type')
                        ? ServerMachine::normalizeKernelType((string) $request->input('kernel_type'))
                        : $machine->kernel_type,
                    'capabilities' => is_array($capabilities) ? $capabilities : $machine->capabilities,
                    'agent_instance_id' => $instanceId !== 'legacy' ? $instanceId : $machine->agent_instance_id,
                    'last_seen_at' => time(),
                ])->saveQuietly();

                $bindingUpdate = ['last_seen_at' => time()];
                if ($request->filled('config_version')) {
                    $bindingUpdate['applied_config_version'] = max(0, (int) $request->input('config_version'));
                }
                if ($request->exists('config_error')) {
                    $bindingUpdate['last_error'] = $request->filled('config_error')
                        ? mb_substr((string) $request->input('config_error'), 0, 4000)
                        : null;
                }
                ServerMachineBinding::query()
                    ->where('server_id', $node->id)
                    ->where('machine_id', $machine->id)
                    ->update($bindingUpdate);
            }

            return response()->json([
                'data' => true,
                'duplicate' => false,
                'accepted_sequence' => $sequence,
            ]);
        } catch (\Throwable $e) {
            $ingestion->release((int) $node->id, $sourceId, is_string($reportId) ? $reportId : null);
            throw $e;
        }
    }
}
