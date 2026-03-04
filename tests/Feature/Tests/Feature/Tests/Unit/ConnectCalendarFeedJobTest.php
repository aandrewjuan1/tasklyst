<?php

it('has tests/feature/tests/unit/connectcalendarfeedjob page', function () {
    $response = $this->get('/tests/feature/tests/unit/connectcalendarfeedjob');

    $response->assertStatus(200);
});
