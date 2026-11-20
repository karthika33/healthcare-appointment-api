<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_id' => ['sometimes', 'integer', 'exists:services,id'],
            'appointment_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'duration_minutes' => ['sometimes', 'integer', 'min:10', 'max:240'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
