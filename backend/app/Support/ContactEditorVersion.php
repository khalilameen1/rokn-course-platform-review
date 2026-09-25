<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Contact;

final class ContactEditorVersion
{
    public static function for(Contact $contact): string
    {
        return AdminEditorVersion::for($contact, [
            'request_type', 'email', 'read', 'resolution_status', 'resolved_at',
            'resolved_by', 'resolved_user_id', 'resolution_metadata', 'updated_at',
        ]);
    }
}
