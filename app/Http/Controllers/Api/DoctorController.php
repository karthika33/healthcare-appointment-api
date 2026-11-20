<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Patient;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    /** Public: list all active doctors with their services. */
    public function index(Request $request): JsonResponse
    {
        $doctors = Doctor::with([
            'schedules',
            'services' => fn ($q) => $q->where('is_active', true),
        ])
            ->where('is_active', true)
            ->when($request->specialization, fn ($q, $v) => $q->where('specialization', $v))
            ->when($request->search, fn ($q, $v) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$v}%")))
            ->get();

        return response()->json(['success' => true, 'data' => $doctors]);
    }

    public function show(Doctor $doctor): JsonResponse
    {
        abort_unless($doctor->is_active, 404);

        return response()->json([
            'success' => true,
            'data' => $doctor->load([
                'schedules',
                'services' => fn ($q) => $q->where('is_active', true),
            ]),
        ]);
    }

    public function availability(Request $request, Doctor $doctor, AppointmentService $service): JsonResponse
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'slot_minutes' => 'nullable|integer|min:10|max:240',
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'doctor_id' => $doctor->id,
                'date' => $request->date,
                'available_slots' => $service->availableSlots(
                    $doctor,
                    $request->date,
                    (int) ($request->slot_minutes ?: AppointmentService::DEFAULT_DURATION)
                ),
            ],
        ]);
    }

    // ----- Doctor role only -----

    public function profile(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()->doctor->load(['schedules', 'services']),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $doctor = $request->user()->doctor;

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'specialization' => 'sometimes|string|max:255',
            'bio' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($data['name'])) {
            $request->user()->update(['name' => $data['name']]);
            unset($data['name']);
        }

        $doctor->update($data);

        return response()->json(['success' => true, 'data' => $doctor->load(['user', 'schedules'])]);
    }

    /** Replace the logged-in doctor's weekly working hours. */
    public function setSchedules(Request $request): JsonResponse
    {
        $doctor = $request->user()->doctor;

        $data = $request->validate([
            'schedules' => 'required|array|min:1',
            'schedules.*.day_of_week' => 'required|integer|between:0,6',
            'schedules.*.start_time' => 'required|date_format:H:i',
            'schedules.*.end_time' => 'required|date_format:H:i|after:schedules.*.start_time',
        ]);

        $doctor->schedules()->delete();
        $doctor->schedules()->createMany($data['schedules']);

        return response()->json(['success' => true, 'data' => $doctor->load('schedules')]);
    }

    /** Patients who have appointments with the logged-in doctor. */
    public function patients(Request $request): JsonResponse
    {
        $request->validate(['status' => 'nullable|in:booked,completed,cancelled']);

        $doctorId = $request->user()->doctor->id;

        $patients = Patient::whereHas('appointments', function ($q) use ($doctorId, $request) {
            $q->where('doctor_id', $doctorId)
                ->when($request->status, fn ($q, $v) => $q->where('status', $v));
        })
            ->withCount(['appointments as total_appointments' => fn ($q) => $q->where('doctor_id', $doctorId)])
            ->paginate(15);

        $pagination = $patients->toArray();
        unset(
            $pagination['first_page_url'],
            $pagination['last_page_url'],
            $pagination['links'],
            $pagination['next_page_url'],
            $pagination['path'],
            $pagination['prev_page_url']
        );

        return response()->json(['success' => true, 'data' => $pagination]);
    }
}
