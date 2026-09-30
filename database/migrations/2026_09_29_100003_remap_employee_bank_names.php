<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Re-run the employee -> bank_id backfill using normalised bank names.
 *
 * The original migration matched employees.bank_name against banks.name
 * exactly, but real data uses abbreviations, typos and "Bank / Branch"
 * suffixes (BOC, Boc / Mannar, Commecial Bank, NSB, Peoples, ...), so only a
 * handful of employees were linked. This maps those variants onto the
 * canonical bank rows instead.
 */
return new class extends Migration
{
    /**
     * Normalised employee bank name => canonical bank name.
     *
     * Keys are produced by normalise(): lowercase, branch suffix removed,
     * punctuation and apostrophes dropped, whitespace collapsed.
     */
    private const ALIASES = [
        // Bank of Ceylon
        'boc' => 'Bank of Ceylon',
        'boc bank' => 'Bank of Ceylon',

        // Commercial Bank
        'commecial bank' => 'Commercial Bank',
        'commercial' => 'Commercial Bank',
        'commercial bank' => 'Commercial Bank',

        // Other banks
        'dfcc' => 'DFCC Bank',
        'dfcc bank' => 'DFCC Bank',
        'hatton national bank' => 'Hatton National Bank',
        'hnb' => 'Hatton National Bank',
        'national saving bank' => 'National Savings Bank',
        'nsb' => 'National Savings Bank',
        'ndb' => 'NDB Bank',
        'pan asia bank' => 'Pan Asia Bank',
        // normalise() turns the apostrophe into a space, so "People's" becomes
        // "people s" while "Peoples" stays "peoples". Both are registered, along
        // with the spaced variant of the full name.
        'people s' => "People's Bank",
        'people s bank' => "People's Bank",
        'peoples' => "People's Bank",
        'peoples bank' => "People's Bank",
        'sampath' => 'Sampath Bank',
        'sampath bank' => 'Sampath Bank',
        'selan bank' => 'Seylan Bank',
        'seylan' => 'Seylan Bank',
        'seylan bank' => 'Seylan Bank',
        'ntb' => 'Nations Trust Bank',
    ];

    /**
     * Reduce a raw bank name to a comparable form:
     * lowercase, drop any "/ Branch" suffix, remove punctuation/apostrophes
     * and collapse whitespace. "Boc / Mannar" and "BOC" both become "boc".
     */
    private function normalise(?string $raw): ?string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        // "Boc / Mannar", "commercial /", "Boc /Mannar" -> keep the part before "/"
        if (str_contains($value, '/')) {
            $value = trim(Str::before($value, '/'));
        }

        $value = Str::lower($value);
        // Strip apostrophes, dots, commas and any other punctuation.
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'bank_id')) {
            return;
        }

        $banks = DB::table('banks')->get(['id', 'name']);

        // Canonical name (normalised) => id. Later rows do not overwrite the
        // first match so the result is deterministic.
        $byName = [];
        foreach ($banks as $bank) {
            $key = $this->normalise($bank->name);
            if ($key !== null && ! isset($byName[$key])) {
                $byName[$key] = $bank->id;
            }
        }

        // Build the final normalised name => bank id map, expanding aliases
        // onto the canonical rows that actually exist in the banks table.
        $aliasToId = [];
        foreach (self::ALIASES as $alias => $canonical) {
            $canonicalKey = $this->normalise($canonical);
            if (isset($byName[$canonicalKey])) {
                $aliasToId[$alias] = $byName[$canonicalKey];
            }
        }

        $employees = DB::table('employees')
            ->whereNull('bank_id')
            ->whereNotNull('bank_name')
            ->where('bank_name', '!=', '')
            ->get(['id', 'bank_name']);

        $updated = 0;
        $unmatched = [];

        foreach ($employees as $employee) {
            $key = $this->normalise($employee->bank_name);

            $bankId = $key !== null
                ? ($aliasToId[$key] ?? $byName[$key] ?? null)
                : null;

            if ($bankId === null) {
                $unmatched[$employee->bank_name] = ($unmatched[$employee->bank_name] ?? 0) + 1;
                continue;
            }

            DB::table('employees')->where('id', $employee->id)->update([
                'bank_id' => $bankId,
            ]);
            $updated++;
        }

        if ($unmatched !== []) {
            \Log::warning('Bank backfill could not resolve some bank names', [
                'unmatched' => $unmatched,
            ]);
        }

        \Log::info('Bank backfill completed', [
            'linked' => $updated,
            'unmatched_values' => count($unmatched),
        ]);
    }

    public function down(): void
    {
        // The link is derived data; leave previously matched rows in place
        // rather than clearing legitimate links set by the earlier migration.
    }
};
