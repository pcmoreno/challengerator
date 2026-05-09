import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu', 'defaultMark', 'autoMark'];

    connect() {
        this.#apply(localStorage.getItem('theme') ?? 'default');
        this._onOutsideClick = (e) => {
            if (!this.element.contains(e.target)) {
                this.menuTarget.classList.add('hidden');
            }
        };
        document.addEventListener('click', this._onOutsideClick);
    }

    disconnect() {
        document.removeEventListener('click', this._onOutsideClick);
    }

    toggleMenu() {
        this.menuTarget.classList.toggle('hidden');
    }

    select(event) {
        this.#apply(event.currentTarget.dataset.themeOption);
        this.menuTarget.classList.add('hidden');
    }

    #apply(theme) {
        if (theme === 'auto') {
            document.documentElement.dataset.theme = 'auto';
            this.defaultMarkTarget.classList.add('invisible');
            this.autoMarkTarget.classList.remove('invisible');
        } else {
            delete document.documentElement.dataset.theme;
            this.defaultMarkTarget.classList.remove('invisible');
            this.autoMarkTarget.classList.add('invisible');
        }
        localStorage.setItem('theme', theme);
    }
}
