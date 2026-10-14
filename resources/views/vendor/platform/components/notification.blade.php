@php($notificationCount = count($notifications))

<a href="{{ route('platform.notifications') }}"
   class="d-flex align-items-center btn btn-link position-relative px-1 py-0 link-body-emphasis"
   data-dts-notification
   data-dts-notification-count="{{ $notificationCount }}"
   data-dts-notification-url="{{ route('platform.api.notifications') }}"
   data-dts-notification-interval="{{ config('platform.notifications.interval') }}"
   aria-label="Notifications">
    <x-orchid-icon path="bs.bell" />
    <span class="position-absolute top-0 start-100 translate-middle badge rounded-circle bg-danger lh-1"
          data-dts-notification-badge
          @if ($notificationCount === 0) hidden @endif>{{ $notificationCount > 9 ? '9+' : $notificationCount }}</span>
</a>
