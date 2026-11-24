<?php

namespace Database\Seeders;

use App\Enums\LocationStatus;
use App\Enums\LocationType;
use App\Enums\UserRole;
use App\Models\ActivityCategory;
use App\Models\LeaveBalance;
use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftPattern;
use App\Models\TalentaMapping;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $jakarta = Location::query()->firstOrCreate(
            ['code' => 'JKT'],
            [
                'name' => 'PT. LSI Cabang Jakarta',
                'type' => LocationType::OFFICE,
                'address' => 'Jakarta, Indonesia',
                'latitude' => -6.2000000,
                'longitude' => 106.8166667,
                'radius_m' => 100,
                'timezone' => 'Asia/Jakarta',
                'work_days' => [1, 2, 3, 4, 5],
                'status' => LocationStatus::ACTIVE,
            ]
        );

        $surabayaSier = Location::query()->firstOrCreate(
            ['code' => 'SBY-SIER'],
            [
                'name' => 'PT. LSI Cabang Surabaya SIER',
                'type' => LocationType::OFFICE,
                'address' => 'Kawasan Industri SIER, Surabaya',
                'latitude' => -7.3266000,
                'longitude' => 112.7610000,
                'radius_m' => 100,
                'timezone' => 'Asia/Jakarta',
                'work_days' => [1, 2, 3, 4, 5, 6],
                'status' => LocationStatus::ACTIVE,
            ]
        );

        Location::query()->firstOrCreate(
            ['code' => 'SBY-KTG'],
            [
                'name' => 'PT. LSI Cabang Surabaya Ketintang',
                'type' => LocationType::OFFICE,
                'address' => 'Ketintang, Surabaya',
                'latitude' => -7.3118000,
                'longitude' => 112.7289000,
                'radius_m' => 100,
                'timezone' => 'Asia/Jakarta',
                'work_days' => [1, 2, 3, 4, 5, 6],
                'status' => LocationStatus::ACTIVE,
            ]
        );

        Location::query()->firstOrCreate(
            ['code' => 'PSR'],
            [
                'name' => 'PT. LSI Cabang Pasuruan',
                'type' => LocationType::OFFICE,
                'address' => 'Pasuruan, Jawa Timur',
                'latitude' => -7.6453000,
                'longitude' => 112.9075000,
                'radius_m' => 100,
                'timezone' => 'Asia/Jakarta',
                'work_days' => [1, 2, 3, 4, 5, 6],
                'status' => LocationStatus::ACTIVE,
            ]
        );

        Location::query()->firstOrCreate(
            ['code' => 'BLI'],
            [
                'name' => 'PT. LSI Cabang Bali',
                'type' => LocationType::OFFICE,
                'address' => 'Denpasar, Bali',
                'latitude' => -8.6705000,
                'longitude' => 115.2126000,
                'radius_m' => 100,
                'timezone' => 'Asia/Makassar',
                'work_days' => [1, 2, 3, 4, 5, 6],
                'status' => LocationStatus::ACTIVE,
            ]
        );

        $shiftsData = [
            ['code' => 'JWD', 'name' => 'Jakarta Weekday', 'start_time' => '08:00:00', 'end_time' => '16:00:00'],
            ['code' => 'SWD', 'name' => 'Surabaya Weekday', 'start_time' => '08:00:00', 'end_time' => '16:00:00'],
            ['code' => 'SWE', 'name' => 'Surabaya Sabtu', 'start_time' => '08:00:00', 'end_time' => '13:00:00'],
            ['code' => 'BWD', 'name' => 'Bali Weekday', 'start_time' => '08:00:00', 'end_time' => '16:00:00'],
            ['code' => 'BWE', 'name' => 'Bali Sabtu', 'start_time' => '08:00:00', 'end_time' => '13:00:00'],
            ['code' => 'PWD', 'name' => 'Pasuruan Weekday', 'start_time' => '08:00:00', 'end_time' => '16:00:00'],
            ['code' => 'PWE', 'name' => 'Pasuruan Sabtu', 'start_time' => '08:00:00', 'end_time' => '13:00:00'],
            ['code' => 'MKT', 'name' => 'Sales Lapangan', 'start_time' => '08:00:00', 'end_time' => '17:00:00'],
        ];

        $shifts = [];
        foreach ($shiftsData as $row) {
            $shifts[$row['code']] = Shift::query()->firstOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'grace_period_minutes' => 10,
                    'auto_close_hours' => 5,
                    'is_active' => true,
                ]
            );
        }

        $jktPattern = ShiftPattern::query()->firstOrCreate(
            ['name' => 'Office Jakarta 5 Hari'],
            [
                'mon_shift_id' => $shifts['JWD']->id,
                'tue_shift_id' => $shifts['JWD']->id,
                'wed_shift_id' => $shifts['JWD']->id,
                'thu_shift_id' => $shifts['JWD']->id,
                'fri_shift_id' => $shifts['JWD']->id,
                'sat_shift_id' => null,
                'sun_shift_id' => null,
                'is_active' => true,
            ]
        );

        $sbyPattern = ShiftPattern::query()->firstOrCreate(
            ['name' => 'Office Surabaya 6 Hari'],
            [
                'mon_shift_id' => $shifts['SWD']->id,
                'tue_shift_id' => $shifts['SWD']->id,
                'wed_shift_id' => $shifts['SWD']->id,
                'thu_shift_id' => $shifts['SWD']->id,
                'fri_shift_id' => $shifts['SWD']->id,
                'sat_shift_id' => $shifts['SWE']->id,
                'sun_shift_id' => null,
                'is_active' => true,
            ]
        );

        ShiftPattern::query()->firstOrCreate(
            ['name' => 'Office Bali 6 Hari'],
            [
                'mon_shift_id' => $shifts['BWD']->id,
                'tue_shift_id' => $shifts['BWD']->id,
                'wed_shift_id' => $shifts['BWD']->id,
                'thu_shift_id' => $shifts['BWD']->id,
                'fri_shift_id' => $shifts['BWD']->id,
                'sat_shift_id' => $shifts['BWE']->id,
                'sun_shift_id' => null,
                'is_active' => true,
            ]
        );

        ShiftPattern::query()->firstOrCreate(
            ['name' => 'Office Pasuruan 6 Hari'],
            [
                'mon_shift_id' => $shifts['PWD']->id,
                'tue_shift_id' => $shifts['PWD']->id,
                'wed_shift_id' => $shifts['PWD']->id,
                'thu_shift_id' => $shifts['PWD']->id,
                'fri_shift_id' => $shifts['PWD']->id,
                'sat_shift_id' => $shifts['PWE']->id,
                'sun_shift_id' => null,
                'is_active' => true,
            ]
        );

        foreach ([
            ['name' => 'Cek Stok', 'exempt_short_duration' => false],
            ['name' => 'Order Barang', 'exempt_short_duration' => false],
            ['name' => 'Pajang Produk', 'exempt_short_duration' => false],
            ['name' => 'Toko Tutup', 'exempt_short_duration' => true],
        ] as $cat) {
            ActivityCategory::query()->firstOrCreate(
                ['name' => $cat['name']],
                ['exempt_short_duration' => $cat['exempt_short_duration'], 'is_active' => true]
            );
        }

        TalentaMapping::ensureDefaults();

        $defaultPassword = Hash::make('password');

        $users = [
            [
                'nik' => 'HR001',
                'email' => 'hr1@lsi.co.id',
                'name' => 'HR Admin Jakarta',
                'role' => UserRole::HR_ADMIN,
                'office_location_id' => $jakarta->id,
                'default_shift_pattern_id' => $jktPattern->id,
            ],
            [
                'nik' => 'HR002',
                'email' => 'hr2@lsi.co.id',
                'name' => 'HR Admin Surabaya',
                'role' => UserRole::HR_ADMIN,
                'office_location_id' => $surabayaSier->id,
                'default_shift_pattern_id' => $sbyPattern->id,
            ],
            [
                'nik' => 'OFF001',
                'email' => 'office@lsi.co.id',
                'name' => 'Staf Kantor Jakarta',
                'role' => UserRole::EMPLOYEE_OFFICE,
                'office_location_id' => $jakarta->id,
                'default_shift_pattern_id' => $jktPattern->id,
            ],
            [
                'nik' => 'SLS001',
                'email' => 'sales@lsi.co.id',
                'name' => 'Sales Lapangan Surabaya',
                'role' => UserRole::EMPLOYEE_SALES,
                'office_location_id' => $surabayaSier->id,
                'default_shift_pattern_id' => null,
            ],
        ];

        $currentYear = (int) now()->year;

        foreach ($users as $userData) {
            $user = User::query()->firstOrCreate(
                ['nik' => $userData['nik']],
                [
                    'email' => $userData['email'],
                    'name' => $userData['name'],
                    'password' => $defaultPassword,
                    'must_change_password' => false,
                    'role' => $userData['role'],
                    'office_location_id' => $userData['office_location_id'],
                    'default_shift_pattern_id' => $userData['default_shift_pattern_id'],
                    'join_date' => now()->subYears(2)->toDateString(),
                    'consent_accepted_at' => now(),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            LeaveBalance::query()->firstOrCreate(
                ['user_id' => $user->id, 'year' => $currentYear],
                [
                    'base_quota' => 12.0,
                    'prorated_deduction' => 0.0,
                    'collective_leave_deduction' => 0.0,
                    'used_days' => 0.0,
                    'pending_days' => 0.0,
                    'adjustment_days' => 0.0,
                ]
            );
        }
    }
}
