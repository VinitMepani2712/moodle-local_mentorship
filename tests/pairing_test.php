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
 * Tests for the pairing API.
 *
 * @package    local_mentorship
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_mentorship\pairing
 */
final class pairing_test extends \advanced_testcase {
    /**
     * Self, duplicate and reverse pairs are blocked; chains and several mentees per mentor are allowed.
     */
    public function test_rules_and_chain(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        [$a, $b, $c, $d] = [$gen->create_user(), $gen->create_user(), $gen->create_user(), $gen->create_user()];

        $this->assertSame('error:self', pairing::validate($a->id, $a->id));

        pairing::create($a->id, $b->id, false);
        pairing::create($a->id, $d->id, false);
        $this->assertCount(2, pairing::get_mentees($a->id));
        $this->assertSame('error:duplicate', pairing::validate($a->id, $b->id));
        $this->assertSame('error:reverse', pairing::validate($b->id, $a->id));

        // B is mentored by A and mentors C.
        $this->assertNull(pairing::validate($b->id, $c->id));
        pairing::create($b->id, $c->id, false);
        $this->assertCount(1, pairing::get_mentors($b->id));
        $this->assertCount(1, pairing::get_mentees($b->id));
    }

    /**
     * Suspended users cannot be paired and drop out of existing pairing lists.
     */
    public function test_suspended_users(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        [$a, $b, $c] = [$gen->create_user(), $gen->create_user(), $gen->create_user(['suspended' => 1])];

        $this->assertSame('error:suspended', pairing::validate($a->id, $c->id));

        $id = pairing::create($a->id, $b->id, false);
        $this->assertTrue(pairing::has_mentees($a->id));
        $this->assertFalse(pairing::has_mentees($b->id), 'A mentee is not a mentor');
        $DB->set_field('user', 'suspended', 1, ['id' => $b->id]);

        $this->assertEmpty(pairing::get_mentees($a->id));
        $this->assertFalse(pairing::has_mentees($a->id));
        $this->assertFalse(pairing::can_view($DB->get_record(pairing::TABLE, ['id' => $id]), (int) $a->id));
    }

    /**
     * Changing a mentor ends the old pairing, keeps it, and starts a new one.
     */
    public function test_change_mentor(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        [$a, $b, $c] = [$gen->create_user(), $gen->create_user(), $gen->create_user()];

        $old = pairing::create($a->id, $b->id, false);
        $new = pairing::change_mentor($old, $c->id, false);

        $this->assertFalse(pairing::is_active($DB->get_record(pairing::TABLE, ['id' => $old])));
        $this->assertTrue(pairing::is_active($DB->get_record(pairing::TABLE, ['id' => $new])));
        $this->assertSame([(int) $c->id], array_map('intval', array_column(pairing::get_mentors($b->id), 'id')));

        // Refused change leaves the current pairing untouched.
        try {
            pairing::change_mentor($new, $b->id, false);
            $this->fail('Self pairing should be refused');
        } catch (\moodle_exception $e) {
            $this->assertTrue(pairing::is_active($DB->get_record(pairing::TABLE, ['id' => $new])));
        }
    }

    /**
     * Ending frees the pair again; deleting a user removes their pairings.
     */
    public function test_end_and_user_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        [$a, $b] = [$gen->create_user(), $gen->create_user()];

        $id = pairing::create($a->id, $b->id, false);
        pairing::end($id);
        $this->assertEmpty(pairing::get_mentees($a->id));
        $this->assertNull(pairing::validate($a->id, $b->id));

        delete_user($b);
        $this->assertFalse($DB->record_exists(pairing::TABLE, ['menteeid' => $b->id]));
    }
}
