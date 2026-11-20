<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public const STATUS_BOOKED = 'booked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'patient_id', 'doctor_id', 'service_id', 'appointment_date',
        'start_time', 'end_time', 'status', 'notes', 'cancel_reason', 'cancelled_at',
    ];

    protected $casts = [
        'appointment_date' => 'date:Y-m-d',
        'cancelled_at' => 'datetime',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Active (booked) appointments overlapping [$start, $end) on $date.
     * Overlap rule: existing.start < new.end AND existing.end > new.start
     * (so back-to-back slots like 10:00-10:30 and 10:30-11:00 are allowed).
     */
    public function scopeOverlapping(Builder $query, string $column, int $id, string $date, string $start, string $end): Builder
    {
        return $query->where($column, $id)
            ->whereDate('appointment_date', $date)
            ->where('status', self::STATUS_BOOKED)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start);
    }
}
