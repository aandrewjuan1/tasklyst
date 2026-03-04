<?php

it('has tests/unit/connectcalendarfeedjob page', function () {
    $response = $this->get('/tests/unit/connectcalendarfeedjob');

    $response->assertStatus(200);
});
