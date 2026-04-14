@include('errors.partials.error-page-shell', [
    'statusCode' => 419,
    'pageTitle' => 'taskLyst · 419',
    'label' => __('Session Expired'),
    'heading' => __('Your session has expired.'),
    'message' => __('Please refresh and try again so we can safely continue your work.'),
])
