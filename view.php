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
 * Pairing page: the mentee's training report, visible to the mentor, the mentee and managers.
 *
 * // Report layout, filters and rules ported from local_staticpage/gradereport.php.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_mentorship\pairing;
use local_mentorship\report;

$id = required_param('id', PARAM_INT);
$export = optional_param('export', '', PARAM_ALPHA);

// Filters. Values are whitelisted so nothing unexpected reaches the report or the URLs.
$sortby = optional_param('sortby', 'fullname', PARAM_ALPHA);
$f = [
    'courseid' => optional_param('courseid', 0, PARAM_INT),
    'q' => trim(optional_param('q', '', PARAM_TEXT)),
    'statuses' => array_values(array_intersect(optional_param_array('statuses', [], PARAM_ALPHA), report::STATUSES)),
    'overdueonly' => optional_param('overdueonly', 0, PARAM_BOOL),
    'sortby' => in_array($sortby, report::SORT_FIELDS, true) ? $sortby : 'fullname',
    'sortdir' => optional_param('sortdir', 'asc', PARAM_ALPHA) === 'desc' ? 'desc' : 'asc',
    'enrollfrom' => optional_param('enrollfrom', '', PARAM_ALPHANUMEXT),
    'enrollto' => optional_param('enrollto', '', PARAM_ALPHANUMEXT),
    'closefrom' => optional_param('closefrom', '', PARAM_ALPHANUMEXT),
    'closeto' => optional_param('closeto', '', PARAM_ALPHANUMEXT),
];

require_login();

$context = context_system::instance();
$pairing = $DB->get_record(pairing::TABLE, ['id' => $id], '*', MUST_EXIST);

// Pairing membership grants access here instead of a role in the mentee's user context.
if (!pairing::can_view($pairing, (int) $USER->id)) {
    throw new moodle_exception('nopermissions', 'error', '', get_string('viewpairing', 'local_mentorship'));
}

$mentor = core_user::get_user($pairing->mentorid, '*', MUST_EXIST);
$mentee = core_user::get_user($pairing->menteeid, '*', MUST_EXIST);
$ismember = in_array((int) $USER->id, [(int) $mentor->id, (int) $mentee->id], true);

$baseurl = new moodle_url('/local/mentorship/view.php', ['id' => $id]);
$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('report:title', 'local_mentorship', fullname($mentee)));
$PAGE->set_heading(get_string($ismember ? 'mymentorship' : 'managepairings', 'local_mentorship'));
if ($ismember) {
    $PAGE->set_primary_active_tab('local_mentorship');
}
// Loaded only on this page; a separate file keeps the report's font @import valid.
$PAGE->requires->css('/local/mentorship/styles/report.css');
$PAGE->requires->js_call_amd('local_mentorship/report', 'init');

$report = report::build((int) $mentee->id, $f);

if ($export === 'csv') {
    report::export_csv($mentee, $report['courses']);
    exit;
}

// Query params for the current filters, reused by the export link.
$params = ['id' => $id, 'sortby' => $f['sortby'], 'sortdir' => $f['sortdir']];
foreach (['courseid', 'q', 'overdueonly', 'enrollfrom', 'enrollto', 'closefrom', 'closeto'] as $key) {
    if (!empty($f[$key])) {
        $params[$key] = $f[$key];
    }
}
$exporturl = (new moodle_url('/local/mentorship/view.php', $params + ['export' => 'csv']))->out(false);
foreach ($f['statuses'] as $status) {
    $exporturl .= '&statuses%5B%5D=' . rawurlencode($status);
}

// Mentee selector: the viewer's own report (as a mentee) plus each of their mentees.
$selector = [];
$current = false;
$mymentors = pairing::get_mentors((int) $USER->id);
if ($mymentors) {
    $pid = array_key_first($mymentors);
    $current = $current || $pid === $id;
    $selector[] = ['url' => (new moodle_url('/local/mentorship/view.php', ['id' => $pid]))->out(false),
        'label' => get_string('report:me', 'local_mentorship', fullname($USER)), 'selected' => $pid === $id];
}
$mymentees = pairing::get_mentees((int) $USER->id);
foreach ($mymentees as $pid => $user) {
    $current = $current || $pid === $id;
    $selector[] = ['url' => (new moodle_url('/local/mentorship/view.php', ['id' => $pid]))->out(false),
        'label' => fullname($user), 'selected' => $pid === $id];
}

$active = pairing::is_active($pairing);
$other = (int) $USER->id === (int) $mentee->id ? $mentor : $mentee;
$s = $report['stats'];

