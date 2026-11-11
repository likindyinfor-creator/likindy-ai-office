<?php

return [
    'invitation_expiration_days' => max(1, (int) env('WORKSPACE_INVITATION_EXPIRATION_DAYS', 7)),
];
