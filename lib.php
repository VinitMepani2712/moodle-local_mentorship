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
 * Library callbacks for local_mentorship.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_mentorship\pairing;

/**
 * Add "My mentorship" to the user's own profile page.
 *
 * //VM: Works on every supported Moodle version. On Moodle 4.1 and 4.2, which have no
 * //VM: primary navigation hook, this is the menu entry point for mentors; mentees get it too.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user Profile owner.
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function local_mentorship_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (!$iscurrentuser || isguestuser($user)) {
        return;
    }
    if (!pairing::has_mentees((int) $user->id) && !pairing::get_mentors((int) $user->id)) {
        return;
    }
    $tree->add_node(new \core_user\output\myprofile\node(
        'miscellaneous',
        'local_mentorship',
        get_string('mymentorship', 'local_mentorship'),
        null,
        new moodle_url('/local/mentorship/index.php')
    ));
}
