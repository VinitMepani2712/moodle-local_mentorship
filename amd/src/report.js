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
 * Training report interactions: mentee selector, expand/collapse, sorting and filter toggles.
 *
 * //VM: Replaces the inline <script> from local_staticpage/gradereport.php.
 *
 * @module     local_mentorship/report
 * @copyright  2026 Vinit Mepani, Rutgers DCF-OTPD
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Show or hide one course panel.
 *
 * @param {HTMLElement} header Course header element.
 * @param {boolean} open
 */
const setCourse = (header, open) => {
    const panel = document.getElementById('egr-course-' + header.dataset.toggleCourse);
    const chevron = header.querySelector('.egr-chevron');
    panel.hidden = !open;
    header.setAttribute('aria-expanded', open ? 'true' : 'false');
    header.classList.toggle('egr-expanded', open);
    chevron.classList.toggle('fa-chevron-right', !open);
    chevron.classList.toggle('fa-chevron-down', open);
};

/**
 * Sort a table body by one column, using data-sort-val so dates and scores sort numerically.
 *
 * @param {HTMLTableElement} table
 * @param {HTMLElement} th Clicked header cell.
 * @param {number} col Column index.
 */
const sortTable = (table, th, col) => {
    const asc = th.getAttribute('aria-sort') !== 'ascending';
    table.querySelectorAll('th.egr-sortable-th').forEach((h) => {
        h.setAttribute('aria-sort', 'none');
        h.querySelector('.egr-sort-indicator i').className = 'fa fa-sort';
    });
    th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
    th.querySelector('.egr-sort-indicator i').className = asc ? 'fa fa-sort-asc' : 'fa fa-sort-desc';

    const value = (row) => {
        const cell = row.cells[col];
        return cell.dataset.sortVal ?? cell.textContent.trim();
    };
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows);
    rows.sort((a, b) => {
        const va = value(a);
        const vb = value(b);
        const na = parseFloat(va);
        const nb = parseFloat(vb);
        const cmp = (!isNaN(na) && !isNaN(nb)) ? na - nb : va.localeCompare(vb);
        return asc ? cmp : -cmp;
    });
    rows.forEach((r) => tbody.appendChild(r));
};

/**
 * Run the action for Enter or Space on a keyboard-focusable element.
 *
 * @param {KeyboardEvent} e
 * @param {Function} action
 */
const onActivateKey = (e, action) => {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        action();
    }
};

/**
 * Wire up the report page.
 */
export const init = () => {
    const root = document.querySelector('[data-region="local-mentorship-report"]');
    if (!root) {
        return;
    }

    // Mentee selector navigates to the chosen pairing.
    const select = root.querySelector('[data-action="select-mentee"]');
    if (select) {
        select.addEventListener('change', () => {
            if (select.value) {
                window.location.href = select.value;
            }
        });
    }

    // Course expand / collapse, by click or keyboard.
    const headers = root.querySelectorAll('.egr-course-header');
    headers.forEach((header) => {
        const toggle = () => setCourse(header, header.getAttribute('aria-expanded') !== 'true');
        header.addEventListener('click', toggle);
        header.addEventListener('keydown', (e) => onActivateKey(e, toggle));
    });

    // Expand / collapse all.
    const expandAll = root.querySelector('#egr-expand-all');
    if (expandAll) {
        let all = false;
        expandAll.addEventListener('click', () => {
            all = !all;
            headers.forEach((header) => setCourse(header, all));
            expandAll.querySelector('i').className = all ? 'fa fa-compress' : 'fa fa-expand';
            expandAll.querySelector('span').textContent = all
                ? expandAll.dataset.collapseLabel : expandAll.dataset.expandLabel;
        });
    }

    // Sort direction buttons update the hidden field and resubmit the filters.
    const form = root.querySelector('#egr-filter-form');
    root.querySelectorAll('.egr-sortdir-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            root.querySelector('#egr-sortdir-hidden').value = btn.dataset.sortdir;
            form.submit();
        });
    });

    // Activity table column sorting, scoped to each table.
    root.querySelectorAll('.egr-activities-table').forEach((table) => {
        table.querySelectorAll('th.egr-sortable-th').forEach((th, col) => {
            const sort = () => sortTable(table, th, col);
            th.addEventListener('click', sort);
            th.addEventListener('keydown', (e) => onActivateKey(e, sort));
        });
    });

    // Date filter row toggle.
    const toggleDates = root.querySelector('#egr-toggle-dates');
    const dateRow = root.querySelector('#egr-date-filter-row');
    if (toggleDates && dateRow) {
        toggleDates.addEventListener('click', () => {
            dateRow.hidden = !dateRow.hidden;
            toggleDates.classList.toggle('active', !dateRow.hidden);
            toggleDates.setAttribute('aria-expanded', dateRow.hidden ? 'false' : 'true');
        });
    }

    // Double-click the status row to clear every status checkbox.
    const statusArea = root.querySelector('.egr-status-checkboxes');
    if (statusArea) {
        statusArea.addEventListener('dblclick', () => {
            statusArea.querySelectorAll('input[name="statuses[]"]').forEach((c) => {
                c.checked = false;
            });
        });
    }
};
