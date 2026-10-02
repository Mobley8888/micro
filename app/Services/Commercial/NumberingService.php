<?php

namespace App\Services\Commercial;

use App\Models\Company;
use App\Models\NumberingSetting;

class NumberingService
{
    public function next(string $companyId, string $documentType, int $year, ?string $defaultPrefix = null): string
    {
        $setting = NumberingSetting::query()
            ->where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($setting === null) {
            $setting = NumberingSetting::create([
                'company_id' => $companyId,
                'document_type' => $documentType,
                'prefix' => $defaultPrefix ?? strtoupper($documentType),
                'next_number' => 1,
                'padding' => 6,
                'year' => $year,
            ]);
        }

        $number = sprintf('%s-%d-%0'.$setting->padding.'d', $setting->prefix, $year, $setting->next_number);
        $setting->increment('next_number');

        return $number;
    }

    public function currentCompanyId(): string
    {
        return (string) Company::query()->value('id');
    }
}
