<?php
/**
 * logbook.php — Logbook domain helpers.
 *
 * Defines the log types and their type-specific fields. The add form,
 * list view, and detail view are all data-driven from logbook_types()
 * so adding a new log type is a single array entry here.
 *
 * Type-specific fields are stored in logbook_entries.meta_json; the
 * shared columns (occurred_at, student_id, details, parent_notified,
 * photo) live on the row itself.
 */
declare(strict_types=1);

/**
 * Every log type, in display order. Each defines:
 *   - label    : human name
 *   - icon     : emoji for the list/picker
 *   - student  : 'required' | 'optional' | 'none' — links to a student
 *   - notify   : true if the "parent notified" flag is relevant
 *   - photo    : true if a photo attachment makes sense
 *   - fields   : type-specific meta fields [key => [label, type, options?]]
 *                type: text | textarea | tel | number | time | select
 */
function logbook_types(): array
{
    return [
        'visitor' => [
            'label' => 'Visitor', 'icon' => '🚪', 'student' => 'none', 'notify' => false, 'photo' => true,
            'fields' => [
                'visitor_name' => ['label' => 'Visitor name', 'type' => 'text'],
                'phone'        => ['label' => 'Phone', 'type' => 'tel'],
                'purpose'      => ['label' => 'Purpose of visit', 'type' => 'text'],
                'visiting'     => ['label' => 'Visiting (person / dept)', 'type' => 'text'],
                'id_shown'     => ['label' => 'ID shown', 'type' => 'select', 'options' => ['', 'Aadhaar', 'Driving licence', 'Other govt ID', 'None']],
                'vehicle'      => ['label' => 'Vehicle no.', 'type' => 'text'],
                'in_time'      => ['label' => 'In time', 'type' => 'time'],
                'out_time'     => ['label' => 'Out time', 'type' => 'time'],
                'authorised_by'=> ['label' => 'Authorised by (staff)', 'type' => 'text'],
            ],
        ],
        'incident' => [
            'label' => 'Incident / Accident', 'icon' => '🩹', 'student' => 'optional', 'notify' => true, 'photo' => true,
            'fields' => [
                'what_happened'  => ['label' => 'What happened', 'type' => 'textarea'],
                'location'       => ['label' => 'Where (room / area)', 'type' => 'text'],
                'injury'         => ['label' => 'Injury / body part', 'type' => 'text'],
                'action_taken'   => ['label' => 'Action / first aid given', 'type' => 'textarea'],
                'witness'        => ['label' => 'Witness (staff)', 'type' => 'text'],
                'severity'       => ['label' => 'Severity', 'type' => 'select', 'options' => ['', 'Minor', 'Moderate', 'Serious']],
            ],
        ],
        'observation' => [
            'label' => 'Observation', 'icon' => '🔭', 'student' => 'required', 'notify' => false, 'photo' => true,
            'fields' => [
                'area'       => ['label' => 'Area of work', 'type' => 'select', 'options' => ['', 'Practical Life', 'Sensorial', 'Language', 'Mathematics', 'Culture', 'Social / Emotional', 'Gross Motor']],
                'milestone'  => ['label' => 'Milestone / progress', 'type' => 'textarea'],
                'concern'    => ['label' => 'Concern (if any)', 'type' => 'textarea'],
            ],
        ],
        'pickup' => [
            'label' => 'Pickup / Handover', 'icon' => '🤝', 'student' => 'required', 'notify' => false, 'photo' => false,
            'fields' => [
                'collected_by'  => ['label' => 'Collected by', 'type' => 'text'],
                'relationship'  => ['label' => 'Relationship to child', 'type' => 'text'],
                'authorised_by' => ['label' => 'Authorised by (parent)', 'type' => 'text'],
                'id_checked'    => ['label' => 'ID checked', 'type' => 'select', 'options' => ['', 'Yes', 'No — known person']],
                'pickup_time'   => ['label' => 'Pickup time', 'type' => 'time'],
            ],
        ],
        'health' => [
            'label' => 'Health check', 'icon' => '🌡️', 'student' => 'required', 'notify' => true, 'photo' => false,
            'fields' => [
                'temperature' => ['label' => 'Temperature (°F)', 'type' => 'number'],
                'symptoms'    => ['label' => 'Symptoms observed', 'type' => 'textarea'],
                'action'      => ['label' => 'Action taken', 'type' => 'text'],
            ],
        ],
        'medication' => [
            'label' => 'Medication', 'icon' => '💊', 'student' => 'required', 'notify' => true, 'photo' => true,
            'fields' => [
                'medicine'   => ['label' => 'Medicine', 'type' => 'text'],
                'dose'       => ['label' => 'Dose', 'type' => 'text'],
                'time_given' => ['label' => 'Time given', 'type' => 'time'],
                'authorised' => ['label' => 'Authorised by parent', 'type' => 'select', 'options' => ['', 'Yes — written', 'Yes — verbal', 'No']],
            ],
        ],
        'cleaning' => [
            'label' => 'Cleaning / Sanitation', 'icon' => '🧹', 'student' => 'none', 'notify' => false, 'photo' => false,
            'fields' => [
                'area'  => ['label' => 'Area cleaned', 'type' => 'text'],
                'tasks' => ['label' => 'Tasks done', 'type' => 'textarea'],
            ],
        ],
        'drill' => [
            'label' => 'Safety drill', 'icon' => '🧯', 'student' => 'none', 'notify' => false, 'photo' => true,
            'fields' => [
                'drill_type'   => ['label' => 'Drill type', 'type' => 'select', 'options' => ['', 'Fire', 'Evacuation', 'Earthquake', 'Lockdown']],
                'duration_min' => ['label' => 'Duration (min)', 'type' => 'number'],
                'attendance'   => ['label' => 'Children present', 'type' => 'number'],
                'notes'        => ['label' => 'Notes / issues', 'type' => 'textarea'],
            ],
        ],
        'maintenance' => [
            'label' => 'Maintenance', 'icon' => '🔧', 'student' => 'none', 'notify' => false, 'photo' => true,
            'fields' => [
                'area'   => ['label' => 'Area / equipment', 'type' => 'text'],
                'issue'  => ['label' => 'Issue', 'type' => 'textarea'],
                'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['Reported', 'In progress', 'Resolved']],
            ],
        ],
    ];
}

function logbook_type(string $code): ?array
{
    return logbook_types()[$code] ?? null;
}

function logbook_type_label(string $code): string
{
    return logbook_types()[$code]['label'] ?? ucfirst($code);
}

function logbook_type_icon(string $code): string
{
    return logbook_types()[$code]['icon'] ?? '📝';
}

/** Decode meta_json safely to an array. */
function logbook_meta(?string $json): array
{
    if (!$json) return [];
    $d = json_decode($json, true);
    return is_array($d) ? $d : [];
}
