@include('errors.partials.error-page-shell', [
    'statusCode' => 500,
    'pageTitle' => 'taskLyst · 500',
    'label' => __('Server Error'),
    'heading' => __('Something went wrong on our side.'),
    'message' => __('Your data is safe. Please try again shortly.'),
])
