{{-- tus-js-client 4.3.1 (MIT), published by scripts/publish-admin-vendor-assets.mjs. --}}
<script src="{{ versioned_asset('admin/assets/js/vendor/tus-js-client/tus.min.js') }}"></script>
@foreach(['records', 'transfer', 'queue', 'form'] as $bunnyUploadModule)
    @php($bunnyUploadFile = 'admin/assets/js/course-studio-bunny-upload-'.$bunnyUploadModule.'.js')
    <script src="{{ asset($bunnyUploadFile) }}?v={{ filemtime(public_path($bunnyUploadFile)) }}"></script>
@endforeach
<script>
document.addEventListener('DOMContentLoaded', function () {
    window.RoknBunnyUploadForm.create({
        ownerId: @json((string) auth()->id()),
        serverRejectedClaim: @json($errors->has('bunny_video_claim_terminal')),
    });
});
</script>
