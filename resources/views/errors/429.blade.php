@include('errors.partials.error-page-shell', [
    'statusCode' => 429,
    'pageTitle' => 'taskLyst · 429',
    'label' => __('Too Many Requests'),
    'heading' => __('You are doing that a bit too quickly.'),
    'message' => __('Give it a moment, then try again. We are protecting system stability.'),
])
