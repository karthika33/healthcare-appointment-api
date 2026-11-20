<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;
    private Service $service;
    private Patient $patient;
    private Patient $patient2;
    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = $this->makeDoctor('doc@test.com');
        $this->service = $this->makeService($this->doctor);
        $this->patient = $this->makePatient('p1@test.com', '1111111111');
        $this->patient2 = $this->makePatient('p2@test.com', '2222222222');
        $this->date = Carbon::now()->addDays(2)->format('Y-m-d');
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name' => ucfirst($role) . ' ' . $email,
            'email' => $email,
            'password' => Hash::make('secret123'),
            'role' => $role,
        ]);
    }

    private function makeDoctor(string $email): Doctor
    {
        $user = $this->makeUser($email, User::ROLE_DOCTOR);
        $doctor = Doctor::create(['user_id' => $user->id, 'specialization' => 'General']);

        foreach (range(0, 6) as $day) {
            $doctor->schedules()->create(['day_of_week' => $day, 'start_time' => '09:00:00', 'end_time' => '17:00:00']);
        }

        return $doctor;
    }

    private function makePatient(string $email, string $phone): Patient
    {
        $user = $this->makeUser($email, User::ROLE_PATIENT);

        return Patient::create(['user_id' => $user->id, 'phone' => $phone]);
    }

    private function makeService(Doctor $doctor): Service
    {
        return Service::create([
            'doctor_id' => $doctor->id, 'name' => 'Consultation', 'price' => 500, 'duration_minutes' => 30,
        ]);
    }

    private function book(Patient $patient, string $time, ?Doctor $doctor = null, ?Service $service = null)
    {
        $doctor = $doctor ?? $this->doctor;
        $service = $service ?? $this->service;

        Sanctum::actingAs($patient->user);

        return $this->postJson('/api/appointments', [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'appointment_date' => $this->date,
            'start_time' => $time,
        ]);
    }

    public function test_patient_can_book_appointment(): void
    {
        $this->book($this->patient, '10:00')
            ->assertCreated()
            ->assertJsonPath('data.end_time', '10:30:00')
            ->assertJsonPath('data.patient_id', $this->patient->id);
    }

    public function test_unauthenticated_user_cannot_book(): void
    {
        $this->postJson('/api/appointments', [])->assertStatus(401);
    }

    public function test_doctor_cannot_book_appointment(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->postJson('/api/appointments', [
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->service->id,
            'appointment_date' => $this->date,
            'start_time' => '10:00',
        ])->assertStatus(403);
    }

    public function test_service_is_required(): void
    {
        Sanctum::actingAs($this->patient->user);

        $this->postJson('/api/appointments', [
            'doctor_id' => $this->doctor->id,
            'appointment_date' => $this->date,
            'start_time' => '10:00',
        ])->assertStatus(422)->assertJsonValidationErrors('service_id');
    }

    public function test_service_must_belong_to_doctor(): void
    {
        $otherDoctor = $this->makeDoctor('other@test.com');
        $otherService = $this->makeService($otherDoctor);

        $this->book($this->patient, '10:00', $this->doctor, $otherService)->assertStatus(422);
    }

    public function test_overlapping_appointment_is_rejected(): void
    {
        $this->book($this->patient, '10:00')->assertCreated();
        $this->book($this->patient2, '10:15')->assertStatus(409);
    }

    public function test_back_to_back_appointment_is_allowed(): void
    {
        $this->book($this->patient, '10:00')->assertCreated();
        $this->book($this->patient2, '10:30')->assertCreated();
    }

    public function test_patient_cannot_double_book_with_another_doctor(): void
    {
        $this->book($this->patient, '10:00')->assertCreated();

        $other = $this->makeDoctor('b@test.com');
        $otherService = $this->makeService($other);

        $this->book($this->patient, '10:10', $other, $otherService)->assertStatus(409);
    }

    public function test_outside_working_hours_is_rejected(): void
    {
        $this->book($this->patient, '18:00')->assertStatus(422);
    }

    public function test_cancel_frees_the_slot(): void
    {
        $id = $this->book($this->patient, '11:00')->json('data.id');

        Sanctum::actingAs($this->patient->user);
        $this->patchJson("/api/appointments/{$id}/cancel", ['cancel_reason' => 'Not feeling well'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->book($this->patient2, '11:00')->assertCreated();
    }

    public function test_other_patient_cannot_cancel_appointment(): void
    {
        $id = $this->book($this->patient, '11:00')->json('data.id');

        Sanctum::actingAs($this->patient2->user);
        $this->patchJson("/api/appointments/{$id}/cancel")->assertStatus(403);
    }

    public function test_only_doctor_can_complete_appointment(): void
    {
        $id = $this->book($this->patient, '12:00')->json('data.id');

        Sanctum::actingAs($this->patient->user);
        $this->patchJson("/api/appointments/{$id}/complete")->assertStatus(403);

        Sanctum::actingAs($this->doctor->user);
        $this->patchJson("/api/appointments/{$id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_patient_sees_only_own_appointments(): void
    {
        $this->book($this->patient, '10:00')->assertCreated();
        $this->book($this->patient2, '11:00')->assertCreated();

        Sanctum::actingAs($this->patient->user);
        $this->getJson('/api/appointments')->assertOk()->assertJsonCount(1, 'data.data');
    }

    public function test_doctor_patient_list_contains_only_own_patients(): void
    {
        $otherDoctor = $this->makeDoctor('b@test.com');
        $otherService = $this->makeService($otherDoctor);

        $this->book($this->patient, '10:00')->assertCreated();
        $this->book($this->patient2, '10:00', $otherDoctor, $otherService)->assertCreated();

        Sanctum::actingAs($this->doctor->user);
        $this->getJson('/api/doctor/patients')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $this->patient->id)
            ->assertJsonStructure(['data' => ['current_page', 'data', 'last_page', 'per_page', 'total']])
            ->assertJsonMissingPath('data.first_page_url')
            ->assertJsonMissingPath('data.last_page_url')
            ->assertJsonMissingPath('data.links')
            ->assertJsonMissingPath('data.next_page_url')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.prev_page_url');
    }

    public function test_patient_cannot_access_doctor_patient_list(): void
    {
        Sanctum::actingAs($this->patient->user);
        $this->getJson('/api/doctor/patients')->assertStatus(403);
    }

    public function test_service_list_shows_all_services_but_doctors_can_only_edit_their_own(): void
    {
        $otherDoctor = $this->makeDoctor('other-service-owner@gmail.com');
        $otherService = $this->makeService($otherDoctor);

        Sanctum::actingAs($this->doctor->user);
        $ownService = $this->postJson('/api/services', [
            'name' => 'My Consultation',
            'price' => 650,
            'duration_minutes' => 30,
        ])->assertCreated()->json('data.id');

        $doctorServices = $this->getJson('/api/services')
            ->assertOk()
            ->json('data');
        $this->assertEqualsCanonicalizing(
            [$this->service->id, $otherService->id, $ownService],
            array_column($doctorServices, 'id')
        );

        $this->getJson("/api/services/{$otherService->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $otherService->id);
        $this->putJson("/api/services/{$otherService->id}", ['name' => 'Changed by another doctor'])
            ->assertForbidden();

        $this->putJson("/api/services/{$ownService}", ['name' => 'Updated Consultation'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Consultation');

        Sanctum::actingAs($this->patient->user);
        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_availability_excludes_booked_slot(): void
    {
        $this->book($this->patient, '09:00')->assertCreated();

        Sanctum::actingAs($this->patient2->user);
        $slots = $this->getJson("/api/doctors/{$this->doctor->id}/availability?date={$this->date}")
            ->assertOk()->json('data.available_slots');

        $this->assertNotContains('09:00', array_column($slots, 'start_time'));
        $this->assertContains('09:30', array_column($slots, 'start_time'));
    }
}
