<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_can_register_and_login(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test Patient',
            'email' => 'tp@gmail.com',
            'password' => 'secret123',
            'role' => 'patient',
            'phone' => '9876543210',
            'gender' => 'female',
        ])->assertCreated()
            ->assertJsonPath('data.user.role', 'patient')
            ->assertJsonStructure(['data' => ['token', 'user', 'profile']]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'tp@gmail.com',
            'password' => 'secret123',
        ])->assertOk();

        $loginResponse->assertExactJson([
            'message' => 'Login successful.',
            'token' => $loginResponse->json('token'),
        ]);

        $this->withToken($loginResponse->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_registering_an_existing_email_returns_already_registered_message(): void
    {
        $payload = [
            'name' => 'Test Patient',
            'email' => 'existing-patient@gmail.com',
            'password' => 'secret123',
            'role' => 'patient',
            'phone' => '9000000015',
        ];

        $this->postJson('/api/auth/register', $payload)->assertCreated();

        $this->postJson('/api/auth/register', array_merge($payload, [
            'name' => 'Duplicate Patient',
            'phone' => '9000000016',
        ]))
            ->assertStatus(409)
            ->assertExactJson([
                'success' => false,
                'message' => 'This user has already registered.',
            ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('patients', 1);
    }

    public function test_doctor_registration_requires_specialization(): void
    {
        $payload = [
            'name' => 'Dr Test',
            'email' => 'dt@gmail.com',
            'password' => 'secret123',
            'role' => 'doctor',
            'phone' => '9000000099',
        ];

        $this->postJson('/api/auth/register', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('specialization');

        $this->postJson('/api/auth/register', $payload + ['specialization' => 'ENT'])
            ->assertCreated()->assertJsonPath('data.user.role', 'doctor');
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'P', 'email' => 'p@gmail.com', 'password' => 'secret123', 'role' => 'patient', 'phone' => '9000000010',
        ])->assertCreated();

        $this->postJson('/api/auth/login', ['email' => 'p@gmail.com', 'password' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_me_requires_token(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);

        $token = $this->postJson('/api/auth/register', [
            'name' => 'P', 'email' => 'p2@gmail.com', 'password' => 'secret123', 'role' => 'patient', 'phone' => '9000000011',
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('data.user.email', 'p2@gmail.com');
    }

    public function test_logged_in_patient_can_view_their_profile(): void
    {
        $registration = $this->postJson('/api/auth/register', [
            'name' => 'Profile Patient',
            'email' => 'profile-patient@gmail.com',
            'password' => 'secret123',
            'role' => 'patient',
            'phone' => '9000000012',
        ])->assertCreated();

        $this->withToken($registration->json('data.token'))
            ->getJson('/api/patient/profile')
            ->assertOk()
            ->assertJsonPath('data.user_id', $registration->json('data.user.id'));
    }

    public function test_patient_profile_update_requires_matching_user_id_and_updates_existing_profile(): void
    {
        $firstRegistration = $this->postJson('/api/auth/register', [
            'name' => 'First Patient',
            'email' => 'first-profile@gmail.com',
            'password' => 'secret123',
            'role' => 'patient',
            'phone' => '9000000013',
        ])->assertCreated();
        $secondRegistration = $this->postJson('/api/auth/register', [
            'name' => 'Second Patient',
            'email' => 'second-profile@gmail.com',
            'password' => 'secret123',
            'role' => 'patient',
            'phone' => '9000000014',
        ])->assertCreated();

        $token = $firstRegistration->json('data.token');
        $firstUserId = $firstRegistration->json('data.user.id');
        $firstPatientId = $firstRegistration->json('data.profile.id');
        $secondUserId = $secondRegistration->json('data.user.id');
        $secondPatientId = $secondRegistration->json('data.profile.id');

        $this->withToken($token)
            ->putJson('/api/patient/profile', ['address' => 'Chennai'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->withToken($token)
            ->putJson('/api/patient/profile', [
                'user_id' => $secondUserId,
                'address' => 'Chennai',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->withToken($token)
            ->putJson('/api/patient/profile', [
                'user_id' => $firstUserId,
                'address' => 'Chennai',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully.')
            ->assertJsonPath('data.id', $firstPatientId)
            ->assertJsonPath('data.user_id', $firstUserId)
            ->assertJsonPath('data.address', 'Chennai');

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('patients', 2);
        $this->assertDatabaseHas('patients', [
            'id' => $secondPatientId,
            'address' => null,
        ]);
    }

    public function test_unauthenticated_api_requests_return_json_without_an_accept_header(): void
    {
        $this->withHeader('Accept', '*/*')
            ->get('/api/auth/me')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_registration_requires_gmail_address_numeric_phone_and_role(): void
    {
        $payload = [
            'name' => 'Test Patient',
            'email' => 'patient@example.com',
            'password' => 'secret123',
            'phone' => '98765abc',
        ];

        $this->postJson('/api/auth/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone', 'role']);
    }

    public function test_login_requires_gmail_address(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'patient@example.com',
            'password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_patient_profile_phone_updates_must_contain_only_digits(): void
    {
        $registration = $this->postJson('/api/auth/register', [
            'name' => 'Test Patient',
            'email' => 'patient-phone@gmail.com',
            'password' => 'secret123',
            'role' => 'patient',
            'phone' => '9876543210',
        ])->assertCreated();

        $this->withToken($registration->json('data.token'))
            ->putJson('/api/patient/profile', [
                'user_id' => $registration->json('data.user.id'),
                'phone' => '98765abc',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_doctor_profile_phone_updates_must_contain_only_digits(): void
    {
        $doctorToken = $this->postJson('/api/auth/register', [
            'name' => 'Test Doctor',
            'email' => 'doctor-phone@gmail.com',
            'password' => 'secret123',
            'role' => 'doctor',
            'phone' => '9876543211',
            'specialization' => 'Cardiology',
        ])->assertCreated()->json('data.token');

        $this->withToken($doctorToken)
            ->putJson('/api/doctor/profile', ['phone' => '98765abc'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }
}
