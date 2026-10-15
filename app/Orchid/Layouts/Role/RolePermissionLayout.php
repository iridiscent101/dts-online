<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Role;

use App\Models\User;
use App\Orchid\Fields\PermissionCheckBox;
use Illuminate\Support\Collection;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Label;
use Orchid\Screen\Layouts\Rows;
use Throwable;

class RolePermissionLayout extends Rows
{
    /**
     * @var User|null
     */
    private $user;

    /**
     * @var array<string, string>
     */
    private array $permissionDescriptions = [
        'platform.index' => 'Use the DTS. Staff can view documents they registered or those assigned to their office. Routing requires an assigned office.',
        'platform.documents.view-all' => 'View documents from every office, including their details, attachments, and movement history. This does not grant editing or routing.',
        'platform.documents.edit' => 'Change document details such as title, type, sender and dates.',
        'platform.documents.routes.manage' => 'Add or remove the offices a registered document is routed to while editing it.',
        'platform.documents.attachments.manage' => 'Add, replace, or delete document attachments.',
        'platform.systems.attachment' => 'Open the system attachment library.',
        'platform.systems.offices' => 'Add or edit offices used for users and routing.',
        'platform.systems.schools' => 'View and remove schools used for document origins.',
        'platform.systems.roles' => 'Add or edit roles and their permissions.',
        'platform.systems.users' => 'Add or edit user accounts, offices, and roles.',
        'platform.systems.health' => 'Check if the system is running properly.',
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

    private function makeCheckBoxGroup(Collection $permissions, string $title): Collection
    {
        // The section title gets its own row so it does not push the first checkbox below its neighbour.
        return $permissions
            ->map(fn (array $chunks) => $this->makeCheckBox(collect($chunks)))
            ->flatten()
            ->chunk(4)
            ->map(fn (Collection $checkboxes) => Group::make($checkboxes->toArray())
                ->alignEnd()
                ->autoWidth())
            ->prepend(Label::make()->title($title));
    }

    private function makeCheckBox(Collection $chunks): PermissionCheckBox
    {
        $slug = (string) $chunks->get('slug');
        // Non-administrators can only grant permissions they hold themselves; the server rejects anything else.
        $editor = request()->user();
        $locked = $editor && ! $editor->isAdministrator() && ! $editor->hasAccess($slug);

        return PermissionCheckBox::make('permissions.'.base64_encode($slug))
            ->placeholder($chunks->get('description'))
            ->set('permissionDescription', $this->descriptionFor($slug).($locked ? ' Only an administrator can grant this.' : ''))
            ->disabled($locked)
            ->value($chunks->get('active'))
            ->sendTrueOrFalse()
            ->indeterminate($this->getIndeterminateStatus(
                $slug,
                $chunks->get('active')
            ));
    }

    private function descriptionFor(string $slug): string
    {
        return $this->permissionDescriptions[$slug] ?? 'Controls access to this permission area.';
    }

    private function getIndeterminateStatus($slug, $value): bool
    {
        return optional($this->user)->hasAccess($slug) === true && $value === false;
    }
}
