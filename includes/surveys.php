<?php
/**
 * includes/surveys.php — parent surveys: question definitions, storage, and the
 * flattening that turns a response into spreadsheet columns.
 *
 * Shape of the thing:
 *
 *   A *spec* is the questionnaire itself, defined in code below (not in the
 *   database). Questions change once a year and are reviewed like any other
 *   change; keeping them in PHP means the wording, the option lists and the
 *   validation can never drift apart, and a typo is a diff rather than a
 *   silent data problem.
 *
 *   A *survey* is one live run of a spec — a row in `surveys` carrying the
 *   shareable token. One link, shared with every parent; there's no per-parent
 *   token because parents identify themselves on the form.
 *
 *   A *response* is one submission — a row in `survey_responses`. Parent name,
 *   child name and class get their own columns (they're what you search and
 *   sort by); everything else is stored as JSON in `answers`.
 *
 * Why JSON rather than a row per answer: the answers are only ever read as a
 * whole response, the questionnaire changes each year, and a key/value answer
 * table would need a join and a pivot to produce the grid the office actually
 * wants. survey_columns() does that pivot in PHP instead, driven by the same
 * spec that rendered the form — so the grid and the CSV can't fall out of step
 * with the questions.
 *
 * Schema: sql/migrate_054_parent_surveys.sql
 */
declare(strict_types=1);

const SURVEY_TEXT_MAX  = 2000;   // per free-text answer
const SURVEY_NAME_MAX  = 120;
const SURVEY_OTHER_MAX = 200;

// ---- Shared answer scales ------------------------------------------------

/** The 5-point agreement scale used by the two matrix questions. */
function survey_agree_scale(): array
{
    return [
        'strongly_disagree' => 'Strongly Disagree',
        'disagree'          => 'Disagree',
        'neutral'           => 'Neutral',
        'agree'             => 'Agree',
        'strongly_agree'    => 'Strongly Agree',
    ];
}

/** The 4-point confidence scale. */
function survey_confidence_scale(): array
{
    return [
        'not_confident'      => 'Not Confident',
        'somewhat_confident' => 'Somewhat Confident',
        'confident'          => 'Confident',
        'very_confident'     => 'Very Confident',
    ];
}

/**
 * Class options come from the grade config (/grades.php), not a frozen list,
 * so a new grade is offered to parents the day it's added and nobody has to
 * remember this file exists. Falls back to the four school classes if the
 * grade table isn't reachable — a public form must still render.
 */
function survey_class_options(): array
{
    $fallback = ['Playgroup', 'Nursery', 'Junior KG', 'Senior KG'];
    if (!function_exists('grade_names')) return array_combine($fallback, $fallback);
    try {
        $names = grade_names();
    } catch (Throwable $e) {
        $names = [];
    }
    if (!$names) $names = $fallback;
    return array_combine($names, $names);
}

// ---- The questionnaires --------------------------------------------------

/**
 * Every questionnaire the app knows, keyed by spec key. The key is stored on
 * the survey row, so responses stay attached to the questions that produced
 * them even after a later edition is added alongside.
 */
function survey_specs(): array
{
    return [
        'orientation_2026_27' => survey_spec_orientation_2026_27(),
        'field_trip'          => survey_spec_field_trip(),
    ];
}

/** One spec by key, or null. PHP catalogue wins; then DB definitions. */
function survey_spec(string $key): ?array
{
    $php = survey_specs()[$key] ?? null;
    if ($php !== null) return $php;
    return survey_definition_load($key);
}

/**
 * Every known spec for the admin list: PHP first, then DB definitions whose
 * keys are not already covered by PHP (PHP always wins on collision).
 *
 * Each entry: ['spec' => array, 'source' => 'code'|'mcp']
 */
function survey_all_specs(): array
{
    $out = [];
    foreach (survey_specs() as $key => $spec) {
        $out[$key] = ['spec' => $spec, 'source' => 'code'];
    }
    foreach (survey_definition_list() as $row) {
        $key = (string)$row['spec_key'];
        if (isset($out[$key])) continue;
        $spec = survey_definition_decode((string)$row['definition']);
        if ($spec === null) continue;
        $out[$key] = ['spec' => $spec, 'source' => 'mcp'];
    }
    return $out;
}

/** True when this key is owned by PHP and must not be written via MCP/DB. */
function survey_spec_is_php(string $key): bool
{
    return array_key_exists($key, survey_specs());
}

/**
 * Parent Voice Survey — Parent Orientation 2026–27.
 *
 * The questions track the orientation deck section by section, so a low score
 * points at a specific slide rather than at "the orientation" in general.
 * Where the deck asks the room a question out loud ("Where do you feel
 * pressure about your child's pace?", "What would make this a meaningful next
 * step — or a clear no?", "What would help you feel confident about the year
 * ahead?"), the same question is repeated here for the parents who don't speak
 * up in a hall.
 *
 * Only the three identifying fields are required. Everything else is optional
 * on purpose: a long form with mandatory questions gets abandoned halfway and
 * a half-finished form saves nothing at all, so partial answers beat no
 * answers. The form also autosaves to the parent's own device as they type
 * (see survey.php), so a dropped connection doesn't cost them the whole thing.
 */
