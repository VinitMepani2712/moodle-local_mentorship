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
 * My mentorship: split view of the user's mentees with a summary of the selected one.
 *
 * Layout "Option B+" from the design canvas. Selection and search are plain
 * query parameters, so the page needs no JavaScript.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_mentorship\pairing;
use local_mentorship\report;

$selectedid = optional_param('mentee', 0, PARAM_INT);
$q = trim(optional_param('q', '', PARAM_TEXT));
$sort = optional_param('sort', 'name', PARAM_ALPHA);
$sort = in_array($sort, ['name', 'progressasc', 'progressdesc'], true) ? $sort : 'name';

require_login();

$baseurl = new moodle_url('/local/mentorship/index.php');
$PAGE->set_url($baseurl, array_filter(['mentee' => $selectedid, 'q' => $q, 'sort' => $sort === 'name' ? '' : $sort]));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mymentorship', 'local_mentorship'));
$PAGE->set_heading(get_string('mymentorship', 'local_mentorship'));
$PAGE->set_primary_active_tab('local_mentorship');
// Same design file as the training report and the admin page.
$PAGE->requires->css('/local/mentorship/styles/report.css');
// Live search, clear and sort for the mentee list; the page still works without it.
$PAGE->requires->js_call_amd('local_mentorship/mentees', 'init');

$nofilters = ['courseid' => 0, 'q' => '', 'statuses' => [], 'overdueonly' => false, 'sortby' => 'fullname',
    'sortdir' => 'asc', 'enrollfrom' => '', 'enrollto' => '', 'closefrom' => '', 'closeto' => ''];

/**
 * Summary of one mentee's training, from the same report the pairing page shows.
 *
 * @param int $userid
 * @param array $nofilters
 * @return array
 */
function local_mentorship_index_summary(int $userid, array $nofilters): array {
    $report = report::build($userid, $nofilters);
    $s = $report['stats'];
    $pcts = array_filter(array_column($report['courses'], 'completionpct'), fn($p) => $p !== null);
    $pct = $pcts ? (int) round(array_sum($pcts) / count($pcts)) : 0;

    if ($s['total'] > 0 && $s['completed'] === $s['total']) {
        $state = 'done';
        $label = get_string('state:done', 'local_mentorship');
    } else if ($s['overdue'] > 0) {
        $state = 'attention';
        $label = get_string('state:overdue', 'local_mentorship', $s['overdue']);
    } else if ($pct === 0) {
        $state = 'notstarted';
        $label = get_string('state:notstarted', 'local_mentorship');
    } else {
        $state = 'ontrack';
        $label = get_string('state:ontrack', 'local_mentorship');
    }
    return ['report' => $report, 'stats' => $s, 'pct' => $pct, 'state' => $state, 'label' => $label];
}

$mentors = pairing::get_mentors((int) $USER->id);
$mentees = pairing::get_mentees((int) $USER->id);

// One report per mentee for the list states. Fine for a handful of mentees per
// mentor; cache the per-mentee summary if mentors get dozens.
$summaries = [];
foreach ($mentees as $pairingid => $user) {
    $summaries[$pairingid] = local_mentorship_index_summary((int) $user->id, $nofilters);
}

// Mentee list. Every mentee is rendered; ones not matching the search are hidden, so clearing
// the search in the browser shows them again without a reload.
$linkparams = fn(array $extra) => array_filter(['q' => $q, 'sort' => $sort === 'name' ? '' : $sort] + $extra);
$list = [];
$firstmatch = 0;
foreach ($mentees as $pairingid => $user) {
    $sum = $summaries[$pairingid];
    $match = $q === '' || core_text::strpos(core_text::strtolower(fullname($user)), core_text::strtolower($q)) !== false;
    $firstmatch = $firstmatch ?: ($match ? (int) $pairingid : 0);
    $list[$pairingid] = [
        'name' => fullname($user),
        'sortname' => core_text::strtolower(fullname($user)),
        'picture' => $OUTPUT->user_picture($user, ['size' => 35, 'link' => false]),
        'pct' => $sum['pct'],
        'state' => $sum['state'],
        'statelabel' => $sum['label'],
        'url' => (new moodle_url($baseurl, $linkparams(['mentee' => $pairingid])))->out(false),
        'active' => false,
        'hidden' => !$match,
    ];
}
uasort($list, function ($a, $b) use ($sort) {
    if ($sort !== 'name' && $a['pct'] !== $b['pct']) {
        return $sort === 'progressasc' ? $a['pct'] <=> $b['pct'] : $b['pct'] <=> $a['pct'];
    }
    return strcmp($a['sortname'], $b['sortname']);
});
$shown = count(array_filter($list, fn($m) => !$m['hidden']));
// Keep the requested mentee selected even when the search hides it; otherwise pick the first match.
if (!isset($mentees[$selectedid])) {
    $selectedid = $firstmatch ?: (int) (array_key_first($list) ?? 0);
}
if (isset($list[$selectedid])) {
    $list[$selectedid]['active'] = true;
}

