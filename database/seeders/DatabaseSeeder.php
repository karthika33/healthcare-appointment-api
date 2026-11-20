<?php

namespace Database\Seeders;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** All seeded users have password: password */
    public function run(): void
    {
        $doctors = [
            ['name' => 'Dr. Arun Kumar', 'email' => 'arun@gmail.com', 'phone' => '9000000001', 'specialization' => 'Cardiology'],
            ['name' => 'Dr. Meena Ravi', 'email' => 'meena@gmail.com', 'phone' => '9000000002', 'specialization' => 'Dermatology'],
        ];

        foreach ($doctors as $row) {
            $user = User::updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => Hash::make('password'),
                    'role' => User::ROLE_DOCTOR,
                ]
            );

            $doctor = Doctor::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'phone' => $row['phone'],
                    'specialization' => $row['specialization'],
                ]
            );

            // Monday to Saturday, 09:00 - 17:00
            foreach (range(1, 6) as $day) {
                $doctor->schedules()->updateOrCreate(
                    ['day_of_week' => $day],
                    [
                        'start_time' => '09:00:00',
                        'end_time' => '17:00:00',
                    ]
                );
            }

            Service::updateOrCreate(
                ['doctor_id' => $doctor->id, 'name' => $row['specialization'] . ' Consultation'],
                [
                    'price' => 500,
                    'duration_minutes' => 30,
                ]
            );

            Service::updateOrCreate(
                ['doctor_id' => $doctor->id, 'name' => 'Follow-up Visit'],
                [
                    'price' => 300,
                    'duration_minutes' => 20,
                ]
            );
        }

        $patients = [
            ['name' => 'Karthika A', 'email' => 'patient1@gmail.com', 'phone' => '9111111111', 'gender' => 'female'],
            ['name' => 'Ravi S', 'email' => 'patient2@gmail.com', 'phone' => '9222222222', 'gender' => 'male'],
        ];

        foreach ($patients as $row) {
            $user = User::updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => Hash::make('password'),
                    'role' => User::ROLE_PATIENT,
                ]
            );

            Patient::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'phone' => $row['phone'],
                    'gender' => $row['gender'],
                ]
            );
        }
    }
}