function survey_spec_orientation_2026_27(): array
{
    $agree = survey_agree_scale();
    $conf  = survey_confidence_scale();

    return [
        'key'      => 'orientation_2026_27',
        'title'    => 'Little Graduates Parent Voice Survey',
        'subtitle' => 'Parent Orientation 2026–27',
        'intro'    => "Thank you for attending today's Parent Orientation.\n\n"
                    . "Your feedback is invaluable in helping us strengthen our partnership with "
                    . "families and provide the best possible learning experience for every child. "
                    . "This survey takes about 5–7 minutes.\n\n"
                    . "Only your name, your child's name and their class are required — answer as "
                    . "much or as little of the rest as you like. Your answers are saved on this "
                    . "device as you go, so you can stop and come back.",
        'thanks'   => "Thank you for taking the time to share your thoughts. Your feedback is "
                    . "deeply valued and will help us create an even better learning experience "
                    . "for every Little Graduate and their family.",
        'sections' => [
            [
                'title' => 'Parent details',
                'questions' => [
                    ['key' => 'parent_name', 'type' => 'text', 'label' => 'Parent Name', 'required' => true],
                    ['key' => 'child_name',  'type' => 'text', 'label' => "Child's Name", 'required' => true],
                    ['key' => 'class',       'type' => 'radio', 'label' => 'Class', 'required' => true,
                     'options' => 'classes'],
                ],
            ],
            [
                'title' => '1. Orientation experience',
                'questions' => [
                    ['key' => 'overall', 'type' => 'radio',
                     'label' => "Overall, how would you rate today's Parent Orientation?",
                     'options' => [
                         'excellent'         => '★★★★★ Excellent',
                         'very_good'         => '★★★★ Very Good',
                         'good'              => '★★★ Good',
                         'fair'              => '★★ Fair',
                         'needs_improvement' => '★ Needs Improvement',
                     ]],
                    ['key' => 'understand', 'type' => 'matrix', 'short' => 'Understood',
                     'label' => 'The orientation helped me understand the following:',
                     'scale' => $agree,
                     'rows'  => [
                         'vision'       => 'Our vision and the five foundations',
                         'early_years'  => 'Why the early years matter — most brain development happens before six',
                         'play_based'   => 'Why we are play-based and Montessori rather than traditional teaching',
                         'routine'      => 'The daily rhythm and the prepared learning environment',
                         'independence' => 'How independence is built, one small responsibility at a time',
                         'partnership'  => 'Parent partnership — the family habits and why our policies exist',
                         'cuepilot'     => 'CuePilot — which channel to use for which kind of message',
                         'safety'       => 'Health, safety and authorised handover at pickup',
                         'year_round'   => 'Year-round learning (no summer break)',
                         'next_chapter' => 'The idea of continuing beyond the early years',
                     ]],
                ],
            ],
            [
                'title' => '2. Your confidence',
                'questions' => [
                    ['key' => 'confidence', 'type' => 'matrix', 'short' => 'Confidence',
                     'label' => "After today's orientation, how confident do you feel about the following?",
                     'scale' => $conf,
                     'rows'  => [
                         'sending'    => 'Sending my child to school',
                         'policies'   => "Following the school's policies and routines",
                         'cuepilot'   => 'Using CuePilot and knowing who to contact',
                         'supporting' => "Supporting my child's learning at home",
                         'pace'       => "Trusting my child's own pace without pushing",
                     ]],
                ],
            ],
            [
                'title' => '3. Your thoughts',
                'questions' => [
                    ['key' => 'valuable', 'type' => 'checkbox', 'other' => true,
                     'label' => '3.1 Which part of today did you find most valuable?',
                     'help'  => 'Select all that apply.',
                     'options' => [
                         'welcome'      => 'Welcome & thank you to the team',
                         'vision'       => 'Our vision and the five foundations',
                         'traditional'  => 'Why traditional learning is not enough',
                         'early_years'  => 'Why the early years matter',
                         'experience'   => 'Experience Little Graduates — the day, the spaces, independence, enrichment',
                         'partnership'  => 'Parent partnership — habits, policies, communication, safety',
                         'year_round'   => 'Year-round learning (no summer break)',
                         'kitchen'      => 'The Little Graduates Kitchen',
                         'daycare'      => 'Daycare — the afternoon programme',
                         'next_chapter' => 'What comes next — continuing beyond the early years',
                         'community'    => 'Our community and events',
                         'qa'           => 'Q&A session',
                     ]],
                    ['key' => 'more_about', 'type' => 'textarea',
                     'label' => '3.2 Which topic would you like to know more about?'],
                    ['key' => 'unclear', 'type' => 'textarea',
                     'label' => '3.3 Was there anything that remained unclear after today?'],
                ],
            ],
            [
                'title' => '4. Parent partnership',
                'intro' => 'Please indicate how strongly you agree with the following statements.',
                'questions' => [
                    ['key' => 'partnership', 'type' => 'matrix', 'short' => 'Partnership',
                     'label' => '', 'scale' => $agree,
                     'rows'  => [
                         'attendance'  => 'I understand the importance of regular attendance.',
                         'punctuality' => 'I understand why punctuality is important.',
                         'philosophy'  => 'I understand our play-based, child-centred philosophy.',
                         'channels'    => 'I know which channel to use for routine, personal and urgent messages.',
                         'cares'       => "I believe Little Graduates genuinely cares about my child's development.",
                         'partners'    => 'I believe parents and the school should work as partners.',
                     ]],
                ],
            ],
            [
                'title' => '5. The Little Graduates Kitchen',
                'intro' => 'We served today so you could taste what your child eats, ask about the '
                         . 'ingredients and tell us honestly what you think.',
                'questions' => [
                    ['key' => 'kitchen_tasted', 'type' => 'radio',
                     'label' => '5.1 Did you taste the food today?',
                     'options' => [
                         'yes'     => 'Yes',
                         'partly'  => 'A little',
                         'no'      => 'No, not today',
                     ]],
                    ['key' => 'kitchen_rating', 'type' => 'radio',
                     'label' => '5.2 If you tasted it, how was it?',
                     'options' => [
                         'excellent' => '★★★★★ Excellent',
                         'very_good' => '★★★★ Very Good',
                         'good'      => '★★★ Good',
                         'fair'      => '★★ Fair',
                         'poor'      => '★ Needs Improvement',
                     ]],
                    ['key' => 'kitchen_feedback', 'type' => 'textarea',
                     'label' => '5.3 Any feedback on the menu, the ingredients or how meals are run?',
                     'help'  => 'Honest feedback is more useful to us than a compliment.'],
                ],
            ],
            [
                'title' => '6. Daycare — the afternoon programme',
                'intro' => 'Art, cooking, music, outdoor play and free play — a calm afternoon, not '
                         . 'a second academic shift.',
                'questions' => [
                    ['key' => 'daycare_interest', 'type' => 'radio',
                     'label' => '6.1 Where does your family stand on daycare?',
                     'options' => [
                         'using'     => 'We already use it',
                         'planning'  => 'We plan to use it this year',
                         'maybe'     => 'We might consider it later',
                         'not_needed'=> 'We do not need it',
                     ]],
                    ['key' => 'daycare_activities', 'type' => 'checkbox', 'other' => true,
                     'label' => '6.2 Which afternoon activities appeal to you most?',
                     'help'  => 'Select all that apply — this helps us plan the timetable.',
                     'options' => [
                         'art'      => 'Art',
                         'cooking'  => 'Cooking',
                         'music'    => 'Music',
                         'outdoor'  => 'Outdoor play',
                         'free'     => 'Free play',
                         'quiet'    => 'Quiet time / rest',
                     ]],
                    ['key' => 'daycare_comments', 'type' => 'textarea',
                     'label' => '6.3 Anything else about the afternoon programme?',
                     'help'  => 'Optional.'],
                ],
            ],
            [
                'title' => '7. Looking ahead',
                'questions' => [
                    ['key' => 'excitement', 'type' => 'radio',
                     'label' => "7.1 How excited are you about your child's journey at Little Graduates?",
                     'options' => [
                         'very_excited'     => '★★★★★ Very Excited',
                         'excited'          => '★★★★ Excited',
                         'neutral'          => '★★★ Neutral',
                         'slightly_excited' => '★★ Slightly Excited',
                         'not_excited'      => '★ Not Excited',
                     ]],
                    ['key' => 'looking_forward', 'type' => 'textarea',
                     'label' => '7.2 What are you most looking forward to for your child this year?'],
                    ['key' => 'pace_pressure', 'type' => 'textarea',
                     'label' => "7.3 Where do you feel pressure about your child's pace, and what would "
                              . 'reassure you most this year?',
                     'help'  => 'We asked this in the hall; answer here if you would rather write it.'],
                    ['key' => 'concerns', 'type' => 'textarea',
                     'label' => '7.4 Do you have any concerns you would like us to know?'],
                    ['key' => 'suggestions', 'type' => 'textarea',
                     'label' => '7.5 Any suggestions to improve future Parent Orientation sessions?'],
                ],
            ],
            [
                'title' => "8. Planning your child's educational journey",
                'intro' => "Every family has different aspirations for their child. Your answers help "
                         . "us understand what matters to you — there is no commitment here, and no "
                         . "answer counts against your child in any way.",
                'questions' => [
                    ['key' => 'next_school', 'type' => 'radio',
                     'label' => "8.1 Have you started thinking about your child's schooling after Playgroup/Nursery?",
                     'options' => [
                         'continue'  => 'We plan to continue at Little Graduates.',
                         'other'     => 'We are considering other schools.',
                         'undecided' => "We haven't decided yet.",
                         'too_early' => 'It is too early for us to think about it.',
                     ]],
                    ['key' => 'choice_factors', 'type' => 'checkbox', 'other' => true,
                     'label' => '8.2 Which factors matter most to you when choosing a school?',
                     'help'  => 'Select all that apply.',
                     'options' => [
                         'academics'      => 'Academic excellence',
                         'happiness'      => "Child's happiness and emotional well-being",
                         'philosophy'     => 'Teaching philosophy & curriculum',
                         'reputation'     => 'School reputation',
                         'continuity'     => 'Continuity — not changing schools again',
                         'facilities'     => 'Facilities & infrastructure',
                         'extracurricular'=> 'Extracurricular opportunities',
                         'location'       => 'Location',
                         'fees'           => 'Fees',
                         'recommendations'=> 'Recommendations from family/friends',
                     ]],
                    ['key' => 'next_chapter', 'type' => 'textarea',
                     'label' => '8.3 We are exploring a small continuation beyond the present programme. '
                              . 'What would make that a meaningful next step for your family — or a clear no?',
                     'help'  => 'Nothing is decided. A plain "no" is as useful to us as a yes.'],
                ],
            ],
            [
                'title' => '9. Our community',
                'questions' => [
                    ['key' => 'involvement', 'type' => 'checkbox', 'other' => true,
                     'label' => '9.1 Would you like to be involved in any of these?',
                     'help'  => 'Optional — select all that apply, and we will get in touch.',
                     'options' => [
                         'events'     => 'Helping at events and celebrations',
                         'trips'      => 'Accompanying outings and trips',
                         'reading'    => 'Reading or storytelling with the children',
                         'talk'       => 'Sharing my work or skill with the children',
                         'kitchen'    => 'Kitchen and nutrition feedback group',
                         'none'       => 'Not this year, thank you',
                     ]],
                ],
            ],
            [
                'title' => '10. Final reflection',
                'questions' => [
                    ['key' => 'why_chose', 'type' => 'textarea',
                     'label' => '10.1 In one sentence, what made you choose Little Graduates for your child?'],
                    ['key' => 'confidence_year', 'type' => 'textarea',
                     'label' => '10.2 What would help you feel confident about the year ahead?'],
                    ['key' => 'anything_else', 'type' => 'textarea',
                     'label' => '10.3 Is there anything else you would like to share with us?'],
                ],
            ],
        ],
    ];
}

