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

/**
 * Tests for the training report helpers.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_mentorship\report
 */
final class report_test extends \basic_testcase {

    /**
     * The 70% pass rule, date parsing and course sorting.
     */
    public function test_helpers(): void {
        $this->assertSame('passed', report::passfail(70.0));
        $this->assertSame('failed', report::passfail(69.9));

        $this->assertSame(0, report::date_to_ts(''));
        $this->assertSame(0, report::date_to_ts('2026-13-99x'));
        $start = report::date_to_ts('2026-10-20');
        $this->assertSame($start + DAYSECS - 1, report::date_to_ts('2026-10-20', true));

        $c = fn($name, $status, $pct, $overdue) => (object) ['fullname' => $name, 'status' => $status,
            'completionpct' => $pct, 'overdue' => $overdue, 'enrollmentdate' => 0];
        $courses = [$c('B', 'completed', 100, 0), $c('A', 'failed', null, 2), $c('C', 'inprogress', 40, 1)];

        $names = fn($list) => array_column($list, 'fullname');
        $this->assertSame(['A', 'B', 'C'], $names(report::sort_courses($courses, 'fullname', 'asc')));
        $this->assertSame(['A', 'C', 'B'], $names(report::sort_courses($courses, 'status', 'asc')));
        $this->assertSame(['B', 'C', 'A'], $names(report::sort_courses($courses, 'completionpct', 'desc')));
        $this->assertSame(['A', 'C', 'B'], $names(report::sort_courses($courses, 'overdue', 'desc')));
    }
}
