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

/** One spec by key, or null. */
function survey_spec(string $key): ?array
{
    return survey_specs()[$key] ?? null;
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
 * 2. Photograph permission is ONE required radio with four escalating levels,
 *    not a set of tickboxes. An untouched checkbox cannot be told apart from a
 *    declined one, and "we assumed the blank meant yes" is not a sentence
 *    anybody wants to say to a parent. One required choice is auditable.
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
        'intro'    => "We are planning a field trip for Playgroup, Nursery, LKG and UKG, and we "
                    . "need your written permission before your child can join.\n\n"
                    . "TRIP DETAILS — destination, date, departure and return times, how the "
                    . "children will travel, and any cost — TO BE CONFIRMED BEFORE THIS FORM IS "
                    . "SENT TO PARENTS.\n\n"
                    . "Children will be supervised by their own class teachers throughout, and "
                    . "our usual staff-to-child ratios apply. Please complete one form per child.",
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
                     'label' => 'Where are you happy for photographs or videos of your child to be used?',
                     'help'  => 'We photograph and film children during the school day, at '
                              . 'celebrations and on trips, because those moments are worth keeping '
                              . 'and sharing. Please choose the one option you are comfortable with. '
                              . 'This is your standing preference for the whole year, not just this '
                              . 'trip, and you can change it at any time by telling the school '
                              . 'office. We never publish your child\'s full name, class or any '
                              . 'contact detail alongside a picture, and we never share images with '
                              . 'anyone outside the school for their own use.',
                     'options' => [
                         'public'   => 'Anywhere, including the school\'s social media, website and '
                                     . 'publicity material such as brochures and banners',
                         'social'   => 'The school\'s own social media and website, but not printed '
                                     . 'publicity or advertising',
                         'parents'  => 'Inside school, and privately with the parents of my child\'s '
                                     . 'class — but nowhere public',
                         'internal' => 'Inside school only — classroom displays and my child\'s own '
                                     . 'portfolio and reports',
                         'none'     => 'Nowhere — please do not photograph or film my child',
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
    if ($o === 'classes') return survey_class_options();
    return is_array($o) ? $o : [];
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

/** Mint a fresh token, retiring the old link. */
function survey_reissue_token(int $surveyId): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare("UPDATE surveys SET token = :t WHERE id = :id")
        ->execute([':t' => $token, ':id' => $surveyId]);
    return $token;
}

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

/** Thrown when a spec allows one response per child and one already exists. */
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

        if ($type === 'radio' || $type === 'checkbox') {
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
