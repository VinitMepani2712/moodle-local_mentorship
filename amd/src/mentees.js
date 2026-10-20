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
 * My mentorship mentee list: filter as you type, clear, sort, keep the choice when opening a mentee.
 *
 * Progressive enhancement. Without this module the search form submits to the server.
 *
 * @module     local_mentorship/mentees
 * @copyright  2026 Vinit Mepani
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Set or remove one query parameter on every mentee link, so a reload keeps search and sort.
 *
 * @param {HTMLElement} list
 * @param {string} name
 * @param {string} value Empty removes the parameter.
 * @param {string} defaultvalue Value that is left out of the URL.
 */
const updateLinks = (list, name, value, defaultvalue) => {
    list.querySelectorAll('a[href]').forEach((a) => {
        const url = new URL(a.href, window.location.href);
        if (value && value !== defaultvalue) {
            url.searchParams.set(name, value);
        } else {
            url.searchParams.delete(name);
        }
        a.href = url.toString();
    });
};

/**
 * Wire up the mentee list.
 */
export const init = () => {
    const root = document.querySelector('[data-region="local-mentorship-mentees"]');
    if (!root) {
        return;
    }
    const form = root.querySelector('[data-region="search-form"]');
    const search = root.querySelector('[data-region="search"]');
    const clear = root.querySelector('[data-action="clear"]');
    const sort = root.querySelector('[data-region="sort"]');
    const list = root.querySelector('[data-region="list"]');
    const count = root.querySelector('[data-region="count"]');
    const nomatch = root.querySelector('[data-region="nomatch"]');
    const total = parseInt(root.dataset.total, 10) || 0;
    const items = Array.from(list.children);

    const applySearch = () => {
        const term = search.value.trim().toLowerCase();
        let shown = 0;
        items.forEach((li) => {
            const match = term === '' || li.dataset.name.includes(term);
            li.hidden = !match;
            shown += match ? 1 : 0;
        });
        clear.hidden = search.value === '';
        nomatch.hidden = shown > 0;
        count.textContent = shown === total ? String(total)
            : root.dataset.countpattern.replace('{$a->shown}', shown).replace('{$a->total}', total);
        updateLinks(list, 'q', search.value.trim(), '');
    };

    const applySort = () => {
        const mode = sort.value;
        const sorted = items.slice().sort((a, b) => {
            const pa = parseInt(a.dataset.pct, 10);
            const pb = parseInt(b.dataset.pct, 10);
            if (mode !== 'name' && pa !== pb) {
                return mode === 'progressasc' ? pa - pb : pb - pa;
            }
            return a.dataset.name.localeCompare(b.dataset.name);
        });
        sorted.forEach((li) => list.appendChild(li));
        updateLinks(list, 'sort', mode, 'name');
    };

    search.addEventListener('input', applySearch);
    search.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && search.value !== '') {
            e.preventDefault();
            search.value = '';
            applySearch();
        }
    });
    clear.addEventListener('click', () => {
        search.value = '';
        applySearch();
        search.focus();
    });
    sort.addEventListener('change', applySort);
    // Filtering is live, so Enter should not reload the page.
    form.addEventListener('submit', (e) => e.preventDefault());

    // Long lists scroll; bring the open mentee into view.
    const active = list.querySelector('[aria-current="true"]');
    if (active) {
        active.scrollIntoView({block: 'nearest'});
    }
};
