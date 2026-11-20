# Healthcare Appointment Management – Laravel REST API

Laravel REST API with role based access (doctor / patient), services, doctor working hours and appointment booking with conflict handling. Authentication uses Laravel Sanctum (Bearer token).

## Requirements
PHP 8.1+, Composer, MySQL 8 (or MariaDB 10.6+). Built and tested on Laravel 10 (PHP 8.1).

## Setup
```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set DB in `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=healthcare_api
DB_USERNAME=root
DB_PASSWORD=
```

```bash
# create the database first: CREATE DATABASE healthcare_api;
php artisan migrate:fresh --seed
php artisan serve
```
Base URL: `http://127.0.0.1:8000/api`. Always send `Accept: application/json`.

Run tests (uses SQLite in memory, enable it in `phpunit.xml`): `php artisan test`

### Seeded logins (password: `password`)
| Role | Email |
|---|---|
| doctor | arun@gmail.com |
| doctor | meena@gmail.com |
| patient | patient1@gmail.com |
| patient | patient2@gmail.com |

## Database schema
| Table | Purpose |
|---|---|
| users | name, email, password, **role** (doctor / patient) |
| doctors | user_id, phone, specialization, bio, is_active |
| patients | user_id, phone, date_of_birth, gender, address |
| services | doctor_id, name, price, duration_minutes, is_active |
| doctor_schedules | doctor_id, day_of_week (0=Sun), start_time, end_time |
| appointments | patient_id, doctor_id, service_id, appointment_date, start_time, end_time, status (booked/completed/cancelled), notes, cancel_reason, cancelled_at |

## Roles
| Action | Patient | Doctor |
|---|---|---|
| Register / login | yes | yes |
| List doctors, services, availability | yes | yes |
| Book / reschedule appointment | yes (own) | no |
| View appointments | own | own (patients booked with them) |
| Cancel appointment | yes (own) | yes (own) |
| Complete appointment | no | yes (own) |
| Patient list (own patients) | no | yes |
| Manage services and working hours | no | yes (own) |

## Business rules
1. `service_id` is required and must be an active service offered by the selected doctor.
2. Appointment must be in the future and fully inside the doctor's working hours for that weekday.
3. Doctor cannot have two overlapping `booked` appointments (409). Patient cannot either (409).
4. Overlap rule: `existing.start < new.end AND existing.end > new.start`. Back-to-back slots are allowed.
5. End time = start + `duration_minutes` (request) or the service duration (default 30).
6. Cancelled appointments free the slot. Only `booked` appointments can be rescheduled / cancelled / completed.
7. Booking runs in a DB transaction with a row lock on the doctor to avoid race-condition double booking.
8. `patient_id` is taken from the logged-in user, never from the request body.

## Endpoints
**Auth**
| Method | URL | Description |
|---|---|---|
| POST | /api/auth/register | register (role: doctor / patient) |
| POST | /api/auth/login | login, returns token |
| POST | /api/auth/logout | logout (token required) |
| GET | /api/auth/me | current user + profile |

Registering an email already in use returns `409` with `This user has already registered.` and does not create another account.

**Public**
| Method | URL | Description |
|---|---|---|
| GET | /api/doctors?specialization=&search= | all active doctors (single response; optional filters) |
| GET | /api/doctors/{id} | active doctor details |
| GET | /api/services?doctor_id= | all active services (single response; optional doctor filter) |
| GET | /api/services/{id} | active service details |

For doctor details, use an `id` returned by `GET /api/doctors`; database IDs can change after reseeding.

**Any logged-in user** (header `Authorization: Bearer <token>`)
| Method | URL |
|---|---|
| GET | /api/doctors/{id}/availability?date=YYYY-MM-DD&slot_minutes=30 |
| GET | /api/appointments?status=&date= |
| GET | /api/appointments/{id} |
| PATCH | /api/appointments/{id}/cancel |

**Patient only**
| Method | URL |
|---|---|
| GET / PUT | /api/patient/profile |
| POST | /api/appointments |
| PUT | /api/appointments/{id} |

Patient profile updates require `user_id` matching the authenticated user's ID; retrieve it from `GET /api/patient/profile`. The API still scopes the update to the logged-in patient.

**Doctor only**
| Method | URL |
|---|---|
| GET / PUT | /api/doctor/profile |
| PUT | /api/doctor/schedules |
| GET | /api/doctor/patients?status= |
| POST | /api/services |
| PUT / DELETE | /api/services/{id} |
| PATCH | /api/appointments/{id}/complete |

### Register examples
```json
POST /api/auth/register
{ "name": "Ravi", "email": "ravi@test.com", "password": "secret123", "role": "patient", "phone": "9876543210", "gender": "male" }
```
```json
POST /api/auth/register
{ "name": "Dr. Sam", "email": "sam@test.com", "password": "secret123", "role": "doctor", "phone": "9000000050", "specialization": "ENT" }
```

### Book appointment (patient token)
```json
POST /api/appointments
{ "doctor_id": 1, "service_id": 1, "appointment_date": "2026-11-23", "start_time": "10:00", "notes": "First visit" }
```
Responses: `201` booked, `401` no token, `403` wrong role / not your appointment, `409` slot conflict, `422` validation / outside working hours / wrong service.

Postman collection: `postman_collection.json` (login requests save the token automatically).

## Incomplete / future work
- No email verification or password reset.
- No email/SMS reminders.
- No holidays / leave handling for doctors.
- No admin role.
