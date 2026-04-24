<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Letter extends Model
{
    use HasFactory;

    /**
     * Generate the next reference number in the format 0001, 0002, etc.
     */
    public static function generateNextRefNumber(): string
    {
        $latestLetter = self::orderBy('id', 'desc')->first();

        if (!$latestLetter || !$latestLetter->ref_number) {
            return '0001';
        }

        $latestRefNumber = $latestLetter->ref_number;
        $numericPart = (int) $latestRefNumber;
        $nextNumber = $numericPart + 1;

        return str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    protected $fillable = [
        'ref_number',
        'title',
        'employee_name',
        'address_line1',
        'address_line2',
        'city',
        'designation_id',
    ];

    // Relationships
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('employee_name', 'like', "%{$search}%")->orWhere('employee_name', 'LIKE', "%{$search}%");
    }
}
