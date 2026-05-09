import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    #onStart = () => this.#setDisabled(true);
    #onEnd   = () => this.#setDisabled(false);

    connect() {
        this.element.addEventListener('turbo:submit-start', this.#onStart);
        this.element.addEventListener('turbo:submit-end',   this.#onEnd);
    }

    disconnect() {
        this.element.removeEventListener('turbo:submit-start', this.#onStart);
        this.element.removeEventListener('turbo:submit-end',   this.#onEnd);
    }

    #setDisabled(disabled) {
        this.element
            .querySelectorAll('button[type="submit"]')
            .forEach(btn => { btn.disabled = disabled; });
    }
}
