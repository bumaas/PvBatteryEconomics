<?php

declare(strict_types=1);

class PVBatteryEconomics extends IPSModuleStrict
{
    private const int STATUS_CONFIG_INCOMPLETE = 201;
    private const int SECONDS_PER_HOUR = 3600;
    private const string DEFAULT_AGGREGATE_FIELD = 'Avg';
    private const float DEFAULT_INITIAL_SOC_RATIO = 0.5;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('StartDate', '2025-01-01 00:00:00');
        $this->RegisterPropertyString('EndDate', '2025-12-31 23:59:59');

        $this->RegisterPropertyInteger('GridImportVarID', 0);
        $this->RegisterPropertyInteger('GridExportVarID', 0);
        $this->RegisterPropertyFloat('CounterUnitToKWh', 1.0);

        $this->RegisterPropertyFloat('PriceImport', 0.32);
        $this->RegisterPropertyFloat('PriceExport', 0.082);

        $this->RegisterPropertyFloat('BatteryCapacity', 10.0);
        $this->RegisterPropertyFloat('BatteryMaxCharge', 4.6);
        $this->RegisterPropertyFloat('BatteryMaxDischarge', 4.6);
        $this->RegisterPropertyFloat('BatteryChargeEff', 0.95);
        $this->RegisterPropertyFloat('BatteryDischargeEff', 0.95);
        $this->RegisterPropertyFloat('BatteryInvest', 6000.0);

        $this->RegisterPropertyFloat('LowImportWarningThreshold', 500.0);

        $this->registerStatusVariables();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->registerStatusVariables();

        if ($this->ReadPropertyInteger('GridImportVarID') <= 0 || $this->ReadPropertyInteger('GridExportVarID') <= 0) {
            $this->SetStatus(self::STATUS_CONFIG_INCOMPLETE);
            return;
        }

