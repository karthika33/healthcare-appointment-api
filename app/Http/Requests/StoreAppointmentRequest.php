<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doctor_id' => ['required', 'integer', 'exists:doctors,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'appointment_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:10', 'max:240'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
