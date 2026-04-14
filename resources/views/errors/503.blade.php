@include('errors.partials.error-page-shell', [
    'statusCode' => 503,
    'pageTitle' => 'taskLyst · 503',
    'label' => __('Service Unavailable'),
    'heading' => __('Tasklyst is temporarily unavailable.'),
    'message' => __('We are likely applying updates or recovering service. Please check back soon.'),
])
