<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Role;

use App\Models\User;
use App\Orchid\Fields\PermissionCheckBox;
use App\Orchid\Fields\PermissionGroupHeading;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Orchid\Screen\Field;
use Orchid\Screen\Layouts\Rows;
use Throwable;

class RolePermissionLayout extends Rows
{
    /**
     * @var User|null
     */
    private $user;

    /**
     * Full width, with the section title above it (no side column), so each permission fits on one line.
     *
     * @var string
     */
    protected $title = 'Permissions';

    /**
     * One short line per permission, always shown next to its checkbox.
     *
     * @var array<string, string>
     */
    private array $permissionSummaries = [
        'platform.index' => 'Sign in and work on their own office\'s documents.',
        'platform.documents.view-all' => 'See documents from every office (read only).',
        'platform.documents.edit' => 'Correct the details of documents they can open.',
        'platform.documents.routes.manage' => 'Change a document\'s first offices before work starts.',
        'platform.documents.attachments.manage' => 'Add, replace or delete document attachments.',
        'platform.kiosk' => 'Run the kiosk screen where recipients sign for documents.',
        'platform.systems.attachment' => 'Open the system attachment library.',
        'platform.systems.offices' => 'Add or edit offices used for users and routing.',
        'platform.systems.schools' => 'View and remove schools used as document origins.',
        'platform.systems.roles' => 'Add or edit roles and their permissions.',
        'platform.systems.users' => 'Add or edit user accounts, offices and roles.',
        'platform.systems.sign-in-activity' => 'See who signed in, and when.',
        'platform.systems.health' => 'Check that the system is running properly.',
    ];

    /**
     * The fine print, behind "More" for the permissions that have rules worth knowing.
     *
     * @var array<string, string>
     */
    private array $permissionDetails = [
        'platform.index' => 'Staff can view documents they registered or those assigned to their office. Routing requires an assigned office.',
        'platform.documents.view-all' => 'Includes every document\'s details, attachments and movement history. It does not allow editing or routing.',
        'platform.documents.edit' => 'Locked details need a reason to change. Only administrators can correct closed (finished) records.',
        'platform.documents.routes.manage' => 'Staff receive, forward and complete their own office\'s routes without this permission; it does not give access to other offices.',
    ];

    /**
     * The screen's layout elements.
     *
     *
     * @return Field[]
     *
     * @throws Throwable
     */
    public function fields(): array
    {
        $this->user = $this->query->get('user');

        return $this->generatedPermissionFields(
            $this->query->getContent('permission')
        );
    }

    private function generatedPermissionFields(Collection $permissionsRaw): array
    {
        return $permissionsRaw
            ->map(fn (Collection $permissions, $title) => $this->makeCheckBoxGroup($permissions, $title))
            ->flatten()
            ->toArray();
    }

    /**
     * A group heading with its "X of Y on" count (kept current by dts.js), then one permission per row.
     */
    private function makeCheckBoxGroup(Collection $permissions, string $title): Collection
    {
        $groupKey = 'permission-group-'.Str::slug($title);

        return $permissions
            ->map(fn (array $chunks) => $this->makeCheckBox(collect($chunks), $groupKey))
            ->values()
            ->prepend(PermissionGroupHeading::make($groupKey)
                ->set('groupTitle', $title)
                ->set('groupKey', $groupKey)
                ->set('groupTotal', $permissions->count())
                ->set('groupActive', $permissions->filter(fn (array $permission): bool => (bool) $permission['active'])->count()));
    }

    private function makeCheckBox(Collection $chunks, string $groupKey): PermissionCheckBox
    {
        $slug = (string) $chunks->get('slug');
        // Non-administrators can only grant permissions they hold themselves; the server rejects anything else.
        $editor = request()->user();
        $locked = $editor && ! $editor->isAdministrator() && ! $editor->hasAccess($slug);

        return PermissionCheckBox::make('permissions.'.base64_encode($slug))
            ->placeholder($chunks->get('description'))
            ->set('permissionDescription', $this->summaryFor($slug).($locked ? ' Only an administrator can grant this.' : ''))
            ->set('permissionDetail', $this->permissionDetails[$slug] ?? null)
            ->set('permissionGroup', $groupKey)
            ->disabled($locked)
            ->value($chunks->get('active'))
            ->sendTrueOrFalse()
            ->indeterminate($this->getIndeterminateStatus(
                $slug,
                $chunks->get('active')
            ));
    }

    private function summaryFor(string $slug): string
    {
        return $this->permissionSummaries[$slug] ?? 'Controls access to this permission area.';
    }

    private function getIndeterminateStatus($slug, $value): bool
    {
        return optional($this->user)->hasAccess($slug) === true && $value === false;
    }
}
