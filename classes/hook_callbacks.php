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

use core\hook\navigation\primary_extend;

/**
 * Hook callbacks for local_mentorship.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Add "My mentorship" to the top menu for users who mentor someone.
     *
     * //VM: Mentors only. Mentees reach their report from the pairing notification and the
     * //VM: dashboard block; the page itself stays open to them.
     *
     * @param primary_extend $hook
     */
    public static function primary_extend(primary_extend $hook): void {
        global $USER;

        if (!isloggedin() || isguestuser() || !pairing::has_mentees((int) $USER->id)) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('mymentorship', 'local_mentorship'),
            new \moodle_url('/local/mentorship/index.php'),
            \navigation_node::TYPE_CUSTOM,
            null,
            'local_mentorship'
        );
    }
}
