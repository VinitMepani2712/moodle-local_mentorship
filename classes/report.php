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

namespace local_mentorship;

use stdClass;

/**
 * Mentee training report: courses, activities, completion, grades and pass/fail.
 *
 * //VM: Ported from local_staticpage gradereport_lib.php / gradereport.php. Same SQL, same
 * //VM: 70% pass rule, same 2021-07-01 course cutoff and CANCELLED exclusions, so numbers
 * //VM: match the existing grade report exactly.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani, Rutgers DCF-OTPD
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report {
    /** @var float Pass threshold, matches the original report's >= 70.0 rule. */
    const PASS_SCORE = 70.0;

    /** @var string Courses starting before this date are left out. */
    const COURSE_START_DATE = '2021-07-01';

    /** @var string[] Allowed course sort fields. */
    const SORT_FIELDS = ['fullname', 'enrollmentdate', 'status', 'completionpct', 'overdue'];

    /** @var string[] Course statuses that can be filtered. */
    const STATUSES = ['completed', 'inprogress', 'failed', 'notyetattempted'];

    /**
     * Courses where the user has at least one grade. Same query as the original report.
     *
     * @param int $userid
     * @return stdClass[]
     */
    public static function get_graded_courses(int $userid): array {
        global $DB;

        $sql = "SELECT DISTINCT c.id AS cid, c.fullname AS cname, c.startdate
                  FROM {user} u
                  JOIN {grade_grades} gg ON gg.userid = u.id
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                  JOIN {course} c ON c.id = gi.courseid
                 WHERE u.id = :userid
              ORDER BY c.startdate DESC";
        return $DB->get_records_sql($sql, ['userid' => $userid]);
    }

    /**
     * Non-empty grade items for a user in a course. Same query as the original report.
     *
     * @param int $userid
     * @param int $courseid
     * @return stdClass[]
     */
    public static function get_course_grade_items(int $userid, int $courseid): array {
        global $DB;

        $sql = "SELECT gi.id AS grade_id,
                       u.id AS uid,
                       gi.itemname AS itemname,
                       gg.finalgrade AS finalgrade,
                       gi.grademax AS maxgrade,
                       ROUND(gg.finalgrade / gi.grademax * 100, 1) AS score
                  FROM {user} u
                  JOIN {grade_grades} gg ON gg.userid = u.id
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                  JOIN {course} c ON c.id = gi.courseid
                 WHERE u.id = :userid
                   AND c.id = :courseid
                   AND gi.itemname IS NOT NULL AND gi.itemname <> ''
                   AND gi.id IS NOT NULL
                   AND gg.finalgrade IS NOT NULL
                   AND gi.grademax IS NOT NULL AND gi.grademax <> 0";
        return $DB->get_records_sql($sql, ['userid' => $userid, 'courseid' => $courseid]);
    }

    /**
     * Pass or fail for a normalised score.
     *
     * @param float $score 0-100
     * @return string 'passed' or 'failed'
     */
    public static function passfail(float $score): string {
        return $score >= self::PASS_SCORE ? 'passed' : 'failed';
    }

    /**
     * Active enrolled courses for a user, after the cutoff date and without cancelled courses.
     *
     * @param int $userid
     * @return stdClass[]
     */
    public static function get_user_courses(int $userid): array {
        global $DB;

        $sql = "SELECT DISTINCT c.id, c.fullname, c.shortname, c.startdate, c.enddate,
                       MIN(ue.timecreated) AS enrollmentdate,
                       cc.timecompleted AS course_completed_date
                  FROM {course} c
                  JOIN {enrol} e ON e.courseid = c.id
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid
             LEFT JOIN {course_completions} cc ON cc.course = c.id AND cc.userid = :userid2
                 WHERE c.id != :siteid
                   AND c.startdate >= :coursestartdate
                   AND ue.status = 0
                   AND e.status = 0
                   AND c.fullname NOT LIKE 'CANCEL %'
                   AND c.fullname NOT LIKE 'CANCEL,%'
                   AND c.fullname NOT LIKE 'CANCEL\n%'
                   AND c.fullname NOT LIKE '(CANCELLED)%'
                   AND c.fullname NOT LIKE 'CANCELLED%'
                   AND c.fullname NOT LIKE '%** CANCELLED **%'
              GROUP BY c.id, c.fullname, c.shortname, c.startdate, c.enddate, cc.timecompleted
              ORDER BY c.fullname ASC";
        return $DB->get_records_sql($sql, [
            'userid' => $userid,
            'userid2' => $userid,
            'siteid' => SITEID,
            'coursestartdate' => strtotime(self::COURSE_START_DATE . ' 00:00:00'),
        ]);
    }

    /**
     * Visible activities in a course with completion status, dates and grade for a user.
     *
     * //VM: Reads course_modules_completion first so manual completions show immediately,
     * //VM: then the completion API, then close-date logic (same as the original report).
     *
     * @param int $courseid
     * @param int $userid
     * @return stdClass[]
     */
    public static function get_course_activities(int $courseid, int $userid): array {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course, $userid);
        $completion = new \completion_info($course);
        $now = time();

        $activities = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }

            $activity = new stdClass();
            $activity->cmid = $cm->id;
            $activity->name = $cm->name;
            $activity->modname = $cm->modname;
            $activity->icon = $cm->get_icon_url()->out(false);
            $activity->url = $cm->url ? $cm->url->out(false) : '';
            $activity->completiondate = 0;
            $activity->opendate = 0;
            $activity->closedate = 0;

            $instance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
            if (isset($instance->timeopen)) {
                $activity->opendate = (int) $instance->timeopen;
            } else if (isset($instance->allowsubmissionsfromdate)) {
                $activity->opendate = (int) $instance->allowsubmissionsfromdate;
            } else if (isset($instance->timeavailable)) {
                $activity->opendate = (int) $instance->timeavailable;
            }
            if (isset($instance->timeclose)) {
                $activity->closedate = (int) $instance->timeclose;
            } else if (isset($instance->duedate)) {
                $activity->closedate = (int) $instance->duedate;
            } else if (isset($instance->timedue)) {
                $activity->closedate = (int) $instance->timedue;
            } else if (!empty($instance->cutoffdate)) {
                $activity->closedate = (int) $instance->cutoffdate;
            }

            $activity->completionstatus = 'nottracked';
            if ($completion->is_enabled($cm)) {
                $data = $DB->get_record(
                    'course_modules_completion',
                    ['coursemoduleid' => $cm->id, 'userid' => $userid],
                    'completionstate, timemodified',
                    IGNORE_MISSING
                );
                if (!$data) {
                    $data = $completion->get_data($cm, true, $userid);
                }
                $state = isset($data->completionstate) ? (int) $data->completionstate : COMPLETION_INCOMPLETE;

                if ($state === COMPLETION_COMPLETE || $state === COMPLETION_COMPLETE_PASS) {
                    $activity->completionstatus = 'completed';
                    $activity->completiondate = $data->timemodified ?? 0;
                } else if ($state === COMPLETION_COMPLETE_FAIL) {
                    $activity->completionstatus = 'failed';
                    $activity->completiondate = $data->timemodified ?? 0;
                } else if ($activity->closedate > 0 && $activity->closedate < $now) {
                    $activity->completionstatus = 'overdue';
                } else if ($activity->closedate > 0) {
                    $activity->completionstatus = 'inprogress';
                } else {
                    $activity->completionstatus = 'notyetattempted';
                }
            }

            // Same grade formula as the original report: round(finalgrade / grademax * 100, 1).
            $activity->grademax = null;
            $activity->score = null;
            $activity->passfail = '';
            $gradeitem = $DB->get_record(
                'grade_items',
                ['courseid' => $courseid, 'itemmodule' => $cm->modname, 'iteminstance' => $cm->instance]
            );
            if ($gradeitem) {
                $activity->grademax = $gradeitem->grademax;
                $grade = $DB->get_record('grade_grades', ['itemid' => $gradeitem->id, 'userid' => $userid]);
                if (
                    $grade && $grade->finalgrade !== null && $grade->finalgrade !== ''
                        && $gradeitem->grademax !== null && $gradeitem->grademax > 0
                ) {
                    $activity->score = round($grade->finalgrade / $gradeitem->grademax * 100, 1);
                    $activity->passfail = self::passfail($activity->score);
                }
            }

            $activities[] = $activity;
        }
        return $activities;
    }

    /**
     * Share of completion-tracked activities the user completed.
     *
     * @param int $courseid
     * @param int $userid
     * @return float|null Null when the course does not track completion.
     */
    public static function get_course_completion_percentage(int $courseid, int $userid): ?float {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            return null;
        }

        $done = [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS];
        $total = 0;
        $completed = 0;
        foreach (get_fast_modinfo($course, $userid)->get_cms() as $cm) {
            if (!$completion->is_enabled($cm)) {
                continue;
            }
            $total++;
            $record = $DB->get_record(
                'course_modules_completion',
                ['coursemoduleid' => $cm->id, 'userid' => $userid],
                'completionstate',
                IGNORE_MISSING
            );
            if ($record && in_array((int) $record->completionstate, $done, true)) {
                $completed++;
            } else if (in_array((int) $completion->get_data($cm, false, $userid)->completionstate, $done, true)) {
                $completed++;
            }
        }
        return $total ? round($completed / $total * 100, 1) : null;
    }

    /**
     * Build the report for a user.
     *
     * Search and close-date filters apply per activity; status, overdue-only and course filters
     * apply per course. Summary stats always count every course (before status filters).
     *
     * @param int $userid
     * @param array $f Filters: courseid, statuses, q, overdueonly, sortby, sortdir,
     *                 enrollfrom, enrollto, closefrom, closeto.
     * @return array ['courses' => stdClass[], 'stats' => array]
     */
    public static function build(int $userid, array $f): array {
        $enrollfrom = self::date_to_ts($f['enrollfrom']);
        $enrollto = self::date_to_ts($f['enrollto'], true);
        $closefrom = self::date_to_ts($f['closefrom']);
        $closeto = self::date_to_ts($f['closeto'], true);
        $activityfilters = $f['q'] !== '' || $closefrom || $closeto;

        // Grade status per course from the original report SQL.
        $gradestatus = [];
        foreach (self::get_graded_courses($userid) as $gc) {
            $items = array_values(self::get_course_grade_items($userid, (int) $gc->cid));
            if (!$items) {
                continue;
            }
            $failed = false;
            foreach ($items as $gi) {
                $failed = $failed || self::passfail((float) $gi->score) === 'failed';
            }
            $gradestatus[$gc->cid] = ['allpassed' => !$failed, 'hasfailed' => $failed, 'items' => $items];
        }

        $stats = ['total' => 0, 'completed' => 0, 'failed' => 0, 'inprogress' => 0, 'overdue' => 0];
        $display = [];

        foreach (self::get_user_courses($userid) as $course) {
            if (($enrollfrom && $course->enrollmentdate < $enrollfrom) || ($enrollto && $course->enrollmentdate > $enrollto)) {
                continue;
            }

            $info = (object) [
                'id' => (int) $course->id,
                'fullname' => $course->fullname,
                'enrollmentdate' => (int) $course->enrollmentdate,
                'completionpct' => self::get_course_completion_percentage((int) $course->id, $userid),
                'gradeitems' => $gradestatus[$course->id]['items'] ?? [],
                'activities' => [],
                'total' => 0, 'completed' => 0, 'inprogress' => 0, 'overdue' => 0,
            ];

            foreach (self::get_course_activities((int) $course->id, $userid) as $a) {
                if ($f['q'] !== '' && stripos($a->name, $f['q']) === false) {
                    continue;
                }
                if ($closefrom && ($a->closedate <= 0 || $a->closedate < $closefrom)) {
                    continue;
                }
                if ($closeto && ($a->closedate <= 0 || $a->closedate > $closeto)) {
                    continue;
                }
                $info->activities[] = $a;
                $info->total++;
                if (in_array($a->completionstatus, ['completed', 'inprogress', 'overdue'], true)) {
                    $info->{$a->completionstatus}++;
                }
            }
            if ($activityfilters && $info->total === 0) {
                continue;
            }

            $graded = isset($gradestatus[$course->id]);
            if ($graded && $gradestatus[$course->id]['allpassed']) {
                $info->status = 'completed';
            } else if ($graded && $gradestatus[$course->id]['hasfailed']) {
                $info->status = 'failed';
            } else if (!empty($course->course_completed_date)) {
                $info->status = 'completed';
            } else if ($info->total > 0 && $info->completed === $info->total) {
                $info->status = 'completed';
            } else if ($info->inprogress || $info->completed || $info->overdue || $info->completionpct > 0) {
                $info->status = 'inprogress';
            } else {
                $info->status = 'notyetattempted';
            }

            $stats['total']++;
            $stats['overdue'] += $info->overdue;
            if (isset($stats[$info->status])) {
                $stats[$info->status]++;
            }

            if ($f['statuses'] && !in_array($info->status, $f['statuses'], true)) {
                continue;
            }
            if ($f['overdueonly'] && $info->overdue === 0) {
                continue;
            }
            if ($f['courseid'] && $info->id !== $f['courseid']) {
                continue;
            }
            $display[] = $info;
        }

        $stats['rate'] = $stats['total'] ? round($stats['completed'] / $stats['total'] * 100, 1) : 0;
        return ['courses' => self::sort_courses($display, $f['sortby'], $f['sortdir']), 'stats' => $stats];
    }

    /**
     * Sort courses.
     *
     * @param stdClass[] $courses
     * @param string $sortby One of SORT_FIELDS.
     * @param string $sortdir asc|desc
     * @return stdClass[]
     */
    public static function sort_courses(array $courses, string $sortby, string $sortdir): array {
        $order = ['failed' => 1, 'inprogress' => 2, 'notyetattempted' => 3, 'completed' => 4];
        usort($courses, function ($a, $b) use ($sortby, $order) {
            switch ($sortby) {
                case 'enrollmentdate':
                    return $a->enrollmentdate <=> $b->enrollmentdate;
                case 'status':
                    return ($order[$a->status] ?? 99) <=> ($order[$b->status] ?? 99);
                case 'completionpct':
                    return ($a->completionpct ?? -1) <=> ($b->completionpct ?? -1);
                case 'overdue':
                    return $a->overdue <=> $b->overdue;
                default:
                    return strcmp($a->fullname, $b->fullname);
            }
        });
        return $sortdir === 'desc' ? array_reverse($courses) : $courses;
    }

    /**
     * YYYY-MM-DD to timestamp; end of day when $endofday.
     *
     * @param string $date
     * @param bool $endofday
     * @return int 0 when empty or invalid.
     */
    public static function date_to_ts(string $date, bool $endofday = false): int {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ($ts = strtotime($date)) === false) {
            return 0;
        }
        return $endofday ? $ts + DAYSECS - 1 : $ts;
    }

    /**
     * Stream the filtered report as CSV.
     *
     * @param stdClass $user
     * @param array $courses From build().
     */
    public static function export_csv(stdClass $user, array $courses): void {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');

        $csv = new \csv_export_writer();
        $csv->set_filename(clean_filename('training_report_' . fullname($user) . '_' . date('Y-m-d')));
        $csv->add_data([
            get_string('course'),
            get_string('report:enrolled', 'local_mentorship'),
            get_string('report:activity', 'local_mentorship'),
            get_string('report:type', 'local_mentorship'),
            get_string('report:completionstatus', 'local_mentorship'),
            get_string('report:grade', 'local_mentorship'),
            get_string('report:grademax', 'local_mentorship'),
            get_string('report:passfail', 'local_mentorship'),
            get_string('report:opendate', 'local_mentorship'),
            get_string('report:closedate', 'local_mentorship'),
        ]);
        foreach ($courses as $course) {
            $enrolled = $course->enrollmentdate ? userdate($course->enrollmentdate, '%Y-%m-%d') : '';
            foreach ($course->activities as $a) {
                $csv->add_data([
                    $course->fullname,
                    $enrolled,
                    $a->name,
                    ucfirst($a->modname),
                    self::status_label($a->completionstatus),
                    $a->score ?? '',
                    $a->grademax ? round($a->grademax, 1) : '',
                    $a->passfail ? get_string('report:' . $a->passfail, 'local_mentorship') : '',
                    $a->opendate ? userdate($a->opendate, '%Y-%m-%d %H:%M') : '',
                    $a->closedate ? userdate($a->closedate, '%Y-%m-%d %H:%M') : '',
                ]);
            }
        }
        $csv->download_file();
    }

    /**
     * Human label for a status key.
     *
     * @param string $status
     * @return string
     */
    public static function status_label(string $status): string {
        $known = ['completed', 'inprogress', 'notyetattempted', 'overdue', 'failed', 'nottracked'];
        return in_array($status, $known, true) ? get_string('report:' . $status, 'local_mentorship') : '-';
    }

    /**
     * Badge data for a status, rendered by local_mentorship/report_badge.
     *
     * @param string $status
     * @return array
     */
    public static function badge(string $status): array {
        $map = [
            'completed' => ['completed', 'fa-check-circle'],
            'inprogress' => ['inprogress', 'fa-spinner fa-spin'],
            'notyetattempted' => ['notattempted', 'fa-circle-o'],
            'overdue' => ['overdue', 'fa-exclamation-triangle'],
            'failed' => ['failed', 'fa-times-circle'],
            'nottracked' => ['nottracked', 'fa-minus-circle'],
        ];
        [$cls, $icon] = $map[$status] ?? ['nottracked', 'fa-minus-circle'];
        return ['cls' => $cls, 'icon' => $icon, 'label' => self::status_label($status)];
    }

    /**
     * Template data for the course blocks.
     *
     * @param stdClass[] $courses From build().
     * @return array
     */
    public static function courses_for_template(array $courses): array {
        $out = [];
        foreach ($courses as $course) {
            $context = \context_course::instance($course->id);
            $pct = $course->completionpct;

            $gradeitems = [];
            foreach ($course->gradeitems as $gi) {
                $gradeitems[] = [
                    'name' => format_string($gi->itemname, true, ['context' => $context, 'escape' => false]),
                    'score' => $gi->score,
                    'passed' => self::passfail((float) $gi->score) === 'passed',
                ];
            }

            $activities = [];
            foreach ($course->activities as $a) {
                $activities[] = [
                    'name' => format_string($a->name, true, ['context' => $context, 'escape' => false]),
                    'sortname' => $a->name,
                    'url' => $a->url,
                    'icon' => $a->icon,
                    'modname' => ucfirst($a->modname),
                    'status' => $a->completionstatus,
                    'badge' => self::badge($a->completionstatus),
                    'overdue' => $a->completionstatus === 'overdue',
                    'hasscore' => $a->score !== null,
                    'score' => $a->score,
                    'passed' => $a->passfail === 'passed',
                    'failed' => $a->passfail === 'failed',
                    'passfailsort' => $a->passfail,
                    'opendate' => $a->opendate ? userdate($a->opendate, '%b %d, %Y %I:%M %p') : '-',
                    'opensort' => $a->opendate,
                    'closedate' => $a->closedate ? userdate($a->closedate, '%b %d, %Y %I:%M %p') : '-',
                    'closesort' => $a->closedate,
                    'hasclose' => $a->closedate > 0,
                ];
            }

            $out[] = [
                'id' => $course->id,
                'fullname' => format_string($course->fullname, true, ['context' => $context, 'escape' => false]),
                'status' => $course->status,
                'badge' => self::badge($course->status),
                'enrolled' => $course->enrollmentdate ? userdate($course->enrollmentdate, '%b %d, %Y') : '-',
                'hasoverdue' => $course->overdue > 0,
                'overdue' => $course->overdue,
                'haspct' => $pct !== null,
                'pct' => $pct,
                'pctcomplete' => $pct !== null && $pct >= 100,
                'hasgradeitems' => !empty($gradeitems),
                'gradeitems' => $gradeitems,
                'hasactivities' => !empty($activities),
                'activities' => $activities,
            ];
        }
        return $out;
    }
}
