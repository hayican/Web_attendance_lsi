<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mapping_key',
    'mapping_value',
    'description',
    'updated_by',
])]
class TalentaMapping extends Model
{
    public const array BOOLEAN_KEYS = [
        'use_offsite_schedule_for_talenta',
        'export_offday_overtime_to_talenta',
    ];

    /**
     * @var list<array{mapping_key: string, mapping_value: string, description: string}>
     */
    public const array DEFAULT_MAPPINGS = [
        ['mapping_key' => 'attendance_present', 'mapping_value' => 'H', 'description' => 'Attendance Code Hadir (File 1 Talenta)'],
        ['mapping_key' => 'attendance_absent', 'mapping_value' => 'A', 'description' => 'Attendance Code Alpa (File 1 Talenta)'],
        ['mapping_key' => 'timeoff_annual', 'mapping_value' => 'CT', 'description' => 'Time Off Code Cuti Tahunan'],
        ['mapping_key' => 'timeoff_collective', 'mapping_value' => 'CB', 'description' => 'Time Off Code Cuti Bersama (>= 1 tahun)'],
        ['mapping_key' => 'timeoff_collective_unpaid', 'mapping_value' => 'CBU', 'description' => 'Time Off Code Cuti Bersama Unpaid (< 1 tahun)'],
        ['mapping_key' => 'timeoff_izin', 'mapping_value' => 'IZ', 'description' => 'Time Off Code Izin'],
        ['mapping_key' => 'timeoff_sakit', 'mapping_value' => 'SK', 'description' => 'Time Off Code Sakit'],
        ['mapping_key' => 'use_offsite_schedule_for_talenta', 'mapping_value' => 'true', 'description' => 'Isi Schedule In/Out File 1 memakai jam dinas saat DINAS_LUAR'],
        ['mapping_key' => 'export_offday_overtime_to_talenta', 'mapping_value' => 'false', 'description' => 'Ekspor lembur hari OFF/libur disetujui HR ke Overtime Check In/Out File 1'],
    ];

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULT_MAPPINGS as $item) {
            self::query()->firstOrCreate(
                ['mapping_key' => $item['mapping_key']],
                ['mapping_value' => $item['mapping_value'], 'description' => $item['description']]
            );
        }
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
