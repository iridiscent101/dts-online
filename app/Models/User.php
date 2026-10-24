<?php

namespace App\Models;

use App\Auth\AccountActivity;
use App\Notifications\VerifyNotificationEmail;
use App\Support\SendAfterResponse;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
use Spatie\Activitylog\Models\Activity;

class User extends Authenticatable
{
    use SoftDeletes;

    public const ADMIN_ROLE_SLUG = 'Admin';

    // The password rule itself is Password::defaults() in AppServiceProvider.
    public const PASSWORD_MIN_LENGTH = 13;

    public const PASSWORD_HINT = 'At least 13 characters, with a capital letter, a small letter, a number and a symbol (for example ! @ # $).';

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
        'deactivated_at' => 'datetime',
        'must_change_password' => 'boolean',
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

    /**
     * The kiosk laptop's account (only "Signing kiosk") never reaches My Account, so it is not asked to change its
     * password; an administrator sets that one.
     */
    public function canChangeOwnPassword(): bool
    {
        return $this->hasAccess('platform.index') || ! $this->hasAccess('platform.kiosk');
    }

    /**
     * Whether the forced password change comes from a temporary password (forgot password) rather than the stricter
     * password rule: the latest of those account events decides.
     */
    public function isUsingTemporaryPassword(): bool
    {
        return $this->passwordChangeReason() === 'temporary';
    }

    /**
     * Why the person must set a new password, from the latest of those account events: 'temporary' (forgot password),
     * 'administrator' (an administrator set it), 'rule' (the stricter password rule), or null when no change is due.
     */
    public function passwordChangeReason(): ?string
    {
        if (! $this->must_change_password) {
            return null;
        }

        $latest = Activity::query()
            ->where('log_name', AccountActivity::LOG_NAME)
            ->where('subject_type', $this->getMorphClass())
            ->where('subject_id', $this->getKey())
            ->whereIn('event', [AccountActivity::TEMPORARY_PASSWORD_SENT, AccountActivity::PASSWORD_CHANGE_REQUIRED, AccountActivity::PASSWORD_CHANGED])
            ->latest('id')
            ->first();

        return match (true) {
            $latest?->event === AccountActivity::TEMPORARY_PASSWORD_SENT => 'temporary',
            $latest?->event === AccountActivity::PASSWORD_CHANGED && (bool) data_get($latest->properties, 'set_by_admin') => 'administrator',
            default => 'rule',
        };
    }

    public function isDeactivated(): bool
    {
        return $this->deactivated_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('deactivated_at'));
    }

    /**
     * Blocks sign-in and ends every open session (including "Remember me") at once. Documents, history and office
     * memberships stay as they are, so the account can be reactivated later.
     */
    public function deactivate(User $by): void
    {
        DB::transaction(function () use ($by): void {
            $this->forceFill(['deactivated_at' => now(), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $this->id)->delete();
            AccountActivity::record(AccountActivity::DEACTIVATED, $this, $by);
        });
    }

    public function reactivate(User $by): void
    {
        DB::transaction(function () use ($by): void {
            $this->forceFill(['deactivated_at' => null])->save();
            AccountActivity::record(AccountActivity::REACTIVATED, $this, $by);
        });
    }

    public function isAdministrator(): bool
    {
        return $this->inRole(self::ADMIN_ROLE_SLUG);
    }

    public function routeNotificationForMail(Notification $notification): ?string
    {
        // A reset link goes only to the address the staff member confirmed, never to an unchecked one.
        return $notification instanceof ResetPassword
            ? $this->notification_email
            : $this->email;
    }

    public function setNotificationEmail(?string $address): bool
    {
        $address = filled($address) ? Str::lower(trim($address)) : null;

        if ($this->notification_email !== $address) {
            $this->notification_email = $address;
            $this->notification_email_verified_at = null;
            DB::table('password_reset_tokens')->where('email', $this->email)->delete();
        }

        return $address !== null && $this->notification_email_verified_at === null;
    }

    public function sendNotificationEmailVerification(): void
    {
        $email = $this->notification_email;
        $verification = new VerifyNotificationEmail($this->getKey(), hash('sha256', $email));

        // Sent after the profile is saved and the page has answered; no queue worker needed.
        SendAfterResponse::mail(fn () => NotificationFacade::route('mail', $email)->notifyNow($verification));
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
