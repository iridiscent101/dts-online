<?php

namespace Database\Seeders;

use App\Models\Office;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Orchid\Platform\Models\Role;

class PersonnelOfficeRoleSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $role = Role::query()->updateOrCreate(
            ['slug' => 'personnel'],
            [
                'name' => 'Personnel',
                'description' => 'DepEd personnel assigned to an office document queue.',
                'permissions' => ['platform.index' => true],
            ],
        );

        foreach ($this->personnel() as $personnel) {
            $user = User::query()->firstOrNew([
                'email' => $personnel['email'],
            ]);

            $user->fill([
                'name' => $personnel['name'],
            ]);

            if (! $user->exists) {
                $user->password = Hash::make(Str::random(32));
                $user->email_verified_at = now();
            }

            $user->save();
            $office = Office::query()->firstOrCreate(['name' => $personnel['office']]);
            $user->offices()->syncWithoutDetaching([$office->id]);

            $user->roles()->syncWithoutDetaching([$role->id]);
        }
    }

    /**
     * Personnel assignments from users with assigned offices.xlsx.
     *
     * @return list<array{name: string, email: string, office: string}>
     */
    private function personnel(): array
    {
        return [
            ['name' => 'Marilou B. Mendoza', 'email' => 'marilou-b-mendoza@deped.local', 'office' => 'SDO-Schools Division Superintendent (SDS) Office'],
            ['name' => 'Laura C. Bergonia', 'email' => 'laura-c-bergonia@deped.local', 'office' => 'SDO-Assistant Schools Division Superintendent (ASDS) Office'],
            ['name' => 'Reliza Joy R. Tactay', 'email' => 'reliza-joy-r-tactay@deped.local', 'office' => 'SDO-Budget Unit'],
            ['name' => 'Steven Daryll P. Cristobal', 'email' => 'steven-daryll-p-cristobal@deped.local', 'office' => 'SDO-Accounting Unit'],
            ['name' => 'Maureen Joy V. Viado', 'email' => 'maureen-joy-v-viado@deped.local', 'office' => 'SDO-Legal Unit'],
            ['name' => 'Kennedy C. Guzman', 'email' => 'kennedy-c-guzman@deped.local', 'office' => 'SDO-Information and Communications Technology (ICT) Unit'],
            ['name' => 'Joshua W. Umli', 'email' => 'joshua-w-umli@deped.local', 'office' => 'SDO-Administrative Section (AO V)'],
            ['name' => 'Jeremie T. Fronda', 'email' => 'jeremie-t-fronda@deped.local', 'office' => 'SDO-Personnel Unit (HRMO)'],
            ['name' => 'Kairee Bryce Q. Carnate', 'email' => 'kairee-bryce-q-carnate@deped.local', 'office' => 'SDO-Property and Supply Unit'],
            ['name' => 'Mariah Czarina Bianca L. Macababbad', 'email' => 'mariah-czarina-bianca-l-macababbad@deped.local', 'office' => 'SDO-Cash Unit'],
            ['name' => 'Maricar M. Valido', 'email' => 'maricar-m-valido@deped.local', 'office' => 'SDO-Records Unit'],
            ['name' => 'Quennie Rose G. Baptista', 'email' => 'quennie-rose-g-baptista@deped.local', 'office' => 'SDO-Records Unit'],
            ['name' => 'Leslie Janine J. Rivera', 'email' => 'leslie-janine-j-rivera@deped.local', 'office' => 'SDO-Records Unit'],
            ['name' => 'Gergileen L. Estonelo', 'email' => 'gergileen-l-estonelo@deped.local', 'office' => 'SDO-Records Unit'],
            ['name' => 'Dennis D. Pangilinan', 'email' => 'dennis-d-pangilinan@deped.local', 'office' => 'SDO-Records Unit'],
            ['name' => 'Julie G. Mamugay', 'email' => 'julie-g-mamugay@deped.local', 'office' => 'SDO-School Governance and Operations Division (SGOD) Chief'],
            ['name' => 'Julie G. Mamugay', 'email' => 'julie-g-mamugay-sgod-personnel@deped.local', 'office' => 'SDO-SGOD Personnel (SMME/SMAN/EFU/YFP/DRRM/HNU)'],
            ['name' => 'Regie M. Marcos', 'email' => 'regie-m-marcos@deped.local', 'office' => 'SDO-EPS (School Sports/ Quality Management Office (QMO))'],
            ['name' => 'Herbert P. Mangabat', 'email' => 'herbert-p-mangabat@deped.local', 'office' => 'SDO-Human Resource Development (HRD) Unit'],
            ['name' => 'Clydinne A. Ballon', 'email' => 'clydinne-a-ballon@deped.local', 'office' => 'SDO-Planning and Research (PAR) Unit'],
            ['name' => 'Marjorie Mae M. Calleja', 'email' => 'marjorie-mae-m-calleja@deped.local', 'office' => 'SDO-Curriculum Implementation Division (CID )Chief'],
            ['name' => 'Jovelyn L. Masa', 'email' => 'jovelyn-l-masa@deped.local', 'office' => 'SDO-CID Personnel (EPS/PSDS)'],
        ];
    }
}
