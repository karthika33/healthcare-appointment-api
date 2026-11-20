<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_can_be_run_more_than_once(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(4, User::count());
        $this->assertSame(2, Doctor::count());
        $this->assertSame(2, Patient::count());
        $this->assertSame(4, Service::count());
        $this->assertDatabaseCount('doctor_schedules', 12);
    }

    public function test_seeded_user_can_log_in(): void
    {
        $this->seed();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'patient1@gmail.com',
            'password' => 'password',
        ])->assertOk();

        $response->assertExactJson([
            'message' => 'Login successful.',
            'token' => $response->json('token'),
        ]);
    }

    public function test_doctor_list_is_public_and_returns_all_active_doctors_in_one_response(): void
    {
        $this->seed();

        $inactiveUser = User::create([
            'name' => 'Inactive Doctor',
            'email' => 'inactive.doctor@gmail.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_DOCTOR,
        ]);
        Doctor::create([
            'user_id' => $inactiveUser->id,
            'specialization' => 'General',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/doctors')->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonMissingPath('data.data');

        $this->assertEqualsCanonicalizing(
            Doctor::where('is_active', true)->pluck('id')->all(),
            array_column($response->json('data'), 'id')
        );
    }

    public function test_active_doctor_details_are_public(): void
    {
        $this->seed();
        $doctor = Doctor::with('user')->firstOrFail();

        $this->getJson("/api/doctors/{$doctor->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $doctor->id)
            ->assertJsonPath('data.user.name', $doctor->user->name);
    }

    public function test_inactive_doctor_details_are_not_public(): void
    {
        $user = User::create([
            'name' => 'Inactive Doctor',
            'email' => 'inactive.details@gmail.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_DOCTOR,
        ]);
        $doctor = Doctor::create([
            'user_id' => $user->id,
            'specialization' => 'General',
            'is_active' => false,
        ]);

        $this->getJson("/api/doctors/{$doctor->id}")->assertNotFound();
    }

    public function test_service_list_is_public_and_returns_all_active_services_in_one_response(): void
    {
        $this->seed();

        $response = $this->getJson('/api/services')->assertOk();
        $response->assertJsonCount(4, 'data');
        $response->assertJsonMissingPath('data.data');

        $this->assertEqualsCanonicalizing(
            Service::where('is_active', true)->pluck('id')->all(),
            array_column($response->json('data'), 'id')
        );
    }

    public function test_active_service_details_are_public(): void
    {
        $this->seed();
        $service = Service::firstOrFail();

        $this->getJson("/api/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $service->id);
    }

    public function test_inactive_service_details_are_not_public(): void
    {
        $this->seed();
        $doctor = Doctor::firstOrFail();
        $service = Service::create([
            'doctor_id' => $doctor->id,
            'name' => 'Inactive service',
            'price' => 100,
            'duration_minutes' => 30,
            'is_active' => false,
        ]);

        $this->getJson("/api/services/{$service->id}")->assertNotFound();
    }
}