$statusicons = ['completed' => 'fa-check-circle', 'inprogress' => 'fa-spinner', 'failed' => 'fa-times-circle',
    'notyetattempted' => 'fa-circle-o'];
$statusoptions = [];
foreach (report::STATUSES as $status) {
    $statusoptions[] = [
        'value' => $status,
        'label' => report::status_label($status),
        'cls' => $status === 'notyetattempted' ? 'notattempted' : $status,
        'icon' => $statusicons[$status],
        'checked' => in_array($status, $f['statuses'], true),
    ];
}

$sortoptions = [];
foreach (report::SORT_FIELDS as $field) {
    $sortoptions[] = ['value' => $field, 'label' => get_string('report:sortby_' . $field, 'local_mentorship'),
        'selected' => $field === $f['sortby']];
}

$courseoptions = [];
foreach (report::get_user_courses((int) $mentee->id) as $course) {
    $courseoptions[] = ['id' => $course->id, 'selected' => (int) $course->id === $f['courseid'],
        'name' => format_string($course->fullname, true, ['context' => context_course::instance($course->id), 'escape' => false])];
}

$transcript = file_exists($CFG->dirroot . '/user/transcript/transcript.php');
$courses = report::courses_for_template($report['courses']);

$data = [
    'backurl' => (new moodle_url($ismember ? '/local/mentorship/index.php' : '/local/mentorship/manage.php'))->out(false),
    'backlabel' => get_string($ismember ? 'backtomentorship' : 'backtopairings', 'local_mentorship'),
    'hasselector' => !empty($mymentees),
    'selectorprompt' => !$current,
    'selector' => $selector,
    'name' => fullname($mentee),
    'picture' => $OUTPUT->user_picture($mentee, ['size' => 64, 'link' => false, 'class' => 'egr-user-avatar']),
    'mentoredby' => get_string('mentoredby', 'local_mentorship', fullname($mentor)),
    'active' => $active,
    'status' => get_string($active ? 'active' : 'ended', 'local_mentorship'),
    'profileurl' => (new moodle_url('/user/profile.php', ['id' => $mentee->id]))->out(false),
    'hastranscript' => $transcript,
    'transcripturl' => $transcript ? (new moodle_url('/user/transcript/transcript.php', ['id' => $mentee->id]))->out(false) : '',
    'hasmessage' => $ismember && $active,
    'messageurl' => (new moodle_url('/message/index.php', ['id' => $other->id]))->out(false),
    'messagelabel' => get_string('messageuser', 'local_mentorship', fullname($other)),
    'stats' => [
        ['cls' => 'stat-total', 'icon' => 'fa-book', 'value' => $s['total'],
            'label' => get_string('report:totalcourses', 'local_mentorship')],
        ['cls' => 'stat-completed', 'icon' => 'fa-check-circle', 'value' => $s['completed'],
            'label' => get_string('report:completedcourses', 'local_mentorship')],
        ['cls' => 'stat-failed', 'icon' => 'fa-times-circle', 'value' => $s['failed'],
            'label' => get_string('report:failedcourses', 'local_mentorship')],
        ['cls' => 'stat-inprogress', 'icon' => 'fa-spinner', 'value' => $s['inprogress'],
            'label' => get_string('report:inprogresscourses', 'local_mentorship')],
        ['cls' => 'stat-overdue', 'icon' => 'fa-exclamation-triangle', 'value' => $s['overdue'],
            'label' => get_string('report:overdueitems', 'local_mentorship')],
        ['cls' => 'stat-rate', 'icon' => 'fa-pie-chart', 'value' => $s['rate'] . '%',
            'label' => get_string('report:completionrate', 'local_mentorship')],
    ],
    'filters' => [
        'action' => (new moodle_url('/local/mentorship/view.php'))->out(false),
        'id' => $id,
        'q' => $f['q'],
        'courseoptions' => $courseoptions,
        'sortoptions' => $sortoptions,
        'sortdir' => $f['sortdir'],
        'asc' => $f['sortdir'] === 'asc',
        'desc' => $f['sortdir'] === 'desc',
        'statusoptions' => $statusoptions,
        'overdueonly' => (bool) $f['overdueonly'],
        'enrollfrom' => $f['enrollfrom'],
        'enrollto' => $f['enrollto'],
        'closefrom' => $f['closefrom'],
        'closeto' => $f['closeto'],
        'datesactive' => $f['enrollfrom'] || $f['enrollto'] || $f['closefrom'] || $f['closeto'],
        'clearurl' => $baseurl->out(false),
        'exporturl' => $exporturl,
    ],
    'hascourses' => !empty($courses),
    'courses' => $courses,
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_mentorship/view', $data);
echo $OUTPUT->footer();
