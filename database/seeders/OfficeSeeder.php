<?php

namespace Database\Seeders;

use App\Models\Office;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class OfficeSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = now();
        $sdoOffices = $this->sdoOfficeNames();
        $clientOffices = $this->clientOfficeNames();

        $offices = collect($sdoOffices)
            ->merge($clientOffices)
            ->unique()
            ->values()
            ->map(function (string $name, int $index) use ($clientOffices, $sdoOffices, $timestamp): array {
                $sdoIndex = array_search($name, $sdoOffices, true);
                $clientIndex = array_search($name, $clientOffices, true);

                return [
                    'name' => $name,
                    'sort_order' => $index + 1,
                    'sdo_sort_order' => $sdoIndex === false ? null : $sdoIndex + 1,
                    'client_sort_order' => $clientIndex === false ? null : $clientIndex + 1,
                    'is_sdo' => $sdoIndex !== false,
                    'is_client' => $clientIndex !== false,
                    'is_active' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            })
            ->all();

        Office::query()->upsert(
            $offices,
            ['name'],
            [
                'sort_order',
                'sdo_sort_order',
                'client_sort_order',
                'is_sdo',
                'is_client',
                'is_active',
                'updated_at',
            ],
        );
    }

    /**
     * Offices from list of offices (sdo).xlsx.
     *
     * @return list<string>
     */
    private function sdoOfficeNames(): array
    {
        return [
            'SDO-Schools Division Superintendent (SDS) Office',
            'SDO-Assistant Schools Division Superintendent (ASDS) Office',
            'SDO-Budget Unit',
            'SDO-Accounting Unit',
            'SDO-Legal Unit',
            'SDO-Information and Communications Technology (ICT) Unit',
            'SDO-Procurement Unit',
            'SDO-Administrative Section (AO V)',
            'SDO-Personnel Unit (HRMO)',
            'SDO-Property and Supply Unit',
            'SDO-Records Unit',
            'SDO-Cash Unit',
            'SDO-School Governance and Operations Division (SGOD) Chief',
            'SDO-EPS (School Sports/ Quality Management Office (QMO))',
            'SDO-Planning and Research (PAR) Unit',
            'SDO-Human Resource Development (HRD) Unit',
            'SDO-School Management Monitoring and Evaluation (SMME)',
            'SDO-Social Mobilization and Networking (SMAN)',
            'SDO-Education Facilities Unit (EFU)',
            'SDO-Youth Formation Program (YFP)',
            'SDO-Disaster Risk Reduction Management',
            'SDO-School Health Section (SHS)',
            'SDO-Curriculum Implementation Division (CID )Chief',
            'SDO-EPS (Araling Panlipunan)',
            'SDO-EPS (Kindergarten & SNED)',
            'SDO-EPS (Mathematics)',
            'SDO-EPS (Science)',
            'SDO-EPS (Edukasyon sa Pagpapakatao)',
            'SDO-EPS (English)',
            'SDO-EPS (MAPEH)',
            'SDO-EPS (Filipino)',
            'SDO-EPS (TLE)',
            'SDO-PSDS (Alfonso Castaneda District)',
            'SDO-PSDS (Ambaguio District)',
            'SDO-PSDS (Aritao 1 District)',
            'SDO-PSDS (Aritao II District)',
            'SDO-PSDS (Bagabag I District)',
            'SDO-PSDS (Bagabag II District)',
            'SDO-PSDS (Bambang I District)',
            'SDO-PSDS (Bambang II District)',
            'SDO-PSDS (Bayombong I District)',
            'SDO-PSDS (Bayombong II District)',
            'SDO-PSDS (Diadi District)',
            'SDO-PSDS (Dupax Del Norte I District)',
            'SDO-PSDS (Dupax Del Norte II District)',
            'SDO-PSDS (Dupax Del Sur District)',
            'SDO-PSDS (Eastern Kayapa District)',
            'SDO-PSDS (Western Kayapa District)',
            'SDO-PSDS (Kasibu East District)',
            'SDO-PSDS (Kasibu West District)',
            'SDO-PSDS (Quezon District)',
            'SDO-PSDS (Santa Fe District)',
            'SDO-PSDS (Solano I District)',
            'SDO-PSDS (Solano II District)',
            'SDO-PSDS (Villaverde District)',
            'Personal Delivery',
            'Others',
        ];
    }

    /**
     * Offices from lsit of offices  (clients).xlsx.
     *
     * @return list<string>
     */
    private function clientOfficeNames(): array
    {
        return [
            'SDO-Schools Division Superintendent (SDS) Office',
            'SDO-Assistant Schools Division Superintendent (ASDS) Office',
            'SDO-School Governance and Operations Division (SGOD) Chief',
            'SDO-SGOD Personnel (SMME/SMAN/EFU/YFP/DRRM/HNU)',
            'SDO-Curriculum Implementation Division (CID )Chief',
            'SDO-CID Personnel (EPS/PSDS)',
            'SDO-Budget Unit',
            'SDO-Accounting Unit',
            'SDO-Legal Unit',
            'SDO-Information and Communications Technology (ICT) Unit',
            'SDO-Administrative Section (AO V)',
            'SDO-Personnel Unit (HRMO)',
            'SDO-Property and Supply Unit',
            'SDO-Records Unit',
            'SDO-Cash Unit',
            'SDO-Procurement Unit',
            'SDO-School Sports/ Quality Management Office (QMO)',
            'SDO-Planning and Research (PAR) Unit',
            'SDO-Human Resource Development (HRD) Unit',
            'Personal Delivery',
            'Others',
        ];
    }
}
