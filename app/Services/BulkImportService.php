<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Models\Country;
use App\Models\Province;
use App\Models\Zonal;
use App\Models\Region;
use App\Models\Branch;
use App\Models\Designation;
use App\Models\Department;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Carbon\Carbon;

class BulkImportService
{
    /**
     * Get configuration for all importable tables.
     */
    public function getImportableConfig(): array
    {
        return [
            'countries' => [
                'model' => Country::class,
                'unique_key' => 'code',
                'fillable' => ['name', 'code', 'is_active'],
            ],
            'provinces' => [
                'model' => Province::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'country_code' => ['model' => Country::class, 'field' => 'code', 'foreign_key' => 'country_id']
                ],
                'fillable' => ['name', 'code', 'is_active', 'country_id'],
            ],
            'zonals' => [
                'model' => Zonal::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id']
                ],
                'fillable' => ['name', 'code', 'province_id', 'is_active'],
            ],
            'regions' => [
                'model' => Region::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'zonal_code' => ['model' => Zonal::class, 'field' => 'code', 'foreign_key' => 'zonal_id']
                ],
                'fillable' => ['name', 'code', 'zonal_id', 'is_active'],
            ],
            'branches' => [
                'model' => Branch::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id'],
                    'zonal_code' => ['model' => Zonal::class, 'field' => 'code', 'foreign_key' => 'zonal_id'],
                    'region_code' => ['model' => Region::class, 'field' => 'code', 'foreign_key' => 'region_id'],
                ],
                'fillable' => ['name', 'code', 'address_line1', 'address_line2', 'city', 'postal_code', 'zonal_id', 'region_id', 'province_id','phone_primary', 'phone_secondary', 'email', 'fax', 'opening_date', 'branch_type', 'latitude', 'longitude', 'is_active', 'is_head_office'],
            ],
            'departments' => [
                'model' => Department::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id'],
                    'zonal_code' => ['model' => Zonal::class, 'field' => 'code', 'foreign_key' => 'zonal_id'],
                    'region_code' => ['model' => Region::class, 'field' => 'code', 'foreign_key' => 'region_id'],
                ],
                'fillable' => ['name', 'code', 'address_line1', 'address_line2', 'city', 'postal_code', 'zonal_id', 'region_id', 'province_id','phone_primary', 'phone_secondary', 'email', 'fax', 'opening_date', 'branch_type', 'latitude', 'longitude', 'is_active', 'is_head_office'],
            ],
            'designations' => [
                'model' => Designation::class,
                'unique_key' => 'code',
                'dependencies' => [
                    'department_code' => ['model' => Department::class, 'field' => 'code', 'foreign_key' => 'department_id'],
                ],
                'fillable' => ['name', 'code', 'department_id', 'level', 'description', 'is_active'],
            ],
            'employees' => [
                'model' => Employee::class,
                'unique_key' => ['employee_code', 'id_number', 'email'], // Fixed: changed to array
                'dependencies' => [
                    'reporting_manager_username' => ['model' => Employee::class, 'field' => 'employee_code', 'foreign_key' => 'reporting_manager_id'],
                    'province_code' => ['model' => Province::class, 'field' => 'code', 'foreign_key' => 'province_id'],
                    'region_code' => ['model' => Region::class, 'field' => 'code', 'foreign_key' => 'region_id'],
                    'zonal_code' => ['model' => Zonal::class, 'field' => 'code', 'foreign_key' => 'zonal_id'],
                    'branch_code' => ['model' => Branch::class, 'field' => 'code', 'foreign_key' => 'branch_id'],
                    'department_code' => ['model' => Department::class, 'field' => 'code', 'foreign_key' => 'department_id'],
                    'designation_code' => ['model' => Designation::class, 'field' => 'code', 'foreign_key' => 'designation_id'],
                ],
                'fillable' => [
                    'f_name', 'l_name', 'full_name', 'name_with_initials', 'employee_code', 'profile_image', 
                    'reporting_manager_id', 'province_id', 'region_id', 'zonal_id', 'branch_id', 'department_id', 
                    'designation_id', 'employee_type', 'id_type', 'id_number', 'date_of_birth', 'email', 
                    'address_line1', 'city', 'state', 'country', 'postal_code', 'phone_primary', 'phone_secondary', 
                    'have_whatsapp', 'whatsapp_number', 'start_date', 'end_date', 'joined_at', 'left_at', 
                    'termination_reason', 'permanent_at', 'employment_status', 'basic_salary', 'bank_name', 
                    'bank_branch', 'account_number', 'description', 'is_active'
                ],
            ],
        ];
    }

    /**
     * Import data from CSV file for a specific table.
     */
    public function import(UploadedFile $file, string $table): array
    {
        $results = [
            'total' => 0,
            'imported' => 0,
            'failed' => 0,
            'errors' => []
        ];

        $config = $this->getImportableConfig()[$table] ?? null;
        if (!$config) {
            $results['errors'][] = ['row' => 0, 'error' => "Unsupported table: $table"];
            return $results;
        }

        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            $results['errors'][] = ['row' => 0, 'error' => "Cannot open file"];
            return $results;
        }

        // Read and clean headers
        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            $results['errors'][] = ['row' => 0, 'error' => 'Empty or invalid CSV file.'];
            return $results;
        }

        // Clean headers (remove BOM, trim whitespace)
        $headers = array_map(function($header) {
            $header = trim($header, "\xEF\xBB\xBF");
            return trim($header);
        }, $headers);

        $rowNumber = 1;
        while (($rowData = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $results['total']++;

            // Handle mismatched column counts
            if (count($headers) !== count($rowData)) {
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'error' => "Column count mismatch. Expected " . count($headers) . ", got " . count($rowData)
                ];
                continue;
            }

            $data = array_combine($headers, $rowData);
            if ($data === false) {
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'error' => "Failed to combine headers with data"
                ];
                continue;
            }

            // Clean all data (remove empty strings, trim)
            $data = $this->cleanData($data);
            
            // Preprocess data if it's employees
            if ($table === 'employees') {
                $data = $this->preprocessEmployeeData($data);
            }

            DB::beginTransaction();
            try {
                $this->processGenericRow($config, $data);
                DB::commit();
                $results['imported']++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $results['failed']++;
                $results['errors'][] = [
                    'row' => $rowNumber,
                    'error' => $e->getMessage()
                ];
                Log::error("Import failed for table $table at row $rowNumber: " . $e->getMessage());
            }
        }

        fclose($handle);
        return $results;
    }

    /**
     * Clean and sanitize data
     */
    protected function cleanData(array $data): array
    {
        $cleaned = [];
        foreach ($data as $key => $value) {
            if ($value === null || $value === '') {
                $cleaned[$key] = null;
            } else {
                $cleaned[$key] = trim($value);
            }
        }
        return $cleaned;
    }

    /**
     * Preprocess employee data (date format, boolean fields, etc.)
     */
    protected function preprocessEmployeeData(array $data): array
    {
        // Handle date fields
        $dateFields = ['date_of_birth', 'start_date', 'end_date', 'joined_at', 'left_at', 'permanent_at'];
        foreach ($dateFields as $dateField) {
            if (!empty($data[$dateField])) {
                $parsedDate = $this->parseDate($data[$dateField]);
                if ($parsedDate) {
                    $data[$dateField] = $parsedDate;
                } else {
                    $data[$dateField] = null;
                }
            }
        }

        // Handle have_whatsapp boolean field
        if (isset($data['have_whatsapp'])) {
            $value = strtolower(trim($data['have_whatsapp']));
            if (in_array($value, ['1', 'true', 'yes', 'y', 'on'])) {
                $data['have_whatsapp'] = true;
            } elseif (in_array($value, ['0', 'false', 'no', 'n', 'off', ''])) {
                $data['have_whatsapp'] = false;
            } else {
                $data['have_whatsapp'] = false;
            }
        } else {
            $data['have_whatsapp'] = false;
        }

        // Handle is_active boolean field
        if (isset($data['is_active'])) {
            $value = strtolower(trim($data['is_active']));
            if (in_array($value, ['1', 'true', 'yes', 'y', 'on'])) {
                $data['is_active'] = true;
            } elseif (in_array($value, ['0', 'false', 'no', 'n', 'off', ''])) {
                $data['is_active'] = false;
            } else {
                $data['is_active'] = true;
            }
        } else {
            $data['is_active'] = true;
        }

        // Handle phone numbers - clean formatting
        if (!empty($data['phone_primary'])) {
            $data['phone_primary'] = $this->cleanPhoneNumber($data['phone_primary']);
        }

        if (!empty($data['phone_secondary'])) {
            $data['phone_secondary'] = $this->cleanPhoneNumber($data['phone_secondary']);
        }

        if (!empty($data['whatsapp_number'])) {
            $data['whatsapp_number'] = $this->cleanPhoneNumber($data['whatsapp_number']);
        }

        // Handle id_number - remove spaces
        if (!empty($data['id_number'])) {
            $data['id_number'] = str_replace(' ', '', trim($data['id_number']));
        }

        // Set default country if missing
        if (empty($data['country'])) {
            $data['country'] = 'Sri Lanka';
        }

        return $data;
    }

    /**
     * Parse date from various formats to Y-m-d
     */
    protected function parseDate($date): ?string
    {
        if (empty($date)) return null;

        $date = trim($date);

        // Handle dd/mm/YYYY format
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];

            if (checkdate($month, $day, $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        // Handle dd-mm-YYYY format
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $date, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];

            if (checkdate($month, $day, $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        // Handle YYYY-mm-dd format (already correct)
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);

            if (checkdate($month, $day, $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        // Try Carbon as last resort
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Clean phone number
     */
    protected function cleanPhoneNumber($phone): ?string
    {
        if (empty($phone)) return null;

        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Remove leading zeros
        $phone = ltrim($phone, '0');

        // Add 94 if it's a 9-digit number (Sri Lankan mobile)
        if (strlen($phone) === 9 && preg_match('/^7[0-9]{8}$/', $phone)) {
            $phone = '94' . $phone;
        }

        return $phone;
    }

    /**
     * Generic row processing using config.
     */
    protected function processGenericRow(array $config, array $data): void
    {
        $modelClass = $config['model'];
        $uniqueKeyField = $config['unique_key'];

        // Resolve Dependencies
        if (isset($config['dependencies'])) {
            foreach ($config['dependencies'] as $csvCol => $dep) {
                if (!empty($data[$csvCol])) {
                    $resolved = $dep['model']::where($dep['field'], $data[$csvCol])->first();
                    if (!$resolved) {
                        throw new \Exception("Could not resolve {$csvCol} with value '{$data[$csvCol]}'");
                    }
                    $data[$dep['foreign_key']] = $resolved->id;
                }
                unset($data[$csvCol]);
            }
        }

        // Prepare allowed fields: fillable + dependencies + unique key
        $allowedFields = $config['fillable'];
        if (isset($config['dependencies'])) {
            foreach ($config['dependencies'] as $dep) {
                $allowedFields[] = $dep['foreign_key'];
            }
        }
        
        // Handle unique key(s)
        if (is_array($uniqueKeyField)) {
            foreach ($uniqueKeyField as $field) {
                if (!in_array($field, $allowedFields)) {
                    $allowedFields[] = $field;
                }
            }
        } else {
            if (!in_array($uniqueKeyField, $allowedFields)) {
                $allowedFields[] = $uniqueKeyField;
            }
        }

        // Remove any fields that aren't in the allowed list
        $filteredData = array_intersect_key($data, array_flip($allowedFields));
        
        // Remove null values that are not required (optional)
        $filteredData = array_filter($filteredData, function($value) {
            return $value !== null;
        });

        // Update or create based on unique key(s)
        try {
            if (is_array($uniqueKeyField)) {
                // Handle multiple unique keys
                $conditions = [];
                foreach ($uniqueKeyField as $field) {
                    if (isset($filteredData[$field])) {
                        $conditions[$field] = $filteredData[$field];
                    }
                }
                
                if (empty($conditions)) {
                    throw new \Exception("No valid unique key fields provided");
                }
                
                $modelClass::updateOrCreate($conditions, $filteredData);
            } else {
                // Single unique key
                if (!isset($filteredData[$uniqueKeyField])) {
                    throw new \Exception("Unique key field '{$uniqueKeyField}' is missing from data");
                }
                
                $modelClass::updateOrCreate(
                    [$uniqueKeyField => $filteredData[$uniqueKeyField]],
                    $filteredData
                );
            }
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23000') {
                throw new \Exception("Relationship mismatch: One or more IDs (like branch, zone, or region) do not exist in the database. Please ensure you have imported the location data (provinces, regions, zones, branches) first. Error: " . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Get list of importable tables for dynamic selection.
     */
    public function getImportableTables(): array
    {
        $configs = $this->getImportableConfig();
        $list = [];

        foreach ($configs as $table => $config) {
            $headers = $config['fillable'];
            if (isset($config['dependencies'])) {
                $headers = array_merge($headers, array_keys($config['dependencies']));
            }

            $list[] = [
                'table' => $table,
                'headers' => $headers,
                'unique_key' => $config['unique_key']
            ];
        }

        return $list;
    }
}