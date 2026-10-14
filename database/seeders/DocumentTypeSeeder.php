<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = now();
        $documentTypes = collect($this->documentTypeNames())
            ->unique()
            ->values()
            ->map(fn (string $name, int $index): array => [
                'name' => $name,
                'sort_order' => $index + 1,
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])
            ->all();

        DocumentType::query()->upsert(
            $documentTypes,
            ['name'],
            ['sort_order', 'is_active', 'updated_at'],
        );
    }

    /**
     * Document names from list of docs.xlsx, with exact duplicate rows removed.
     *
     * @return list<string>
     */
    private function documentTypeNames(): array
    {
        return [
            'Advisory',
            'Annual Implementation Plan (AIP)',
            'Annual Learning and Development Plan (Schools)',
            'Annual MISTAH Report',
            'AOA GSIS Form',
            'Application for Conversion from Primary to Complete Elementary',
            'Application for Coversion to Integrated School',
            'Application for Government Recognition',
            'Application for Renaming of Public Schools',
            'Application for Renaming of Schools',
            'Application for Renewal of Government Permit',
            'Application for SO SHS (Private)',
            'Application for Temporary Closure of Schools',
            'Application Letter',
            'Application of Education',
            'Application of Non-Increase of School Fees (Private)',
            'Bidding Documents',
            'Billing Statement (Electric)',
            'Billing Statement (Internet)',
            'Billing Statement (Telephone)',
            'Calendar of Activities',
            'Career Guidance Program (CGP)',
            'Casual Form',
            'Central Office Memorandum',
            'Class Program',
            'Complaint Letter',
            'Contextualized learning Resources (CLRs)',
            'Conversion to Technical Vocational School',
            'Delivery Receipts',
            'DepEd Order',
            'Designation Orders',
            'Division Bulletin',
            'Division Memorandum',
            'Division Order',
            'DTR-Administrative Assistants',
            'DTR-Administrative Officer II',
            'DTR-Senior High School Teachers',
            'EFU-Engineering-Program of Works',
            'Enhanced School Improvement Plan (ESIP)',
            'Equivalent Record Form (ERF)',
            'Establishment of New Private School',
            'Establishment of Senior HS (Private)',
            'Establishment of Senior HS (Public)',
            'Form 6-Elementary (Travel Abroad, Maternity Leave, Paternity Leave etc.)',
            'Form 6-JHS (Travel Abroad, Maternity Leave, Paternity Leave etc.)',
            'Form 6-SDO (Travel Abroad, Maternity Leave, Paternity Leave etc.)',
            'Form 6-SHS (Travel Abroad, Maternity Leave, Paternity Leave etc.)',
            'Form 7-Payroll-Cash Allowance',
            'Form 7-Payroll-Clothing',
            'Form 7-Payroll-Maternity Pay',
            'Form 7-Payroll-Midyear Bonus',
            'Form 7-Payroll-Proportional Vacation Pay',
            'Form 7-Payroll-Salary Differential',
            'Form 7-Payroll-Year-End Bonus',
            'Form 7-Summary of Leaves',
            'GAD Proposal',
            'Government Permit',
            'Government Recognition (Private)',
            'GSIS Form (ARA)',
            'Increase of School Fees (Private)',
            'Indorsement Letter',
            'innovation',
            'Inspection and Acceptance Report (IAR)',
            'Inventory & Inspection of Unserviceable Property (IIRUP)',
            'Inventory Custodian Slip',
            'Invitation Letter',
            'IPCRF',
            'Itinerary of Travel',
            'Itinerary of Travel (Elementary)',
            'Itinerary of Travel (SDO)',
            'Itinerary of Travel (Secondary)',
            'Job Order Form',
            'L & D Proposal',
            'Learners Rights Report (Child Protection Reports)',
            'Memorandum of Agreement (MOA)Others',
            'Monthly Accomplishment Report',
            'Monthly Itinerary Plan',
            'Monthly Monitoring and Evaluation Report',
            'Notice of Organization, Staffing and Compensation Action (NOSCA)',
            'Office Memorandum',
            'Office Order',
            'OPCRF',
            'Permit/Acceptance of Proposal',
            'PRAISE/STARS Report',
            'Proposal Sheet',
            'REAP Proposal',
            'Recommendation Letter',
            'Reconstituted Diploma',
            'Regional Memorandum',
            'Report on the Physical Count of Property, Plant & Equipment (RPCPE)',
            'Request Certification for No Pending Case',
            'Request for a Copy of Earned Service Credits',
            'Request for Additional SHS (Private)',
            'Request for Additional SHS (Public)',
            'Request for Certificate of Rating (COE)',
            'Request for Certification of SDO-NV Documents',
            'Request for Certification, Authentication and Verification of Student\'s Academic Records',
            'Request for Certified True Copy (CTC) and Photocopy of Documents',
            'Request for Change of Status',
            'Request for Monetization',
            'Request for Non-CTC Documnts',
            'Request for Publication',
            'Request for Salary Integration Form (GSIS Form, PSU Form)',
            'Request for SBM Validation',
            'Request for Service Credits/Compensatory Time Off (CTO)',
            'Request for Service Recordp',
            'Request for Study Leave',
            'Request for Tracks',
            'Request for Transfer',
            'Request for Travel Abroad',
            'Request Letter',
            'Request of Technical Assistance',
            'Research Proposal',
            'Scholarship (E-Learning Courses, Exchange Programs)',
            'School Governance Council (SGC Report)',
            'School-Based Management (SBM)',
            'SMAN Partnership Proposals',
            'SMAN-Project Proposals',
            'Special Allotment Release Order (SARO)',
            'Special Order',
            'Special Order of Graduation Private Schools',
            'Sports Proposal',
            'Statement of Account (SOA)- Fuel Consumption',
            'Step Increment',
            'Strategic Intervention Management (SLM)',
            'Submission of QAME Report for L&D',
            'Submission of Technical Assistance Needs',
            'Teachers Schedule',
            'Terminal Leave Benefit (TLB)',
            'Travel Authority',
            'Waste Material Report',
            'Work and Financial Plan (WFP)',
            'Youth Formation Leardership Project Proposals',
            'Youth Formation Partnership Proposals',
            'Others',
        ];
    }
}
