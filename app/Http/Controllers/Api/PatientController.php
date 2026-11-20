<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PatientController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $request->user()->patient]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $patient = $user->patient()->firstOrFail();

        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::in([$user->id])],
            'name' => 'sometimes|string|max:255',
            'phone' => ['sometimes', 'string', 'max:20', 'regex:/^[0-9]+$/', Rule::unique('patients', 'phone')->ignore($patient->id)],
            'date_of_birth' => 'sometimes|nullable|date_format:Y-m-d|before:today',
            'gender' => 'sometimes|nullable|in:male,female,other',
            'address' => 'sometimes|nullable|string|max:255',
        ]);

        unset($data['user_id']);

        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
            unset($data['name']);
        }

        $patient->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $patient->load('user'),
        ]);
    }
}
