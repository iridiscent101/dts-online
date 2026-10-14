<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PublicDocumentTrackingTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_can_open_the_fast_track_page(): void
    {
        $this->get(route('tracking.index'))
            ->assertOk()
            ->assertSee('Track a document')
            ->assertSee(asset('images/icon.png'))
            ->assertSee('SDO – Nueva Vizcaya')
            ->assertSee('Staff sign in')
            ->assertSee('Track document')
            ->assertSee('data-open-submission', false)
            ->assertSee('id="submission-dialog"', false)
            ->assertSee('data-options="destination-offices"', false)
            ->assertSee('Add another office')
            ->assertDontSee('Enter its DTS tracking number to view the latest recorded movement.')
            ->assertDontSee('No matching document');
    }

    public function test_submission_validation_returns_to_the_tracking_page_and_reopens_the_modal(): void
    {
        $response = $this->followingRedirects()
            ->from(route('tracking.index'))
            ->post(route('submission.store'));

        $response
            ->assertOk()
            ->assertSee('Track a document')
            ->assertSee('Please check your submission.')
            ->assertSee('submissionDialog.showModal();', false);
    }

    public function test_exact_tracking_number_shows_public_document_details_and_newest_movements_first(): void
    {
        $actor = User::factory()->create(['name' => 'Private Employee']);
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-2026-000123',
            'subject' => 'Budget <script>alert("private")</script>',
            'document_type' => 'Memorandum',
            'sender' => 'Private Sender',
            'origin' => 'Private Origin',
            'current_office' => 'Curriculum Implementation Division',
            'status' => 'Received',
            'received_at' => '2026-09-25',
            'remarks' => 'Private document remarks',
            'created_at' => '2026-09-25 01:00:00',
        ]);
        $document->movements()->create([
            'action' => 'forward',
            'from_office' => 'Records Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Received',
            'to_status' => 'Forwarded',
            'remarks' => 'Private forwarding remarks',
            'acted_by' => $actor->id,
            'created_at' => '2026-09-25 03:00:00',
            'updated_at' => '2026-09-25 03:00:00',
        ]);
        $document->movements()->create([
            'action' => 'acknowledge',
            'from_office' => 'Administrative Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Forwarded',
            'to_status' => 'Received',
            'remarks' => 'Private receipt remarks',
            'acted_by' => $actor->id,
            'created_at' => '2026-09-25 05:00:00',
            'updated_at' => '2026-09-25 05:00:00',
        ]);

        $response = $this->get(route('tracking.index', ['tracking' => 'dts-2026-000123']));

        $response
            ->assertOk()
            ->assertSee('DTS-2026-000123')
            ->assertSee('Current status')
            ->assertDontSee('Show tracking QR code')
            ->assertSee('QR code for DTS-2026-000123')
            ->assertSee('Save your tracking QR code')
            ->assertSee('Curriculum Implementation Division')
            ->assertSee('Assigned to Records Unit')
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("private")</script>', false)
            ->assertSeeInOrder([
                'Current status',
                'Administrative Unit received the document',
                'Records Unit forwarded it to Administrative Unit',
                'Document registered',
            ])
            ->assertSee('data-kind="received"', false)
            ->assertSee('data-kind="moved"', false)
            ->assertDontSee('Private Sender')
            ->assertDontSee('Private Origin')
            ->assertDontSee('Private document remarks')
            ->assertDontSee('Private forwarding remarks')
            ->assertDontSee('Private Employee');
    }

    public function test_registration_office_does_not_change_after_the_document_is_forwarded(): void
    {
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-2026-000124',
            'current_office' => 'Administrative Unit',
            'status' => 'Forwarded',
        ]);
        $document->movements()->create([
            'action' => 'sent',
            'from_office' => null,
            'to_office' => 'Records Unit',
            'to_status' => 'Awaiting receipt',
        ]);
        $document->movements()->create([
            'action' => 'forwarded',
            'from_office' => 'Records Unit',
            'to_office' => 'Administrative Unit',
            'from_status' => 'Received',
            'to_status' => 'Forwarded',
        ]);

        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-000124']))
            ->assertOk()
            ->assertSee('Assigned to Records Unit')
            ->assertDontSee('Assigned to Administrative Unit');
    }

    public function test_multiple_initial_and_active_offices_are_shown_without_a_false_single_status(): void
    {
        $document = Document::factory()->received()->create([
            'tracking_number' => 'DTS-2026-000125',
            'current_office' => 'Records Unit',
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => 'Forwarded',
        ]);
        foreach (['Records Unit', 'Administrative Unit'] as $office) {
            $document->movements()->create([
                'action' => 'sent',
                'from_office' => null,
                'to_office' => $office,
                'to_status' => 'Awaiting receipt',
            ]);
        }

        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-000125']))
            ->assertOk()
            ->assertSee('In progress')
            ->assertDontSee('Multiple office routes')
            ->assertSee('Assigned offices')
            ->assertSee('Assigned to Records Unit and Administrative Unit')
            ->assertSee('Received')
            ->assertSee('Forwarded');
    }

    public function test_target_date_shows_how_many_days_are_left_until_every_office_finishes(): void
    {
        $document = Document::factory()->received()->create([
            'tracking_number' => 'DTS-2026-000129',
            'due_at' => now('Asia/Manila')->subDays(2)->toDateString(),
        ]);

        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-000129']))
            ->assertOk()
            ->assertSee('Overdue by 2 days');

        $document->update(['due_at' => now('Asia/Manila')->addDay()->toDateString()]);
        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-000129']))
            ->assertSee('Due in 1 day');

        $document->officeAssignments()->update(['status' => 'Completed']);
        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-000129']))
            ->assertDontSee('data-due-badge', false);
    }

    public function test_office_routes_show_whether_each_office_has_finished(): void
    {
        $document = Document::factory()->received()->create([
            'tracking_number' => 'DTS-2026-000127',
            'current_office' => 'Records Unit',
        ]);
        $document->officeAssignments()->update(['status' => 'Completed']);
        $document->officeAssignments()->create(['office' => 'Administrative Unit', 'status' => 'Received']);
        $document->officeAssignments()->create(['office' => 'Legal Unit', 'status' => 'On Hold']);

        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-000127']))
            ->assertOk()
            ->assertSee('1 of 3 offices finished')
            ->assertSee('What do these statuses mean?')
            ->assertSee('The office has finished its part.')
            ->assertSee('Processing is paused for now.')
            ->assertSee('aria-valuenow="1"', false)
            ->assertSee('data-route-state="done" data-route-office="Records Unit"', false)
            ->assertSee('data-route-state="active" data-route-office="Administrative Unit"', false)
            ->assertSee('data-route-state="paused" data-route-office="Legal Unit"', false)
            ->assertSee('class="dts-route-link flex w-full items-center gap-3 px-3 py-2 text-inherit no-underline" data-timeline-office="Administrative Unit"', false)
            ->assertSee('Select an office to see its history.');

        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-000127', 'office' => 'Legal Unit']))
            ->assertOk()
            ->assertSee('data-timeline-office="Legal Unit" aria-current="true"', false)
            ->assertDontSee('data-timeline-office="Administrative Unit" aria-current="true"', false);
    }

    public function test_history_is_grouped_by_day_and_names_the_acting_office(): void
    {
        $this->travel(-3)->days();
        $document = Document::factory()->create(['tracking_number' => 'DTS-2026-000128']);
        $document->movements()->create([
            'action' => 'returned',
            'from_office' => 'Legal Unit',
            'to_office' => 'Records Unit',
            'from_status' => 'Received',
            'to_status' => 'Returned',
        ]);
        $this->travelBack();
        $document->movements()->create([
            'action' => 'on_hold',
            'from_office' => 'Records Unit',
            'to_office' => 'Records Unit',
            'from_status' => 'Returned',
            'to_status' => 'On Hold',
        ]);

        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-000128']))
            ->assertOk()
            ->assertSeeInOrder([
                'Today',
                'Records Unit placed it on hold',
                'Latest',
                'Status: On Hold',
                now('Asia/Manila')->subDays(3)->format('F j, Y'),
                'Legal Unit returned it to Records Unit',
                'Document registered',
            ])
            ->assertSee('data-kind="hold"', false)
            ->assertSee('data-kind="returned"', false);
    }

    public function test_public_timeline_can_show_movements_for_one_office(): void
    {
        $document = Document::factory()->create(['tracking_number' => 'DTS-2026-000126']);
        foreach (['Records Unit', 'Administrative Unit'] as $office) {
            $document->movements()->create([
                'action' => 'sent',
                'from_office' => null,
                'to_office' => $office,
                'to_status' => 'Awaiting receipt',
            ]);
        }
        $document->movements()->create([
            'action' => 'acknowledge',
            'from_office' => 'Administrative Unit',
            'to_office' => 'Administrative Unit',
            'to_status' => 'Received',
        ]);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number, 'office' => 'Records Unit']))
            ->assertOk()
            ->assertSee('View timeline by office')
            ->assertSee('<option value="Records Unit" selected>Records Unit</option>', false)
            ->assertSee('Assigned to Records Unit')
            ->assertDontSee('Assigned to Records Unit and Administrative Unit')
            ->assertSee('data-from-office="Administrative Unit" data-to-office="Administrative Unit" class="dts-public-timeline-item hidden', false)
            ->assertSee("timelineOffice.addEventListener('change'", false);
    }

    public function test_office_without_recorded_movements_has_a_clear_empty_timeline(): void
    {
        $document = Document::factory()->create([
            'tracking_number' => 'DTS-2026-000127',
            'current_office' => 'Records Unit',
        ]);
        $document->officeAssignments()->create([
            'office' => 'Administrative Unit',
            'status' => 'Awaiting receipt',
        ]);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number, 'office' => 'Administrative Unit']))
            ->assertOk()
            ->assertSee('<p id="timeline-empty" class=" mt-5', false)
            ->assertSee('No movements recorded for this office yet.');

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number, 'office' => 'Records Unit']))
            ->assertOk()
            ->assertSee('<li id="registration-event" class="dts-public-timeline-item grid', false)
            ->assertSee('Assigned to Records Unit')
            ->assertSee('<p id="timeline-empty" class="hidden', false);
    }

    public function test_unknown_tracking_number_returns_a_neutral_not_found_message(): void
    {
        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-999999']))
            ->assertOk()
            ->assertSee('No matching document')
            ->assertDontSee('Document movement');
    }

    public function test_invalid_tracking_number_is_rejected_without_searching(): void
    {
        Document::factory()->create(['tracking_number' => 'DTS-2026-000123']);

        $this->get(route('tracking.index', ['tracking' => "DTS-2026-000123' OR 1=1--"]))
            ->assertOk()
            ->assertSee('Enter a valid DTS tracking number.')
            ->assertDontSee('Document movement');
    }

    public function test_array_tracking_input_is_rejected_without_an_error_page(): void
    {
        $this->get(route('tracking.index').'?tracking[]=DTS-2026-000123')
            ->assertOk()
            ->assertSee('Enter a valid DTS tracking number.');
    }

    public function test_array_office_filter_is_ignored_without_an_error_page(): void
    {
        $document = Document::factory()->create(['tracking_number' => 'DTS-2026-000123']);

        $this->get(route('tracking.index', ['tracking' => $document->tracking_number]).'&office[]=Records%20Unit')
            ->assertOk()
            ->assertSee($document->tracking_number);
    }

    public function test_public_tracking_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->get(route('tracking.index', ['tracking' => 'DTS-2026-MISSING']))->assertOk();
        }

        $this->get(route('tracking.index', ['tracking' => 'DTS-2026-MISSING']))
            ->assertTooManyRequests();
    }
}
