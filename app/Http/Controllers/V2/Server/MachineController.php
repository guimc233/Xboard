<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * machine controller
 */
class MachineController extends Controller
{
    /**
     * get nodes list for machine
     */
    public function nodes(Request $request): JsonResponse
    {
        $machine = $this->authenticateMachine($request);

        $nodes = ServerService::getMachineNodes($machine)
            ->map(fn($node) => [
                'id' => $node->id,
                'type' => $node->type,
                'name' => $node->name,
            ])->values();

        return response()->json([
            'nodes' => $nodes,
            'base_config' => [
                'push_interval' => (int) admin_setting('server_push_interval', 60),
                'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            ],
			'update' => $machine->update_status === 'pending' ? [
				'request_id' => $machine->update_request_id,
				'version' => $machine->update_version ?: 'dev',
			] : null,
        ]);
    }

    /**
     * report machine status
     */
    public function status(Request $request): JsonResponse
    {
        $request->validate([
            'cpu' => 'required|numeric|min:0|max:100',
            'mem.total' => 'required|integer|min:0',
            'mem.used' => 'required|integer|min:0',
            'swap.total' => 'nullable|integer|min:0',
            'swap.used' => 'nullable|integer|min:0',
            'disk.total' => 'nullable|integer|min:0',
            'disk.used' => 'nullable|integer|min:0',
            'net.in_speed' => 'nullable|numeric|min:0',
            'net.out_speed' => 'nullable|numeric|min:0',
            'agent_version' => 'nullable|string|max:64',
            'kernel_type' => 'nullable|string|max:32',
            'capabilities' => 'nullable|array',
            'agent_instance_id' => 'nullable|uuid',
        ]);

        $machine = $this->authenticateMachine($request);
        $recordedAt = now()->timestamp;

        $loadStatus = [
            'cpu' => (float) $request->input('cpu'),
            'mem' => [
                'total' => (int) $request->input('mem.total'),
                'used' => (int) $request->input('mem.used'),
            ],
            'swap' => [
                'total' => (int) $request->input('swap.total', 0),
                'used' => (int) $request->input('swap.used', 0),
            ],
            'disk' => [
                'total' => (int) $request->input('disk.total', 0),
                'used' => (int) $request->input('disk.used', 0),
            ],
            'updated_at' => $recordedAt,
        ];

        $netInSpeed = $request->input('net.in_speed');
        $netOutSpeed = $request->input('net.out_speed');

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $loadStatus['net'] = [
                'in_speed' => (float) $netInSpeed,
                'out_speed' => (float) $netOutSpeed,
            ];
        }

        $machine->forceFill([
            'load_status' => $loadStatus,
            'last_seen_at' => $recordedAt,
            'agent_version' => $request->input('agent_version', $machine->agent_version),
            'kernel_type' => $request->filled('kernel_type')
                ? ServerMachine::normalizeKernelType((string) $request->input('kernel_type'))
                : $machine->kernel_type,
            'capabilities' => $request->input('capabilities', $machine->capabilities),
            'agent_instance_id' => $request->input('agent_instance_id', $machine->agent_instance_id),
        ])->save();

		if ($machine->update_status === 'running'
			&& $machine->update_from_instance_id
			&& $request->filled('agent_instance_id')
			&& $request->input('agent_instance_id') !== $machine->update_from_instance_id
		) {
			$machine->forceFill([
				'update_status' => 'succeeded',
				'update_message' => 'Agent restarted with '.$request->input('agent_version', 'unknown'),
				'update_completed_at' => time(),
			])->save();
		}

        $historyData = [
            'machine_id' => $machine->id,
            'cpu' => (float) $request->input('cpu'),
            'mem_total' => (int) $request->input('mem.total'),
            'mem_used' => (int) $request->input('mem.used'),
            'disk_total' => (int) $request->input('disk.total', 0),
            'disk_used' => (int) $request->input('disk.used', 0),
            'recorded_at' => $recordedAt,
        ];

        if ($netInSpeed !== null && $netOutSpeed !== null) {
            $historyData['net_in_speed'] = (float) $netInSpeed;
            $historyData['net_out_speed'] = (float) $netOutSpeed;
        }

        ServerMachineLoadHistory::create($historyData);

        // Time-based cleanup: keep 24h of data, runs on ~5% of requests
        if (random_int(1, 20) === 1) {
            ServerMachineLoadHistory::query()
                ->where('machine_id', $machine->id)
                ->where('recorded_at', '<', now()->subDay()->timestamp)
                ->delete();
        }

        return response()->json(['data' => true]);
    }

	public function claimUpdate(Request $request): JsonResponse
	{
		$request->validate(['request_id' => 'required|uuid']);
		$machine = $this->authenticateMachine($request);
		$accepted = ServerMachine::query()
			->whereKey($machine->id)
			->where('update_request_id', $request->input('request_id'))
			->where('update_status', 'pending')
			->update([
				'update_status' => 'running',
				'update_started_at' => time(),
				'update_message' => 'Agent accepted update',
			]);
		return response()->json(['accepted' => $accepted === 1]);
	}

	public function finishUpdate(Request $request): JsonResponse
	{
		$request->validate([
			'request_id' => 'required|uuid',
			'status' => 'required|string|in:failed,succeeded',
			'message' => 'nullable|string|max:2000',
		]);
		$machine = $this->authenticateMachine($request);
		$updated = ServerMachine::query()
			->whereKey($machine->id)
			->where('update_request_id', $request->input('request_id'))
			->where('update_status', 'running')
			->update([
				'update_status' => $request->input('status'),
				'update_message' => $request->input('message'),
				'update_completed_at' => time(),
			]);
		return response()->json(['updated' => $updated === 1]);
	}

    private function authenticateMachine(Request $request): ServerMachine
    {
        $request->validate([
            'machine_id' => 'required|integer',
            'token' => 'required|string',
        ]);

        $machine = ServerMachine::where('id', $request->input('machine_id'))
            ->where('token', $request->input('token'))
            ->first();

        if (!$machine || !$machine->is_active) {
            abort(403, 'Machine not found or disabled');
        }

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();

        return $machine;
    }
}