$sortoptions = [];
foreach (['name', 'progressasc', 'progressdesc'] as $key) {
    $sortoptions[] = ['value' => $key, 'label' => get_string('sort:' . $key, 'local_mentorship'), 'selected' => $key === $sort];
}


// Selected mentee summary.
$sel = null;
if ($selectedid && isset($mentees[$selectedid])) {
    $user = $mentees[$selectedid];
    $sum = $summaries[$selectedid];
    $s = $sum['stats'];
    $courses = [];
    $overdue = [];
    $recent = [];
    foreach ($sum['report']['courses'] as $course) {
        $context = context_course::instance($course->id);
        $coursename = format_string($course->fullname, true, ['context' => $context, 'escape' => false]);
        $pct = (int) round($course->completionpct ?? 0);
        $courses[] = ['name' => $coursename, 'pct' => $pct, 'pctcomplete' => $pct >= 100, 'status' => $course->status,
            'badge' => report::badge($course->status)];
        foreach ($course->activities as $a) {
            if ($a->completionstatus === 'overdue') {
                $due = userdate($a->closedate, get_string('strftimedate', 'langconfig'));
                $overdue[] = [
                    'name' => format_string($a->name, true, ['context' => $context, 'escape' => false]),
                    'course' => $coursename,
                    'due' => get_string('due', 'local_mentorship', $due),
                    'sort' => $a->closedate,
                ];
            } else if ($a->completionstatus === 'completed' && !empty($a->completiondate)) {
                $recent[] = ['name' => format_string($a->name, true, ['context' => $context, 'escape' => false]),
                    'when' => get_string(
                        'completedon',
                        'local_mentorship',
                        userdate($a->completiondate, get_string('strftimedate', 'langconfig'))
                    ),
                    'sort' => $a->completiondate];
            }
        }
    }
    usort($overdue, fn($a, $b) => $a['sort'] <=> $b['sort']);
    usort($recent, fn($a, $b) => $b['sort'] <=> $a['sort']);
    $recent = array_slice($recent, 0, 4);

    $sel = [
        'name' => fullname($user),
        'pct' => $sum['pct'],
        'state' => $sum['state'],
        'stateshort' => get_string('state:' . $sum['state'] . ':short', 'local_mentorship'),
        'summary' => get_string(
            $s['overdue'] ? 'summary:overdue' : 'summary',
            'local_mentorship',
            (object) ['done' => $s['completed'], 'total' => $s['total'], 'overdue' => $s['overdue']]
        ),
        'reporturl' => (new moodle_url('/local/mentorship/view.php', ['id' => $selectedid]))->out(false),
        'messageurl' => (new moodle_url('/message/index.php', ['id' => $user->id]))->out(false),
        'messagelabel' => get_string('messageuser', 'local_mentorship', fullname($user)),
        'stats' => [
            ['cls' => 'total', 'icon' => 'fa-book', 'value' => $s['total'],
                'label' => get_string('report:totalcourses', 'local_mentorship')],
            ['cls' => 'completed', 'icon' => 'fa-check', 'value' => $s['completed'],
                'label' => get_string('report:completed', 'local_mentorship')],
            ['cls' => 'inprogress', 'icon' => 'fa-spinner', 'value' => $s['inprogress'],
                'label' => get_string('report:inprogress', 'local_mentorship')],
            ['cls' => 'overdue', 'icon' => 'fa-exclamation-triangle', 'value' => $s['overdue'],
                'label' => get_string('report:overdueitems', 'local_mentorship')],
        ],
        'hascourses' => !empty($courses),
        'courses' => $courses,
        'hasoverdue' => !empty($overdue),
        'overdue' => $overdue,
        'hasrecent' => !empty($recent),
        'recent' => $recent,
    ];
}

$mentorlist = [];
foreach ($mentors as $pairingid => $mentor) {
    $mentorlist[] = [
        'name' => fullname($mentor),
        'picture' => $OUTPUT->user_picture($mentor, ['size' => 40, 'link' => false]),
        'reporturl' => (new moodle_url('/local/mentorship/view.php', ['id' => $pairingid]))->out(false),
        'messageurl' => (new moodle_url('/message/index.php', ['id' => $mentor->id]))->out(false),
        'messagelabel' => get_string('messageuser', 'local_mentorship', fullname($mentor)),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_mentorship/index', [
    'name' => fullname($USER),
    'picture' => $OUTPUT->user_picture($USER, ['size' => 64, 'link' => false]),
    'totals' => [
        'mentees' => count($mentees),
    ],
    'hasmentors' => !empty($mentorlist),
    'mentors' => $mentorlist,
    'hasmentees' => !empty($mentees),
    'action' => $baseurl->out(false),
    'q' => $q,
    'sortoptions' => $sortoptions,
    'shown' => $shown === count($list) ? (string) $shown
        : get_string('menteecount', 'local_mentorship', (object) ['shown' => $shown, 'total' => count($list)]),
    'list' => array_values($list),
    'emptylist' => $shown === 0,
    'hassel' => $sel !== null,
    'sel' => $sel,
    'none' => !$mentors && !$mentees,
]);
echo $OUTPUT->footer();
