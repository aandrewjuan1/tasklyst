@include('errors.partials.error-page-shell', [
    'statusCode' => 404,
    'pageTitle' => 'taskLyst · 404',
    'label' => __('Page Not Found'),
    'heading' => __('We could not find that page.'),
    'message' => __('The link may be outdated, or the page may have moved.'),
])
