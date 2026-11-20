<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AppointmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Models\Appointment;
use App\Services\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function __construct(private AppointmentService $appointments)
    {
    }

    /** Patient sees own appointments, doctor sees own appointments. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'patient_id' => 'nullable|integer',
            'status' => 'nullable|in:booked,completed,cancelled',
            'date' => 'nullable|date_format:Y-m-d',
        ]);

        $user = $request->user();
        $query = Appointment::with(['patient', 'doctor', 'service']);

        if ($user->isPatient()) {
            $query->where('patient_id', $user->patient->id);
        } else {
            $query->where('doctor_id', $user->doctor->id)
                ->when($request->patient_id, fn ($q, $v) => $q->where('patient_id', $v));
        }

        $list = $query
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->date, fn ($q, $v) => $q->whereDate('appointment_date', $v))
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->paginate(15);

        return response()->json(['success' => true, 'data' => $list]);
    }

    /** Patient only. patient_id comes from the logged-in user. */
    public function store(StoreAppointmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['patient_id'] = $request->user()->patient->id;

        $appointment = $this->appointments->book($data);

        return response()->json([
            'success' => true,
            'message' => 'Appointment booked successfully.',
            'data' => $appointment,
        ], 201);
    }

    public function show(Request $request, Appointment $appointment): JsonResponse
    {
        $this->ensureParticipant($request, $appointment);

        return response()->json([
            'success' => true,
            'data' => $appointment->load(['patient', 'doctor', 'service']),
        ]);
    }

    /** Patient only (reschedule own appointment). */
    public function update(UpdateAppointmentRequest $request, Appointment $appointment): JsonResponse
    {
        $this->ensureParticipant($request, $appointment);

        return response()->json([
            'success' => true,
            'message' => 'Appointment updated successfully.',
            'data' => $this->appointments->reschedule($appointment, $request->validated()),
        ]);
    }

    /** Patient or doctor of this appointment. */
    public function cancel(Request $request, Appointment $appointment): JsonResponse
    {
        $request->validate(['cancel_reason' => 'nullable|string|max:255']);
        $this->ensureParticipant($request, $appointment);

        return response()->json([
            'success' => true,
            'message' => 'Appointment cancelled.',
            'data' => $this->appointments->cancel($appointment, $request->cancel_reason),
        ]);
    }

    /** Doctor only. */
    public function complete(Request $request, Appointment $appointment): JsonResponse
    {
        $this->ensureParticipant($request, $appointment);

        return response()->json([
            'success' => true,
            'message' => 'Appointment marked as completed.',
            'data' => $this->appointments->complete($appointment),
        ]);
    }

    private function ensureParticipant(Request $request, Appointment $appointment): void
    {
        $user = $request->user();

        $allowed = $user->isPatient()
            ? $user->patient && (int) $appointment->patient_id === (int) $user->patient->id
            : $user->doctor && (int) $appointment->doctor_id === (int) $user->doctor->id;

        if (!$allowed) {
            throw new AppointmentException('You do not have access to this appointment.', 403);
        }
    }
}
