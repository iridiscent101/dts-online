<?php

namespace App\Models;

use App\Notifications\DocumentAssignedMailNotification;
use App\Notifications\VerifyNotificationEmail;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Filters\Types\WhereDateStartEnd;
use Orchid\Platform\Models\Role;
use Orchid\Platform\Models\User as Authenticatable;

class User extends Authenticatable
{
    public const ADMIN_ROLE_SLUG = 'Admin';

    protected static function booted(): void
    {
        static::deleted(function (User $user): void {
            $user->notifications()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'notification_email',
        'password',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'permissions',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'permissions' => 'array',
        'email_verified_at' => 'datetime',
        'notification_email_verified_at' => 'datetime',
    ];

    /**
     * The attributes for which you can use filters in url.
     *
     * @var array
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'name' => Like::class,
        'email' => Like::class,
        'updated_at' => WhereDateStartEnd::class,
        'created_at' => WhereDateStartEnd::class,
    ];

    /**
     * The attributes for which can use sort in url.
     *
     * @var array
     */
    protected $allowedSorts = [
        'id',
        'name',
        'email',
        'updated_at',
        'created_at',
    ];

    public function isAdministrator(): bool
    {
        return $this->inRole(self::ADMIN_ROLE_SLUG);
    }

    public function routeNotificationForMail(Notification $notification): ?string
    {
        return $notification instanceof DocumentAssignedMailNotification
            ? $this->notification_email
            : $this->email;
    }

    public function setNotificationEmail(?string $address): bool
    {
        $address = filled($address) ? Str::lower(trim($address)) : null;

        if ($this->notification_email !== $address) {
            $this->notification_email = $address;
            $this->notification_email_verified_at = null;
        }

        return $address !== null && $this->notification_email_verified_at === null;
    }

    public function sendNotificationEmailVerification(): void
    {
        $email = $this->notification_email;
        $verification = new VerifyNotificationEmail($this->getKey(), hash('sha256', $email));

        if (config('queue.default') === 'sync') {
            DB::afterCommit(fn () => rescue(fn () => NotificationFacade::route('mail', $email)->notify($verification)));

            return;
        }

        NotificationFacade::route('mail', $email)->notify($verification);
    }

    public function canManageRole(Role $role): bool
    {
        return $this->isAdministrator() || ($this->hasAccess('platform.systems.roles')
            && $role->slug !== self::ADMIN_ROLE_SLUG
            && ! collect($role->permissions ?? [])->filter()->keys()
                ->contains(fn (string $permission): bool => ! $this->hasAccess($permission)));
    }

    public function isReadOnlySupervisor(): bool
    {
        return ! $this->isAdministrator() && $this->inRole('supervisor');
    }

    /**
     * The Records Unit receives every document and sends it on, so like administrators it follows each document's
     * whole history and timeline, not only the entries that involve its own office.
     */
    public function canViewFullDocumentHistory(): bool
    {
        return $this->isAdministrator() || $this->isAssignedToOffice('SDO-Records Unit');
    }

    public function canConfirmPaperIntake(): bool
    {
        return ! $this->isReadOnlySupervisor()
            && $this->hasAccess('platform.index')
            && ($this->isAdministrator() || $this->isAssignedToOffice('SDO-Records Unit'));
    }

    public function canRegisterDocument(): bool
    {
        return ! $this->isReadOnlySupervisor()
            && $this->hasAccess('platform.index')
            && ($this->isAdministrator() || $this->assignedOfficeNames()->intersect(['SDO-Records Unit', 'Records Unit'])->isNotEmpty());
    }

    public function offices(): BelongsToMany
    {
        return $this->belongsToMany(Office::class)->withTimestamps();
    }

    /**
     * @return Collection<int, string>
     */
    public function assignedOfficeNames(): Collection
    {
        $officeNames = $this->relationLoaded('offices')
            ? $this->offices->pluck('name')
            : $this->offices()->pluck('name');

        return $officeNames->filter()->unique()->values();
    }

    public function isAssignedToOffice(?string $office): bool
    {
        return filled($office) && $this->assignedOfficeNames()->contains($office);
    }

    public function hasAccess(string $permit, bool $cache = true): bool
    {
        return $this->isAdministrator() || parent::hasAccess($permit, $cache);
    }
}
