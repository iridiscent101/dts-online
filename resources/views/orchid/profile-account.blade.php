{{-- My Account: read-only summary of what the signed-in person can do. Offices and roles are set by an administrator. --}}
@php
    $offices = $user->assignedOfficeNames();
    $roles = $user->roles->pluck('name');
    $abilities = array_values(array_filter([
        match (true) {
            $user->isAdministrator() => 'See and manage all documents',
            $user->isReadOnlySupervisor() => 'View documents (no changes)',
            $user->hasAccess('platform.documents.view-all') => 'View all documents',
            default => 'Work on documents assigned to your offices',
        },
        $user->canConfirmPaperIntake() ? 'Confirm physical document/s received by the Records Unit' : null,
        $user->isAdministrator() ? 'Manage users, roles, offices and schools' : null,
    ]));
@endphp
<div class="dts-profile-account">
    <dl class="dts-profile-account-grid">
        <div>
            <dt>{{ $offices->count() === 1 ? 'Office' : 'Offices' }}</dt>
            <dd class="dts-profile-chips">
                @forelse ($offices as $office)
                    <span class="dts-profile-chip"><x-orchid-icon path="bs.building" /> {{ $office }}</span>
                @empty
                    <span class="dts-muted">Not assigned to an office yet</span>
                @endforelse
            </dd>
        </div>
        <div>
            <dt>{{ $roles->count() === 1 ? 'Role' : 'Roles' }}</dt>
            <dd class="dts-profile-chips">
                @forelse ($roles as $role)
                    <span class="dts-profile-chip"><x-orchid-icon path="bs.person-badge" /> {{ $role }}</span>
                @empty
                    <span class="dts-muted">No role yet</span>
                @endforelse
            </dd>
        </div>
        <div>
            <dt>What you can do</dt>
            <dd>
                <ul class="dts-profile-abilities">
                    @foreach ($abilities as $ability)
                        <li><x-orchid-icon path="bs.check-circle-fill" /> {{ $ability }}</li>
                    @endforeach
                </ul>
            </dd>
        </div>
        <div>
            <dt>Account created</dt>
            <dd>{{ $user->created_at?->timezone('Asia/Manila')->format('F j, Y') ?? '—' }}</dd>
        </div>
    </dl>
    <p class="dts-profile-account-note"><x-orchid-icon path="bs.info-circle" /> Ask a system administrator if your office or role is wrong.</p>
</div>
