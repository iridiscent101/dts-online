<?php

// Field names in validation messages, so staff read "The office name field is required." instead of "office.name".
// Laravel's own messages are kept; only these names are added.
return [
    'attributes' => [
        'office.name' => 'office name',
        'office.sort_order' => 'display order',
        'office.sdo_sort_order' => 'SDO display order',
        'office.client_sort_order' => 'client display order',
        'office.is_active' => 'active setting',
        'office.is_sdo' => 'SDO office setting',
        'office.is_client' => 'client office setting',
        'role.name' => 'role name',
        'role.slug' => 'role key',
        'role.description' => 'role description',
        'school.name' => 'school name',
        'user.name' => 'name',
        'user.email' => 'email address',
        'user.password' => 'password',
        'user.roles' => 'roles',
        'user.office_ids' => 'offices',
    ],
];
