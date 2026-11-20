<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AppointmentException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    /** Public: list all active services. Filter by doctor: /services?doctor_id=1 */
    public function index(Request $request): JsonResponse
    {
        $services = Service::with('doctor')
            ->where('is_active', true)
            ->when($request->doctor_id, fn ($q, $v) => $q->where('doctor_id', $v))
            ->get();

        return response()->json(['success' => true, 'data' => $services]);
    }

    public function show(Service $service): JsonResponse
    {
        abort_unless($service->is_active, 404);

        return response()->json(['success' => true, 'data' => $service->load('doctor')]);
    }

    // ----- Doctor role only (own services) -----

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'duration_minutes' => 'required|integer|min:10|max:240',
            'is_active' => 'boolean',
        ]);

        $service = $request->user()->doctor->services()->create($data);

        return response()->json(['success' => true, 'data' => $service], 201);
    }

    public function update(Request $request, Service $service): JsonResponse
    {
        $this->ensureOwner($request, $service);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'price' => 'sometimes|numeric|min:0',
            'duration_minutes' => 'sometimes|integer|min:10|max:240',
            'is_active' => 'boolean',
        ]);

        $service->update($data);

        return response()->json(['success' => true, 'data' => $service]);
    }

    public function destroy(Request $request, Service $service): JsonResponse
    {
        $this->ensureOwner($request, $service);

        $inUse = Appointment::where('service_id', $service->id)
            ->where('status', Appointment::STATUS_BOOKED)
            ->exists();

        if ($inUse) {
            throw new AppointmentException('Service has booked appointments and cannot be deleted.', 409);
        }

        $service->delete();

        return response()->json(['success' => true, 'message' => 'Service deleted.']);
    }

    private function ensureOwner(Request $request, Service $service): void
    {
        if ((int) $service->doctor_id !== (int) $request->user()->doctor->id) {
            throw new AppointmentException('This service does not belong to you.', 403);
        }
    }
}