/**
 * Field Trip — parent consent.
 *
 * ---------------------------------------------------------------------------
 * Why this one is not shaped like the orientation survey
 * ---------------------------------------------------------------------------
 * That one gathers opinion, so every question is optional: a half-finished
 * form still tells us something. This one is a permission slip. A blank answer
 * is not a small gap in the data, it is the difference between a child getting
 * on the bus and not, so the questions that decide that are required and the
 * wording has to be unambiguous months later when somebody reads the row back.
 *
 * Three deliberate choices:
 *
 * 1. The class list is frozen to the four classes going. Elsewhere it comes
 *    from /grades.php so a new grade appears automatically, which is right for
 *    a survey about the school. Here it would silently invite Daycare parents
 *    to consent to a trip their child is not on.
 *
 * 2. Photograph permission is ONE required yes/no radio, not a checkbox. An
 *    untouched checkbox cannot be told apart from a declined one, and "we
 *    assumed the blank meant yes" is not a sentence anybody wants to say to
 *    a parent. A required radio has no unanswered state to misread.
 *
 * 3. Photograph permission is asked *after* the trip consent and framed as
 *    standing rather than trip-specific, so a parent can say yes to the trip
 *    and no to social media without feeling the two are linked.
 */
function survey_spec_field_trip(): array
{
    // Frozen on purpose — see (1) above.
    $classes = [
        'Playgroup' => 'Playgroup',
        'Nursery'   => 'Nursery',
        'LKG'       => 'LKG',
        'UKG'       => 'UKG',
    ];

    return [
        'key'      => 'field_trip',
        'title'    => 'Field Trip — Parent Consent',
        'subtitle' => 'Playgroup · Nursery · LKG · UKG',
        // One form per child. Two consent rows for the same child is not extra
        // data, it is a question about which one counts — and the morning of
        // the trip is the worst time to discover it.
        'one_per_child' => true,
        // Show the class roster against the replies, so "who has not answered"
        // is a page rather than a manual comparison. Names the question that
        // decides it.
        'roster'             => true,
        'roster_consent_key' => 'consent',
        'intro'    => "We are planning a field trip for Playgroup, Nursery, LKG and UKG, and we "
                    . "need your permission before your child can join.\n\n"
                    . "The full trip details — where we are going, the date, departure and return "
                    . "times, what to send with your child and what to expect on the day — will be "
                    . "shared separately on CuePilot, with a detailed message closer to the time. "
                    . "Please keep an eye on CuePilot for that.\n\n"
                    . "Children will be supervised by their own class teachers throughout, and our "
                    . "usual staff-to-child ratios apply. Please complete one form per child — a "
                    . "sibling needs their own form under their own name.",
        'thanks'   => "Thank you. Your consent has been recorded.\n\n"
                    . "If anything changes — your emergency contact, something we should know "
                    . "about your child that day, or if you change your mind about the trip or "
                    . "about photographs — please tell the school office and we will update our "
                    . "records. You do not need to fill this form in again.",
        'sections' => [
            [
                'title' => 'Your details',
                'questions' => [
                    ['key' => 'parent_name', 'type' => 'text', 'required' => true,
                     'label' => 'Your name (parent or guardian)'],
                    ['key' => 'child_name', 'type' => 'text', 'required' => true,
                     'label' => "Your child's full name"],
                    ['key' => 'class', 'type' => 'radio', 'required' => true,
                     'label' => "Your child's class", 'options' => $classes],
                ],
            ],
            [
                'title' => '1. Consent for the trip',
                'questions' => [
                    ['key' => 'consent', 'type' => 'radio', 'required' => true, 'short' => 'Consent',
                     'label' => 'Do you give permission for your child to take part in this field trip?',
                     'options' => [
                         'yes' => 'Yes — I give permission for my child to attend',
                         'no'  => 'No — my child will not be attending',
                     ]],
                    ['key' => 'emergency_contact', 'type' => 'text', 'short' => 'Emergency no.',
                     'label' => 'A phone number we can reach you on during the trip',
                     'help'  => 'Please give a number that will be answered on the day, even if it '
                              . 'is different from the one we usually use.'],
                    ['key' => 'health_notes', 'type' => 'textarea', 'short' => 'To know',
                     'label' => 'Is there anything we should know for the day?',
                     'help'  => 'Allergies, medication, travel sickness, dietary needs, or anything '
                              . 'that would help us look after your child well. Write "nothing" if '
                              . 'there is nothing.'],
                ],
            ],
            [
                'title' => '2. Coming along to help',
                'questions' => [
                    ['key' => 'volunteer', 'type' => 'checkbox', 'short' => 'Volunteer',
                     'label' => 'Would you be willing to come along as a parent volunteer?',
                     'help'  => 'We may not need everyone who offers, and we will confirm nearer '
                              . 'the day. Ticking this does not commit you.',
                     'options' => [
                         'yes' => 'Yes — I am willing to help supervise on the trip if you need me',
                     ]],
                    ['key' => 'volunteer_phone', 'type' => 'text', 'short' => 'Vol. phone',
                     'label' => 'If you ticked that, the best number to reach you on to arrange it'],
                ],
            ],
            [
                'title' => '3. Photographs and videos of your child',
                'questions' => [
                    ['key' => 'media_consent', 'type' => 'radio', 'required' => true, 'short' => 'Photos',
                     'label' => 'Do you give permission for the school to photograph or film your '
                              . 'child, and use that for the school\'s own purposes — such as notice '
                              . 'boards, newsletters, the website and the school\'s social media?',
                     'help'  => 'We photograph and film children during the school day, at '
                              . 'celebrations and on trips, because those moments are worth keeping '
                              . 'and sharing. This is your standing preference for the whole year, '
                              . 'not just this trip. Consent already on file is not changed by '
                              . 'submitting this form again — tell the school office if you would '
                              . 'like it changed. We never publish your child\'s full name, class or '
                              . 'any contact detail alongside a picture, and we never share images '
                              . 'with anyone outside the school for their own use.',
                     'options' => [
                         'yes' => 'Yes — the school may photograph or film my child',
                         'no'  => 'No — please do not photograph or film my child',
                     ]],
                ],
            ],
            [
                'title' => '4. Anything else',
                'questions' => [
                    ['key' => 'anything_else', 'type' => 'textarea',
                     'label' => 'Any questions or anything else you would like to tell us?'],
                ],
            ],
        ],
    ];
}

// ---- Spec helpers --------------------------------------------------------

/** Resolve a question's options — the literal map, or a named dynamic list. */
function survey_options(array $q): array
{
    $o = $q['options'] ?? [];
    if ($o === 'classes')  return survey_class_options();
    if ($o === 'students') return survey_student_options($q['options_filter'] ?? []);
    if ($o === 'parents')  return survey_parent_options($q['options_filter'] ?? []);
    // student_picker always draws from the student roster (options may be omitted).
    if (($q['type'] ?? '') === 'student_picker') {
        return survey_student_options($q['options_filter'] ?? []);
    }
    return is_array($o) ? $o : [];
}

/**
 * Enrolled students as option map: student_id => "Name (Grade)".
 * Filter keys: grades[], enrollment_status[] (default enrolled), active_only (default true).
 *
 * Prefer survey_student_search() on public forms — this full list must not be
 * embedded in HTML (it reveals how many children are enrolled).
 */
