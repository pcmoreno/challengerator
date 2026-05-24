import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['menu', 'defaultMark', 'autoMark', 'racingMark'];

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
        if (theme === 'auto' || theme === 'racing') {
            document.documentElement.dataset.theme = theme;
        } else {
            delete document.documentElement.dataset.theme;
        }
        this.defaultMarkTarget.classList.toggle('invisible', theme !== 'default');
        this.autoMarkTarget.classList.toggle('invisible', theme !== 'auto');
        this.racingMarkTarget.classList.toggle('invisible', theme !== 'racing');
        localStorage.setItem('theme', theme);
    }
}
