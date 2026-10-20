<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Assign mentees to mentors, change mentors and end pairings.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use local_mentorship\pairing;

admin_externalpage_setup('local_mentorship_manage');
// Same design file as the training report and My mentorship.
$PAGE->requires->css('/local/mentorship/styles/report.css');

$endid = optional_param('end', 0, PARAM_INT);
$changeid = optional_param('change', 0, PARAM_INT);
$showended = optional_param('showended', 0, PARAM_BOOL);

if ($endid) {
    require_sesskey();
    pairing::end($endid);
    redirect($PAGE->url, get_string('pairingended', 'local_mentorship'), null, notification::NOTIFY_SUCCESS);
}

$userfields = implode(', ', array_unique(array_merge(['id'], \core_user\fields::for_userpic()->get_required_fields())));
$template = ['changemode' => false];

if ($changeid) {
    // Change mentor: end the current pairing (kept as history) and start a new one.
    $pairing = $DB->get_record(pairing::TABLE, ['id' => $changeid], '*', MUST_EXIST);
    if (!pairing::is_active($pairing)) {
        redirect($PAGE->url, get_string('error:notactive', 'local_mentorship'), null, notification::NOTIFY_ERROR);
    }
    $changeform = new \local_mentorship\form\change_mentor_form(null, ['pairing' => $pairing]);
    if ($changeform->is_cancelled()) {
        redirect($PAGE->url);
    }
    if ($data = $changeform->get_data()) {
        pairing::change_mentor((int) $pairing->id, (int) $data->mentorid, !empty($data->notify));
        redirect($PAGE->url, get_string('mentorchanged', 'local_mentorship'), null, notification::NOTIFY_SUCCESS);
    }
    $mentee = $DB->get_record('user', ['id' => $pairing->menteeid], $userfields, MUST_EXIST);
    $mentor = $DB->get_record('user', ['id' => $pairing->mentorid], $userfields, MUST_EXIST);
    $template['changemode'] = true;
    $template['change'] = [
        'menteename' => fullname($mentee),
        'menteepicture' => $OUTPUT->user_picture($mentee, ['size' => 35, 'link' => false]),
        'mentorname' => fullname($mentor),
        'mentorpicture' => $OUTPUT->user_picture($mentor, ['size' => 35, 'link' => false]),
        'formhtml' => $changeform->render(),
    ];
} else {
    $form = new \local_mentorship\form\pairing_form();
    if ($data = $form->get_data()) {
        // One transaction, so a mentee refused at the last second rolls back the whole batch.
        $count = 0;
        $transaction = $DB->start_delegated_transaction();
        foreach (array_unique(array_map('intval', (array) $data->menteeids)) as $menteeid) {
            pairing::create((int) $data->mentorid, $menteeid, !empty($data->notify));
            $count++;
        }
        $transaction->allow_commit();
        redirect($PAGE->url, get_string('pairingscreated', 'local_mentorship', $count), null,
            notification::NOTIFY_SUCCESS);
    }
    $template['formhtml'] = $form->render();
}

// Pairings involving a suspended or deleted user are left out of the table and the counts.
// No paging yet; add paging if pairings grow past a few hundred.
$pairings = $DB->get_records_sql(
    "SELECT p.*
       FROM {" . pairing::TABLE . "} p
       JOIN {user} mu ON mu.id = p.mentorid AND mu.suspended = 0 AND mu.deleted = 0
       JOIN {user} eu ON eu.id = p.menteeid AND eu.suspended = 0 AND eu.deleted = 0
   ORDER BY p.timecreated DESC"
);
$userids = [];
$activementors = [];
$activementees = [];
$endedcount = 0;
foreach ($pairings as $p) {
    $userids[$p->mentorid] = $p->mentorid;
    $userids[$p->menteeid] = $p->menteeid;
    $p->isactive = pairing::is_active($p);
    if ($p->isactive) {
        $activementors[$p->mentorid] = true;
        $activementees[$p->menteeid] = true;
    } else {
        $endedcount++;
    }
}
// Users holding both relationships right now (middle of a chain).
$bothroles = array_intersect_key($activementors, $activementees);
$users = $userids ? $DB->get_records_list('user', 'id', $userids, '', $userfields) : [];

$person = function(int $userid) use ($users, $bothroles, $OUTPUT): array {
    $user = $users[$userid] ?? null;
    return [
        'name' => $user ? fullname($user) : '-',
        'picture' => $user ? $OUTPUT->user_picture($user, ['size' => 35, 'link' => false]) : '',
        'both' => isset($bothroles[$userid]),
    ];
};

$rows = [];
foreach ($pairings as $p) {
    if (!$p->isactive && !$showended) {
        continue;
    }
    $rows[] = [
        'mentor' => $person((int) $p->mentorid),
        'mentee' => $person((int) $p->menteeid),
        'assigned' => userdate($p->timecreated, get_string('strftimedate', 'langconfig')),
        'active' => $p->isactive,
        'status' => get_string($p->isactive ? 'active' : 'ended', 'local_mentorship'),
        'viewurl' => (new moodle_url('/local/mentorship/view.php', ['id' => $p->id]))->out(false),
        'changeurl' => (new moodle_url($PAGE->url, ['change' => $p->id]))->out(false),
        'endid' => $p->id,
    ];
}
// Active first, then by mentor name, then mentee name.
usort($rows, fn($a, $b) => [$b['active'], $a['mentor']['name'], $a['mentee']['name']]
    <=> [$a['active'], $b['mentor']['name'], $b['mentee']['name']]);

$template += [
    'stats' => [
        ['cls' => 'stat-total', 'icon' => 'fa-link', 'value' => count($pairings) - $endedcount,
            'label' => get_string('activepairings', 'local_mentorship')],
        ['cls' => 'stat-inprogress', 'icon' => 'fa-user', 'value' => count($activementors),
            'label' => get_string('mentors', 'local_mentorship')],
        ['cls' => 'stat-completed', 'icon' => 'fa-users', 'value' => count($activementees),
            'label' => get_string('mentees', 'local_mentorship')],
        ['cls' => 'stat-rate', 'icon' => 'fa-exchange', 'value' => count($bothroles),
            'label' => get_string('inbothroles', 'local_mentorship')],
    ],
    'hasrows' => !empty($rows),
    'rows' => $rows,
    'manageurl' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'hasended' => $endedcount > 0,
    'showended' => (bool) $showended,
    'toggleendedurl' => (new moodle_url($PAGE->url, $showended ? [] : ['showended' => 1]))->out(false),
    'toggleendedlabel' => $showended
        ? get_string('hideended', 'local_mentorship')
        : get_string('showended', 'local_mentorship', $endedcount),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_mentorship/manage', $template);
echo $OUTPUT->footer();
