<link rel="stylesheet" href="{{ versioned_asset($stylesheet) }}">
<meta name="admin-identity-theme"
      content="{{ $designSettings->color_1 ?? '#2563eb' }}"
      data-secondary="{{ $designSettings->color_2 ?? '#16a34a' }}"
      data-neutral="{{ $designSettings->color_3 ?? '#f5f7fa' }}"
      data-accent="{{ $designSettings->color_4 ?? '#f97316' }}">

@once
    @push('scripts')
        <script src="{{ versioned_asset('admin/assets/js/admin-identity-theme.js') }}"></script>
    @endpush
@endonce