        $this->SetStatus(IS_ACTIVE);
    }

    private function registerStatusVariables(): void
    {
        $this->RegisterVariableString('Summary', 'Zusammenfassung', '', 10);
        $this->RegisterVariableFloat('BaselineImportKWh', 'Netzbezug ohne Batterie (kWh)', '~Electricity', 20);
        $this->RegisterVariableFloat('BaselineExportKWh', 'Netzeinspeisung ohne Batterie (kWh)', '~Electricity', 30);
        $this->RegisterVariableFloat('BaselineCostEUR', 'Kosten ohne Batterie (EUR)', '~Euro', 40);

        $this->RegisterVariableFloat('SimImportKWh', 'Netzbezug mit Batterie (kWh)', '~Electricity', 50);
        $this->RegisterVariableFloat('SimExportKWh', 'Netzeinspeisung mit Batterie (kWh)', '~Electricity', 60);
        $this->RegisterVariableFloat('SimCostEUR', 'Kosten mit Batterie (EUR)', '~Euro', 70);

        $this->RegisterVariableFloat('SavingEUR', 'Ersparnis (EUR)', '~Euro', 80);
        $this->RegisterVariableFloat('PaybackYears', 'Amortisation (Jahre)', '', 90);
        $this->RegisterVariableFloat('EquivalentCycles', 'Äquivalente Vollzyklen', '', 100);

        $this->RegisterVariableFloat('AvoidedImportKWh', 'Vermiedener Netzbezug (kWh)', '~Electricity', 101);
        $this->RegisterVariableFloat('LostFeedInKWh', 'Weniger Einspeisung (kWh)', '~Electricity', 102);
        $this->RegisterVariableFloat('AvoidedImportCostEUR', 'Vermiedene Bezugskosten (EUR)', '~Euro', 103);
        $this->RegisterVariableFloat('LostFeedInRevenueEUR', 'Entgangene Einspeisevergütung (EUR)', '~Euro', 104);
        $this->RegisterVariableFloat('ChargedFromPvKWh', 'In Batterie geladen (kWh)', '~Electricity', 110);
        $this->RegisterVariableFloat('DischargedToLoadKWh', 'Aus Batterie an Last (kWh)', '~Electricity', 120);
        $this->RegisterVariableFloat('BatteryLossesKWh', 'Batterieverluste (kWh)', '~Electricity', 130);
    }

    public function Calculate(): void
    {
        try {
            $importVarId = $this->ReadPropertyInteger('GridImportVarID');
            $exportVarId = $this->ReadPropertyInteger('GridExportVarID');

            if ($importVarId <= 0 || $exportVarId <= 0) {
                throw new RuntimeException('Variablen-IDs für Netzbezug/Netzeinspeisung sind nicht gesetzt.');
            }

            $startTs = strtotime($this->ReadPropertyString('StartDate'));
            $endTs = strtotime($this->ReadPropertyString('EndDate'));
            if ($startTs === false || $endTs === false || $endTs <= $startTs) {
                throw new RuntimeException('Ungültiges Datum in StartDate/EndDate.');
            }

            $archiveId = $this->getArchiveId();
            $unitToKwh = $this->ReadPropertyFloat('CounterUnitToKWh');

            $hourlyImport = $this->getHourlyEnergyFromAggregates($archiveId, $importVarId, $startTs, $endTs, $unitToKwh);
            $hourlyExport = $this->getHourlyEnergyFromAggregates($archiveId, $exportVarId, $startTs, $endTs, $unitToKwh);

            $hourKeys = array_values(array_intersect(array_keys($hourlyImport), array_keys($hourlyExport)));
            sort($hourKeys);
            if ($hourKeys === []) {
                throw new RuntimeException('Keine gemeinsamen Stundenwerte für Bezug und Einspeisung gefunden.');
            }

            $baseline = $this->calculateBaseline($hourKeys, $hourlyImport, $hourlyExport);
            $simulation = $this->simulateBattery($hourKeys, $hourlyImport, $hourlyExport);

            $priceImport = $this->ReadPropertyFloat('PriceImport');
            $priceExport = $this->ReadPropertyFloat('PriceExport');

            $baseCost = $baseline['import_kwh'] * $priceImport - $baseline['export_kwh'] * $priceExport;
            $simCost = $simulation['import_kwh'] * $priceImport - $simulation['export_kwh'] * $priceExport;
            $saving = $baseCost - $simCost;
            $avoidedImportKWh = max(0.0, $baseline['import_kwh'] - $simulation['import_kwh']);
            $lostFeedInKWh = max(0.0, $baseline['export_kwh'] - $simulation['export_kwh']);
            $avoidedImportCostEUR = $avoidedImportKWh * $priceImport;
            $lostFeedInRevenueEUR = $lostFeedInKWh * $priceExport;
            $netBenefitEUR = $avoidedImportCostEUR - $lostFeedInRevenueEUR;

            $capacity = $this->ReadPropertyFloat('BatteryCapacity');
            $cycles = $capacity > 0.0 ? ($simulation['discharged_to_load_kwh'] / $capacity) : 0.0;

            $invest = $this->ReadPropertyFloat('BatteryInvest');
            $paybackYears = $netBenefitEUR > 0.0 ? ($invest / $netBenefitEUR) : -1.0;

            $this->SetValue('BaselineImportKWh', round($baseline['import_kwh'], 3));
            $this->SetValue('BaselineExportKWh', round($baseline['export_kwh'], 3));
            $this->SetValue('BaselineCostEUR', round($baseCost, 2));

            $this->SetValue('SimImportKWh', round($simulation['import_kwh'], 3));
            $this->SetValue('SimExportKWh', round($simulation['export_kwh'], 3));
            $this->SetValue('SimCostEUR', round($simCost, 2));

            $this->SetValue('SavingEUR', round($saving, 2));
            $this->SetValue('PaybackYears', $paybackYears < 0 ? 0.0 : round($paybackYears, 2));
            $this->SetValue('EquivalentCycles', round($cycles, 2));
            $this->SetValue('AvoidedImportKWh', round($avoidedImportKWh, 3));
            $this->SetValue('LostFeedInKWh', round($lostFeedInKWh, 3));
            $this->SetValue('AvoidedImportCostEUR', round($avoidedImportCostEUR, 2));
            $this->SetValue('LostFeedInRevenueEUR', round($lostFeedInRevenueEUR, 2));

            $this->SetValue('ChargedFromPvKWh', round($simulation['charged_from_pv_kwh'], 3));
            $this->SetValue('DischargedToLoadKWh', round($simulation['discharged_to_load_kwh'], 3));
            $this->SetValue('BatteryLossesKWh', round($simulation['battery_losses_kwh'], 3));

            $summary = $this->buildSummary(
                $hourKeys,
                $baseline,
                $baseCost,
                $simulation,
                $simCost,
                $saving,
                $paybackYears,
                $avoidedImportKWh,
                $lostFeedInKWh,
                $avoidedImportCostEUR,
                $lostFeedInRevenueEUR
            );
            $this->SetValue('Summary', $summary);

            $dailyValues = $this->buildPeriodValues(
                $hourKeys,
                $hourlyImport,
                $hourlyExport,
                $simulation['hourly_import_kwh'],
                $simulation['hourly_export_kwh'],
                $priceImport,
                $priceExport,
                'Y-m-d'
            );
            $monthlyValues = $this->buildPeriodValues(
                $hourKeys,
                $hourlyImport,
                $hourlyExport,
                $simulation['hourly_import_kwh'],
                $simulation['hourly_export_kwh'],
                $priceImport,
                $priceExport,
                'Y-m'
            );
            $this->sendPeriodDebug($dailyValues, $monthlyValues);

            $this->SetStatus(IS_ACTIVE);
        } catch (Throwable $e) {
            $this->SendDebug('Calculate', $e->getMessage(), 0);
            $this->SetValue('Summary', 'Fehler: ' . $e->getMessage());
            $this->SetStatus(IS_EBASE + 1);
            throw $e;
        }
    }

    private function buildSummary(
        array $hourKeys,
        array $baseline,
        float $baseCost,
        array $simulation,
        float $simCost,
        float $saving,
        float $paybackYears,
        float $avoidedImportKWh,
        float $lostFeedInKWh,
        float $avoidedImportCostEUR,
        float $lostFeedInRevenueEUR
    ): string {
        $lines = [];
        $lines[] = '--- Batteriesimulation (stündlich) ---';
        $lines[] = sprintf('Zeitraum: %s bis %s', date('Y-m-d H:i', $hourKeys[0]), date('Y-m-d H:i', end($hourKeys) + self::SECONDS_PER_HOUR));
        $lines[] = '';
        $lines[] = '[Ohne Batterie]';
        $lines[] = sprintf('Netzbezug: %.1f kWh', $baseline['import_kwh']);
        $lines[] = sprintf('Netzeinspeisung: %.1f kWh', $baseline['export_kwh']);
        $lines[] = sprintf('Kosten: %.2f EUR', $baseCost);
        $lines[] = '';
        $lines[] = '[Mit Batterie]';
        $lines[] = sprintf('Netzbezug: %.1f kWh', $simulation['import_kwh']);
        $lines[] = sprintf('Netzeinspeisung: %.1f kWh', $simulation['export_kwh']);
        $lines[] = sprintf('Kosten: %.2f EUR', $simCost);
        $lines[] = '';
        $lines[] = '[Wirtschaftlichkeit]';
        $lines[] = sprintf('Ersparnis: %.2f EUR', $saving);
        $lines[] = sprintf('Vermiedener Netzbezug: %.1f kWh', $avoidedImportKWh);
        $lines[] = sprintf('Weniger Einspeisung: %.1f kWh', $lostFeedInKWh);
        $lines[] = sprintf('Vermiedene Bezugskosten: %.2f EUR', $avoidedImportCostEUR);
        $lines[] = sprintf('Entgangene Einspeisevergütung: %.2f EUR', $lostFeedInRevenueEUR);
        $lines[] = sprintf('Netto-Vorteil: %.2f EUR', $avoidedImportCostEUR - $lostFeedInRevenueEUR);
        if ($paybackYears > 0) {
            $lines[] = sprintf('Amortisation: %.1f Jahre', $paybackYears);
        } else {
            $lines[] = 'Amortisation: nicht erreichbar';
        }

        if ($baseline['import_kwh'] < $this->ReadPropertyFloat('LowImportWarningThreshold')) {
            $lines[] = '';
            $lines[] = '[Hinweis]';
            $lines[] = 'Netzbezug wirkt für den Zeitraum sehr niedrig. Bitte Einheit und Archiv-Aggregation prüfen.';
        }

        return implode("\n", $lines);
    }

    private function getArchiveId(): int
    {
        $ids = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (!isset($ids[0])) {
            throw new RuntimeException('Archive-Control-Instanz nicht gefunden.');
        }

        return (int) $ids[0];
    }

    private function getHourlyEnergyFromAggregates(int $archiveId, int $varId, int $startTs, int $endTs, float $unitToKwh): array
    {
        $firstHour = intdiv($startTs, self::SECONDS_PER_HOUR) * self::SECONDS_PER_HOUR;
        $lastHourStart = intdiv($endTs, self::SECONDS_PER_HOUR) * self::SECONDS_PER_HOUR;

        $rows = AC_GetAggregatedValues($archiveId, $varId, 0, $firstHour, $lastHourStart + self::SECONDS_PER_HOUR, 0);
        if (!is_array($rows) || count($rows) === 0) {
            throw new RuntimeException(sprintf('Keine aggregierten Werte für Variable %d gefunden.', $varId));
        }

        $hours = [];
        foreach ($rows as $row) {
            if (!isset($row['TimeStamp'])) {
                continue;
            }

            $hourStart = $this->mapRowToHourStart((int) $row['TimeStamp'], $firstHour, $lastHourStart);
            if ($hourStart === null) {
                continue;
            }

            $delta = $this->extractHourlyEnergyKwh($row, $unitToKwh);
            if ($delta < 0) {
                $delta = 0.0;
            }

            $hours[$hourStart] = $delta;
        }

        return $hours;
    }

    private function mapRowToHourStart(int $timeStamp, int $firstHour, int $lastHourStart): ?int
    {
        if ($timeStamp >= $firstHour && $timeStamp <= $lastHourStart) {
            return $timeStamp;
        }

        $shifted = $timeStamp - self::SECONDS_PER_HOUR;
        if ($shifted >= $firstHour && $shifted <= $lastHourStart) {
            return $shifted;
        }

        return null;
    }

    private function extractHourlyEnergyKwh(array $row, float $unitToKwh): float
    {
        if (self::DEFAULT_AGGREGATE_FIELD === 'Avg') {
            if (isset($row['Avg']) && is_numeric($row['Avg'])) {
                return (float) $row['Avg'] * $unitToKwh;
            }
            if (isset($row['Max'], $row['Min']) && is_numeric($row['Max']) && is_numeric($row['Min'])) {
                return ((float) $row['Max'] - (float) $row['Min']) * $unitToKwh;
            }
            return 0.0;
        }

        if (isset($row['Max'], $row['Min']) && is_numeric($row['Max']) && is_numeric($row['Min'])) {
            return ((float) $row['Max'] - (float) $row['Min']) * $unitToKwh;
        }
        if (isset($row['Avg']) && is_numeric($row['Avg'])) {
            return (float) $row['Avg'] * $unitToKwh;
        }

        return 0.0;
    }

    private function calculateBaseline(array $hourKeys, array $hourlyImport, array $hourlyExport): array
    {
        $sumImport = 0.0;
        $sumExport = 0.0;

        foreach ($hourKeys as $ts) {
            $sumImport += max(0.0, (float) $hourlyImport[$ts]);
            $sumExport += max(0.0, (float) $hourlyExport[$ts]);
        }

        return [
            'import_kwh' => $sumImport,
            'export_kwh' => $sumExport
        ];
    }

    private function simulateBattery(array $hourKeys, array $hourlyImport, array $hourlyExport): array
    {
        $capacity = max(0.01, $this->ReadPropertyFloat('BatteryCapacity'));
        $maxCharge = max(0.0, $this->ReadPropertyFloat('BatteryMaxCharge'));
        $maxDischarge = max(0.0, $this->ReadPropertyFloat('BatteryMaxDischarge'));
        $chargeEff = min(1.0, max(0.01, $this->ReadPropertyFloat('BatteryChargeEff')));
        $dischargeEff = min(1.0, max(0.01, $this->ReadPropertyFloat('BatteryDischargeEff')));

        $soc = $capacity * self::DEFAULT_INITIAL_SOC_RATIO;

        $sumImport = 0.0;
        $sumExport = 0.0;
        $sumChargedFromPv = 0.0;
        $sumDischargedToLoad = 0.0;
        $sumBatteryLosses = 0.0;
        $simImportByHour = [];
        $simExportByHour = [];

        foreach ($hourKeys as $ts) {
            $deficit = max(0.0, (float) $hourlyImport[$ts]);
            $surplus = max(0.0, (float) $hourlyExport[$ts]);

            $deliverable = min($maxDischarge, $soc * $dischargeEff);
            $dischargeToLoad = min($deficit, $deliverable);
            if ($dischargeToLoad > 0.0) {
                $socDecrease = $dischargeToLoad / $dischargeEff;
                $soc -= $socDecrease;
                $deficit -= $dischargeToLoad;
                $sumDischargedToLoad += $dischargeToLoad;
                $sumBatteryLosses += ($socDecrease - $dischargeToLoad);
            }

            $storableFromPv = min($maxCharge, ($capacity - $soc) / $chargeEff);
            $chargeFromPv = min($surplus, $storableFromPv);
            if ($chargeFromPv > 0.0) {
                $socIncrease = $chargeFromPv * $chargeEff;
                $soc += $socIncrease;
                $surplus -= $chargeFromPv;
                $sumChargedFromPv += $chargeFromPv;
                $sumBatteryLosses += ($chargeFromPv - $socIncrease);
            }

            $sumImport += $deficit;
            $sumExport += $surplus;
            $simImportByHour[$ts] = $deficit;
            $simExportByHour[$ts] = $surplus;
        }

        return [
            'import_kwh' => $sumImport,
            'export_kwh' => $sumExport,
            'charged_from_pv_kwh' => $sumChargedFromPv,
            'discharged_to_load_kwh' => $sumDischargedToLoad,
            'battery_losses_kwh' => $sumBatteryLosses,
            'hourly_import_kwh' => $simImportByHour,
            'hourly_export_kwh' => $simExportByHour
        ];
    }

    private function buildPeriodValues(
        array $hourKeys,
        array $baselineImport,
        array $baselineExport,
        array $simImport,
        array $simExport,
        float $priceImport,
        float $priceExport,
        string $periodFormat
    ): array {
        $periods = [];

        foreach ($hourKeys as $ts) {
            $periodKey = date($periodFormat, $ts);
            if (!isset($periods[$periodKey])) {
                $periods[$periodKey] = [
                    'baseline_import_kwh' => 0.0,
                    'baseline_export_kwh' => 0.0,
                    'sim_import_kwh' => 0.0,
                    'sim_export_kwh' => 0.0
                ];
            }

            $periods[$periodKey]['baseline_import_kwh'] += max(0.0, (float) $baselineImport[$ts]);
            $periods[$periodKey]['baseline_export_kwh'] += max(0.0, (float) $baselineExport[$ts]);
            $periods[$periodKey]['sim_import_kwh'] += max(0.0, (float) $simImport[$ts]);
            $periods[$periodKey]['sim_export_kwh'] += max(0.0, (float) $simExport[$ts]);
        }

        foreach ($periods as $periodKey => $values) {
            $baseCost = $values['baseline_import_kwh'] * $priceImport - $values['baseline_export_kwh'] * $priceExport;
            $simCost = $values['sim_import_kwh'] * $priceImport - $values['sim_export_kwh'] * $priceExport;
            $periods[$periodKey]['baseline_cost_eur'] = round($baseCost, 2);
            $periods[$periodKey]['sim_cost_eur'] = round($simCost, 2);
            $periods[$periodKey]['saving_eur'] = round($baseCost - $simCost, 2);
            $periods[$periodKey]['baseline_import_kwh'] = round($values['baseline_import_kwh'], 3);
            $periods[$periodKey]['baseline_export_kwh'] = round($values['baseline_export_kwh'], 3);
            $periods[$periodKey]['sim_import_kwh'] = round($values['sim_import_kwh'], 3);
            $periods[$periodKey]['sim_export_kwh'] = round($values['sim_export_kwh'], 3);
        }

        ksort($periods);
        return $periods;
    }

    private function sendPeriodDebug(array $dailyValues, array $monthlyValues): void
    {
        $daysByMonth = [];
        foreach ($dailyValues as $dayKey => $values) {
            $monthKey = substr($dayKey, 0, 7);
            if (!isset($daysByMonth[$monthKey])) {
                $daysByMonth[$monthKey] = [];
            }
            $daysByMonth[$monthKey][$dayKey] = $values;
        }

        ksort($daysByMonth);
        foreach ($daysByMonth as $monthKey => $monthDays) {
            ksort($monthDays);
            foreach ($monthDays as $dayKey => $dayValues) {
                $this->SendDebug('DayValue', $dayKey . ' ' . json_encode($dayValues), 0);
            }

            if (isset($monthlyValues[$monthKey])) {
                $this->SendDebug('MonthValue', $monthKey . ' ' . json_encode($monthlyValues[$monthKey]), 0);
            }
        }
    }
}