function survey_student_options(array $filter = []): array
{
    $grades = array_values(array_filter(array_map('strval', (array)($filter['grades'] ?? []))));
    $statuses = array_values(array_filter(array_map('strval',
        (array)($filter['enrollment_status'] ?? ['enrolled']))));
    if ($statuses === []) $statuses = ['enrolled'];
    $activeOnly = !array_key_exists('active_only', $filter) || !empty($filter['active_only']);

    try {
        $sql = "SELECT id, first_name, last_name, grade FROM students WHERE 1=1";
        $params = [];
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        if ($statuses) {
            $in = [];
            foreach ($statuses as $i => $st) {
                $k = ':st' . $i;
                $in[] = $k;
                $params[$k] = $st;
            }
            $sql .= " AND enrollment_status IN (" . implode(',', $in) . ")";
        }
        if ($grades) {
            $in = [];
            foreach ($grades as $i => $g) {
                $k = ':g' . $i;
                $in[] = $k;
                $params[$k] = $g;
            }
            $sql .= " AND grade IN (" . implode(',', $in) . ")";
        }
        $sql .= " ORDER BY grade, first_name, last_name LIMIT 500";
        $st = db()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $name = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            $label = $name;
            if ((string)$row['grade'] !== '') $label .= ' (' . (string)$row['grade'] . ')';
            $out[(string)(int)$row['id']] = $label;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Minimum characters before a public typeahead search runs. */
const SURVEY_LOOKUP_MIN_CHARS = 3;
/** Hard cap on matches returned to the public form (never expose full roster). */
const SURVEY_LOOKUP_MAX = 8;

/**
 * Prefix search for the public student typeahead.
 * Returns a list of ['id','label','data'] — never a total count of the school.
 * Empty until the query has SURVEY_LOOKUP_MIN_CHARS letters/digits.
 */
function survey_lookup_like_prefix(string $query): string
{
    // Escape LIKE metacharacters so "a%" cannot dump the roster.
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
}

function survey_student_search(string $query, array $filter = [], int $limit = SURVEY_LOOKUP_MAX): array
{
    $q = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
    // Count letters/digits so "adh" works; ignore punctuation length tricks.
    $letters = preg_replace('/[^\p{L}\p{N}]+/u', '', $q) ?? '';
    if (function_exists('mb_strlen')) {
        if (mb_strlen($letters) < SURVEY_LOOKUP_MIN_CHARS) return [];
    } elseif (strlen($letters) < SURVEY_LOOKUP_MIN_CHARS) {
        return [];
    }
    $limit = max(1, min(SURVEY_LOOKUP_MAX, $limit));
    $like = survey_lookup_like_prefix($q);

    $grades = array_values(array_filter(array_map('strval', (array)($filter['grades'] ?? []))));
    $statuses = array_values(array_filter(array_map('strval',
        (array)($filter['enrollment_status'] ?? ['enrolled']))));
    if ($statuses === []) $statuses = ['enrolled'];
    $activeOnly = !array_key_exists('active_only', $filter) || !empty($filter['active_only']);

    try {
        $sql = "SELECT id, first_name, last_name, grade FROM students WHERE 1=1";
        // Unique placeholders only — PDO with ATTR_EMULATE_PREPARES=false
        // (see includes/db.php) rejects reusing the same :name twice.
        $prefix = $like . '%';
        $word   = '% ' . $like . '%';
        $params = [
            ':q_first' => $prefix,
            ':q_last'  => $prefix,
            ':q_full'  => $prefix,
            ':q_word'  => $word,
        ];
        if ($activeOnly) $sql .= " AND is_active = 1";
        if ($statuses) {
            $in = [];
            foreach ($statuses as $i => $st) {
                $k = ':st' . $i;
                $in[] = $k;
                $params[$k] = $st;
            }
            $sql .= " AND enrollment_status IN (" . implode(',', $in) . ")";
        }
        if ($grades) {
            $in = [];
            foreach ($grades as $i => $g) {
                $k = ':g' . $i;
                $in[] = $k;
                $params[$k] = $g;
            }
            $sql .= " AND grade IN (" . implode(',', $in) . ")";
        }
        // Match start of first name, last name, or any word in the full name.
        $sql .= " AND (
                    first_name LIKE :q_first
                 OR last_name  LIKE :q_last
                 OR CONCAT(TRIM(first_name), ' ', TRIM(last_name)) LIKE :q_full
                 OR CONCAT(TRIM(first_name), ' ', TRIM(last_name)) LIKE :q_word
                  )";
        $sql .= " ORDER BY first_name, last_name LIMIT " . (int)$limit;
        $st = db()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $id = (int)$row['id'];
            $data = survey_student_fill_data($id);
            if (!$data) continue;
            $label = $data['full_name'];
            if ($data['grade'] !== '') $label .= ' (' . $data['grade'] . ')';
            $out[] = [
                'id'    => (string)$id,
                'label' => $label,
                'data'  => $data,
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('survey_student_search failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Prefix search for parents (public typeahead). Same privacy rules as students.
 * id is "parentId:studentId".
 */
function survey_parent_search(string $query, array $filter = [], int $limit = SURVEY_LOOKUP_MAX): array
{
    $q = preg_replace('/\s+/u', ' ', trim($query)) ?? '';
    $letters = preg_replace('/[^\p{L}\p{N}]+/u', '', $q) ?? '';
    if (function_exists('mb_strlen')) {
        if (mb_strlen($letters) < SURVEY_LOOKUP_MIN_CHARS) return [];
    } elseif (strlen($letters) < SURVEY_LOOKUP_MIN_CHARS) {
        return [];
    }
    $limit = max(1, min(SURVEY_LOOKUP_MAX, $limit));
    $like = survey_lookup_like_prefix($q);

    $grades = array_values(array_filter(array_map('strval', (array)($filter['grades'] ?? []))));
    $statuses = array_values(array_filter(array_map('strval',
        (array)($filter['enrollment_status'] ?? ['enrolled']))));
    if ($statuses === []) $statuses = ['enrolled'];
    $activeOnly = !array_key_exists('active_only', $filter) || !empty($filter['active_only']);

    try {
        $sql = "SELECT p.id AS parent_id, p.name AS parent_name,
                       s.id AS student_id, s.first_name, s.last_name, s.grade
                  FROM student_parents p
                  JOIN students s ON s.id = p.student_id
                 WHERE 1=1";
        // Unique placeholders — native PDO prepares cannot reuse :name.
        $prefix = $like . '%';
        $word   = '% ' . $like . '%';
        $params = [
            ':q_pname'  => $prefix,
            ':q_pword'  => $word,
            ':q_sfirst' => $prefix,
            ':q_slast'  => $prefix,
            ':q_sfull'  => $prefix,
        ];
        if ($activeOnly) $sql .= " AND s.is_active = 1";
        if ($statuses) {
            $in = [];
            foreach ($statuses as $i => $st) {
                $k = ':st' . $i;
                $in[] = $k;
                $params[$k] = $st;
            }
            $sql .= " AND s.enrollment_status IN (" . implode(',', $in) . ")";
        }
        if ($grades) {
            $in = [];
            foreach ($grades as $i => $g) {
                $k = ':g' . $i;
                $in[] = $k;
                $params[$k] = $g;
            }
            $sql .= " AND s.grade IN (" . implode(',', $in) . ")";
        }
        $sql .= " AND (
                    p.name LIKE :q_pname
                 OR p.name LIKE :q_pword
                 OR s.first_name LIKE :q_sfirst
                 OR s.last_name LIKE :q_slast
                 OR CONCAT(TRIM(s.first_name), ' ', TRIM(s.last_name)) LIKE :q_sfull
                  )";
        $sql .= " ORDER BY p.name, s.first_name LIMIT " . (int)$limit;
        $st = db()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $child = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            $label = (string)$row['parent_name'] . ' — ' . $child;
            if ((string)$row['grade'] !== '') $label .= ' (' . (string)$row['grade'] . ')';
            $id = (int)$row['parent_id'] . ':' . (int)$row['student_id'];
            $out[] = [
                'id'    => $id,
                'label' => $label,
                'data'  => [
                    'parent_name'    => (string)$row['parent_name'],
                    'full_name'      => $child,
                    'grade'          => (string)$row['grade'],
                    'primary_parent' => (string)$row['parent_name'],
                    'student_id'     => (int)$row['student_id'],
                ],
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('survey_parent_search failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Parents of matching students: "parent_id:student_id" => "Parent — Child (Grade)".
 * Same filter shape as survey_student_options.
 */
function survey_parent_options(array $filter = []): array
{
    $grades = array_values(array_filter(array_map('strval', (array)($filter['grades'] ?? []))));
    $statuses = array_values(array_filter(array_map('strval',
        (array)($filter['enrollment_status'] ?? ['enrolled']))));
    if ($statuses === []) $statuses = ['enrolled'];
    $activeOnly = !array_key_exists('active_only', $filter) || !empty($filter['active_only']);

    try {
        $sql = "SELECT p.id AS parent_id, p.name AS parent_name, p.relation,
                       s.id AS student_id, s.first_name, s.last_name, s.grade
                  FROM student_parents p
                  JOIN students s ON s.id = p.student_id
                 WHERE 1=1";
        $params = [];
        if ($activeOnly) $sql .= " AND s.is_active = 1";
        if ($statuses) {
            $in = [];
            foreach ($statuses as $i => $st) {
                $k = ':st' . $i;
                $in[] = $k;
                $params[$k] = $st;
            }
            $sql .= " AND s.enrollment_status IN (" . implode(',', $in) . ")";
        }
        if ($grades) {
            $in = [];
            foreach ($grades as $i => $g) {
                $k = ':g' . $i;
                $in[] = $k;
                $params[$k] = $g;
            }
            $sql .= " AND s.grade IN (" . implode(',', $in) . ")";
        }
        $sql .= " ORDER BY s.grade, s.first_name, s.last_name, p.is_primary DESC, p.name LIMIT 800";
        $st = db()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $child = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
            $label = (string)$row['parent_name'] . ' — ' . $child;
            if ((string)$row['grade'] !== '') $label .= ' (' . (string)$row['grade'] . ')';
            $code = (int)$row['parent_id'] . ':' . (int)$row['student_id'];
            $out[$code] = $label;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Autofill payload for one student (used by student_picker JS + signed prefills).
 * Keys match the `fills` map targets: full_name, grade, primary_parent, …
 */
function survey_student_fill_data(int $studentId): ?array
{
    if ($studentId <= 0) return null;
    try {
        $st = db()->prepare("
            SELECT id, first_name, last_name, grade, section, admission_number
              FROM students WHERE id = :id LIMIT 1
        ");
        $st->execute([':id' => $studentId]);
        $row = $st->fetch();
        if (!$row) return null;
        $full = trim((string)$row['first_name'] . ' ' . (string)$row['last_name']);
        $parent = '';
        $pst = db()->prepare("
            SELECT name FROM student_parents
             WHERE student_id = :id
             ORDER BY is_primary DESC, id ASC LIMIT 1
        ");
        $pst->execute([':id' => $studentId]);
        $prow = $pst->fetch();
        if ($prow) $parent = (string)$prow['name'];
        return [
            'student_id'      => (int)$row['id'],
            'full_name'       => $full,
            'first_name'      => (string)$row['first_name'],
            'last_name'       => (string)$row['last_name'],
            'grade'           => (string)$row['grade'],
            'section'         => (string)($row['section'] ?? ''),
            'admission_number'=> (string)($row['admission_number'] ?? ''),
            'primary_parent'  => $parent,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/** Map fills config + student data → field values to put on the form. */
function survey_apply_fills(array $fills, array $data): array
{
    $out = [];
    foreach ($fills as $field => $source) {
        $field = (string)$field;
        $source = (string)$source;
        if ($field === '' || $source === '') continue;
        if (array_key_exists($source, $data)) {
            $out[$field] = (string)$data[$source];
        }
    }
    return $out;
}

/** Every question in a spec, flat, in order. */
function survey_questions(array $spec): array
{
    $out = [];
    foreach ($spec['sections'] ?? [] as $sec) {
        foreach ($sec['questions'] ?? [] as $q) $out[] = $q;
    }
    return $out;
}

/**
 * The spreadsheet columns for a spec: one per answerable field, in form order.
 * A matrix becomes one column per statement — that's what makes the grid and
 * the CSV usable in Excel, where each cell must hold a single value.
 *
 * Each column: ['key','label','type','q'] where key is 'question' or
 * 'question.row' and q is the owning question.
 */
function survey_columns(array $spec): array
{
    $cols = [];
    foreach (survey_questions($spec) as $q) {
        $type = $q['type'] ?? 'text';
        if ($type === 'matrix') {
            $short = (string)($q['short'] ?? $q['label'] ?? $q['key']);
            foreach (($q['rows'] ?? []) as $rk => $rlabel) {
                $cols[] = [
                    'key'   => $q['key'] . '.' . $rk,
                    'label' => $short . ': ' . $rlabel,
                    'type'  => 'matrix',
                    'q'     => $q,
                ];
            }
            continue;
        }
        $cols[] = ['key' => $q['key'], 'label' => (string)$q['label'], 'type' => $type, 'q' => $q];
        if ($type === 'checkbox' && !empty($q['other'])) {
            $cols[] = ['key' => $q['key'] . '_other', 'label' => $q['label'] . ' — Other',
                       'type' => 'text', 'q' => $q];
        }
    }
    return $cols;
}

/** Read a possibly-dotted answer key out of a decoded answers array. */
function survey_raw(array $answers, string $key)
{
    if (strpos($key, '.') === false) return $answers[$key] ?? null;
    [$a, $b] = explode('.', $key, 2);
    return $answers[$a][$b] ?? null;
}

/**
 * One cell, as display text. Codes become their labels; a multi-select becomes
 * a comma-joined list. '' when unanswered — a survey left blank should read as
 * blank, not as a zero or a dash that looks like data.
 */
function survey_cell(array $col, array $answers): string
{
    $v = survey_raw($answers, $col['key']);
    if ($v === null || $v === '' || $v === []) return '';
    $q = $col['q'];

    switch ($col['type']) {
        case 'matrix':
            $scale = $q['scale'] ?? [];
            return (string)($scale[$v] ?? $v);
        case 'radio':
        case 'select':
        case 'student_picker':
            $opts = survey_options($q);
            return (string)($opts[$v] ?? $v);
        case 'checkbox':
            $opts = survey_options($q);
            $out  = [];
            foreach ((array)$v as $code) $out[] = (string)($opts[$code] ?? $code);
            return implode(', ', $out);
        default:
            return (string)$v;
    }
}

// ---- Validation ----------------------------------------------------------

/**
 * Turn a raw POST into the answers to store.
 *
 * Whitelist-only: an answer survives just when it matches an option the spec
 * actually offers, so nothing a browser (or anyone else) invents reaches the
 * database. Returns [answers, errors]; errors is keyed by question key.
 */
function survey_collect(array $spec, array $post): array
{
    $answers = [];
    $errors  = [];

    foreach (survey_questions($spec) as $q) {
        $key  = (string)$q['key'];
        $type = (string)($q['type'] ?? 'text');
        $req  = !empty($q['required']);
        $raw  = $post[$key] ?? null;

        switch ($type) {
            case 'text':
            case 'textarea':
                $max = $type === 'text' ? SURVEY_NAME_MAX : SURVEY_TEXT_MAX;
                $v   = trim((string)($raw ?? ''));
                if (function_exists('mb_substr')) $v = mb_substr($v, 0, $max);
                else                              $v = substr($v, 0, $max);
                if ($v !== '') $answers[$key] = $v;
                elseif ($req)  $errors[$key] = 'Please fill this in.';
                break;

            case 'radio':
            case 'student_picker':
            case 'select':
                $opts = survey_options($q);
                $v    = (string)($raw ?? '');
                if ($v !== '' && array_key_exists($v, $opts)) $answers[$key] = $v;
                elseif ($req) $errors[$key] = 'Please choose one.';
                break;

            case 'checkbox':
                $opts = survey_options($q);
                $picked = [];
                foreach ((array)($raw ?? []) as $code) {
                    $code = (string)$code;
                    if (array_key_exists($code, $opts) && !in_array($code, $picked, true)) {
                        $picked[] = $code;
                    }
                }
                if ($picked) $answers[$key] = $picked;
                elseif ($req) $errors[$key] = 'Please choose at least one.';

                if (!empty($q['other'])) {
                    $other = trim((string)($post[$key . '_other'] ?? ''));
                    if ($other !== '') {
                        $answers[$key . '_other'] = function_exists('mb_substr')
                            ? mb_substr($other, 0, SURVEY_OTHER_MAX)
                            : substr($other, 0, SURVEY_OTHER_MAX);
                    }
                }
                break;

            case 'matrix':
                $scale = $q['scale'] ?? [];
                $rows  = (array)($raw ?? []);
                $picked = [];
                foreach (($q['rows'] ?? []) as $rk => $_) {
                    $v = (string)($rows[$rk] ?? '');
                    if ($v !== '' && array_key_exists($v, $scale)) $picked[$rk] = $v;
                }
                if ($picked) $answers[$key] = $picked;
                elseif ($req) $errors[$key] = 'Please answer this.';
                break;
        }
    }

    return [$answers, $errors];
}

// ---- Surveys (the live links) -------------------------------------------

/**
 * The live survey row for a spec, creating it — token and all — the first time
 * it's asked for. So the shareable link simply exists once the code is
 * deployed; nobody has to remember to press "create" before an orientation.
 */
function survey_ensure(string $specKey, ?int $byUserId = null): ?array
{
    if (!survey_spec($specKey)) return null;
    try {
        $s = db()->prepare("SELECT * FROM surveys WHERE spec_key = :k ORDER BY id LIMIT 1");
        $s->execute([':k' => $specKey]);
        if ($row = $s->fetch()) return $row;

        db()->prepare("
            INSERT INTO surveys (spec_key, token, active, created_by)
            VALUES (:k, :t, 1, :by)
        ")->execute([
            ':k'  => $specKey,
            ':t'  => bin2hex(random_bytes(32)),
            ':by' => $byUserId,
        ]);
        $s->execute([':k' => $specKey]);
        return $s->fetch() ?: null;
    } catch (Throwable $e) {
        return null;   // pre-migration DB
    }
}

/** Look up a survey by its public token. Null when unknown or closed. */
function survey_by_token(string $token): ?array
{
    // Length-check first so a junk URL never reaches the database, and compare
    // in constant time so a wrong token can't be narrowed down by timing.
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return null;
    try {
        $s = db()->prepare("SELECT * FROM surveys WHERE token = :t LIMIT 1");
        $s->execute([':t' => $token]);
        $row = $s->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row || !hash_equals((string)$row['token'], $token)) return null;
    if ((int)$row['active'] !== 1) return null;
    return $row;
}

/*
 * There is deliberately no function here that changes an existing survey's
 * token.
 *
 * A survey link is shared outside the app — pasted into CuePilot, forwarded
 * in a WhatsApp group, printed on a notice. Every one of those copies is a
 * screenshot or a message nobody can recall. Minting a new token orphans all
 * of them at once: a parent who taps a link they saved yesterday lands on
 * "survey not available" with no way to tell why, and support has to work
 * out which of several copies floating around is still valid.
 *
 * If a link needs to stop working, use survey_set_active() below — Close
 * keeps the token but refuses new responses, which is reversible and doesn't
 * silently break a copy someone still has open in a browser tab. If a token
 * is believed to be actually compromised, that is an incident, not a
 * button — talk to whoever built this rather than routing around it here.
 */

/** Open or close a survey. A closed survey's link stops accepting responses. */
function survey_set_active(int $surveyId, bool $active): void
{
    db()->prepare("UPDATE surveys SET active = :a WHERE id = :id")
        ->execute([':a' => $active ? 1 : 0, ':id' => $surveyId]);
}

/** Absolute URL of a survey's public form. */
function survey_url(string $token): string
{
    if (empty($_SERVER['HTTP_HOST'])) return '/survey.php?t=' . $token;
    return app_base_url() . '/survey.php?t=' . $token;
}

// ---- Responses -----------------------------------------------------------

/**
 * Store one submission. parent_name / child_name / class get their own columns
 * because they're what the office sorts, searches and de-duplicates on;
 * everything else rides along as JSON.
 */
/**
 * A child's name reduced to something two parents will agree on.
 *
 * "Aarav  Nair", "aarav nair" and " Aarav Nair " are one child. Case and
 * stray spaces are the whole of what this fixes, and that is on purpose: it
 * does not try to guess that "Aarav" and "Aarav Nair" are the same person,
 * because sometimes they are not, and a form that silently refuses a sibling
 * is worse than one that lets a near-duplicate through for a human to spot.
 */
function survey_child_key(string $childName): string
{
    $k = trim(preg_replace('/\s+/u', ' ', $childName) ?? '');
    return function_exists('mb_strtolower') ? mb_strtolower($k, 'UTF-8') : strtolower($k);
}

/**
 * Thrown when a spec allows one response per child and one already exists.
 *
 * This is also the entire mechanism behind a stricter rule: photograph and
 * video consent, once given, is not something a parent can change by
 * resubmitting the form. There is no update path for a saved response — see
 * survey_response_delete() — only insert and delete, and delete is
 * admin-only. So a second submission for the same child is always refused
 * here, in full, not just for the photo question; the only way past it is an
 * admin at the school choosing to withdraw the response first. Keep it that
 * way: a scoped "let the parent edit just this one answer" endpoint would
 * quietly reopen photo consent to being changed without anyone at the school
 * seeing it happen.
 */
class SurveyDuplicateError extends Exception
{
    public array $existing;
    public function __construct(array $existing)
    {
        $this->existing = $existing;
        parent::__construct('A response already exists for this child.');
    }
}

/**
 * The response already on file for this child, or null.
 *
 * Only meaningful for a spec with one_per_child — every other survey leaves
 * child_key NULL, and NULL matches nothing.
 */
function survey_existing_for_child(int $surveyId, string $childName): ?array
{
    $key = survey_child_key($childName);
    if ($key === '') return null;
    try {
        $s = db()->prepare("SELECT * FROM survey_responses
                             WHERE survey_id = :s AND child_key = :k LIMIT 1");
        $s->execute([':s' => $surveyId, ':k' => $key]);
        $row = $s->fetch();
        return $row === false ? null : $row;
    } catch (PDOException $e) {
        return null;      // pre-migration: behave as it did before
    }
}

/**
 * Store one response.
 *
 * For a spec marked one_per_child this refuses a second response for the same
 * child and throws SurveyDuplicateError carrying the row already on file, so
 * the form can tell the parent when they answered rather than just saying no.
 * The check is made twice on purpose: once here for the message, and again by
 * the unique index, which is the one that actually holds when two parents of
 * the same child submit in the same second.
 */
function survey_save_response(int $surveyId, array $answers, ?array $spec = null): int
{
    $child    = (string)($answers['child_name'] ?? '');
    $oneEach  = !empty($spec['one_per_child']);
    $childKey = $oneEach ? survey_child_key($child) : '';

    if ($oneEach && $childKey !== '') {
        $existing = survey_existing_for_child($surveyId, $child);
        if ($existing !== null) throw new SurveyDuplicateError($existing);
    }

    try {
        db()->prepare("
            INSERT INTO survey_responses
                (survey_id, parent_name, child_name, child_key, class, answers, ip_hash)
            VALUES (:s, :p, :c, :ck, :g, :a, :ip)
        ")->execute([
            ':s'  => $surveyId,
            ':p'  => (string)($answers['parent_name'] ?? ''),
            ':c'  => $child,
            ':ck' => $childKey !== '' ? $childKey : null,
            ':g'  => (string)($answers['class'] ?? ''),
            ':a'  => json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            // Hashed, not stored raw: enough to spot one device submitting twenty
            // times, without keeping identifiable network data about families.
            ':ip' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')),
        ]);
    } catch (PDOException $e) {
        // 23000 is the unique index doing its job — the race the check above
        // cannot close. Re-read and report it as the duplicate it is, rather
        // than as "something went wrong".
        if ($oneEach && $e->getCode() === '23000') {
            $existing = survey_existing_for_child($surveyId, $child);
            if ($existing !== null) throw new SurveyDuplicateError($existing);
        }
        throw $e;
    }
    return (int)db()->lastInsertId();
}

/**
 * Every child who should answer this survey, set against who has.
 *
 * The responses grid answers "what did parents say". For a consent form the
 * urgent question is the opposite one — *who has not replied* — and a list of
 * the forms you already have cannot tell you that. So this starts from the
 * class roster and works outwards.
 *
 * Three groups come back, and the third matters as much as the others:
 *
 *   consented / declined / waiting — children on the roster, by what we hold
 *   unmatched                      — responses whose child name matches no
 *                                    child on the roster
 *
 * Without `unmatched`, a parent who typed "Aarav" instead of "Aarav Nair"
 * would appear as a missing consent while their form sat in the table, and
 * somebody would chase a family who had already answered. Name matching is
 * the same normalisation the duplicate check uses — nothing smarter, because
 * a wrong guess here is a child on or off a bus.
 *
 * `classes` defaults to the spec's own class options, so it cannot drift out
 * of step with the form.
 */
function survey_roster_status(int $surveyId, array $spec, ?array $classes = null): array
{
    $consentKey = (string)($spec['roster_consent_key'] ?? 'consent');
    if ($classes === null) {
        $classes = [];
        foreach (survey_questions($spec) as $q) {
            if (($q['key'] ?? '') === 'class') { $classes = array_keys(survey_options($q)); break; }
        }
    }

    $out = ['consented' => [], 'declined' => [], 'waiting' => [], 'unmatched' => [],
            'classes' => $classes];
    if (!$classes) return $out;

    try {
        $in = implode(',', array_fill(0, count($classes), '?'));
        $st = db()->prepare(
            "SELECT id, first_name, last_name, grade, admission_number
               FROM students
              WHERE is_active = 1 AND enrollment_status = 'enrolled' AND grade IN ($in)
              ORDER BY grade, first_name, last_name"
        );
        $st->execute($classes);
        $roster = $st->fetchAll();
    } catch (Throwable $e) {
        return $out;
    }

    // Index the responses by normalised child name.
    $byChild = [];
    foreach (survey_responses($surveyId) as $r) {
        $k = survey_child_key((string)$r['child_name']);
        if ($k !== '' && !isset($byChild[$k])) $byChild[$k] = $r;
    }

    $claimed = [];
    foreach ($roster as $child) {
        $full = trim((string)$child['first_name'] . ' ' . (string)$child['last_name']);
        $k    = survey_child_key($full);
        // A roster of "Aarav Nair" against a form filled in as "Aarav" is a
        // real and common mismatch, so try the first name alone as well —
        // but only when it is not ambiguous across the roster.
        $r = $byChild[$k] ?? null;
        if ($r === null) {
            $firstKey = survey_child_key((string)$child['first_name']);
            $sameFirst = 0;
            foreach ($roster as $other) {
                if (survey_child_key((string)$other['first_name']) === $firstKey) $sameFirst++;
            }
            if ($sameFirst === 1 && isset($byChild[$firstKey])) {
                $r = $byChild[$firstKey];
                $k = $firstKey;
            }
        }

        $child['full_name'] = $full;
        if ($r === null) { $out['waiting'][] = $child; continue; }

        $claimed[$k]        = true;
        $child['response']  = $r;
        $answers            = is_array($r['_a'] ?? null) ? $r['_a'] : [];
        $child['answers']   = $answers;
        $said               = (string)($answers[$consentKey] ?? '');
        if ($said === 'yes')     $out['consented'][] = $child;
        elseif ($said === 'no')  $out['declined'][]   = $child;
        else                     $out['waiting'][]    = $child;   // form without a decision
    }

    foreach ($byChild as $k => $r) {
        if (!isset($claimed[$k])) $out['unmatched'][] = $r;
    }
    return $out;
}

/**
 * Withdraw a response so the family can submit again.
 *
 * Refusing duplicates without this would be a trap: one mistyped name, or one
 * parent changing their mind, and there is no way back — the form says "we
 * already have this" and nobody can clear it. Deleting is the right verb here
 * rather than an edit, because the answers are the parent's own words and an
 * admin should not be quietly rewriting a consent record; they remove it and
 * the parent fills it in again.
 */
function survey_response_delete(int $responseId): bool
{
    $s = db()->prepare("DELETE FROM survey_responses WHERE id = :i");
    $s->execute([':i' => $responseId]);
    return $s->rowCount() > 0;
}

/** Responses for a survey, newest first. Each row gains a decoded `_a`. */
function survey_responses(int $surveyId, string $q = ''): array
{
    $sql    = "SELECT * FROM survey_responses WHERE survey_id = :s";
    $params = [':s' => $surveyId];
    if ($q !== '') {
        // One placeholder per LIKE. db() runs with EMULATE_PREPARES off, and a
        // native prepare rejects the same named placeholder used twice in a
        // statement ("Invalid parameter number") — which the catch below would
        // then turn into a silently empty result.
        $sql .= " AND (parent_name LIKE :q1 OR child_name LIKE :q2 OR class LIKE :q3 OR answers LIKE :q4)";
        $like = '%' . $q . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
        $params[':q3'] = $like;
        $params[':q4'] = $like;
    }
    $sql .= " ORDER BY submitted_at DESC, id DESC LIMIT 2000";
    try {
        $s = db()->prepare($sql);
        $s->execute($params);
        $rows = $s->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as &$r) {
        $decoded = json_decode((string)$r['answers'], true);
        $r['_a'] = is_array($decoded) ? $decoded : [];
    }
    return $rows;
}

/** One response by id, with `_a` decoded. */
function survey_response(int $id): ?array
{
    try {
        $s = db()->prepare("SELECT * FROM survey_responses WHERE id = :id");
        $s->execute([':id' => $id]);
        $row = $s->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) return null;
    $decoded = json_decode((string)$row['answers'], true);
    $row['_a'] = is_array($decoded) ? $decoded : [];
    return $row;
}

/** How many responses a survey has. */
function survey_response_count(int $surveyId): int
{
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM survey_responses WHERE survey_id = :s");
        $s->execute([':s' => $surveyId]);
        return (int)$s->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Tally the choice questions across a set of responses:
 *   question key → ['label', 'options' => [label => count], 'answered' => n]
 *
 * Free-text questions are skipped — there's nothing to count, and they're read
 * one at a time on the responses page.
 */
function survey_tally(array $spec, array $rows): array
{
    $out = [];
    foreach (survey_questions($spec) as $q) {
        $type = (string)($q['type'] ?? '');
        $key  = (string)$q['key'];

        if ($type === 'radio' || $type === 'checkbox' || $type === 'select' || $type === 'student_picker') {
            $opts   = survey_options($q);
            $counts = array_fill_keys(array_values($opts), 0);
            $answered = 0;
            foreach ($rows as $r) {
                $v = $r['_a'][$key] ?? null;
                if ($v === null || $v === '' || $v === []) continue;
                $answered++;
                foreach ((array)$v as $code) {
                    $label = (string)($opts[$code] ?? $code);
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
            }
            $out[$key] = ['label' => (string)$q['label'], 'options' => $counts,
                          'answered' => $answered, 'multi' => $type === 'checkbox'];
        } elseif ($type === 'matrix') {
            $scale = $q['scale'] ?? [];
            foreach (($q['rows'] ?? []) as $rk => $rlabel) {
                $counts   = array_fill_keys(array_values($scale), 0);
                $answered = 0;
                foreach ($rows as $r) {
                    $v = $r['_a'][$key][$rk] ?? null;
                    if ($v === null || $v === '') continue;
                    $answered++;
                    $label = (string)($scale[$v] ?? $v);
                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }
                $out[$key . '.' . $rk] = [
                    'label'    => ($q['short'] ?? $q['label']) . ': ' . $rlabel,
                    'options'  => $counts,
                    'answered' => $answered,
                    'multi'    => false,
                ];
            }
        }
    }
    return $out;
}

// ---- MCP / JSON survey definitions --------------------------------------

const SURVEY_DEF_KEY_MAX = 64;
const SURVEY_DEF_ALLOWED_TYPES = ['text', 'textarea', 'radio', 'checkbox', 'matrix', 'student_picker', 'select'];
const SURVEY_DEF_OPTION_SOURCES = ['classes', 'students', 'parents'];
const SURVEY_PREFILL_TTL_DEFAULT = 60 * 60 * 24 * 30; // 30 days

/** Decode a definition LONGTEXT into a spec array, or null. */
function survey_definition_decode(string $json): ?array
{
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/** Load one DB definition as a spec array (no PHP fallback). */
function survey_definition_load(string $key): ?array
{
    try {
        $st = db()->prepare("SELECT definition FROM survey_definitions WHERE spec_key = :k LIMIT 1");
        $st->execute([':k' => $key]);
        $row = $st->fetch();
        if (!$row) return null;
        $spec = survey_definition_decode((string)$row['definition']);
        if ($spec === null) return null;
        $spec['key'] = $key;
        return $spec;
    } catch (Throwable $e) {
        return null; // pre-migration
    }
}

/** Raw rows from survey_definitions (may be empty / missing table). */
function survey_definition_list(): array
{
    try {
        return db()->query("SELECT spec_key, title, definition, created_by, created_at, updated_at
                              FROM survey_definitions ORDER BY title, spec_key")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function survey_definition_get(string $key): ?array
{
    try {
        $st = db()->prepare("SELECT * FROM survey_definitions WHERE spec_key = :k LIMIT 1");
        $st->execute([':k' => $key]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Validate a JSON spec. Returns ['ok' => bool, 'errors' => string[], 'spec' => ?array].
 * Does not check PHP-key reservation (callers do that on upsert).
 */
function survey_definition_validate($raw): array
{
    $errors = [];
    if (!is_array($raw)) {
        return ['ok' => false, 'errors' => ['Spec must be a JSON object.'], 'spec' => null];
    }
    $key = trim((string)($raw['key'] ?? ''));
    if ($key === '') {
        $errors[] = 'key is required.';
    } elseif (strlen($key) > SURVEY_DEF_KEY_MAX) {
        $errors[] = 'key must be at most ' . SURVEY_DEF_KEY_MAX . ' characters.';
    } elseif (!preg_match('/^[a-z][a-z0-9_]{1,62}$/', $key)) {
        $errors[] = 'key must be lowercase snake_case starting with a letter (a-z0-9_).';
    }

    $title = trim((string)($raw['title'] ?? ''));
    if ($title === '') $errors[] = 'title is required.';

    $sections = $raw['sections'] ?? null;
    if (!is_array($sections) || $sections === []) {
        $errors[] = 'sections must be a non-empty array.';
        $sections = [];
    }

    $seenKeys = [];
    foreach ($sections as $si => $sec) {
        if (!is_array($sec)) {
            $errors[] = "sections[$si] must be an object.";
            continue;
        }
        if (trim((string)($sec['title'] ?? '')) === '') {
            $errors[] = "sections[$si].title is required.";
        }
        $questions = $sec['questions'] ?? null;
        if (!is_array($questions) || $questions === []) {
            $errors[] = "sections[$si].questions must be a non-empty array.";
            continue;
        }
        foreach ($questions as $qi => $q) {
            if (!is_array($q)) {
                $errors[] = "sections[$si].questions[$qi] must be an object.";
                continue;
            }
            $qk = trim((string)($q['key'] ?? ''));
            if ($qk === '' || !preg_match('/^[a-z][a-z0-9_]{0,62}$/', $qk)) {
                $errors[] = "sections[$si].questions[$qi].key must be lowercase snake_case.";
            } elseif (isset($seenKeys[$qk])) {
                $errors[] = "Duplicate question key '$qk'.";
            } else {
                $seenKeys[$qk] = true;
            }
            $type = (string)($q['type'] ?? 'text');
            if (!in_array($type, SURVEY_DEF_ALLOWED_TYPES, true)) {
                $errors[] = "Question '$qk' has unknown type '$type'.";
            }
            if (in_array($type, ['radio', 'checkbox', 'select'], true)) {
                $opts = $q['options'] ?? null;
                if (is_string($opts)) {
                    if (!in_array($opts, SURVEY_DEF_OPTION_SOURCES, true)) {
                        $errors[] = "Question '$qk' options source '$opts' is not allowed.";
                    }
                } elseif (!is_array($opts) || $opts === []) {
                    $errors[] = "Question '$qk' needs options (map or classes|students|parents).";
                }
            }
            if ($type === 'matrix') {
                if (!is_array($q['scale'] ?? null) || ($q['scale'] ?? []) === []) {
                    $errors[] = "Question '$qk' matrix needs a scale map.";
                }
                if (!is_array($q['rows'] ?? null) || ($q['rows'] ?? []) === []) {
                    $errors[] = "Question '$qk' matrix needs a rows map.";
                }
            }
            if ($type === 'student_picker' && isset($q['fills']) && !is_array($q['fills'])) {
                $errors[] = "Question '$qk' fills must be an object.";
            }
            if (isset($q['options_filter']) && !is_array($q['options_filter'])) {
                $errors[] = "Question '$qk' options_filter must be an object.";
            }
        }
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'spec' => null];
    }

    $spec = [
        'key'      => $key,
        'title'    => $title,
        'subtitle' => (string)($raw['subtitle'] ?? ''),
        'intro'    => (string)($raw['intro'] ?? ''),
        'thanks'   => (string)($raw['thanks'] ?? ''),
        'sections' => $sections,
    ];
    if (!empty($raw['one_per_child'])) $spec['one_per_child'] = true;
    if (!empty($raw['roster'])) {
        $spec['roster'] = true;
        $spec['roster_consent_key'] = (string)($raw['roster_consent_key'] ?? 'consent');
    }

    return ['ok' => true, 'errors' => [], 'spec' => $spec];
}

/**
 * Create or update a DB survey definition. Throws InvalidArgumentException on
 * validation failure or when the key is reserved by a PHP spec.
 */
function survey_definition_upsert(array $raw, ?int $byUserId = null): array
{
    $v = survey_definition_validate($raw);
    if (!$v['ok']) {
        throw new InvalidArgumentException(implode(' ', $v['errors']));
    }
    $spec = $v['spec'];
    $key  = (string)$spec['key'];
    if (survey_spec_is_php($key)) {
        throw new InvalidArgumentException(
            "Spec key '$key' is defined in PHP and cannot be created or changed via MCP. "
          . "Choose a new key for a new survey."
        );
    }

    $json = json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new InvalidArgumentException('Could not encode definition as JSON.');
    }

    db()->prepare("
        INSERT INTO survey_definitions (spec_key, title, definition, created_by)
        VALUES (:k, :t, :d, :by)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            definition = VALUES(definition),
            updated_at = CURRENT_TIMESTAMP
    ")->execute([
        ':k'  => $key,
        ':t'  => (string)$spec['title'],
        ':d'  => $json,
        ':by' => $byUserId,
    ]);

    return $spec;
}

// ---- Signed per-student prefills ----------------------------------------

function survey_prefill_secret(): string
{
    try {
        $cfg = app_config();
        $s = (string)($cfg['app']['survey_prefill_secret'] ?? '');
        if ($s !== '') return $s;
        // Stable fallback so prefills work before a dedicated secret is set.
        return hash('sha256', 'survey-prefill|'
            . (string)($cfg['db']['name'] ?? '') . '|'
            . (string)($cfg['db']['password'] ?? 'x'));
    } catch (Throwable $e) {
        return hash('sha256', 'survey-prefill-fallback');
    }
}

/**
 * Build a signed pref payload for one student on one survey.
 * Returns the opaque string to put in ?pref=
 */
function survey_prefill_sign(int $surveyId, int $studentId, ?int $ttlSeconds = null): string
{
    $ttl = $ttlSeconds ?? SURVEY_PREFILL_TTL_DEFAULT;
    $exp = time() + max(60, $ttl);
    $body = $surveyId . '.' . $studentId . '.' . $exp;
    $sig  = hash_hmac('sha256', $body, survey_prefill_secret());
    return rtrim(strtr(base64_encode($body . '.' . $sig), '+/', '-_'), '=');
}

/**
 * Verify ?pref= and return ['survey_id'=>int,'student_id'=>int,'exp'=>int] or null.
 */
function survey_prefill_verify(string $token): ?array
{
    $token = trim($token);
    if ($token === '') return null;
    $pad = strlen($token) % 4;
    if ($pad) $token .= str_repeat('=', 4 - $pad);
    $raw = base64_decode(strtr($token, '-_', '+/'), true);
    if ($raw === false) return null;
    $parts = explode('.', $raw);
    if (count($parts) !== 4) return null;
    [$sid, $studentId, $exp, $sig] = $parts;
    if (!ctype_digit($sid) || !ctype_digit($studentId) || !ctype_digit($exp)) return null;
    $body = $sid . '.' . $studentId . '.' . $exp;
    $expect = hash_hmac('sha256', $body, survey_prefill_secret());
    if (!hash_equals($expect, $sig)) return null;
    if ((int)$exp < time()) return null;
    return [
        'survey_id'  => (int)$sid,
        'student_id' => (int)$studentId,
        'exp'        => (int)$exp,
    ];
}

/**
 * Resolve prefills for the public form: identity field values + optional hide picker.
 * Returns ['values' => [field => value], 'student_id' => int, 'hide_picker' => true] or null.
 */
function survey_prefill_for_form(array $survey, array $spec, string $prefToken): ?array
{
    $verified = survey_prefill_verify($prefToken);
    if (!$verified) return null;
    if ((int)$verified['survey_id'] !== (int)$survey['id']) return null;
    $data = survey_student_fill_data((int)$verified['student_id']);
    if ($data === null) return null;

    // Collect fills from the first student_picker in the spec; fall back to
    // the usual identity fields so a prefill link still helps bare forms.
    $fills = [
        'child_name'  => 'full_name',
        'class'       => 'grade',
        'parent_name' => 'primary_parent',
    ];
    $pickerKey = null;
    foreach (survey_questions($spec) as $q) {
        if (($q['type'] ?? '') === 'student_picker') {
            $pickerKey = (string)$q['key'];
            if (!empty($q['fills']) && is_array($q['fills'])) {
                $fills = $q['fills'];
            }
            break;
        }
    }
    $values = survey_apply_fills($fills, $data);
    if ($pickerKey !== null) {
        $values[$pickerKey] = (string)$data['student_id'];
    }
    return [
        'values'     => $values,
        'student_id' => (int)$data['student_id'],
        'hide_picker'=> true,
        'data'       => $data,
    ];
}

/**
 * Build share URLs with ?pref= for many students.
 * $studentIds empty + optional grade filter → all matching enrolled students.
 */
function survey_prefill_links(array $survey, array $filter = [], ?array $studentIds = null, ?int $ttl = null): array
{
    $ids = $studentIds;
    if ($ids === null) {
        $opts = survey_student_options($filter);
        $ids = array_map('intval', array_keys($opts));
    }
    $base = survey_url((string)$survey['token']);
    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $data = survey_student_fill_data($id);
        if ($data === null) continue;
        $pref = survey_prefill_sign((int)$survey['id'], $id, $ttl);
        $out[] = [
            'student_id' => $id,
            'child_name' => $data['full_name'],
            'class'      => $data['grade'],
            'parent_name'=> $data['primary_parent'],
            'url'        => $base . '&pref=' . rawurlencode($pref),
        ];
    }
    return $out;
}
