@include('errors.partials.error-page-shell', [
    'statusCode' => 403,
    'pageTitle' => 'taskLyst · 403',
    'label' => __('Access Required'),
    'heading' => __('You do not have permission to view this page.'),
    'message' => __('This area may belong to another user, workspace, or role.'),
])
