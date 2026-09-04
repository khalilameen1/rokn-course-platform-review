@foreach([
    'course-studio-core.js',
    'course-studio-outline.js',
    'course-studio-editor-coordinator.js',
    'course-studio-section-editor.js',
    'course-studio-module-editor.js',
    'course-studio-details.js',
    'course-studio-attachments.js',
    'course-studio.js',
] as $courseStudioFile)
    @php($courseStudioAsset = public_path('admin/assets/js/'.$courseStudioFile))
    <script src="{{ asset('admin/assets/js/'.$courseStudioFile) }}?v={{ is_file($courseStudioAsset) ? filemtime($courseStudioAsset) : '1' }}"></script>
@endforeach
