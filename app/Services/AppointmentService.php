<?php

namespace App\Services;

use App\Exceptions\AppointmentException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AppointmentService
{
    public const DEFAULT_DURATION = 30;

    public function book(array $data): Appointment
    {
        return DB::transaction(function () use ($data) {
            // Lock doctor row so two parallel requests can't double-book the same slot.
            Doctor::whereKey($data['doctor_id'])->lockForUpdate()->firstOrFail();

            $this->assertServiceBelongsToDoctor($data['service_id'] ?? null, (int) $data['doctor_id'], true);

            $slot = $this->resolveSlot($data);
            $this->assertBookable((int) $data['doctor_id'], (int) $data['patient_id'], $slot);

            return Appointment::create([
                'patient_id' => $data['patient_id'],
                'doctor_id' => $data['doctor_id'],
                'service_id' => $data['service_id'],
                'appointment_date' => $slot['date'],
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
                'status' => Appointment::STATUS_BOOKED,
                'notes' => $data['notes'] ?? null,
            ])->load(['patient', 'doctor', 'service']);
        });
    }

    public function reschedule(Appointment $appointment, array $data): Appointment
    {
        if ($appointment->status !== Appointment::STATUS_BOOKED) {
            throw new AppointmentException('Only booked appointments can be updated.', 422);
        }

        return DB::transaction(function () use ($appointment, $data) {
            Doctor::whereKey($appointment->doctor_id)->lockForUpdate()->firstOrFail();

            if (!empty($data['service_id'])) {
                $this->assertServiceBelongsToDoctor((int) $data['service_id'], (int) $appointment->doctor_id, true);
            }

            $merged = [
                'service_id' => $data['service_id'] ?? $appointment->service_id,
                'appointment_date' => $data['appointment_date'] ?? $appointment->appointment_date->format('Y-m-d'),
                'start_time' => $data['start_time'] ?? substr($appointment->start_time, 0, 5),
                'duration_minutes' => $data['duration_minutes'] ?? null,
            ];

            // Keep the old duration if neither duration nor service changed.
            if ($merged['duration_minutes'] === null && !array_key_exists('service_id', $data)) {
                $merged['duration_minutes'] = Carbon::parse($appointment->start_time)
                    ->diffInMinutes(Carbon::parse($appointment->end_time));
            }

            $slot = $this->resolveSlot($merged);
            $this->assertBookable((int) $appointment->doctor_id, (int) $appointment->patient_id, $slot, $appointment->id);

            $appointment->update([
                'service_id' => $merged['service_id'],
                'appointment_date' => $slot['date'],
                'start_time' => $slot['start'],
                'end_time' => $slot['end'],
                'notes' => $data['notes'] ?? $appointment->notes,
            ]);

            return $appointment->load(['patient', 'doctor', 'service']);
        });
    }

    public function cancel(Appointment $appointment, ?string $reason = null): Appointment
    {
        if ($appointment->status !== Appointment::STATUS_BOOKED) {
            throw new AppointmentException('Only booked appointments can be cancelled.', 422);
        }

        $appointment->update([
            'status' => Appointment::STATUS_CANCELLED,
            'cancel_reason' => $reason,
            'cancelled_at' => now(),
        ]);

        return $appointment->load(['patient', 'doctor', 'service']);
    }

    public function complete(Appointment $appointment): Appointment
    {
        if ($appointment->status !== Appointment::STATUS_BOOKED) {
            throw new AppointmentException('Only booked appointments can be completed.', 422);
        }

        $appointment->update(['status' => Appointment::STATUS_COMPLETED]);

        return $appointment->load(['patient', 'doctor', 'service']);
    }

    /** Free slots for a doctor on a date, based on working hours minus booked appointments. */
    public function availableSlots(Doctor $doctor, string $date, int $slotMinutes = self::DEFAULT_DURATION): array
    {
        $day = Carbon::parse($date);
        $schedules = $doctor->schedules()->where('day_of_week', $day->dayOfWeek)->get();

        $booked = $doctor->appointments()
            ->whereDate('appointment_date', $date)
            ->where('status', Appointment::STATUS_BOOKED)
            ->get(['start_time', 'end_time']);

        $slots = [];
        foreach ($schedules as $schedule) {
            $cursor = Carbon::parse("$date {$schedule->start_time}");
            $limit = Carbon::parse("$date {$schedule->end_time}");

            while ($cursor->copy()->addMinutes($slotMinutes)->lte($limit)) {
                $start = $cursor->format('H:i:s');
                $end = $cursor->copy()->addMinutes($slotMinutes)->format('H:i:s');

                $taken = $booked->contains(fn ($a) => $a->start_time < $end && $a->end_time > $start);
                $past = $cursor->lt(now());

                if (!$taken && !$past) {
                    $slots[] = ['start_time' => substr($start, 0, 5), 'end_time' => substr($end, 0, 5)];
                }
                $cursor->addMinutes($slotMinutes);
            }
        }

        return $slots;
    }

    private function assertServiceBelongsToDoctor(?int $serviceId, int $doctorId, bool $required = false): void
    {
        if (!$serviceId) {
            if ($required) {
                throw new AppointmentException('service_id is required.', 422);
            }
            return;
        }

        $service = Service::find($serviceId);

        if (!$service || !$service->is_active || (int) $service->doctor_id !== $doctorId) {
            throw new AppointmentException('Selected service is not offered by this doctor.', 422);
        }
    }

    private function resolveSlot(array $data): array
    {
        $duration = $data['duration_minutes'] ?? null;

        if (!$duration && !empty($data['service_id'])) {
            $duration = Service::whereKey($data['service_id'])->value('duration_minutes');
        }
        $duration = (int) ($duration ?: self::DEFAULT_DURATION);

        $start = Carbon::parse("{$data['appointment_date']} {$data['start_time']}");
        $end = $start->copy()->addMinutes($duration);

        if (!$end->isSameDay($start)) {
            throw new AppointmentException('Appointment must end on the same day it starts.', 422);
        }

        return [
            'date' => $start->format('Y-m-d'),
            'start' => $start->format('H:i:s'),
            'end' => $end->format('H:i:s'),
        ];
    }

    private function assertBookable(int $doctorId, int $patientId, array $slot, ?int $ignoreId = null): void
    {
        if (Carbon::parse("{$slot['date']} {$slot['start']}")->isPast()) {
            throw new AppointmentException('Appointment time must be in the future.', 422);
        }

        $dayOfWeek = Carbon::parse($slot['date'])->dayOfWeek;
        $withinHours = DoctorSchedule::where('doctor_id', $doctorId)
            ->where('day_of_week', $dayOfWeek)
            ->where('start_time', '<=', $slot['start'])
            ->where('end_time', '>=', $slot['end'])
            ->exists();

        if (!$withinHours) {
            throw new AppointmentException('Doctor is not available at the requested time.', 422);
        }

        $doctorClash = Appointment::overlapping('doctor_id', $doctorId, $slot['date'], $slot['start'], $slot['end'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($doctorClash) {
            throw new AppointmentException('Doctor already has an appointment in this time slot.', 409);
        }

        $patientClash = Appointment::overlapping('patient_id', $patientId, $slot['date'], $slot['start'], $slot['end'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($patientClash) {
            throw new AppointmentException('Patient already has an appointment in this time slot.', 409);
        }
    }
}
