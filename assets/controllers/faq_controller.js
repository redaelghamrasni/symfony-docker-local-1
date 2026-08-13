import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['category', 'noResults'];

    toggle(event) {
        const item = event.currentTarget.closest('.faq-item');
        const wrap = item.querySelector('.faq-answer-wrap');
        const isOpen = item.classList.contains('open');

        if (isOpen) {
            item.classList.remove('open');
            wrap.style.maxHeight = null;
        } else {
            item.classList.add('open');
            wrap.style.maxHeight = wrap.scrollHeight + 'px';
        }
    }

    search(event) {
        const query = event.target.value.trim().toLowerCase();
        let anyVisible = false;

        this.categoryTargets.forEach((section) => {
            let sectionHasMatch = false;

            section.querySelectorAll('.faq-item').forEach((item) => {
                const question = item.querySelector('[data-faq-question]').textContent.toLowerCase();
                const answer = item.querySelector('[data-faq-answer]').textContent.toLowerCase();
                const matches = !query || question.includes(query) || answer.includes(query);

                item.style.display = matches ? '' : 'none';
                if (matches) sectionHasMatch = true;
            });

            section.style.display = sectionHasMatch ? '' : 'none';
            if (sectionHasMatch) anyVisible = true;
        });

        if (this.hasNoResultsTarget) {
            this.noResultsTarget.style.display = anyVisible ? 'none' : 'block';
        }
    }
}
