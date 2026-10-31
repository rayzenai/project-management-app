<?php

/*
|--------------------------------------------------------------------------
| Attendance defaults
|--------------------------------------------------------------------------
|
| What an organization starts with when a super-admin turns attendance on;
| its admins change them afterwards on the settings page. Workdays are ISO
| weekdays (1 = Monday). A season overrides the hours between two MM-DD
| dates and may wrap the year end. Winter hours follow government offices
| (Kartik 16 to Magh 15, about 2 November to 29 January).
|
*/

return [
    'workdays' => [1, 2, 3, 4, 5],
    'start_time' => '10:00',
    'end_time' => '17:00',
    'seasons' => [
        ['name' => 'Winter', 'from' => '11-02', 'to' => '01-29', 'start' => '10:00', 'end' => '16:00'],
    ],
    'grace_minutes' => 15,
    'place_radius_m' => 150,
];
