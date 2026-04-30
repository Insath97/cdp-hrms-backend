<?php
// database/seeders/PayrollSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\PayrollRecord;

class PayrollSeeder extends Seeder
{
    public function run()
    {
        $users = User::take(5)->get();
        
        $months = ['January 2026', 'February 2026', 'March 2026'];
        
        foreach ($users as $user) {
            foreach ($months as $index => $month) {
                $basic = 85000;
                $allowances = 15000;
                $deductions = $index === 0 ? 5000 : 0;
                $gross = $basic + $allowances;
                $epfEmployee = $gross * 0.08;
                $epfEmployer = $gross * 0.12;
                $etfEmployer = $gross * 0.03;
                $net = $gross - $epfEmployee - $deductions;
                
                PayrollRecord::create([
                    'user_id' => $user->id,
                    'month' => $month,
                    'basic' => $basic,
                    'allowances' => $allowances,
                    'deductions' => $deductions,
                    'net' => $net,
                    'epf_employee' => $epfEmployee,
                    'epf_employer' => $epfEmployer,
                    'etf_employer' => $etfEmployer,
                    'status' => $index === 2 ? 'pending' : 'processed',
                    'processed_at' => $index !== 2 ? now() : null,
                ]);
            }
        }
    }
}