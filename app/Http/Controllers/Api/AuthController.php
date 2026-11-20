<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        if ($request->filled('email') && User::where('email', $request->input('email'))->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This user has already registered.',
            ], 409);
        }

        $role = $request->input('role');

        $phoneRules = ['required', 'string', 'max:20', 'regex:/^[0-9]+$/'];
        if ($role === User::ROLE_PATIENT) {
            $phoneRules[] = Rule::unique('patients', 'phone');
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email:rfc', 'regex:/@gmail\.com$/i', 'max:255', 'unique:users,email'],
            'password' => 'required|string|min:6',
            'role' => 'required|in:doctor,patient',
            'phone' => $phoneRules,
            // doctor only
            'specialization' => 'required_if:role,doctor|nullable|string|max:255',
            'bio' => 'nullable|string',
            // patient only
            'date_of_birth' => 'nullable|date_format:Y-m-d|before:today',
            'gender' => 'nullable|in:male,female,other',
            'address' => 'nullable|string|max:255',
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'role' => $data['role'],
            ]);

            if ($data['role'] === User::ROLE_DOCTOR) {
                Doctor::create([
                    'user_id' => $user->id,
                    'phone' => $data['phone'],
                    'specialization' => $data['specialization'],
                    'bio' => $data['bio'] ?? null,
                ]);
            } else {
                Patient::create([
                    'user_id' => $user->id,
                    'phone' => $data['phone'],
                    'date_of_birth' => $data['date_of_birth'] ?? null,
                    'gender' => $data['gender'] ?? null,
                    'address' => $data['address'] ?? null,
                ]);
            }

            return $user;
        });

        return response()->json([
            'success' => true,
            'message' => 'Registered successfully.',
            'data' => $this->payload($user, true),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'regex:/@gmail\.com$/i'],
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        return response()->json([
            'message' => 'Login successful.',
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['success' => true, 'message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload($request->user())]);
    }

    private function payload(User $user, bool $withToken = false): array
    {
        $profile = $user->isDoctor() ? $user->doctor : $user->patient;
        $profile?->unsetRelation('user');

        $payload = [
            'user' => $user->only(['id', 'name', 'email', 'role']),
            'profile' => $profile,
        ];

        if ($withToken) {
            $payload['token'] = $user->createToken('api')->plainTextToken;
            $payload['token_type'] = 'Bearer';
        }

        return $payload;
    }
}
